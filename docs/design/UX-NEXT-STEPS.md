# DragonGate Portals — UI/UX Next Steps

**Status:** working design brief (execute against)  
**Date:** 2026-07-26  
**Audience:** principal engineer + designer  
**Ground truth:** user critique · LocalWP tutorial captures (`docs/design/tutorial/`) · mockups (`docs/design/mockups/`) · code under `includes/Definition/`, `assets/`, `src/wizard/`  
**Contracts:** [`TOKENS.md`](./TOKENS.md) · [`PORTAL-MODEL.md`](./PORTAL-MODEL.md)  
**UI stack:** Svelte 5 + Vite for all product UI (wizard, builders). Permanent choice — do not propose Alpine or a second framework.

This is not a wishlist. Each item has file pointers, severity, and observable done-when criteria.

---

## 1. Executive summary

**Operator job (admin):** create and open an application portal — name it, choose or clone a structure, define fields, map Sheets/Drive, set deadline/fee/disclaimers, publish. “Done” for the operator is a public URL that accepts applications and lands rows/files where they expect.

**Applicant job (public):** open the portal URL, understand what is required, upload score/recording (and identity), accept legal statements, submit once, receive a receipt. “Done” for the applicant is a clear confirmation that the submission landed — not a puzzle about layout or whether the portal is open.

**Product thesis (from PORTAL-MODEL):** the portal is a **structured definition** (`fields[]` + mapping + publish), not WordPress post body. The admin surface should feel like a **single DragonGate product** (wizard shell in mockups). The public surface should feel like a **single paper form** with brand tokens — not a collage of shortcode chrome, native file widgets, and unstyled paragraphs.

**Current gap:** definition-first render works for open forms and closed/preview gates, but **three product UIs fight each other**:

1. WordPress block editor + publish sidebar (empty canvas for definition portals)
2. Svelte “Portal setup” wizard meta box (real product UI, still incomplete steps)
3. Public template + shortcode shell + definition renderer (form works; chrome, closed, note, and layout do not meet the bar)

Tutorial captures and the closed page prove sparse hierarchy and **duplicated deadline copy**. The mockup deck shows a full-shell checklist wizard that never appears as the sole edit surface.

**North star for this track:** one obvious authoring path for operators; one clear, dense, tokenized form path for applicants; closed and preview states that communicate a single truth.

---

## 2. Current architecture of the UI

| Surface | Who | What it is today | Primary code |
|--------|-----|------------------|--------------|
| WP admin menu “Portals” | Operator | CPT menu; icon is black SVG on dark chrome | `includes/class-portal-post-type.php` (`menu_icon`, `get_menu_icon_data_uri`) |
| Portal list | Operator | Standard WP list table + Duplicate row action | `Portal_Post_Type` |
| Block editor (post edit) | Operator (confused) | Title + empty block canvas + WP publish/sidebar | CPT `supports` includes `'editor'`; capture `03-admin-portal-edit.png` |
| “Portal setup” meta box | Operator | Svelte wizard mount (`data-portal-wizard`) — Start/Build/Map/Publish shell | `includes/class-portal-meta.php` `render_wizard_mount`; `src/wizard/WizardShell.svelte` |
| Legacy meta boxes | Operator | Sheets/Drive tables, deadline fields still registered beside wizard | `portal-builder.php` + `Portal_Meta` |
| Public singular | Applicant | Theme header + shortcode chrome + `the_content` filter | `includes/templates/single-portal.php` |
| Definition form | Applicant | `fields[]` → HTML fieldsets / file inputs | `includes/Definition/class-portal-definition-renderer.php` |
| Open/closed/preview gate | System | Publish config + capability preview | `includes/Definition/class-portal-open-state.php`, `class-portal-public-render.php` |
| Closed message | Applicant | Unstyled `<div class="dg-portal-closed">` **plus** shortcode fallbacks | See §3 P0-1 |
| Preview banner | Editor | Unstyled `dg-preview-banner` string | `Portal_Public_Render::render_preview_banner` |
| Upload “Note” | Applicant | Inline grey box shortcode **below** submit | `includes/shortcodes/portal-application-upload-notes.php` |
| Agreements + submit | Applicant | Global option disclaimers + Submit | `includes/shortcodes/portal-application-agreements.php` |
| Design target | Team | Full-page shell, paper/ink/ember | Mockups `01–04-*.png`, `TOKENS.md`, `src/tokens.css` |

