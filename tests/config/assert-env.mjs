#!/usr/bin/env node
/**
 * Assert Local/CI harness config is coherent before e2e.
 * Exit 0 only when required paths, symlink target, and baseUrl respond.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '../..');
const localPath = path.join(__dirname, 'env.local.json');
const examplePath = path.join(__dirname, 'env.example.json');

function fail(msg) {
  console.error('assert-env FAIL:', msg);
  process.exit(1);
}
function ok(msg) {
  console.log('assert-env OK:', msg);
}

const cfgPath = fs.existsSync(localPath) ? localPath : examplePath;
if (!fs.existsSync(cfgPath)) fail('missing env.example.json');
const cfg = JSON.parse(fs.readFileSync(cfgPath, 'utf8'));

const required = [
  'baseUrl',
  'pluginPath',
  'pluginMustResolveTo',
  'artifactDir',
  'useMocks',
];
for (const k of required) {
  if (cfg[k] === undefined || cfg[k] === '') fail(`missing key ${k}`);
}

const mustResolve = path.resolve(cfg.pluginMustResolveTo);
if (mustResolve !== repoRoot && path.resolve(mustResolve) !== path.resolve(repoRoot)) {
  // allow if pluginMustResolveTo equals repoRoot after normalize
  if (fs.realpathSync(mustResolve) !== fs.realpathSync(repoRoot)) {
    fail(`pluginMustResolveTo ${mustResolve} !== repo ${repoRoot}`);
  }
}
ok(`repo ${repoRoot}`);

if (!fs.existsSync(cfg.pluginPath)) fail(`pluginPath missing: ${cfg.pluginPath}`);
let realPlugin;
try {
  realPlugin = fs.realpathSync(cfg.pluginPath);
} catch (e) {
  fail(`pluginPath unreadable: ${e.message}`);
}
const realRepo = fs.realpathSync(repoRoot);
if (realPlugin !== realRepo) {
  fail(`plugin realpath ${realPlugin} !== repo ${realRepo} (symlink broken?)`);
}
ok(`plugin symlink → ${realPlugin}`);

const portalPhp = path.join(realPlugin, 'portal-builder.php');
if (!fs.existsSync(portalPhp)) fail('portal-builder.php missing in plugin');
ok('portal-builder.php present');

const fixtureDir = path.join(repoRoot, 'tests/fixtures/portals');
if (!fs.existsSync(fixtureDir)) fail('tests/fixtures/portals missing');
ok('fixtures dir present');

const artifactDir = path.isAbsolute(cfg.artifactDir)
  ? cfg.artifactDir
  : path.join(repoRoot, cfg.artifactDir);
fs.mkdirSync(artifactDir, { recursive: true });
ok(`artifactDir ${artifactDir}`);

// HTTP reachability
const base = cfg.baseUrl.replace(/\/$/, '');
try {
  const res = await fetch(base + '/', { method: 'GET', redirect: 'manual' });
  if (res.status < 200 || res.status >= 500) fail(`baseUrl HTTP ${res.status}`);
  ok(`baseUrl ${base} → HTTP ${res.status}`);
} catch (e) {
  fail(`baseUrl unreachable: ${e.message}`);
}

if (cfg.useMocks !== true && cfg.useMocks !== false) fail('useMocks must be boolean');
ok(`useMocks=${cfg.useMocks}`);

console.log(JSON.stringify({ ok: true, cfgPath, realPlugin, base }, null, 2));
process.exit(0);
