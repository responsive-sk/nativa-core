# Nativa Core

**This tree is generated.** Every file here is exported from a private source
repository at a recorded commit; `.nativa-core-export.json` names that commit.
A hand edit here is overwritten by the next export — send changes upstream.

## What it is

The kernel and the catalogue, with no site attached:

- `src/Modules/Core` and `src/Modules/Database`: routing, the request/response
  pair, middleware discovery, the migrator runner, the SQLite connection
  manager, the asset resolver, markdown rendering and the error pages.
- `src/Modules/Tooling`: the module catalogue and lifecycle that `bin/cms`
  drives.
- `src/Modules/KernelTheme` and `src/Themes/kernel`: the default presentation
  that needs no build.
- `modules/`: 27 catalogued modules, six of them themes. This is a catalogue,
  not a running set. `src/Modules/<Name>` (the installed copy) is gitignored.
- `Frontend/packages/`: the pnpm workspace that builds the theme bundles.
  `public/dist/griffe` and `public/dist/mono` are committed frozen builds; the
  rest is built by `make assets`.

The boundary rule is one sentence: a kernel file may not import a module, and a
module may import the kernel. `make mago-guard` fails on the other direction.

## Run it

```sh
php -S 127.0.0.1:8000 -t public
```

`vendor/` is not needed. The runtime has zero Composer packages: `src/init.php`
installs a PSR-4 fallback loader, and `composer install` is development tooling
only. The first request, or `php bin/cms migrate`, builds the databases empty,
so a fresh tree has no account and `/mark` answers 403. Create the first
administrator:

```sh
php bin/cms user:admin                                  # admin@example.test / admin1234
php bin/cms user:admin you@example.com 'your-password'
```

## Install a module

```sh
php bin/cms module:list                       # what the catalogue carries
php bin/cms module:add Blog                   # copies the closure into src/Modules/
php bin/cms module:remove Blog --force        # removes it again
```

`modules/Themes/<Name>` holds the theme modules. The installed copy keeps the
same grouped shape, `src/Modules/Themes/<Name>`, and the theme name the settings
store is the lower-case one.

## Build the themes

```sh
make assets     # pnpm workspace → public/dist/<theme>
```

`make assets` needs pnpm and network access for the first install. A deployment
does not: the two frozen bundles are committed, and the rest can be shipped from
a build.

## Gates

```sh
make gates                # every pin, gate and CI job, from toolchain.json
make lint                 # every PHP file parses
composer phpstan          # types over the kernel scope
make test                 # PHPUnit: tests/ plus modules/*/tests/
make mago-guard           # the kernel-to-module dependency rule
make mago-lint            # curated lint rules, ceiling zero
make modules-install      # every catalogued module installs in closure order
make dist-check           # a clean clone builds a green artifact
```

`make core-export`, `make themes-check` and `make lekalo-check` do not exist
here. They guard the source repository's relationship with a sibling checkout
and its own model; the export carries neither.

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md), including the one rule that changes
how a pull request works here: this tree is generated. Participation is covered
by [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md).

## License

MIT. See `LICENSE`.