**Request path (public singular):**

```
single-portal.php
  → [portal-application-title]
  → [portal-application-formstart]   # empty when closed
  → the_content  → Portal_Public_Render::filter_content
       → closed message  OR  preview banner + definition form  OR  legacy wrap
  → [portal-application-agreements]  # emits deadline <p> when closed
  → [portal-application-upload-notes] # emits deadline <p> when closed
  → [portal-application-formend]
```

**Request path (admin edit):** WP block editor loads full chrome; high-priority meta box mounts wizard below the block canvas. Definition is REST/meta, not blocks.

---

## 3. Problem inventory

Severity: **P0** = applicants or operators misled / broken clarity now; **P1** = brand or structure wrong, blocks rewrite polish; **P2** = polish / debt.

### P0-1 — Closed page shows three conflicting messages (duplicate deadline)

| | |
|--|--|
| **Symptom** | Closed public URL shows e.g. “This portal is closed.” then “The application deadline has passed.” twice (`06-public-closed.png`). Sparse left-aligned plain text under the title. |
| **Root cause** | (1) `Portal_Public_Render::render_closed_message()` returns one reason-specific line. (2) Template still runs agreements + upload-notes shortcodes after `the_content`. Both shortcodes, when `PB_APPLICATION_DEADLINE_PASSED` is true, return **the same** deadline string regardless of closed reason. |
| **Exact lines** | `class-portal-public-render.php` L156–169 (`render_closed_message`); `prime_request_flags` / filter set `PB_APPLICATION_DEADLINE_PASSED` for **any** closed state (L46–50, L97–101). `portal-application-agreements.php` L7–8. `portal-application-upload-notes.php` L6–7. Template order: `single-portal.php` L38–39 after `the_content`. |
| **Impact** | Applicant cannot trust the page; force-closed portals incorrectly claim deadline passed; looks unfinished. |
| **Severity** | **P0** |

### P0-2 — Block editor + Portal setup dual surface

| | |
|--|--|
| **Symptom** | Edit screen is mostly empty “Type / to choose a block” plus WP sidebar; product UI is a collapsible meta box underneath (`03-admin-portal-edit.png`, `09-admin-new-portal.png`). Operators do not know what the block canvas is for. |
| **Root cause** | CPT registers `'editor'` support (`class-portal-post-type.php` L38). Definition is the real model (PORTAL-MODEL §2–3, §10); shortcodes are import-only. Wizard is injected as a meta box, not the primary chrome. Mockups assume a full DragonGate shell, not Gutenberg. |
| **Impact** | Training cost, mistaken content edits, dual mental model; blocks rewrite velocity. |
| **Severity** | **P0** (product model) |

### P1-1 — Menu icon unreadable / off-token

| | |
|--|--|
| **Symptom** | Admin sidebar “Portals” icon is dark (black fills) on WP dark chrome (`02-admin-portal-list.png`). Does not read as brand. |
| **Root cause** | Inline SVG in `get_menu_icon_data_uri()` uses `#000` gradients and `#fff` only for check accents (`class-portal-post-type.php` L52–94; same as `assets/icon.svg`). WP expects menu icons that read on dark backgrounds (typically single-color, often white/`currentColor`). |
| **Impact** | Brand miss on every admin page; low trust next to polished mockups. |
| **Severity** | **P1** |

### P1-2 — Public form layout density and field chrome

