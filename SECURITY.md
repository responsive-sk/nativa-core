# Security policy

Nativa PHP Core is the kernel-only tree: `Modules\Core` + `Modules\Database`, one
theme, and a module catalog you add to and remove from. This file says which
versions are supported, how to report a problem, and what happens when a CVE
lands in something the tree runs.

## Supported runtime

The tree's floor is `composer.json` → `require.php`:

```json
"php": "^8.5.12"
```

That is `>=8.5.12 <9.0.0`. It is a **patch-level** floor on purpose: `^8.5` was
satisfied by `8.5.11`, which is vulnerable to `CVE-2026-109618` and
`CVE-2026-109619` (PHP2Shell, CVSS 9.2, exploited in the wild since
2026-10-21). A runtime that does not satisfy the constraint is not supported,
even when the tree boots on it.

| Component | Supported |
|---|---|
| PHP | `>=8.5.12 <9.0`; the newest 8.5 patch is the intent, the floor is the minimum |
| Branch | `master` only; releases are commit hashes, there are no tagged versions |
| Modules | the catalog in `modules/` — each module is supported with the kernel revision it ships beside |
| Installed module slices (`src/Modules/<Name>/`) | **not** supported surface: operator state, gitignored, produced by `bin/cms module:add` |
| Themes and page shell | `modules/Layout` and `modules/Themes/*` as committed |

Older PHP branches (8.2–8.4) receive upstream fixes, but this tree does not
claim them: `mago.toml`, CI and the two tool configs are set to 8.5.

## How to report a vulnerability

**Use GitHub's private vulnerability reporting** — the repository's *Security*
tab → *Report a vulnerability*. That opens a private advisory visible only to
the maintainers. Please do not open a public issue, a public PR, or a public
discussion for a suspected vulnerability.

There is no public security mailbox yet. That is a known gap, not a policy
choice: if you cannot use GitHub, contact the maintainer who gave you access to
the repository directly, and the report will be moved into a private advisory.

A useful report contains:

- the affected surface: file, route (`/login`, `/blog/<slug>`, `bin/cms …`) or
  commit hash,
- the version you measured, from the **serving** SAPI (`php-fpm -v`, or
  `/proc/<pid>/exe -v`), not from `php -v` in a shell,
- reproduction steps, and whether the finding needs authentication,
- impact: what an attacker gains, and what they need to have first.

What you can expect:

| Step | Target |
|---|---|
| Acknowledgement | 3 working days |
| Triage — accepted, or why not | 7 working days |
| Fix or documented mitigation | coordinated; disclosure defaults to 90 days from the report, or when a fix ships, whichever comes first |
| Credit | in the advisory and the boot record, unless you ask otherwise |

## In scope

- The kernel: `src/Modules/Core`, `src/Modules/Database` — routing, the
  middleware pipeline, session handling, CSRF, security headers, the rate
  limiter, markdown rendering, the error pages, `/robots.txt` and
  `/sitemap.xml`.
- `bin/cms` and the CLI commands it dispatches.
- Authentication and account surfaces: `/login`, `/register`,
  `/forgot-password`, `/reset-password`, admin access control, password policy.
- Uploads and stored media, comment and question submission, inline editing.
- The gates themselves: a way to make `make security-audit`,
  `make dependency-audit` or the source repository's `bin/build/admin-smoke.sh`
  report success without checking anything is a valid finding.

## Out of scope

- **Operator state**: `src/Modules/<Name>/` installed slices, `storage/`,
  `config/`, `.env`. These are runtime data on a deployment, not source.
- **Documented development defaults**: `bin/cms user:admin` creates
  `admin@example.test` / `admin1234` when no password is given. It is in the
  README, it is meant for a laptop, and it is shorter than the password policy
  on purpose — a deployment that leaves it in place is a deployment problem,
  but the default itself is not a vulnerability report.
