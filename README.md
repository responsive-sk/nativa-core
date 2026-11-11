# Nativa Core

**This tree is generated.** Every file here is exported from
[`responsive-sk/nativa-php-core`](https://github.com/responsive-sk/nativa-php-core)
at a recorded commit; `.nativa-core-export.json` names it. A hand edit here is
overwritten by the next export. Change the source repository instead.

## What it is

The kernel and the Nativa theme, with no site attached:

- `src/Modules/Core` and `src/Modules/Database`: routing, the request/response
  pair, middleware discovery, the migrator runner, the connection manager, the
  asset resolver, the page-shell port and the error pages.
- `src/Modules/Tooling`: the module catalogue and lifecycle that `bin/cms`
  drives.
- `src/Modules/KernelTheme`: the default presentation that needs no build. It
  renders through `PageShellInterface`, the kernel port whose dependency-free
  implementation (`PlainPageShell`) is what a boot with no modules answers.
- `modules/`: six catalogued modules. `Layout` (the page shell: theme registry,
  resolver, chrome, critical CSS, theme settings), `View` (the renderer and path
  helpers), `Markdown` (the parser every copy surface renders through),
  `Themes/Nativa`, `Settings` (the theme vote) and `ContentLocale` (the locale
  mapping every theme asks for).
  This is a catalogue, not a running set. `src/Modules/<Name>` (the installed
  copy) is gitignored. Docs is a module in the source repository and is pruned
  here: this tree carries no guides, and `/docs` answers 404.
- `Frontend/packages/`: the pnpm workspace that builds the Nativa bundle.
  `public/dist/nativa` is a committed frozen build; `make assets` rebuilds it.

The boundary rule is one sentence: a kernel file may not import a module, and a
module may import the kernel. `make mago-guard` fails on the other direction.

### Why these modules

The catalogue is a measurement, not a starting point. The source repository
built this tree without a catalogue and added back only what a surface proved
it needed. Every surface row is a module that could be added, with its measured
cost:

| Surface | Modules | Cost | Without it |
|---|---|---|---|
| Nativa on `/` | `Settings`, `ContentLocale` | +16 files | `/` renders `KernelTheme` |
| Slovak copy | `Settings`, `Locales` | +143 files | English kernel copy |
| `/blog`, `/cookies` | `Blog`, `Pages` | +373 files | 404 |
| `/mark` | `Users`, `Cardboard` | +209 files | 404 |

Three more rows are kernel slices rather than surfaces: code that used to live
in `src/` and did not have to. Moving each one out is what makes the boundary
measurable — the kernel keeps the port, the module keeps the implementation.

| Kernel slice | Module | Cost | The kernel keeps |
|---|---|---|---|
| Page shell (`src/Themes`) | `Layout` | 25 files, 114 KB | `PageShellInterface`, `PlainPageShell`, `PageShellRegistry` |
| Template rendering (`src/View`) | `View` | 4 files, 295 lines | nothing: the shell asks the renderer |
| Markdown parsing | `Markdown` | 5 files, 858 lines | nothing: every copy surface calls it |

`Settings` is kept because the theme vote is a kernel port with no kernel
implementation. `ContentLocale` is kept because every theme asks it for the
locale-to-URL-prefix rule; it has no routes, tables or admin screen. The rest
are product surfaces, not dependencies of the theme. A module comes back only
with a row here and a measurement behind it.

## Run it

```sh
php -S 127.0.0.1:8000 -t public
```

`vendor/` is not needed. The runtime has zero Composer packages: `src/init.php`
installs a PSR-4 fallback loader, and `composer install` is development tooling
only. The first request, or `php bin/cms migrate`, builds the databases empty.
The panel module is not shipped, so `/mark` answers 404; add `Users` and
`Cardboard` to the catalogue first if you need it (each is a dependency the
source repository measures, not a hidden default).

## Install a module

```sh
php bin/cms module:list                       # what the catalogue carries
php bin/cms module:add Settings               # copies the closure into src/Modules/
php bin/cms module:remove Settings --force    # removes it again
```

The catalogue is six entries: the page shell and its renderer, the markdown
parser, the theme, the module that votes it, and the locale rule the theme
renders through. A module that is not in `modules/` cannot be installed, and
adding one back is a decision with a measured cost — the register in `docs/` of
the source repository explains what each surface would cost.

`modules/Themes/<Name>` holds the theme modules. The installed copy keeps the
same grouped shape, `src/Modules/Themes/<Name>`, and the theme name the settings
store is the lower-case one.

## Build the themes

```sh
make assets     # pnpm workspace → public/dist/nativa
```

`make assets` needs pnpm and network access for the first install. A deployment
does not: the frozen bundle is committed.

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

The suite carries no test whose subject is absent. Tests of the source
repository's approval records, its Lekalo model, its article corpus or a module
outside the catalogue are not copied here at all; the source's
`bin/export/core/phpunit.exclude` names each one with its reason.

## License

MIT. See `LICENSE`.