| | |
|--|--|
| **Symptom** | Form sits in a narrow left card with large empty right margin; applicant pack and submission (data ingestion) feel sparse; native “Choose File” controls look like browser defaults. **Labels end up above fields** (or misaligned vs controls) instead of a clean label|control row — especially bad on score/recording. |
| **Root cause** | Split CSS: intended side-label grid in `assets/portal.css` (`.portal-group .form-grid` + `display: contents`) fights token card rules in `assets/definition-form.css`, so layout **collapses into label-above** (user critique). File inputs are bare `<input type="file">` (`class-portal-definition-renderer.php` `render_file_input`). No dedicated file-drop / accept-hint UI. Form width capped by legacy rules without a centered content column. Help jammed via `<br /><span class="caption">` inside labels. |
| **Impact** | Applicants hesitate on score/recording upload; form feels “not finished” vs site chrome. |
| **Severity** | **P1** |

### P1-3 — Note section is a grey ad-hoc box, wrong placement

| | |
|--|--|
| **Symptom** | After Submit, a grey rounded box with underlined “Note:” and mixed required/file-conversion copy (`05b`). Not token-aligned; not an info/warning alert pattern. |
| **Root cause** | `portal-application-upload-notes.php` L16–24: inline `background-color: rgba(155, 150, 150, 0.5); border-radius: 25px`. Template places notes **after** agreements/submit. Content mixes required-field legend with CloudConvert links. |
| **Impact** | Visual noise; wrong hierarchy (notes after CTA); fails TOKENS semantic surfaces. |
| **Severity** | **P1** |

### P1-4 — Preview banner unstyled; closed state has no layout system

| | |
|--|--|
| **Symptom** | Preview = plain left-aligned text “Preview — not a live submission” (`07-editor-preview-closed.png`). Closed = unstyled paragraphs + huge empty main (`06`). No card, no max-width column, no hierarchy. |
| **Root cause** | Markup exists (`dg-preview-banner`, `dg-portal-closed`) but **no CSS rules** for those classes in `definition-form.css` / `portal.css` (grep finds only PHP). Template still renders full title shortcode chrome above closed body. |
| **Impact** | Editors cannot tell preview is intentional; applicants see a broken page. |
| **Severity** | **P1** |

### P1-5 — Public page is a shortcode collage, not one form surface

| | |
|--|--|
| **Symptom** | Title, definition fieldsets, global agreements, note, and submit live in separate template fragments with inconsistent width/alignment. |
| **Root cause** | `single-portal.php` orchestrates shortcodes around definition content. Definition renderer does not own agreements, notes, or submit chrome. |
| **Impact** | Hard to restyle as one card; closed path cannot short-circuit cleanly; dual definition vs legacy paths. |
| **Severity** | **P1** (architecture for public UX) |

### P2-1 — Wizard steps are placeholders vs mockups

| | |
|--|--|
| **Symptom** | Start/Build/Map/Publish shell renders; step bodies are “will live here” placeholders (`WizardShell.svelte`). Mockups show template cards, field canvas, mapping, publish. |
| **Root cause** | Vertical slice prioritised definition render + gate over wizard content. |
| **Impact** | Operators still use legacy meta boxes for real work. |
| **Severity** | **P2** for this UX track’s *visual* fixes; **P1** for product completeness (tracked in roadmap as dependency of editor model). |

### P2-2 — Required marker is a bare `*` glued to labels

| | |
|--|--|
| **Symptom** | `Title*`, `Full Score*` — no accessible “required” text, no consistent marker styling. |
| **Root cause** | `label_text()` appends `*` (`class-portal-definition-renderer.php` L367–372). |
| **Impact** | A11y and visual polish. |
| **Severity** | **P2** |

### P2-3 — Tokens not fully applied on public

