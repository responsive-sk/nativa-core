<?php

declare(strict_types = 1);

namespace Modules\Notifications\Domain\Model;

/**
 * Notification — one line in one user's inbox.
 *
 * A row belongs to exactly ONE notifiable (a user id), not to the event: two
 * moderators are two recipients, and one reading it must not clear the other's
 * badge. That is why the read state is a column here instead of a shared
 * "read" on the event, and why `notifiable_id` appears in every write that
 * clears it (see `SqliteNotificationRepository::markRead()`).
 *
 * The row is a SNAPSHOT, like the queue payload it is written from
 * (`Modules\Users\Application\Services\NotificationPayload`): it describes what
 * happened at one moment, so the wording, the actor and the panel path travel
 * with it rather than being re-derived when the inbox is opened. Re-deriving
 * would describe a comment an admin has since rejected, or a panel that moved.
 *
 * Immutable after creation; reading it returns a new instance.
 */
final readonly class Notification
{
    private function __construct(
        private string $id,
        private string $notifiableId,
        private string $kind,
        private string $title,
        private string $body,
        private string $href,
        private ?string $readAt,
        private string $createdAt,
    ) {
    }

    /**
     * A new, unread notification for one recipient.
     *
     * `$href` is the panel surface the notification is about, root-relative —
     * the producer owns that path (`NotificationPayload` carries it), so this
     * model never composes one.
     */
    public static function create(
        string $notifiableId,
        string $kind,
        string $title,
        string $body,
        string $href,
    ): self {
        return new self(
            id: bin2hex(random_bytes(13)),
            notifiableId: $notifiableId,
            kind: $kind,
            title: $title,
            body: $body,
            href: $href,
            readAt: null,
            createdAt: date('Y-m-d H:i:s'),
        );
    }

    /**
     * Hydrate from a database row.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? '';
        $notifiableId = $data['notifiable_id'] ?? '';
        $kind = $data['kind'] ?? '';
        $title = $data['title'] ?? '';
        $body = $data['body'] ?? '';
        $href = $data['href'] ?? '';
        $readAt = $data['read_at'] ?? null;
        $createdAt = $data['created_at'] ?? '';

        return new self(
            id: is_string($id) ? $id : '',
            notifiableId: is_string($notifiableId) ? $notifiableId : '',
            kind: is_string($kind) ? $kind : '',
            title: is_string($title) ? $title : '',
            body: is_string($body) ? $body : '',
            href: is_string($href) ? $href : '',
            readAt: is_string($readAt) && '' !== $readAt ? $readAt : null,
            createdAt: is_string($createdAt) ? $createdAt : '',
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'notifiable_id' => $this->notifiableId,
            'kind'          => $this->kind,
            'title'         => $this->title,
            'body'          => $this->body,
            'href'          => $this->href,
            'read_at'       => $this->readAt,
            'created_at'    => $this->createdAt,
        ];
    }

    /**
     * The same row, read. Nothing else changes — reading is not an edit.
     */
    public function withRead(): self
    {
        return new self(
            id: $this->id,
            notifiableId: $this->notifiableId,
            kind: $this->kind,
            title: $this->title,
            body: $this->body,
            href: $this->href,
            readAt: $this->readAt ?? date('Y-m-d H:i:s'),
            createdAt: $this->createdAt,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function notifiableId(): string
    {
        return $this->notifiableId;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function href(): string
    {
        return $this->href;
    }

    public function readAt(): ?string
    {
        return $this->readAt;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    /**
     * Whether this row is addressed to that user — the answer every write
     * checks before clearing a read state from an id that arrived in a URL.
     */
    public function belongsTo(string $notifiableId): bool
    {
        return $this->notifiableId === $notifiableId;
    }
}
