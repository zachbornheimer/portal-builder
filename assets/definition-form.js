/**
 * Public definition form — file enclosure + open-to-confirm + branch paths.
 *
 * After a file is chosen: stage bytes (XHR progress → anonymize spinner →
 * retained server name), open the staged file to prove it isn’t corrupt, then
 * require an explicit confirm.
 *
 * Branch radios show only the selected path’s `.dg-branch-children` and disable
 * hidden controls so HTML5 `required` cannot block submit.
 */
(function () {
	'use strict';

	var KB = 1024;
	var MB = 1024 * 1024;
	var STATUS_WORKING = 'Processing';
	var STAGE_FAIL = 'Upload failed. Replace the file and try again.';

	/**
	 * DOM-free visibility plan (mirrors src/wizard/branchVisibility.js).
	 * @param {string} selectedId
	 * @param {string[]} optionIds
	 * @returns {{ id: string, visible: boolean }[]}
	 */
	function visibleBranchOptions(selectedId, optionIds) {
		var selected = selectedId == null ? '' : String(selectedId);
		var out = [];
		for (var i = 0; i < optionIds.length; i += 1) {
			var id = optionIds[i];
			out.push({ id: id, visible: id === selected && selected !== '' });
		}
		return out;
	}

	function formatSize(bytes) {
		if (bytes < KB) {
			return String(bytes) + ' B';
		}
		if (bytes < MB) {
			return trimFixed(bytes / KB) + ' KB';
		}
		return trimFixed(bytes / MB) + ' MB';
	}

	function trimFixed(value) {
		return value.toFixed(1).replace(/\.0$/, '');
	}

	function publicConfig() {
		return window.dgPublicForm && typeof window.dgPublicForm === 'object'
			? window.dgPublicForm
			: {};
	}

	function stageUrl() {
		var cfg = publicConfig();
		return cfg.stageUrl ? String(cfg.stageUrl) : '';
	}

	function bindFileCard(card) {
		var input = card.querySelector('input[type="file"]');
		if (!input) {
			return;
		}
		var nameEl = card.querySelector('[data-dg-file-name]');
		var sizeEl = card.querySelector('[data-dg-file-size]');
		var originalEl = card.querySelector('[data-dg-file-original]');
		var sepEl = card.querySelector('[data-dg-file-sep]');
		var openBtn = card.querySelector('[data-dg-file-open]');
		var swapBtn = card.querySelector('[data-dg-file-swap]');
		var confirm = card.querySelector('[data-dg-file-confirm]');
		var copyEl = card.querySelector('[data-dg-file-confirm-copy]');
		var stagedInput = card.querySelector('[data-dg-file-staged]');
		var progressWrap = card.querySelector('[data-dg-file-progress]');
		var progressBar = card.querySelector('[data-dg-file-progress-bar]');
		var statusEl = card.querySelector('[data-dg-file-status]');
		var objectUrl = '';
		var stagedUrl = '';
		var xhr = null;
		var stagedToken = '';

		function revokeUrl() {
			if (objectUrl) {
				URL.revokeObjectURL(objectUrl);
				objectUrl = '';
			}
		}

		function setCopy(mode) {
			if (!copyEl) {
				return;
			}
			var idle = copyEl.getAttribute('data-idle') || '';
			var ready = copyEl.getAttribute('data-ready') || '';
			copyEl.textContent = mode === 'ready' ? ready : idle;
		}

		function setProgress(pct) {
			if (progressBar) {
				progressBar.style.width = Math.max(0, Math.min(100, pct)) + '%';
			}
			if (progressWrap) {
				if (pct >= 0) {
					progressWrap.hidden = false;
					progressWrap.removeAttribute('hidden');
				} else {
					progressWrap.hidden = true;
					progressWrap.setAttribute('hidden', '');
				}
			}
		}

		function setStatus(text) {
			if (!statusEl) {
				return;
			}
			if (text) {
				statusEl.textContent = text;
				statusEl.hidden = false;
				statusEl.removeAttribute('hidden');
			} else {
				statusEl.textContent = '';
				statusEl.hidden = true;
				statusEl.setAttribute('hidden', '');
			}
		}

		function forgetRemoteToken(token) {
			if (!token) {
				return;
			}
			var cfg = publicConfig();
			var base = cfg.stageUrl
				? String(cfg.stageUrl).replace(/\/portals\/\d+\/files\/?$/, '/files/')
				: '';
			if (!base || typeof XMLHttpRequest === 'undefined') {
				return;
			}
			try {
				var del = new XMLHttpRequest();
				del.open('DELETE', base + encodeURIComponent(token), true);
				if (cfg.nonce) {
					del.setRequestHeader('X-WP-Nonce', cfg.nonce);
				}
				del.send();
			} catch (err) {
				/* best-effort */
			}
		}

		function clearStaged(forgetRemote) {
			if (forgetRemote && stagedToken) {
				forgetRemoteToken(stagedToken);
			}
			stagedToken = '';
			stagedUrl = '';
			if (stagedInput) {
				stagedInput.value = '';
			}
			card.classList.remove('is-staged', 'is-uploading', 'is-working');
			setProgress(-1);
			setStatus('');
		}

		function abortXhr() {
			if (xhr) {
				try {
					xhr.abort();
				} catch (err) {
					/* ignore */
				}
				xhr = null;
			}
		}

		function resetConfirm() {
			card.classList.remove('is-opened', 'is-confirmed', 'is-invalid');
			if (confirm) {
				confirm.checked = false;
				confirm.disabled = true;
				confirm.required = false;
			}
			setCopy('idle');
		}

		function showLocalMeta(file) {
			if (nameEl) {
				nameEl.textContent = file ? file.name : '';
			}
			if (sizeEl) {
				sizeEl.textContent = file ? formatSize(file.size) : '';
			}
			if (originalEl) {
				originalEl.textContent = '';
			}
			if (sepEl) {
				sepEl.hidden = true;
				sepEl.setAttribute('hidden', '');
			}
		}

		function showStagedMeta(payload, localFile) {
			var stored = payload.storedName || (localFile && localFile.name) || '';
			var original = payload.originalName || (localFile && localFile.name) || '';
			var bytes = typeof payload.bytes === 'number' ? payload.bytes : (localFile ? localFile.size : 0);
			if (nameEl) {
				nameEl.textContent = stored;
			}
			if (sizeEl) {
				sizeEl.textContent = formatSize(bytes);
			}
			if (originalEl) {
				originalEl.textContent = original;
			}
			if (sepEl) {
				sepEl.hidden = false;
				sepEl.removeAttribute('hidden');
			}
		}

		function setError(message) {
			card.classList.add('is-invalid');
			var err = card.querySelector('.dg-file-error');
			if (err && message) {
				err.textContent = message;
			}
		}

		function stageFile(file) {
			var url = stageUrl();
			if (!url) {
				// No stage endpoint configured (CLI/static render): local-only path.
				card.classList.add('is-ready');
				return;
			}

			abortXhr();
			clearStaged(true);
			card.classList.add('is-ready', 'is-uploading');
			card.classList.remove('is-invalid', 'is-working', 'is-staged');
			setProgress(0);
			setStatus('');

			var fieldId = card.getAttribute('data-dg-field-id') || '';
			var body = new FormData();
			body.append('file', file, file.name);
			body.append('field_id', fieldId);
			var cfg = publicConfig();
			if (cfg.nonce) {
				body.append('_wpnonce', cfg.nonce);
			}

			xhr = new XMLHttpRequest();
			xhr.open('POST', url, true);
			if (cfg.nonce) {
				xhr.setRequestHeader('X-WP-Nonce', cfg.nonce);
			}

			function enterWorking() {
				card.classList.remove('is-uploading');
				card.classList.add('is-working');
				setProgress(-1);
				setStatus(STATUS_WORKING);
			}

			xhr.upload.addEventListener('progress', function (event) {
				if (!event.lengthComputable) {
					return;
				}
				var pct = event.total ? (event.loaded / event.total) * 100 : 0;
				setProgress(pct);
				// Progress may report 100% before the request finishes sending.
				if (event.loaded >= event.total) {
					enterWorking();
				}
			});

			// Bytes finished sending; server is still anonymizing / responding.
			// Tiny uploads often never fire progress at 100% — this is the reliable edge.
			xhr.upload.addEventListener('load', function () {
				enterWorking();
			});

			xhr.addEventListener('load', function () {
				var req = xhr;
				xhr = null;
				card.classList.remove('is-uploading');
				var ok = req.status >= 200 && req.status < 300;
				var payload = null;
				try {
					payload = JSON.parse(req.responseText || '{}');
				} catch (err) {
					payload = null;
				}
				if (!ok || !payload || !payload.token) {
					card.classList.remove('is-working', 'is-staged');
					setProgress(-1);
					setStatus('');
					setError(STAGE_FAIL);
					return;
				}
				stagedToken = String(payload.token);
				stagedUrl = payload.url ? String(payload.url) : '';
				if (stagedInput) {
					stagedInput.value = stagedToken;
				}
				card.classList.remove('is-working');
				card.classList.add('is-ready', 'is-staged');
				setProgress(-1);
				setStatus('');
				showStagedMeta(payload, file);
			});

			xhr.addEventListener('error', function () {
				xhr = null;
				card.classList.remove('is-uploading', 'is-working', 'is-staged');
				setProgress(-1);
				setStatus('');
				setError(STAGE_FAIL);
			});

			xhr.addEventListener('abort', function () {
				xhr = null;
				card.classList.remove('is-uploading', 'is-working');
				setProgress(-1);
				setStatus('');
			});

			xhr.send(body);
		}

		function sync() {
			var file = input.files && input.files[0];
			abortXhr();
			// stageFile clears+forgets; empty pick only clears local state.
			if (!file) {
				clearStaged(true);
			}
			card.classList.toggle('is-empty', !file);
			card.classList.toggle('is-ready', !!file);
			showLocalMeta(file);
			revokeUrl();
			resetConfirm();
			if (file) {
				stageFile(file);
			}
		}

		input.addEventListener('change', sync);

		if (openBtn) {
			openBtn.addEventListener('click', function () {
				var file = input.files && input.files[0];
				var requiresStage = !!stageUrl();
				// When staging is configured, wait for a successful stage before open.
				if (requiresStage && !stagedToken) {
					return;
				}
				// Prefer staged bytes when available.
				if (stagedUrl) {
					var openedStaged = window.open(stagedUrl, '_blank', 'noopener');
					if (!openedStaged) {
						window.location.assign(stagedUrl);
					}
					card.classList.add('is-opened');
					card.classList.remove('is-invalid');
					if (confirm) {
						confirm.disabled = false;
						confirm.required = true;
					}
					setCopy('ready');
					return;
				}
				if (requiresStage && stagedToken && !stagedUrl) {
					// Token without URL: build GET from stage root when possible.
					var cfg = publicConfig();
					var base = cfg.stageUrl ? String(cfg.stageUrl).replace(/\/portals\/\d+\/files\/?$/, '/files/') : '';
					if (base) {
						var tokenUrl = base + encodeURIComponent(stagedToken);
						var openedTok = window.open(tokenUrl, '_blank', 'noopener');
						if (!openedTok) {
							window.location.assign(tokenUrl);
						}
						card.classList.add('is-opened');
						card.classList.remove('is-invalid');
						if (confirm) {
							confirm.disabled = false;
							confirm.required = true;
						}
						setCopy('ready');
						return;
					}
				}
				if (!file) {
					return;
				}
				revokeUrl();
				objectUrl = URL.createObjectURL(file);
				var opened = window.open(objectUrl, '_blank', 'noopener');
				if (!opened) {
					window.location.assign(objectUrl);
				}
				card.classList.add('is-opened');
				card.classList.remove('is-invalid');
				if (confirm) {
					confirm.disabled = false;
					confirm.required = true;
				}
				setCopy('ready');
			});
		}

		if (swapBtn) {
			swapBtn.addEventListener('click', function () {
				abortXhr();
				clearStaged(true);
				input.click();
			});
		}

		if (confirm) {
			confirm.addEventListener('change', function () {
				card.classList.toggle('is-confirmed', !!confirm.checked);
				if (confirm.checked) {
					card.classList.remove('is-invalid');
				}
			});
		}

		card.addEventListener('dragover', function (event) {
			event.preventDefault();
			card.classList.add('is-dragover');
		});
		card.addEventListener('dragleave', function () {
			card.classList.remove('is-dragover');
		});
		card.addEventListener('drop', function (event) {
			event.preventDefault();
			card.classList.remove('is-dragover');
			if (!event.dataTransfer || !event.dataTransfer.files.length) {
				return;
			}
			try {
				input.files = event.dataTransfer.files;
			} catch (err) {
				return;
			}
			input.dispatchEvent(new Event('change', { bubbles: true }));
		});

		card.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter' && event.key !== ' ') {
				return;
			}
			if (event.target !== card.querySelector('.dg-file-pick') && event.target !== card) {
				return;
			}
			event.preventDefault();
			input.click();
		});

		sync();
	}

	function bindSubmitGate(form) {
		form.addEventListener('submit', function (event) {
			var cards = form.querySelectorAll('.dg-file.is-ready');
			var blocked = false;
			var requireStage = !!stageUrl();
			for (var i = 0; i < cards.length; i += 1) {
				var card = cards[i];
				var confirm = card.querySelector('[data-dg-file-confirm]');
				var staged = card.querySelector('[data-dg-file-staged]');
				var missingConfirm = confirm && !confirm.checked;
				var missingToken = requireStage && staged && !staged.value;
				if (missingConfirm || missingToken) {
					card.classList.add('is-invalid');
					blocked = true;
				}
			}
			if (blocked) {
				event.preventDefault();
				var first = form.querySelector('.dg-file.is-invalid');
				if (first) {
					first.scrollIntoView({ block: 'center' });
				}
				return;
			}
			var submit = form.querySelector('[type="submit"]');
			if (submit && !submit.disabled) {
				submit.disabled = true;
				submit.setAttribute('data-dg-working', '1');
				if (!submit.getAttribute('data-dg-label')) {
					submit.setAttribute('data-dg-label', submit.textContent || 'Submit');
				}
				submit.textContent = 'Working…';
			}
		});
	}

	/**
	 * Direct child panels of a fieldset (not nested branch panels).
	 * @param {HTMLFieldSetElement} fieldset
	 * @returns {Element[]}
	 */
	function directBranchPanels(fieldset) {
		var out = [];
		var kids = fieldset.children;
		for (var i = 0; i < kids.length; i += 1) {
			var el = kids[i];
			if (el.classList && el.classList.contains('dg-branch-children')) {
				out.push(el);
			}
		}
		return out;
	}

	/**
	 * Enable or disable form controls inside a panel.
	 * Skips controls that belong to a nested hidden branch panel.
	 * @param {Element} panel
	 * @param {boolean} enabled
	 */
	function setPanelControlsEnabled(panel, enabled) {
		var controls = panel.querySelectorAll('input, select, textarea, button');
		for (var i = 0; i < controls.length; i += 1) {
			var control = controls[i];
			var nestedHidden = control.closest('.dg-branch-children[hidden]');
			if (nestedHidden && nestedHidden !== panel) {
				continue;
			}
			if (enabled) {
				if (control.getAttribute('data-dg-was-disabled') === '1') {
					control.removeAttribute('data-dg-was-disabled');
					control.disabled = false;
				}
			} else if (!control.disabled) {
				control.setAttribute('data-dg-was-disabled', '1');
				control.disabled = true;
			}
		}
	}

	/**
	 * Bind one branch fieldset: radios toggle sibling `.dg-branch-children`.
	 * @param {Element} root  [data-dg-field-type="branch"]
	 */
	function bindBranchField(root) {
		var fieldset = root.querySelector('fieldset');
		if (!fieldset) {
			return;
		}
		var panels = directBranchPanels(fieldset);
		if (!panels.length) {
			return;
		}
		var optionIds = [];
		for (var p = 0; p < panels.length; p += 1) {
			optionIds.push(panels[p].getAttribute('data-dg-branch-option') || '');
		}
		var radios = fieldset.querySelectorAll('input[type="radio"]');
		// Only radios whose name matches this branch (first radio’s name).
		var branchName = radios.length ? radios[0].name : '';
		var ownRadios = [];
		for (var r = 0; r < radios.length; r += 1) {
			// Skip radios that live inside a nested branch panel.
			var inPanel = false;
			for (var q = 0; q < panels.length; q += 1) {
				if (panels[q].contains(radios[r])) {
					inPanel = true;
					break;
				}
			}
			if (!inPanel && radios[r].name === branchName) {
				ownRadios.push(radios[r]);
			}
		}

		function selectedId() {
			for (var i = 0; i < ownRadios.length; i += 1) {
				if (ownRadios[i].checked) {
					return ownRadios[i].value;
				}
			}
			return '';
		}

		function apply() {
			var plan = visibleBranchOptions(selectedId(), optionIds);
			for (var i = 0; i < panels.length; i += 1) {
				var panel = panels[i];
				var optId = panel.getAttribute('data-dg-branch-option') || '';
				var visible = false;
				for (var j = 0; j < plan.length; j += 1) {
					if (plan[j].id === optId) {
						visible = plan[j].visible;
						break;
					}
				}
				if (visible) {
					panel.removeAttribute('hidden');
					setPanelControlsEnabled(panel, true);
				} else {
					panel.setAttribute('hidden', '');
					setPanelControlsEnabled(panel, false);
				}
			}
		}

		for (var i = 0; i < ownRadios.length; i += 1) {
			ownRadios[i].addEventListener('change', apply);
		}
		apply();
	}

	function bindBranches(root) {
		var branches = (root || document).querySelectorAll('[data-dg-field-type="branch"]');
		for (var i = 0; i < branches.length; i += 1) {
			bindBranchField(branches[i]);
		}
	}

	function init() {
		var cards = document.querySelectorAll('.dg-file');
		for (var i = 0; i < cards.length; i += 1) {
			bindFileCard(cards[i]);
		}
		bindBranches(document);
		var form = document.querySelector('.dg-packet')
			? document.querySelector('.dg-packet').closest('form')
			: document.querySelector('form');
		if (form) {
			bindSubmitGate(form);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
