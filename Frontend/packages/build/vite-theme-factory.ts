/**
 * vite-theme-factory.ts — one Vite config shape for every theme package.
 *
 * Each theme in `Frontend/packages/<theme>/` builds itself through this
 * factory so the old-browser baseline and the manifest contract cannot drift
 * per package:
 *
 *   - `target: 'es2015'` — Chrome 70 / Android 9+ baseline (no `?.` / `??`).
 *   - `manifest: 'manifest.json'` — read by PHP `AssetManager`.
 *   - `outDir: public/dist/<theme>/` — one bundle directory per theme, so
 *     `AssetManager` maps bundle → `dist/<bundle>/` uniformly.
 *   - `codeSplitting: false` for single-entry packages — NOT optional there. A
 *     shared Rollup chunk would emit `import` statements, and the deferred
 *     `<script defer>` (no `type="module"`) cannot execute them on the Chrome 70
 *     baseline. Rolldown rejects the flag outright for multi-input builds, so
 *     it is only set when there is exactly one entry; multi-entry packages
 *     (moonshine) must keep their entries free of shared JavaScript, which
 *     `bin/build/verify-build.php` asserts for every emitted bundle.
 *   - `stripAtLayerPlugin` — Chrome 70 (< 99) drops `@layer` blocks entirely.
 *
 * The `inputs` record carries the logical entry key on the left and the source
 * path on the right. Those keys are load-bearing: `AssetManager::manifestEntry()`
 * probes `$key`, `src/$key.ts` and `src/$key.css`, and Vite keys the manifest
 * by the resolved source path — so an entry file keeps its exact path.
 */

import { defineConfig } from 'vite';
import { resolve } from 'path';
import { fileURLToPath } from 'url';
import { stripAtLayerPlugin } from './strip-at-layer.ts';

/** Repo root: this file lives at <root>/Frontend/packages/build/. */
const packageDir = fileURLToPath(new URL('..', import.meta.url));
const repoRoot = resolve(packageDir, '../..');

/** Port pair per theme, so `pnpm dev` in two packages never collides. */
const THEME_PORTS: Record<string, { dev: number; preview: number }> = {
  starter: { dev: 5177, preview: 4177 },
  erik: { dev: 5178, preview: 4178 },
  moonshine: { dev: 5179, preview: 4179 },
  magazine: { dev: 5180, preview: 4180 },
  minimal: { dev: 5181, preview: 4181 },
  basic: { dev: 5182, preview: 4182 },
  jednotka: { dev: 5183, preview: 4183 },
  griffe: { dev: 5184, preview: 4184 },
  mono: { dev: 5185, preview: 4185 },
  boson: { dev: 5186, preview: 4186 },
};

export interface ThemeConfigOptions {
  /** Theme name — also the `public/dist/<theme>/` directory and the base path. */
  theme: string;
  /** Logical entry key → source path, relative to the package `src/`. */
  inputs: Record<string, string>;
}

/**
 * Build the Vite config for one theme package.
 *
 * @param options theme name and its entry inputs
 */
export function createThemeConfig(options: ThemeConfigOptions) {
  const { theme, inputs } = options;
  const ports = THEME_PORTS[theme] ?? THEME_PORTS.starter;

  if (!(theme in THEME_PORTS)) {
    throw new Error(
      `vite-theme-factory: unknown theme "${theme}" — add it to THEME_PORTS first.`,
    );
  }

  const singleEntry = Object.keys(inputs).length === 1;

  const input = Object.fromEntries(
    Object.entries(inputs).map(([key, source]) => [
      key,
      resolve(packageDir, theme, source),
    ]),
  );

  return defineConfig({
    base: `/dist/${theme}/`,
    publicDir: false,
    appType: 'custom',
    server: {
      allowedHosts: true,
      port: ports.dev,
    },
    preview: {
      allowedHosts: true,
      port: ports.preview,
    },
    plugins: [stripAtLayerPlugin(resolve(repoRoot, 'public', 'dist', theme))],
    build: {
      target: 'es2015',
      outDir: resolve(repoRoot, 'public', 'dist', theme),
      emptyOutDir: true,
      manifest: 'manifest.json',
      rollupOptions: {
        input,
        output: {
          // One self-contained file per entry, no shared chunks — a classic
          // `<script defer>` cannot execute ES module imports (Chrome 70).
          // Rolldown only accepts this for a single input.
          ...(singleEntry ? { codeSplitting: false } : {}),
        },
      },
    },
  });
}
