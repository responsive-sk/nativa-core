# Contributing

Nativa Core is a kernel, a module catalogue and the theme sources, with no site
attached. Contributions that make it smaller, stricter, or more honest about
what it can do are welcome — and so are bug reports with a reproduction.

By participating you agree to the [Code of Conduct](CODE_OF_CONDUCT.md).

## Read this first: this tree is generated

Every file here is exported from a private source repository at the commit named
in `.nativa-core-export.json`. **A pull request merged here would be overwritten
by the next export.** That changes what "contributing" means:

- **Bug report or proposal** — open an issue. Name the surface (a file, a route
  such as `/login` or `/blog/<slug>`, or a `bin/cms` command), the commit you
  measured, and what you expected instead.
- **Patch** — open a pull request if code says it better than prose. A
  maintainer ports it to the source repository, and it arrives back here, byte
  for byte, with the next export. When that has happened the PR is closed with
  the export's commit hash. That hash is the record that your change landed:
  the merge into this repository is not.

Please open an issue before a large patch — a change that cannot be ported
upstream is work neither of us can keep.

## Requirements

- **PHP `>=8.5.12 <9.0`.** The floor is a patch level on purpose; `8.5.11` is
  rejected for the reason recorded in [`SECURITY.md`](SECURITY.md).
- Extensions: `json`, `mbstring`, `pdo`, `pdo_sqlite`, `sqlite3`.
- **Composer for development tooling only.** The runtime has zero Composer
  packages — `src/init.php` installs a PSR-4 fallback loader, and `composer
  install` pulls PHPStan, PHPUnit, Rector and php-cs-fixer. A change that adds an
  entry to `require` is refused on that rule alone.
- `pnpm`, only if you touch `Frontend/packages`.

## Set up

```sh
git clone https://github.com/responsive-sk/nativa-core
cd nativa-core
composer install            # dev tooling; not needed to run the tree
make gates                  # every pin, gate and CI job, from toolchain.json
php bin/cms user:admin      # first administrator: admin@example.test / admin1234
make dev                    # http://127.0.0.1:8000
```

`vendor/` is not needed to boot: the first request, or `php bin/cms migrate`,
builds the databases empty.

## Gates

Run the ones your change touches; CI runs all of them.

| Question | Command |
|---|---|
| Does every PHP file parse? | `make lint` |
| Does it type-check? | `composer phpstan` |
| Do the tests pass? | `make test` |
| Does the kernel still avoid importing a module? | `make mago-guard` |
| Are there zero curated lint findings? | `make mago-lint` |
| Does the whole catalogue install in closure order? | `make modules-install` |
| Does a clean clone build a green artifact? | `make dist-check` |
| No tracked `.env`, no secret-shaped literal? | `make security-audit` |
| No high or critical advisory in either lockfile? | `make dependency-audit` |
| Style, Rector and php-cs-fixer? | `make analyze` |

## Rules a contribution has to keep

- **The boundary.** A file in `src/Modules/Core`, `src/Modules/Database` or
  `src/Modules/Tooling` may not import a module; a module may import the kernel.
  `make mago-guard` fails on the other direction, and it is the reason the kernel
  can be served without a single module installed.
- **A catalogue slice is edited in `modules/<Name>/`, then reinstalled.**
  `src/Modules/<Name>/` is operator state and is gitignored, so a change made
  there is invisible to everyone else:

  ```sh
  php bin/cms module:remove <Name> --force && php bin/cms module:add <Name>
  ```

- **Frozen bundles are never hand-edited.** `public/dist/griffe` and
  `public/dist/mono` are committed builds, pinned by tests. Rebuild them with
  `make assets` (needs pnpm and network for the first install).
- **Style.** `declare(strict_types = 1);`, four spaces, and `composer cs-fix`
  before you push. PHP 8.5 syntax is expected, not merely tolerated: the two
  tool configs and `mago.toml` are set to 8.5.
- **Tests.** A behaviour change lands with a test that fails without it. The
  suite runs `tests/` plus `modules/*/tests/`.

## Commit messages

```
type(scope): subject
```

`type` is one of `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `ci`,
`build`, `style`, `perf`. Present tense, no trailing period.

No agent attribution footer. A trailer naming an assistant is not part of this
repository's history.

## Reporting a vulnerability

Do not open a public issue. [`SECURITY.md`](SECURITY.md) has the private
channel, what is in scope, and what to expect.

## Licence

Contributions are accepted under the [MIT licence](LICENSE). By opening a pull
request you confirm you have the right to submit the work under it.
