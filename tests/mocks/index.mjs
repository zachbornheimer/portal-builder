/**
 * Mock facades for Sheet / Drive / Mail (test mode).
 * Wire production adapters behind the same call shapes in Phase 3.
 */
export {
	ARTIFACT_DRIVE,
	ARTIFACT_MAIL,
	ARTIFACT_SHEETS,
	SELFTEST_PORTAL_ID,
} from './constants.mjs';
export { systemClock } from './clock.mjs';
export { nodeFiles } from './files.mjs';
export {
	appendRow,
	clearPortalRows,
	readRows,
	sheetsPath,
} from './sheets.mjs';
export {
	clearPortalFiles,
	driveDir,
	storeFile,
} from './drive.mjs';
export { captureMail } from './mail.mjs';
