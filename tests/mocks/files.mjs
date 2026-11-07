/**
 * Filesystem facade for mock stores.
 * Inject in tests; production mocks use node:fs defaults.
 */
import fs from 'node:fs';
import path from 'node:path';

/**
 * @typedef {object} Files
 * @property {(p: string, opts?: { recursive?: boolean }) => void} mkdir
 * @property {(p: string, data: string | Buffer) => void} write
 * @property {(p: string, data: string) => void} append
 * @property {(p: string, encoding: BufferEncoding) => string} readText
 * @property {(p: string) => boolean} exists
 * @property {(p: string) => void} remove
 */

/** @type {Files} */
export const nodeFiles = {
	mkdir(p, opts) {
		fs.mkdirSync(p, opts);
	},
	write(p, data) {
		fs.writeFileSync(p, data);
	},
	append(p, data) {
		fs.appendFileSync(p, data);
	},
	readText(p, encoding) {
		return fs.readFileSync(p, encoding);
	},
	exists(p) {
		return fs.existsSync(p);
	},
	remove(p) {
		fs.rmSync(p, { force: true, recursive: true });
	},
};

/**
 * @param {string[]} parts
 * @returns {string}
 */
export function joinPath(...parts) {
	return path.join(...parts);
}

/**
 * @param {string} p
 * @returns {string}
 */
export function dirnameOf(p) {
	return path.dirname(p);
}
