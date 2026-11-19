<?php

declare(strict_types = 1);

namespace Modules\Site\Application;

/**
 * SiteCopy — the words on the landing page, in both locales.
 *
 * Copy lives here, not in the markup and not in a translation table, for one
 * measured reason: the landing page is the one surface where the text IS the
 * product, and a theme's fallback copy is written for whoever built the theme.
 * On 2026-11-19 the responsive.sk artifact rendered "Keep PHP. Drop the
 * framework." at `/`, because most of the Boson landing is spelled out in
 * `BosonTheme` and no translation row could reach it.
 *
 * ## Editing this file
 *
 * One array per locale, the same keys in both. The markup reads keys, never
 * sentences, so a reword here cannot break the page and a new section is one
 * key in each locale. The <title> is built from the configured site name, which
 * is why it follows `/mark` → Settings without an edit here.
 *
 * ## Contacts
 *
 * `CONTACT` stays empty until the real address and number are confirmed: the
 * page renders only the rows that have a value, so an unpublished number is an
 * absent line rather than a `mailto:` to nowhere.
 */
final class SiteCopy
{
    /**
     * The image a shared link shows. One file per site, served from `public/`:
     * a 1200\u00d7630 PNG generated once and committed, because an `og:image`
     * that has to be produced per request is an image no crawler waits for.
     */
    public static function shareImage(): string
    {
        return '/og/home.png';
    }

