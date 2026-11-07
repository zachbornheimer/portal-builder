# DragonGate Portals — Full UX + Maintainability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make DragonGate Portals effortless for ISJAC operators and applicants — one honest closed/public form surface, one wizard-only admin path, tokenized brand, and maintainable definition-first code — while preserving the existing definition REST + submit pipeline.

**Architecture:** Keep WordPress CPT + `_portal_definition` as the aggregate. Public path becomes **definition-owned form chrome** (closed message, notes, agreements, submit inside one tree when a definition exists). Admin path becomes **wizard-only** (no block editor): Svelte `WizardShell` loads/saves definition via existing `POST /wp-json/dragongate/v1/portals/{id}/definition`. Legacy shortcode portals remain import-compatible but are not the authoring path.

**Tech Stack:** PHP (WordPress plugin), Svelte 5 + Vite (admin), Playwright e2e against LocalWP (`http://localhost:10033`), node:test unit harness, design tokens in `src/tokens.css` / `docs/design/TOKENS.md`.

## Global Constraints

- **Product:** Acceptd alternative for ISJAC application portals (score/recording + identity → Sheets/Drive).
- **Contracts (read before coding):** `docs/design/UX-NEXT-STEPS.md`, `docs/design/PORTAL-MODEL.md`, `docs/design/TOKENS.md`.
- **Locked product decisions (2026-08-12):**
  1. Editor model = **Option A wizard-only** (remove CPT `editor` support).
  2. Framework = **Svelte 5 + Vite** (locked). Product UI stays Svelte; do not migrate to Alpine, React, Vue, or plain jQuery.
  3. Admin menu icon = **white monochrome** (WP standard).
  4. Preview submit = **view-only v1** (banner stays; submit disabled or non-posting in preview). Labeled test-submit is a later optional flag, out of this plan’s ship gate.
- **Out of scope (do not expand):** live Sheets/Drive OAuth reliability, importer rewrite, submission inbox, dark mode, i18n, theme header/footer rewrite, marketplace templates.
- **Code standards:** WHAT-not-HOW names; facades at system edges already in pipeline — do not call raw `os`/`time` from domain; no magic hex in new CSS (tokens only); files ~250 lines; functions ~50 lines; no `//nolint` bypasses.
- **Test law:** bug fixes are RED-then-GREEN; every UX slice ends with an automated assertion or re-shot under `docs/design/tutorial/`.
- **LocalWP:** `tests/config/env.local.json` — plugin symlink must resolve to this repo. Auto-login: `http://localhost:10033/wp-admin/?localwp_auto_login=9`.
- **Verify commands (repo norms):**
  - Offline: `npm run ci:offline` (mocks + unit)
  - Env: `npm run test:env`
  - Public e2e: `npx playwright test e2e/public`
  - Admin e2e: `npx playwright test e2e/admin`
  - Capture: `node scripts/capture-tutorial-shots.mjs` (after visual slices)
- **Commit style:** conventional, one logical change per commit, signed if hooks require.
- **User-facing strings:** prefer PHP/`__()` constants or named Svelte strings — no new inline grey boxes or raw hex.

## Product outcome (done when all phases pass)

| Who | Outcome |
|-----|---------|
| Applicant (closed) | Exactly one closed reason message; tokenized centered layout; no form/agreements/notes |
| Applicant (open) | Single paper form column; side labels on desktop; styled file controls; note above files; tokenized agreements + “Submit application” |
| Editor (preview) | Ember banner + full form UI; **cannot** create production submission |
| Operator (admin) | No empty block canvas; white Portals icon; full-width wizard; can configure definition via wizard steps without legacy meta boxes for happy path |
| Maintainers | Definition path owns public chrome; shortcodes silent when closed/definition; tests guard regressions |

## File map (ownership)

