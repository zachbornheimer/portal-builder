# DragonGate — public application form
## Full brief for Grok Design (zero prior context)

You are designing the **applicant-facing form** for DragonGate Portals. You have no other project files. Everything you need is in this brief. Do not invent a new brand, a new color system, or a new product.

This pass is **inspiration**, not production code. After we pick a direction, a human will ask another agent to turn it into HTML in `docs/design/mockups/`.

---

## 1. What this product is

**DragonGate Portals** is a WordPress plugin. Festival and conservatory staff (often non-technical volunteers) build **application portals**: one public form per opportunity.

Applicants (composers, performers, students) open a URL, fill the form, upload scores/recordings, and submit. Files go to Google Drive. A row goes to Google Sheets. They get a receipt.

This is **not** a generic SaaS signup, not a checkout, not a government PDF, not Acceptd (the competitor — cluttered, institutional, exhausting). It is closer to a **warm, serious application packet** you would be proud to send a prize committee.

**Canonical example for this brief:** the **Ernst von Dohnányi / Herbolzheimer-class Composer Prize**.

- Opportunity name: `Ernst von Dohnányi Composer Prize`
- Org context: a jazz / concert-music festival or conservatory (think ISJAC — International Society of Jazz Arrangers & Composers). The **site** may have its own header (logo, nav, donate). You are designing the **application itself** that sits in the page, not the whole festival website.

**Brand idea (already locked):** “the door that opens for the right applicant.” A **gate / portal**, with a dragon as **guardian of the seal**, not a game-boss illustration. Circular seal: open doors or pillars, flame in the threshold, dragon framing. Ember + brass on paper.

---

## 2. Job of this design

Make an applicant feel:

1. **This is the real opportunity** — not a WordPress leftover.
2. **I know what to do next** — three states only: waiting for me, working, done.
3. **I will not lose my work** — honest errors, obvious required fields, one submit.

The current live form **fails this**. It looks like a 2008 admin settings table dropped into a theme: right-aligned labels, OS file pickers, a gray “Note” box, “Application Form for:”, Custom Sidebars junk, a default blue Submit. That is the thing we are replacing.

---

## 3. What to design (and what not to)

### In scope — produce these screens

Use **one visual language**. You may explore **two or three low-fidelity directions** (layout / density / how files feel). Do **not** produce six polished alternatives. We will pick one direction, then mock it in HTML.

| # | Screen | Must include |
|---|--------|----------------|
| A | **Open application** | Full form, ready to fill. Desktop **and** 360px. |
| B | **Validation / error** | Same form after a failed submit: missing name, missing score PDF, invalid email. Errors in human language next to the field. |
| C | **Uploading** | Score or recording in flight. User must see the system is working. |
| D | **Preview (staff only)** | Same as A, plus an honest banner: “Preview — not a live submission.” |
| E | **Closed** | Deadline passed. Title remains. One sentence why. **No fields.** |
| F | **Receipt** | After success. Application ID, what they sent, what happens next, notification date if any. |

### Out of scope

- Admin wizard (Start / Build / Map / Publish) — already designed
- WordPress dashboard chrome
- Dark mode
- Multi-page “wizard” for the applicant (v1 is **one page**, one submit)
- Payment UI (fee may be mentioned in copy; do not design a checkout)
- Login / account creation (applicants are **not** required to have an account)

---

## 4. Content to design against (do not use lorem)

### Opportunity

- Eyebrow: `Application`
- Title: `Ernst von Dohnányi Composer Prize`
- Optional guidelines link: `Read the call` → `https://example.org/dohnanyi-guidelines`
- Deadline line (when set): `Closes 15 September 2026, 11:59 pm America/New York`
- Fee line (when set): `Application fee $25` · if members free: `Free for members`
- Intro (one or two sentences, not a wall):  
  `Submit one original work. PDF score and MP3 recording required. You will receive a receipt by email.`

### Applicant (persona)

- **Elena Varga**, composer, Brooklyn NY
- Email `elena.varga@example.com`
- Affiliation `Manhattan School of Music` (optional)
- Address plausible US address
- Phone `(917) 555-0142`
- Work title `Night Train, After Gil`
- Files: `Varga_NightTrain_SCORE.pdf`, `Varga_NightTrain_REC.mp3`

### Field inventory (this is the form)

**Section — Your information** (`applicant_pack`, required)  
Stacked fields, **label above input**, never a two-column admin table:

