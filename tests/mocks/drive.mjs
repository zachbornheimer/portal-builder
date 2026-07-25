/**
 * Drive store facade — writes files under artifactDir/drive/{portalId}/.
 * Real Google Drive adapter lands in Phase 3; this is the test-mode path.
 */
import { ARTIFACT_DRIVE } from './constants.mjs';
import { joinPath, nodeFiles } from './files.mjs';

/**
 * @param {string} artifactDir
 * @param {string | number} portalId
 * @returns {string}
 */
export function driveDir(artifactDir, portalId) {
	return joinPath(artifactDir, ARTIFACT_DRIVE, String(portalId));
}

/**
 * @param {string} artifactDir
 * @param {string | number} portalId
 * @param {string} fieldId
 * @param {string | Buffer} buffer
 * @param {string} filename
 * @param {{ files?: import('./files.mjs').Files }} [deps]
 * @returns {string} absolute path written
 */
export function storeFile(artifactDir, portalId, fieldId, buffer, filename, deps = {}) {
	const files = deps.files ?? nodeFiles;
	const dir = driveDir(artifactDir, portalId);
	files.mkdir(dir, { recursive: true });
	const dest = joinPath(dir, `${fieldId}-${filename}`);
	files.write(dest, buffer);
	return dest;
}

/**
 * Remove only this portal's drive folder.
 * @param {string} artifactDir
 * @param {string | number} portalId
 * @param {{ files?: import('./files.mjs').Files }} [deps]
 */
export function clearPortalFiles(artifactDir, portalId, deps = {}) {
	const files = deps.files ?? nodeFiles;
	const dir = driveDir(artifactDir, portalId);
	if (files.exists(dir)) {
		files.remove(dir);
	}
}
