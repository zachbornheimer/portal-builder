/**
 * Unit tests for seed title rules (no WordPress / Playwright).
 */
import assert from "node:assert/strict";
import test from "node:test";
import { DEFAULT_PORTAL_PREFIX } from "../support/load-env.mjs";
import { buildSeedTitle } from "../support/seed.mjs";

test("buildSeedTitle always starts with prefix", () => {
  const title = buildSeedTitle(DEFAULT_PORTAL_PREFIX, "smoke");
  assert.ok(title.startsWith(DEFAULT_PORTAL_PREFIX));
  assert.match(title, new RegExp(`^${DEFAULT_PORTAL_PREFIX}smoke-\\d+$`));
});

test("buildSeedTitle sanitizes label", () => {
  const title = buildSeedTitle("dg-e2e-", "weird title!!");
  assert.ok(title.startsWith("dg-e2e-"));
  assert.doesNotMatch(title, /!/);
  assert.match(title, /^dg-e2e-weird-title-\d+$/);
});
