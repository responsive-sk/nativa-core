<?php

declare(strict_types = 1);

/*
 * themes-demo.php — the fixture every generated theme demo page renders from.
 *
 * One source of demo data, so a page cannot drift from its siblings: the same
 * six articles, the same tags and the same author appear on all five themes.
 * Everything here is static on purpose — no `now()`, no database, no module —
 * because the output is committed and a generator that reads the clock cannot
 * be checked by a diff.
 *
 * Article objects are plain stdClass: `PageContext` accepts objects and the
 * themes read them through `get_object_vars()`, so no entity class is imported
 * (docs/modules.md, "the kernel payload never names the module").
 */

$article = static function (
    string $slug,
    string $title,
    string $excerpt,
    string $cover,
    string $date,
    string $category,
    string $author,
): object {
    return (object) [
        'slug' => $slug,
        'title' => $title,
        'excerpt' => $excerpt,
        'cover' => '/covers/' . $cover,
        'date' => $date,
        'category' => $category,
        'author' => $author,
    ];
};

$articles = [
    $article(
        'themes-as-modules',
        'Themes as modules, not as a folder',
        'A theme is a slice with a class, assets and tests — installed and removed by the same tool as anything else.',
        'temy-ako-moduly-pre-deti.svg',
        '2026-10-18',
        'engineering',
        'Ivan Paldan',
    ),
    $article(
        'a-contract-without-customers',
        'A contract without customers',
        'What a kernel port promises when there is nobody to ask: the shape of the answer, not the answer.',
        'kontrakt-bez-zakaznikov.svg',
        '2026-10-15',
        'architecture',
        'Ivan Paldan',
    ),
    $article(
        'the-last-su-d-o',
        'The last sudo I needed',
        'One command, one reason, and the deploy that no longer asks for either.',
        'sudo-posledne-pre-deti.svg',
        '2026-10-12',
        'notes',
        'Ivan Paldan',
    ),
    $article(
        'a-philosophy-of-fewer-layers',
        'A philosophy of fewer layers',
        'Every layer you add is a place a request can stop being yours.',
        'filozofia-menej-vrstiev.svg',
        '2026-10-09',
        'design',
        'Ivan Paldan',
    ),
    $article(
        'opcache-and-jit',
        'OPcache and the JIT, measured',
        'What a warm opcode cache actually buys on one module, and where the JIT does not help at all.',
        'opcache-a-jit-prekompilacia.svg',
        '2026-10-05',
        'performance',
        'Ivan Paldan',
    ),
    $article(
        'php-c-api-in-a-module',
        'The C API is not the only extension point',
        'A module can be a directory. That is the whole extension model, and it is enough.',
        'c-rozsirenia-php-api.svg',
        '2026-10-01',
        'modules',
        'Ivan Paldan',
    ),
];

$tags = ['php', 'architecture', 'kernel', 'sqlite', 'themes', 'testing', 'performance', 'modules'];

$categories = ['engineering', 'design', 'product', 'notes'];

$counts = [];
foreach ($tags as $index => $tag) {
    $counts[$tag] = max(1, 6 - $index);
}

foreach ($categories as $index => $category) {
    $counts[$category] = max(1, 3 - $index);
}

$body = <<<'HTML'
    <p class="lede">A theme is the only part of a page a reader notices, and the only part
    a developer treats as decoration. This demo page exists to make that gap visible:
    everything below is the theme's own markup, rendered against one fixture.</p>

    <p>The kernel hands a theme a payload and the theme answers with HTML. No template
    engine, no parent theme, no cascade of overrides — one class, one stylesheet, and a
    handful of scripts that self-gate on the markup they find.</p>

    <h2 id="what-the-payload-carries">What the payload carries</h2>

    <p>Articles travel as plain objects. The theme reads slug, title, excerpt, cover,
    date, category and author through <code>get_object_vars()</code>; it never imports the
    blog entity, so removing the blog module does not break the theme.</p>

    <h3 id="why-objects-and-not-arrays">Why objects and not arrays</h3>

    <p>An object with a missing field is a visible absence; an array with a missing key is
    a notice in a log nobody reads. The conversion happens once, at the boundary.</p>

    <h2 id="what-the-theme-owns">What the theme owns</h2>

    <ul>
      <li>the markup of every surface it implements;</li>
      <li>the stylesheet that styles it;</li>
      <li>the behaviour scripts, gated on their own markup;</li>
      <li>the tests that pin all three.</li>
    </ul>

    <blockquote>If a page needs a class the theme does not define, the page is wrong —
    not the theme.</blockquote>
HTML;

$headings = [
    ['level' => 2, 'text' => 'What the payload carries', 'anchor' => 'what-the-payload-carries'],
    ['level' => 3, 'text' => 'Why objects and not arrays', 'anchor' => 'why-objects-and-not-arrays'],
    ['level' => 2, 'text' => 'What the theme owns', 'anchor' => 'what-the-theme-owns'],
];

$tagChips = '<a href="../tags/">#php</a> <a href="../tags/">#themes</a> <a href="../tags/">#kernel</a>';

return [
    'locale' => 'en',
    'site_name' => 'Nativa',
    'author' => [
        'id' => 'ivan-paldan',
        'name' => 'Ivan Paldan',
        'avatar' => '/covers/avatar.svg',
    ],
    'member_since' => '2024',
    'articles' => $articles,
    'tags' => $tags,
    'categories' => $categories,
    'counts' => $counts,
    'body' => $body,
    'headings' => $headings,
    'tag_chips' => $tagChips,
    'article' => [
        'slug' => 'themes-as-modules',
        'title' => 'Themes as modules, not as a folder',
        'meta_line' => '18 October 2026 · engineering · 4 min read',
        'cover' => '/covers/temy-ako-moduly-pre-deti.svg',
        'views' => 42,
    ],
];
