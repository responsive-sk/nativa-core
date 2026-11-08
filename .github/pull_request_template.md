<!--
Read this before you fill the rest in.

This tree is generated from a private source repository at the commit named in
.nativa-core-export.json. Merging your pull request here does NOT land it: the
next export overwrites this tree. A maintainer ports your patch upstream and it
comes back byte for byte, at which point the PR is closed with the export's
commit hash. Opening the PR anyway is welcome — code often says it better than
prose — as long as you know what happens next. See CONTRIBUTING.md.
-->

## What this changes

<!-- One or two sentences. Link the issue it closes: "Closes #123". -->

## Why

<!-- The problem it solves, and what the tree does today instead. -->

## Which gates did you run

<!-- Delete the ones you did not run. CI runs all of them; saying which you
     checked locally is what a reviewer uses to decide where to look. -->

- [ ] `make lint`
- [ ] `composer phpstan`
- [ ] `make test`
- [ ] `make mago-guard` — the kernel still does not import a module
- [ ] `make mago-lint`
- [ ] `make modules-install`
- [ ] `make security-audit`

## Checklist

- [ ] No new entry in `composer.json` → `require`: the runtime has zero
      Composer packages.
- [ ] A behaviour change comes with a test that fails without it.
- [ ] A catalogue slice was edited in `modules/<Name>/` and reinstalled — not in
      `src/Modules/<Name>/`, which is operator state and gitignored.
- [ ] `public/dist/griffe` and `public/dist/mono` are untouched, or were
      rebuilt with `make assets` rather than edited by hand.
- [ ] `composer cs-fix` is clean, and commits read `type(scope): subject`.
- [ ] I have read CODE_OF_CONDUCT.md.
