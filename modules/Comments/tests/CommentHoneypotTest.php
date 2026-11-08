<?php

declare(strict_types = 1);

namespace Modules\Comments\Tests;

use Modules\Comments\Infrastructure\Persistence\Migrations\CommentsDatabaseMigrator;
use Modules\Comments\Infrastructure\Persistence\Statement;
use Modules\Comments\Interfaces\HTTP\Actions\CommentSubmitAction;
use Modules\Comments\Interfaces\HTTP\CommentSection;
use Modules\Comments\Interfaces\HTTP\CommentsServices;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Users\Interfaces\HTTP\AuthServices;
use PDO;
use Modules\Core\Interfaces\CLI\CliOutput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CommentHoneypotTest — the comment form carries the same bot defense as the
 * tree's other public form, and a bot that fills every field reaches nothing.
 *
 * The probe this encodes ran against the REAL route before the field existed:
 * a submission with every field filled (honeypot included) was answered 302
 * `…/blog/{slug}#comments` and left a `pending` row behind — the moderation
 * queue grows for free. After the change the same POST gets the identical
 * redirect and stores nothing, which is the point of a honeypot: the bot
 * cannot tell which field gave it away, and a moderator never sees it.
 *
 * The control assertion is deliberate: a clean submission (honeypot present
 * and blank — what a real browser sends) must still be stored, or "nothing was
 * stored" would be indistinguishable from a broken endpoint.
 *
 * `DB_COMMENTS` / `DB_USERS` point at a temporary directory, so the working
 * tree's comments.db is never touched. Like every module test it drives the
 * INSTALLED copy and skips when the module (or the dependencies this path
 * needs) is not installed — the Blog re-render path is not exercised here.
 */
final class CommentHoneypotTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Comments', 'Security', 'Users', 'Validation'] as $module) {
            if (!is_dir(APP_BASE_PATH . '/src/Modules/' . $module)) {
                $this->markTestSkipped($module . ' is not installed.');
            }
        }

        $this->tmpDir = sys_get_temp_dir() . '/nativa-comments-hp-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o775, true);

        putenv('DB_COMMENTS=' . $this->tmpDir . '/comments.db');
        putenv('DB_USERS=' . $this->tmpDir . '/users.db');
        putenv('DB_OUTBOX=' . $this->tmpDir . '/outbox.db');
        DatabaseConnectionManager::resetInstance();
        CommentsServices::reset();
        AuthServices::reset();

        (new CommentsDatabaseMigrator(new PDO('sqlite:' . $this->tmpDir . '/comments.db')))
            ->run($this->quietOutput());
    }

    protected function tearDown(): void
    {
        // PHPUnit runs tearDown() even when setUp() skipped (module absent).
        if ('' !== $this->tmpDir && is_dir($this->tmpDir)) {
            putenv('DB_COMMENTS');
            putenv('DB_USERS');
            putenv('DB_OUTBOX');
            DatabaseConnectionManager::resetInstance();
            CommentsServices::reset();
            AuthServices::reset();

            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->tmpDir);
        }

        $_SESSION = [];

        parent::tearDown();
    }

    /**
     * A honeypot nobody renders catches nobody: the field has to be in the
     * markup the reader (and the crawler) gets, hidden, out of the tab order
     * and out of autocomplete — in every theme's vocabulary, because the whole
     * comment form for every theme comes through this one method
     * (`BlogArticleAction` passes the active theme's prefix).
     */
    #[Test]
    public function theFormCarriesAHiddenHoneypotField(): void
    {
        foreach (['st', 'er'] as $prefix) {
            $html = CommentSection::render('hello-world', [], [], $prefix);

            self::assertStringContainsString(
                'name="comment_website"',
                $html,
                'the comment form must carry the honeypot field the action discards on',
            );
            self::assertMatchesRegularExpression(
                '/<div class="' . $prefix . '-comments__hp" hidden>/',
                $html,
                'the honeypot must live in a hidden wrapper, not in front of a reader (' . $prefix . ' vocabulary)',
            );
            self::assertMatchesRegularExpression(
                '/name="comment_website" value="" autocomplete="off" tabindex="-1"/',
                $html,
                'the honeypot must be skipped by autocomplete and by tab order',
            );
        }
    }

    /**
     * The finding: the bot's submission is answered exactly like a real one and
     * leaves the moderation queue untouched.
     */
    #[Test]
    public function aFilledHoneypotIsSilentlyDiscarded(): void
    {
        $response = $this->submit(['comment_website' => 'https://spam.example.test']);

        self::assertSame(302, $response->getStatusCode(), 'a bot must not be told what gave it away');
        self::assertSame(
            '/blog/hello-world#comments',
            $response->getHeader('Location'),
            'the answer must be the redirect a real submission gets — nothing else',
        );
        self::assertSame(
            0,
            $this->commentCount(),
            'a honeypot-filling bot must not reach the moderation queue (before the fix: one pending row per POST)',
        );
    }

    /**
     * The control: the honeypot must not cost a real reader their comment. A
     * browser posts the hidden field blank, and that is the human case.
     */
    #[Test]
    public function aCleanSubmissionIsStillStored(): void
    {
        $response = $this->submit([]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/blog/hello-world#comments', $response->getHeader('Location'));
        self::assertSame(1, $this->commentCount(), 'a clean submission must still reach the moderation queue');

        $status = Statement::query(
            DatabaseConnectionManager::instance()->connection('comments'),
            'SELECT status FROM comments',
        )->fetchColumn();

        self::assertSame('pending', $status, 'new comments are always pending — nothing is public until moderated');
    }

    /**
     * "Silently" is the whole contract: the honeypot is checked before
     * validation, so a bot's malformed payload is answered with the success
     * redirect too — no error page, no hint that another field mattered.
     */
    #[Test]
    public function theHoneypotIsDiscardedWhateverElseThePayloadSays(): void
    {
        $response = $this->submit(['comment_website' => 'x', 'body' => '']);

        self::assertSame(302, $response->getStatusCode(), 'a bot must never see a validation error');
        self::assertSame('/blog/hello-world#comments', $response->getHeader('Location'));
        self::assertSame(0, $this->commentCount());
    }

    /**
     * POST the real handler with a guest comment. With no overrides the
     * honeypot is present and blank — exactly what the form sends; an override
     * is what hands it to the bot.
     *
     * @param array<string, string> $overrides
     */
    private function submit(array $overrides): Response
    {
        $body = [
            'guest_name'      => 'Probe Reader',
            'guest_email'     => 'probe-reader@example.test',
            'body'            => 'A real reader comment.',
            'comment_website' => '',
        ];

        foreach ($overrides as $field => $value) {
            $body[$field] = $value;
        }

        $request = Request::create('POST', '/blog/hello-world/comments', [], $body);
        $request->setRouteParams(['slug' => 'hello-world']);

        return (new CommentSubmitAction())->handle($request);
    }

    private function commentCount(): int
    {
        return (int) Statement::query(
            DatabaseConnectionManager::instance()->connection('comments'),
            'SELECT COUNT(*) FROM comments',
        )->fetchColumn();
    }

    /**
     * A `CliOutput` that swallows every line.
     *
     * This was a `Symfony\...\NullOutput` until the migrator contract stopped
     * accepting a dev-only union arm (Boot 32): the kernel's runtime has no
     * Composer dependencies, so a test asserting a kernel contract should not
     * need one to satisfy it either.
     */
    private function quietOutput(): CliOutput
    {
        return new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
    }
}
