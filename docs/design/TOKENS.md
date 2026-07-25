# DragonGate Portals — Design Token Contract

**Status:** accepted (v0.1)  
**Source of truth:** this file + `src/tokens.css` (must stay in sync)  
**Consumers:** Svelte admin app (`src/`), `tailwind.config.js` theme.extend  
**Visual refs:** `docs/design/mockups/01–04-*.png`, `docs/design/dragongate-brand-guide.html`

Values are frozen for implementation. Change them here first, then in `src/tokens.css` and Tailwind maps.

---

## Invariants

1. **One accent:** `--ember` is the only chromatic call-to-action. Never decorative fills, page backgrounds, or “pretty” borders.
2. **Light only.** Dark mode is out of scope. `color-scheme: light`.
3. **Warm paper, warm ink.** No pure black (`#000`) or pure white (`#fff`) for brand surfaces.
4. **Semantic ≠ brand.** Success/error are distinct from ember so “done” and “the one action” never look the same.
5. **Contrast policy (AA):**
   - `--ink`, `--ink-600`, `--ember-600`, `--error` → normal text OK on paper surfaces.
   - `--success` (`#3F8A5C`) and `--brass` (`#9C7A3C`) → **large text / icons / decorative only** until darkened (known ~3.6–3.8:1 on paper). Do not use for body copy.
6. **Gate-seal state is never color alone.** Shape differs (hollow ring vs solid disc + check) + accessible name.
7. **Field IDs are secondary.** Human label leads; mono id is muted caption.

---

## Color

| Token | Value | Role |
|-------|-------|------|
| `--ink` | `#12140F` | Primary text; dark chrome (topbar/sidebar) |
| `--ink-800` | `#1C1F17` | Elevated dark chrome |
| `--ink-600` | `#454A3C` | Body text on paper |
| `--ink-400` | `#6E7460` | Help, captions, de-emphasized field IDs |
| `--paper` | `#F7F4EC` | Page ground (parchment) |
| `--paper-50` | `#FCFBF6` | Card / input surface (lightest) |
| `--paper-100` | `#EFEBDD` | Alt ground, tag chips |
| `--ember-100` | `#F4E2D4` | Tinted attention background |
| `--ember` | `#B8501F` | Accent — primary action / sealed complete |
| `--ember-600` | `#963F16` | Hover/active; links on paper |
| `--ember-flow` | `linear-gradient(115deg, #C96A2E 0%, #B8501F 45%, #8B3A1A 100%)` | Accent surfaces only (primary btn, sealed seal) |
| `--brass` | `#9C7A3C` | Eyebrows, unsealed ring — **not body text** |
| `--brass-200` | `#E4D9BE` | Field-type pill fill |
| `--success` | `#3F8A5C` | Confirmation — **not body text** until AA fix |
| `--success-100` | `#E1EEE4` | Success tint surface |
| `--warn` | `var(--brass)` | Warning alias |
| `--error` | `#B8341F` | Validation/destructive (cooler than ember) |
| `--error-100` | `#F4DCD6` | Error tint surface |
| `--rule` | `rgba(18,20,15,0.10)` | Default hairline on paper |
| `--rule-strong` | `rgba(18,20,15,0.20)` | Emphasized border / input border |
| `--rule-ink` | `rgba(247,244,236,0.14)` | Hairline on dark chrome |

### Tailwind map (color)

CSS vars stay canonical. Tailwind names mirror tokens:

| Tailwind | CSS var |
|----------|---------|
| `ink`, `ink-800`, `ink-600`, `ink-400` | as above |
| `paper`, `paper-50`, `paper-100` | as above |
| `ember`, `ember-100`, `ember-600` | as above |
| `brass`, `brass-200` | as above |
| `success`, `success-100`, `error`, `error-100` | as above |

Legacy `zysys-blue` is **deprecated** for new UI; do not use in wizard shell.

---

## Typography

| Token | Value |
|-------|-------|
| `--font-display` | `'Fraunces', Georgia, serif` |
| `--font-ui` | `'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif` |
| `--font-mono` | `'IBM Plex Mono', ui-monospace, 'SF Mono', Menlo, monospace` |
| `--t-display` | `clamp(28px, 3.4vw, 40px)` |
| `--t-h1` | `clamp(22px, 2.4vw, 28px)` |
| `--t-h2` | `20px` |
| `--t-h3` | `16px` |
| `--t-body` | `14px` |
| `--t-small` | `12.5px` |
| `--t-caption` | `11px` |
| `--t-eyebrow` | `10.5px` |
| `--lh-tight` | `1.05` |
| `--lh-snug` | `1.3` |
| `--lh-prose` | `1.55` |

**Usage:** Fraunces = titles / brand lockup. Inter = UI body. IBM Plex Mono = eyebrows, field IDs, step labels, pills.

Fonts: load Inter / Fraunces / IBM Plex Mono in admin (or system fallbacks until font enqueue exists).

---

## Spacing (4px base)

| Token | Value |
|-------|-------|
| `--s-1` … `--s-9` | `4, 8, 12, 16, 24, 32, 48, 64, 96` px |

Tailwind: extend spacing as `dg-1`…`dg-9` **or** use arbitrary `var(--s-N)`. Prefer CSS vars in component CSS; Tailwind for layout utilities.

---

## Radius / shadow / motion / focus

| Token | Value |
|-------|-------|
| `--r-1` | `3px` |
| `--r-2` | `6px` |
| `--r-3` | `10px` |
| `--r-pill` | `999px` |
| `--shadow-card` | `0 1px 2px rgba(18,20,15,0.03), 0 6px 16px -10px rgba(18,20,15,0.16)` |
| `--shadow-shell` | `0 1px 1px rgba(18,20,15,0.04), 0 8px 24px -12px rgba(18,20,15,0.18), 0 32px 64px -24px rgba(18,20,15,0.22)` |
| `--shadow-ember` | `0 1px 1px rgba(184,80,31,0.25), 0 6px 16px -6px rgba(184,80,31,0.55)` |
| `--ease` | `cubic-bezier(0.2, 0.8, 0.2, 1)` |
| `--dur-fast` | `120ms` |
| `--dur-base` | `180ms` |
| `--focus-ring` | `0 0 0 2px var(--paper-50), 0 0 0 4px var(--ember)` |

Focus ring is mandatory on interactive elements; never `outline: none` without an equivalent.

---

## Wizard shell layout constants

| Token / constant | Value | Role |
|------------------|-------|------|
| `--wizard-sidebar-width` | `236px` | Gate checklist column |
| `--wizard-topbar-height` | `44px` | Dark brand topbar |
| Steps | `start`, `build`, `map`, `publish` | Stable step ids |

Gate states: `open` | `current` | `sealed` (shape + label, not color alone).

---

## Paper grain (optional surface)

Subtle noise + corner ember wash is part of the mock aesthetic. Implementation:

- Base: `--paper-100`
- Optional overlay: radial `rgba(184,80,31,0.09)` at bottom-right + grain SVG (see mockups `body` background)
- Grain is decorative; contrast must hold without it

---

## Anti-patterns

- Raw hex in new Svelte components (use tokens)
- Using `zysys-blue` in wizard or new admin surfaces
- Ember as page-wide background
- Brass/success as 14px body text without AA fix
- Color-only complete/incomplete indicators

---

## Sync checklist

When changing a token:

1. Update this file
2. Update `src/tokens.css`
3. Update `tailwind.config.js` maps if color/spacing names change
4. Rebuild `npm run build` and spot-check mockup screenshots vs UI
