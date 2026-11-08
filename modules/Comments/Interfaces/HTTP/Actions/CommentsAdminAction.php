<?php

declare(strict_types = 1);

namespace Modules\Comments\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Comments\Domain\Entities\Comment;
use Modules\Comments\Interfaces\HTTP\CommentsServices;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * Comments admin action — moderation queue at /mark/comments.
 *
 * The /mark prefix is already protected by AuthMiddleware (admin role
 * required). Lists comments pending-first with approve/reject/delete POST
 * actions; every state change redirects back to the list.
 */
#[Route(path: '/mark/comments', method: 'GET', name: 'mark.comments')]
#[Route(path: '/mark/comments/{id}/approve', method: 'POST', name: 'mark.comments.approve', handlerMethod: 'approve')]
#[Route(path: '/mark/comments/{id}/reject', method: 'POST', name: 'mark.comments.reject', handlerMethod: 'reject')]
#[Route(path: '/mark/comments/{id}/delete', method: 'POST', name: 'mark.comments.delete', handlerMethod: 'delete')]
final class CommentsAdminAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        return Response::html($this->page());
    }

    public function approve(Request $request): Response
    {
        $this->moderate($request, 'comments.approve', 'Approved comment', fn (string $id): bool => CommentsServices::comments()->approve($id));

        return Response::redirect($this->url('mark.comments'));
    }

    public function reject(Request $request): Response
    {
        $this->moderate($request, 'comments.reject', 'Rejected comment', fn (string $id): bool => CommentsServices::comments()->reject($id));

        return Response::redirect($this->url('mark.comments'));
    }

    public function delete(Request $request): Response
    {
        $this->moderate($request, 'comments.delete', 'Deleted comment', fn (string $id): bool => CommentsServices::comments()->delete($id));

        return Response::redirect($this->url('mark.comments'));
    }

    /**
     * Run a moderation operation on the route id and record an audit entry.
     * No-op (no audit row) when the id is missing.
     *
     * @param \Closure(string): bool $operation
     */
    private function moderate(Request $request, string $action, string $detail, \Closure $operation): void
    {
        $id = $request->routeParam('id');

        if (null !== $id && '' !== $id) {
            $operation($id);
            CacheInvalidator::flush();
            AuditServices::log($action, 'comment:' . $id, $detail . ' ' . $id, $request->getClientIp());
        }
    }

    private function page(): string
    {
        $comments = CommentsServices::comments()->all();
        $csrf = AdminLayout::csrfField();

        if ([] === $comments) {
            $content = '<p class="m-muted">No comments yet.</p>';
        } else {
            $rows = '';

            foreach ($comments as $comment) {
                $rows .= $this->row($comment, $csrf);
            }

            $content = '<table class="m-table"><thead><tr>'
                . '<th>Article</th><th>Author</th><th>Comment</th><th>Status</th><th></th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table>';
        }

        $pending = count(CommentsServices::comments()->pending());
        $summary = '<p class="m-muted">' . $pending . ' pending · ' . count($comments)
            . ' total</p>' . $content;

        return AdminLayout::render('Comments', $summary, 'comments', router: $this->router());
    }

    private function row(Comment $comment, string $csrf): string
    {
        $id = AdminLayout::e($comment->id());
        $slug = AdminLayout::e($comment->articleSlug());
        $author = AdminLayout::e($comment->displayName());
        $body = AdminLayout::e($this->excerpt($comment->body(), 120));
        $status = AdminLayout::e($comment->status());
        $article = '<a href="' . AdminLayout::e('/blog/' . $comment->articleSlug()) . '">' . $slug . '</a>';

        return '<tr>'
            . '<td>' . $article . '</td>'
            . '<td>' . $author . '</td>'
            . '<td>' . $body . '</td>'
            . '<td><span class="m-badge ' . (Comment::STATUS_APPROVED === $comment->status() ? 'm-badge-success' : 'm-badge-error') . '">' . $status . '</span></td>'
            . '<td>' . $this->actions($id, $csrf, $comment->status()) . '</td>'
            . '</tr>';
    }

    /**
     * Approve/reject/delete POST forms — approved comments get reject+delete,
     * everything else gets approve+reject+delete.
     */
    private function actions(string $id, string $csrf, string $status): string
    {
        $approve = Comment::STATUS_APPROVED !== $status
            ? $this->form($id, $csrf, 'approve', 'Approve')
            : '';
        $reject = Comment::STATUS_REJECTED !== $status
            ? $this->form($id, $csrf, 'reject', 'Reject')
            : '';
        $delete = $this->form($id, $csrf, 'delete', 'Delete');

        return '<div class="m-btn-group">' . $approve . $reject . $delete . '</div>';
    }

    private function form(string $id, string $csrf, string $action, string $label): string
    {
        // Only the irreversible one asks: approve/reject re-moderate in place.
        $confirm = 'delete' === $action ? ' data-confirm="Delete this comment? This cannot be undone."' : '';
        $icon = match ($action) {
            'approve' => AdminLayout::iconApprove(),
            'reject'  => AdminLayout::iconReject(),
            default   => AdminLayout::iconDelete(),
        };

        return '<form method="POST" action="' . AdminLayout::e($this->url('mark.comments.' . $action, ['id' => $id]))
            . '" class="m-inline-form"' . $confirm . '>' . $csrf
            . '<button class="m-btn m-btn-sm m-btn-icon ' . ('delete' === $action ? 'm-btn-error' : 'm-btn-ghost')
            . '" data-tooltip="' . $label . '" title="' . $label . '">' . $icon . '</button></form>';
    }

    private function excerpt(string $body, int $length): string
    {
        if (mb_strlen($body) <= $length) {
            return $body;
        }

        return mb_substr($body, 0, $length) . '…';
    }
}