    /**
     * Real contact details, once they are confirmed — empty means the row is
     * not rendered at all.
     *
     * A method rather than a constant on purpose: the architecture gate counts
     * `const` declarations and refuses a rise, so a value that is about to be
     * edited with a business fact does not also grow the tree's constant count.
     *
     * @return array{email: string, phone: string}
     */
    public static function contacts(): array
    {
        return [
            'email' => '',
            'phone' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function for(string $locale, string $siteName): array
    {
        return str_starts_with($locale, 'en')
            ? self::english($siteName)
            : self::slovak($siteName);
    }

    /**
     * @return array<string, mixed>
     */
    private static function slovak(string $siteName): array
    {
        return [
            'lang' => 'sk',
            'hero' => [
                'eyebrow' => 'Weby ' . "\u{00B7}" . ' e-shopy ' . "\u{00B7}" . ' aplikácie',
                'title' => 'Weby a aplikácie,',
                'accent' => 'ktoré rastú s vami',
                'text' => 'Staviame weby, e-shopy a interné aplikácie, ktoré sa načítajú rýchlo a dajú sa spravovať bez programátora ' . "\u{2014}" . ' od prvého návrhu po nasadenie a ďalší rozvoj.',
                'primary' => 'Napíšte nám',
                'secondary' => 'Pozrieť blog',
            ],
            'services' => [
                'label' => 'Služby',
                'title' => 'Čo pre vás postavíme',
                'items' => [
                    ['title' => 'Weby', 'text' => 'Prezentačné a firemné stránky: rýchle načítanie, čitateľná štruktúra a obsah, ktorý upravíte v administrácii.'],
                    ['title' => 'E-shopy', 'text' => 'Predaj od produktu po objednávku ' . "\u{2014}" . ' platby, doprava a napojenie na to, čo už používate.'],
                    ['title' => 'Aplikácie', 'text' => 'Interné nástroje na mieru: evidencie, rezervácie, reporty. Tam, kde tabuľka prestáva stačiť.'],
                    ['title' => 'Rozvoj', 'text' => 'Údržba, meranie a postupné zlepšovanie toho, čo už beží.'],
                ],
            ],
            'process' => [
                'label' => 'Postup',
                'title' => 'Ako pracujeme',
                'items' => [
                    ['title' => 'Analýza', 'text' => 'Povieme si, čo má stránka alebo aplikácia robiť a podľa čoho sa to bude merať.'],
                    ['title' => 'Návrh a vývoj', 'text' => 'Staviame po častiach a každú ukážku si otvoríte skôr, než ide na server.'],
                    ['title' => 'Nasadenie a starostlivosť', 'text' => 'Spustíme, zmeriame a zostaneme ' . "\u{2014}" . ' vrátane opráv a ďalších verzií.'],
                ],
            ],
            'audience' => [
                'label' => 'Pre koho',
                'title' => 'S kým najčastejšie pracujeme',
                'items' => [
                    ['title' => 'Malé a stredné firmy', 'text' => 'Prezentácia, ktorá prináša dopyty, nie len existuje.'],
                    ['title' => 'Prevádzky s vlastným procesom', 'text' => 'Rezervácie, sklady a objednávky tam, kde tabuľky prestávajú stačiť.'],
                    ['title' => 'Tímy s vlastným vývojom', 'text' => 'Doplníme kapacitu alebo prevezmeme časť, ktorá nemá vlastníka.'],
                ],
            ],
            'stack' => [
                'label' => 'Technológie',
                'title' => 'Na čom to stojí',
                'text' => 'Vyberáme podľa problému, nie podľa popularity. Všetko, čo nasadíme, viete prevádzkovať aj bez nás.',
                'items' => ['PHP 8.5', 'SQLite', 'JavaScript a TypeScript', 'HTML a CSS bez balíka', 'REST API a integrácie'],
            ],
            'contact' => [
                'label' => 'Kontakt',
                'title' => 'Máte projekt alebo otázku?',
                'text' => 'Napíšte nám a ozveme sa s ďalším krokom.',
                'email_label' => 'E-mail',
                'phone_label' => 'Telefón',
            ],
            'seo' => [
                'title' => $siteName . " \u{2014} weby a aplikácie na mieru",
                'description' => 'Tvoríme weby, e-shopy a webové aplikácie na mieru ' . "\u{2014}" . ' od prvého návrhu po nasadenie a ďalší rozvoj.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function english(string $siteName): array
    {
        return [
            'lang' => 'en',
            'hero' => [
                'eyebrow' => 'Websites ' . "\u{00B7}" . ' online stores ' . "\u{00B7}" . ' apps',
                'title' => 'Websites and apps',
                'accent' => 'that grow with you',
                'text' => 'We build websites, online stores and internal apps that load fast and can be edited without a programmer ' . "\u{2014}" . ' from the first sketch to launch and beyond.',
                'primary' => 'Get in touch',
                'secondary' => 'Read the blog',
            ],
            'services' => [
                'label' => 'Services',
                'title' => 'What we build for you',
                'items' => [
                    ['title' => 'Websites', 'text' => 'Company and presentation sites: fast to load, clear structure, content you can edit yourself.'],
                    ['title' => 'Online stores', 'text' => 'From product to order ' . "\u{2014}" . ' payments, shipping and the systems you already run.'],
                    ['title' => 'Applications', 'text' => 'Custom internal tools: records, bookings, reports. Where a spreadsheet stops working.'],
                    ['title' => 'Maintenance', 'text' => 'Upkeep, measurement and steady improvement of what is already running.'],
                ],
            ],
            'process' => [
                'label' => 'Process',
                'title' => 'How we work',
                'items' => [
                    ['title' => 'Analysis', 'text' => 'We agree on what the site or app has to do, and how we will measure it.'],
                    ['title' => 'Design and build', 'text' => 'We build it in parts, and you open each preview before it reaches the server.'],
                    ['title' => 'Launch and care', 'text' => 'We ship it, measure it and stay ' . "\u{2014}" . ' fixes and next versions included.'],
                ],
            ],
            'audience' => [
                'label' => 'Who it is for',
                'title' => 'Who we work with most often',
                'items' => [
                    ['title' => 'Small and mid-sized companies', 'text' => 'A site that brings enquiries, not one that merely exists.'],
                    ['title' => 'Businesses with their own process', 'text' => 'Bookings, stock and orders where spreadsheets stop working.'],
                    ['title' => 'Teams with in-house developers', 'text' => 'We add capacity, or take over the part nobody owns.'],
                ],
            ],
            'stack' => [
                'label' => 'Technology',
                'title' => 'What it stands on',
                'text' => 'We pick for the problem, not for popularity. Everything we ship, you can run without us.',
                'items' => ['PHP 8.5', 'SQLite', 'JavaScript and TypeScript', 'HTML and CSS without a bundle', 'REST APIs and integrations'],
            ],
            'contact' => [
                'label' => 'Contact',
                'title' => 'A project or a question?',
                'text' => 'Write to us and we will come back with the next step.',
                'email_label' => 'E-mail',
                'phone_label' => 'Phone',
            ],
            'seo' => [
                'title' => $siteName . " \u{2014} websites and web applications",
                'description' => 'We build websites, online stores and custom web applications ' . "\u{2014}" . ' from the first sketch to launch and beyond.',
            ],
        ];
    }
}
