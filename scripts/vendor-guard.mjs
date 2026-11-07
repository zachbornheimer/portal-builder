#!/usr/bin/env node
/**
 * Vendor-prefix guard (ZYS-2607).
 *
 * The plugin ships every third-party dependency under DragonGate\Vendor\ (Strauss, see
 * scripts/prefix-vendor.sh) so another plugin's older Guzzle, psr/log, Monolog or Google client
 * can no longer win the autoload race. This guard fails when a change would let an unprefixed
 * dependency name or autoloader back in:
 *
 *   1. rule      a foxguard rule, generated from composer.lock, over the first-party PHP tree
 *                (rules/unprefixed-vendor-reference.yml.tmpl)
 *   2. structure composer.json / portal-builder.php / first-party PHP wiring
 *   3. built     (--built) every production package in composer.lock is in vendor-prefixed/
 *   4. collision (--pushed, when build inputs changed) the load-order collision test
 *
 * Usage:
 *   node scripts/vendor-guard.mjs                 rule + structure
 *   node scripts/vendor-guard.mjs --built         rule + structure + built-tree check
 *   node scripts/vendor-guard.mjs --pushed -- F…  rule + structure; when F includes a build input
 *                                                 (composer.json, composer.lock, scripts/prefix-vendor.sh)
 *                                                 also the built-tree check and the collision test
 *   --root DIR                                    guard another tree (the unit test uses fixtures)
 *
 * Exit 0 clean, 1 findings, 2 the guard itself could not run.
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const RULE_TEMPLATE = fileURLToPath(
	new URL('../rules/unprefixed-vendor-reference.yml.tmpl', import.meta.url),
);
export const COLLISION_TEST = 'tests/unit/vendor-prefix-collision.test.mjs';
export const PREFIXED_DIR = 'vendor-prefixed';
export const PREFIXED_AUTOLOAD = 'vendor-prefixed/autoload.php';
export const PLUGIN_MAIN_FILE = 'portal-builder.php';
export const README_ANCHOR = 'README.md, "Vendor prefix guard"';

/** Changing any of these can change what ships in vendor-prefixed/, so the built tree is re-proved. */
export const BUILD_INPUTS = ['composer.json', 'composer.lock', 'scripts/prefix-vendor.sh'];

/** Not first-party plugin code: dependencies, generated bundles, and the test tree. */
export const NON_FIRST_PARTY = [
	'node_modules',
	'vendor',
	'vendor-prefixed',
	'tests',
	'dist',
	'build',
	'assets/dist',
	'playwright-report',
	'test-results',
	'.git',
];

