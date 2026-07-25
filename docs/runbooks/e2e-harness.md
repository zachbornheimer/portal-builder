# E2E harness (DragonGate rewrite)

## Setup (once per machine)

1. Symlink Local plugin to this repo (already done on isjac-overhaul).
2. Copy `tests/config/env.example.json` → `tests/config/env.local.json` and edit if paths differ.
3. `npm install && npx playwright install chromium`
4. `npm run test:env` must exit 0.

## Commands

| Command | Purpose |
|---------|---------|
| `npm run test:env` | Assert URLs, symlink realpath, fixtures |
| `npm run test:mocks` | Sheet/Drive/mail file-backed selftest |
| `npm run test:unit` | Node unit tests |
| `npm run test:e2e:smoke` | Auto-login → Portals list → seed → cleanup |
| `node tests/support/seed.mjs` | Create one `dg-e2e-*` portal (CLI) |
| `node tests/support/cleanup.mjs` | Remove all `dg-e2e-*` portals (+ registry) |
| `node tests/support/cleanup.mjs --id=N` | Remove one portal by id |
| `npm run ci` | env + mocks + unit + smoke |

Artifacts land in `tests/.artifacts/` (gitignored). Seed registry: `tests/.artifacts/seeded-portals.json`.

## Workflow

Phase runner: `.grok/workflows/dragongate-rewrite.rhai`  
Invoke with `args.phase` 0–9.
