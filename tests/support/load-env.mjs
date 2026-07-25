/**
 * Load harness env (local overrides example). Pure config — no I/O beyond files.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const DEFAULT_ARTIFACT_DIR = 'tests/.artifacts';
const DEFAULT_PORTAL_PREFIX = 'dg-e2e-';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * @returns {Record<string, any> & {
 *   repoRoot: string,
 *   cfgPath: string,
 *   artifactDir: string,
 *   artifactDirAbs: string,
 *   testPortalPrefix: string,
 * }}
 */
export function loadEnv() {
	const local = path.join(root, 'tests/config/env.local.json');
	const example = path.join(root, 'tests/config/env.example.json');
	const cfgPath = fs.existsSync(local) ? local : example;
	if (!fs.existsSync(cfgPath)) {
		throw new Error(`harness env missing: copy env.example.json to env.local.json`);
	}
	const cfg = JSON.parse(fs.readFileSync(cfgPath, 'utf8'));
	const artifactDir = cfg.artifactDir || DEFAULT_ARTIFACT_DIR;
	return {
		...cfg,
		testPortalPrefix: cfg.testPortalPrefix || DEFAULT_PORTAL_PREFIX,
		repoRoot: root,
		cfgPath,
		artifactDir,
		artifactDirAbs: path.isAbsolute(artifactDir)
			? artifactDir
			: path.join(root, artifactDir),
	};
}

export { root as repoRoot, DEFAULT_PORTAL_PREFIX, DEFAULT_ARTIFACT_DIR };
