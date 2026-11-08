# AGENTS.md

This tree is **generated** by `bin/build/export-core.sh` in the private source
repository. `.nativa-core-export.json` names the commit it came from.

Do not edit a file here. A change belongs in the source repository, where the
gates live; the next publish replaces this tree. An edit made here is lost and,
until it is lost, it is a fork nobody measures.

## Working here

- `make gates` prints every pin, gate and CI job. The registry is
  `toolchain.json`, and the workflow in `.github/workflows/ci.yml` is its CI
  side.
- The kernel runtime has no Composer packages. `composer.json`'s `require`
  holds PHP and its extensions; `composer install` is dev tooling.
- `src/Modules/<Name>` is operator state and is gitignored. `modules/<Name>` is
  the catalogue and is tracked. After editing a catalogue slice, reinstall it:
  `php bin/cms module:remove <Name> --force && php bin/cms module:add <Name>`.
- A kernel file may not import a module. `make mago-guard` enforces it.
- `public/dist/griffe` and `public/dist/mono` are committed frozen bundles.
  Rebuild them with `make assets`; never hand-edit them.

## Commit messages

`type(scope): subject`, with the type from `feat`, `fix`, `docs`, `refactor`,
`test`, `chore`, `ci`, `build`, `style`, `perf`, and no agent footer.