| Area | Primary files |
|------|----------------|
| Closed/preview gate | `includes/Definition/class-portal-public-render.php`, `class-portal-open-state.php` |
| Form HTML | `includes/Definition/class-portal-definition-renderer.php` |
| Template shell | `includes/templates/single-portal.php` |
| Shortcodes | `includes/shortcodes/portal-application-*.php`, `pb_form*.php` |
| Public CSS | `assets/definition-form.css` (canonical), `assets/portal.css` (legacy only; stop fighting definition) |
| Tokens | `src/tokens.css`, `docs/design/TOKENS.md` |
| CPT / icon | `includes/class-portal-post-type.php` |
| Wizard mount / admin CSS | `includes/class-portal-meta.php`, `assets/admin.css` (or new `assets/portal-admin.css`) |
| Wizard UI | `src/wizard/*`, `src/index.js` |
| Definition REST | `includes/class-portal-definition-rest.php` |
| Public JS (file UI) | new small module under `assets/` or enqueue from public-render |
| Tests | `e2e/public/*`, `e2e/admin/*`, `tests/unit/*` |
| Evidence | `docs/design/tutorial/`, `scripts/capture-tutorial-shots.mjs` |

## Execution DAG (phases)

```
Phase 0  Docs + harness hygiene          ──► independent
Phase 1  UX-01 closed message ownership  ──► first ship
Phase 2  UX-02 closed/preview visuals    ──► after Phase 1
Phase 3  UX-07 white menu icon           ──► parallel after Phase 0
Phase 4  UX-03/05/11/04 public form      ──► after Phase 1 (layout before chrome merge)
Phase 5  UX-06/12 definition owns chrome ──► after Phase 4
Phase 6  UX-08/09 wizard-only admin      ──► parallel after Phase 0
Phase 7  Preview view-only enforce       ──► after Phase 1–2
Phase 8  UX-10 wizard step content       ──► after Phase 6 (large)
Phase 9  Visual capture + CI green       ──► after Phases 1–8
```

Parallelizable after Phase 0: **Phase 3** and **Phase 6** with public phases **1–2, 4–5**.

---

### Task 0: Commit design ground truth + plan

**Files:**
- Add: `docs/design/UX-NEXT-STEPS.md` (already untracked)
- Add: `docs/design/tutorial/**`, `scripts/capture-tutorial-shots.mjs`
- Add: `docs/design/plans/2026-08-12-ux-full-implementation.md` (copy of this plan)

**Interfaces:** none

- [ ] **Step 1:** Copy this plan into the repo at `docs/design/plans/2026-08-12-ux-full-implementation.md` so workers do not depend on session state.
- [ ] **Step 2:** Commit docs only (no behavior change).

```bash
git add docs/design/UX-NEXT-STEPS.md docs/design/tutorial docs/design/plans scripts/capture-tutorial-shots.mjs
git commit -m "docs(design): UX next-steps, tutorial captures, implementation plan"
```

- [ ] **Step 3:** Confirm LocalWP env.

```bash
npm run test:env
```

Expected: exit 0; plugin path resolves to this repo.

---

### Task 1: UX-01 — Single closed message (P0)

**Files:**
- Modify: `includes/shortcodes/portal-application-agreements.php` (L7–9)
- Modify: `includes/shortcodes/portal-application-upload-notes.php` (L6–8)
- Modify: `e2e/public/closed-preview.spec.ts` (assert single message + reason)
- Optional: `tests/unit/` PHP harness for closed HTML if easy; e2e is primary

**Interfaces:**
- Consumes: `PB_APPLICATION_DEADLINE_PASSED` already defined when closed
- Produces: shortcodes return `''` when closed (not deadline copy)

**Bug (live):** `single-portal.php` always runs agreements + upload-notes after `the_content`. Both shortcodes print “The application deadline has passed.” whenever the closed flag is set, even for `forceClosed`.

- [ ] **Step 1: Extend e2e RED**

In `e2e/public/closed-preview.spec.ts`, after anon closed assertions, add:

```ts
// Force-closed definition must not claim deadline (seed with forceClosed only, future deadline)
const forceOnly = {
  ...baseDefinition,
  publish: {
    deadline: '2099-01-01T00:00:00',
    timezone: 'America/New_York',
    applicationFee: null,
    forceClosed: true,
  },
};
// putDefinition(...forceOnly)
// anon visit:
await expect(anonPage.locator('[data-dg-portal-state="closed"]')).toBeVisible();
await expect(anonPage.locator('[data-dg-closed-reason]')).toHaveAttribute('data-dg-closed-reason', 'force');
const bodyText = await anonPage.locator('body').innerText();
const deadlineHits = (bodyText.match(/application deadline has passed/gi) || []).length;
expect(deadlineHits, 'force-closed must not say deadline passed').toBe(0);
const closedHits = (bodyText.match(/This portal is closed\./g) || []).length;
expect(closedHits).toBe(1);
// agreements / notes must not re-emit
await expect(anonPage.locator('body')).not.toContainText('denotes Required Field');
```

Also strengthen existing closed seed: count of deadline OR closed sentences for force+past should be **1** total status line from `.dg-portal-closed` only.

- [ ] **Step 2: Run e2e — expect RED**

```bash
npx playwright test e2e/public/closed-preview.spec.ts
```

- [ ] **Step 3: Fix shortcodes**

`portal-application-agreements.php`:

```php
if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
	return '';
}
```

`portal-application-upload-notes.php`:

```php
if ( defined( 'PB_APPLICATION_DEADLINE_PASSED' ) && PB_APPLICATION_DEADLINE_PASSED ) {
	return '';
}
```

Do **not** change `render_closed_message` reason mapping in this task (already correct).

- [ ] **Step 4: Run e2e — expect GREEN**

```bash
npx playwright test e2e/public/closed-preview.spec.ts
```

- [ ] **Step 5: Commit**

```bash
git commit -m "fix(public): silence shortcodes when closed (single closed message)"
```

---

### Task 2: UX-02 — Closed + preview visual system

**Files:**
- Modify: `includes/Definition/class-portal-public-render.php` (`render_closed_message`, `render_preview_banner`)
- Modify: `assets/definition-form.css`
- Ensure enqueue runs for closed definition portals (today enqueue requires definition present — keep that; closed still has definition)

**Interfaces:**
- Produces: enriched closed markup; styled `.dg-portal-closed`, `.dg-preview-banner`

- [ ] **Step 1: Enrich closed markup** (single hierarchy)

```php
// render_closed_message — structure only; one message still from closed_reason
return sprintf(
  '<div class="dg-portal-closed" data-dg-portal-state="closed" data-dg-closed-reason="%1$s">
    <p class="dg-portal-closed__eyebrow">%2$s</p>
    <h2 class="dg-portal-closed__title">%3$s</h2>
    <p class="dg-portal-closed__status">%4$s</p>
    %5$s
  </div>',
  esc_attr( (string) $reason ),
  esc_html__( 'Application portal', 'dragongate-portals' ),
  esc_html( get_the_title( $post_id ) ),
  esc_html( $message ),
  $detail_html // optional deadline datetime only when reason === deadline
);
```

- [ ] **Step 2: CSS in `definition-form.css`**

```css
.dg-portal-closed {
  max-width: 36rem;
  margin-inline: auto;
  margin-block: var(--s-6, 2rem);
  padding: var(--s-5);
  background: var(--paper-50);
  border: 1px solid var(--rule);
  border-radius: var(--r-3);
  box-shadow: var(--shadow-card);
  text-align: center;
}
.dg-portal-closed__eyebrow { color: var(--ink-400); font-size: var(--t-small); }
.dg-portal-closed__title { font-family: var(--font-display); color: var(--ink); }
.dg-portal-closed__status { color: var(--ink-600); }

.dg-preview-banner {
  width: 100%;
  max-width: 40rem;
  margin-inline: auto;
  margin-block-end: var(--s-4);
  padding: var(--s-3) var(--s-4);
  background: var(--ember-100);
  border: 1px solid var(--rule-strong);
  border-left: 4px solid var(--ember);
  border-radius: var(--r-2);
  color: var(--ink);
  font-weight: 600;
}
```

- [ ] **Step 3: Enqueue tokens/CSS when closed** — if definition exists, current enqueue is enough; if not, still enqueue tokens for closed shell when singular portal.

- [ ] **Step 4: E2e asserts** classes present / banner styled marker (`data-dg-preview`); visual capture optional via capture script.