| | |
|--|--|
| **Symptom** | Definition card uses tokens; agreements/submit/note/title use theme + inline styles; portal.css still has `#ccc` borders. |
| **Root cause** | Partial migration to definition-form.css; shortcode paths never tokenized. |
| **Impact** | Brand split between form island and page. |
| **Severity** | **P2** (resolved by P1-3/P1-5 work) |

---

## 4. Recommended product model for the editor

### Options

| Option | Summary | Pros | Cons |
|--------|---------|------|------|
| **A — Wizard-only** | Remove block editor support; portal edit screen is title (WP title or wizard) + full-width Portal setup shell. Publish via WP status or wizard Publish step. | Matches PORTAL-MODEL and mockups; one mental model; no empty block canvas | Custom admin chrome work; loses freeform post body unless replaced by `static_html` fields |
| **B — Title + optional freeform** | Keep editor **only** for optional intro copy above the form; hide block UI when unused; wizard remains primary | Allows marketing prose without `static_html` | Still two editors; freeform content often unused for prize portals |
| **C — Hybrid progressive disclosure** | Default wizard-only; “Advanced: page content” reveals block editor | Flexible | Complexity; most operators never need it; still ships dual UI debt |

### Primary recommendation: **Option A — wizard-only portal builder**

**Rationale:**

1. **PORTAL-MODEL** states structured `fields[]` is source of truth; classic post content is import source only (§9, §12 acceptance: “Define a portal only via `fields[]`”).
2. **Mockups** (`01-start` … `04-publish`) are a full DragonGate shell (topbar + gates + paper main), not a Gutenberg canvas with a meta box.
3. Live edit capture shows **zero useful block content** on definition portals — the canvas is dead weight.
4. Applicant-facing prose that is structural belongs as `static_html` / disclaimer fields or portal options (`guidelinesUrl`), not arbitrary blocks.

**Migration notes:**

1. Change CPT `supports` from `array( 'title', 'editor', 'thumbnail' )` to `array( 'title', 'thumbnail' )` (or title-only if featured image unused) in `includes/class-portal-post-type.php`.
2. Prefer classic-style edit or a custom admin page later; short term: remove editor support so Gutenberg does not own the canvas. Use `add_meta_box` priority and admin CSS so `#portal_setup_wizard` is full-width and primary.
3. Optional: `remove_post_type_support( 'portal', 'editor' )` on init for safety if other code re-adds it.
4. Move deadline/Sheets/Drive UI **into** wizard steps (Map/Publish) per mockups; hide legacy meta boxes once parity exists.
5. Import path: existing `post_content` shortcodes still importable; do not re-enable editor for day-to-day authoring.
6. If a portal **must** show freeform HTML above the form: add optional `publish.introHtml` or a leading `static_html` field — not the block editor.
7. WP title remains the portal name (list table, public H2 via title shortcode or renderer). Consider syncing title edits into wizard Start step for a single place later.

**Not recommended now:** Option C as a standing product mode. Revisit only if a real content team requires long-form portal landing pages (open question §10).

---

## 5. Visual system next steps

Reference: [`TOKENS.md`](./TOKENS.md), `src/tokens.css`.

