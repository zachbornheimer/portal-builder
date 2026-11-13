/**
 * Public definition form — file enclosure (empty / ready, name, size, keyboard).
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

	function bindFileCard(card) {
		var input = card.querySelector('input[type="file"]');
		if (!input) {
			return;
		}
		var nameEl = card.querySelector('[data-dg-file-name]');
		var sizeEl = card.querySelector('[data-dg-file-size]');

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
		}

		input.addEventListener('change', sync);

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
			if (event.target === input) {
				return;
			}
			event.preventDefault();
			input.click();
		});

		sync();
	}

	function init() {
		var cards = document.querySelectorAll('.dg-file');
		for (var i = 0; i < cards.length; i += 1) {
			bindFileCard(cards[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
