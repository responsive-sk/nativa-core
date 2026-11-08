<?php

declare(strict_types = 1);

namespace Modules\Locales\Infrastructure\Persistence;

/**
 * SeedData — default translation entries for sk_SK and en_US.
 *
 * Single source of truth shared by LocalesDatabaseMigrator and tests.
 * Keys are group-prefixed (e.g. nav.home); the group is stored separately
 * for admin filtering.
 *
 * @internal
 */
final class SeedData
{
    /**
     * @return list<array{key: string, sk_SK: string, en_US: string, group: string}>
     */
    public static function getDefaults(): array
    {
        return array_merge(
            self::coreDefaults(),
            self::sharedNavDefaults(),
            self::moonshineDefaults(),
            self::markDefaults(),
            self::blogDefaults(),
            self::formsDefaults(),
        );
    }

    /**
     * Public blog chrome.
     *
     * The article page's "On this page" TOC label is still hardcoded English in
     * each theme's markup; `blog.edit` is the one public-article string that is
     * translated, because it is the only one the action has to resolve anyway
     * (it decides whether to render the link at all, so it is already in hand).
     *
     * @return list<array{key: string, sk_SK: string, en_US: string, group: string}>
     */
    private static function blogDefaults(): array
    {
        return [
            ['key' => 'blog.edit', 'sk_SK' => 'Upraviť', 'en_US' => 'Edit', 'group' => 'blog'],
            ['key' => 'blog.edit_body', 'sk_SK' => 'Upraviť tento článok', 'en_US' => 'Edit this article', 'group' => 'blog'],
            ['key' => 'blog.saved', 'sk_SK' => 'Uložené.', 'en_US' => 'Saved.', 'group' => 'blog'],
        ];
    }