```bash
npx playwright test e2e/public/closed-preview.spec.ts
```

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(public): tokenized closed and preview surfaces"
```

---

### Task 3: UX-07 — White monochrome menu icon

**Files:**
- Modify: `includes/class-portal-post-type.php` `get_menu_icon_data_uri()`
- Optional source: simplify `assets/icon.svg` for admin use (do not break public dragon art)

**Done when:** Portals icon readable on WP dark admin chrome (white/near-white fills; no black gradients).

- [ ] **Step 1:** Replace gradient black fills with `#ffffff` / `currentColor`-equivalent solid paths suitable for WP menu (single-color silhouette + check). Keep viewBox; drop multi-stop black gradients.
- [ ] **Step 2:** Manual check LocalWP sidebar **or** Playwright screenshot of `/wp-admin/edit.php?post_type=portal` (admin session).
- [ ] **Step 3: Commit**

```bash
git commit -m "fix(admin): white monochrome Portals menu icon"
```

---

### Task 4: UX-03 + UX-11 — Form layout v2 + required/help structure

**Files:**
- Modify: `includes/Definition/class-portal-definition-renderer.php` (`label_text`, `help_html`, field wrappers)
- Modify: `assets/definition-form.css`
- Modify: `assets/portal.css` only to **scope legacy** rules so they do not force label-above on `.dg-form`
- Modify: `tests/unit/definition-render.test.mjs` (or PHP harness `php-render-definition.php`)

**Interfaces:**
- Produces: desktop side-label rows; `.dg-required`; `.dg-field__help`; centered column `max-width: 40rem`

- [ ] **Step 1: RED unit** — rendered HTML for `short_text` required field contains `dg-required` and does **not** contain bare `Title*` glued without span; help is outside label.

- [ ] **Step 2: Renderer changes**

```php
// label_text: do not append raw *; caller wraps marker
private static function label_text( $field ) {
  return isset( $field['label'] ) ? (string) $field['label'] : (string) $field['id'];
}
private static function required_marker( $field ) {
  if ( empty( $field['required'] ) ) return '';
  return ' <span class="dg-required" aria-hidden="true">*</span>';
}
private static function help_html( $field ) {
  if ( empty( $field['help'] ) ) return '';
  return '<p class="dg-field__help caption">' . esc_html( (string) $field['help'] ) . '</p>';
}
// text/file rows: structure
// <div class="dg-field dg-field--row dg-field--{type}">
//   <div class="dg-field__meta"><label>...</label>{help}</div>
//   <div class="dg-field__control">input</div>
// </div>
```

- [ ] **Step 3: CSS grid for desktop**

```css
.dg-form.portal-definition-form {
  max-width: 40rem;
  margin-inline: auto;
}
.dg-form .dg-field--row {
  display: grid;
  grid-template-columns: minmax(8rem, 12rem) 1fr;
  gap: var(--s-3) var(--s-4);
  align-items: start;
  margin-block-end: var(--s-3);
}
@media (max-width: 640px) {
  .dg-form .dg-field--row { grid-template-columns: 1fr; }
}
.dg-form .dg-required { color: var(--ember); }
/* Do not use #ccc on definition form */
```

- [ ] **Step 4:** Ensure `.portal-group .form-grid` legacy rules do not apply inside `.dg-form` (raise specificity or reset).

- [ ] **Step 5:**

```bash
npm run test:unit
npx playwright test e2e/public/render-herbolzheimer.spec.ts
```

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(public): definition form side labels, required markers, help"
```

---

### Task 5: UX-05 — Note as `dg-alert` above files

**Files:**
- Modify: `includes/shortcodes/portal-application-upload-notes.php` **or** move note into definition renderer (prefer Task 6 ownership; this task can restyle shortcode markup first)
- Modify: `assets/definition-form.css`
- Modify: `includes/templates/single-portal.php` order **if** still using shortcodes: notes **before** `the_content` is wrong for open forms — notes should be **inside** form above file fields (Task 6). Interim: restyle only; placement fixed in Task 6.

**Copy rules:**
- Generic size/time note (32MB)
- Conversion links as secondary list
- Drop “Call For Scores, all fields required…” on prize-only portals (generic: “Required fields are marked with *”)

- [ ] **Step 1:** Markup

```html
<div class="dg-alert dg-alert--info" role="note">
  <p class="dg-alert__title">Note</p>
  <p class="dg-alert__body">Large files may take several minutes. Keep uploads under 32 MB.</p>
  <ul class="dg-alert__links">...</ul>
