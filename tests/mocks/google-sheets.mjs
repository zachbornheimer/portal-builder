import fs from 'node:fs';
import path from 'node:path';

export function sheetsPath(artifactDir, portalId) {
  return path.join(artifactDir, 'sheets', `${portalId}.jsonl`);
}

export function appendRow(artifactDir, portalId, row) {
  const p = sheetsPath(artifactDir, portalId);
  fs.mkdirSync(path.dirname(p), { recursive: true });
  fs.appendFileSync(p, JSON.stringify(row) + '\n');
  return p;
}

export function readRows(artifactDir, portalId) {
  const p = sheetsPath(artifactDir, portalId);
  if (!fs.existsSync(p)) return [];
  return fs
    .readFileSync(p, 'utf8')
    .trim()
    .split('\n')
    .filter(Boolean)
    .map((l) => JSON.parse(l));
}
