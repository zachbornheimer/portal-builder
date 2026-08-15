#!/usr/bin/env node
/**
 * Print the last JSONL sheet row for a portal id.
 *
 * Usage:
 *   node tests/support/read-sheet.mjs [portalId]
 *
 * Default: newest file under tests/.artifacts/sheets/
 */
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const sheetsDir = path.join(root, 'tests/.artifacts/sheets');
const portalId = process.argv[2] || null;

if (!fs.existsSync(sheetsDir)) {
	console.error(`No sheets directory at ${sheetsDir}`);
	process.exit(1);
}

/**
 * @param {string} dir
 * @returns {string[]}
 */
function listJsonl(dir) {
	return fs
		.readdirSync(dir)
		.filter((name) => name.endsWith('.jsonl'))
		.map((name) => path.join(dir, name));
}

let target;
if (portalId) {
	target = path.join(sheetsDir, `${portalId}.jsonl`);
	if (!fs.existsSync(target)) {
		console.error(`No sheet for portal "${portalId}" at ${target}`);
		process.exit(1);
	}
} else {
	const files = listJsonl(sheetsDir);
	if (files.length === 0) {
		console.error(`No .jsonl files in ${sheetsDir}`);
		process.exit(1);
	}
	files.sort((a, b) => fs.statSync(b).mtimeMs - fs.statSync(a).mtimeMs);
	target = files[0];
}

const lines = fs.readFileSync(target, 'utf8').trim().split('\n').filter(Boolean);
if (lines.length === 0) {
	console.error(`Sheet is empty: ${target}`);
	process.exit(1);
}

const last = lines[lines.length - 1];
let parsed;
try {
	parsed = JSON.parse(last);
} catch {
	console.error(`Last line is not JSON in ${target}`);
	process.exit(1);
}

console.log(`# ${path.basename(target)} (row ${lines.length})`);
console.log(JSON.stringify(parsed, null, 2));
