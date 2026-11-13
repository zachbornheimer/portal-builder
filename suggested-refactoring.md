# Suggested refactoring — DragonGate Portals

**Date:** 2026-08-14  
**Scope:** read-only structural assessment against the code-construction contract  
**Verdict:** **FAIL**

This is not a style review. Production still has two permanent owners for the same product operations. A definition-first layer sits beside the shortcode/Google path instead of replacing it. Passing unit and pipeline tests do not change that.

The new stack is the right target. The work is ownership transfer, not a rewrite from zero.

---

## 1. What a caller understands

Two product operations, one resource.

| Operation | Caller | Done when |
|-----------|--------|-----------|
| **Author a portal** | Admin (often a volunteer) | A public URL exists. Fields, mapping, and open/closed are one saved document. |
| **Submit an application** | Applicant | One validated payload becomes a sheet row, stored files, and a receipt. |

`docs/design/PORTAL-MODEL.md` already names this. The production path does not yet embody it.

There is no `LAYOUT.md`. Bootstrap is a `require_once` bag (`portal-builder.php:44–71`) plus side-effect `::init()` calls at the bottom of REST, public render, and setup-screen files.

---

## 2. Traced production paths

### 2.1 Submit an application (two mutation authorities)

**Definition path (test-only)**

```
POST + dg_definition_submit
  → handle_submissions()                 portal-builder.php:239
  → nonce check
  → Portal_Submission_Pipeline::process_request()
       load definition
       require DG_TEST_MODE              class-portal-submission-pipeline.php:76
       validate
       store files / append row / mail
  → set DG_DEFINITION_SUBMIT_* constants
  → Portal_Public_Render swaps content
```

The pipeline’s `process()` (`class-portal-submission-pipeline.php:309`) is the closest observe → decide → execute sequence in the repo. The HTTP wrapper around it is not. It does not observe open/closed. It does not bind a definition version. It refuses live Google (`DG_TEST_MODE` required).

**Legacy path (live Google)**

```
POST review_nonce
  → Portal_File_Handler temp store       portal-builder.php:282
  → review HTML via regex on shortcode output
POST ready_to_submit_nonce
  → decrypt app id
  → pb_try_definition_submission()       only if DG_TEST_MODE
  → else Portal_Submission::process_submission()
       Zysys_FileStore Drive + Sheets
       PHPMailer
       permanent local files
```

`Portal_Submission` (`includes/class-portal-submission.php`) observes post meta, decides what to write, and talks to Google and mail in one class. Payment, PDF, and S3 methods are unused stubs (`:89–119`).

**Bypass list (submit)**

1. Definition POST (`portal-builder.php:239`)
2. Legacy two-nonce (`:282`, `:310`)
3. `pb_try_definition_submission` from the legacy final step (`:346`)
4. PHP CLI harness `tests/support/php-submit-pipeline.php` (tests only)

### 2.2 Author a portal (two mutation authorities)

**Wizard / REST (product UI)**

```
Setup screen or hidden meta box
  → [data-portal-wizard]
  → WizardShell load/save
  → POST dragongate/v1/portals/{id}/definition
       Portal_Definition::validate
       update_post_meta(_portal_definition)
  → POST wp/v2/portal/{id}               status + title
```

`canOpen` lives only in `PublishStep.svelte:88` (`fieldCount > 0 && unmapped.length === 0`). REST does not enforce mapping. REST does not check `version` before write (`class-portal-definition-rest.php:83–113`).

**Legacy meta (still registered, CSS-hidden)**

```
save_post
  → Portal_Meta::save_post_meta          class-portal-meta.php:152
  → _portal_deadline, _portal_record_keeping,
    _portal_file_backups, anonymize, …
```

No nonce on that save. Hidden `[data-segmented-control]` still mounts the old Svelte mapper (`src/index.js:166–168`). `save_post` can still write the old mapping keys.

**Open/closed is display-only**

