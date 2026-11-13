<?php

declare(strict_types = 1);

namespace Modules\Users\Application\Services;

use Modules\Users\Domain\Contracts\Mailer;
use Modules\Core\Infrastructure\Env;
use Modules\Users\Infrastructure\Mail\MailerFactory;
use Modules\Settings\Interfaces\HTTP\SettingsServices;

/**
 * AdminNotifier — where an operator notification goes, and with what reply
 * address.
 *
 * The recipient is `admin_email` from Settings, not a new environment variable:
 * the panel already asks for that address in `/mark/settings` (the migration
 * seeds `admin@example.test`, so a fresh checkout demonstrably has not chosen
 * one yet), and a second place to name "the admin" is a second place to get it
 * wrong. With no address configured nothing is sent and the log says so — a
 * notification to a placeholder domain is a bounce this deployment would never
 * see.
 *
 * The transport comes from `MailerFactory`: the dev log in a development
 * environment, the configured HTTP endpoint in production, and a real null
 * answer when neither exists. This class never picks a transport itself, so the
 * delivery policy has one home.
 */
final readonly class AdminNotifier
{
    /**
     * @param string $baseUrl absolute prefix for the moderation links (`APP_URL`),
     *                        empty when the deployment did not configure one — in
     *                        which case the links stay root-relative and still work
     *                        for a reader already in the panel
     */
    public function __construct(
        private Mailer $mailer,
        private string $recipient,
        private string $baseUrl,
    ) {
    }

    /**
     * The notifier this deployment sends with — the only method that reads
     * Settings and the environment, so tests construct the object directly.
     */
    public static function create(): self
    {
        $stored = SettingsServices::settings()->get('admin_email', '');

        return new self(
            MailerFactory::create(),
            is_string($stored) ? trim($stored) : '',
            rtrim(trim((string) (Env::get('APP_URL') ?? '')), '/'),
        );
    }

    public function send(string $subject, string $text): bool
    {
        if ('' === $this->recipient) {
            error_log(sprintf(
                '[notifications] "%s" was not sent — admin_email is empty; set it at /mark/settings',
                $subject,
            ));

            return false;
        }

        return $this->mailer->send($this->recipient, $subject, $text);
    }

    /**
     * A panel link: absolute when `APP_URL` is configured, root-relative when it
     * is not. Both open the same page for an operator already signed in.
     */
    public function link(string $path): string
    {
        return $this->baseUrl . $path;
    }
}
