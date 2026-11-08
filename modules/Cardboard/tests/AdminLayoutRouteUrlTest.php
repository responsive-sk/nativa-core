<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Tests;

use Modules\Cardboard\Application\Services\BulkActions;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\View\ViewRenderer;
use Modules\Core\Interfaces\HTTP\Router;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminLayoutRouteUrlTest — templates ask for a route NAME.
 *
 * Task 2.9: `dashboard-audit.phtml` (and the other admin fragments) spelled
 * `/mark/...` by hand. They receive `$url` from the render context now, and
 * this test renders the real template through the real Router: the path in the
 * output comes from the route table, not from the template.
 *
 * @internal
 *
 * @coversNothing
 */
final class AdminLayoutRouteUrlTest extends TestCase
{
    /**
     * Kernel-only checkouts run this file from the catalogue with no slice
     * installed; naming a Cardboard class there is a fatal, not a skip.
     * Every module test carries the same guard (PHPUnit runs tearDown even
     * after setUp skipped, so nothing here assumes an installed module).
     */
    protected function setUp(): void
    {
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Cardboard')) {
            $this->markTestSkipped('Cardboard is not installed — the admin layout ships with this module.');
        }
    }

    #[Test]
    public function aTemplateResolvesLinksThroughTheInjectedUrlBuilder(): void
    {
        $router = Router::fromEntries([], [
            ['path' => '/mark/users/create', 'method' => 'GET', 'name' => 'mark.users.create', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/articles/create', 'method' => 'GET', 'name' => 'articles.create', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/pages/create', 'method' => 'GET', 'name' => 'pages.create', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/media/upload', 'method' => 'GET', 'name' => 'media.upload', 'handlerMethod' => null, 'action' => self::class],
            ['path' => '/mark/settings', 'method' => 'GET', 'name' => 'mark.settings', 'handlerMethod' => null, 'action' => self::class],
        ]);

        $html = ViewRenderer::render(
            'Modules/Cardboard/Interfaces/HTTP/templates/dashboard-audit.phtml',
            ['url' => AdminLayout::urlBuilder($router)],
        );

        self::assertStringContainsString('href="/mark/users/create"', $html);
        self::assertStringContainsString('href="/mark/articles/create"', $html);
        self::assertStringContainsString('href="/mark/pages/create"', $html);
        self::assertStringContainsString('href="/mark/media/upload"', $html);
        self::assertStringContainsString('href="/mark/settings"', $html);
    }

    /**
     * The module list is a separate page (`mark.settings.modules`), so its
     * entry in the settings tab bar must be a LINK — not a JS panel button
     * that has no panel — and the panel tabs must keep working as in-place
     * switches while still carrying a `?tab=` href for the no-JS path.
     */
    #[Test]
    public function theSettingsTabsLinkToTheModulesScreen(): void
    {
        $html = $this->settingsPage('general');

        self::assertMatchesRegularExpression(
            '/<a class="m-tabs__btn" href="\/mark\/settings\/modules">Modules<\/a>/',
            $html,
            'The Modules entry must be a tab-styled link to its own route, with no panel to switch to.',
        );
        self::assertMatchesRegularExpression(
            '/<a class="m-tabs__btn m-tabs__btn--active" href="\/mark\/settings\?tab=general" data-tab="general" aria-current="page">General<\/a>/',
            $html,
            'The active panel tab keeps its data-tab hook for in-place switching and a ?tab= fallback.',
        );
        self::assertStringContainsString('href="/mark/settings?tab=design" data-tab="design"', $html);
    }

    /**
     * On the module list the same bar renders with Modules current. It is a
     * separate page, so no JS intercepts these links: every panel entry is a
     * plain navigation back to the settings screen.
     */
    #[Test]
    public function theModuleScreenCarriesTheSameTabsWithModulesActive(): void
    {
        $html = ViewRenderer::render(
            'Modules/Cardboard/Interfaces/HTTP/templates/modules-list.phtml',
            [
                'settingsTabs' => AdminLayout::settingsTabs($this->url(), 'modules'),
                'intro'        => '',
                'panel'        => '<div id="modules-panel"></div>',
            ],
        );

        self::assertStringContainsString(
            'class="m-tabs__btn m-tabs__btn--active" href="/mark/settings/modules" aria-current="page">Modules</a>',
            $html,
            'Modules must be the current entry on its own page.',
        );
        self::assertStringContainsString('href="/mark/settings?tab=nav" data-tab="nav"', $html);
    }

    private function settingsPage(string $active): string
    {
        return ViewRenderer::render(
            'Modules/Cardboard/Interfaces/HTTP/templates/settings-form.phtml',
            [
                'url'          => $this->url(),
                'settingsTabs' => AdminLayout::settingsTabs($this->url(), $active),
                'errorHtml'    => '',
                'csrf'         => '',
                'siteName'     => '',
                'siteDescription' => '',
                'adminEmail'   => '',
                'timezone'     => '',
                'locale'       => '',
                'layoutSelect' => '',
                'themeSelect'  => '',
                'themeSettingsCard' => '',
                'regChecked'   => '',
                'tokenTable'   => '',
                'navTable'     => '',
                'adminNavTable' => '',
                'iconOptions'  => '',
                'parentOptions' => '',
            ],
        );
    }

    /**
     * @return \Closure(string): string
     */
    private function url(): \Closure
    {
        return static fn (string $name): string => 'mark.settings.modules' === $name
            ? '/mark/settings/modules'
            : ('mark.settings' === $name ? '/mark/settings' : '/route/' . $name);
    }

    /**
     * Task 2.9: the bulk bar is rendered by an Application-layer service but
     * its form targets are admin navigation — the caller's Router-owned URLs
     * must reach the markup unchanged, with nothing left to spell /mark.
     */
    #[Test]
    public function theBulkBarRendersTheUrlsTheCallerResolved(): void
    {
        $html = BulkActions::actionBarWithPublish(
            'articles',
            '/routes/bulk-publish',
            '/routes/bulk-delete',
            'sk',
        );

        self::assertStringContainsString('action="/routes/bulk-publish"', $html);
        self::assertStringContainsString('action="/routes/bulk-delete"', $html);
        self::assertStringNotContainsString('/mark', $html);
    }

    #[Test]
    public function thePublishSwitchRendersTheUrlTheCallerResolved(): void
    {
        $html = BulkActions::markSwitch(
            '/routes/articles/publish',
            'sk',
            '',
            true,
            ['draft' => 'Draft', 'published' => 'Published', 'tooltip_publish' => 'Publish', 'tooltip_unpublish' => 'Unpublish'],
        );

        self::assertStringContainsString('action="/routes/articles/publish?locale=sk"', $html);
        self::assertStringContainsString('hx-post="/routes/articles/publish?locale=sk"', $html);
        self::assertStringNotContainsString('/mark', $html);
    }

    #[Test]
    public function aLayoutWithoutARouterRefusesToBuildALink(): void
    {
        $url = AdminLayout::urlBuilder(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('without a Router');

        $url('mark.settings');
    }
}