    /**
     * Core translations: nav/home/footer/lang/admin groups.
     *
     * @return list<array{key: string, sk_SK: string, en_US: string, group: string}>
     */
    private static function coreDefaults(): array
    {
        return [
            // Navigation
            ['key' => 'nav.home', 'sk_SK' => 'Domov', 'en_US' => 'Home', 'group' => 'nav'],
            ['key' => 'nav.blog', 'sk_SK' => 'Blog', 'en_US' => 'Blog', 'group' => 'nav'],
            ['key' => 'nav.admin', 'sk_SK' => 'Admin', 'en_US' => 'Admin', 'group' => 'nav'],

            // Homepage
            ['key' => 'home.eyebrow', 'sk_SK' => 'Nativa Minimal', 'en_US' => 'Nativa Minimal', 'group' => 'home'],
            ['key' => 'home.title', 'sk_SK' => 'Postavte Niečo Skvelé', 'en_US' => 'Build Something Great', 'group' => 'home'],
            ['key' => 'home.message', 'sk_SK' => 'Minimálny PHP 8.5 starter kit s modulárnou architektúrou, routovaním založeným na atribútoch a vanilla TypeScript frontendom.', 'en_US' => 'A minimal PHP 8.5 starter kit with modular architecture, attribute-based routing and a vanilla TypeScript frontend.', 'group' => 'home'],
            ['key' => 'home.cta_start', 'sk_SK' => 'Začať', 'en_US' => 'Get Started', 'group' => 'home'],
            ['key' => 'home.hero_title', 'sk_SK' => 'Minimálny PHP 8.5 starter kit', 'en_US' => 'Minimal PHP 8.5 Starter Kit', 'group' => 'home'],
            ['key' => 'home.hero_subtitle', 'sk_SK' => 'Malý a čitateľný základ: modulárny monolit, per-module SQLite, routovanie na atribútoch a prísna bezpečnosť — postavené od nuly.', 'en_US' => 'A tiny, auditable foundation: modular monolith, per-module SQLite, attribute-based routing and strict security — built from scratch.', 'group' => 'home'],
            ['key' => 'home.cta_blog', 'sk_SK' => 'Prejsť na blog', 'en_US' => 'Read the blog', 'group' => 'home'],
            ['key' => 'home.cta_admin', 'sk_SK' => 'Otvoriť admin', 'en_US' => 'Open admin', 'group' => 'home'],

            // Homepage — feature sections (blog series: fewer layers + super fast PHP)
            ['key' => 'home.layer1_title', 'sk_SK' => 'Menej vrstiev', 'en_US' => 'Fewer Layers', 'group' => 'home'],
            ['key' => 'home.layer1_card1_title', 'sk_SK' => 'Zero dependencies', 'en_US' => 'Zero dependencies', 'group' => 'home'],
            ['key' => 'home.layer1_card1_text', 'sk_SK' => 'Žiadny Composer runtime, žiadny framework. Hand-rolled PSR-4 autoloader nabootuje aplikáciu aj bez vendor/ — celý produkčný runtime tvorí 5 PHP extensions.', 'en_US' => 'No Composer runtime, no framework. A hand-rolled PSR-4 autoloader boots the app even without vendor/ — the whole production runtime is 5 PHP extensions.', 'group' => 'home'],
            ['key' => 'home.layer1_card2_title', 'sk_SK' => 'Čitateľný povrch', 'en_US' => 'Readable surface', 'group' => 'home'],
            ['key' => 'home.layer1_card2_text', 'sk_SK' => 'C-pattern: žiadny magic, žiadne service locatory, žiadne generované mapy. Každý riadok vieš prečítať a overiť — žiadna čierna skrinka.', 'en_US' => 'C-pattern: no magic methods, no service locators, no generated maps. Every line is readable and auditable — no black box.', 'group' => 'home'],
            ['key' => 'home.layer1_card3_title', 'sk_SK' => 'Per-module SQLite', 'en_US' => 'Per-module SQLite', 'group' => 'home'],
            ['key' => 'home.layer1_card3_text', 'sk_SK' => 'Jeden databázový súbor na modul — users.db, config.db, locales.db. Žiadna infraštruktúra, žiadny ORM, žiadna migračná sieť.', 'en_US' => 'One database file per module — users.db, config.db, locales.db. No infrastructure, no ORM, no migration maze.', 'group' => 'home'],
            ['key' => 'home.layer2_title', 'sk_SK' => 'Super rýchle PHP', 'en_US' => 'Super Fast PHP', 'group' => 'home'],
            ['key' => 'home.layer2_card1_title', 'sk_SK' => 'PHP 8.5 + OPcache/JIT', 'en_US' => 'PHP 8.5 + OPcache/JIT', 'group' => 'home'],
            ['key' => 'home.layer2_card1_text', 'sk_SK' => 'Prekompilácia na úrovni jazyka: OPcache drží opcody v pamäti, JIT prekladá horúce cesty do natívneho strojového kódu.', 'en_US' => 'Language-level precompilation: OPcache keeps opcodes in memory, JIT compiles hot paths to native machine code.', 'group' => 'home'],
            ['key' => 'home.layer2_card2_title', 'sk_SK' => 'Dáta cez C', 'en_US' => 'Data through C', 'group' => 'home'],
            ['key' => 'home.layer2_card2_text', 'sk_SK' => 'SQLite je celé v C — najrýchlejšia cesta k dátam bez ORM vrstvy. Built-in funkcie PHP sú prekompilované C knižnice.', 'en_US' => 'SQLite is pure C — the fastest path to data with no ORM layer. PHP built-ins are precompiled C libraries.', 'group' => 'home'],
            ['key' => 'home.layer2_card3_title', 'sk_SK' => 'Bez cache vrstvy', 'en_US' => 'No cache layer', 'group' => 'home'],
            ['key' => 'home.layer2_card3_text', 'sk_SK' => 'Vyhodili sme cache a dostali 100/100 PageSpeed. Rýchlosť nepochádza z cache, ale z odstránenia vrstiev.', 'en_US' => 'We dropped the cache layer and hit 100/100 PageSpeed. Speed comes from removing layers, not from caching.', 'group' => 'home'],
            ['key' => 'home.ratio_value', 'sk_SK' => '215 MB dev → 1,7 MB prod', 'en_US' => '215 MB dev → 1.7 MB prod', 'group' => 'home'],
            ['key' => 'home.ratio_label', 'sk_SK' => 'Pomer ~126:1 — celý produkčný runtime je menší ako jedna fotka z mobilu.', 'en_US' => '~126:1 — the entire production runtime is smaller than one phone photo.', 'group' => 'home'],
            ['key' => 'home.read_article', 'sk_SK' => 'Čítať článok', 'en_US' => 'Read article', 'group' => 'home'],

            // Footer
            ['key' => 'footer.copyright', 'sk_SK' => '© {year} Nativa. Všetky práva vyhradené.', 'en_US' => '© {year} Nativa. All rights reserved.', 'group' => 'footer'],

            // Language switcher
            ['key' => 'lang.switch', 'sk_SK' => 'Prepnúť jazyk', 'en_US' => 'Switch language', 'group' => 'lang'],
            ['key' => 'lang.sk_SK', 'sk_SK' => 'Slovenčina', 'en_US' => 'Slovak', 'group' => 'lang'],
            ['key' => 'lang.en_US', 'sk_SK' => 'Angličtina', 'en_US' => 'English', 'group' => 'lang'],

            // Admin UI
            ['key' => 'admin.dashboard', 'sk_SK' => 'Dashboard', 'en_US' => 'Dashboard', 'group' => 'admin'],
            ['key' => 'admin.locales', 'sk_SK' => 'Preklady', 'en_US' => 'Translations', 'group' => 'admin'],
            ['key' => 'admin.users', 'sk_SK' => 'Používatelia', 'en_US' => 'Users', 'group' => 'admin'],
            ['key' => 'admin.settings', 'sk_SK' => 'Nastavenia', 'en_US' => 'Settings', 'group' => 'admin'],
            ['key' => 'admin.logout', 'sk_SK' => 'Odhlásiť', 'en_US' => 'Logout', 'group' => 'admin'],
            ['key' => 'admin.save', 'sk_SK' => 'Uložiť', 'en_US' => 'Save', 'group' => 'admin'],
            ['key' => 'admin.cancel', 'sk_SK' => 'Zrušiť', 'en_US' => 'Cancel', 'group' => 'admin'],
            ['key' => 'admin.delete', 'sk_SK' => 'Zmazať', 'en_US' => 'Delete', 'group' => 'admin'],
            ['key' => 'admin.create', 'sk_SK' => 'Vytvoriť', 'en_US' => 'Create', 'group' => 'admin'],
        ];
    }