| Item | Direction | Pointers |
|------|-----------|----------|
| **Menu icon** | Single-color mark for WP admin: **ember** (`#B8501F` / `var(--ember)`) **or** solid **white** / near-paper on dark chrome. Prefer **white monochrome** for WP menu (WP greys out inactive; color icons often fight core CSS) with ember reserved for selected state if custom admin CSS is acceptable. Remove black gradient fills. | `class-portal-post-type.php` `get_menu_icon_data_uri()`; source art `assets/icon.svg` / `assets/dragon.svg` |
| **Surfaces** | Public form card: `--paper-50` fill, `--rule` border, `--shadow-card`, `--r-3`. Page ground may remain theme; form column uses paper. | `definition-form.css` already starts this — complete it |
| **Alert / Note** | Replace grey box with semantic alert: default **info** = `--ember-100` bg + `--ink` text + left rule or brass eyebrow; **warn** for size limits if needed. Structure: eyebrow “Note”, body, optional links. Never raw grey `rgba(155,150,150)`. | New classes e.g. `.dg-alert`, `.dg-alert--info` in `definition-form.css`; markup from shortcode or definition renderer |
| **Closed state** | Centered content column (max ~36rem), card or quiet stack: title (portal name) → one status line → optional deadline datetime → optional guidelines link. No triple messages. | New styles for `.dg-portal-closed`; PHP markup enrichment in `render_closed_message` |
| **Preview banner** | Full-width or form-column sticky strip: `--ember-100` / brass border, `role="status"`, clear copy. Not bare paragraph. | `.dg-preview-banner` |
| **Form fields** | See §6. Prefer side-label rows (not label-above). Kill remaining `#ccc` in `portal.css` for definition forms; prefer definition-form.css as single public stylesheet path. |
| **Density** | Spacing scale `--s-3`/`--s-4` between fields; `--s-5` between groups. Avoid multi-em empty regions under title. |
| **Alignment** | Public main: center form column (`margin-inline: auto`, `max-width: 40rem` or `42rem`). Stop left-ragged island on wide viewports. |

**Anti-patterns (from TOKENS):** ember as page wash; brass as body text; color-only status without shape/label for gates; raw hex in new CSS.

---

## 6. Form UX spec (public)

Applies to definition-rendered portals. Legacy shortcode portals may trail but should not block definition path.

### 6.1 Layout contract

| Rule | Spec |
|------|------|
| **Column** | Single centered column, `max-width: 40rem` (640px) for the form shell; readable on laptop without a empty half-page. |
| **Label position** | **Side labels on desktop** (label left, control right) for text/email/tel/select — this is the intended prize-form density; user critique rejects **labels stacked above** fields as looking wrong. Fix the fragile `display: contents` side-label grid so it never collapses into accidental “label-above” stacking. **Stack only below ~640px** (mobile). File fields: label + help on left (or above the drop zone only if the control is a multi-line drop target), never orphaned bare “Choose File” under a lone label with no alignment contract. |
| **Paired fields** | Optional 2-column **control** grid for short pairs (e.g. City + Zip) inside applicant pack — each cell keeps the same side-label pattern as siblings. |
| **Groups** | `fieldset` + `legend` (display font, `--t-h3`) for `applicant_pack`, `group`, `branch`. Background `--paper` inset on `--paper-50` shell. |
| **Required** | Visible marker after label text (ember or ink asterisk) **and** `required` / `aria-required`. Prefer `<span class="dg-required" aria-hidden="true">*</span>` + legend note once: “Required fields are marked with *”. |
| **Help** | Separate element under label or under control: `.dg-field__help` / `.caption` — never `<br>` inside `<label>`. |
| **File fields** | Custom control: drop zone or button styled with tokens; show `accept` as human text (“PDF only”, “MP3 only”); show selected file name after pick. Keep native input for a11y (visually hidden or clipped). Types: `score_file`, `recording_file`, `bio_file`, `file`. |
| **Applicant pack** | One fieldset “Your Information”; order Title → Name → Email → Org → Address → City → Country → Region → Zip → Phone. |
| **Submission group** | Second fieldset; title of work + score + recording as primary ingestion path — highest visual weight after identity. |
| **Agreements** | Inside form shell, above submit; checkbox + label on one row; legal text readable (`--t-small`, `--ink-600`). Source may remain global options until wizard owns them. |
| **Note / alert** | **Above** file fields (or directly under submission legend), **not** after Submit. Info alert pattern (§5). Split content: (a) upload size/time; (b) conversion links as secondary list. Drop Call-for-Scores-specific required copy on prize-only portals (make copy portal-aware or generic). |
| **Submit CTA** | Primary button: ember fill / `--ember-flow`, white/paper text, `--shadow-ember`, full width on small screens, auto width on large. Label “Submit application” (not bare “Submit”) for definition path. Disable + spinner on submit (always-show-state). |
| **Errors** | Existing `.dg-submit-errors` → style as `--error-100` / `--error` list with `role="alert"`; associate field errors when client validation lands. |
| **Success / receipt** | Already gated via pipeline; keep single confirmation surface, tokenized. |

