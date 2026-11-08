---
name: Bug report
about: Something behaves differently than the tree says it should
title: ''
labels: ''
assignees: ''
---

<!--
This tree is generated from a private source repository, so a fix lands there
and comes back with the next export. A precise report is what makes that
possible — see CONTRIBUTING.md.
-->

## What happened

<!-- One or two sentences. What did you run, and what came back? -->

## What you expected

<!-- And why the tree should behave that way: a test, a docblock, README, or a
     sibling surface that already does it. -->

## How to reproduce

```sh
# the smallest sequence that shows it
```

## The surface

<!-- Exactly one of these, as specifically as you can: -->

- file: <!-- src/Modules/... or modules/... -->
- route: <!-- /login, /blog/<slug>, /robots.txt ... -->
- CLI: <!-- bin/cms ...  /  make ... -->

## Versions

- Commit: <!-- from .nativa-core-export.json -->
- PHP, measured on the process that serves the request, not a shell:
  `ps -eo pid,args | grep -E 'php-fpm|httpd|php-cgi'` then
  `readlink -f /proc/<pid>/exe` → `<!-- x.y.z -->`
- Did `make gates` pass before your change? <!-- yes / no / not run -->

## Anything else

<!-- Logs, the failing test name, a stack trace. Do not paste secrets or a
     tracked .env. -->
