<?php

declare(strict_types = 1);

namespace Shell;

/**
 * Homepage — what a `HomepageSurface` answers: the body, and the head it needs.
 *
 * Every nullable field is "the kernel decides": no title means the site name
 * (what `/` has always rendered), no description means `site_description`, no
 * image means no `og:image`, no lang means the shell's `<html lang>`, no
 * `hreflang` means the page declares no alternates (the kernel's `/` never did). A surface
 * names only what it actually knows — and a surface that serves different copy
 * per locale knows the language, which is why `lang` is here: measured on `/en`,
 * English copy under `<html lang="sk">` reads to a crawler as a Slovak page.
 */
final readonly class Homepage
{
    public function __construct(
        public string $content,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $ogImage = null,
        public ?string $lang = null,
        /** @var array<string, string> language code => absolute URL */
        public array $hreflang = [],
    ) {
    }
}