export const FOXGUARD_TIMEOUT_MS = 60_000;
export const COLLISION_TIMEOUT_MS = 120_000;
const OUTPUT_TAIL_LINES = 40;
const FOXGUARD_BLOCKING_SEVERITY = 'high';
const FOXGUARD_LOAD_FAILURE = /Warning:|Failed to (?:parse|load)/;
const RULE_ID_PREFIX = 'unprefixed-vendor-reference-';
const ROOT_PATTERN = /^[A-Za-z][A-Za-z0-9_]*$/;
const UNPREFIXED_AUTOLOAD = /(?<![\w-])vendor\/(?:autoload\.php|composer\/)/;
const COMMENT_LINE = /^\s*(?:\*|\/\/|\/|#)/;

class GuardError extends Error {}

function readJson(file) {
	try {
		return JSON.parse(fs.readFileSync(file, 'utf8'));
	} catch (error) {
		throw new GuardError(`cannot read ${file}: ${error.message}`);
	}
}

function straussConfig(composer) {
	const config = composer.extra?.strauss;
	if (!config?.namespace_prefix || !config?.classmap_prefix || !Array.isArray(config.packages)) {
		throw new GuardError(
			'composer.json extra.strauss needs namespace_prefix, classmap_prefix and packages; the guard derives the prefixes from it',
		);
	}
	return config;
}

/**
 * Namespace roots to guard, derived from the production packages in composer.lock.
 * Returns { roots: Map<root, packageName[]>, exempt: string[], underivable: string[] }.
 * `underivable` are non-exempt packages that declare no psr-4/psr-0 namespace, so the guard
 * cannot know which class names they define.
 */
export function deriveGuardedNamespaces(lock, composer) {
	const exempt = new Set(straussConfig(composer).exclude_from_prefix?.packages ?? []);
	const roots = new Map();
	const underivable = [];
	for (const pkg of lock.packages ?? []) {
		if (exempt.has(pkg.name)) {
			continue;
		}
		const keys = [...Object.keys(pkg.autoload?.['psr-4'] ?? {}), ...Object.keys(pkg.autoload?.['psr-0'] ?? {})];
		let declared = false;
		for (const key of keys) {
			const root = key.includes('\\') ? key.split('\\')[0] : key.replace(/_+$/, '');
			if (root === '') {
				continue;
			}
			if (!ROOT_PATTERN.test(root)) {
				throw new GuardError(
					`composer.lock: ${pkg.name} declares namespace ${JSON.stringify(key)} the guard cannot turn into a rule`,
				);
			}
			declared = true;
			roots.set(root, [...new Set([...(roots.get(root) ?? []), pkg.name])]);
		}
		if (!declared) {
			underivable.push(pkg.name);
		}
	}
	return { roots, exempt: [...exempt], underivable };
}

function yamlSingleQuoted(value) {
	return value.replaceAll("'", "''");
}

/** One foxguard rule per namespace root, stamped from the template's rule block. */
export function renderRules(template, roots, strauss) {
	const start = template.search(/^- id:/m);
	if (start < 0) {
		throw new GuardError(`${RULE_TEMPLATE} has no "- id:" rule block`);
	}
	const block = template.slice(start).trimEnd();
	const items = [...roots].map(([root, packages]) =>
		block
			.replaceAll('@@CLASSMAP_PREFIX@@', yamlSingleQuoted(strauss.classmap_prefix))
			.replaceAll('@@PREFIX@@', yamlSingleQuoted(strauss.namespace_prefix))
			.replaceAll('@@PACKAGES@@', yamlSingleQuoted(packages.join(', ')))
			.replaceAll('@@ROOT@@', root)
			.replace(/^/gm, '  '),
	);
	return `rules:\n${items.join('\n')}\n`;
}

function isNonFirstParty(relative) {
	return NON_FIRST_PARTY.some((excluded) => relative === excluded || relative.startsWith(`${excluded}/`));
}

/** Relative paths of first-party PHP: everything but dependencies, bundles and tests. */
export function firstPartyPhp(root) {
	const found = [];
	const walk = (dir) => {
		for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
			const full = path.join(dir, entry.name);
			const relative = path.relative(root, full).split(path.sep).join('/');
			if (entry.isDirectory()) {
				if (entry.name !== 'node_modules' && !isNonFirstParty(relative)) {
					walk(full);
				}
			} else if (entry.name.endsWith('.php')) {
				found.push(relative);
			}
		}
	};
	walk(root);
	return found.sort();
}

function codeLines(file) {
	return fs
		.readFileSync(file, 'utf8')
		.split('\n')
		.map((text, index) => ({ number: index + 1, text }))
		.filter(({ text }) => !COMMENT_LINE.test(text));
}

