<?php

declare(strict_types = 1);

namespace Modules\Blog\Tests;

use Modules\Blog\Application\Services\BlogArticleHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CoverAltTest — the alt text describes the generated cover without copying
 * the article title. Module test contract: skips on a kernel-only tree.
 *
 * @internal
 *
 * @coversNothing
 */
final class CoverAltTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Blog')) {
            $this->markTestSkipped('Blog is not installed.');
        }
    }

    #[Test]
    public function itNamesTheCategoryAndAtMostThreeTags(): void
    {
        $meta = [
            'category' => 'ARCHITECTURE',
            'tags' => ['alpha', 'beta', 'gamma', 'ignored'],
        ];

        self::assertSame(
            'Ilustračný obrázok článku: ARCHITECTURE, alpha, beta, gamma',
            BlogArticleHelper::coverAlt($meta, 'sk_SK'),
        );
    }

    #[Test]
    public function theEnglishLocaleGetsAnEnglishLabel(): void
    {
        $meta = ['category' => 'ARCHITECTURE', 'tags' => ['alpha']];

        self::assertSame(
            'Article cover illustration: ARCHITECTURE, alpha',
            BlogArticleHelper::coverAlt($meta, 'en_US'),
        );
    }

    #[Test]
    public function aCoverWithoutMetadataStillHasAnAlt(): void
    {
        self::assertSame(
            'Ilustračný obrázok článku',
            BlogArticleHelper::coverAlt([], 'sk_SK'),
        );
    }
}
