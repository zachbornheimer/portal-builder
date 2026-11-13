/**
 * Public definition form — file enclosure + open-to-confirm + branch paths.
 *
 * After a file is chosen: show name/size, open a local blob so the applicant
 * can prove it isn’t corrupt, then require an explicit confirm. Anonymize
 * (strip identifiers before Drive) is a hook — `window.dgPrepareUploadedFile`.
 *
 * Branch radios show only the selected path’s `.dg-branch-children` and disable
 * hidden controls so HTML5 `required` cannot block submit.
 */
(function () {
	'use strict';

	var KB = 1024;
	var MB = 1024 * 1024;

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

	function prepareUploadedFile(file, card) {
		var hook = window.dgPrepareUploadedFile;
		if (typeof hook !== 'function') {
			return Promise.resolve(file);
		}
		return Promise.resolve(hook(file, card));
	}

	function bindFileCard(card) {
		var input = card.querySelector('input[type="file"]');
		if (!input) {
			return;
		}
		var nameEl = card.querySelector('[data-dg-file-name]');
		var sizeEl = card.querySelector('[data-dg-file-size]');
		var openBtn = card.querySelector('[data-dg-file-open]');
		var swapBtn = card.querySelector('[data-dg-file-swap]');
		var confirm = card.querySelector('[data-dg-file-confirm]');
		var copyEl = card.querySelector('[data-dg-file-confirm-copy]');
		var objectUrl = '';

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

		function resetConfirm() {
			card.classList.remove('is-opened', 'is-confirmed', 'is-invalid');
			if (confirm) {
				confirm.checked = false;
				confirm.disabled = true;
				confirm.required = false;
			}
			setCopy('idle');
		}

		function sync() {
			var file = input.files && input.files[0];
			card.classList.toggle('is-empty', !file);
			card.classList.toggle('is-ready', !!file);
			if (nameEl) {
				nameEl.textContent = file ? file.name : '';
			}
			if (sizeEl) {
				sizeEl.textContent = file ? formatSize(file.size) : '';
			}
			revokeUrl();
			resetConfirm();
			if (file) {
				prepareUploadedFile(file, card).catch(function () {
					/* Anonymize is optional until the API exists. */
				});
			}
		}

		input.addEventListener('change', sync);

		if (openBtn) {
			openBtn.addEventListener('click', function () {
				var file = input.files && input.files[0];
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
			for (var i = 0; i < cards.length; i += 1) {
				var confirm = cards[i].querySelector('[data-dg-file-confirm]');
				if (confirm && !confirm.checked) {
					cards[i].classList.add('is-invalid');
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
