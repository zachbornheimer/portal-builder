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
| `npm run test:e2e` | Full Playwright suite |
| `npm run test:e2e:smoke` | Auto-login → Portals list → seed → cleanup |
| `npm run test:e2e:admin` | Admin CRUD + definition specs |
| `npm run test:e2e:public` | Public form + closed/preview |
| `npm run test:e2e:pipeline` | Submit → mock sheet/drive/mail |
| `npm run test:seed` | Create one `dg-e2e-*` portal (CLI) |
| `npm run test:cleanup` | Remove all `dg-e2e-*` portals (+ registry) |
| `npm run test:import` | Importer fixture match (Phase 6) |
| `npm run ci:offline` | mocks + unit (no LocalWP; GH-safe) |
| `npm run ci` | env + mocks + unit + smoke (LocalWP required) |

Artifacts land in `tests/.artifacts/` (gitignored). Seed registry: `tests/.artifacts/seeded-portals.json`.

## CI gates

| Workflow | Trigger | Runs |
|----------|---------|------|
| `.github/workflows/ci.yml` | push/PR to `main` | `npm run ci:offline` |
| `.github/workflows/e2e-local.yml` | `workflow_dispatch` | `test:env` + chosen e2e suite on self-hosted LocalWP |

**Local-only gate:** e2e needs `isjac-overhaul` (or equivalent) at `baseUrl` with the plugin symlink. GitHub-hosted runners cannot reach LocalWP; use a self-hosted runner labeled `localwp` or run `npm run ci` on the Mac.

## Workflow

Phase runner: `workflows/dragongate-rewrite.rhai`  
Invoke with `args.phase` 0–9.