</div>
```

- [ ] **Step 2:** CSS using `--ember-100` / `--ink` / left rule — never `rgba(155,150,150)`.
- [ ] **Step 3: Commit**

```bash
git commit -m "feat(public): tokenized upload note alert"
```

---

### Task 6: UX-04 — File ingestion UI

**Files:**
- Modify: `class-portal-definition-renderer.php` `render_file_input`
- Add: small public JS `assets/definition-form.js` (filename display) enqueued by `Portal_Public_Render::enqueue_assets`
- Modify: `definition-form.css`

**Interfaces:**
- Native `<input type="file">` remains for a11y (visually clipped)
- Visible: accept hint (“PDF only” / “MP3 only”), button/drop zone, selected filename

- [ ] **Step 1:** Markup

```html
<div class="dg-file" data-dg-file>
  <input type="file" class="dg-file__input" id="..." name="..." accept="..." />
  <button type="button" class="dg-file__trigger" aria-controls="...">Choose file</button>
  <span class="dg-file__name" data-dg-file-name>No file chosen</span>
  <p class="dg-field__help">PDF only</p>
</div>
```

- [ ] **Step 2:** JS: trigger click → input; `change` → update filename.
- [ ] **Step 3:** Unit/e2e: accept attribute present; class `dg-file` present in render output.
- [ ] **Step 4: Commit**

```bash
git commit -m "feat(public): styled file inputs with accept hints"
```

---

### Task 7: UX-06 + UX-12 — Definition owns form chrome

**Files:**
- Modify: `class-portal-definition-renderer.php` or `class-portal-public-render.php` to compose: note + fields + agreements + submit
- Modify: `single-portal.php` — when definition present, **do not** emit agreements/notes shortcodes (detect via `Portal_Definition::load_for_post`)
- Modify: agreements shortcode to extract pure HTML builder used by both shortcode (legacy) and definition path
- Modify: submit label → “Submit application” on definition path
- Style agreements + recaptcha inside `.dg-form`

**Interfaces:**
- Produces: one `.dg-form` (or shell) containing agreements + submit for definition portals
- Closed path: template skips chrome shortcodes when closed **or** shortcodes empty (Task 1 already empty when closed)

- [ ] **Step 1: RED e2e** — open definition portal DOM: `document.querySelectorAll('.sub_submit, [name=sub_submit]').length === 1` and submit lives under `[data-dg-render=definition]` or `.dg-form`.
- [ ] **Step 2: Implement ownership**

Prefer:

```php
// single-portal.php
$definition = Portal_Definition::load_for_post( get_the_ID() );
// ...
the_content();
if ( ! is_array( $definition ) ) {
  echo do_shortcode( '[portal-application-agreements]' );
  echo do_shortcode( '[portal-application-upload-notes]' );
}
// formend only if legacy formstart opened
```

And `Portal_Definition_Renderer::render` / public-render open branch appends note + agreements + submit inside the form wrapper. **Form must wrap fields+submit** — currently formstart shortcode may open form outside content; for definition path, renderer should emit `<form method="post" enctype="multipart/form-data" class="dg-form ...">` **or** ensure formstart still wraps. Inspect `pb_formstart` — if definition + open, keep formstart but move agreements inside content. Cleaner: definition path uses self-contained form in filter_content and formstart returns '' when definition exists.

- [ ] **Step 3:** Agreements styling: remove inline `style=`; use `.dg-agreements`, `.dg-btn--primary` with `--ember-flow`.
- [ ] **Step 4:**

```bash
npx playwright test e2e/public e2e/pipeline
npm run test:unit
```

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(public): definition owns agreements, notes, and submit chrome"
```

---

### Task 8: Preview view-only (product decision)

