# Suggested refactoring — DragonGate Portals

**Date:** 2026-08-15\
**Scope:** read-only structural assessment against the code-construction contract, WordPress plugin handbook / Plugin Check, and Svelte 5 construction (runes, session, views)\
**Verdict:** **FAIL**

This is not a style review. Production still has two permanent owners for the same product operations. A definition-first layer sits beside the shortcode/Google path instead of replacing it. Passing unit and pipeline tests do not change that. The same files also fail WordPress mutation, upload, i18n, and lifecycle rules. Those are not a later polish pass — they sit on the same submit and save paths.

The new stack is the right target. The work is ownership transfer, not a rewrite from zero.

---

## 1. What a caller understands

Two product operations, one resource.

| Operation                 | Caller                    | Done when                                                                     |
| ------------------------- | ------------------------- | ----------------------------------------------------------------------------- |
| **Author a portal**       | Admin (often a volunteer) | A public URL exists. Fields, mapping, and open/closed are one saved document. |
| **Submit an application** | Applicant                 | One validated payload becomes a sheet row, stored files, and a receipt.       |

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

| Owner                                         | Why it already fits                                                                                  |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `Portal_Open_State::is_open_from_publish`     | Pure. Injectable clock. Tested via `tests/unit/open-state.test.mjs`.                                 |
| `Portal_Definition::validate`                 | Pure schema. Tested via `tests/unit/definition-validate.test.mjs`.                                   |
| `Portal_Submission_Validator` + `Field_Rules` | Mostly pure; file magic still reads the filesystem (`class-portal-submission-field-rules.php` ~247). |
| `src/wizard/definitionModel.js`               | Pure templates and field helpers. No I/O.                                                            |

**Missing**

No submit plan type. `process()` validates, then immediately writes Drive, then Sheet, then mail. Partial failure is not a closed outcome (`received` / `synced` / `failed` exist as constants; only `synced` is written).

No publish plan type. The client decides `canOpen`; WP REST sets `publish`. Mapping gaps are a UI hint, not a server decision.

JS `deriveApplicantStatus` (`WizardShell.svelte:164–173`) re-implements open/closed with `Date.parse` and no timezone. PHP uses `DateTimeZone`. Two answers for one question.

### 3.4 Ownership and authority — FAIL

| Concept             | Owners today (too many)                                                                                            | Should own it                                                   |
| ------------------- | ------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------- |
| Submit mutation     | Pipeline + `Portal_Submission` + `handle_submissions`                                                              | One submit operation                                            |
| Definition document | REST + `Portal_Definition` + JS `normalizeLoaded`                                                                  | `Portal_Definition` only                                        |
| Mapping             | `_portal_definition.mapping` + `_portal_record_keeping` / `_portal_file_backups`                                   | Definition document                                             |
| Open/closed         | `Portal_Open_State` + JS derive + `is_application_deadline_passed` + `PB_APPLICATION_DEADLINE_PASSED` + shortcodes | `Portal_Open_State`                                             |
| Applicant pack      | Renderer + field rules + `portal-applicant-information.php`                                                        | Field rules + one renderer                                      |
| Field types         | PHP `FIELD_TYPES` + JS catalog (subset) + renderer switch                                                          | One closed catalog                                              |
| Google / mail       | `Zysys_FileStore` + PHPMailer in `Portal_Submission`; file adapters in pipeline                                    | Ports already sketched (`Sheet_Store`, `Drive_Store`, `Mailer`) |
| Admin UI            | Wizard + hidden meta boxes + leftover mapper                                                                       | Wizard only                                                     |
| Public chrome       | Definition renderer + shortcode template                                                                           | One public form owner                                           |

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

| File                                                          | Lines | Problem                                                  |
| ------------------------------------------------------------- | ----: | -------------------------------------------------------- |
| `src/wizard/definitionModel.js`                               |  1392 | Pure, but many owners (templates, tree, mapping, access) |
| `src/styles.css`                                              |  1115 | Wizard + leftover `.portal-builder`                      |
| `portal-builder.php`                                          |   660 | Boot + submit + review HTML + crypto                     |
| `includes/templates.php`                                      |   634 | Template redirect + Gutenberg twins of shortcodes        |
| `includes/class-portal-settings.php`                          |   470 | Options UI + secrets + URL probe                         |
| `includes/Submission/class-portal-submission-pipeline.php`    |   447 | HTTP wrapper + pipeline + HTML                           |
| `gsuite-filestore/zysys-file-store.class.php`                 |   437 | Live Google SDK (HOW in the name)                        |
| `assets/data-table.js`                                        |   399 | Legacy table                                             |
| `includes/class-portal-submission.php`                        |   395 | Live submit god-class                                    |
| `includes/Definition/class-portal-definition-renderer.php`    |   386 | Field HTML switchboard                                   |
| `includes/class-portal-setup-screen.php`                      |   377 | Admin surface + draft create + assets                    |
| `src/wizard/steps/PublishStep.svelte`                         |   503 | Form + access/fee + `canOpen`                            |
| `src/fields/TagsField.svelte`                                 |   406 | Legacy tags + WP content parse                           |
| `src/wizard/WizardShell.svelte`                               |   374 | Load/save/status + router                                |
| `includes/class-portal-meta.php`                              |   354 | Wizard mount + hidden meta + SSRF probe                  |
| `src/wizard/steps/MapStep.svelte`                             |   339 | Mapping UI                                               |
| `includes/class-data-table.php`                               |   335 | Admin table                                              |
| `src/wizard/steps/BuildStep.svelte`                           |   327 | Tree + inspector + drag/drop                             |
| `includes/class-portal-about.php`                             |   317 | About page + inline CSS                                  |
| `includes/Submission/class-portal-submission-field-rules.php` |   314 | Rules + filesystem magic                                 |
| `src/SheetManager.js`                                         |   312 | Legacy mapper                                            |

Functions over the bar include `handle_submissions`, `pb_reviewable_content_filter` (`portal-builder.php:460–602`), `Portal_Submission::store_records`, `Portal_Definition::validate_fields`, `WizardShell` persist/load effect.

Do not split these by line count. Rename the owners first.

### 3.8 Production reality and tests — PARTIAL

**What tests actually prove**

