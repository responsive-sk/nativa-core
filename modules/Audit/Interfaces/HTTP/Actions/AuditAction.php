<?php

declare(strict_types = 1);

namespace Modules\Audit\Interfaces\HTTP\Actions;

use Modules\Audit\Domain\Entities\AuditEntry;
use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * Audit log action — GET /mark/audit.
 *
 * The /mark prefix is already protected by AuthMiddleware (admin role
 * required). Lists the immutable admin audit trail newest-first (recent 100)
 * with the total count in the header.
 */
#[Route(path: '/mark/audit', method: 'GET', name: 'mark.audit')]
final class AuditAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        $entries = AuditServices::audit()->recent(100);
        $total = AuditServices::audit()->count();

        $rows = '';

        foreach ($entries as $entry) {
            $rows .= $this->row($entry);
        }

        $content = '<p class="m-muted">' . $total . ' logged actions (append-only, immutable).</p>';

        if ([] === $entries) {
            $content .= '<p class="m-muted">No admin actions logged yet.</p>';
        } else {
            $content .= '<div class="m-card m-card-flush"><table class="m-table">'
                . '<thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Target</th><th>Detail</th><th>IP</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table></div>';
        }

        return Response::html(AdminLayout::render('Audit log', $content, 'audit', router: $this->router()));
    }

    private function row(AuditEntry $entry): string
    {
        $datetime = $entry->createdAt()->format('Y-m-d\TH:i:s');
        $time = AdminLayout::relativeTime($datetime);
        $actor = AdminLayout::e($entry->actorName());
        $action = '<span class="m-badge m-badge-success">' . AdminLayout::e($entry->action()) . '</span>';
        $target = AdminLayout::e($entry->target());
        $detail = AdminLayout::e($entry->detail());
        $ip = AdminLayout::e((string) ($entry->ipAddress() ?? ''));

        return '<tr>'
            . '<td>' . $time . '</td>'
            . '<td>' . $actor . '</td>'
            . '<td>' . $action . '</td>'
            . '<td>' . $target . '</td>'
            . '<td>' . $detail . '</td>'
            . '<td>' . $ip . '</td>'
            . '</tr>';
    }
}
