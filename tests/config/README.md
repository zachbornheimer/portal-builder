# Harness env config

Machine-specific LocalWP paths live in **`env.local.json`** (gitignored).  
Committed template: **`env.example.json`**.

## Setup

```sh
cp tests/config/env.example.json tests/config/env.local.json
# edit paths/URLs for your Local site
npm run test:env
```

`assert-env.mjs` exits **0** only when:

| Check | Detail |
|-------|--------|
| Required keys | `baseUrl`, `pluginPath`, `pluginMustResolveTo`, `artifactDir`, `useMocks` |
| Plugin symlink | `realpath(pluginPath) ===` git repo root |
| Plugin entry | `portal-builder.php` present |
| Fixtures | portal definitions + sample files under `tests/fixtures/` |
| baseUrl | HTTP 2xx or 3xx (expect 200/302 from Local) |
| Artifacts | `artifactDir` created if missing |

## Keys

| Key | Purpose |
|-----|---------|
| `baseUrl` | Local site origin, e.g. `http://localhost:10033` |
| `adminPath` | Usually `/wp-admin` |
| `pluginPath` | Absolute path to the active plugin (symlink in `wp-content/plugins`) |
| `pluginMustResolveTo` | Absolute path to this git checkout |
| `wpContentPlugins` | Absolute path to `wp-content/plugins` |
| `autoLoginUrl` | LocalWP auto-login URL (used by e2e auth in 0.2+) |
| `useMocks` | `true` routes Sheet/Drive/mail to file-backed mocks |
| `artifactDir` | Relative or absolute dir for mock outputs (default `tests/.artifacts`) |
| `testPortalPrefix` | Prefix for e2e-seeded portals (`dg-e2e-`) |

## Current LocalWP (this machine)

- Site: **ISJAC overhaul** → `http://localhost:10033`
- Plugin symlink:  
  `~/Local Sites/isjac-overhaul/app/public/wp-content/plugins/portal-builder-0.0.4a`  
  → `/Users/zbornheimer/Developer/Personal/portal-builder`
