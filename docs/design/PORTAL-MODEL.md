# DragonGate Portals — Domain Model Contract

**Status:** accepted (v0.1) — rewrite target, not a description of the shortcode era  
**Framework:** Svelte 5 + Vite (admin + public UI)  
**Design tokens:** `docs/design/TOKENS.md`  
**Companion:** mockups in `docs/design/mockups/`

This document is the product contract. Implementation maps to it. Legacy shortcodes and classic post content are **import sources**, not the authoring model.

---

## 1. Product outcome

Admins (often non-technical volunteers) create **application portals**: multi-field forms that collect text and files, write rows to **Google Sheets**, store files in **Google Drive** (and/or local storage), and email receipts.

Applicants complete a **public form** for one portal and receive confirmation.

**One UI language** for admin setup and public form chrome: paper / ink / ember tokens.

---

## 2. Core resource: Portal

A Portal is the single aggregate. Stable id = WordPress post ID while we stay on WP; storage may later move.

| Field | Type | Notes |
|-------|------|--------|
| `id` | opaque | WP post ID today |
| `title` | string | Public + admin name |
| `slug` | string | Public URL segment under `/portal/{slug}` |
| `status` | `draft` \| `published` \| `archived` | WP post status mapped |
| `fields` | `Field[]` | Ordered form definition (**source of truth**) |
| `mapping` | `Mapping` | Sheets + Drive bindings |
| `publish` | `PublishConfig` | Deadline, fee, open/closed |
| `options` | `PortalOptions` | Anonymize, guidelines URL, etc. |
| `templateOf` | id? | If cloned from another portal |

### PortalOptions

| Field | Type | Notes |
|-------|------|--------|
| `anonymize` | bool | Strip identity from judge-facing exports when true |
| `skipHeader` | bool | Sheet write behavior |
| `guidelinesUrl` | url? | Linked from title/header |
| `applicantNotificationDate` | date? | Copy on success message |
| `freeForMembers` | bool | Fee waiver rule (Woo/membership integration optional) |

### PublishConfig

| Field | Type | Notes |
|-------|------|--------|
| `deadline` | datetime? | Portal tz; null = no deadline |
| `timezone` | IANA string | e.g. `America/New_York` (not a raw offset index long-term) |
| `applicationFee` | money? | Minor units + currency later; number today |
| `isOpen` | bool | Derived: published ∧ (no deadline ∨ now ≤ deadline) ∧ not force-closed |
| `forceClosed` | bool | Admin override |

**Preview rule:** Authenticated users with `edit_post` on the portal MAY open a **preview** that renders the form even when `isOpen` is false (deadline passed / draft). Preview MUST NOT create production submissions unless explicitly labeled “test submit” (v1: preview is view-only of form UI, or submits to a test flag — decide at implement; default **view-only + client validation only** for v1).

---

## 3. Field model

Fields are a **ordered list**. No shortcodes. Nested structure uses group / branch field types.

### Field (base)

| Field | Type | Notes |
|-------|------|--------|
| `id` | string | Stable within portal; slug-like (`work_title`). **Not** shown as primary UI label |
| `type` | FieldType | Closed enum |
| `label` | string | Human-facing |
| `required` | bool | |
| `help` | string? | Caption / helper |
| `mapKey` | string? | Default export key; usually = `id` with `sub_` prefix only if needed for migration |

### FieldType (v0.1)

| Type | Purpose | Value shape |
|------|---------|-------------|
| `short_text` | Single-line text | string |
| `long_text` | Multi-line (future; not required v1) | string |
| `email` | Email (when not using applicant pack) | string |
| `phone` | Tel | string |
| `file` | Generic file | file ref |
| `score_file` | PDF score; accept application/pdf | file ref |
| `recording_file` | Audio; accept audio/mpeg (mp3) | file ref |
| `bio_file` | PDF bio | file ref |
| `applicant_pack` | Built-in identity block (title, name, email, org, address, country, region, zip, phone) | object |
| `group` | Visual fieldset; contains `children: Field[]` | — |
| `branch` | Category chooser; `options: { id, label, children: Field[] }[]` | selected option id + child values |
| `disclaimer` | Single required checkbox + legal text | bool |
| `static_html` | Non-input prose (migration of `pb_full_span` / notices) | — |

**Rules:**

- `applicant_pack` appears at most once per portal (typical: first).
- `branch` options are mutually exclusive for required child validation: only the selected branch’s required fields apply.
- File types carry default `accept` and suggested Drive naming (`fileSuffix` optional for export filenames).
- Field **id** is secondary in UI (muted mono); **label** leads (see TOKENS).

### Example (simple prize ≈ Herbolzheimer)

```json
{
  "fields": [
    { "id": "applicant", "type": "applicant_pack", "label": "Your Information", "required": true },
    {
      "id": "submission",
      "type": "group",
      "label": "Submission Information",
      "children": [
        { "id": "work_title", "type": "short_text", "label": "Title of Work", "required": true },
        { "id": "score", "type": "score_file", "label": "Full Score", "required": true, "fileSuffix": "_SCORE" },
        { "id": "recording", "type": "recording_file", "label": "Recording (MP3)", "required": true, "fileSuffix": "_REC" }
      ]
    }
  ]
}
```

### Example (branching ≈ Call for Scores)

`branch` with options Poster / Papers / Scores…; nested `branch` under Scores for masterclass categories. Importer maps `pb_group_selection_wrap*` → `branch`.

---

## 4. Mapping

### Mapping

| Field | Type |
|-------|------|
| `sheets` | `SheetTarget[]` |
| `drive` | `DriveTarget[]` |

### SheetTarget

