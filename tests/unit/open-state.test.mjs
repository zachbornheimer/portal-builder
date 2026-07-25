/**
 * Unit tests for Portal_Open_State pure is_open logic (PHP CLI harness).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-open-state.php');
const casesPath = path.join(root, 'tests/.artifacts/open-state-cases.json');

const CASES = [
  {
    name: 'published no deadline is open',
    publish: { deadline: null, timezone: 'America/New_York', forceClosed: false },
    post_status: 'publish',
    now: '2026-06-01T12:00:00',
    expect_open: true,
  },
  {
    name: 'forceClosed is closed',
    publish: { deadline: null, timezone: 'America/New_York', forceClosed: true },
    post_status: 'publish',
    now: '2026-06-01T12:00:00',
    expect_open: false,
  },
  {
    name: 'draft is closed even without deadline',
    publish: { deadline: null, timezone: 'America/New_York', forceClosed: false },
    post_status: 'draft',
    now: '2026-06-01T12:00:00',
    expect_open: false,
  },
  {
    name: 'past deadline is closed',
    publish: {
      deadline: '2020-01-01T00:00:00',
      timezone: 'America/New_York',
      forceClosed: false,
    },
    post_status: 'publish',
    now: '2026-06-01T12:00:00',
    expect_open: false,
  },
  {
    name: 'future deadline is open',
    publish: {
      deadline: '2030-12-31T23:59:59',
      timezone: 'America/New_York',
      forceClosed: false,
    },
    post_status: 'publish',
    now: '2026-06-01T12:00:00',
    expect_open: true,
  },
  {
    name: 'exactly at deadline is open (inclusive)',
    publish: {
      deadline: '2026-06-01T12:00:00',
      timezone: 'America/New_York',
      forceClosed: false,
    },
    post_status: 'publish',
    now: '2026-06-01T12:00:00',
    expect_open: true,
  },
  {
    name: 'one second after deadline is closed',
    publish: {
      deadline: '2026-06-01T12:00:00',
      timezone: 'America/New_York',
      forceClosed: false,
    },
    post_status: 'publish',
    now: '2026-06-01T12:00:01',
    expect_open: false,
  },
];

function runCases(cases) {
  fs.mkdirSync(path.dirname(casesPath), { recursive: true });
  fs.writeFileSync(casesPath, JSON.stringify(cases, null, 2));
  const r = spawnSync('php', [harness, casesPath], { encoding: 'utf8' });
  return {
    code: r.status,
    out: (r.stdout || '') + (r.stderr || ''),
  };
}

test('open-state matrix matches expectations', () => {
  const { code, out } = runCases(CASES);
  assert.equal(code, 0, out);
  const data = JSON.parse(out.trim());
  assert.equal(data.ok, true);
  assert.equal(data.failed, 0);
  assert.equal(data.results.length, CASES.length);
});

test('forceClosed wins over future deadline', () => {
  const { code, out } = runCases([
    {
      name: 'force + future',
      publish: {
        deadline: '2030-01-01T00:00:00',
        timezone: 'UTC',
        forceClosed: true,
      },
      post_status: 'publish',
      now: '2026-01-01T00:00:00',
      expect_open: false,
    },
  ]);
  assert.equal(code, 0, out);
});
