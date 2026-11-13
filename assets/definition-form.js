/**
 * Public definition form — file enclosure + open-to-confirm.
 *
 * After a file is chosen: show name/size, open a local blob so the applicant
 * can prove it isn’t corrupt, then require an explicit confirm. Anonymize
 * (strip identifiers before Drive) is a hook — `window.dgPrepareUploadedFile`.
 */
(function () {
	'use strict';

	var KB = 1024;
	var MB = 1024 * 1024;

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
			}
		});
	}

	function init() {
		var cards = document.querySelectorAll('.dg-file');
		for (var i = 0; i < cards.length; i += 1) {
			bindFileCard(cards[i]);
		}
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
