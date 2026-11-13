/**
 * Display wording for open and close. Actual instants are not formatted here.
 */
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const harness = path.join(root, "tests/support/php-when.php");
const casesPath = path.join(root, "tests/.artifacts/when-cases.json");

const CASES = [
  {
    name: "close display is 11:59 p.m. on the 23rd",
    which: "close",
    part: "phrase",
    publish: {
      deadline: "2026-10-24T04:00",
      deadlineDisplay: "2026-10-23T23:59",
      timezone: "America/New_York",
    },
    expect: "Friday, October 23, 2026, 11:59 p.m. Eastern",
  },
  {
    name: "blank display falls back to the actual close",
    which: "close",
    part: "phrase",
    publish: {
      deadline: "2026-10-24T04:00",
      timezone: "America/New_York",
    },
    expect: "Saturday, October 24, 2026, 4:00 a.m. Eastern",
  },
  {
    name: "midnight open display is the date only",
    which: "open",
    part: "phrase",
    publish: {
      launchAt: "2026-08-15T16:43:10+00:00",
      launchDisplay: "2026-08-17T00:00",
      timezone: "America/New_York",
    },
    expect: "Monday, August 17, 2026",
  },
  {
    name: "date part has no weekday or time",
    which: "close",
    part: "date",
    publish: {
      deadlineDisplay: "2026-10-23T23:59",
      timezone: "America/New_York",
    },
    expect: "October 23, 2026",
  },
];

test("display close is the 23rd at 11:59 p.m., not the 4 a.m. gate", () => {
  fs.mkdirSync(path.dirname(casesPath), { recursive: true });
  fs.writeFileSync(casesPath, JSON.stringify(CASES));
  const r = spawnSync("php", [harness, casesPath], { encoding: "utf8" });
  assert.equal(r.status, 0, (r.stdout || "") + (r.stderr || ""));
  const rows = JSON.parse((r.stdout || "").trim());
  assert.equal(rows.length, CASES.length);
  for (const row of rows) {
    assert.equal(row.phrase, row.expect, row.name);
  }
});
