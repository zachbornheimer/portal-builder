import { handleWaitlistRequest, pathnameWithoutTrailingSlash } from './waitlist.js';

const API_PREFIX = '/api/';

export default {
	/**
	 * @param {Request} request
	 * @param {{ WAITLIST: KVNamespace, ASSETS: { fetch: (request: Request) => Promise<Response> } }} env
	 * @returns {Promise<Response>}
	 */
	async fetch(request, env) {
		const path = pathnameWithoutTrailingSlash(new URL(request.url).pathname);
		if (path.startsWith(API_PREFIX)) {
			return handleWaitlistRequest(request, env.WAITLIST);
		}
		return env.ASSETS.fetch(request);
	},
};