### 6.2 Markup sketch (target)

```html
<div class="dg-form portal-definition-form" data-dg-render="definition">
  <div class="dg-alert dg-alert--info" role="note">
    <p class="dg-alert__title">Note</p>
    <p class="dg-alert__body">Large files may take several minutes. Keep uploads under 32&nbsp;MB.</p>
    <ul class="dg-alert__links">…</ul>
  </div>

  <fieldset class="dg-field dg-field--applicant_pack">… stacked fields …</fieldset>

  <fieldset class="dg-field dg-field--group">
    <legend>Submission Information</legend>
    <!-- Desktop: label | control grid; not label stacked above -->
    <div class="dg-field dg-field--short_text dg-field--row">
      <label for="sub_work_title">Title of Work <span class="dg-required">*</span></label>
      <input id="sub_work_title" name="sub_work_title" required />
    </div>
    <div class="dg-field dg-field--score_file dg-field--row">
      <div class="dg-field__meta">
        <label for="sub_score">Full Score <span class="dg-required">*</span></label>
        <p class="dg-field__help">PDF only</p>
      </div>
      <div class="dg-file">
        <input type="file" id="sub_score" name="sub_score" accept="application/pdf" required />
        <!-- styled trigger + filename (not raw browser widget alone) -->
      </div>
    </div>
    <!-- recording_file similarly -->
  </fieldset>

  <div class="dg-agreements">…</div>
  <button type="submit" class="dg-btn dg-btn--primary">Submit application</button>
</div>
```

### 6.3 CSS ownership

- **Canonical public form styles:** `assets/definition-form.css` (+ tokens).
- **Canonical layout:** side-label rows for definition forms (desktop); do not default to label-above.
- Scope any remaining stacked-only rules to mobile breakpoints.
- Enqueue remains `Portal_Public_Render::enqueue_assets`.


### 6.4 Renderer / template ownership

- Prefer definition path to render **one** form document including notes + agreements + submit when definition exists, **or** make template shortcodes no-op for definition portals (return empty) so `filter_content` owns the full tree.
- Minimum fix for closed: shortcodes return empty when closed (not deadline text) — see §7.

---

## 7. Closed / preview UX spec

### 7.1 Closed (anonymous visitor)

**Single hierarchy (no duplicates):**

1. **Eyebrow:** “Application portal” (optional, muted)
2. **Title:** portal post title (existing title shortcode or closed shell)
3. **Status heading:** one line from reason  
   - `deadline` → “The application deadline has passed.”  
   - `force` → “This portal is closed.”  
   - `status` → “This portal is not currently accepting applications.”
4. **Detail (optional):** formatted deadline in portal timezone when reason is deadline; never invent deadline text for force-closed.
5. **Action:** guidelines link if `guidelinesUrl` / meta set; else nothing.
6. **No form, no agreements, no note, no submit.**

**Layout:** centered column, vertical rhythm `--s-4`/`--s-5`, optional quiet card. Main should not look “empty broken page” — content block vertically centered or upper-third with footer still normal.

**Code changes (document only — implement in roadmap):**

| Change | Where |
|--------|--------|
| Enrich `render_closed_message` markup (title optional, single reason, data attributes keep) | `class-portal-public-render.php` |
| Style `.dg-portal-closed` | `definition-form.css` |
| Agreements shortcode: if closed → `return ''` (not deadline paragraph) | `portal-application-agreements.php` L7–8 |
| Upload-notes shortcode: if closed → `return ''` | `portal-application-upload-notes.php` L6–7 |
| Optionally skip title chrome noise; keep portal name once | `single-portal.php` / title shortcode |

