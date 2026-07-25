import fs from 'node:fs';
import path from 'node:path';

export function captureMail(artifactDir, message) {
  const dir = path.join(artifactDir, 'mail');
  fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, `${Date.now()}-${message.to || 'unknown'}.json`);
  fs.writeFileSync(dest, JSON.stringify(message, null, 2));
  return dest;
}
