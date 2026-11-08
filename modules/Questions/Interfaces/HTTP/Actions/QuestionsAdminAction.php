<?php

declare(strict_types = 1);

namespace Modules\Questions\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Core\Interfaces\HTTP\ContentServices;
use Modules\Questions\Application\QuestionsServices;
use Modules\Questions\Domain\Entities\Question;

/**
 * GET /mark/questions — the author inbox: reader questions grouped by subject.
 *
 * The inbox is grouped by the DISCRIMINATED SUBJECT (type + slug + locale) and
 * not listed flat, because the whole product is "where did readers stall":
 * a flat list ordered by date answers "what arrived most recently", which is a
 * different question. Counts per group are what an author actually acts on —
 * four questions on one article is a different signal from one question on
 * four articles.
 *
 * Each row exposes approve / reject / delete as inline POST forms carrying the
 * CSRF token. Every one of them is audited (`questions.*`, mirroring
 * `comments.*`) and flushes the page cache, because approving a question
 * publishes it onto the article — a cached article would keep showing the old
 * answer set until the cache happened to expire.
 *
 * There is NO bulk endpoint here. Moderation is per question, deliberately:
 * approving is a judgement about one reader's words, and a bulk path would be
 * an unclassified entity in `AdminRoutePolicy::permissionForEntity()`.
 */
#[Route(path: '/mark/questions', method: 'GET', name: 'mark.questions')]
#[Route(path: '/mark/questions/{id}/approve', method: 'POST', name: 'mark.questions.approve', handlerMethod: 'approve')]
#[Route(path: '/mark/questions/{id}/reject', method: 'POST', name: 'mark.questions.reject', handlerMethod: 'reject')]
#[Route(path: '/mark/questions/{id}/delete', method: 'POST', name: 'mark.questions.delete', handlerMethod: 'delete')]
final class QuestionsAdminAction
{
    use ResolvesRouteUrls;

    /** @var list<string> The statuses the `?status=` filter accepts. */
    private const array STATUSES = [
        Question::STATUS_PENDING,
        Question::STATUS_APPROVED,
        Question::STATUS_REJECTED,
    ];

    public function handle(Request $request): Response
    {
        return Response::html($this->page($request));
    }

    public function approve(Request $request): Response
    {
        $this->moderate($request, 'questions.approve', 'Approved question', static function (string $id): void {
            QuestionsServices::repository()->setStatus($id, Question::STATUS_APPROVED);
        });

        return Response::redirect($this->url('mark.questions'));
    }

    public function reject(Request $request): Response
    {
        $this->moderate($request, 'questions.reject', 'Rejected question', static function (string $id): void {
            QuestionsServices::repository()->setStatus($id, Question::STATUS_REJECTED);
        });

        return Response::redirect($this->url('mark.questions'));
    }

    public function delete(Request $request): Response
    {
        $this->moderate($request, 'questions.delete', 'Deleted question', static function (string $id): void {
            QuestionsServices::repository()->delete($id);
        });

        return Response::redirect($this->url('mark.questions'));
    }

    /**
     * Run a moderation operation on the route id and record an audit entry.
     * No-op (no audit row) when the id is missing.
     *
     * @param \Closure(string): void $operation
     */
    private function moderate(Request $request, string $action, string $detail, \Closure $operation): void
    {
        $id = $request->routeParam('id');

        if (null === $id || '' === $id) {
            return;
        }

        $operation($id);
        CacheInvalidator::flush();
        AuditServices::log($action, 'question:' . $id, $detail . ' ' . $id, $request->getClientIp());
    }

    private function page(Request $request): string
    {
        $status = $this->statusFilter($request);
        $all = QuestionsServices::repository()->all();
        $shown = null === $status
            ? $all
            : array_values(array_filter($all, static fn (Question $q): bool => $status === $q->status()));

        $csrf = AdminLayout::csrfField();
        $body = [] === $shown
            ? '<p class="m-muted">No questions' . (null === $status ? ' yet.' : ' with that status.') . '</p>'
            : $this->groups($shown, $csrf);

        return AdminLayout::render(
            'Questions',
            $this->summary($all, $status) . $this->filterBar($status) . $body,
            'questions',
        router: $this->router());
    }

    /**
     * One card per subject, in the order the questions arrive (pending first).
     *
     * @param list<Question> $questions
     */
    private function groups(array $questions, string $csrf): string
    {
        $groups = [];

        foreach ($questions as $question) {
            $key = $question->subjectKey();
            $groups[$key['type'] . '/' . $key['slug'] . '@' . $key['locale']][] = $question;
        }

        $html = '';

        foreach ($groups as $group) {
            $html .= $this->groupCard($group, $csrf);
        }

        return $html;
    }

    /**
     * A group header naming the subject, its locale and the counts per status —
     * then the rows themselves.
     *
     * @param list<Question> $questions
     */
    private function groupCard(array $questions, string $csrf): string
    {
        $first = $questions[0];
        $key = $first->subjectKey();
        $counts = self::emptyStatusCounts();

        foreach ($questions as $question) {
            $counts[$question->status()] = ($counts[$question->status()] ?? 0) + 1;
        }

        $rows = '';

        foreach ($questions as $question) {
            $rows .= $this->row($question, $csrf);
        }

        return '<div class="m-card">'
            . '<div class="m-card-header">'
            . '<h2 class="m-card-title">' . AdminLayout::e($key['type']) . ' / ' . AdminLayout::e($key['slug']) . '</h2>'
            . '<span class="m-badge m-badge-gray">' . AdminLayout::e($key['locale']) . '</span>'
            . '</div>'
            . '<p class="m-muted">' . $this->counts($counts) . '</p>'
            . $this->articleEditLink($key['type'], $key['slug'], $key['locale'])
            . $this->table($rows)
            . '</div>';
    }

