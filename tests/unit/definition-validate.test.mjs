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
