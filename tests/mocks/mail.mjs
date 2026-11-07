/**
 * Mail capture facade — writes messages under artifactDir/mail/*.json.
 * Real mailer lands in Phase 3; this is the test-mode path.
 */
import {
	ARTIFACT_MAIL,
	MAIL_EXTENSION,
	MAIL_UNKNOWN_RECIPIENT,
} from './constants.mjs';
import { systemClock } from './clock.mjs';
import { joinPath, nodeFiles } from './files.mjs';

/**
 * @param {string} artifactDir
 * @param {{ to?: string, subject?: string, [key: string]: unknown }} message
 * @param {{
 *   files?: import('./files.mjs').Files,
 *   clock?: import('./clock.mjs').Clock,
 * }} [deps]
 * @returns {string} path written
 */
export function captureMail(artifactDir, message, deps = {}) {
	const files = deps.files ?? nodeFiles;
	const clock = deps.clock ?? systemClock;
	const dir = joinPath(artifactDir, ARTIFACT_MAIL);
	files.mkdir(dir, { recursive: true });
	const recipient = message.to || MAIL_UNKNOWN_RECIPIENT;
	const dest = joinPath(dir, `${clock.nowMs()}-${recipient}${MAIL_EXTENSION}`);
	files.write(dest, JSON.stringify(message, null, 2));
	return dest;
}
