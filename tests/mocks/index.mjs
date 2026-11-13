/**
 * Mock facades for Sheet / Drive / Mail (test mode).
 * Wire production adapters behind the same call shapes in Phase 3.
 */
export { systemClock } from "./clock.mjs";
export { ARTIFACT_DRIVE, ARTIFACT_MAIL, ARTIFACT_SHEETS, SELFTEST_PORTAL_ID } from "./constants.mjs";
export { clearPortalFiles, driveDir, storeFile } from "./drive.mjs";
export { nodeFiles } from "./files.mjs";
export { captureMail } from "./mail.mjs";
export { appendRow, clearPortalRows, readRows, sheetsPath } from "./sheets.mjs";