**Files:**
- Modify: `class-portal-public-render.php` / submission pipeline entry
- Modify: definition form submit button when `is_preview_request`
- Tests: e2e preview does not POST production artifacts

**Rule:** If `Portal_Open_State::is_preview_request( $post_id )` and portal would otherwise be closed (or always in preview):
- Banner visible
- Form visible
- Submit control `disabled` **or** pipeline rejects with friendly message and does not write Sheet/Drive

Prefer: **disable submit** + `aria-disabled` + banner second line “Submissions are disabled in preview.”

- [ ] **Step 1: RED** — e2e editor preview: submit button disabled or absent; POST to pipeline returns no success write when forced.
- [ ] **Step 2: Implement**
- [ ] **Step 3: GREEN + commit**

```bash
git commit -m "feat(public): preview is view-only for submissions"
```

---

### Task 9: UX-08 — Remove block editor (wizard-only CPT)

**Files:**
- Modify: `includes/class-portal-post-type.php` L38 supports → `array( 'title', 'thumbnail' )`
- Optional: `remove_post_type_support( 'portal', 'editor' )` on init
- Verify: `e2e/admin/portal-crud.spec.ts` still green (REST-based)
- Do **not** delete legacy `post_content` (import still works)

- [ ] **Step 1:** Change supports; load new/edit portal in Playwright — assert no “Type / to choose a block”, wizard mount present.
- [ ] **Step 2: Commit**

```bash
git commit -m "feat(admin): wizard-only portals (drop block editor support)"
```

---

### Task 10: UX-09 — Admin CSS product chrome

**Files:**
- Modify: `assets/admin.css` or add `assets/portal-admin.css` enqueued only on portal screens
- Modify: `includes/class-portal-meta.php` enqueue

**Goals:**
- `#portal_setup_wizard` full width, primary visual
- Hide noisy unrelated meta (Custom Sidebars / OP3) via CSS if possible; at least collapse postbox clutter
- Title field remains for portal name
- Wizard root min-height comfortable

```css
/* portal edit only body class post-type-portal */
.post-type-portal .block-editor { /* gone after UX-08 */ }
.post-type-portal #portal_setup_wizard .inside { margin: 0; padding: 0; }
.post-type-portal .dg-wizard-root { min-height: 70vh; }
.post-type-portal #postbox-container-1 { /* optional narrow */ }
```

- [ ] **Step 1: Implement + screenshot admin edit**
- [ ] **Step 2: Commit**

```bash
git commit -m "feat(admin): full-width portal wizard chrome"
```

---

### Task 11: UX-10a — Wizard foundation (load/save definition)

**Files:**
- Modify: `src/wizard/WizardShell.svelte`
- Add: `src/wizard/definitionApi.js` (GET/POST facade — no raw fetch scatter)
- Modify: `src/index.js` — pass `portalId`, `restNonce`, `restRoot` from `wp_localize_script` / data attributes on mount
- Modify: `class-portal-meta.php` — data attributes on mount:

```php
printf(
  '<div data-portal-wizard class="dg-wizard-root" data-portal-id="%d" data-rest-root="%s" data-rest-nonce="%s"></div>',
  (int) $post->ID,
  esc_url_raw( rest_url( 'dragongate/v1' ) ),
  esc_attr( wp_create_nonce( 'wp_api' ) )
);
```

**Interfaces:**
```js
// definitionApi.js
export async function getDefinition({ restRoot, nonce, portalId })
export async function saveDefinition({ restRoot, nonce, portalId, definition })
// POST /portals/{id}/definition  body: { definition }
```

- [ ] **Step 1:** RED unit/e2e: wizard requests definition on load (intercept REST).
- [ ] **Step 2:** Load definition into Svelte state; save on explicit “Save portal definition” control (do not auto-spam).
- [ ] **Step 3:** `npm run build` + admin e2e definition-api still green.
- [ ] **Step 4: Commit**

```bash
git commit -m "feat(admin): wizard loads and saves portal definition via REST"
```

---

### Task 12: UX-10b — Start step (templates / blank / clone)

**Files:**
- Add: `src/wizard/steps/StartStep.svelte`
- Modify: `WizardShell.svelte`
- Reuse: existing `duplicate_portal` AJAX or REST list of portals for template cards
- Fixture templates: at minimum “Blank” + clone list from recent portals with definitions

