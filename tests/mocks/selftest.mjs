#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { appendRow, readRows } from './google-sheets.mjs';
import { storeFile } from './google-drive.mjs';
import { captureMail } from './mail.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const artifactDir = path.join(root, 'tests/.artifacts');
const portalId = '_selftest';

// clean selftest artifacts
for (const sub of ['sheets', 'drive', 'mail']) {
  const p = path.join(artifactDir, sub);
  // leave other runs; only overwrite selftest files
}

appendRow(artifactDir, portalId, { work_title: 'Test Work', at: new Date().toISOString() });
const rows = readRows(artifactDir, portalId);
if (rows.length < 1 || rows[rows.length - 1].work_title !== 'Test Work') {
  console.error('sheets selftest failed', rows);
  process.exit(1);
}

const f = storeFile(artifactDir, portalId, 'score', Buffer.from('%PDF'), 'sample.pdf');
if (!fs.existsSync(f)) {
  console.error('drive selftest failed');
  process.exit(1);
}

const m = captureMail(artifactDir, { to: 'applicant@example.com', subject: 'Receipt' });
if (!fs.existsSync(m)) {
  console.error('mail selftest failed');
  process.exit(1);
}

console.log(JSON.stringify({ ok: true, rows: rows.length, file: f, mail: m }, null, 2));
process.exit(0);
