/**
 * Copy the newest Playwright video to a stable path.
 *
 *   node tests/support/copy-last-video.mjs
 *
 * Default search: test-results/
 * Default dest:   tests/.artifacts/videos/last-public-submit.{webm|mp4}
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadEnv } from './load-env.mjs';

const VIDEO_EXTENSIONS = new Set(['.webm', '.mp4']);
const STABLE_BASENAME = 'last-public-submit';
const DEFAULT_SEARCH_DIR = 'test-results';
const VIDEOS_SUBDIR = 'videos';

/**
 * @param {string} dir
 * @returns {string[]}
 */
export function listVideoFiles(dir) {
	if (!fs.existsSync(dir)) {
		return [];
	}
	const found = [];
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			found.push(...listVideoFiles(full));
			continue;
		}
		if (entry.isFile() && VIDEO_EXTENSIONS.has(path.extname(entry.name).toLowerCase())) {
			found.push(full);
		}
	}
	return found;
}

/**
 * Playwright names the default fixture recording `video.webm` and extra-context
 * pages `page@<id>.webm`. Newest-mtime picks the fixture (admin) because that
 * context closes last. Prefer extra-context films for the public walk.
 * @param {string} filePath
 */
function isExtraContextVideo(filePath) {
	return /^page[@-]/i.test(path.basename(filePath));
}

/**
 * Public-walk video under searchDir, or null.
 * Prefers extra-context `page@*` recordings over fixture `video.webm`.
 * @param {string} searchDir
 * @returns {string|null}
 */
export function findLatestVideo(searchDir) {
	const files = listVideoFiles(searchDir);
	if (files.length === 0) {
		return null;
	}
	const extra = files.filter(isExtraContextVideo);
	const pool = extra.length > 0 ? extra : files;
	pool.sort((a, b) => fs.statSync(b).mtimeMs - fs.statSync(a).mtimeMs);
	return pool[0] ?? null;
}

/**
 * Copy source video to destDir/last-public-submit.{ext}.
 * @param {string} sourcePath
 * @param {string} destDir
 * @returns {string} Destination path.
 */
export function copyVideoToStable(sourcePath, destDir) {
	if (!fs.existsSync(sourcePath)) {
		throw new Error(`copyVideoToStable: source missing: ${sourcePath}`);
	}
	const ext = path.extname(sourcePath).toLowerCase() || '.webm';
	fs.mkdirSync(destDir, { recursive: true });
	const dest = path.join(destDir, `${STABLE_BASENAME}${ext}`);
	fs.copyFileSync(sourcePath, dest);
	return dest;
}

/**
 * Find newest video under searchDir and copy to destDir.
 * @param {{ searchDir?: string, destDir?: string }} [opts]
 * @returns {string|null}
 */
export function copyLastVideo(opts = {}) {
	const searchDir = opts.searchDir || path.resolve(DEFAULT_SEARCH_DIR);
	const destDir = opts.destDir;
	const latest = findLatestVideo(searchDir);
	if (!latest) {
		return null;
	}
	if (!destDir) {
		throw new Error('copyLastVideo: destDir required');
	}
	return copyVideoToStable(latest, destDir);
}

/** CLI: copy newest test-results video to tests/.artifacts/videos/. */
function mainCli() {
	const env = loadEnv();
	const destDir = path.join(env.artifactDirAbs, VIDEOS_SUBDIR);
	const dest = copyLastVideo({
		searchDir: path.join(env.repoRoot, DEFAULT_SEARCH_DIR),
		destDir,
	});
	if (!dest) {
		console.error(`copy-last-video: no .webm/.mp4 under ${DEFAULT_SEARCH_DIR}/`);
		process.exit(1);
	}
	console.log(`filmed video: ${dest}`);
}

const isDirectRun =
	process.argv[1] &&
	path.resolve(process.argv[1]) === path.resolve(fileURLToPath(import.meta.url));

if (isDirectRun) {
	mainCli();
}
