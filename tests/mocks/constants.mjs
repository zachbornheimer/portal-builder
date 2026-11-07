/**
 * Artifact layout for file-backed Sheet / Drive / Mail mocks.
 * Paths are relative to artifactDir (env.artifactDirAbs).
 */

export const ARTIFACT_SHEETS = 'sheets';
export const ARTIFACT_DRIVE = 'drive';
export const ARTIFACT_MAIL = 'mail';

/** Portal id reserved for `npm run test:mocks` selftest. */
export const SELFTEST_PORTAL_ID = '_selftest';

export const SHEETS_EXTENSION = '.jsonl';
export const MAIL_EXTENSION = '.json';

export const SELFTEST_WORK_TITLE = 'Test Work';
export const SELFTEST_FIELD_ID = 'score';
export const SELFTEST_FILENAME = 'sample.pdf';
export const SELFTEST_FILE_BYTES = '%PDF';
export const SELFTEST_MAIL_TO = 'applicant@example.com';
export const SELFTEST_MAIL_SUBJECT = 'Receipt';
export const MAIL_UNKNOWN_RECIPIENT = 'unknown';
