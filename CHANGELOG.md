## 0.1.8

### Features
- Show DragonGate seal and changelog on plugin updates

**Full Changelog**: https://github.com/zachbornheimer/portal-builder/compare/v0.1.7...v0.1.8

## 0.1.7

### Features
- Let signed-in administrators see members portals

**Full Changelog**: https://github.com/zachbornheimer/portal-builder/compare/v0.1.6...v0.1.7

## 0.1.6

### Features
- Add view-as preview and receipt From name

### Fixes
- Show every site disclosure on the definition form

### Tests
- Assert live CFS form shows all three disclosures

### Maintenance
- Add mise, Lefthook, aube, and cut-release

**Full Changelog**: https://github.com/zachbornheimer/portal-builder/compare/v0.1.4...v0.1.6

## 0.1.4

### Features
- Make option promote an admin action and use Bits radios

**Full Changelog**: https://github.com/zachbornheimer/portal-builder/compare/v0.1.3...v0.1.4

## 0.1.3

### Features
- Add Bits UI, DG_ prefixes, and pb_ to dg_ option move
- Say on or before a date, or a window like mid-December
- Ship the Claude Design homepage

### Fixes
- Store Turnstile keys as dg_turnstile_* and require them on members
- Give file cards a section break before the next heading
- Prove file bytes before marking a packet file opened
- Stop button hover from flashing

**Full Changelog**: https://github.com/zachbornheimer/portal-builder/compare/v0.1.2...v0.1.3

## 0.1.2

### Fixes
- Drop “call,” cap the gated pane, follow brand shadow

**Full Changelog**: https://github.com/zachbornheimer/portal-builder/compare/v0.1.1...v0.1.2

## 0.1.1

### Fixes
- Confirm files in a modal and ship 0.1.1
- Find GitHub updates when the API is rate-limited

**Full Changelog**: https://github.com/zachbornheimer/portal-builder/compare/v0.1.0...v0.1.1

## 0.1.0

### Features
- Let WordPress find GitHub Release updates
- Let staff and applicants replace a current packet file
- Mask Google secrets, Turnstile gate, and packet console
- Keep per-portal testMode off the mapped production dests
- Keep connect-Google notice until test write succeeds
- Ship semver 0.1.0 and document ZIP upgrade
- Write an operator log row for each definition submit
- Add Google connection probe on Settings
- Recommend a generic starter on Start
- Notify the portal operator on successful submit
- White-label public form from host brand settings
- DragonGate Portals marketing site and waitlist
- Stage public file picks before submit
- Require anonymize certification and send signed receipts
- Live Google dest-header writes and CFS submit path
- Single-step definition submit with MIME gate (P3)
- Mockable submit artifacts for definition fields (3.x)
- Definition renderer, open-state, and public render path (2.1)
- Schema validator and REST get/put (1.1–1.3)
- Design tokens, wizard shell, and save integrity

### Fixes
- Build the plugin ZIP without git-archive-all
- Keep fail-closed and guidelines label on site defaults
- Keep public POST out of legacy Portal_Submission
- Refuse identifying PDFs when anonymize cannot run
- Let vanilla WordPress restrict a portal by role
- Add terms and subprocessors; About claims only shipped behavior
- Label application fee as not charged in this form
- Send portal edits to the setup wizard
- Drop Consortium from product members copy
- Do not show submit success after a public form exception
- Keep public submit errors human and uploads off the web root
- Show one reason-specific closed message
- Match Google API setup copy to OAuth client runtime
- Send supportsAllDrives on every write
- Hide the public seal on the ISJAC white-label preset
- Logo seal, drop privacy line, pill buttons
- Open confirm and app-page links in a new tab
- Use wp_rest nonce for file staging and quiet Processing status
- Drop unprefixed portal-group classes from the public form
- Stop legacy portal.css from stretching public-form radios
- Ship public form tokens from assets so prod is not unstyled
- Pretty-permalink seed for closed-preview query preservation
- Prime closed flag on wp and harden closed-preview e2e (2.2)
- Skip empty Google Drive folder IDs in submission processing

### Tests
- Prove generic host submit writes sheet and receipt
- Harden portal CRUD list search and REST budgets
- Stabilize portal admin create-edit-trash via REST (1.4)
- Assert definition HTML via request fetch (2.1)
- Closed/preview e2e and open-state pure unit tests (2.2)
- Resilient portal CRUD via REST persist (1.4)
- Harden mock Sheet/Drive/mail facades (0.3)
- Definition REST put/get against LocalWP (1.2–1.3)
- Seed/cleanup helpers and full smoke lifecycle (0.2)
- Document env.local and tighten fixture asserts (0.1)
- Playwright smoke against LocalWP admin (0.2)
- File-backed Sheet/Drive/mail mocks and unit runner (0.3)
- Add env assert config and portal fixtures (0.1)

### Documentation
- Packet console, ZIP upgrade, sitemap and robots
- Walk ZIP install through the definition wizard
- Portal domain model contract for full rewrite

### Maintenance
- Npm CI scripts and GH workflow stubs (0.4)
- E2e runbook and phase workflow (0.4)

### Other
- Indent the release ZIP script
- merge: mockable definition submit pipeline (3.x)
- merge: portal CRUD e2e from worktree (1.4)
- merge: public definition render from worktree (2.1)
- Can move tags
- Svelting
- Svelting!
- Things are improving stylistically
- Updated logo
- Fixing labelling issues
- gsuite syntax error fix, asset versioning, and version bump
- Updated gsuite commit
- About page!
- Adding about page
- Attempted rebrand
- Added svg icon
- Adding duplicate feature, code cleanup, and sidebar image
- Fix Add Row button: handle case when 0 rows exist
- Fix tag display: restore {{ }} wrappers for all tags
- Add enhanced record keeping features and fix copy functionality
- Working on the releasing process
- Added missing auth to release
- Updated release-drafter.yml
- Attempted simplification of release
- Updated release workflow
- Renamed the .github/actions => .github/workflows
- Added missing .github dir
- Added missing .gitattributes and .gitignore
- Added a readme and updated license text
- Added missing .gitmodules
- Updated latest version for gsuite-filestore
- Updated system to build!
- Updated stored files
- Changes based on deployment
- Added some style and missing files
- Initial commit