`Portal_Open_State` is a real decision owner for render. Submit does not call it. Preview banner says “not a live submission” (`class-portal-public-render.php:18`) while a definition POST can still run.

### 2.3 Render the public form (two chrome owners)

```
single-portal.php
  → [portal-application-title]
  → [portal-application-formstart]       empty when PB_APPLICATION_DEADLINE_PASSED
  → the_content
       Portal_Public_Render::filter_content
         closed message  OR  preview + definition HTML  OR  legacy wrap
  → [portal-application-agreements]      repeats deadline copy when closed
  → [portal-application-upload-notes]    same
  → [portal-application-formend]
```

This is the closed-page triple message already recorded in `docs/design/UX-NEXT-STEPS.md` §3 P0-1. The definition renderer owns fields. The template still owns chrome.

---

## 3. Construction-contract findings

### 3.1 Product expression — FAIL

The product operation is obvious in `PORTAL-MODEL.md`. It is not obvious in the plugin file.

`handle_submissions()` (`portal-builder.php:235–382`) is the real submit entrypoint. A reader cannot see observe → decide → execute there. They see nonce branches, constants as request state, and a fallback into the legacy class.

`WizardShell.svelte` (`src/wizard/WizardShell.svelte:70–259`) mixes load, normalize, open-state labeling, persist, and step routing. Steps then rebuild fetch and policy (`StartStep` clone I/O; `PublishStep` `canOpen`).

External triggers do not converge:

- definition POST vs legacy two-nonce
- REST definition vs `save_post` meta
- wizard publish vs WP core editor (setup screen redirects away; meta boxes remain)

### 3.2 Observation and versioning — FAIL

`Portal_Definition` stores `version` as an int defaulting to 1 (`class-portal-definition.php:85`). Nothing uses it as a basis.

- REST PUT overwrites current meta. No compare-and-swap.
- Submit loads current `_portal_definition` at request time. The sheet row records `createdAt` and `portalId`, not the definition version (`class-portal-submission-pipeline.php:395–398`).
- Open/closed and submit can disagree if an admin publishes or force-closes between render and POST. There is no re-observation at the mutation.

Observation and effects are mixed in `process_request`, `Portal_Submission`, and `WizardShell.persist`.

### 3.3 Decision purity and plans — PARTIAL

**Keep and grow**

| Owner | Why it already fits |
|-------|---------------------|
| `Portal_Open_State::is_open_from_publish` | Pure. Injectable clock. Tested via `tests/unit/open-state.test.mjs`. |
| `Portal_Definition::validate` | Pure schema. Tested via `tests/unit/definition-validate.test.mjs`. |
| `Portal_Submission_Validator` + `Field_Rules` | Mostly pure; file magic still reads the filesystem (`class-portal-submission-field-rules.php` ~247). |
| `src/wizard/definitionModel.js` | Pure templates and field helpers. No I/O. |

**Missing**

No submit plan type. `process()` validates, then immediately writes Drive, then Sheet, then mail. Partial failure is not a closed outcome (`received` / `synced` / `failed` exist as constants; only `synced` is written).

No publish plan type. The client decides `canOpen`; WP REST sets `publish`. Mapping gaps are a UI hint, not a server decision.

JS `deriveApplicantStatus` (`WizardShell.svelte:164–173`) re-implements open/closed with `Date.parse` and no timezone. PHP uses `DateTimeZone`. Two answers for one question.

### 3.4 Ownership and authority — FAIL

