# Close all DragonGate release-gap issues

> Linear project: [DragonGate Portals](https://linear.app/zysys/project/dragongate-portals-974620717bb6)
> Plan doc: [Close all release-gap issues](https://linear.app/zysys/document/plan-close-all-release-gap-issues-a919c0870e14)
> Issues: ZYS-613 … ZYS-636. Mark Done only after Verify evidence.

**Goal:** Every stranger-org release gap is implemented, tested, and Done in Linear.

**Architecture:** Four waves. Wave 1 is ISJAC-shared reliability (parallel). Later waves follow hard deps: 615 after 613+616; 617 after 614; 624 after 615; 631 after 627; 636 after 625.

**Tech stack:** WordPress plugin (PHP 8), Svelte 5 wizard, Node test harnesses (`tests/unit/*.test.mjs` + `tests/support/php-*.php`), `npm run ci:offline`.

## Global constraints

- Do not commit secrets. Do not write to production ISJAC sheets except an explicit test-write ID.
- Definition pipeline is the real submit path. Do not revive shortcode submit as the owner.
- Names describe WHAT. Facades at I/O. Red→green tests. `npm run test:unit` green on touched tests.
- Worktrees only. Merge to the session checkout after each wave.

## Wave 1 — ISJAC-shared reliability

Parallel (no hard deps):

| ID | Outcome | Owner files |
|----|---------|-------------|
| 613 | Every Drive create/list on submit sends `supportsAllDrives => true` | `gsuite-filestore/zysys-file-store.class.php` |
| 614 | Public POST never `wp_die`s raw Google/store errors | `portal-builder.php`, `class-portal-submission.php` |
| 616 | Google setup copy is OAuth client JSON + access token | `class-portal-settings.php` |
| 618 | Cron deletes staged tmp older than 24h | `portal-builder.php`, `class-portal-staged-file.php` |
| 619 | Tmp/permanent uploads leave `ABSPATH` web root | `portal-builder.php`, file-handler |
| 620 | Operator notify mail on successful submit (fail-open) | `class-portal-mailer.php`, pipeline, settings |
| 621 | One reason-specific closed message; shortcodes silent | public-render + agreements/upload-notes shortcodes |

Then: 615 (test write) after 613+616; 617 (operator log) after 614.

## Wave 2 — Operator onboarding

622 wizard-only edit · 623 README · 624 activate checklist (after 615) · 625 generic starter · 626 Consortium leftovers

## Wave 3 — Access, money, identity

627 fee honest or collect · 628 members without Woo · 629 anonymize fail-visible · 630 Turnstile · 631 retire legacy pipeline (after 627)

## Wave 4 — Stranger-org release

632 hide Google JSON · 633 semver/update channel · 634 terms/DPA/About · 635 operator test mode · 636 CI generic host (after 625)

## Execution

Subagent-driven, one worktree per parallel group. Orchestrator merges, runs `npm run test:unit`, comments Verify evidence on each Linear issue, then sets state Done.
