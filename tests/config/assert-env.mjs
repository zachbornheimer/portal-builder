#!/usr/bin/env node
/**
 * Assert Local/CI harness config is coherent before e2e.
 * Exit 0 only when required paths, symlink target, fixtures, and baseUrl respond.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HTTP_OK_MIN = 200;
const HTTP_REDIRECT_MAX = 399;

const REQUIRED_KEYS = [
  'baseUrl',
  'pluginPath',
  'pluginMustResolveTo',
  'artifactDir',
  'useMocks',
];

/** Fixture paths relative to repo root that every e2e suite may depend on. */
const REQUIRED_FIXTURES = [
  'tests/fixtures/portals/herbolzheimer.definition.json',
  'tests/fixtures/files/sample-score.pdf',
  'tests/fixtures/files/sample-recording.mp3',
];

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

function loadConfig() {
  const cfgPath = fs.existsSync(localPath) ? localPath : examplePath;
  if (!fs.existsSync(cfgPath)) {
    fail('missing env.example.json — copy env.example.json to env.local.json');
  }
  let cfg;
  try {
    cfg = JSON.parse(fs.readFileSync(cfgPath, 'utf8'));
  } catch (e) {
    fail(`invalid JSON in ${cfgPath}: ${e.message}`);
  }
  return { cfg, cfgPath };
}

function assertRequiredKeys(cfg) {
  for (const key of REQUIRED_KEYS) {
    if (cfg[key] === undefined || cfg[key] === '') {
      fail(`missing key ${key}`);
    }
  }
}

function assertRepoTarget(cfg) {
  const mustResolve = path.resolve(cfg.pluginMustResolveTo);
  let realMust;
  let realRepo;
  try {
    realMust = fs.realpathSync(mustResolve);
    realRepo = fs.realpathSync(repoRoot);
  } catch (e) {
    fail(`pluginMustResolveTo unreadable: ${e.message}`);
  }
  if (realMust !== realRepo) {
    fail(`pluginMustResolveTo ${realMust} !== repo ${realRepo}`);
  }
  ok(`repo ${realRepo}`);
  return realRepo;
}

function assertPluginSymlink(cfg, realRepo) {
  if (!fs.existsSync(cfg.pluginPath)) {
    fail(`pluginPath missing: ${cfg.pluginPath}`);
  }
  let realPlugin;
  try {
    realPlugin = fs.realpathSync(cfg.pluginPath);
  } catch (e) {
    fail(`pluginPath unreadable: ${e.message}`);
  }
  if (realPlugin !== realRepo) {
    fail(
      `plugin realpath ${realPlugin} !== repo ${realRepo} (symlink broken?)`,
    );
  }
  ok(`plugin symlink → ${realPlugin}`);

  const portalPhp = path.join(realPlugin, 'portal-builder.php');
  if (!fs.existsSync(portalPhp)) {
    fail('portal-builder.php missing in plugin');
  }
  ok('portal-builder.php present');
  return realPlugin;
}

function assertFixtures() {
  for (const rel of REQUIRED_FIXTURES) {
    const abs = path.join(repoRoot, rel);
    if (!fs.existsSync(abs)) {
      fail(`fixture missing: ${rel}`);
    }
  }
  ok(`fixtures (${REQUIRED_FIXTURES.length} required paths)`);
}

function ensureArtifactDir(cfg) {
  const artifactDir = path.isAbsolute(cfg.artifactDir)
    ? cfg.artifactDir
    : path.join(repoRoot, cfg.artifactDir);
  fs.mkdirSync(artifactDir, { recursive: true });
  ok(`artifactDir ${artifactDir}`);
  return artifactDir;
}

async function assertBaseUrl(cfg) {
  const base = cfg.baseUrl.replace(/\/$/, '');
  let res;
  try {
    res = await fetch(`${base}/`, { method: 'GET', redirect: 'manual' });
  } catch (e) {
    fail(`baseUrl unreachable: ${e.message}`);
  }
  if (res.status < HTTP_OK_MIN || res.status > HTTP_REDIRECT_MAX) {
    fail(`baseUrl HTTP ${res.status} (want 2xx or 3xx, typically 200/302)`);
  }
  ok(`baseUrl ${base} → HTTP ${res.status}`);
  return base;
}

function assertMockFlag(cfg) {
  if (cfg.useMocks !== true && cfg.useMocks !== false) {
    fail('useMocks must be boolean');
  }
  ok(`useMocks=${cfg.useMocks}`);
}

const { cfg, cfgPath } = loadConfig();
assertRequiredKeys(cfg);
const realRepo = assertRepoTarget(cfg);
const realPlugin = assertPluginSymlink(cfg, realRepo);
assertFixtures();
ensureArtifactDir(cfg);
const base = await assertBaseUrl(cfg);
assertMockFlag(cfg);

console.log(
  JSON.stringify(
    {
      ok: true,
      cfgPath,
      realPlugin,
      realRepo,
      base,
      useMocks: cfg.useMocks,
    },
    null,
    2,
  ),
);
process.exit(0);