    /**
     * Sign-in / admin labels shared by the wired public themes.
     *
     * The keys keep their `mag.` prefix because the magazine theme no
     * longer ships markup but every deployed locales.db already stores
     * them under that name — renaming two keys would need a data
     * migration for no reader-visible gain. starter and erik both read
     * them in their Chrome\UserLink. The rest of the old magazine/minimal
     * seeds were deleted with their themes (nothing read them).
     *
     * @return list<array{key: string, sk_SK: string, en_US: string, group: string}>
     */
    private static function sharedNavDefaults(): array
    {
        return [
            ['key' => 'mag.nav_login', 'sk_SK' => 'Prihlásiť sa', 'en_US' => 'Sign in', 'group' => 'mag'],
            ['key' => 'mag.nav_admin', 'sk_SK' => 'Admin', 'en_US' => 'Admin', 'group' => 'mag'],
        ];
    }

    /**
     * Moonshine theme UI translations (ms.* keys).
     *
     * @return list<array{key: string, sk_SK: string, en_US: string, group: string}>
     */
    private static function moonshineDefaults(): array
    {
        return [
            ['key' => 'ms.nav_login', 'sk_SK' => 'Prihlásiť sa', 'en_US' => 'Sign in', 'group' => 'ms'],
            ['key' => 'ms.nav_admin', 'sk_SK' => 'Admin', 'en_US' => 'Admin', 'group' => 'ms'],
            ['key' => 'ms.tags_title', 'sk_SK' => 'Značky', 'en_US' => 'Tags', 'group' => 'ms'],
            ['key' => 'ms.popular_title', 'sk_SK' => 'Populárne príspevky', 'en_US' => 'Popular Posts', 'group' => 'ms'],
            ['key' => 'ms.categories_title', 'sk_SK' => 'Kategórie', 'en_US' => 'Categories', 'group' => 'ms'],
            ['key' => 'ms.latest_title', 'sk_SK' => 'Najnovšie články', 'en_US' => 'Latest Articles', 'group' => 'ms'],
            ['key' => 'ms.blog_desc', 'sk_SK' => 'Najnovšie články a postrehy.', 'en_US' => 'Latest articles and insights.', 'group' => 'ms'],
            ['key' => 'ms.view_all', 'sk_SK' => 'Zobraziť všetko', 'en_US' => 'View all', 'group' => 'ms'],
            ['key' => 'ms.related_title', 'sk_SK' => 'Súvisiace články', 'en_US' => 'Related Articles', 'group' => 'ms'],
            ['key' => 'ms.cta_blog', 'sk_SK' => 'Čítať blog', 'en_US' => 'Read the Blog', 'group' => 'ms'],
            ['key' => 'ms.cta_admin', 'sk_SK' => 'Admin panel', 'en_US' => 'Admin Panel', 'group' => 'ms'],
            ['key' => 'ms.cta_title', 'sk_SK' => 'Pripravení preskúmať?', 'en_US' => 'Ready to explore?', 'group' => 'ms'],
            ['key' => 'ms.cta_text', 'sk_SK' => 'Ponorte sa do blogu pre technické hĺbkové analýzy.', 'en_US' => 'Dive into the blog for technical deep-dives.', 'group' => 'ms'],
            ['key' => 'ms.no_articles', 'sk_SK' => 'Zatiaľ žiadne články.', 'en_US' => 'No articles published yet.', 'group' => 'ms'],
            ['key' => 'ms.back_blog', 'sk_SK' => 'Späť na blog', 'en_US' => 'Back to Blog', 'group' => 'ms'],
            ['key' => 'ms.tags_label', 'sk_SK' => 'Značky', 'en_US' => 'Tags', 'group' => 'ms'],
            ['key' => 'ms.on_this_page', 'sk_SK' => 'Na tejto stránke', 'en_US' => 'On this page', 'group' => 'ms'],
            ['key' => 'ms.skip_to_content', 'sk_SK' => 'Preskočiť na obsah', 'en_US' => 'Skip to content', 'group' => 'ms'],
            ['key' => 'ms.nav_sidebar', 'sk_SK' => 'Hlavný panel', 'en_US' => 'Main sidebar', 'group' => 'ms'],
            ['key' => 'ms.nav_main', 'sk_SK' => 'Hlavná navigácia', 'en_US' => 'Main navigation', 'group' => 'ms'],
            ['key' => 'ms.nav_burger', 'sk_SK' => 'Prepnúť navigačné menu', 'en_US' => 'Toggle navigation menu', 'group' => 'ms'],
            ['key' => 'ms.nav_collapse', 'sk_SK' => 'Zbaliť bočný panel', 'en_US' => 'Collapse sidebar', 'group' => 'ms'],
            ['key' => 'ms.toggle_theme', 'sk_SK' => 'Prepnúť motív', 'en_US' => 'Toggle theme', 'group' => 'ms'],
            ['key' => 'ms.backtotop', 'sk_SK' => 'Späť nahor', 'en_US' => 'Back to top', 'group' => 'ms'],
            ['key' => 'ms.widgets_title', 'sk_SK' => 'Bočný panel', 'en_US' => 'Sidebar', 'group' => 'ms'],
            ['key' => 'ms.pager_title', 'sk_SK' => 'Stránkovanie článku', 'en_US' => 'Article pagination', 'group' => 'ms'],
        ];
    }

