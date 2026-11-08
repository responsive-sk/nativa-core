<?php

declare(strict_types = 1);

namespace Modules\Users\Tests;

use Modules\Core\Domain\Contracts\Mailer;
use Modules\Users\Application\Services\AdminNotificationHandler;
use Modules\Users\Application\Services\AdminNotifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AdminNotificationTest — what an operator's inbox actually receives.
 *
 * The handler is the only place that decides the subject and the shape of the
 * message, and a queue job is invisible when it is wrong: the panel says
 * "processed 1", the row disappears, and the mail that arrives says nothing
 * useful. So each kind is asserted for the four things a reader needs — where it
 * happened, who did it, what they wrote, and where to act — and the negative
 * cases (an unknown kind, no configured address) are asserted to send NOTHING
 * rather than a half-built message.
 *
 * The mailer is a recorder implemented right here: no transport, no disk, no
 * network. What the transports do with a message is
 * `Tests\Mail\MailerTest`'s subject, not this file's.
 */
final class AdminNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Kernel-only checkouts carry no Users slice: the kernel suite runs the
        // module tests too, and a missing class must skip the file rather than
        // fail it. Same guard as PasswordResetMailerTest.
        if (!is_dir(APP_BASE_PATH . '/src/Modules/Users')) {
            $this->markTestSkipped('Users is not installed.');
        }
    }

    #[Test]
    public function aCommentNotificationNamesTheArticleAuthorTextAndPanel(): void
    {
        $mailer = $this->recorder();

        $sent = $this->handler($mailer, 'owner@example.test')->handle([
            'kind'    => 'comment',
            'target'  => 'why-a-port',
            'author'  => 'Eva',
            'excerpt' => 'This helped, thanks.',
            'panel'   => '/mark/comments',
        ]);

        self::assertTrue($sent);
        self::assertCount(1, $mailer->calls);

        [$to, $subject, $text] = $mailer->calls[0];

        self::assertSame('owner@example.test', $to);
        self::assertSame('New comment awaiting moderation', $subject);
        self::assertStringContainsString('Article: why-a-port', $text);
        self::assertStringContainsString('From: Eva', $text);
        self::assertStringContainsString('This helped, thanks.', $text);
        self::assertStringContainsString('https://app.example.test/mark/comments', $text);
    }

    #[Test]
    public function aQuestionNotificationPointsAtItsOwnPanel(): void
    {
        $mailer = $this->recorder();

        $this->handler($mailer, 'owner@example.test')->handle([
            'kind'    => 'question',
            'target'  => 'article/why-a-port',
            'author'  => 'Peter',
            'excerpt' => 'What about the second port?',
            'panel'   => '/mark/moderation',
        ]);

        [$to, $subject, $text] = $mailer->calls[0];

        self::assertSame('New question awaiting moderation', $subject);
        self::assertStringContainsString('About: article/why-a-port', $text);
        self::assertStringContainsString('https://app.example.test/mark/moderation', $text);
        self::assertStringContainsString('What about the second port?', $text);
    }

    #[Test]
    public function aPayloadWithoutAPanelStillSendsButWithoutALink(): void
    {
        $mailer = $this->recorder();

        $this->handler($mailer, 'owner@example.test')->handle([
            'kind'    => 'comment',
            'target'  => 'why-a-port',
            'author'  => 'Eva',
            'excerpt' => 'No panel path in this payload.',
        ]);

        $text = $mailer->calls[0][2];

        self::assertStringContainsString('No panel path in this payload.', $text);
        self::assertStringNotContainsString('Moderate:', $text, 'a blank panel path must not produce a dangling link');
    }

    #[Test]
    public function anUnknownKindSendsNothing(): void
    {
        $mailer = $this->recorder();

        $sent = $this->handler($mailer, 'owner@example.test')->handle(['kind' => 'telegram']);

        self::assertFalse($sent);
        self::assertSame([], $mailer->calls, 'a kind this version does not know must never produce a half-built message');
    }

    /**
     * No address configured is a deployment state, not a failure of the event:
     * nothing is sent to the seeded `admin@example.test` placeholder, because a
     * bounce to a reserved domain is a notification nobody ever sees.
     */
    #[Test]
    public function withoutAnAddressNothingIsSent(): void
    {
        $mailer = $this->recorder();

        $sent = $this->handler($mailer, '')->handle([
            'kind'    => 'comment',
            'target'  => 'why-a-port',
            'author'  => 'Eva',
            'excerpt' => 'Hello.',
            'panel'   => '/mark/comments',
        ]);

        self::assertFalse($sent);
        self::assertSame([], $mailer->calls);
    }

    #[Test]
    public function aLongBodyIsTrimmedToTheReadableExcerpt(): void
    {
        $mailer = $this->recorder();

        $this->handler($mailer, 'owner@example.test')->handle([
            'kind'    => 'comment',
            'target'  => 'why-a-port',
            'author'  => 'Eva',
            'excerpt' => str_repeat('a', 1000),
            'panel'   => '/mark/comments',
        ]);

        $text = $mailer->calls[0][2];

        self::assertStringContainsString('aaa…', $text);
        self::assertStringNotContainsString(str_repeat('a', 401), $text, 'the message is an excerpt, not the page');
    }

    /**
     * A textarea body keeps its newlines; the mail is a paragraph, so runs of
     * whitespace collapse instead of producing a body with holes in it.
     */
    #[Test]
    public function lineBreaksInUserTextCollapseToSpaces(): void
    {
        $mailer = $this->recorder();

        $this->handler($mailer, 'owner@example.test')->handle([
            'kind'    => 'comment',
            'target'  => 'why-a-port',
            'author'  => 'Eva',
            'excerpt' => "First line.\n\n   Second line.",
            'panel'   => '/mark/comments',
        ]);

        self::assertStringContainsString('First line. Second line.', $mailer->calls[0][2]);
    }

    private function handler(Mailer $mailer, string $recipient): AdminNotificationHandler
    {
        return new AdminNotificationHandler(
            new AdminNotifier($mailer, $recipient, 'https://app.example.test'),
        );
    }

    private function recorder(): object
    {
        return new class () implements Mailer {
            /** @var list<array{string, string, string}> */
            public array $calls = [];

            public function send(string $to, string $subject, string $text): bool
            {
                $this->calls[] = [$to, $subject, $text];

                return true;
            }
        };
    }
}