| Test                                        | Production symbol                             | Gap                                                                                       |
| ------------------------------------------- | --------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `tests/unit/definition-validate.test.mjs`   | `Portal_Definition::validate` via CLI harness | Good. Plain JSON.                                                                         |
| `tests/unit/open-state.test.mjs`            | `is_open_from_publish` via harness            | Good. Does not cover JS derive.                                                           |
| `tests/unit/submit-pipeline.test.mjs`       | `Pipeline::process` via harness               | Artifacts only. Not `handle_submissions`. Not open-state.                                 |
| `tests/unit/definition-render.test.mjs`     | renderer harness                              | Markup, not public request.                                                               |
| `e2e/pipeline/submit-herbolzheimer.spec.ts` | same CLI harness as unit                      | Named e2e; not HTTP.                                                                      |
| `e2e/admin/definition-api.spec.ts`          | live `dragongate/v1`                          | Persist shape. Overwrites the **first listed portal**, not a seeded isolate.              |
| `e2e/admin/definition-persist.spec.ts`      | —                                             | Skipped (`test.skip`, superseded).                                                        |
| `e2e/admin/portal-crud.spec.ts`             | WP core `wp/v2/portal`                        | Create/edit/trash. Not DragonGate REST.                                                   |
| `e2e/public/render-herbolzheimer.spec.ts`   | seed + `the_content`                          | Definition markers. Soft on `sub_*` names.                                                |
| `e2e/public/closed-preview.spec.ts`         | live public + preview                         | Best stale/auth observation: anon closed vs editor `?preview=true`. Does not POST.        |
| `e2e/pipeline/submit-herbolzheimer.spec.ts` | same CLI as unit                              | Named e2e; no browser, no WP POST.                                                        |
| `tests/mocks/selftest.mjs`                  | JS `tests/mocks/*`                            | File-backed Sheet/Drive/Mail **in JavaScript** — a second mock stack, not the PHP stores. |
| `tests/support/run-import-tests.mjs`        | —                                             | Always skip. Legacy-import characterization does not exist.                               |
| `tests/unit/smoke.test.mjs`                 | none                                          | `1+1=2` runner canary.                                                                    |

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

| Gate                              | What it actually runs                                                                         |
| --------------------------------- | --------------------------------------------------------------------------------------------- |
| PR CI `.github/workflows/ci.yml`  | `npm run ci:offline` = JS mocks + unit only                                                   |
| `npm run ci` (local “full”)       | env + mocks + unit + Playwright **smoke** only                                                |
| `.github/workflows/e2e-local.yml` | `workflow_dispatch` on `[self-hosted, localwp]` — not a required check                        |
| `phpcs.xml`                       | WordPress-Core/Docs/Extra. **Not** in `npm run ci`                                            |
| `HOOKS.md`                        | Documents `.git/hooks/pre-commit` PHPCBF. Mentions `./test-hook.sh`, which is not in the repo |
| `Makefile`                        | Zip/release build, not test                                                                   |

This does not meet the org mise task contract. Marked as Path 7, not the first refactor.

### 3.9 Security and reliability (quality attributes)

These are in scope because they sit on the mutation paths.

| Seam            | Evidence                                                                                                                               | Construction issue                                            |
| --------------- | -------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| SSRF            | `Portal_Meta::validate_url_callback` (`class-portal-meta.php:286`) and settings twin: `wp_remote_get($_POST['url'])`, no nonce, no cap | External fetch without an owned port or auth                  |
| Preview vs live | Banner is copy only; submit does not check `is_preview_request` / `is_open`                                                            | Effect ignores the decision owner                             |
| Receipt PII     | Name/email in query string (`class-portal-submission-pipeline.php:208`)                                                                | Identity in logs and Referer                                  |
| Secrets         | Service account JSON in `pb_google_secret_key` option                                                                                  | Expected for WP; no facade, logged risk via settings UI       |
| Uploads         | `ABSPATH/tmp-uploads` and `/file-storage/` (`portal-builder.php:23–36`); review links serve temp files                                 | Path policy is constants, not a Files port on the legacy path |
| `save_post`     | Cap check, no nonce (`class-portal-meta.php:152`)                                                                                      | Mutation without request authenticity                         |
| Duplicate       | `edit_posts` not `edit_post` (`class-portal-post-type.php:142`)                                                                        | Authorization at the wrong grain                              |
| Crypto          | `pb_encrypt_str` uses `NONCE_SALT` (`portal-builder.php:618`)                                                                          | Mechanism named as a helper in the bootstrap file             |

Definition pipeline adapters (`Portal_Files`, sheet/drive/mail stores) are the right shape. Live Google is not behind them.

---

## 4. WordPress plugin practices

WordPress is the host, not a layer to fight. Construction still owns observe → decide → execute. WordPress owns how this plugin boots, authorizes, stores files, talks to the user, and leaves the site. A path that is structurally clean and still writes to `ABSPATH/tmp-uploads` or mutates without a nonce has not transferred ownership.

Plugin Handbook, Plugin Check, and WordPress-VIP PHPCS (already in `composer.json` as `automattic/vipwpcs`, not in CI) are the bar. Cite production, not a generic list.

### 4.1 Prefix, identity, and lifecycle

| Rule                                                        | Production today                                                                                                                                                                                                              | Target                                                                                                                                                                |
| ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| One plugin slug and one text domain                         | Header `Text Domain: dragongate-portals` (`portal-builder.php:12`). Settings and admin JS use `'portal-builder'` (`class-portal-settings.php:19–55`, `:446`). No `load_plugin_textdomain`. No `languages/` directory.         | One domain: `dragongate-portals`. Load it on `init`. Every `__()` / `esc_html__()` uses that string.                                                                  |
| One prefix for functions, options, meta, CPT, REST, globals | Mix of `pb_`, `PB_`, `dg_`, `DG_`, `portal_`, `Portal_`. Options `pb_google_secret_key`. REST `dragongate/v1`. Nonce `dg_definition_submit`.                                                                                  | Keep `dragongate` / `dg_` for new public names. Do not invent a fourth prefix. Alias old option keys once, then stop writing `pb_*` on touched paths.                 |
| Register the CPT once, on `init`                            | `Portal_Post_Type::create_post_type` is hooked to `init` (correct), but `register_post_type()` is wired twice: `Portal_Builder::init` (`class-portal-builder.php:9`) and `pb_initialize_plugin` (`portal-builder.php:97–99`). | One constructor. `plugins_loaded` builds the composition root; `init` registers the CPT once.                                                                         |
| Activation registers, then flushes                          | `portal_plugin_activate` only `flush_rewrite_rules()` (`portal-builder.php:74–77`). The CPT is not registered in that request, so the flush is often a no-op.                                                                 | On activate: construct the post type, `register_post_type`, then flush. On deactivate: flush.                                                                         |
| Uninstall is explicit                                       | No `uninstall.php`, no `register_uninstall_hook`. Deactivate only flushes rewrites.                                                                                                                                           | `uninstall.php`: delete `pb_*` / `dg_*` options you own. Do **not** delete portal posts or `_portal_definition` unless the operator opts in — those are site content. |
| No work at include time                                     | REST, public render, and setup screen call `::init()` at file bottom (`class-portal-definition-rest.php`, `class-portal-public-render.php`, `class-portal-setup-screen.php`). Loading the file registers hooks.               | Files define classes. The composition root registers hooks.                                                                                                           |
| Guard direct access                                         | Some templates check `ABSPATH`; many `includes/*.php` files do not.                                                                                                                                                           | `defined( 'ABSPATH' ) \|\| exit;` at the top of every PHP file that is not the plugin header.                                                                         |

