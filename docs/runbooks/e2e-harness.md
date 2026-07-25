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
| `npm run test:e2e:smoke` | Admin auto-login + Portals list |
| `npm run ci` | env + mocks + unit + smoke |

Artifacts land in `tests/.artifacts/` (gitignored).

## Workflow

Phase runner: `.grok/workflows/dragongate-rewrite.rhai`  
Invoke with `args.phase` 0–9.
