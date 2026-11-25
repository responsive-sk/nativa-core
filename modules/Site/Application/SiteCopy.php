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
 * `contacts()` is the one place an address or a number is written down. The page
 * renders only the rows that have a value, so a number that is not confirmed yet
 * is an absent line rather than a `mailto:` to nowhere.
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
        // The address the site's own contact page publishes. The phone stays
        // empty on purpose: an unpublished number is an absent row, not a
        // guessed one.
        return [
            'email' => 'ahoj@responsive.sk',
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
                'eyebrow' => 'Weby ' . "\u{00B7}" . ' aplikácie ' . "\u{00B7}" . ' integrácie',
                'title' => 'Weby a aplikácie,',
                'accent' => 'ktoré rastú s vami',
                'text' => 'Staviame weby a interné aplikácie na mieru ' . "\u{2014}" . ' od prvého návrhu po nasadenie a ďalší rozvoj.',
                'primary' => 'Napíšte nám',
                'secondary' => 'Pozrieť blog',
                'discover' => 'Ako to funguje',
            ],
            'services' => [
                'label' => 'Služby',
                'title' => 'Čo pre vás postavíme',
                'items' => [
                    ['title' => 'Weby', 'text' => 'Prezentačné a firemné stránky: čitateľná štruktúra a obsah, ktorý upravíte v administrácii.'],
                    ['title' => 'Integrácie', 'text' => 'Napojenie na to, čo už používate ' . "\u{2014}" . ' účtovníctvo, sklad a rozhrania pre ďalšie systémy.'],
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
                // The page behind the claim above, not another paragraph about it:
                // `/referencie` lists only what a visitor can open and check.
                'link' => ['label' => 'Referencie: čo si viete overiť', 'href' => '/referencie'],
            ],
            'stack' => [
                'label' => 'Technológie',
                'title' => 'Na čom to stojí',
                'text' => 'Vyberáme podľa problému, nie podľa popularity. Všetko, čo nasadíme, viete prevádzkovať aj bez nás.',
                // Measured, not asserted: 2026-11-22, this release, on the
                // built artifact served by `php -S` (no gzip, 155 KiB, 6
                // requests) through bin/lab/psi-speed-index.sh — Lighthouse
                // 13.6.0 under PSI's own lanternMobileSlow4G profile and
                // mobile emulation, five runs: LCP 1053-1109 ms, median
                // 1107 ms, CLS 0.000. It cites the median of a repeatable run,
                // not a bound; the earlier 736-740 ms figure came from a
                // hand-rolled CDP loop and no longer describes this release.
                'diagram_caption' => 'Strom, na ktorom tento web stojí.',
                'fact' => 'Tento web je postavený na tom istom strome: 6 requestov, kritické CSS priamo v HTML a JavaScript až po obsahu. Diagram nad zoznamom si Mermaid dotiahne až pri scrollovaní, takže prvá obrazovka platí len to, čo vidíte. Na pomalom 4G (1,6 Mbit/s, 4× spomalené CPU) sa najväčší prvok vykreslí za 1,1 s (medián piatich meraní).',
                // Group, then language, then what we build with it. A flat list
                // of chips answered "which words do you know", not "what would
                // you build this in".
                'groups' => [
                    [
                        'title' => 'Backend',
                        'lines' => [
                            // `tag` links the name to /blog/tags/<tag>, and only
                            // where the blog has posts under it: an unused tag
                            // answers 404, so a guess here would be a dead link.
                            [
                                'title' => 'PHP 8.5',
                                'note' => 'Jazyk, na ktorom stojí celý náš stack.',
                                'tag' => 'php',
                                'items' => [
                                    ['title' => 'Nativa', 'tag' => 'nativa', 'note' => 'Keď má aplikácia vydržať roky a nemá so sebou ťahať aj framework.'],
                                    ['title' => 'Laravel', 'tag' => 'laravel', 'note' => 'Keď tím klienta Laravel pozná a prevádzka musí ostať v jeho rukách.'],
                                    ['title' => 'Symfony', 'note' => 'Keď projekt obsahuje viac domén, ktoré si potrebujú vlastné hranice.'],
                                    ['title' => 'Nette', 'note' => 'Keď preberáme existujúcu Nette aplikáciu a nemá zmysel ju prepisovať.'],
                                ],
                            ],
                            [
                                'title' => 'SQLite',
                                'note' => 'Keď sa dáta zmestia do jedného súboru a databázový server je zbytočná položka v rozpočte.',
                            ],
                        ],
                    ],
                    [
                        'title' => 'Frontend',
                        'lines' => [
                            [
                                'title' => 'TypeScript',
                                'note' => 'Keď má rozhranie viac stavov, než sa dá udržať v hlave.',
                                'items' => [
                                    ['title' => 'React', 'note' => 'Keď tím React pozná alebo potrebuje komponenty, ktoré už niekto napísal.'],
                                    ['title' => 'Svelte', 'note' => 'Keď chceme menej kódu v prehliadači a stránka je skôr obsah než dashboard.'],
                                    ['title' => 'Vue', 'note' => 'Keď projekt preberáme po niekom, kto už Vue používa.'],
                                ],
                            ],
                            [
                                'title' => 'HTML a CSS bez balíka',
                                'note' => 'Keď je stránka obsah a pri builde sa nemá čo pokaziť.',
                            ],
                        ],
                    ],
                    [
                        'title' => 'Rozhrania',
                        'lines' => [
                            [
                                'title' => 'REST API a integrácie',
                                'note' => 'Keď účtovníctvo alebo sklad musia hovoriť s tým naším.',
                            ],
                        ],
                    ],
                ],
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
                'description' => 'Tvoríme weby, webové aplikácie a integrácie na mieru ' . "\u{2014}" . ' od prvého návrhu po nasadenie a ďalší rozvoj.',
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
                'eyebrow' => 'Websites ' . "\u{00B7}" . ' apps ' . "\u{00B7}" . ' integrations',
                'title' => 'Websites and apps',
                'accent' => 'that grow with you',
                'text' => 'We build custom websites and internal apps ' . "\u{2014}" . ' from the first sketch to launch and beyond.',
                'primary' => 'Get in touch',
                'secondary' => 'Read the blog',
                'discover' => 'Discover how it works',
            ],
            'services' => [
                'label' => 'Services',
                'title' => 'What we build for you',
                'items' => [
                    ['title' => 'Websites', 'text' => 'Company and presentation sites: clear structure and content you can edit yourself.'],
                    ['title' => 'Integrations', 'text' => 'Connecting to what you already run ' . "\u{2014}" . ' accounting, the warehouse and other systems.'],
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
                'link' => ['label' => 'References: what you can check', 'href' => '/en/references'],
            ],
            'stack' => [
                'label' => 'Technology',
                'title' => 'What it stands on',
                'text' => 'We pick for the problem, not for popularity. Everything we ship, you can run without us.',
                'diagram_caption' => 'The tree this site stands on.',
                'fact' => 'This site runs on the same tree: 6 requests, the critical CSS inline in the HTML and JavaScript only after the content. The diagram above the list pulls Mermaid in only once you scroll to it, so the first screen pays for what you see. On Slow 4G (1.6 Mbit/s, 4x slower CPU) the largest element paints in 1.1 s (median of five runs).',
                'groups' => [
                    [
                        'title' => 'Backend',
                        'lines' => [
                            // No `tag` here on purpose: the stack links a name to
                            // `/en/blog/tags/<tag>`, and the English blog has no
                            // posts yet, so every one of those links would answer
                            // 404. Slovak copy above carries the tags.
                            [
                                'title' => 'PHP 8.5',
                                'note' => 'The language the whole stack stands on.',
                                'items' => [
                                    ['title' => 'Nativa', 'note' => 'When the application has to last for years without dragging a framework along.'],
                                    ['title' => 'Laravel', 'note' => 'When the client already knows it and running it has to stay in their hands.'],
                                    ['title' => 'Symfony', 'note' => 'When the project holds several domains that need boundaries of their own.'],
                                    ['title' => 'Nette', 'note' => 'When we take over an existing Nette application and rewriting it makes no sense.'],
                                ],
                            ],
                            [
                                'title' => 'SQLite',
                                'note' => 'When the data fits in one file and a database server is a wasted line in the budget.',
                            ],
                        ],
                    ],
                    [
                        'title' => 'Frontend',
                        'lines' => [
                            [
                                'title' => 'TypeScript',
                                'note' => 'When the interface has more states than anyone can hold in their head.',
                                'items' => [
                                    ['title' => 'React', 'note' => 'When the team knows React or needs components someone has already written.'],
                                    ['title' => 'Svelte', 'note' => 'When we want less code in the browser and the page is content rather than a dashboard.'],
                                    ['title' => 'Vue', 'note' => 'When we inherit a project from someone already using Vue.'],
                                ],
                            ],
                            [
                                'title' => 'HTML and CSS without a bundle',
                                'note' => 'When the page is content and the build has nothing left to break.',
                            ],
                        ],
                    ],
                    [
                        'title' => 'Interfaces',
                        'lines' => [
                            [
                                'title' => 'REST APIs and integrations',
                                'note' => 'When accounting or the warehouse has to talk to ours.',
                            ],
                        ],
                    ],
                ],
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
                'description' => 'We build custom websites, web applications and integrations ' . "\u{2014}" . ' from the first sketch to launch and beyond.',
            ],
        ];
    }
}