/** Structure findings: [{ where, problem, fix }]. Static: reads composer.json, composer.lock, PHP. */
export function structureFindings(root) {
	const findings = [];
	const composer = readJson(path.join(root, 'composer.json'));
	const lock = readJson(path.join(root, 'composer.lock'));
	const strauss = straussConfig(composer);
	const exempt = new Set(strauss.exclude_from_prefix?.packages ?? []);
	const prefixed = new Set(strauss.packages);

	for (const name of Object.keys(composer.require ?? {})) {
		if (name === 'php' || name.startsWith('ext-') || name.startsWith('lib-')) {
			continue;
		}
		if (!prefixed.has(name) && !exempt.has(name)) {
			findings.push({
				where: 'composer.json',
				problem: `require "${name}" is not in extra.strauss.packages, so it would ship unprefixed (or not at all)`,
				fix: `add "${name}" to extra.strauss.packages in composer.json, then run composer update`,
			});
		}
	}

	for (const [kind, entries] of Object.entries(composer.autoload ?? {})) {
		for (const entry of Array.isArray(entries) ? entries : Object.values(entries ?? {}).flat()) {
			if (typeof entry === 'string' && /^(?:\.\/)?vendor(?:-prefixed)?\//.test(entry)) {
				findings.push({
					where: 'composer.json',
					problem: `autoload.${kind} points into ${entry}; composer would autoload a third-party tree at runtime`,
					fix: 'remove it: the plugin loads vendor-prefixed/autoload.php only',
				});
			}
		}
	}

	for (const name of deriveGuardedNamespaces(lock, composer).underivable) {
		findings.push({
			where: 'composer.lock',
			problem: `production package ${name} declares no psr-4/psr-0 namespace, so the guard cannot tell which class names it defines`,
			fix: `if Strauss intentionally leaves it unprefixed, add "${name}" to extra.strauss.exclude_from_prefix in composer.json and say why in the PR; otherwise teach the guard its namespace`,
		});
	}

	const main = path.join(root, PLUGIN_MAIN_FILE);
	if (!fs.existsSync(main) || !fs.readFileSync(main, 'utf8').includes(PREFIXED_AUTOLOAD)) {
		findings.push({
			where: PLUGIN_MAIN_FILE,
			problem: `does not require ${PREFIXED_AUTOLOAD}`,
			fix: `require_once plugin_dir_path( __FILE__ ) . '${PREFIXED_AUTOLOAD}';`,
		});
	}
	for (const relative of firstPartyPhp(root)) {
		for (const { number, text } of codeLines(path.join(root, relative))) {
			if (UNPREFIXED_AUTOLOAD.test(text)) {
				findings.push({
					where: `${relative}:${number}`,
					problem: `loads the unprefixed Composer tree: ${text.trim()}`,
					fix: `require ${PREFIXED_AUTOLOAD} instead`,
				});
			}
		}
	}
	return findings;
}

/** Built-tree findings: every production package in composer.lock must exist in vendor-prefixed/. */
export function builtFindings(root) {
	const lock = readJson(path.join(root, 'composer.lock'));
	const prefixedRoot = path.join(root, PREFIXED_DIR);
	if (!fs.existsSync(path.join(root, PREFIXED_AUTOLOAD))) {
		return [
			{
				where: PREFIXED_DIR,
				problem: `${PREFIXED_AUTOLOAD} is missing; the prefixed tree is not built`,
				fix: 'run `composer install` (its post-install hook runs scripts/prefix-vendor.sh) or `mise run setup`',
			},
		];
	}
	return (lock.packages ?? [])
		.filter((pkg) => !fs.existsSync(path.join(prefixedRoot, pkg.name)))
		.map((pkg) => ({
			where: `${PREFIXED_DIR}/${pkg.name}`,
			problem: `production package ${pkg.name} (composer.lock) is absent from ${PREFIXED_DIR}/`,
			fix: 'run `composer install` so scripts/prefix-vendor.sh rebuilds vendor-prefixed/; if it stays missing, add the package to extra.strauss.packages',
		}));
}

/** Runs the generated foxguard rule. Returns [{ where, problem, fix }]; throws GuardError if foxguard cannot run. */
export function ruleFindings(root) {
	const composer = readJson(path.join(root, 'composer.json'));
	const lock = readJson(path.join(root, 'composer.lock'));
	const strauss = straussConfig(composer);
	const { roots } = deriveGuardedNamespaces(lock, composer);
	if (roots.size === 0) {
		throw new GuardError('composer.lock yields no third-party namespace to guard; refusing to pass an empty rule set');
	}
	const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'vendor-guard-'));
	try {
		fs.writeFileSync(
			path.join(scratch, 'unprefixed-vendor-reference.yml'),
			renderRules(fs.readFileSync(RULE_TEMPLATE, 'utf8'), roots, strauss),
		);
		const excludes = NON_FIRST_PARTY.flatMap((excluded) => ['--exclude', excluded]);
		const run = spawnSync(
			'foxguard',
			[
				'--no-builtins',
				'--rules',
				scratch,
				'--severity',
				FOXGUARD_BLOCKING_SEVERITY,
				'--format',
				'json',
				...excludes,
				root,
			],
			{ encoding: 'utf8', timeout: FOXGUARD_TIMEOUT_MS, env: { ...process.env, RAYON_NUM_THREADS: '2' } },
		);
		if (run.error) {
			throw new GuardError(
				run.error.code === 'ENOENT'
					? 'foxguard is not on PATH; run through `mise run vendor-guard` (mise installs the pinned version)'
					: `foxguard failed to run (${run.error.code ?? run.error.message}) within ${FOXGUARD_TIMEOUT_MS / 1000}s`,
			);
		}
		// foxguard warns and exits 0 when a rule file does not parse, which would pass every file.
		if (FOXGUARD_LOAD_FAILURE.test(run.stderr ?? '')) {
			throw new GuardError(`foxguard did not load the generated rules (failing closed): ${run.stderr.trim().slice(0, 400)}`);
		}
		let report;
		try {
			report = JSON.parse(run.stdout);
		} catch {
			throw new GuardError(
				`foxguard exited ${run.status} without a JSON report: ${(run.stderr || run.stdout).slice(0, 400)}`,
			);
		}
		return (report.findings ?? []).map((finding) => {
			const rootName = finding.rule_id.split(RULE_ID_PREFIX)[1] ?? '';
			return {
				where: `${path.relative(root, path.resolve(root, finding.file))}:${finding.line}`,
				problem: `unprefixed ${rootName} reference: ${finding.snippet.trim()}`,
				fix: `name the bundled copy: ${strauss.namespace_prefix}${rootName}\\… (a global ${rootName}_Name class is ${strauss.classmap_prefix}${rootName}_Name); see ${README_ANCHOR}`,
			};
		});
	} finally {
		fs.rmSync(scratch, { recursive: true, force: true });
	}
}