| Field | Type | Notes |
|-------|------|--------|
| `id` | string | Local id |
| `name` | string | Admin label |
| `spreadsheetId` | string | Google spreadsheet id |
| `columns` | `ColumnMap[]` | |

### ColumnMap

| Field | Type | Notes |
|-------|------|--------|
| `fieldId` | string | Portal field id (or dotted path into applicant_pack / branch) |
| `column` | string | Header name or A1 letter — **prefer header name** going forward |

### DriveTarget

| Field | Type | Notes |
|-------|------|--------|
| `id` | string | |
| `name` | string | |
| `folderId` | string | Google Drive folder |
| `fieldIds` | string[] | Which file fields land here |

Unmapped **required file/text fields** block **Publish** (gate state), with an actionable error naming the field.

---

## 5. Submission

### Submission (runtime)

| Field | Type |
|-------|------|
| `id` | opaque |
| `portalId` | id |
| `createdAt` | timestamptz |
| `values` | map fieldId → value |
| `files` | map fieldId → stored file metadata |
| `status` | `received` \| `synced` \| `failed` |

### Pipeline (logical)

1. Validate against portal `fields` + selected branches  
2. Persist files (temp → permanent path / Drive)  
3. Append Sheet row(s) per mapping  
4. Email applicant receipt (+ admin notify as configured)  
5. Return receipt URL / success copy  

**v1 UX:** Single-page form + clear validation + confirmation step (review summary) is allowed; legacy two-nonce review flow is **not** required.

Legacy field names (`sub_work_title`, …) may be emitted as an compatibility alias layer during migration only.

---

## 6. Admin wizard

Steps (stable ids):

| id | Purpose |
|----|---------|
| `start` | Create blank or clone template / existing portal |
| `build` | Edit `fields[]` (canvas + live preview of public form) |
| `map` | Bind fields → Sheets / Drive |
| `publish` | Deadline, fee, disclaimers, open gate |

Gate-seal state per step: `open` | `current` | `sealed` (shape + label, not color alone).

**Sealed heuristics (v0.1):**

- **start:** portal exists with title  
- **build:** ≥1 input field beyond empty shell; applicant_pack or equivalent identity present if submissions need contact  
- **map:** every required collectable field has a sheet or drive binding as appropriate (files → drive or sheet link column; text → sheet)  
- **publish:** timezone set if deadline set; agreements configured if required by org defaults  

---

## 7. Public rendering

- Route: `/portal/{slug}` (WP rewrite today)  
- Renders from `fields[]` + tokens; theme provides site chrome only  
- If `!isOpen` and not preview: closed message (no form)  
- If preview (capability): form UI with banner “Preview — not a live submission”  

---

## 8. Templates & clone

- **Clone portal** copies title (suffix “Copy”), `fields`, `mapping` structure (clear or copy spreadsheet ids — **default: copy structure, clear spreadsheet/folder ids** so clones don’t write to the same Sheet), `publish` with deadline cleared.  
- **Start** gallery: built-ins derived from imported real portals (e.g. “Composer prize”, “Call for scores”).  

---

## 9. Legacy import

Importer input: classic post `post_content` shortcodes + post meta.

| Legacy | Target |
|--------|--------|
| `[portal-applicant-information]` | `applicant_pack` |
| `[pb_text …]` | `short_text` |
| `[pb_file accept=pdf …]` | `score_file` / `bio_file` / `file` by accept + label heuristics |
| `[pb_file accept=audio …]` | `recording_file` |
| `[pb_group]` | `group` |
| `[pb_group_selection_wrap*]` | `branch` |
| `[pb_full_span]` / HTML notices | `static_html` |
| `_portal_deadline` / timezone index | `publish` (convert index → IANA) |
| `_portal_record_keeping` JSON | `mapping.sheets` |
| `_portal_file_backups` JSON | `mapping.drive` |
| option `pb_legal_disclaimers` | portal-level or global disclaimer fields |

Import is **idempotent** when re-run on unchanged content (hash content → skip), or writes a new `fields` revision.

After import trust threshold: new portals never write shortcodes; optional dual-read for unmigrated posts.

---

## 10. Storage (implementation mapping)

| Concern | v0.1 on WordPress |
|---------|-------------------|
| Portal title/slug/status | `portal` CPT |
| `fields` + mapping + publish blob | post meta `_portal_definition` JSON (single document) **or** split keys; prefer **one JSON document** for atomic save |
| Legacy meta keys | Read for import; stop writing once migrated |
| Global disclaimers / Google service account | options (existing) |

Atomic save: one `update_post_meta` for definition JSON with proper JSON sanitization (never `sanitize_text_field` on the blob).

---

## 11. Non-goals (v0.1)

- Full Gravity Forms / WPForms parity  
- In-admin submission review inbox (Sheet remains source of truth unless we add it later)  
- Dark mode  
- Multi-language  
- Drag-and-drop form builder marketplace  

---

## 12. Acceptance criteria (rewrite vertical slice)

1. Define a portal **only** via `fields[]` (no shortcodes in content).  
2. Public URL renders that form with tokens.  
3. Preview works for draft and past-deadline portals for editors.  
4. Map step binds fields to Sheet columns; save survives reload.  
5. Importer converts Herbolzheimer-class and Call-for-Scores-class fixtures with manual review checklist.  
6. Local site runs **git** plugin path (`portal-builder` checkout).  

---

## 13. Decision log

| Decision | Choice | Why |
|----------|--------|-----|
| Authoring | Structured fields, not shortcodes | Maintainability + wizard + a11y |
| UI stack | Svelte 5 everywhere for product UI | Already chosen; one language |
| Legacy | Import then delete path | User not locked to shortcodes |
| Preview | Capability-based, ignores closed for editors | Design without fighting deadlines |
| Mapping | Keep Google Sheets/Drive as backends | Core value of the product |