`@TODO add some sort of cron for tmp file cleanup` (`portal-builder.php:90`) is still open. If temp files stay, they need `wp_schedule_event` and a bound upload dir — not a comment.

### 4.2 Authorize at the mutation

WordPress rule: every write has a nonce **and** a capability check for that object.

| Mutation                                 | Nonce                                                    | Capability                                                      | Gap                                                                                                                                                            |
| ---------------------------------------- | -------------------------------------------------------- | --------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Definition REST PUT                      | WP REST nonce                                            | `edit_post` for that id (`class-portal-definition-rest.php:41`) | Good model. Keep. Add version compare (Path 3).                                                                                                                |
| Definition form POST                     | `dg_definition_submit` (`portal-builder.php:247`)        | None (public form — correct)                                    | Must still check open/preview (Path 1).                                                                                                                        |
| `save_post` meta                         | **None** (`class-portal-meta.php:152`)                   | `edit_post`                                                     | Add `check_admin_referer` or delete this writer (Path 3).                                                                                                      |
| `wp_ajax_validate_url` (meta + settings) | **None**                                                 | **None**                                                        | SSRF. Any logged-in user who can hit `admin-ajax.php` can make the server GET an arbitrary URL (`class-portal-meta.php:286`, `class-portal-settings.php:452`). |
| Duplicate GET/AJAX                       | GET `duplicate_nonce` (`class-portal-post-type.php:117`) | `edit_posts` not `edit_post`                                    | Use `check_ajax_referer` + `current_user_can( 'edit_post', $id )`. Prefer `admin-post.php` over a GET that copies.                                             |
| Setup `maybe_create_draft`               | **None**                                                 | `edit_posts`                                                    | Creating a post from a GET is CSRF-shaped. Use `admin-post.php` + nonce, or create only from a POST “Add New”.                                                 |
| Settings options                         | Settings API nonce (core)                                | `manage_options` (core page)                                    | Good host. Sanitize callbacks are missing or wrong (`wp_json_encode` is not a sanitizer — `class-portal-settings.php:69`).                                     |

Public submit is unauthenticated by design. That does not excuse preview submit, closed submit, or missing MIME/size limits.

### 4.3 Input, output, and data

- **Sanitize on the way in, escape on the way out.** Settings echo raw option values into `value="<?php echo $value; ?>"` (`class-portal-settings.php:294`, `:325–413`). Use `esc_attr`, `esc_url`, `esc_textarea`. Use `esc_html_e()` instead of `_e()` when the string is not wrapped in a safe HTML builder.
- **Do not echo `$_POST`.** `templates.php` writes `eappId` / `efiles` without `esc_attr` (`includes/templates.php:324–325`). File-review shortcode is better (`esc_attr`) — the block twin is not.
- **Never `sanitize_text_field` a JSON blob.** `save_json_meta` already avoids that for Sheets/Drive (`class-portal-meta.php:186–216`). REST `update_post_meta` of `Portal_Definition::to_json` is the right pattern. Keep it as the only writer.
- **Prepared queries only.** No raw `$wpdb` found in product PHP. Stay that way. Do not add a custom table in this refactor unless Path 1 proves post meta cannot hold the definition document (PORTAL-MODEL says it can).
- **Identity does not belong in query strings.** Receipt `email` / `name` (`class-portal-submission-pipeline.php:208`) land in access logs, Referer, and shared links. Receipt should be a capability-gated or tokenized view, or a file the mailer already wrote.
- **`define()` is not request state.** `PB_APPLICATION_SUBMITTED`, `DG_DEFINITION_SUBMIT_OK`, `PB_APPLICATION_DEADLINE_PASSED` are process-wide constants. A second portal in the same request cannot have a different outcome. Use a request object or `Portal_Public_Render` instance state.

### 4.4 Files, mail, and process

WordPress already owns uploads and mail. The plugin bypasses both.

| Concern       | Production                                                                                                                                                                          | WordPress-shaped target                                                                                                                                                                                                                                                                            |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Upload root   | `ABSPATH . '/tmp-uploads/'` and `ABSPATH . '/file-storage/'` (`portal-builder.php:23–36`). Web-reachable, outside `wp-content/uploads`, no `index.php` / `.htaccess` story in-tree. | `wp_upload_dir()` subdirectory, e.g. `uploads/dragongate/{portal_id}/`. Not web-listable. Serve through a capability-checked download or a signed URL, not a raw path under the docroot.                                                                                                           |
| Accept upload | Legacy `Portal_File_Handler` + `$_FILES`. No `wp_handle_upload`, no `wp_check_filetype_and_ext`.                                                                                    | `wp_handle_upload` (or a port that wraps it) + `wp_check_filetype_and_ext` in addition to magic-byte rules. Bound size.                                                                                                                                                                            |
| Anonymize     | `shell_exec` of `exiftool`, `qpdf`, `lame`, `eyed3`, `perl` with option-configured binary paths (`class-portal-file-handler.php:153–200`).                                          | If anonymize stays, run through an owned `Anonymizer` port with allowlisted binaries and `escapeshellarg` on every argument (today the binary path is not arg-escaped). Prefer PHP libraries over shell. Never `shell_exec` a bitrate string that has not been proven integer (partially checked). |
| Mail          | Bundled PHPMailer in `vendor/` and a second copy under `gsuite-filestore/vendor`. Comment says `wp_mail` (`class-portal-submission.php:131`) then constructs `new PHPMailer`.       | `wp_mail` (or a Mailer port whose live adapter calls `wp_mail`). Let the site’s SMTP plugin work. Do not ship a second PHPMailer.                                                                                                                                                                  |
| Google SDK    | Entire Client + Guzzle + phpseclib tree committed under `gsuite-filestore/vendor/`. Standalone `get-credentials/*.php` is not a WP admin flow.                                      | Composer at the plugin root. OAuth / service-account paste stays on the settings screen with `manage_options`. Delete the standalone PHP entrypoints from the shipped plugin.                                                                                                                      |

