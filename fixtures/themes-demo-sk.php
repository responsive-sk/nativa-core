<?php

declare(strict_types = 1);

/*
 * themes-demo-sk.php — the Slovak fixture, the twin of themes-demo.php.
 *
 * Same shape, same surfaces, same six articles — only the language differs.
 * That is deliberate: the demo's job in Slovak is to put the project's DEFAULT
 * locale through every theme, so a face that ships no `č`, `ľ` or `ô` shows up
 * as a fallback in the middle of a paragraph instead of hiding behind an
 * English article that never needed the glyph.
 *
 * Everything here is static for the same reason as its English twin: the
 * output is committed, and a generator that reads the clock cannot be checked
 * by a diff. Diacritics are not decoration in this file — they are the test
 * data. The article body carries every letter of the Slovak alphabet in both
 * cases, plus the Czech `ě`, `ř` and `ů`, so one rendered page exercises the
 * whole set.
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
        'temy-ako-moduly',
        'Témy ako moduly, nie ako priečinok',
        'Téma je rez s triedou, assetmi a testami — nainštaluje a odstráni ju ten istý nástroj ako čokoľvek iné.',
        'temy-ako-moduly-pre-deti.svg',
        '2026-10-18',
        'inžinierstvo',
        'Ivan Paldan',
    ),
    $article(
        'zmluva-bez-zakaznikov',
        'Zmluva bez zákazníkov',
        'Čo sľubuje port jadra, keď sa nikto nemá na čo opýtať: tvar odpovede, nie odpoveď.',
        'kontrakt-bez-zakaznikov.svg',
        '2026-10-15',
        'architektúra',
        'Ivan Paldan',
    ),
    $article(
        'posledne-sudo',
        'Posledné sudo, ktoré som potreboval',
        'Jeden príkaz, jeden dôvod a nasadenie, ktoré už nepýta ani jedno z toho.',
        'sudo-posledne-pre-deti.svg',
        '2026-10-12',
        'poznámky',
        'Ivan Paldan',
    ),
    $article(
        'filozofia-menej-vrstiev',
        'Filozofia menšieho počtu vrstiev',
        'Každá vrstva, ktorú pridáš, je miesto, kde požiadavka prestane byť tvoja.',
        'filozofia-menej-vrstiev.svg',
        '2026-10-09',
        'dizajn',
        'Ivan Paldan',
    ),
    $article(
        'opcache-a-jit-merane',
        'OPcache a JIT, merané',
        'Čo teplá pamäť opcodu naozaj prinesie na jednom module a kde JIT nepomôže vôbec.',
        'opcache-a-jit-prekompilacia.svg',
        '2026-10-05',
        'výkon',
        'Ivan Paldan',
    ),
    $article(
        'c-api-nie-je-jediny-bod',
        'C API nie je jediný bod rozšírenia',
        'Modul môže byť priečinok. To je celý model rozšírenia a stačí to.',
        'c-rozsirenia-php-api.svg',
        '2026-10-01',
        'moduly',
        'Ivan Paldan',
    ),
];

$tags = ['php', 'architektúra', 'jadro', 'sqlite', 'témy', 'testovanie', 'výkon', 'moduly'];

$categories = ['inžinierstvo', 'dizajn', 'produkt', 'poznámky'];

$counts = [];
foreach ($tags as $index => $tag) {
    $counts[$tag] = max(1, 6 - $index);
}

foreach ($categories as $index => $category) {
    $counts[$category] = max(1, 3 - $index);
}

$body = <<<'HTML'
    <p class="lede">Téma je jediná časť stránky, ktorú čitateľ vidí, a zároveň jediná časť,
    s ktorou vývojár zaobchádza ako s dekoráciou. Táto ukážková stránka robí ten rozdiel
    viditeľným: všetko nižšie je vlastný markup témy vykreslený nad jedným fixture.</p>

    <p>Jadro odovzdá téme dátový balík a téma odpovie HTML. Žiadny šablónovací engine,
    žiadna rodičovská téma, žiadna kaskáda prepisov — jedna trieda, jeden štýl a hŕstka
    skriptov, ktoré sa samy zapínajú podľa markupu, ktorý nájdu.</p>

    <p>Slovenská abeceda, malé písmená: a á ä b c č d ď e é f g h i í j k l ĺ ľ m n ň
    o ó ô p q r ŕ s š t ť u ú v w x y ý z ž. Veľké písmená: A Á Ä B C Č D Ď E É F G H
    I Í J K L Ĺ Ľ M N Ň O Ó Ô P Q R Ŕ S Š T Ť U Ú V W X Y Ý Z Ž. České znaky navyše:
    ě ř ů, veľké: Ě Ř Ů. Čísla a interpunkcia: 0123456789 „úvodzovky“ – pomlčka — a bodka.</p>

    <h2 id="co-nesie-datovy-balik">Čo nesie dátový balík</h2>

    <p>Články cestujú ako obyčajné objekty. Téma z nich číta slug, titulok, perex, obal,
    dátum, kategóriu a autora cez <code>get_object_vars()</code>; nikdy neimportuje entitu
    blogu, takže odstránenie modulu blogu tému nerozbije.</p>

    <h3 id="preco-objekty-a-nie-polia">Prečo objekty a nie polia</h3>

    <p>Objekt s chýbajúcim poľom je viditeľná neprítomnosť; pole s chýbajúcim kľúčom je
    oznámenie v logu, ktorý nikto nečíta. Konverzia sa deje raz, na hranici.</p>

    <h2 id="co-vlastni-tema">Čo vlastní téma</h2>

    <ul>
      <li>markup každého povrchu, ktorý implementuje;</li>
      <li>štýl, ktorý ho formuje;</li>
      <li>skripty správania, naviazané na vlastný markup;</li>
      <li>testy, ktoré všetky tri držia.</li>
    </ul>

    <blockquote>Ak stránka potrebuje triedu, ktorú téma nemá, zlá je stránka —
    nie téma.</blockquote>
HTML;

$headings = [
    ['level' => 2, 'text' => 'Čo nesie dátový balík', 'anchor' => 'co-nesie-datovy-balik'],
    ['level' => 3, 'text' => 'Prečo objekty a nie polia', 'anchor' => 'preco-objekty-a-nie-polia'],
    ['level' => 2, 'text' => 'Čo vlastní téma', 'anchor' => 'co-vlastni-tema'],
];

$tagChips = '<a href="../tags/">#php</a> <a href="../tags/">#témy</a> <a href="../tags/">#jadro</a>';

return [
    'locale' => 'sk',
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
        'slug' => 'temy-ako-moduly',
        'title' => 'Témy ako moduly, nie ako priečinok',
        'meta_line' => '18. októbra 2026 · inžinierstvo · 4 min čítania',
        'cover' => '/covers/temy-ako-moduly-pre-deti.svg',
        'views' => 42,
    ],
];