    /**
     * Admin Publish/Draft switch (articles + pages tables) translations
     * (mark.* keys).
     *
     * @return list<array{key: string, sk_SK: string, en_US: string, group: string}>
     */
    private static function markDefaults(): array
    {
        return [
            ['key' => 'mark.switch_draft', 'sk_SK' => 'Návrh', 'en_US' => 'Draft', 'group' => 'mark'],
            ['key' => 'mark.switch_published', 'sk_SK' => 'Publikované', 'en_US' => 'Published', 'group' => 'mark'],
            ['key' => 'mark.switch_publish_tooltip', 'sk_SK' => 'Klikni pre publikovanie', 'en_US' => 'Click to publish', 'group' => 'mark'],
            ['key' => 'mark.switch_unpublish_tooltip', 'sk_SK' => 'Klikni pre stiahnutie z publikovania', 'en_US' => 'Click to unpublish', 'group' => 'mark'],
        ];
    }

    /**
     * Embedded-form chrome (forms.* keys).
     *
     * `{label}` and `{max}` are placeholders the Forms block substitutes with
     * the admin-authored field label and the field's length bound — the
     * label has to stay movable inside the sentence, because English does not
     * put it where Slovak does. These are the only strings the block owns; the
     * form title, field labels and questions are per-locale content stored in
     * the form definition, not here.
     *
     * @return list<array{key: string, sk_SK: string, en_US: string, group: string}>
     */
    private static function formsDefaults(): array
    {
        return [
            ['key' => 'forms.thanks', 'sk_SK' => 'Ďakujeme — vaša odpoveď bola uložená.', 'en_US' => 'Thank you — your response has been saved.', 'group' => 'forms'],
            ['key' => 'forms.submit', 'sk_SK' => 'Odoslať', 'en_US' => 'Send', 'group' => 'forms'],
            ['key' => 'forms.error.required', 'sk_SK' => '{label} je povinné.', 'en_US' => '{label} is required.', 'group' => 'forms'],
            ['key' => 'forms.error.email', 'sk_SK' => '{label} musí byť platná e-mailová adresa.', 'en_US' => '{label} must be a valid e-mail address.', 'group' => 'forms'],
            ['key' => 'forms.error.too_long', 'sk_SK' => '{label} je príliš dlhé (maximálne {max} znakov).', 'en_US' => '{label} is too long (max {max} characters).', 'group' => 'forms'],
            ['key' => 'forms.error.choice', 'sk_SK' => '{label} musí byť jedna z ponúkaných možností.', 'en_US' => '{label} must be one of the offered choices.', 'group' => 'forms'],
            ['key' => 'forms.error.answer', 'sk_SK' => 'Vyberte odpoveď.', 'en_US' => 'Choose an answer.', 'group' => 'forms'],
            ['key' => 'forms.error.rate_limit', 'sk_SK' => 'Príliš veľa odoslaní. Skúste to prosím o chvíľu.', 'en_US' => 'Too many submissions. Please try again in a minute.', 'group' => 'forms'],
        ];
    }
}
