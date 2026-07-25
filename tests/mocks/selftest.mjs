#!/usr/bin/env node
/**
 * Mock wiring selftest — proves Sheet / Drive / Mail facades write artifacts.
 * Run: npm run test:mocks
 */
import { loadEnv } from '../support/load-env.mjs';
import {
	SELFTEST_FIELD_ID,
	SELFTEST_FILENAME,
	SELFTEST_FILE_BYTES,
	SELFTEST_MAIL_SUBJECT,
	SELFTEST_MAIL_TO,
	SELFTEST_PORTAL_ID,
	SELFTEST_WORK_TITLE,
} from './constants.mjs';
import { storeFile, clearPortalFiles } from './drive.mjs';
import { nodeFiles } from './files.mjs';
import { captureMail } from './mail.mjs';
import { appendRow, clearPortalRows, readRows } from './sheets.mjs';

const env = loadEnv();
const artifactDir = env.artifactDirAbs;
const portalId = SELFTEST_PORTAL_ID;

// Isolate this run from prior selftest debris
clearPortalRows(artifactDir, portalId);
clearPortalFiles(artifactDir, portalId);

appendRow(artifactDir, portalId, {
	work_title: SELFTEST_WORK_TITLE,
	at: new Date().toISOString(),
});
const rows = readRows(artifactDir, portalId);
const last = rows[rows.length - 1];
if (!last || last.work_title !== SELFTEST_WORK_TITLE) {
	console.error('sheets selftest failed', { rows, expected: SELFTEST_WORK_TITLE });
	process.exit(1);
}

const filePath = storeFile(
	artifactDir,
	portalId,
	SELFTEST_FIELD_ID,
	Buffer.from(SELFTEST_FILE_BYTES),
	SELFTEST_FILENAME,
);
if (!nodeFiles.exists(filePath)) {
	console.error('drive selftest failed: missing', filePath);
	process.exit(1);
}

const mailPath = captureMail(artifactDir, {
	to: SELFTEST_MAIL_TO,
	subject: SELFTEST_MAIL_SUBJECT,
});
if (!nodeFiles.exists(mailPath)) {
	console.error('mail selftest failed: missing', mailPath);
	process.exit(1);
}

console.log(
	JSON.stringify(
		{
			ok: true,
			artifactDir,
			portalId,
			rows: rows.length,
			file: filePath,
			mail: mailPath,
		},
		null,
		2,
	),
);
process.exit(0);