| Concept | Owners today (too many) | Should own it |
|---------|-------------------------|---------------|
| Submit mutation | Pipeline + `Portal_Submission` + `handle_submissions` | One submit operation |
| Definition document | REST + `Portal_Definition` + JS `normalizeLoaded` | `Portal_Definition` only |
| Mapping | `_portal_definition.mapping` + `_portal_record_keeping` / `_portal_file_backups` | Definition document |
| Open/closed | `Portal_Open_State` + JS derive + `is_application_deadline_passed` + `PB_APPLICATION_DEADLINE_PASSED` + shortcodes | `Portal_Open_State` |
| Applicant pack | Renderer + field rules + `portal-applicant-information.php` | Field rules + one renderer |
| Field types | PHP `FIELD_TYPES` + JS catalog (subset) + renderer switch | One closed catalog |
| Google / mail | `Zysys_FileStore` + PHPMailer in `Portal_Submission`; file adapters in pipeline | Ports already sketched (`Sheet_Store`, `Drive_Store`, `Mailer`) |
| Admin UI | Wizard + hidden meta boxes + leftover mapper | Wizard only |
| Public chrome | Definition renderer + shortcode template | One public form owner |

A handler, worker, or helper can bypass the new owner. That is the structural failure.

### 3.5 Domain modeling — FAIL on states that matter

Closed and useful:

- `Portal_Definition::FIELD_TYPES`
- Pipeline status constants (unused as a state machine)

Loose and unsafe:

- `forceClosed` bool + deadline string + WP `post_status` as three independent knobs (PORTAL-MODEL already says `isOpen` is derived; production still treats them as separate writes)
- Timezone: IANA in definition, numeric index in `_portal_timezone` (`class-portal-open-state.php:104–109`)
- Field `type` is a raw string in JS (`definitionModel.js:5`); catalog omits `branch`, `long_text`, `email`, `phone`, `file`, `static_html`
- Receipt identity in query args (`email`, `name`, `work_title`) (`class-portal-submission-pipeline.php:203–221`)
- Request outcome as PHP `define()` flags (`PB_APPLICATION_SUBMITTED`, `DG_DEFINITION_SUBMIT_OK`)

JS is not TypeScript. Domain logic in the wizard cannot exhaustively handle a closed `FieldType`.

### 3.6 Contracts — FAIL

Public surfaces with no single machine contract:

- REST `dragongate/v1/portals/{id}/definition` — implicit JSON; no OpenAPI
- WP core `wp/v2/portal` for title/status
- POST form (`dg_definition_submit` or `review_nonce` / `ready_to_submit_nonce`)
- Options (`pb_google_secret_key`, receipt copy, disclaimers)
- `_portal_definition` JSON document — PHP validate is the real schema; JS and fixtures are copies

`PORTAL-MODEL.md` is the human contract. PHP `Portal_Definition::validate` is the only enforced schema. JS `normalizeLoaded` accepts a weaker shape.

No idempotency key on submit. A refresh can write a second row (the pipeline e2e even asserts append). That may be intended; it is not named as product policy.

### 3.7 Composition and size — FAIL

Review bar: ~250 lines per production file, ~50 per function, one purpose.

| File | Lines | Problem |
|------|------:|---------|
| `src/styles.css` | 872 | Wizard + leftover `.portal-builder` |
| `portal-builder.php` | 660 | Boot + submit + review HTML + crypto |
| `includes/templates.php` | 634 | Template redirect + Gutenberg twins of shortcodes |
| `includes/class-portal-settings.php` | 470 | Options UI + secrets + URL probe |
| `includes/Submission/class-portal-submission-pipeline.php` | 447 | HTTP wrapper + pipeline + HTML |
| `gsuite-filestore/zysys-file-store.class.php` | 437 | Live Google SDK (HOW in the name) |
| `assets/data-table.js` | 399 | Legacy table |
| `includes/class-portal-submission.php` | 395 | Live submit god-class |
| `includes/Definition/class-portal-definition-renderer.php` | 386 | Field HTML switchboard |
| `includes/class-portal-setup-screen.php` | 377 | Admin surface + draft create + assets |
| `src/wizard/WizardShell.svelte` | 359 | Load/save/status + router |
| `includes/class-portal-meta.php` | 354 | Wizard mount + hidden meta + SSRF probe |
| `includes/class-data-table.php` | 335 | Admin table |
| `includes/class-portal-about.php` | 317 | About page + inline CSS |
| `includes/Submission/class-portal-submission-field-rules.php` | 314 | Rules + filesystem magic |
| `src/SheetManager.js` | 312 | Legacy mapper |
| `src/wizard/steps/PublishStep.svelte` | 269 | Form + publish policy |
| `src/fields/TagsField.svelte` | 406 | Legacy tags + WP content parse |

