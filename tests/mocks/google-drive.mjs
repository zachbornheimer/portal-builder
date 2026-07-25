import fs from 'node:fs';
import path from 'node:path';

export function driveDir(artifactDir, portalId) {
  return path.join(artifactDir, 'drive', String(portalId));
}

export function storeFile(artifactDir, portalId, fieldId, buffer, filename) {
  const dir = driveDir(artifactDir, portalId);
  fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, `${fieldId}-${filename}`);
  fs.writeFileSync(dest, buffer);
  return dest;
}
