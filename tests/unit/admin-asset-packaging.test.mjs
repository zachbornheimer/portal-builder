import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

test('Portal_Setup_Screen enqueues assets/tokens.css and never excluded src/tokens.css', () => {
	const setupScreen = read('includes/class-portal-setup-screen.php');
	assert.doesNotMatch(
		setupScreen,
		/src\/tokens\.css/,
		'Portal_Setup_Screen must not enqueue src/tokens.css because src/ is excluded from deploys and release zips'
	);
	assert.match(
		setupScreen,
		/plugins_url\(\s*'assets\/tokens\.css',\s*\$plugin_file\s*\)/,
		'Portal_Setup_Screen must enqueue assets/tokens.css'
	);
	assert.match(
		setupScreen,
		/\$asset_ver\(\s*'assets\/tokens\.css'\s*\)/,
		'Portal_Setup_Screen must version assets/tokens.css from its filemtime'
	);
});

test('assets/tokens.css matches src/tokens.css byte-for-byte and is required in release zips', () => {
	const srcTokens = fs.readFileSync(path.join(root, 'src/tokens.css'));
	const assetTokensPath = path.join(root, 'assets/tokens.css');
	assert.equal(fs.existsSync(assetTokensPath), true, 'assets/tokens.css must exist');
	const assetTokens = fs.readFileSync(assetTokensPath);
	assert.equal(
		Buffer.compare(srcTokens, assetTokens),
		0,
		'assets/tokens.css must match src/tokens.css byte-for-byte'
	);

	const releaseScript = read('scripts/make-release-zip.sh');
	assert.match(
		releaseScript,
		/require_zip_member\s+"assets\/tokens\.css"/,
		'make-release-zip.sh must verify assets/tokens.css in the release zip'
	);
});

test('vite.config.js hashes shared chunk filenames so ES module imports are cache-safe', () => {
	const viteConfig = read('vite.config.js');
	assert.match(
		viteConfig,
		/chunkFileNames:\s*['"]\[name\]-\[hash\]\.js['"]/,
		'vite.config.js must configure chunkFileNames with [name]-[hash].js'
	);
	assert.doesNotMatch(
		viteConfig,
		/chunkFileNames:\s*['"]\[name\]\.js['"]/,
		'vite.config.js must not emit unhashed shared chunk names'
	);
});

test('built entry bundles only import existing content-hashed shared chunks in assets/dist', () => {
	const distDir = path.join(root, 'assets/dist');
	assert.equal(
		fs.existsSync(path.join(distDir, 'label.js')),
		false,
		'unhashed assets/dist/label.js must not exist'
	);

	const hashedChunkPattern = /^\.\/[a-z0-9_-]+-[A-Za-z0-9_-]{6,}\.js$/;

	for (const entry of ['dragongate-portal.js', 'dragongate-public.js']) {
		const content = read(`assets/dist/${entry}`);
		const imports = [
			...content.matchAll(/from\s*["'](\.\/[^"']+\.js)["']/g),
			...content.matchAll(/import\s*\(\s*["'](\.\/[^"']+\.js)["']\s*\)/g),
		].map((m) => m[1]);

		assert.ok(imports.length > 0, `${entry} should import at least one shared chunk`);
		for (const relImport of imports) {
			assert.match(
				relImport,
				hashedChunkPattern,
				`${entry} import ${relImport} must be content-hashed`
			);
			const targetPath = path.resolve(distDir, relImport);
			assert.equal(
				fs.existsSync(targetPath),
				true,
				`${entry} imported chunk ${relImport} must exist in assets/dist`
			);
		}
	}
});
