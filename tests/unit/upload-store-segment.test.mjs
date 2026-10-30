import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
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

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'dg-store-'));
const blocker = path.join(tmp, 'blocker');
fs.writeFileSync(blocker, 'a regular file: nothing can be created beneath it');
test.after(() => fs.rmSync(tmp, { recursive: true, force: true }));

function dataDir({ dataRoot, abspath, basedir }) {
  const code = `
ini_set('display_errors', '0');
require ${JSON.stringify(path.join(root, 'includes/Submission/class-portal-files.php'))};
require ${JSON.stringify(path.join(root, 'includes/Submission/class-portal-upload-store.php'))};
${dataRoot ? `define('DG_DATA_ROOT', ${JSON.stringify(dataRoot)});` : ''}
${abspath ? `define('ABSPATH', ${JSON.stringify(abspath)});` : ''}
Portal_Upload_Store::set_basedir(${JSON.stringify(basedir)});
echo Portal_Upload_Store::data_dir('dg-logs'), PHP_EOL, Portal_Upload_Store::site_segment(), PHP_EOL;
`;
  const run = spawnSync('php', ['-r', code], { cwd: root, encoding: 'utf8' });
  assert.equal(run.status, 0, run.stderr);
  const [dir, segment] = run.stdout.trim().split('\n');
  return { dir, segment, stderr: run.stderr };
}

test('data dir uses a writable DG_DATA_ROOT and stays quiet', () => {
  const dataRoot = path.join(tmp, 'private');
  const { dir, stderr } = dataDir({ dataRoot, abspath: path.join(tmp, 'site/'), basedir: path.join(tmp, 'uploads') });
  assert.equal(dir, path.join(dataRoot, 'dg-logs'));
  assert.ok(fs.existsSync(path.join(dir, '.htaccess')));
  assert.doesNotMatch(stderr, /Portal Data Store/);
});

test('data dir falls back to the off-docroot root when DG_DATA_ROOT cannot be created', () => {
  const { dir, segment, stderr } = dataDir({
    dataRoot: path.join(blocker, 'root'),
    abspath: path.join(tmp, 'site/'),
    basedir: path.join(tmp, 'uploads'),
  });
  assert.equal(dir, path.join(tmp, `dg-data-${segment}`, 'dg-logs'));
  assert.ok(fs.existsSync(path.join(dir, '.htaccess')));
  assert.match(stderr, /Portal Data Store: DG_DATA_ROOT .* cannot be created or written for dg-logs/);
  assert.match(stderr, /Portal Data Store: Using .*dg-data-/);
});

test('data dir falls back to uploads with deny stubs and a web-served warning', () => {
  const { dir, segment, stderr } = dataDir({
    dataRoot: path.join(blocker, 'root'),
    abspath: path.join(blocker, 'site/'),
    basedir: path.join(tmp, 'uploads'),
  });
  assert.equal(dir, path.join(tmp, 'uploads', `dg-${segment}`, 'dg-logs'));
  assert.ok(fs.existsSync(path.join(dir, '.htaccess')));
  assert.ok(fs.existsSync(path.join(dir, 'index.php')));
  assert.match(stderr, /Off-docroot root .* cannot be created or written/);
  assert.match(stderr, /nginx will serve it/);
});

test('data dir logs loudly when no root is usable', () => {
  const { dir, segment, stderr } = dataDir({
    dataRoot: path.join(blocker, 'root'),
    abspath: path.join(blocker, 'site/'),
    basedir: path.join(blocker, 'uploads'),
  });
  assert.equal(dir, path.join(blocker, 'uploads', `dg-${segment}`, 'dg-logs'));
  assert.match(stderr, /Uploads root .* cannot be created or written/);
  assert.match(stderr, /No usable data root for dg-logs; its rows are not being persisted/);
});
