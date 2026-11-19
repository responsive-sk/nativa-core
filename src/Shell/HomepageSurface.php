<?php

declare(strict_types = 1);

namespace Shell;

/**
 * HomepageSurface — the landing page a module owns, if one does.
 *
 * The kernel answers `/` on its own (`HomepageAction` renders whatever the
 * active theme composes from the shell port). That is the smallest honest
 * answer for a tree with nothing installed, and it is the WRONG answer for a
 * site with an identity: the Boson landing is the framework's own pitch, with
 * most of its copy spelled out in the theme's code, so a company site rendered
 * "Keep PHP. Drop the framework." at its front door and no translation row
 * could change it (measured 2026-11-19, docs/kernel-status/01).
 *
 * A module that ships this interface — marked `#[AsHomepageSurface]` — replaces
 * the landing CONTENT, and can name its own title, description and share image.
 * The route, the shell, the canonical URL and the structured data stay the
 * kernel's, so nothing about the page's SEO plumbing is re-implemented per site.
 *
 * Not a route: two `#[Route(path: '/')]` classes match in filesystem order, and
 * "which home page answers" would be a coin flip nobody measured. One port, one
 * answer, and a module that is removed leaves the kernel's landing behind.
 */
interface HomepageSurface
{
    public function homepage(PageContext $context): Homepage;
}