**Bug proof (current triple path):**

| Output line | Source |
|-------------|--------|
| Reason-specific closed line | `Portal_Public_Render::render_closed_message` via `the_content` |
| “The application deadline has passed.” | `pb_application_agreements_shortcode` when `PB_APPLICATION_DEADLINE_PASSED` |
| “The application deadline has passed.” | `pb_application_upload_notes_shortcode` when `PB_APPLICATION_DEADLINE_PASSED` |

Note: `PB_APPLICATION_DEADLINE_PASSED` is set for **all** closed states (`should_show_form` false), so force-closed portals incorrectly get deadline sentences from shortcodes.

### 7.2 Preview (editor, closed or draft)

| Element | Spec |
|---------|------|
| **Banner** | Always first in content; `role="status"`; copy: “Preview — not a live submission” (keep constant); optional second line: “Submissions are disabled in preview.” |
| **Form** | Full definition form UI for layout QA (`should_show_form` true via capability). |
| **Submit** | v1: view-only or clearly non-production (PORTAL-MODEL default: view-only / client validation only). If submit remains wired, banner must stay visible and pipeline must not write production Sheet rows without test flag. |
| **Style** | Ember-tinted bar, full form column width, not a stray left paragraph (`07` capture fails this). |

### 7.3 Empty-space rules

- Closed: content block min-height ~40vh with centered message **or** natural flow with ≤ one spacer — not a near-blank main + footer jump.
- Open: title → form within `--s-5` of each other; no large dead zones between groups.

---

## 8. Prioritized roadmap

Order optimizes **clarity first** (stop lying closed pages, then public form cohesion, then admin model, then brand chrome, then wizard depth).

| ID | Work item | Surface | Effort | Depends on | Done when (observable) |
|----|-----------|---------|--------|------------|------------------------|
| **UX-01** | Fix closed-path message ownership: shortcodes silent when closed; one message from `render_closed_message` | Public closed | S | — | Closed URL shows **exactly one** status sentence; force-closed never says “deadline has passed”; capture re-shot replaces triple text |
| **UX-02** | Closed + preview visual system (card/column, `.dg-portal-closed`, `.dg-preview-banner`) | Public closed / preview | S | UX-01 | Preview banner is full-width strip on form column; closed page is centered hierarchy, not three orphan paragraphs |
| **UX-03** | Public form layout v2: reliable side labels (desktop), centered column, group density, token borders | Public form | M | — | `05b`-class page: form centered; desktop labels **beside** controls (not stacked above); no `#ccc` chrome on definition form |
| **UX-04** | File ingestion UI (score/recording): accept hints, styled control, filename feedback | Public form | M | UX-03 | Score/recording no longer look like raw browser file inputs; accept text visible |
| **UX-05** | Note → `dg-alert` info pattern; move above files; portal-aware copy | Public form | S | UX-03 | Grey box gone; alert uses `--ember-100` / tokens; not after Submit |
| **UX-06** | Definition owns form chrome OR template short-circuits for definition portals (agreements + submit + note inside one tree) | Public form | M | UX-03–05 | View-source / DOM: single `.dg-form` (or clear shell) contains agreements+submit; closed path cannot double-emit |
| **UX-07** | Admin menu icon: monochrome white or ember-safe SVG | WP admin | S | — | Portals icon clearly visible on dark admin menu; matches brand review |
| **UX-08** | Editor model Option A: remove `editor` support; promote wizard meta box to primary edit UI | Admin edit | M | — | New/edit portal: no empty block canvas; title + Portal setup dominate; mockup-aligned shell feasible |
| **UX-09** | Admin CSS: full-width wizard, hide noise sidebars on `portal` CPT edit | Admin edit | S | UX-08 | Edit screen looks product-like; block inserter not the first thing seen |
| **UX-10** | Wizard step content (Start templates, Build canvas, Map, Publish) per mockups | Admin wizard | L | UX-08 | Operator can configure definition without legacy meta boxes |
| **UX-11** | Required markers + field help structure + primary Submit label | Public form | S | UX-03 | Consistent required UI; help not inside labels via `<br>` |
| **UX-12** | Agreements + reCAPTCHA styling inside form shell | Public form | S | UX-06 | Legal block density and alignment match fieldsets |

