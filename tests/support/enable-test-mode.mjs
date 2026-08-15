/**
 * Enable the plugin's existing test-mode signals for LocalWP PHP.
 *
 * PHP resolves artifactDir from the plugin realpath (often the main checkout,
 * not this worktree). The marker file is the signal Portal_Test_Mode already
 * understands. Option dg_test_mode is set via an admin-page eval when WP is
 * loaded — no new flag.
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const TEST_MODE_MARKER = '.dg-test-mode';
const TEST_MODE_OPTION = 'dg_test_mode';
const DEFAULT_ARTIFACT_REL = 'tests/.artifacts';
const WP_LOAD_REL = path.join('wp-content', 'plugins');

const TEST_MODE_HELP = [
	'Definition HTTP submit requires test mode inside LocalWP PHP.',
	'Enable one of:',
	'  - env DG_TEST_MODE=1 for the PHP-FPM process',
	'  - WP option dg_test_mode = 1',
	`  - marker file ${DEFAULT_ARTIFACT_REL}/${TEST_MODE_MARKER} on the plugin realpath`,
].join('\n');

/**
 * Plugin root PHP will use (symlink realpath, else configured must-resolve).
 * @param {ReturnType<import('./load-env.mjs').loadEnv>} env
 * @returns {string}
 */
export function pluginRoot(env) {
	if (env.pluginPath && fs.existsSync(env.pluginPath)) {
		return fs.realpathSync(env.pluginPath);
	}
	if (env.pluginMustResolveTo && fs.existsSync(env.pluginMustResolveTo)) {
		return fs.realpathSync(env.pluginMustResolveTo);
	}
	return env.repoRoot;
}

/**
 * Directory PHP Portal_Test_Mode::artifact_dir() writes when no env override.
 * @param {ReturnType<import('./load-env.mjs').loadEnv>} env
 * @returns {string}
 */
export function phpArtifactDir(env) {
	return path.join(pluginRoot(env), DEFAULT_ARTIFACT_REL);
}

/**
 * WordPress public root from env.wpContentPlugins (parent of wp-content).
 * @param {ReturnType<import('./load-env.mjs').loadEnv>} env
 * @returns {string|null}
 */
export function wpPublicRoot(env) {
	if (env.wpContentPlugins && fs.existsSync(env.wpContentPlugins)) {
		return path.resolve(env.wpContentPlugins, '..', '..');
	}
	if (env.pluginPath && fs.existsSync(env.pluginPath)) {
		const pluginsDir = path.dirname(fs.realpathSync(env.pluginPath));
		if (pluginsDir.endsWith(WP_LOAD_REL) || pluginsDir.endsWith('plugins')) {
			return path.resolve(pluginsDir, '..', '..');
		}
	}
	return null;
}

/**
 * Write the existing .dg-test-mode marker where PHP will read it.
 * @param {ReturnType<import('./load-env.mjs').loadEnv>} env
 * @returns {{ markerPath: string, artifactDir: string }}
 */
export function writeTestModeMarker(env) {
	const artifactDir = phpArtifactDir(env);
	fs.mkdirSync(artifactDir, { recursive: true });
	const markerPath = path.join(artifactDir, TEST_MODE_MARKER);
	fs.writeFileSync(markerPath, 'enabled-by-filmed-spec\n', 'utf8');
	if (env.artifactDirAbs !== artifactDir) {
		fs.mkdirSync(env.artifactDirAbs, { recursive: true });
		fs.writeFileSync(
			path.join(env.artifactDirAbs, TEST_MODE_MARKER),
			'enabled-by-filmed-spec\n',
			'utf8',
		);
	}
	return { markerPath, artifactDir };
}

/**
 * Set option dg_test_mode via wp-cli when the Local site is on disk.
 * @param {ReturnType<import('./load-env.mjs').loadEnv>} env
 * @returns {{ ok: boolean, via: string }}
 */
export function setTestModeOptionCli(env) {
	const publicRoot = wpPublicRoot(env);
	if (!publicRoot || !fs.existsSync(path.join(publicRoot, 'wp-load.php'))) {
		return { ok: false, via: 'no-wp-load' };
	}
	const r = spawnSync(
		'wp',
		['option', 'update', TEST_MODE_OPTION, '1', `--path=${publicRoot}`],
		{ encoding: 'utf8' },
	);
	if (r.status === 0) {
		return { ok: true, via: 'wp-cli' };
	}
	return { ok: false, via: 'wp-cli-missing' };
}

/**
 * Enable test mode (marker + best-effort existing WP option).
 * @param {ReturnType<import('./load-env.mjs').loadEnv>} env
 */
export function enableTestMode(env) {
	const written = writeTestModeMarker(env);
	const option = setTestModeOptionCli(env);
	return { ...written, option };
}

export { TEST_MODE_HELP, TEST_MODE_MARKER, TEST_MODE_OPTION };