    private function articleEditLink(string $type, string $slug, string $locale): string
    {
        if (Question::SUBJECT_ARTICLE !== $type) {
            return '';
        }

        $language = ContentServices::mapper()->languageCode($locale);
        $href = $this->url('articles.edit', ['slug' => rawurlencode($slug)]) . '?locale=' . rawurlencode($language);

        return '<p><a class="m-btn m-btn-ghost" href="' . AdminLayout::e($href) . '">Review article</a></p>';
    }

    private function table(string $rows): string
    {
        return '<table class="m-table"><thead><tr>'
            . '<th>Reader</th><th>Question</th><th>What they tried</th><th>Status</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param array<string, int> $counts
     */
    /**
     * The zeroed counters both headers start from, keyed by the OWNER of the
     * status vocabulary. A hand-written key here is how a renamed status
     * becomes a silently empty counter, and how a status added to `Question`
     * disappears from the inbox without a failing test.
     *
     * @return array<string, int>
     */
    private static function emptyStatusCounts(): array
    {
        return [
            Question::STATUS_PENDING => 0,
            Question::STATUS_APPROVED => 0,
            Question::STATUS_REJECTED => 0,
        ];
    }

    private function counts(array $counts): string
    {
        $parts = [];

        foreach (self::STATUSES as $status) {
            $parts[] = $counts[$status] . ' ' . $status;
        }

        return implode(' · ', $parts);
    }

    private function row(Question $question, string $csrf): string
    {
        $id = AdminLayout::e($question->id());
        $name = AdminLayout::e($question->displayName());
        $text = AdminLayout::e($question->text());
        $answer = AdminLayout::e($question->attemptedAnswer());

        return '<tr>'
            . '<td>' . $name . '<br><span class="m-muted">'
            . AdminLayout::e($question->createdAt()->format('Y-m-d H:i')) . '</span></td>'
            . '<td>' . $text . '</td>'
            . '<td>' . $answer . '</td>'
            . '<td><span class="m-badge ' . $this->badgeClass($question->status()) . '">'
            . AdminLayout::e($question->status()) . '</span></td>'
            . '<td>' . $this->actions($id, $csrf, $question->status()) . '</td>'
            . '</tr>';
    }

    private function badgeClass(string $status): string
    {
        return match ($status) {
            Question::STATUS_APPROVED => 'm-badge-success',
            Question::STATUS_REJECTED => 'm-badge-error',
            default                  => 'm-badge-warning',
        };
    }

    /**
     * Approve/reject/delete POST forms — an approved question is offered only
     * reject + delete, a rejected one only approve + delete.
     */
    private function actions(string $id, string $csrf, string $status): string
    {
        $approve = Question::STATUS_APPROVED === $status ? '' : $this->form($id, $csrf, 'approve', 'Approve');
        $reject = Question::STATUS_REJECTED === $status ? '' : $this->form($id, $csrf, 'reject', 'Reject');

        return '<div class="m-btn-group">' . $approve . $reject . $this->form($id, $csrf, 'delete', 'Delete') . '</div>';
    }

    private function form(string $id, string $csrf, string $action, string $label): string
    {
        // Only the irreversible one asks: approve/reject re-moderate in place.
        $confirm = 'delete' === $action ? ' data-confirm="Delete this question? This cannot be undone."' : '';
        $icon = match ($action) {
            'approve' => AdminLayout::iconApprove(),
            'reject'  => AdminLayout::iconReject(),
            default   => AdminLayout::iconDelete(),
        };

        return '<form method="POST" action="' . AdminLayout::e($this->url('mark.questions.' . $action, ['id' => $id]))
            . '" class="m-inline-form"' . $confirm . '>' . $csrf
            . '<button class="m-btn m-btn-sm m-btn-icon ' . ('delete' === $action ? 'm-btn-error' : 'm-btn-ghost')
            . '" data-tooltip="' . $label . '" title="' . $label . '">' . $icon . '</button></form>';
    }

    /**
     * @param list<Question> $all
     */
    private function summary(array $all, ?string $status): string
    {
        $counts = self::emptyStatusCounts();

        foreach ($all as $question) {
            $counts[$question->status()] = ($counts[$question->status()] ?? 0) + 1;
        }

        return '<p class="m-muted">' . $this->counts($counts) . ' · showing '
            . ($status ?? 'all') . '</p>';
    }

    /**
     * The status filter. An unknown value is IGNORED rather than refused: a
     * stale bookmark or a hand-edited query must not turn the inbox into an
     * error page, and ignoring it shows every question, which is the honest
     * default for "I did not understand that filter".
     */
    private function statusFilter(Request $request): ?string
    {
        $status = $request->query('status');

        return \is_string($status) && \in_array($status, self::STATUSES, true) ? $status : null;
    }

    private function filterBar(?string $status): string
    {
        // NOT `static fn`: the pill's href comes from `$this->url()`, and a
        // static closure has no `$this` to reach. PHP only says so when the
        // line runs, so the panel answered 500 on the first request that
        // rendered it while every static gate, and the smoke's own list of
        // pages, passed. `admin-smoke.sh` opens this page now.
        $pill = fn (string $label, ?string $value): string => '<a class="m-pill'
            . ($value === $status ? ' m-pill-active' : '') . '" href="'
            . AdminLayout::e(null === $value ? $this->url('mark.questions') : $this->url('mark.questions') . '?status=' . rawurlencode($value))
            . '">' . $label . '</a>';

        $bar = $pill('All', null);

        foreach (self::STATUSES as $candidate) {
            $bar .= $pill($candidate, $candidate);
        }

        return '<div class="m-filter-bar">' . $bar . '</div>';
    }
}