Functions over the bar include `handle_submissions`, `pb_reviewable_content_filter` (`portal-builder.php:460–602`), `Portal_Submission::store_records`, `Portal_Definition::validate_fields`, `WizardShell` persist/load effect.

Do not split these by line count. Rename the owners first.

### 3.8 Production reality and tests — PARTIAL

**What tests actually prove**

| Test | Production symbol | Gap |
|------|-------------------|-----|
| `tests/unit/definition-validate.test.mjs` | `Portal_Definition::validate` via CLI harness | Good. Plain JSON. |
| `tests/unit/open-state.test.mjs` | `is_open_from_publish` via harness | Good. Does not cover JS derive. |
| `tests/unit/submit-pipeline.test.mjs` | `Pipeline::process` via harness | Artifacts only. Not `handle_submissions`. Not open-state. |
| `tests/unit/definition-render.test.mjs` | renderer harness | Markup, not public request. |
| `e2e/pipeline/submit-herbolzheimer.spec.ts` | same CLI harness as unit | Named e2e; not HTTP. |
| `e2e/admin/definition-api.spec.ts` | live `dragongate/v1` | Persist shape. Overwrites the **first listed portal**, not a seeded isolate. |
| `e2e/admin/definition-persist.spec.ts` | — | Skipped (`test.skip`, superseded). |
| `e2e/admin/portal-crud.spec.ts` | WP core `wp/v2/portal` | Create/edit/trash. Not DragonGate REST. |
| `e2e/public/render-herbolzheimer.spec.ts` | seed + `the_content` | Definition markers. Soft on `sub_*` names. |
| `e2e/public/closed-preview.spec.ts` | live public + preview | Best stale/auth observation: anon closed vs editor `?preview=true`. Does not POST. |
| `e2e/pipeline/submit-herbolzheimer.spec.ts` | same CLI as unit | Named e2e; no browser, no WP POST. |
| `tests/mocks/selftest.mjs` | JS `tests/mocks/*` | File-backed Sheet/Drive/Mail **in JavaScript** — a second mock stack, not the PHP stores. |
| `tests/support/run-import-tests.mjs` | — | Always skip. Legacy-import characterization does not exist. |
| `tests/unit/smoke.test.mjs` | none | `1+1=2` runner canary. |

The PHP harnesses stub `WP_Error` and skip WordPress (`tests/support/php-submit-pipeline.php:21`). That is correct for pure decisions. It is not a public-entrypoint test.

Unit tests **do** `require_once` the real classes (`php-open-state.php`, `php-validate-definition.php`, `php-render-definition.php`, `php-submit-pipeline.php`). They do **not** boot WordPress, so they never hit `process_request`, nonce, `$_FILES`, `handle_submissions`, REST, or `Portal_Public_Render`.

**Not covered**

- Live `Portal_Submission` / Google path (zero tests on the shortcode submit class)
- Browser POST of the public form
- Submit while closed or preview (preview e2e checks banner + fields only)
- `DG_TEST_MODE` gate on `process_request` (`class-portal-submission-pipeline.php:76–85`)
- Stale definition version
- `save_post` vs REST conflict
- Unauthenticated REST 401/403; missing/bad submit nonce
- Capability/nonce on `validate_url_callback`
- Mapping required to publish (server)
- Recaptcha / agreements on definition submit
- Path traversal, replay (the append test is two successful writes, not a replay)
- Shortcode-era characterization and the importer (`run-import-tests.mjs` is a stub)

