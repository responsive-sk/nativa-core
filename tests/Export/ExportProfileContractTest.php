<?php

declare(strict_types = 1);

namespace Tests\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ExportProfileContractTest — every export profile is one complete answer.
 *
 * The exporter reads a profile from a commit, not from the working tree. That
 * makes a malformed profile a failure late in a publish, after the temporary
 * tree has been assembled. This test reads the same files as data and answers
 * the questions before the exporter runs: does each profile have the keys the
 * exporter reads, does every named module exist with a closed dependency set,
 * does every prune entry still name a path in HEAD, and do two profiles claim
 * the same marker or repository.
 *
 * The test reads `HEAD` for the prune entries, so it is a source-tree test.
 * The exported trees prune `bin/export` and carry no `.git`; there it skips
 * instead of failing on a subject the export deliberately does not ship.
 *
 * @internal
 *
 * @coversNothing
 */
final class ExportProfileContractTest extends TestCase
{
    /** @var list<string> */
    private const array REQUIRED_KEYS = [
        'schema_version',
        'slug',
        'name',
        'repository',
        'source',
        'visibility',
        'marker',
        'default_target',
        'install_modules',
        'migrate_before_tests',
        'minimum_tests',
        'deploy',
        'required_paths',
        'pruned_paths',
    ];