`wp_remote_get` for “is this URL valid?” is not a WordPress best practice. It is an open-proxy primitive. Remove it (Path 6). If a reachability check is required, allowlist `docs.google.com` hosts and require `manage_options` + `check_ajax_referer`.

### 4.5 CPT, REST, capabilities, and templates

- **`capability_type => 'post'`** (`class-portal-post-type.php:40`) means anyone who can edit posts can edit every portal. For a product that holds Drive folder IDs and applicant data, add `map_meta_cap` / custom caps (`edit_portal`, `edit_portals`, `publish_portals`) or document that this is intentional for volunteer orgs on single-site installs.
- **`show_in_rest => true`** is required for the wizard’s `wp/v2/portal` title/status writes. That also exposes the CPT to any user who can `edit_posts` via the block editor REST. Fine if caps stay `post`. Wrong if you later lock the CPT down and forget the REST route.
- **Public rewrite `portal`** is the product URL. Activation must register it before flush (4.1).
- **Theme templates:** `template_include` hard-points at plugin files (`includes/templates.php:8–27`). Allow `locate_template( 'single-portal.php' )` first so a theme can override chrome without forking the plugin.
- **Blocks vs shortcodes vs definition HTML:** Gutenberg twins in `templates.php` duplicate shortcodes. Definition portals should not load either. Path 4.

### 4.6 Admin UI that looks like WordPress — and product UI that does not

Two different surfaces, two rules:

1. **List table, settings, About** stay native wp-admin. `Portal_Post_Type::enqueue_assets` already skips the list table so Tailwind does not break sticky headers (`class-portal-post-type.php:75–78`). Keep that. Settings should use the Settings API fields you already register, with escaped output and no unauthenticated AJAX.
2. **Setup / wizard** is a product screen (`Portal_Setup_Screen`). Hiding notices and the block editor is correct for that page. Do not also leave a second edit path (meta boxes on `post.php`) that still saves.

Enqueue rules: handle names prefixed (`dragongate-portal-js`), version `PB_VERSION`, `true` in footer, `type="module"` only on that handle. Do not enqueue the wizard bundle on `edit.php`. Do not enqueue `portal.js` jQuery on definition public pages once Path 4 owns the form.

Wizard copy is hardcoded English in Svelte. Treat those strings as a message catalog (even a JS object keyed for `wp_set_script_translations`) before claiming i18n is done. PHP `esc_html__` on the public form is the start, not the whole surface.

### 4.7 What “WordPress-shaped” looks like on the two operations

```
# Author
POST /wp-json/dragongate/v1/portals/{id}/definition
  permission: current_user_can( 'edit_post', id )   # or edit_portal
  nonce:     X-WP-Nonce
  body:      { version, definition }
  mutate:    update_post_meta( id, '_portal_definition', json )

# Submit
POST /portal/{slug}   (or admin-post style front endpoint)
  nonce:     dg_definition_submit
  files:     wp_handle_upload → uploads/dragongate/{id}/
  decide:    Open_State + Definition + Validator
  mutate:    SheetStore / DriveStore / Mailer (wp_mail)
```

No `save_post` side writes. No `ABSPATH` upload roots. No `shell_exec` in the default path. No second PHPMailer.

### 4.8 Map onto the refactor paths

Do not open a “WP hygiene” project beside Paths 1–7. Fold the rule into the path that already owns the mutation:

| WP rule                                                                                                                                                 | Path   |
| ------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Nonce + open/preview on submit; `wp_handle_upload`; no `define()` request flags                                                                         | Path 1 |
| `wp_mail` adapter; no vendor PHPMailer in the live path                                                                                                 | Path 2 |
| Delete nonce-less `save_post`; keep REST `edit_post`                                                                                                    | Path 3 |
| `locate_template`; one public renderer; escape every echo                                                                                               | Path 4 |
| One admin host; prefixed enqueue; script translations                                                                                                   | Path 5 |
| One CPT register; activate+flush; `uninstall.php`; `wp_upload_dir`; kill URL AJAX; one text domain; `ABSPATH` guards; settings escape + real sanitizers | Path 6 |
| PHPCS WordPress-VIP + Plugin Check in `mise run lint` / CI                                                                                              | Path 7 |

---

## 5. Svelte construction

The admin product UI is Svelte 5 + Vite (`PORTAL-MODEL.md` locks this). Construction still applies: components present one region, `.svelte.js` modules own reusable stateful effects, plain modules own policy, and the screen entrypoint shows observe → decide → execute.

Svelte 5 runes are the language. The wizard already uses `$props`, `$state`, `$derived`, and `$effect`. That is not compliance. `WizardShell.svelte` still hides load, open-state labeling, persist, and step routing in one file. `definitionModel.js` is 1,392 lines of mixed pure policy. The Vite bundle still mounts the Svelte 4 mapper beside the wizard.

Official Svelte 5 notes that matter here (`$effect`, `$state`, `$derived`, `.svelte.js` files):

- `$derived` expressions must be free of side effects. Open/closed labels and mapping counts belong there, not in `$effect`.
- `$effect` is for the browser talking to something Svelte does not own (DOM, `matchMedia`, `history`, a fetch you cancel on teardown). Do not use it to invent product state.
- Do not update `$state` inside `$effect` as the main way to load a document. That is the “when not to use `$effect`” case. A named `loadDefinition` / `persistDefinition` operation is the owner.
- `$state` on a definition object makes a deep proxy. Persist with `$state.snapshot(definition)` (or `cloneDefinition`) so REST sees plain JSON, not a proxy.
- Shared session state belongs in a `.svelte.js` module (getters + functions, or a single object you mutate). Do not export a reassigned `$state` let. Do not keep a second `GlobalStore` that writes hidden inputs.

### 5.1 Owners today

