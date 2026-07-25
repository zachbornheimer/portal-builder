import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

export function loadEnv() {
	const local = path.join(root, 'tests/config/env.local.json');
	const example = path.join(root, 'tests/config/env.example.json');
	const p = fs.existsSync(local) ? local : example;
	const cfg = JSON.parse(fs.readFileSync(p, 'utf8'));
	cfg.repoRoot = root;
	cfg.artifactDirAbs = path.isAbsolute(cfg.artifactDir)
		? cfg.artifactDir
		: path.join(root, cfg.artifactDir);
	return cfg;
}