| Label | Type | Required | Notes |
|-------|------|----------|--------|
| Title | short text | yes | honorific, short (`Ms.`, `Dr.`) |
| Name | text | yes | |
| Email address | email | yes | |
| Institutional affiliation | text | no | caption: `(if any)` |
| Address (home/work) | text | yes | |
| City | text | yes | |
| Country | select | yes | |
| State / region | select | yes | depends on country |
| Zip / postal code | text | no | |
| Phone | tel | yes | |

**Section — Submission** (group)

| Label | Type | Required | Help |
|-------|------|----------|------|
| Title of work | short text | yes | |
| Full score | PDF file | yes | `PDF only` |
| Recording | MP3 file | yes | `MP3 only` |

**After fields, before submit**

- Disclaimer (required checkbox) with short legal text, e.g.  
  `I certify that this work is my own and that I have the right to submit it.`
- Optional second disclaimer (recording permission) — keep secondary, not a gray dump.

**Primary action (one):** `Submit application`  
No “Continue” two-step. No second “Submit” later.

**Receipt copy**

- `Application received`
- `Application ID: DG-2026-08-14-1842`
- `We emailed a copy to elena.varga@example.com.`
- `Decisions by 1 November 2026.` (from `applicantNotificationDate`)

**Closed copy**

- Deadline: `The application deadline has passed.`
- Force-closed: `This portal is closed.`
- Draft/not published: `This portal is not currently accepting applications.`

**Errors (plain language)**

- `Enter your name.`
- `Enter a valid email so we can send your receipt.`
- `Add a PDF score.`
- `Recording must be an MP3.`
- Never `401`, `required field`, or stack traces.

---

## 5. Visual system (locked — do not replace)

Light only. Warm paper, warm ink. **One chromatic accent: ember.** Brass is decorative (eyebrows, rings), never body text. Success green and error red are semantic, not brand.

### Color

| Token | Hex | Use |
|-------|-----|-----|
| ink | `#12140F` | Titles, primary text |
| ink-800 | `#1C1F17` | Elevated dark (rare on public) |
| ink-600 | `#454A3C` | Body |
| ink-400 | `#6E7460` | Help, captions |
| paper | `#F7F4EC` | Page ground |
| paper-50 | `#FCFBF6` | Card / input fill |
| paper-100 | `#EFEBDD` | Alt ground, chips |
| ember-100 | `#F4E2D4` | Soft attention |
| ember | `#B8501F` | The one action, focus, sealed |
| ember-600 | `#963F16` | Hover / links on paper |
| ember-flow | `linear-gradient(115deg, #C96A2E 0%, #B8501F 45%, #8B3A1A 100%)` | Primary button fill only |
| brass | `#9C7A3C` | Eyebrows, unsealed rings — **not 14px body** |
| brass-200 | `#E4D9BE` | Soft pills |
| success | `#3F8A5C` | Receipt confirmation — large/icon, not small body |
| success-100 | `#E1EEE4` | Receipt tint |
| error | `#B8341F` | Validation (cooler than ember) |
| error-100 | `#F4DCD6` | Error field tint |
| rule | `rgba(18,20,15,0.10)` | Hairline |
| rule-strong | `rgba(18,20,15,0.20)` | Input border |

**No** `#000`, **no** `#fff` as brand surfaces. **No** Tailwind sky/indigo/blue CTAs. **No** Acceptd teal, **no** generic purple SaaS.

Optional page atmosphere: faint paper grain + a **small** ember radial wash in a far corner. Contrast must hold with grain off.

### Type

| Role | Stack | Size |
|------|--------|------|
| Display / opportunity title | Fraunces, Georgia, serif | `clamp(28px, 3.4vw, 40px)` |
| Section titles | Fraunces | 20px |
| Field labels, body, button | Inter, system-ui, sans | 14px body, labels 14px semibold |
| Eyebrows, deadlines, IDs | IBM Plex Mono, ui-monospace | 10.5–11px, letter-spacing ~0.08em, uppercase for eyebrows |
| Help captions | Inter | 12.5px, ink-400 |

Line-height: titles tight (~1.05–1.2), body 1.55.

### Space / radius / motion / focus

Spacing scale (4px): 4, 8, 12, 16, 24, 32, 48, 64, 96.

Radius: 3 / 6 / 10 / pill 999.

Card shadow: `0 1px 2px rgba(18,20,15,0.03), 0 6px 16px -10px rgba(18,20,15,0.16)`  
Button ember shadow (optional): `0 1px 1px rgba(184,80,31,0.25), 0 6px 16px -6px rgba(184,80,31,0.55)`