| File                                     | Lines | Actual job                                                              | Construction problem                           |
| ---------------------------------------- | ----: | ----------------------------------------------------------------------- | ---------------------------------------------- |
| `src/wizard/definitionModel.js`          |  1392 | Templates, field tree, mapping dests, access, load plan, Unicode repair | One file, many owners. Pure, but not holdable. |
| `src/styles.css`                         |  1115 | Wizard chrome + leftover `.portal-builder`                              | Presentation bag                               |
| `src/wizard/steps/PublishStep.svelte`    |   503 | Publish form, access/fee, `canOpen`, clipboard                          | View + publish policy                          |
| `src/wizard/WizardShell.svelte`          |   374 | Load, persist, status, step host                                        | Observe + decide + execute + view              |
| `src/wizard/steps/MapStep.svelte`        |   339 | Mapping UI + dest encoding calls                                        | OK if model stays the decider                  |
| `src/wizard/steps/BuildStep.svelte`      |   327 | Tree + inspector + drag/drop                                            | Large but mostly presentation                  |
| `src/SheetManager.js`                    |   312 | Legacy mapper                                                           | Second authoring UI                            |
| `src/wizard/steps/BuildFieldTree.svelte` |   251 | Tree region                                                             | Fits a view                                    |
| `src/index.js`                           |   203 | Mounts **both** UIs                                                     | Two products, one entry                        |
| `src/wizard/definitionApi.js`            |   167 | REST port                                                               | Fits                                           |
| `src/fields/TagsField.svelte`            |   406 | Legacy tags + `createEventDispatcher`                                   | Svelte 4, dead once mapper dies                |

Wizard path uses Svelte 5 callback props (`onChange`, `onContinue`). Legacy path still uses `createEventDispatcher` and `svelte/legacy` (`SheetCard.svelte:2`). Two eras in one bundle.

### 5.2 Product expression

`WizardShell` should read:

```
load / hydrate definition     → observe
applicant status, canOpen     → decide (pure)
persist / open gate           → execute through definitionApi
then host the current step
```

Today:

- Observe is an `$effect` that fetches, times out at 8s, and on failure sets `loadError = ''` and a blank document (`WizardShell.svelte:101–146`). The user never sees a load failure.
- `planDefinitionLoad` (`definitionModel.js:34`) is the right seed decision. Keep it. The fetch branch still swallows errors.
- Decide for applicants is `deriveApplicantStatus` in the shell (`WizardShell.svelte:166–175`) using `Date.parse` and no timezone. PHP `Open_State` uses `DateTimeZone`. Two answers.
- Execute is `persist` (`WizardShell.svelte:204–244`): `Promise.all` of definition POST and `wp/v2/portal` status. If one succeeds and the other fails, the portal can be published without a saved definition, or saved without a status change. Execution invented ordering.
- `StartStep` rebuilds fetch for clone cards (`StartStep.svelte:25–43`). Steps should not each own observation.
- `PublishStep` owns `canOpen` (`fieldCount > 0 && unmapped.length === 0`). That is a server plan (Path 3). The step should display it.

`GateNav.svelte` is a clean view. `GateSeal.svelte` is unused. PORTAL-MODEL wants seal states `open | current | sealed`. `gateStateForStep` already returns those (`steps.js:9–32`). Wire the seal or delete the file.

### 5.3 Observation, effects, and snapshots

Correct `$effect` uses already in the tree:

- `history.replaceState` for `portal_id` (`WizardShell.svelte:93–99`) — sync with the browser.
- `matchMedia('(min-width: 901px)')` in `BuildStep.svelte:41–50` — sync with the viewport.

Incorrect or risky:

- Fetch-inside-`$effect` as the only load path, with a silent timeout fallback.
- Setting `definition` / `loadState` from that effect on both success and failure (state updates inside effects).
- Sending the `$state` proxy to `JSON.stringify` in `saveDefinition` without a snapshot.
- `busyId` flip in `StartStep` template actions (`useComposer` sets busy then immediately clears) — not a real working state.

Target session module (`definitionSession.svelte.js` or equivalent):

```
observe(host): loadPlan | fetch → { definition, status, publicUrl, error }
decidePublish(observation): Draft | Closed | Open | Blocked(reasons)
execute(plan, snapshot): saveDefinition + updatePortalPost in an explicit order
```

The shell calls those three. Steps receive data and callbacks. They do not fetch.

### 5.4 Domain modeling in the client

- Field `type` is still `string` (`definitionModel.js:9`). PHP `FIELD_TYPES` is closed. JS catalog now includes more types (`definitionModel.js:362`) but is not the same set as PHP and is not exhaustive in the type system.
- No `tsconfig`. No `svelte-check`. Path 8 is still later, but `definitionModel` and the session module should move to `.svelte.ts` / `.ts` first — that is where invalid field types hide.
- `InsertTarget` is already a closed union (`definitionModel.js:21`). Keep that shape; it is the right model for the tree.
- Mapping dest strings (`toggleSheetDest`, `encodeDestParts`) are a protocol. They belong in one mapping module, not in `MapStep` and not mixed with `composerPrizeTemplate`.

Split `definitionModel.js` by concept, not by function count:

```text
src/wizard/model/document.js     blank, normalizeLoaded, planDefinitionLoad, clone
src/wizard/model/fields.js       catalog, newField, insert/move/remove, branch options
src/wizard/model/mapping.js      dest encode/toggle, sheets, drive parse
src/wizard/model/templates.js    composer prize, call for scores
src/wizard/model/access.js       defaultAccess, normalizeAccess
src/wizard/model/openLabel.js    Draft | Closed | Open — same rules as PHP
```

A barrel `definitionModel.js` may re-export. The 1,392-line file must not remain the owner.

### 5.5 Two Svelte UIs

`src/index.js:191–193` still calls `initWizard()` and `initPortalBuilder()`. Hidden `[data-segmented-control]` still exists for CSS-hidden meta boxes.

Legacy components (`PortalBuilder.svelte`, `SheetManager.js`, `GlobalStore.js`, `fields/*`, `Combobox.svelte`, `ConfirmModal.svelte`) are Svelte 4 event dispatchers. Do not migrate them to runes. Delete them when Path 3 stops writing those meta keys (Path 5).

Until then, treat that tree as quarantined. New work does not import it.

### 5.6 Views: props, states, accessibility

Handbook-shaped Svelte (and construction 07, applied to Svelte instead of Vue):

