<?php

declare(strict_types = 1);

namespace Modules\Blog\Application\Services;

use Modules\Queue\Interfaces\HTTP\QueueServices;

/**
 * CoverJobs — the queue side of the article cover.
 *
 * THE FIRST REAL PRODUCER IN THIS TREE. Until now `DatabaseQueue::push()` had
 * no caller outside the module's own tests, so the queue shipped a worker with
 * nothing to deliver. The cover is that job: rendering an SVG (and optionally a
 * raster variant) from an article's title, slug and code evidence is slow,
 * purely derived work that nothing in a request should wait for — and it was
 * already runnable only by hand, through `bin/cms generate-images`.
 *
 * WHY THIS TRIGGER, MEASURED (Boot 32, task 4.1):
 *
 *   The obvious place to hang a producer is the outbox, which is this tree's
 *   established async mechanism. It is the wrong place. `article.published` and
 *   `article.unpublished` are recorded in exactly ONE place —
 *   `ArticlePublishToggleAction` — while create, edit, inline-edit and delete
 *   all flush the cache directly and record nothing:
 *
 *     ArticlesCreateAction   outbox: no   cache flush: yes
 *     ArticlesEditAction     outbox: no   cache flush: yes
 *     BlogInlineEditAction   outbox: no   cache flush: yes
 *     ArticlesDeleteAction   outbox: no   cache flush: yes
 *     ArticlePublishToggle   outbox: YES  cache flush: yes
 *
 *   A cover tracks CONTENT, so the publish toggle is wrong twice over: it fires
 *   on unpublish, when nothing is served at all, and it misses a later edit of
 *   an already-published article, which is exactly when a stale cover shows up
 *   on the page. So the producer sits where the cache is already flushed for a
 *   content write — the same "the write has happened, now tell the world"
 *   moment.
 *
 * THE PAYLOAD IS TWO SCALARS, and that is the property worth keeping. It is
 * `{slug, locale}` — identifiers, not content. No token, no body, no file
 * handle, no rendered SVG. Two reasons, and the second is why the obvious
 * first candidate was rejected:
 *
 *   - A `jobs.payload` row and a `failed_jobs.payload` row both sit in
 *     `storage/data/queue.db`, and the payload is rendered on screen at
 *     `/mark/queue/{id}`. A credential in a payload is a credential in a second
 *     database and on a page — which is what the audit found when a reset token
 *     turned up in `app.log`. The queued reset link is the obvious producer and
 *     it is still NOT this one, for exactly this reason.
 *   - A payload of ids re-reads its source, so a job that runs late renders the
 *     article as it is then, not as it was when queued. That is what an operator
 *     wants from a derived artefact, and it is why no snapshot is stored.
 *
 * The rendering options (`--template`, `--quality`, `--format`) are deliberately
 * NOT in the payload: they are an operator's choice for a deliberate run of
 * `generate-images`, not part of writing an article. This job renders with the
 * defaults.
 *
 * The dependency is declared, not guarded: `QueueServices` is imported, so
 * `module:show Blog` lists Queue in Blog's closure and `module:add Blog`
 * installs it. That is the same treatment `Outbox` already gets from
 * `ArticlePublishToggleAction` — a dependency that is really there, in the
 * graph, where an operator can see it. The alternative (a `class_exists` guard
 * with the class named inline and no `use`) would hide it from the closure scan
 * and that hatch exists for dependencies that are genuinely absent without a
 * module, which this is not.
 */
final class CoverJobs
{
    /**
     * The handler name, which is also what the worker looks up in the registry.
     */
    public const string HANDLER = 'blog.cover';

    /**
     * Ask for one article's cover to be rendered.
     *
     * Fire and forget by design: the article has already been written, so a
     * failure to queue the render must not surface as a failed write — the same
     * contract `OutboxServices::articleEvent()` states, for the same reason.
     * A cover that is missing is visible (no image); a failed publish is not.
     *
     * The call below is UNGUARDED and the failure is still CONTAINED, and both
     * are deliberate — they answer different questions:
     *
     *   - Unguarded, because `QueueServices` is imported. That is what puts
     *     Queue in Blog's closure and makes `module:add Blog` refuse without it.
     *     A `class_exists` guard with the class named inline would hide the
     *     dependency from `module:show`, and an operator has to be able to see
     *     that Blog needs a queue.
     *   - Contained by the catch, because a tree that installed Blog BEFORE
     *     this commit still has no Queue installed, and `module:show`'s refusal
     *     only applies to the next `module:add`. Measured with the Queue slice
     *     removed: `Class "Modules\Queue\Interfaces\HTTP\QueueServices" not
     *     found` is logged as `[BLOG] Could not queue the cover for …` and the
     *     write returns false. The article still saves; the cover is simply not
     *     queued, and the log says why.
     *
     * So the degradation is: no queue, no covers, articles unaffected.
     *
     * @return bool false when the job could not be queued
     */
    public static function request(string $slug, string $locale): bool
    {
        if ('' === $slug) {
            return false;
        }

        try {
            QueueServices::queue()->push(
                self::HANDLER,
                ['slug' => $slug, 'locale' => $locale],
                // Two attempts: the handler is idempotent (it rewrites the same
                // deterministic bytes), so a retry is safe, and three would only
                // add a third pass over an already-good file.
                maxAttempts: 2,
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[BLOG] Could not queue the cover for "%s" (%s): %s',
                $slug,
                $locale,
                $e->getMessage(),
            ));

            return false;
        }

        return true;
    }
}