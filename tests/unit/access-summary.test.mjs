/**
 * Who-may-submit sentence names saved plan ids from the catalog.
 */
import assert from "node:assert/strict";
import test from "node:test";
import { accessSummary } from "../../src/wizard/definitionModel.js";

const catalog = {
  membershipPlans: [
    { id: "17276", name: "ISJAC Membership" },
    { id: "17214", name: "Student Membership" },
    { id: "17213", name: "Community Member" },
  ],
};

test("saved member plans are named, and the other plans are excluded", () => {
  const summary = accessSummary(
    { audience: "members", membershipPlanIds: ["17214", "17276"] },
    catalog,
  );
  assert.equal(
    summary.headline,
    "Student Membership and ISJAC Membership can submit. Community Member cannot.",
  );
  assert.deepEqual(summary.allowed, ["Student Membership", "ISJAC Membership"]);
  assert.deepEqual(summary.excluded, ["Community Member"]);
});

test("numeric plan ids still match catalog strings", () => {
  const summary = accessSummary(
    { audience: "members", membershipPlanIds: [17214] },
    catalog,
  );
  assert.equal(summary.headline, "Student Membership can submit. ISJAC Membership and Community Member cannot.");
});

test("members with no plan ids means any active membership", () => {
  const summary = accessSummary({ audience: "members", membershipPlanIds: [] }, catalog);
  assert.equal(summary.headline, "Any active member can submit.");
});

test("anyone and signed-in do not invent a plan list", () => {
  assert.equal(accessSummary({ audience: "anyone" }, catalog).headline, "Anyone can submit.");
  assert.equal(
    accessSummary({ audience: "logged_in" }, catalog).headline,
    "Signed-in users can submit.",
  );
});
