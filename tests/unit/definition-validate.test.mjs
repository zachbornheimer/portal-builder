/**
 * Spawns PHP CLI validator harness (no full WordPress).
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const harness = path.join(root, 'tests/support/php-validate-definition.php');
const fixture = path.join(root, 'tests/fixtures/portals/herbolzheimer.definition.json');

function runPhp(jsonPath) {
  const r = spawnSync('php', [harness, jsonPath], { encoding: 'utf8' });
  return { code: r.status, out: (r.stdout || '') + (r.stderr || '') };
}

test('valid herbolzheimer fixture validates', () => {
  const { code, out } = runPhp(fixture);
  assert.equal(code, 0, out);
  const data = JSON.parse(out.trim());
  assert.equal(data.ok, true);
  assert.ok(Array.isArray(data.definition.fields));
  assert.equal(data.definition.fields[0].type, 'applicant_pack');
});

test('unknown field type is rejected', () => {
  const bad = path.join(root, 'tests/.artifacts/bad-definition.json');
  fs.mkdirSync(path.dirname(bad), { recursive: true });
  fs.writeFileSync(
    bad,
    JSON.stringify({
      version: 1,
      fields: [{ id: 'x', type: 'not_a_type', label: 'X' }],
    })
  );
  const { code, out } = runPhp(bad);
  assert.notEqual(code, 0, out);
  assert.match(out, /dg_definition_field_type|Unknown field type/i);
});

test('duplicate field ids rejected', () => {
  const bad = path.join(root, 'tests/.artifacts/dup-definition.json');
  fs.writeFileSync(
    bad,
    JSON.stringify({
      version: 1,
      fields: [
        { id: 'a', type: 'short_text', label: 'A' },
        { id: 'a', type: 'short_text', label: 'A2' },
      ],
    })
  );
  const { code, out } = runPhp(bad);
  assert.notEqual(code, 0, out);
});

test('sheet roles and anonymizeEndpoint persist', () => {
  const file = path.join(root, 'tests/.artifacts/roles-endpoint-definition.json');
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(
    file,
    JSON.stringify({
      version: 1,
      title: 'Dohnányi',
      fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
      mapping: {
        sheets: [
          {
            id: 'sheet_housekeeping',
            name: 'Housekeeping',
            role: 'housekeeping',
            spreadsheetId: '1a2B3c4D5e6F7g8H9i0Jklmnopqrstuvwx',
          },
          {
            id: 'sheet_adjudicator',
            name: 'Adjudicator',
            role: 'adjudicator',
            spreadsheetId: '1zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz',
          },
        ],
        drive: [
          {
            id: 'drive_submissions',
            name: 'Submissions',
            folderId: '1folderfolderfolderfolderfoldr',
          },
        ],
      },
      options: {
        anonymize: true,
        anonymizeEndpoint: 'https://anon.example.com/v1/strip',
        anonymizeApiKey: 'anon_test_fixture_key',
        freeForMembers: true,
        freeMembershipPlanIds: ['17214'],
      },
      publish: {
        enabled: false,
        forceClosed: false,
        launchAt: '2026-09-01T00:00:00',
        timezone: 'America/New_York',
      },
      access: {
        audience: 'members',
        membershipPlanIds: ['17276'],
        profileRules: [{ key: 'COUNTRY', op: 'eq', value: 'Canada' }],
        denyMessage: 'Members in Canada only.',
      },
    })
  );
  const { code, out } = runPhp(file);
  assert.equal(code, 0, out);
  const data = JSON.parse(out.trim());
  assert.equal(data.ok, true);
  assert.equal(data.definition.mapping.sheets[0].role, 'housekeeping');
  assert.equal(data.definition.mapping.sheets[1].role, 'adjudicator');
  assert.equal(data.definition.options.anonymize, true);
  assert.equal(
    data.definition.options.anonymizeEndpoint,
    'https://anon.example.com/v1/strip'
  );
  assert.equal(data.definition.options.anonymizeApiKey, 'anon_test_fixture_key');
  assert.equal(data.definition.publish.enabled, false);
  assert.equal(data.definition.publish.forceClosed, true);
  assert.equal(data.definition.publish.launchAt, '2026-09-01T00:00:00');
  assert.equal(data.definition.options.freeForMembers, true);
  assert.deepEqual(data.definition.options.freeMembershipPlanIds, ['17214']);
  assert.equal(data.definition.access.audience, 'members');
  assert.deepEqual(data.definition.access.membershipPlanIds, ['17276']);
  assert.equal(data.definition.access.profileRules[0].key, 'COUNTRY');
  assert.equal(data.definition.access.denyMessage, 'Members in Canada only.');
});

test('empty anonymizeEndpoint persists as null', () => {
  const file = path.join(root, 'tests/.artifacts/empty-endpoint-definition.json');
  fs.writeFileSync(
    file,
    JSON.stringify({
      version: 1,
      fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
      options: { anonymize: true, anonymizeEndpoint: '', anonymizeApiKey: '' },
      publish: { enabled: true, forceClosed: true, launchAt: '' },
    })
  );
  const { code, out } = runPhp(file);
  assert.equal(code, 0, out);
  const data = JSON.parse(out.trim());
  assert.equal(data.definition.options.anonymizeEndpoint, null);
  assert.equal(data.definition.options.anonymizeApiKey, null);
  assert.equal(data.definition.publish.enabled, true);
  assert.equal(data.definition.publish.forceClosed, false);
  assert.equal(data.definition.publish.launchAt, null);
});

test('anonymizeAck persists as nullable string', () => {
  const withText = path.join(root, 'tests/.artifacts/anonymize-ack-text.json');
  fs.writeFileSync(
    withText,
    JSON.stringify({
      version: 1,
      fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
      options: {
        anonymize: true,
        anonymizeAck: 'Portal-level certification sentence.',
      },
    }),
  );
  const on = runPhp(withText);
  assert.equal(on.code, 0, on.out);
  const onData = JSON.parse(on.out.trim());
  assert.equal(onData.definition.options.anonymizeAck, 'Portal-level certification sentence.');

  const empty = path.join(root, 'tests/.artifacts/anonymize-ack-empty.json');
  fs.writeFileSync(
    empty,
    JSON.stringify({
      version: 1,
      fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
      options: { anonymize: true, anonymizeAck: '' },
    }),
  );
  const off = runPhp(empty);
  assert.equal(off.code, 0, off.out);
  const offData = JSON.parse(off.out.trim());
  assert.equal(offData.definition.options.anonymizeAck, null);
});

test('legacy forceClosed dual-writes enabled false', () => {
  const file = path.join(root, 'tests/.artifacts/legacy-force-closed.json');
  fs.writeFileSync(
    file,
    JSON.stringify({
      version: 1,
      fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
      publish: { forceClosed: true },
    })
  );
  const { code, out } = runPhp(file);
  assert.equal(code, 0, out);
  const data = JSON.parse(out.trim());
  assert.equal(data.definition.publish.enabled, false);
  assert.equal(data.definition.publish.forceClosed, true);
  assert.equal(data.definition.publish.launchAt, null);
});

test('public render source does not echo anonymizeApiKey', () => {
  const renderSrc = fs.readFileSync(
    path.join(root, 'includes/Definition/class-portal-public-render.php'),
    'utf8'
  );
  assert.doesNotMatch(renderSrc, /anonymizeApiKey/);
  assert.doesNotMatch(renderSrc, /json_encode\s*\(\s*\$options/);
  assert.doesNotMatch(renderSrc, /wp_json_encode\s*\(\s*\$options/);
});

test('call-for-scores definition validates', () => {
  const cfs = path.join(root, 'tests/fixtures/portals/call-for-scores.definition.json');
  const { code, out } = runPhp(cfs);
  assert.equal(code, 0, out);
  const data = JSON.parse(out.trim());
  assert.equal(data.ok, true);
  const category = data.definition.fields.find((f) => f.id === 'category');
  assert.equal(category.type, 'branch');
  assert.ok(category.options.some((o) => o.id === 'scores'));
});

test('branch option missing id is rejected', () => {
  const bad = path.join(root, 'tests/.artifacts/bad-branch-option.json');
  fs.mkdirSync(path.dirname(bad), { recursive: true });
  fs.writeFileSync(
    bad,
    JSON.stringify({
      version: 1,
      fields: [
        {
          id: 'category',
          type: 'branch',
          label: 'Category',
          required: true,
          options: [
            { label: 'Poster', children: [] },
            { id: 'papers', label: 'Papers', children: [] },
          ],
        },
      ],
    })
  );
  const { code, out } = runPhp(bad);
  assert.notEqual(code, 0, out);
  assert.match(out, /dg_definition_branch|Invalid branch option/i);
});

test('old un-roled sheet id is kept; invalid role does not crash', () => {
  const file = path.join(root, 'tests/.artifacts/legacy-sheet-definition.json');
  fs.writeFileSync(
    file,
    JSON.stringify({
      version: 1,
      fields: [{ id: 'work_title', type: 'short_text', label: 'Title' }],
      mapping: {
        sheets: [
          { id: 'sheet_main', name: 'All Records', spreadsheetId: 'kept-id-value-here-20xx' },
          { id: 'sheet_bad', name: 'Nope', role: 'not-a-role', spreadsheetId: 'x' },
        ],
      },
    })
  );
  const { code, out } = runPhp(file);
  assert.equal(code, 0, out);
  const data = JSON.parse(out.trim());
  assert.equal(data.definition.mapping.sheets[0].spreadsheetId, 'kept-id-value-here-20xx');
  assert.equal(data.definition.mapping.sheets[0].id, 'sheet_main');
  assert.ok(!data.definition.mapping.sheets[1].role);
});