- One visual region per component. `BuildFieldTree` and `BuildFieldEditor` already split the build canvas — that is the pattern. `PublishStep` should split access/fee from the deadline/open actions.
- Props and callbacks explicit. Wizard already does this. Keep it. Do not introduce a context bag that every step writes.
- Loading, empty, error, disabled must be visible. Load failure is not visible today. Save error is (`saveError` on Publish). Empty build has a status region (`BuildStep.svelte:220–224`). Good.
- Drag-and-drop field placement (`BuildStep.svelte:156–160`, `:234–243`) needs a keyboard path (the click-to-add palette is that path — keep it, and do not make drag the only way to nest).
- `GateNav` buttons should expose the subtitle to assistive tech, not only `title={sub}` (`GateNav.svelte:29`).
- Decorative logo in the header uses `alt=""` (`WizardShell.svelte:284`); the large mark uses `alt="DragonGate"` (`:329`). One is enough. Prefer empty alt on the repeated mark.

CSS: tokens live in `src/tokens.css` (96 lines). `src/styles.css` is 1,115 lines and still carries leftover `.portal-builder` rules. New wizard regions get token variables. Do not add Tailwind gray utilities to the product wizard (those belong to the dying mapper).

### 5.7 Map onto the refactor paths

| Svelte rule                                                           | Path                                                                                              |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Session module: named load/persist; snapshot on save; ordered execute | Path 3 (save) and Path 5 (shell)                                                                  |
| Delete mapper mount and Svelte 4 tree                                 | Path 5                                                                                            |
| Split `definitionModel.js` by concept                                 | Path 5 (authoring model). Mapping helpers may move with Path 3 if the server takes dest encoding. |
| `canOpen` / open label from server or shared pure module              | Path 3 + Path 5                                                                                   |
| `svelte-check` + (later) TypeScript on model/session                  | Path 7 / Path 8                                                                                   |
| Token-only CSS; delete `.portal-builder` leftovers                    | Path 5                                                                                            |

Do not start a “convert everything to runes” pass on `SheetManager`. That preserves the second owner.

---

## 6. What is already correct — do not throw it away

The definition module is the paved road. A refactor that starts over would discard working owners.

1. **One document** — `_portal_definition` JSON (`Portal_Definition::META_KEY`).
2. **Pure open check** — `is_open_from_publish` + clock injection.
3. **Ports sketched** — `Portal_Sheet_Store`, `Portal_Drive_Store`, `Portal_Mailer`, `Portal_Files`.
4. **Validator split** — walk in `Portal_Submission_Validator`, per-field in `Field_Rules`.
5. **Wizard model is I/O-free** — `definitionModel.js` performs no fetch/DOM. It is too large (1,392 lines) but the purity is the seed for the split in §5.4.
6. **REST permission** — `current_user_can('edit_post', $id)` on definition GET/PUT. This is the WordPress mutation pattern to copy.
7. **Setup screen** — dedicated admin surface (`Portal_Setup_Screen`) is the right authoring host.
8. **Characterization fixtures** — Herbolzheimer definition + submission + expected sheet row.
9. **List table left native** — wizard CSS is not loaded on `edit.php` (`class-portal-post-type.php:75–78`).
10. **Definition POST nonce** — `wp_nonce_field` + `wp_verify_nonce` on the public form (`pb_formstart.php:34`, `portal-builder.php:247`).
11. **Wizard is Svelte 5** — `$props` / `$state` / `$derived` / callback props. `planDefinitionLoad` and `InsertTarget` are already closed decisions. `definitionApi.js` is a thin port. `BuildFieldTree` / `BuildFieldEditor` already split one region each. Do not regress to stores or `createEventDispatcher` on this path.

The missing move: make these the only production path, then delete the rest.

---

## 7. Proposed `LAYOUT.md`

Write this next to `portal-builder.php` before moving files. Holdable concepts, not one file per old method.

```text
# Author a portal
src/wizard/WizardShell.svelte: host steps; show load/save/status
src/wizard/definitionSession.svelte.js: observe + persist (snapshot, ordered writes)
src/wizard/model/document.js: blank, normalize, load plan, clone
src/wizard/model/fields.js: catalog, tree insert/move/remove, branch options
src/wizard/model/mapping.js: dest encode/toggle, sheet/drive parse
src/wizard/model/templates.js: composer prize, call for scores
src/wizard/model/access.js: audience and fee-waiver values
src/wizard/model/openLabel.js: Draft | Closed | Open (same rules as PHP)
src/wizard/definitionApi.js: port to definition REST and post REST
src/wizard/steps.js: step ids + gateState
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
includes/Submission/class-portal-mailer.php: mail capture / wp_mail adapter later
includes/adapters/class-portal-google-store.php: live Google behind the same ports
includes/adapters/class-portal-uploads.php: wp_handle_upload + wp_upload_dir

# Composition
portal-builder.php: boot; register adapters; route HTTP to Submit / Author
includes/class-portal-setup-screen.php: admin host for the wizard
includes/class-portal-post-type.php: CPT + list only; register once on init
includes/class-portal-settings.php: Google credentials + receipt copy (no URL fetch)
uninstall.php: delete plugin options only; leave portal posts
```

**Delete after callers move** (not “migrate later”):

- `includes/class-portal-submission.php` live path
- Hidden meta boxes + `save_post` field loop for mapping/deadline
- `src/PortalBuilder.svelte`, `SheetManager.js`, `GlobalStore.js`, `fields/TagsField.svelte`, `services/ContentParser.js` once mapping lives only in the wizard
- Shortcode chrome on definition portals (`single-portal.php` agreements/notes/title when a definition exists)
- `pb_reviewable_content_filter` and two-nonce review
- `is_application_deadline_passed` wrapper once callers use `Open_State`
- Unused `GateSeal.svelte` or wire it as the only gate UI
- `wp_ajax_validate_url` in meta and settings
- `gsuite-filestore/vendor` and `gsuite-filestore/get-credentials/` from the shipped artifact
- Bundled PHPMailer once the Mailer port uses `wp_mail`
- `'portal-builder'` text domain (replace with `dragongate-portals`)

A temporary seam is allowed: **legacy shortcode posts without `_portal_definition` keep the old submit class**, named and listed. Two owners for definition portals are not.

---

## 8. Refactor sequence

Each item is one complete domain path. Do not extract files inside a path and leave both owners. Protect behavior with a test first when the path already has a public effect.

### Path 0 — Ledger (no behavior change)

Write `LAYOUT.md` from §7. Stop adding files that are not on it.

Done when: every production path in this document maps to one line in the ledger.

### Path 1 — Submit uses one owner

**Operation:** submit an application.

```
submit_application(request, deps):
    observation = observe_submit(request)   # post, files, definition, open-state, preview, version
    plan        = decide_submit(observation) # RejectClosed | RejectPreview | RejectInvalid | Accept
    return execute_submit(observation.basis, plan)
```

