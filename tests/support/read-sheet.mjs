/**
 * Print the last JSONL sheet row for a portal id.
 *
 * Usage:
 *   node tests/support/read-sheet.mjs [portalId]
 *
 * Searches tests/.artifacts/sheets/ and each test file's own
 * tests/.artifacts/<name>/sheets/. Default: the newest sheet anywhere.
 */
import fs from "node:fs";
import path from "node:path";

const root = process.cwd();
const artifactsDir = path.join(root, "tests/.artifacts");
const portalId = process.argv[2] || null;

/**
 * Every sheets/ directory: the shared root one plus one per test artifact dir.
 *
 * @returns {string[]}
 */
function sheetDirs() {
  if (!fs.existsSync(artifactsDir)) {
    return [];
  }
  const nested = fs
    .readdirSync(artifactsDir, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => path.join(artifactsDir, entry.name, "sheets"));
  return [path.join(artifactsDir, "sheets"), ...nested].filter((dir) => fs.existsSync(dir));
}

const candidates = sheetDirs()
  .flatMap((dir) => fs.readdirSync(dir).map((name) => path.join(dir, name)))
  .filter((file) => file.endsWith(".jsonl"))
  .filter((file) => !portalId || path.basename(file) === `${portalId}.jsonl`)
  .sort((a, b) => fs.statSync(b).mtimeMs - fs.statSync(a).mtimeMs);

if (candidates.length === 0) {
  const what = portalId ? `portal "${portalId}"` : "any portal";
  console.error(`No sheet for ${what} under ${artifactsDir}`);
  process.exit(1);
}
const target = candidates[0];

const lines = fs.readFileSync(target, "utf8").trim().split("\n").filter(Boolean);
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

console.log(`# ${path.relative(root, target)} (row ${lines.length})`);
console.log(JSON.stringify(parsed, null, 2));