**Two mock stacks.** `npm run test:mocks` exercises JavaScript file-backed Sheet/Drive/Mail under `tests/mocks/`. The pipeline tests exercise PHP `Portal_Sheet_Store` / `Drive_Store` / `Mailer`. Same artifact idea, two owners. Path 2 should keep the PHP ports and delete or stop treating the JS mocks as a second product.

**Repository contract**

No `mise.toml`. No Lefthook. No Trunk. No `LAYOUT.md`.

| Gate | What it actually runs |
|------|------------------------|
| PR CI `.github/workflows/ci.yml` | `npm run ci:offline` = JS mocks + unit only |
| `npm run ci` (local “full”) | env + mocks + unit + Playwright **smoke** only |
| `.github/workflows/e2e-local.yml` | `workflow_dispatch` on `[self-hosted, localwp]` — not a required check |
| `phpcs.xml` | WordPress-Core/Docs/Extra. **Not** in `npm run ci` |
| `HOOKS.md` | Documents `.git/hooks/pre-commit` PHPCBF. Mentions `./test-hook.sh`, which is not in the repo |
| `Makefile` | Zip/release build, not test |

This does not meet the org mise task contract. Marked as Path 7, not the first refactor.

### 3.9 Security and reliability (quality attributes)

These are in scope because they sit on the mutation paths.

| Seam | Evidence | Construction issue |
|------|----------|-------------------|
| SSRF | `Portal_Meta::validate_url_callback` (`class-portal-meta.php:286`) and settings twin: `wp_remote_get($_POST['url'])`, no nonce, no cap | External fetch without an owned port or auth |
| Preview vs live | Banner is copy only; submit does not check `is_preview_request` / `is_open` | Effect ignores the decision owner |
| Receipt PII | Name/email in query string (`class-portal-submission-pipeline.php:208`) | Identity in logs and Referer |
| Secrets | Service account JSON in `pb_google_secret_key` option | Expected for WP; no facade, logged risk via settings UI |
| Uploads | `ABSPATH/tmp-uploads` and `/file-storage/` (`portal-builder.php:23–36`); review links serve temp files | Path policy is constants, not a Files port on the legacy path |
| `save_post` | Cap check, no nonce (`class-portal-meta.php:152`) | Mutation without request authenticity |
| Duplicate | `edit_posts` not `edit_post` (`class-portal-post-type.php:142`) | Authorization at the wrong grain |
| Crypto | `pb_encrypt_str` uses `NONCE_SALT` (`portal-builder.php:618`) | Mechanism named as a helper in the bootstrap file |

Definition pipeline adapters (`Portal_Files`, sheet/drive/mail stores) are the right shape. Live Google is not behind them.

---

## 4. What is already correct — do not throw it away

The definition module is the paved road. A refactor that starts over would discard working owners.

1. **One document** — `_portal_definition` JSON (`Portal_Definition::META_KEY`).
2. **Pure open check** — `is_open_from_publish` + clock injection.
3. **Ports sketched** — `Portal_Sheet_Store`, `Portal_Drive_Store`, `Portal_Mailer`, `Portal_Files`.
4. **Validator split** — walk in `Portal_Submission_Validator`, per-field in `Field_Rules`.
5. **Wizard model** — `definitionModel.js` is already a pure module.
6. **REST permission** — `current_user_can('edit_post', $id)` on definition GET/PUT.
7. **Setup screen** — dedicated admin surface (`Portal_Setup_Screen`) is the right authoring host.
8. **Characterization fixtures** — Herbolzheimer definition + submission + expected sheet row.

The missing move: make these the only production path, then delete the rest.

---

## 5. Proposed `LAYOUT.md`

Write this next to `portal-builder.php` before moving files. Holdable concepts, not one file per old method.

