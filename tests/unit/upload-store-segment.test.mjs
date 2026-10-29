import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const php = `
require ${JSON.stringify(path.join(root, 'includes/Submission/class-portal-files.php'))};
require ${JSON.stringify(path.join(root, 'includes/Submission/class-portal-upload-store.php'))};
echo Portal_Upload_Store::site_segment(), PHP_EOL;
`;

test('site segment is deterministic and safely shaped', () => {
  const run = () => spawnSync('php', ['-r', php], {
    cwd: root,
    encoding: 'utf8',
  });
  const first = run();
  const second = run();
  assert.equal(first.status, 0, first.stderr);
  assert.equal(second.status, 0, second.stderr);
  assert.equal(first.stdout, second.stdout);
  assert.match(first.stdout.trim(), /^[0-9a-f]{16}$/);
});