**Suggested first ship slice:** UX-01 → UX-02 → UX-07 → UX-03 → UX-05 → UX-04 → UX-08 (parallelizable after UX-01).

---

## 9. Out of scope / non-goals (this track)

- Live Google Sheets/Drive adapter reliability, OAuth, or new mapping backends  
- Importer completeness beyond what PORTAL-MODEL already specifies  
- Full Gravity Forms / WPForms parity  
- In-admin submission inbox  
- Dark mode  
- Multi-language  
- Drag-and-drop marketplace templates  
- Rewriting theme chrome (ISJAC header/footer)  
- Long Playwright campaigns or LocalWP e2e green-up beyond visual acceptance of listed items  
- Changing submission pipeline semantics except where public UX requires test/preview flags  

---

## 10. Open questions (genuine forks)

1. **Is post body content ever needed on public portal pages?**  
   If yes for marketing landings, Option B or a `static_html` / `introHtml` field is required. If no (current definition portals), Option A stands without exception.

2. **Menu icon color preference: white monochrome vs ember?**  
   White is safer with core admin CSS; ember needs custom `.toplevel_page_…` or menu CSS. Product call.

3. **Preview submit:** keep view-only for v1, or allow labeled test submissions that write to a test Sheet?  
   PORTAL-MODEL defaults view-only; pipeline may still accept POSTs today — confirm product rule before UX copy on the banner.

4. **Featured image / thumbnail on portals:** keep CPT support or drop with editor?

5. **Upload note CloudConvert links:** keep for all portals, make optional per portal, or move to guidelines URL only?

---

## Appendix A — Evidence map

| Capture / doc | Used for |
|---------------|----------|
| `tutorial/03-admin-portal-edit.png` | Dual editor + wizard |
| `tutorial/05-public-form-open.png`, `05b-…-full.png` | Form layout, files, note, agreements |
| `tutorial/06-public-closed.png` | Triple closed messaging |
| `tutorial/07-editor-preview-closed.png` | Unstyled preview banner |
| `tutorial/02-admin-portal-list.png` | Menu icon |
| `mockups/01-start.png` … `04-publish.png` | Wizard-only product target |
| `TOKENS.md` | Ember, paper, alert surfaces |
| `PORTAL-MODEL.md` | Definition source of truth, preview rules |

## Appendix B — Key file index

| Concern | Path |
|---------|------|
| CPT + menu icon | `includes/class-portal-post-type.php` |
| Wizard mount | `includes/class-portal-meta.php` |
| Wizard UI | `src/wizard/WizardShell.svelte`, `GateNav.svelte`, `steps.js` |
| Public gate + closed/preview | `includes/Definition/class-portal-public-render.php` |
| Open state | `includes/Definition/class-portal-open-state.php` |
| Form HTML | `includes/Definition/class-portal-definition-renderer.php` |
| Public CSS | `assets/definition-form.css`, `assets/portal.css` |
| Tokens | `src/tokens.css`, `docs/design/TOKENS.md` |
| Template shell | `includes/templates/single-portal.php` |
| Note shortcode | `includes/shortcodes/portal-application-upload-notes.php` |
| Agreements shortcode | `includes/shortcodes/portal-application-agreements.php` |
| Title shortcode | `includes/shortcodes/pb_application_title.php` |

---

*End of brief. Implementation should land acceptance criteria in e2e/visual captures under `docs/design/tutorial/` after each UX-0x slice, without expanding product scope listed in §9.*
