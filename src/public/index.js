/**
 * Public file-confirm island + Bits field enhancement.
 * Independent of the admin wizard bundle.
 */
import { mount } from 'svelte';
import PreviewDialog from './PreviewDialog.svelte';
import { previewApi } from './preview-api.js';
import Field from './Field.svelte';
import CheckField from './CheckField.svelte';

const PREVIEW_ROOT = 'data-dg-preview-root';
const FILE_TYPES = new Set([
	'file',
	'score_file',
	'recording_file',
	'bio_file',
	'image',
	'audio',
]);
const NATIVE_SELECT_IDS = new Set(['sub_country', 'gds-cr-one', 'sub_state']);

function previewRoot() {
	let root = document.querySelector(`[${PREVIEW_ROOT}]`);
	if (root) {
		return root;
	}
	root = document.createElement('div');
	root.setAttribute(PREVIEW_ROOT, '');
	document.body.appendChild(root);
	return root;
}

function labelTextOf(label) {
	if (!label) {
		return '';
	}
	const clone = label.cloneNode(true);
	clone.querySelectorAll('.dg-req, .caption').forEach((el) => el.remove());
	return (clone.textContent || '').trim();
}

function enhanceTextControl(input) {
	if (!input || input.getAttribute('data-dg-bits') === '1') {
		return;
	}
	if (NATIVE_SELECT_IDS.has(input.id) || input.tagName === 'SELECT') {
		return;
	}
	if (input.type === 'file' || input.type === 'hidden' || input.type === 'radio') {
		return;
	}
	const wrapper = input.closest('.dg-field') || input.parentElement;
	const label = wrapper ? wrapper.querySelector(`label[for="${input.id}"]`) : null;
	const mountPoint = document.createElement('div');
	mountPoint.setAttribute('data-dg-bits-field', '');
	input.parentNode.insertBefore(mountPoint, input);
	const required = input.required;
	const multiline = input.tagName === 'TEXTAREA';
	const text = labelTextOf(label);
	mount(Field, {
		target: mountPoint,
		props: {
			id: input.id,
			name: input.name,
			label: required && text ? `${text} *` : text,
			type: input.type || 'text',
			value: input.value,
			required,
			multiline,
			autocomplete: input.getAttribute('autocomplete') || '',
			maxlength: input.maxLength > 0 ? input.maxLength : undefined,
		},
	});
	input.setAttribute('data-dg-bits', '1');
	input.remove();
	if (label) {
		label.remove();
	}
}

function enhanceDisclaimer(wrapper) {
	const input = wrapper.querySelector('input[type="checkbox"]');
	if (!input || input.getAttribute('data-dg-bits') === '1') {
		return;
	}
	const span = wrapper.querySelector('.dg-check span');
	const mountPoint = document.createElement('div');
	mountPoint.setAttribute('data-dg-bits-field', '');
	const host = wrapper.querySelector('.dg-check') || input;
	host.parentNode.insertBefore(mountPoint, host);
	mount(CheckField, {
		target: mountPoint,
		props: {
			id: input.id,
			name: input.name,
			label: span ? span.textContent : labelTextOf(wrapper.querySelector('label')),
			required: input.required,
			checked: input.checked,
		},
	});
	input.setAttribute('data-dg-bits', '1');
	if (host.classList && host.classList.contains('dg-check')) {
		host.remove();
	} else {
		input.remove();
	}
}

function enhanceFields() {
	const form = document.querySelector('[data-dg-form], [data-dg-render="definition"]');
	if (!form) {
		return;
	}
	form.querySelectorAll('[data-dg-field-type]').forEach((wrapper) => {
		const type = wrapper.getAttribute('data-dg-field-type') || '';
		if (FILE_TYPES.has(type) || type === 'static_html' || type === 'group') {
			return;
		}
		if (type === 'disclaimer') {
			enhanceDisclaimer(wrapper);
			return;
		}
		if (type === 'branch') {
			return;
		}
		if (type === 'applicant_pack') {
			wrapper.querySelectorAll('input.dg-control, textarea.dg-control').forEach(enhanceTextControl);
			return;
		}
		wrapper.querySelectorAll('input.dg-control, textarea.dg-control').forEach(enhanceTextControl);
	});
}

function initPreview() {
	mount(PreviewDialog, { target: previewRoot() });
	globalThis.DGPreview = {
		open(options) {
			if (typeof previewApi.open !== 'function') {
				throw new Error('file preview: dialog unavailable');
			}
			previewApi.open(options);
		},
	};
	enhanceFields();
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initPreview);
} else {
	initPreview();
}