Ease: `cubic-bezier(0.2, 0.8, 0.2, 1)`. Fast 120ms, base 180ms.

**Focus ring (mandatory):** `0 0 0 2px #FCFBF6, 0 0 0 4px #B8501F`. Never `outline: none` without this.

### Mark

If you show a logo: circular **seal** — gate / pillars / threshold, dragon as frame, flame as “open.” Flat, spare. Not a tablet. Not a photoreal dragon. Not a wordmark lockup. Public form may omit the mark if the **site header** already has org branding; a small seal near the eyebrow is enough.

---

## 6. Interaction and UX rules (non-negotiable)

1. **Always-show-state.** Waiting / working / done. Upload and submit must change the button or show a spinner **immediately**.
2. **One page.** Progressive disclosure inside the page is fine (e.g. country → region). Do not invent a 4-step applicant wizard.
3. **Labels above fields.** Full width on a measure ~36–42rem. On desktop you may use a **2-column grid only inside** the address block (city / region), never a label-left settings table.
4. **Required** is a clear mark on the label (`*` or “Required”), not color alone.
5. **Files** are first-class. Design a drop zone: type (PDF / MP3), size hint (e.g. keep under 32 MB), chosen filename, replace, and an uploading state. Do not rely on raw OS `<input type=file>` chrome as the visual.
6. **Legal last.** Disclaimers after the work, before submit. Not a gray dump in the middle.
7. **Preview is honest.** Staff preview must be visually distinct and say it will not create a real submission.
8. **Closed is empty of fields.** Do not disable a full form; remove it.
9. **Accessibility.** Semantic headings, labels tied to controls, errors referenced with `aria-describedby` / `role="alert"`, keyboard through drop zones, 44px-class hit targets on submit.
10. **The site may wrap you.** Assume a festival header + footer exist. Your application should still read as a complete object (a packet) in the middle — typically one paper card or a short stack of cards, not full-bleed admin.

---

## 7. Layout hints (starting point, not a prison)

A direction that will implement cleanly:

```
[ optional site header ]

        APPLICATION                    ← mono eyebrow, brass
        Ernst von Dohnányi
        Composer Prize                 ← Fraunces
        Closes 15 Sep 2026 · $25       ← mono, ink-400
        Read the call                  ← ember-600 link

        ┌ paper-50 card ─────────────┐
        │ Your information           │
        │  stacked fields            │
        │ Submission                 │
        │  title + two drop zones    │
        │ ☐ I certify…               │
        │ [ Submit application ]     │  ← ember-flow pill
        └────────────────────────────┘

[ optional site footer ]
```

Other valid directions to explore (pick among these, don’t explode):

- **Packet:** one tall paper sheet, like a physical application.
- **Movements:** identity card, then work card, then legal + submit — still one scroll, one submit.
- **Threshold:** a slim seal + title, then a very quiet field stack (more editorial, less “app”).

Do not explore: multi-step stepper, modal-heavy upload, chatbot, dark concert-poster, skeuomorphic parchment scroll.

---

## 8. Anti-references (do not look like these)

- Current live form: WordPress table, right-aligned labels, blue Bootstrap button, gray note box, “Application Form for:”
- Acceptd: dense, institutional, too many chrome layers
- Google Forms / Microsoft Forms
- Stripe Checkout (wrong metaphor) — **do** steal its calm focus and one-action honesty
- Fantasy game store pages, D&D character sheets
- Purple/indigo “AI startup” gradients

**Do steal the feeling of:** a well-set prize circular; a conservatory poster that was actually typeset; Stripe’s “you always know what’s happening”; a good paper form you could fill with a fountain pen.

---

## 9. What to deliver from this Design pass

1. **2–3 directional frames** of screen A (open application) — different structure, same tokens. Annotate in one sentence what the idea is (“packet” / “movements” / “threshold”).
2. **One chosen direction** taken through screens B–F (error, uploading, preview, closed, receipt). Same type, same button, same measure.
3. **Desktop (~960–1200) and a 360-wide frame** of the chosen open application.
4. Short notes: how the drop zone works; how preview differs from live; what you refused.

Do **not** output production React/Svelte. Do **not** invent extra features (save draft for applicants, accounts, chat). Do **not** retoken the palette.

When you are done, we will lock one direction and build a static HTML mockup from it.