**Mockup:** template cards “Composer Prize”, “Call for Scores”, “Start blank”.

- [ ] **Step 1:** Blank → empty `fields: []` draft definition + title from WP title
- [ ] **Step 2:** Clone → copy definition from selected portal id (GET other definition + POST current)
- [ ] **Step 3:** Gate seal advances to Build when fields or blank confirmed
- [ ] **Step 4: Commit**

```bash
git commit -m "feat(admin): wizard Start step with blank and clone templates"
```

---

### Task 13: UX-10c — Build step (field canvas)

**Files:**
- Add: `src/wizard/steps/BuildStep.svelte` (+ field list components as needed; keep files small)
- Field types v0.1 from PORTAL-MODEL: short_text, score_file, recording_file, applicant_pack, group, disclaimer, static_html (minimum set for Herbolzheimer)
- Live preview: optional read-only HTML iframe or simplified list; full public CSS preview is stretch — list + type pills OK for v1 if public preview is hard

**Done when:** Operator can add/remove/reorder fields and save `fields[]` via REST; Herbolzheimer shape constructible without editing JSON by hand.

- [ ] **Step 1:** Field list UI + add field type menu
- [ ] **Step 2:** Edit label/required/help; id auto from label slug
- [ ] **Step 3:** Persist via saveDefinition
- [ ] **Step 4:** E2e: create portal, set fields via wizard or REST parity test
- [ ] **Step 5: Commit**

```bash
git commit -m "feat(admin): wizard Build step for definition fields"
```

---

### Task 14: UX-10d — Map step

**Files:**
- Add: `src/wizard/steps/MapStep.svelte`
- Prefer writing `mapping.sheets` / `mapping.drive` on definition; bridge from legacy `_portal_record_keeping` / `_portal_file_backups` on first load if mapping empty
- Reuse concepts from `PortalBuilder.svelte` without forcing full dual UI forever — either embed PortalBuilder in Map step **or** simplified mapping table

**Strategy (recommended):** Map step owns definition `mapping`; keep legacy meta boxes visible until Map saves successfully once, then hide via admin CSS class `dg-mapping-migrated` or after wizard complete. Do not break pipeline that still reads legacy meta — dual-write mapping + legacy JSON until pipeline is definition-only (already partially true for definition submit).

- [ ] **Step 1:** UI: field → column / folder rows
- [ ] **Step 2:** Dual-write or definition-only with pipeline check on e2e pipeline
- [ ] **Step 3: Commit**

```bash
git commit -m "feat(admin): wizard Map step for Sheets and Drive mapping"
```

---

### Task 15: UX-10e — Publish step

**Files:**
- Add: `src/wizard/steps/PublishStep.svelte`
- Fields: deadline, timezone, applicationFee, forceClosed, guidelinesUrl, anonymize, freeForMembers
- WP publish: use existing post status UI or button that sets status via REST `wp/v2/portal/{id}`

- [ ] **Step 1:** Bind to `definition.publish` + `definition.options`
- [ ] **Step 2:** Save definition; optional “Publish portal” sets post_status publish
- [ ] **Step 3:** Hide redundant **Portal Options** meta box fields that are now in wizard (admin CSS or unregister when feature complete)
- [ ] **Step 4: Commit**

```bash
git commit -m "feat(admin): wizard Publish step for deadline and open state"
```

---

### Task 16: Best practices hardening (cross-cutting)

Do these as you touch areas — not a big-bang rewrite.

