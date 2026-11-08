---
name: Feature request
about: Propose a capability the kernel or the catalogue does not have
title: ''
labels: ''
assignees: ''
---

## The problem

<!-- What can you not do today, and what do you do instead? -->

## Where it belongs

<!-- This is the question that decides whether the request is buildable.
     The kernel may not import a module, and a module may import the kernel.
     `make mago-guard` enforces that direction, so a feature that needs the
     kernel to know about a feature module cannot land as written. -->

- [ ] `src/Modules/Core` or `src/Modules/Database` — and it needs nothing from
      a module
- [ ] a catalogue module under `modules/<Name>` — which one, or a new one
- [ ] a theme under `modules/Themes/<Name>`
- [ ] the tooling (`src/Modules/Tooling`, `bin/cms`)

## Proposed shape

<!-- The smallest interface that solves the problem. Routes, CLI verbs,
     settings keys, a sketch of the HTML. Not a full design. -->

## What it costs

<!-- New Composer packages are not available: the runtime has zero of them.
     New frontend dependencies, new gates, a new database file — say so here. -->

## Alternatives you considered

<!-- Including doing nothing, and why that is worse. -->
