<?php

declare(strict_types = 1);

namespace Modules\Outbox\Interfaces\HTTP\Actions;

use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Outbox\Domain\Model\OutboxMessage;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;

/**
 * Outbox admin action — message browser at /mark/outbox.
 *
 * The /mark prefix is already protected by AuthMiddleware (admin role
 * required). Read-only: delivery stays `outbox:process` / `schedule:run`,
 * this page only shows what is waiting, what moved and what failed.
 */
#[Route(path: '/mark/outbox', method: 'GET', name: 'mark.outbox')]
#[Route(path: '/mark/outbox/{id}', method: 'GET', name: 'mark.outbox.detail', handlerMethod: 'detail')]
final class OutboxAdminAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        return Response::html($this->listPage($request));
    }

    public function detail(Request $request): Response
    {
        $id = (string) ($request->routeParam('id') ?? '');
        $message = '' !== $id ? OutboxServices::repository()->findById($id) : null;

        if (null === $message) {
            return Response::html(
                AdminLayout::render('Message not found', '<p class="m-muted">No message with this id.</p>', router: $this->router()),
                404,
            );
        }

        return Response::html($this->detailPage($message));
    }

    private function listPage(Request $request): string
    {
        $filter = $request->query('status', null);
        $filter = is_string($filter) && '' !== $filter ? $filter : null;

        $repository = OutboxServices::repository();
        $messages = $repository->listMessages($filter);
        $counts = $repository->countByStatus();

        $summary = '<p class="m-muted">' . $repository->countTotal($filter) . ' messages'
            . (null !== $filter ? ' with status “' . AdminLayout::e($filter) . '”' : '')
            . ' · ' . $counts['pending'] . ' pending · ' . $counts['failed'] . ' failed</p>';

        if ([] === $messages) {
            $content = $summary . '<p class="m-muted">No messages.</p>';
        } else {
            $rows = '';

            foreach ($messages as $message) {
                $rows .= $this->row($message);
            }

            $content = $summary
                . '<table class="m-table"><thead><tr>'
                . '<th>Id</th><th>Type</th><th>Status</th><th>Attempts</th><th>Created</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table>';
        }

        return AdminLayout::render('Outbox', $content, 'outbox', router: $this->router());
    }

    private function row(OutboxMessage $message): string
    {
        $id = $message->id();

        return '<tr>'
            . '<td><a href="' . AdminLayout::e($this->url('mark.outbox.detail', ['id' => $id])) . '" class="m-mono">' . AdminLayout::e(substr($id, 0, 8)) . '</a></td>'
            . '<td class="m-mono">' . AdminLayout::e($message->eventType()) . '</td>'
            . '<td>' . $this->badge($message->status()) . '</td>'
            . '<td class="m-mono">' . $message->attempts() . '/' . $message->maxAttempts() . '</td>'
            . '<td class="m-mono">' . AdminLayout::e($message->createdAt()) . '</td>'
            . '</tr>';
    }

    private function badge(string $status): string
    {
        $modifier = match ($status) {
            'processed' => ' m-badge-success',
            'failed' => ' m-badge-error',
            default => '',
        };

        return '<span class="m-badge' . $modifier . '">' . AdminLayout::e($status) . '</span>';
    }

    private function detailPage(OutboxMessage $message): string
    {
        $rows = '';
        foreach ([
            'Type' => $message->eventType(),
            'Status' => $message->status(),
            'Attempts' => $message->attempts() . '/' . $message->maxAttempts(),
            'Last attempt' => $message->lastAttemptAt() ?? '—',
            'Processed at' => $message->processedAt() ?? '—',
            'Created' => $message->createdAt(),
        ] as $label => $value) {
            $rows .= '<tr><td>' . $label . '</td><td class="m-mono">' . AdminLayout::e($value) . '</td></tr>';
        }

        $error = $message->errorMessage();

        $content = '<table class="m-table"><tbody>' . $rows . '</tbody></table>'
            . '<h2 class="m-heading">Payload</h2>'
            . '<pre class="m-mono" style="overflow:auto">' . AdminLayout::e($this->pretty($message->payload())) . '</pre>'
            . '<h2 class="m-heading">Metadata</h2>'
            . '<pre class="m-mono" style="overflow:auto">' . AdminLayout::e($this->pretty($message->metadata() ?? [])) . '</pre>'
            . (null !== $error
                ? '<h2 class="m-heading">Error</h2><pre class="m-mono" style="overflow:auto">' . AdminLayout::e($error) . '</pre>'
                : '')
            . '<p><a href="' . AdminLayout::e($this->url('mark.outbox')) . '">← Back to messages</a></p>';

        return AdminLayout::render('Message ' . substr($message->id(), 0, 8), $content, 'outbox', router: $this->router());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function pretty(array $data): string
    {
        $pretty = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($pretty) ? $pretty : '{}';
    }
}
