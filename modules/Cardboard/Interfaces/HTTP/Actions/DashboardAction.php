<?php

declare(strict_types = 1);

namespace Modules\Cardboard\Interfaces\HTTP\Actions;

use Modules\Cardboard\Domain\Model\Period;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Cardboard\Interfaces\HTTP\CardboardServices;
use View\ViewRenderer;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Cardboard\Infrastructure\Registry\CardboardRegistry;
use Modules\Cardboard\Infrastructure\Registry\MetricRegistry;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * Dashboard action — auto-discovery metric cards with period selection.
 *
 * GET /mark renders an admin dashboard with widgets: metric cards
 * from all #[AsMetricProvider] classes, quick actions, and system info.
 *
 * Period selection via ?period=7d|30d|90d query parameter.
 * Metric providers are auto-discovered by MetricRegistry — no hardcoded cards.
 */
#[Route(path: '/mark', method: 'GET', name: 'mark.dashboard')]
final class DashboardAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $settings = CardboardServices::settings()->all();
        $siteName = is_string($settings['site_name'] ?? null) ? $settings['site_name'] : 'Mark';

        $periodParam = $request->query('period', '7d');
        $period = Period::tryFrom(is_string($periodParam) ? $periodParam : '7d')
            ?? Period::SEVEN_DAYS;

        $this->registerMetricCards($period);

        $periodSelector = $this->buildPeriodSelector($request, $period);

        $statCards = '<div class="m-grid">';
        foreach (CardboardRegistry::all() as $card) {
            $statCards .= $card->render();
        }
        $statCards .= '</div>';

        $quickActions = ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/dashboard-audit.phtml', [
            'url' => $this->urlBuilder(),
            // The quick actions belong to other modules; the dashboard is not
            // allowed to assume they are installed. Without this, a catalogue
            // tree with Cardboard and no Blog answered /mark with 500 (the
            // template resolved `articles.create`).
            'has' => fn (string $name): bool => $this->hasRoute($name),
        ]);

        $systemRows = '';
        $system = [
            'PHP version'     => PHP_VERSION,
            'SAPI'            => PHP_SAPI,
            'Memory limit'    => self::iniValue('memory_limit', '—'),
            'Upload max'      => self::iniValue('upload_max_filesize', '—'),
            'Max execution'   => self::iniValue('max_execution_time', '—') . 's',
            'Server software' => is_string($_SERVER['SERVER_SOFTWARE'] ?? null) ? $_SERVER['SERVER_SOFTWARE'] : 'unknown',
        ];

        foreach ($system as $label => $value) {
            $systemRows .= "<tr><td>{$label}</td><td class=\"m-mono\">" . AdminLayout::e($value) . '</td></tr>';
        }

        $systemInfo = ViewRenderer::render('Modules/Cardboard/Interfaces/HTTP/templates/dashboard.phtml', ['systemRows' => $systemRows]);

        $content = $periodSelector . $statCards . $quickActions . $systemInfo;

        return Response::html(AdminLayout::render(
            $siteName . ' — Dashboard',
            $content,
        router: $this->router()));
    }

    /**
     * Auto-discover all metric providers and register their cards.
     */
    private function registerMetricCards(Period $period): void
    {
        CardboardRegistry::reset();

        foreach (MetricRegistry::all() as $metric) {
            $metric->registerCards($period);
        }
    }

    /**
     * Build period selector HTML with links to 7d/30d/90d.
     * Preserves other query parameters from the current request.
     */
    private function buildPeriodSelector(Request $request, Period $current): string
    {
        $allParams = $request->getQueryParams();
        $html = '<div class="m-card"><div class="m-filter-bar">';

        foreach (Period::cases() as $period) {
            $params = $allParams;
            $params['period'] = $period->value;
            $qs = http_build_query($params);
            $active = $period === $current ? ' m-btn--active' : '';
            $html .= '<a class="m-btn' . $active . '" href="' . $this->url('mark.dashboard') . '?' . $qs . '">'
                . AdminLayout::e($period->label()) . '</a>';
        }

        $html .= '</div></div>';

        return $html;
    }

    private static function iniValue(string $name, string $fallback): string
    {
        $value = ini_get($name);

        return is_string($value) && '' !== $value ? $value : $fallback;
    }
}