| Practice | Action |
|----------|--------|
| **Flag rename** | Prefer `DG_PORTAL_FORM_CLOSED` for new code paths; keep defining `PB_APPLICATION_DEADLINE_PASSED` as alias for legacy shortcodes until all shortcodes use empty-when-closed |
| **Facade** | Wizard REST only via `definitionApi.js`; no `fetch` in leaf components |
| **Constants** | Closed messages stay on `Portal_Public_Render` class constants; no new string literals in shortcodes |
| **File size** | Split `class-portal-definition-renderer.php` if it exceeds ~250 after form chrome (extract `class-portal-definition-form-chrome.php`) |
| **A11y** | Required fields: `required` + `aria-required`; preview banner `role="status"`; file button labeled |
| **No dual ownership** | After Task 7, grep for deadline copy in shortcodes — only `Portal_Public_Render` may emit closed copy |
| **Tokens** | Ban new `#ccc` / raw greys in definition CSS; legacy portal.css may keep until legacy portals die |
| **Tests** | Every phase ends with targeted test command; full `npm run ci` before declaring plan complete |
| **Tutorial shots** | Re-run `node scripts/capture-tutorial-shots.mjs` after Phases 1–7; replace stale `docs/design/tutorial/*.png` |
| **Legacy meta** | After Map+Publish parity, hide `portal_options` / record_keeping / file_backups behind “Advanced” or remove from default screen |

- [ ] **Step 1:** Add grep-based unit or CI note: `rg "deadline has passed" includes/shortcodes` should only hit comments or empty paths.
- [ ] **Step 2:** Run full gate:

```bash
npm run ci:offline
npm run test:env
npx playwright test e2e/smoke.spec.ts e2e/public e2e/admin e2e/pipeline
```

- [ ] **Step 3:** Final commit if any hygiene leftovers

```bash
git commit -m "chore: UX track hygiene and tutorial re-capture"
```

---

### Task 17: Plan completion gate (observable)

All must be true:

| Check | Evidence |
|-------|----------|
| Closed public: one status sentence | e2e + live shot |
| Force-closed never says deadline | e2e force-only |
| Preview banner tokenized; submit disabled | e2e |
| Form centered; side labels desktop | tutorial re-shot `05b` class |
| File controls styled | tutorial / e2e markers |
| Note alert not grey after submit | DOM order |
| No block editor on portal edit | admin screenshot / e2e |
| White menu icon | admin list shot |
| Wizard can save definition | REST round-trip from UI or e2e |
| `npm run ci:offline` green | stdout |
| Public + admin e2e green on LocalWP | stdout |

---

## Spec coverage matrix

| UX-NEXT-STEPS ID | Task(s) |
|------------------|---------|
| UX-01 | Task 1 |
| UX-02 | Task 2 |
| UX-03 | Task 4 |
| UX-04 | Task 6 |
| UX-05 | Task 5 (+ 7 placement) |
| UX-06 | Task 7 |
| UX-07 | Task 3 |
| UX-08 | Task 9 |
| UX-09 | Task 10 |
| UX-10 | Tasks 11–15 |
| UX-11 | Task 4 |
| UX-12 | Task 7 |
| Preview view-only | Task 8 |
| Best practices | Task 16–17 |
| PORTAL-MODEL definition-first | Tasks 7, 11–15 |
| TOKENS | Tasks 2–7 |

## Explicit non-goals (restate)

- Google OAuth / live adapter reliability
- Full drag-and-drop marketplace
- Dark mode
- Framework switch (Svelte 5 + Vite is permanent; see PORTAL-MODEL decision log)
- Production test-submit Sheet mode (post-plan)

## Risk notes

| Risk | Mitigation |
|------|------------|
| Definition form + formstart double form tags | Own form entirely in definition path; silence formstart when definition exists |
| Pipeline still needs legacy meta | Dual-write mapping in Map step until pipeline e2e is definition-only |
| Admin edit without editor confuses WP | Title + wizard CSS; document in About if needed |
| Wizard scope explosion | Ship 10a foundation before fancy DnD; Herbolzheimer field set first |
| LocalWP flakiness | Prefer REST assertions; use pretty permalinks for preview |

## Suggested first execution order (one day slices)

1. Task 0 → Task 1 → Task 2 → Task 3 (clarity + brand chrome)
2. Task 4 → Task 5 → Task 6 → Task 7 → Task 8 (public form bar)
3. Task 9 → Task 10 → Task 11 (admin model)
4. Tasks 12–15 (wizard depth)
5. Tasks 16–17 (gate + captures)

---

*Plan authored 2026-08-12 from session resume + live LocalWP verification + UX-NEXT-STEPS.md.*