    /** @var list<string> */
    private const array CONTENT_PATHS = [
        'storage/pages',
        'storage/blog',
        'storage/blog-images',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(self::root() . '/bin/export') || !is_dir(self::root() . '/.git')) {
            self::markTestSkipped(
                'The export profiles are source-only: this tree prunes bin/export and has no HEAD to check.',
            );
        }
    }

    #[Test]
    public function everyProfileHasTheShapeTheExporterReads(): void
    {
        $profiles = self::profiles();

        self::assertNotEmpty($profiles, 'bin/export carries no profile.json.');

        foreach ($profiles as $slug => $profile) {
            $data = $profile['data'];
            $where = $profile['path'];

            foreach (self::REQUIRED_KEYS as $key) {
                self::assertArrayHasKey($key, $data, sprintf('%s is missing "%s".', $where, $key));
            }

            self::assertSame(1, $data['schema_version'], sprintf('%s has an unknown schema_version.', $where));
            self::assertSame($slug, $data['slug'], sprintf('%s names a different slug.', $where));
            self::assertNonEmptyString($data, 'name', $where);
            self::assertNonEmptyString($data, 'repository', $where);
            self::assertNonEmptyString($data, 'source', $where);
            self::assertNonEmptyString($data, 'default_target', $where);
            self::assertContains(
                $data['visibility'],
                ['public', 'private'],
                sprintf('%s must be public or private.', $where),
            );
            self::assertIsString($data['marker'], sprintf('%s marker must be a string.', $where));
            self::assertMatchesRegularExpression(
                '/^\.[a-z0-9-]+\.json$/',
                $data['marker'],
                sprintf('%s marker must be a dotfile ending in .json.', $where),
            );
            self::assertIsBool($data['install_modules'], sprintf('%s install_modules must be a boolean.', $where));
            self::assertIsBool(
                $data['migrate_before_tests'],
                sprintf('%s migrate_before_tests must be a boolean.', $where),
            );
            self::assertIsInt($data['minimum_tests'], sprintf('%s minimum_tests must be an integer.', $where));
            self::assertGreaterThan(0, $data['minimum_tests'], sprintf('%s minimum_tests must be positive.', $where));

            self::assertIsArray($data['deploy'], sprintf('%s deploy must be an object.', $where));
            self::assertNonEmptyString($data['deploy'], 'theme', $where . ' deploy');

            $requiredPaths = self::listOfStrings($data['required_paths'], $where . ' required_paths');
            self::listOfStrings($data['pruned_paths'], $where . ' pruned_paths');

            if (true === $data['install_modules']) {
                self::assertArrayHasKey('modules', $data, sprintf('%s installs modules but names none.', $where));
                $modules = self::moduleSet($data, $where);
                self::assertNotEmpty($modules, sprintf('%s installs modules but its modules list is empty.', $where));

                foreach (self::CONTENT_PATHS as $contentPath) {
                    self::assertContains(
                        $contentPath,
                        $requiredPaths,
                        sprintf('%s carries a site but does not require %s.', $where, $contentPath),
                    );
                }
            }
        }
    }

    #[Test]
    public function everyListedModuleAndItsClosureAreInTheCatalogue(): void
    {
        $catalogue = self::catalogue();

        foreach (self::profiles() as $slug => $profile) {
            $modules = self::moduleSet($profile['data'], $profile['path']);

            foreach ($modules as $module) {
                self::assertArrayHasKey(
                    $module,
                    $catalogue,
                    sprintf('%s names %s, which is not in modules/catalogue.json.', $slug, $module),
                );
            }

            $allowed = array_fill_keys($modules, true);

            foreach ($modules as $module) {
                $requires = $catalogue[$module]['requires'] ?? null;

                self::assertIsArray($requires, sprintf('Catalogue entry %s has no requires list.', $module));

                foreach ($requires as $dependency) {
                    self::assertArrayHasKey(
                        $dependency,
                        $catalogue,
                        sprintf('%s requires %s, which is not in modules/catalogue.json.', $module, $dependency),
                    );
                    self::assertArrayHasKey(
                        $dependency,
                        $allowed,
                        sprintf('%s requires %s, which %s does not carry.', $module, $dependency, $slug),
                    );
                }
            }
        }
    }

    #[Test]
    public function everyPruneEntryStillExistsInHead(): void
    {
        foreach (self::profiles() as $slug => $profile) {
            $prune = dirname($profile['path']) . '/prune.txt';

            self::assertFileExists($prune, sprintf('%s has no prune.txt.', $slug));

            foreach (file($prune, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $entry = trim(explode('#', $line, 2)[0]);

                if ('' === $entry) {
                    continue;
                }

                $output = [];
                $status = 0;
                exec(
                    sprintf(
                        'git -C %s cat-file -e %s 2>/dev/null',
                        escapeshellarg(self::root()),
                        escapeshellarg('HEAD:' . $entry),
                    ),
                    $output,
                    $status,
                );

                self::assertSame(
                    0,
                    $status,
                    sprintf('%s prunes %s, but HEAD has no such path.', $slug, $entry),
                );
            }
        }
    }

    #[Test]
    public function noTwoProfilesShareAMarkerOrRepository(): void
    {
        $markers = [];
        $repositories = [];

        foreach (self::profiles() as $slug => $profile) {
            $marker = $profile['data']['marker'];
            $repository = $profile['data']['repository'];

            self::assertIsString($marker);
            self::assertIsString($repository);

            self::assertArrayNotHasKey(
                $marker,
                $markers,
                sprintf('%s and %s share the marker %s.', $slug, $markers[$marker] ?? 'another profile', $marker),
            );
            self::assertArrayNotHasKey(
                $repository,
                $repositories,
                sprintf('%s and %s share the repository %s.', $slug, $repositories[$repository] ?? 'another profile', $repository),
            );

            $markers[$marker] = $slug;
            $repositories[$repository] = $slug;
        }
    }

    /**
     * @return array<string, array{path: string, data: array<string, mixed>}>
     */
    private static function profiles(): array
    {
        $profiles = [];

        foreach (glob(self::root() . '/bin/export/*/profile.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $slug = basename(dirname($path));

            self::assertIsArray($decoded, sprintf('%s is not a JSON object.', $path));

            $profiles[$slug] = ['path' => $path, 'data' => $decoded];
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function moduleSet(array $data, string $where): array
    {
        $modules = $data['modules'] ?? [];

        self::assertIsArray($modules, sprintf('%s modules must be a list.', $where));

        $names = [];

        foreach ($modules as $module) {
            self::assertIsString($module, sprintf('%s modules must hold strings.', $where));
            self::assertNotSame('', $module, sprintf('%s modules must hold non-empty strings.', $where));

            $names[] = $module;
        }

        return $names;
    }

    /**
     * @return array<string, array{requires: list<string>}>
     */
    private static function catalogue(): array
    {
        $path = self::root() . '/modules/catalogue.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded, 'modules/catalogue.json is not a JSON object.');
        self::assertArrayHasKey('modules', $decoded, 'modules/catalogue.json has no modules map.');
        self::assertIsArray($decoded['modules'], 'modules/catalogue.json modules must be a map.');

        /** @var array<string, array{requires: list<string>}> $modules */
        $modules = $decoded['modules'];

        return $modules;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function assertNonEmptyString(array $data, string $key, string $where): void
    {
        self::assertArrayHasKey($key, $data, sprintf('%s is missing "%s".', $where, $key));
        self::assertIsString($data[$key], sprintf('%s "%s" must be a string.', $where, $key));
        self::assertNotSame('', $data[$key], sprintf('%s "%s" must not be empty.', $where, $key));
    }

    /**
     * @param mixed $list
     *
     * @return list<string>
     */
    private static function listOfStrings(mixed $list, string $where): array
    {
        self::assertIsArray($list, sprintf('%s must be a list.', $where));
        self::assertNotEmpty($list, sprintf('%s must not be empty.', $where));

        $strings = [];

        foreach ($list as $entry) {
            self::assertIsString($entry, sprintf('%s must hold strings.', $where));
            self::assertNotSame('', $entry, sprintf('%s must hold non-empty strings.', $where));

            $strings[] = $entry;
        }

        return $strings;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