**Decision owner:** new pure `decide_submit` next to the validator (closed plan, not a bool).\
**Mutation authority:** `Portal_Submission_Pipeline::process` only.\
**Basis:** definition `version` + portal id + `is_open` at observe time; re-check open + version immediately before the first write.

Work:

1. Red tests: submit when closed; submit when preview; definition version changed between observe and write; missing `DG_TEST_MODE` is not a product error once live adapters exist.
2. `handle_submissions` becomes an adapter: parse POST, verify nonce, call `submit_application`, map the plan to HTML. No Drive/Sheet/mail in the plugin file.
3. Call `Open_State` on this path. Preview may render the form; it must not `Accept`.
4. Route `ready_to_submit_nonce` through the same function when a definition exists. Remove `pb_try_definition_submission` as a special case.
5. Split `process_request` HTML helpers out of the pipeline file so the pipeline file is the executor only.
6. Push file-magic reads behind a `Uploads` port. Accept files with `wp_handle_upload` + `wp_check_filetype_and_ext` into `wp_upload_dir()['basedir'] / dragongate / {portal_id}`. Field rules stay pure.
7. Stop using `define()` for submit outcome. Return a request result the public renderer reads.

Done when: grep shows no `new Portal_Submission` for posts that have `_portal_definition`; closed/preview probes fail closed; no new files land under `ABSPATH/tmp-uploads` on the definition path; pipeline unit tests still green.

### Path 2 — Live adapters behind the existing ports

**Operation:** same submit. Leaf adapter change once Path 1 exists.

`Portal_Sheet_Store` / `Drive_Store` / `Mailer` already name WHAT. Implement Google as a sibling adapter that satisfies the same methods. The live Mailer adapter calls `wp_mail` so site SMTP plugins keep working. Do not construct `new PHPMailer` in product code (`class-portal-submission.php:148`) and do not ship a second PHPMailer tree.

`Portal_Submission` and `Zysys_FileStore` become the Google adapter’s private implementation, then disappear from the domain. Composer lives at the plugin root; delete `gsuite-filestore/vendor` and the standalone `get-credentials/*.php` entrypoints from the shipped plugin.

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
4. Stop registering hidden mapping/deadline meta boxes. Stop `save_post` writes for those keys on definition portals. A `save_post` writer without `check_admin_referer` must not remain. Import remains a one-way read (`Open_State::legacy_publish_config`).
5. Delete JS `normalizeLoaded` policy that disagrees with `Portal_Definition::validate`. The client may default a blank document; it may not accept a shape the server would reject.

Done when: a stale wizard tab cannot overwrite a newer definition; opening the gate without mapping fails on the server; `_portal_record_keeping` is no longer written from admin; grep shows no `save_post` handler writing definition or mapping keys.

### Path 4 — Public render has one chrome owner

**Operation:** show the applicant form, closed page, or receipt.

`Portal_Public_Render` (rename toward `PublicForm` if the ledger wants it) renders the whole applicant page for definition portals: title, form, nonce, agreements from the definition, success, closed, preview. `single-portal.php` becomes a theme shell: `the_content` only. Prefer `locate_template( 'single-portal.php' )` before the plugin file so a theme can override chrome.

Work:

1. Move formstart nonce into the definition renderer (or one `PublicForm::render` that includes it).
2. Stop emitting agreements/upload-notes/title shortcodes when a definition exists.
3. One closed message. Delete `PB_APPLICATION_DEADLINE_PASSED` as a cross-file bus; pass a typed view model.
4. Tokenize closed/preview/success with the same tokens as the wizard (`src/tokens.css`).
5. Escape every echo (`esc_html`, `esc_attr`, `esc_url`). No raw `$_POST` in templates. Receipt PII leaves the query string.

Done when: a closed URL shows one sentence; a definition portal HTML source has no `[portal-application-*]` output; public e2e still pass; a theme-copied `single-portal.php` is honored.

### Path 5 — Admin surface is the wizard only

**Operation:** author a portal.

Work:

1. Keep `Portal_Setup_Screen` as the host. Remove wizard mount from the block-editor meta box path once redirects are the only edit link (already partly true).
2. Unmount path: stop `initPortalBuilder()` in production (`src/index.js:191–193`).
3. Delete the Svelte 4 mapper tree after Path 3 (`SheetManager`, `GlobalStore`, `TagsField`, `ContentParser`, `createEventDispatcher` components). Do not convert that tree to runes.
4. Extract `definitionSession.svelte.js`: named `observe` / `persist`. Shell only hosts. Steps do not fetch (move `listPortalTemplates` out of `StartStep`).
5. Persist with `$state.snapshot` (or `cloneDefinition`). Save definition first, then post status — not `Promise.all`. Surface load errors; delete the 8s silent-blank timeout.
6. Split `definitionModel.js` per §5.4. Lift `deriveApplicantStatus` into `openLabel.js` (or the server). `PublishStep` displays `canOpen`; it does not invent it.
7. Wire `GateSeal` to `gateStateForStep` or delete the unused component.
8. Prefix enqueue handles; `wp_set_script_translations` (or a message map) for wizard copy. Create-draft via POST + nonce (`admin-post.php`), not a GET.
9. Delete leftover `.portal-builder` CSS from `src/styles.css`. Keep `matchMedia` / `history` effects — those are the correct `$effect` uses.

Done when: `src/index.js` mounts only `[data-portal-wizard]`; `definitionModel.js` is a barrel or is gone; no wizard file both fetches and decides publish; load failure is visible; adding a portal requires a nonce.

### Path 6 — Bootstrap and leftover bags

After Paths 1–5. This path is the WordPress plugin shell.

- `portal-builder.php` only defines constants, loads the autoloader, and calls a composition root. Review HTML and crypto move with their owners (review dies with Path 1; encrypt dies with legacy two-nonce).
- Files define classes. The root registers hooks. No `::init()` at file bottom.
- `defined( 'ABSPATH' ) || exit;` on every PHP file.
- `templates.php` Gutenberg clones of shortcodes go away with Path 4 or become thin wrappers around `PublicForm`.
- `Portal_Builder::init` must stop constructing CPT/meta a second time (`class-portal-builder.php:7–18` vs `portal-builder.php:97–103`). `register_post_type( 'portal' )` runs twice today.
- Activation: register the CPT, then `flush_rewrite_rules()`. Deactivation: flush. Add `uninstall.php` that deletes plugin options only.
- One text domain `dragongate-portals`. Call `load_plugin_textdomain`. Replace `'portal-builder'` in settings.
- `class-portal-settings.php`: keep credentials and receipt copy; remove `validate_url_callback`; add real `sanitize_callback`s; escape every field echo.
- Duplicate: `check_ajax_referer` + `current_user_can( 'edit_post', $id )`.
- Uploads: migrate remaining `PB_*_UPLOADS_DIR` constants to `wp_upload_dir()`. Schedule temp cleanup with `wp_schedule_event` if anything still writes temps.
- Name `Zysys_FileStore` out of domain language. The port is `DriveStore` / `SheetStore`.

