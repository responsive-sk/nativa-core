<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\HTTP\Actions;

use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\Attributes\Route;

/**
 * LlmsTxtAction — serves /llms.txt for LLM-friendly site overview.
 *
 * Follows the llms.txt v2 spec (https://github.com/answerdotai/llms-txt):
 * a Markdown file with an H1 header, brief description, and links to
 * key content sections in both locales.
 */
#[Route(path: '/llms.txt', method: 'GET', name: 'llms-txt')]
final class LlmsTxtAction
{
    /**
     * llms.txt body — Markdown link placeholders like (/sk/blog), made
     * absolute against the request host before serving.
     */
    private const string TEMPLATE = <<<'TXT'
        # Nativa PHP Core

        A kernel-only PHP tree: zero production dependencies, attribute routing, a middleware pipeline, per-module SQLite databases, and a theme with no build step. A module that is installed extends what is listed here through its own routes.

        ## Sections

        - [Homepage](/): the kernel home surface
        - [Documentation](/docs): guides published in this deployment
        - [Sitemap](/sitemap.xml): XML sitemap for search engines
        - [Robots](/robots.txt): crawler permissions

        ## Details

        - Zero Composer runtime dependencies — self-hosted PSR-4 autoloader
        - SQLite per-module databases, migrated idempotently on boot
        - One theme that needs no build (plain CSS, inlined)
        - Strict CSP with per-request nonces
        TXT;

    /**
     * @var list<string> paths linked from TEMPLATE (Markdown placeholder form).
     *                   Only routes the kernel serves: advertising a module's route here
     *                   would teach a crawler to find a 404 (same rule as the sitemap).
     */
    private const array LINKED_PATHS = [
        '/',
        '/docs',
        '/sitemap.xml',
        '/robots.txt',
    ];

    public function handle(Request $request): Response
    {
        $content = $this->absolutizeLinks($request->absoluteUrl(''), self::TEMPLATE);

        $response = new Response($content);
        $response->setHeader('Content-Type', 'text/plain; charset=utf-8');

        return $response;
    }

    /**
     * Replace the Markdown link placeholders in TEMPLATE with absolute URLs.
     */
    private function absolutizeLinks(string $host, string $template): string
    {
        $search = array_map(
            static fn (string $path): string => '(' . $path . ')',
            self::LINKED_PATHS,
        );
        $replace = array_map(
            static fn (string $path): string => '(' . $host . $path . ')',
            self::LINKED_PATHS,
        );

        return str_replace($search, $replace, $template);
    }
}