/** Runs the existing load-order collision test. Returns findings. */
export function collisionFindings(root) {
	const run = spawnSync('node', ['--test', COLLISION_TEST], {
		cwd: root,
		encoding: 'utf8',
		timeout: COLLISION_TIMEOUT_MS,
	});
	if (run.status === 0) {
		return [];
	}
	const tail = `${run.stdout}${run.stderr}`.split('\n').slice(-OUTPUT_TAIL_LINES).join('\n');
	const timedOut = run.error?.code === 'ETIMEDOUT';
	return [
		{
			where: COLLISION_TEST,
			problem: timedOut
				? `the collision test did not finish within ${COLLISION_TIMEOUT_MS / 1000}s`
				: `the collision test failed (exit ${run.status}):\n${tail}`,
			fix: 'run `composer install` to rebuild vendor-prefixed/, then `node --test tests/unit/vendor-prefix-collision.test.mjs`',
		},
	];
}

export function formatFindings(findings) {
	return findings.map(({ where, problem, fix }) => `  ${where}\n    ${problem}\n    fix: ${fix}`).join('\n');
}

function parseArgs(argv) {
	const options = { root: process.cwd(), built: false, pushed: false, files: [] };
	for (let i = 0; i < argv.length; i++) {
		const arg = argv[i];
		if (arg === '--root') {
			options.root = path.resolve(argv[++i] ?? '');
		} else if (arg === '--built') {
			options.built = true;
		} else if (arg === '--pushed') {
			options.pushed = true;
		} else if (arg === '--') {
			options.files = argv.slice(i + 1);
			break;
		} else {
			throw new GuardError(`unknown argument ${JSON.stringify(arg)}`);
		}
	}
	return options;
}

export function main(argv) {
	const options = parseArgs(argv);
	const buildInputsChanged = options.pushed && options.files.some((file) => BUILD_INPUTS.includes(file));
	const findings = [...structureFindings(options.root), ...ruleFindings(options.root)];
	if (options.built || buildInputsChanged) {
		const built = builtFindings(options.root);
		findings.push(...built);
		if (buildInputsChanged && built.length === 0) {
			findings.push(...collisionFindings(options.root));
		}
	}
	if (findings.length > 0) {
		process.stderr.write(
			`vendor-prefix-guard: ${findings.length} problem(s). Third-party code must stay under the DragonGate\\Vendor\\ prefix (ZYS-2607).\n${formatFindings(findings)}\n`,
		);
		return 1;
	}
	const checked = buildInputsChanged
		? 'rule, structure, built tree, collision test'
		: options.built
			? 'rule, structure, built tree'
			: 'rule, structure';
	process.stdout.write(`vendor-prefix-guard: ok (${checked})\n`);
	return 0;
}

if (process.argv[1] && fs.realpathSync(process.argv[1]) === fs.realpathSync(fileURLToPath(import.meta.url))) {
	try {
		process.exitCode = main(process.argv.slice(2));
	} catch (error) {
		if (!(error instanceof GuardError)) {
			throw error;
		}
		process.stderr.write(`vendor-prefix-guard: cannot run: ${error.message}\n`);
		process.exitCode = 2;
	}
}
