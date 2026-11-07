/**
 * Sheet store facade — append/read rows as JSONL under artifactDir/sheets/.
 * Real Google Sheets adapter lands in Phase 3; this is the test-mode path.
 */
import {
	ARTIFACT_SHEETS,
	SHEETS_EXTENSION,
} from './constants.mjs';
import { dirnameOf, joinPath, nodeFiles } from './files.mjs';

/**
 * @param {string} artifactDir
 * @param {string | number} portalId
 * @returns {string}
 */
export function sheetsPath(artifactDir, portalId) {
	return joinPath(artifactDir, ARTIFACT_SHEETS, `${portalId}${SHEETS_EXTENSION}`);
}

/**
 * @param {string} artifactDir
 * @param {string | number} portalId
 * @param {Record<string, unknown>} row
 * @param {{ files?: import('./files.mjs').Files }} [deps]
 * @returns {string} path written
 */
export function appendRow(artifactDir, portalId, row, deps = {}) {
	const files = deps.files ?? nodeFiles;
	const dest = sheetsPath(artifactDir, portalId);
	files.mkdir(dirnameOf(dest), { recursive: true });
	files.append(dest, `${JSON.stringify(row)}\n`);
	return dest;
}

/**
 * @param {string} artifactDir
 * @param {string | number} portalId
 * @param {{ files?: import('./files.mjs').Files }} [deps]
 * @returns {Record<string, unknown>[]}
 */
export function readRows(artifactDir, portalId, deps = {}) {
	const files = deps.files ?? nodeFiles;
	const dest = sheetsPath(artifactDir, portalId);
	if (!files.exists(dest)) {
		return [];
	}
	const text = files.readText(dest, 'utf8').trim();
	if (!text) {
		return [];
	}
	return text.split('\n').filter(Boolean).map((line) => JSON.parse(line));
}

/**
 * Remove only this portal's sheet artifact (leaves other portals intact).
 * @param {string} artifactDir
 * @param {string | number} portalId
 * @param {{ files?: import('./files.mjs').Files }} [deps]
 */
export function clearPortalRows(artifactDir, portalId, deps = {}) {
	const files = deps.files ?? nodeFiles;
	const dest = sheetsPath(artifactDir, portalId);
	if (files.exists(dest)) {
		files.remove(dest);
	}
}