```text
# Author a portal
src/wizard/WizardShell.svelte: observe definition + post; run persist; host steps
src/wizard/definitionModel.js: pure definition values and templates
src/wizard/definitionApi.js: port to definition REST and post REST
src/wizard/steps/: one visual region each (start, build, map, publish)
includes/Definition/class-portal-definition.php: schema; load; save
includes/class-portal-definition-rest.php: HTTP adapter for definition save/load
includes/Author/class-portal-publish.php: pure publish plan; execute status + definition

# Submit an application
includes/Submission/class-portal-submit.php: observe → decide → execute
includes/Submission/class-portal-submission-validator.php: pure validate → collected values
includes/Submission/class-portal-submission-field-rules.php: per-field rules (no FS)
includes/Definition/class-portal-open-state.php: open / closed / preview
includes/Definition/class-portal-public-form.php: applicant HTML + closed + receipt
includes/Submission/ports.php: SheetStore, DriveStore, Mailer, Uploads, Clock

# Adapters
includes/Submission/class-portal-files.php: filesystem
includes/Submission/class-portal-sheet-store.php: sheet artifact (test) / Google adapter later
includes/Submission/class-portal-drive-store.php: file artifact / Google adapter later
includes/Submission/class-portal-mailer.php: mail capture / PHPMailer adapter later
includes/adapters/class-portal-google-store.php: live Google behind the same ports

# Composition
portal-builder.php: boot; register adapters; route HTTP to Submit / Author
includes/class-portal-setup-screen.php: admin host for the wizard
includes/class-portal-post-type.php: CPT + list only
includes/class-portal-settings.php: Google credentials + receipt copy (no URL fetch)
```

**Delete after callers move** (not “migrate later”):

- `includes/class-portal-submission.php` live path
- Hidden meta boxes + `save_post` field loop for mapping/deadline
- `src/PortalBuilder.svelte`, `SheetManager.js`, `GlobalStore.js`, `fields/TagsField.svelte`, `services/ContentParser.js` once mapping lives only in the wizard
- Shortcode chrome on definition portals (`single-portal.php` agreements/notes/title when a definition exists)
- `pb_reviewable_content_filter` and two-nonce review
- `is_application_deadline_passed` wrapper once callers use `Open_State`
- Unused `GateSeal.svelte` or wire it as the only gate UI

A temporary seam is allowed: **legacy shortcode posts without `_portal_definition` keep the old submit class**, named and listed. Two owners for definition portals are not.

---

## 6. Refactor sequence

Each item is one complete domain path. Do not extract files inside a path and leave both owners. Protect behavior with a test first when the path already has a public effect.

### Path 0 — Ledger (no behavior change)

Write `LAYOUT.md` from §5. Stop adding files that are not on it.

Done when: every production path in this document maps to one line in the ledger.

### Path 1 — Submit uses one owner

**Operation:** submit an application.

```
submit_application(request, deps):
    observation = observe_submit(request)   # post, files, definition, open-state, preview, version
    plan        = decide_submit(observation) # RejectClosed | RejectPreview | RejectInvalid | Accept
    return execute_submit(observation.basis, plan)
```

**Decision owner:** new pure `decide_submit` next to the validator (closed plan, not a bool).  
**Mutation authority:** `Portal_Submission_Pipeline::process` only.  
**Basis:** definition `version` + portal id + `is_open` at observe time; re-check open + version immediately before the first write.

Work:

1. Red tests: submit when closed; submit when preview; definition version changed between observe and write; missing `DG_TEST_MODE` is not a product error once live adapters exist.
2. `handle_submissions` becomes an adapter: parse POST, verify nonce, call `submit_application`, map the plan to HTML. No Drive/Sheet/mail in the plugin file.
3. Call `Open_State` on this path. Preview may render the form; it must not `Accept`.
4. Route `ready_to_submit_nonce` through the same function when a definition exists. Remove `pb_try_definition_submission` as a special case.
5. Split `process_request` HTML helpers out of the pipeline file so the pipeline file is the executor only.
6. Push file-magic reads behind `Portal_Files` (or a `Uploads` port). Field rules stay pure.