- **Known non-goals**, decided and recorded in the 2026-10 security round: no
  MFA, no e-mail verification, no password complexity rules, no rate limit on
  `/reset-password` (the token is 256-bit), and the reset link is deliberately
  **not** queued.
- **Third-party dev tooling** (php-cs-fixer, PHPStan, Rector, Mago, PHPUnit):
  report upstream. We do want to hear about it if `make dependency-audit` fails
  to see an advisory that affects us.
- **Denial of service by volume** against `php -S` (the `make dev` server): that
  server is single-process by design and is not a deployment target.

## The CVE rule

When a CVE lands in PHP, in a Composer package, or in the `Frontend/packages`
workspace, the order matters:

1. **Measure the binary that serves requests.** `ps -eo pid,args | grep -E
   'php-fpm|httpd|php-cgi'`, then `readlink -f /proc/<pid>/exe`. `php -v` in a
   shell answers about the CLI, which may be a different build.
2. **Patch to the fixed release of the branch.** If the fix raises the floor,
   `composer.json` → `require.php` changes in the same PR as the code that
   depends on it. A floor that permits a vulnerable patch level is a finding,
   not documentation.
3. **Restart every worker.** `systemctl restart php8.5-fpm`. A reload leaves
   the old process — and, for `CVE-2026-109619`, its stale internal state —
   alive.
4. **Verify on the serving SAPI**, not in a shell:
   `php-fpm -i | grep -E 'session\.(upload_progress|serialize_handler)'`.
5. **Record it**: the floor in `composer.json`, a boot record under
   `docs/kernel-status/`, and a row in the table below when supported versions
   or runtime settings change.
6. **Run the gates**: `make security-audit`, `make dependency-audit`, and
   `make test`. They read tracked files and lockfiles; **they do not see the
   interpreter**, so steps 1–4 stay manual.

Configuration that mitigates a PHP CVE belongs in the pool/`php.ini` layer with
`php_admin_flag`, because `ini_set()` is itself an attack path in
`CVE-2026-115560` — the application must not be able to undo it. The reviewable
form of that setting lives in `deploy/php/`, installed by copying a drop-in;
what the repository cannot own is whether a given server has it loaded. When a
mitigation exists only on one machine, say so in the boot record: an
unreviewable mitigation is not a mitigation the next person can keep.

## Fixed or mitigated so far

| Date | CVE | What changed | Where |
|---|---|---|---|
| 2026-10-24 | `CVE-2026-109618`, `CVE-2026-109619` (PHP2Shell) | runtime floor `^8.5` → `^8.5.12`; `8.5.11` is now rejected by Composer | `composer.json` |
| 2026-10-24 | `CVE-2026-115560` (`session.upload_progress`) | the tree never sets `session.serialize_handler` and has no upload-progress UI, so the narrow preconditions are absent — but the ini default is `On`. `deploy/php/99-nativa-session.ini` now carries the mitigating line, reviewable in a PR; installing it stays an operator step. Upstream fix still pending. | `deploy/php/99-nativa-session.ini` |
| 2026-10-16 | password policy, registration and reset throttles, CSRF cross-check | findings #3, #4, #6, #7 from the 2026-10 audit | `bin/build/`, `PasswordPolicy`, `RateLimiter`, `CsrfMiddleware` |

## The gates this policy relies on

| Gate | Command | What it covers |
|---|---|---|
| secret scan + tracked `.env` | `make security-audit` | tracked files; `audit-ok` marks a deliberate literal |
| dependency advisories | `make dependency-audit` | `composer.lock` and `Frontend/packages/pnpm-lock.yaml`, high and critical |
| live surface | `bin/build/admin-smoke.sh` (source repository; the export ships no admin module) | CSRF refusal, honeypot, moderation, admin access |
| architecture | `make guard` | the kernel may not import a module |

None of these reads the PHP interpreter's patch level. That is why the floor is
in `composer.json` and why step 1 of the rule is a human with `ps`.
