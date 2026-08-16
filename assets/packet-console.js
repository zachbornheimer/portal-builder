/**
 * Staff + applicant packet console: recall and replace via REST.
 *
 * Keyboard-operable buttons; no pointer-only handlers.
 */
(function () {
	'use strict';

	function config() {
		return window.dgPacketConsole && typeof window.dgPacketConsole === 'object'
			? window.dgPacketConsole
			: {};
	}

	function restBase() {
		var cfg = config();
		return cfg.restBase ? String(cfg.restBase) : '';
	}

	function nonce() {
		var cfg = config();
		return cfg.nonce ? String(cfg.nonce) : '';
	}

	function closestPacket(el) {
		return el.closest('tr') || el.closest('li') || el.parentElement;
	}

	function postRecall(button) {
		var app = button.getAttribute('data-dg-recall') || '';
		var base = restBase();
		if (!app || !base) {
			return;
		}
		button.disabled = true;
		fetch(base + encodeURIComponent(app) + '/recall', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce() },
		}).then(function (res) {
			if (!res.ok) {
				throw new Error('recall failed');
			}
			window.location.reload();
		}).catch(function () {
			button.disabled = false;
			button.setAttribute('aria-invalid', 'true');
		});
	}

	function postReplace(button) {
		var app = button.getAttribute('data-dg-replace') || '';
		var base = restBase();
		var row = closestPacket(button);
		if (!app || !base || !row) {
			return;
		}
		var field = row.querySelector('input[name="field_id"]');
		var file = row.querySelector('input[name="file"]');
		if (!field || !file || !file.files || !file.files[0] || !String(field.value || '').trim()) {
			button.setAttribute('aria-invalid', 'true');
			return;
		}
		var body = new FormData();
		body.append('field_id', String(field.value).trim());
		body.append('file', file.files[0]);
		button.disabled = true;
		fetch(base + encodeURIComponent(app) + '/replace', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce() },
			body: body,
		}).then(function (res) {
			if (!res.ok) {
				throw new Error('replace failed');
			}
			window.location.reload();
		}).catch(function () {
			button.disabled = false;
			button.setAttribute('aria-invalid', 'true');
		});
	}

	function onClick(event) {
		var recall = event.target.closest('[data-dg-recall]');
		if (recall) {
			event.preventDefault();
			postRecall(recall);
			return;
		}
		var replace = event.target.closest('[data-dg-replace]');
		if (replace) {
			event.preventDefault();
			postReplace(replace);
		}
	}

	function init() {
		var roots = document.querySelectorAll('[data-dg-applicant-packets], [data-dg-packet-console]');
		for (var i = 0; i < roots.length; i += 1) {
			roots[i].addEventListener('click', onClick);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