Done when: grep shows no `new Portal_Submission` for posts that have `_portal_definition`; closed/preview probes fail closed; pipeline unit tests still green.

### Path 2 — Live adapters behind the existing ports

**Operation:** same submit. Leaf adapter change once Path 1 exists.

`Portal_Sheet_Store` / `Drive_Store` / `Mailer` already name WHAT. Implement Google and PHPMailer as alternate constructors or sibling adapters that satisfy the same methods. `Portal_Submission` and `Zysys_FileStore` become the Google adapter’s private implementation, then disappear from the domain.

`DG_TEST_MODE` becomes “use file adapters”, not “definition submit is forbidden in production.”

Done when: one definition POST on a published open portal writes a real Sheet row without calling `Portal_Submission::process_submission`. Characterization: Herbolzheimer fixture against a test spreadsheet or recorded adapter.

### Path 3 — Definition save has one writer and a version basis

**Operation:** save definition / open the gate.

```
save_definition(input, deps):
    observation = load current document + post status + cap
    plan        = decide_save(observation, input)  # Conflict | Invalid | SaveDraft | Open
    return execute_save(observation.basis, plan)
```

Work:

1. REST PUT requires the client `version` and rejects mismatch (409). Increment on successful write.
2. Move `canOpen` / mapping completeness into PHP next to `Portal_Definition`. `PublishStep` displays the plan; it does not invent it.
3. `updatePortalPost(status=publish)` only after the definition plan is `Open`.
4. Stop registering hidden mapping/deadline meta boxes. Stop `save_post` writes for those keys on definition portals. Import remains a one-way read (`Open_State::legacy_publish_config`).
5. Delete JS `normalizeLoaded` policy that disagrees with `Portal_Definition::validate`. The client may default a blank document; it may not accept a shape the server would reject.

Done when: a stale wizard tab cannot overwrite a newer definition; opening the gate without mapping fails on the server; `_portal_record_keeping` is no longer written from admin.

### Path 4 — Public render has one chrome owner

**Operation:** show the applicant form, closed page, or receipt.

`Portal_Public_Render` (rename toward `PublicForm` if the ledger wants it) renders the whole applicant page for definition portals: title, form, nonce, agreements from the definition, success, closed, preview. `single-portal.php` becomes a theme shell: `the_content` only.

Work:

1. Move formstart nonce into the definition renderer (or one `PublicForm::render` that includes it).
2. Stop emitting agreements/upload-notes/title shortcodes when a definition exists.
3. One closed message. Delete `PB_APPLICATION_DEADLINE_PASSED` as a cross-file bus; pass a typed view model.
4. Tokenize closed/preview/success with the same tokens as the wizard (`src/tokens.css`).

Done when: a closed URL shows one sentence; a definition portal HTML source has no `[portal-application-*]` output; public e2e still pass.

### Path 5 — Admin surface is the wizard only

**Operation:** author a portal.

Work:

1. Keep `Portal_Setup_Screen` as the host. Remove wizard mount from the block-editor meta box path once redirects are the only edit link (already partly true).
2. Unmount path: stop `initPortalBuilder()` in production (`src/index.js:167`).
3. Delete or quarantine the legacy Svelte mapper (`SheetManager`, `GlobalStore`, `TagsField`, `ContentParser`) after Path 3.
4. Lift `deriveApplicantStatus` out of the shell; call a shared representation of `Open_State` (shared fixture tests, or render status from the server).
5. Split `WizardShell`: observation/persist module + view. Steps stay presentational.

Done when: `src/index.js` mounts only `[data-portal-wizard]`; unused `GateSeal` is wired or gone; `src/styles.css` no longer styles `.portal-builder` leftovers.

### Path 6 — Bootstrap and leftover bags

After Paths 1–5:

- `portal-builder.php` only defines constants, loads the autoloader, and calls a composition root. Review HTML and crypto move with their owners (review dies with Path 1; encrypt dies with legacy two-nonce).
- `templates.php` Gutenberg clones of shortcodes go away with Path 4 or become thin wrappers around `PublicForm`.
- `Portal_Builder::init` must stop constructing CPT/meta a second time (`class-portal-builder.php:7–18` vs `portal-builder.php:97–103`). `register_post_type( 'portal' )` runs twice on `plugins_loaded`.
- `class-portal-settings.php`: keep credentials and receipt copy; remove unauthenticated URL probe.
- Name `Zysys_FileStore` out of domain language. The port is `DriveStore` / `SheetStore`.

### Path 7 — Repository gate (later)

Only after the product paths above. Add `mise.toml` tasks `setup / lint / test / scan / ci` that wrap existing npm + phpcs. Replace `HOOKS.md`’s ad-hoc git hook with Lefthook calling those tasks. Do not invent a second CI story while two submit owners remain.

### Path 8 — TypeScript for new domain JS (later)

`definitionModel`, publish plan, and open-state labeling should be strict TS with closed `FieldType`. Do not convert `SheetManager` first.

---

## 7. What not to do

- Do not split `portal-builder.php` or `WizardShell.svelte` by line count and keep the same mixed purposes.
- Do not add another facade around `Portal_Submission` and call the job done. Wrappers that preserve the old owner fail the migration contract.
- Do not keep the hidden meta boxes “for compatibility” once definition portals no longer read those keys at runtime. Import can read them; authoring must not write them.
- Do not implement live Google inside `process()` with `new Google_Client`. Extend the existing stores.
- Do not move publish policy into more Svelte. The server already owns `Portal_Definition`.
- Do not write OpenAPI until Path 3 freezes the definition document and error shape. Then the REST route is the first contract to publish.
- Do not treat `e2e/pipeline/submit-herbolzheimer.spec.ts` as proof of the HTTP submit path. It is the CLI harness again.

---

## 8. Suggested first commit series

Small, reviewable, each one a complete path:

1. `docs: add LAYOUT.md` from §5 (this file stays as the assessment).
2. `test: closed and preview submits are rejected` (red against Path 1).
3. `feat: submit_application owns definition POST` + green those tests.
4. `feat: definition posts no longer construct Portal_Submission`.
5. `feat: definition save compares version`.
6. `feat: publish plan enforced on REST`.
7. `refactor: public form is one renderer for definition portals`.
8. `refactor: remove hidden mapping meta boxes`.
9. `feat: Google adapters implement existing stores`.
10. `chore: delete dead mapper and review filter`.

Do not start at 9. Live Google on a forked policy duplicates the current mess.

---

## 9. Evidence limits (NOT PROVEN)

- Full Playwright suite and `npm run ci` were not executed for this assessment. Claims about what tests cover come from reading the specs and harnesses, not from a green run in this pass.
- Live Google write against a real spreadsheet was not observed.
- Whether any production site still depends on shortcode-only portals (no `_portal_definition`) is unknown. The seam in Path 1 assumes some still exist.
- Woo/membership `freeForMembers` and fee collection are modeled in PORTAL-MODEL and stubbed in `Portal_Submission`; no working payment path was found.
- `STATUS_RECEIVED` / `STATUS_FAILED` have no writer. Recovery after a Drive-success / Sheet-failure is unproven.
- `e2e/admin/definition-api.spec.ts` mutates whichever portal is first in the admin list. Isolation of that contract is not proven.

---

## 10. Bottom line

The product already named the right owners in `PORTAL-MODEL.md` and sketched them in `includes/Definition/` and `includes/Submission/`. Production still runs the 2024 shortcode plugin next to that sketch.

The smallest complete correction is not a new framework and not a file-by-file tidy. It is:

1. One submit operation that observes, decides, and executes.
2. One definition writer with a version basis.
3. One public form owner.
4. Live Google behind the stores that tests already use.
5. Delete the second owner of each.

Until those five land, the construction verdict stays **FAIL**.