### Path 7 — Repository gate (later)

Only after the product paths above. Add `mise.toml` tasks `setup / lint / test / scan / ci` that wrap existing npm + phpcs. `lint` must run PHPCS (`WordPress-Core` / VIP sniffs already in `composer.json`), `svelte-check` (or `sv check`) on `src/wizard`, and, when available, Plugin Check. Replace `HOOKS.md`’s ad-hoc git hook with Lefthook calling those tasks. Do not invent a second CI story while two submit owners remain.

### Path 8 — TypeScript for new domain JS (later)

`model/*`, `definitionSession.svelte.js`, and `openLabel` become `.ts` / `.svelte.ts` with a closed `FieldType` union matching PHP `FIELD_TYPES`. Components may stay JSDoc until the model is typed. Do not convert `SheetManager` first.

---

## 9. What not to do

- Do not split `portal-builder.php` or `WizardShell.svelte` by line count and keep the same mixed purposes.
- Do not add another facade around `Portal_Submission` and call the job done. Wrappers that preserve the old owner fail the migration contract.
- Do not keep the hidden meta boxes “for compatibility” once definition portals no longer read those keys at runtime. Import can read them; authoring must not write them.
- Do not implement live Google inside `process()` with `new Google_Client`. Extend the existing stores.
- Do not move publish policy into more Svelte. The server already owns `Portal_Definition`.
- Do not add a “WordPress hygiene” branch that retouches strings and prefixes while two submit owners remain. Fold Handbook rules into the path that owns the mutation (§4.8).
- Do not run a “convert the mapper to Svelte 5 runes” pass. That preserves the second authoring UI.
- Do not split `definitionModel.js` by line count into `helpers2.js`. Split by the concepts in §5.4.
- Do not put fetch in more `$effect` blocks. Load and persist are named session operations.
- Do not keep `ABSPATH/tmp-uploads` “until Drive is live.” New definition submits use `wp_upload_dir()`.
- Do not add another `wp_ajax_` probe. URL validation via `wp_remote_get` is removed, not hardened halfway.
- Do not ship `gsuite-filestore/vendor` or standalone OAuth PHP in the plugin zip.
- Do not write OpenAPI until Path 3 freezes the definition document and error shape. Then the REST route is the first contract to publish.
- Do not treat `e2e/pipeline/submit-herbolzheimer.spec.ts` as proof of the HTTP submit path. It is the CLI harness again.

---

## 10. Suggested first commit series

Small, reviewable, each one a complete path:

1. `docs: add LAYOUT.md` from §7 (this file stays as the assessment).
2. `test: closed and preview submits are rejected` (red against Path 1).
3. `feat: submit_application owns definition POST` + green those tests.
4. `feat: definition uploads use wp_upload_dir` (no new `ABSPATH/tmp-uploads` writes).
5. `feat: definition posts no longer construct Portal_Submission`.
6. `feat: definition save compares version`.
7. `feat: publish plan enforced on REST`.
8. `refactor: public form is one renderer for definition portals`.
9. `refactor: remove hidden mapping meta boxes` (kills nonce-less `save_post`).
10. `feat: wp_mail + Google adapters implement existing stores`.
11. `fix: register CPT once; activate then flush; uninstall options only`.
12. `fix: remove validate_url AJAX; one text domain; escape settings`.
13. `refactor: definitionSession owns load/persist; snapshot on save`.
14. `refactor: split definitionModel by document/fields/mapping/templates`.
15. `chore: delete dead mapper, review filter, gsuite-filestore/vendor from the zip`.

Do not start at 10 or at a runes migration of `SheetManager`. Live Google on a forked policy, and a Svelte 5 coat on the mapper, both duplicate the current mess.

---

## 11. Evidence limits (NOT PROVEN)

- Full Playwright suite and `npm run ci` were not executed for this assessment. Claims about what tests cover come from reading the specs and harnesses, not from a green run in this pass.
- Live Google write against a real spreadsheet was not observed.
- Whether any production site still depends on shortcode-only portals (no `_portal_definition`) is unknown. The seam in Path 1 assumes some still exist.
- Woo/membership `freeForMembers` and fee collection are modeled in PORTAL-MODEL and stubbed in `Portal_Submission`; no working payment path was found.
- `STATUS_RECEIVED` / `STATUS_FAILED` have no writer. Recovery after a Drive-success / Sheet-failure is unproven.
- `e2e/admin/definition-api.spec.ts` mutates whichever portal is first in the admin list. Isolation of that contract is not proven.
- Plugin Check / `wp plugin check` was not run. PHPCS WordPress-VIP is a Composer dep; it is not in CI. WP findings above are from reading production files, not from a Plugin Check report.
- `svelte-check` / `svelte-autofixer` were not run against the wizard tree in this pass. Svelte findings are from reading `src/wizard` plus Svelte 5 rune docs (`$state`, `$derived`, `$effect`, `.svelte.js`).

---

## 12. Bottom line

The product already named the right owners in `PORTAL-MODEL.md` and sketched them in `includes/Definition/` and `includes/Submission/`. Production still runs the 2024 shortcode plugin next to that sketch.

The smallest complete correction is not a new framework and not a file-by-file tidy. It is:

1. One submit operation that observes, decides, and executes.
2. One definition writer with a version basis.
3. One public form owner.
4. Live Google behind the stores that tests already use; mail through `wp_mail`; files through `wp_upload_dir`.
5. Delete the second owner of each.

WordPress practices (nonce + cap at the mutation, one text domain, one CPT register, no SSRF AJAX, no docroot uploads) ride those five paths. Svelte practices (session module, snapshot on save, split model, one mounted UI, no fetch-in-`$effect` as the owner) ride Path 3 and Path 5. Neither is a sixth project.

Until those five land, the construction verdict stays **FAIL**.
