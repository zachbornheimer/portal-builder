import { mount, unmount } from 'svelte';
import PortalBuilder from './PortalBuilder.svelte';
import WizardShell from './wizard/WizardShell.svelte';
import './styles.css';

const UNMOUNT_KEY = '_dgUnmount';

/**
 * Mount PortalBuilder on each [data-segmented-control] (legacy map UI).
 */
function initPortalBuilder() {
	const containers = document.querySelectorAll('[data-segmented-control]');

	containers.forEach((container) => {
		if (container[UNMOUNT_KEY]) {
			return;
		}

		// Find the data table in the same form group
		const formGroup = container.closest('.form-group');
		const dataTable = formGroup ? formGroup.querySelector('.data-table') : null;

		if (!dataTable) {
			console.warn('No data table found for segmented control');
			return;
		}

		// Get meta key from container attributes
		const metaKey = container.getAttribute('data-meta-key') || '';

		// Detect configuration type based on meta key or columns
		let type = 'sheets'; // default
		if (metaKey.includes('file_backups') || metaKey.includes('drive')) {
			type = 'drive';
		} else if (metaKey.includes('record_keeping') || metaKey.includes('sheet')) {
			type = 'sheets';
		}

		// Extract columns from the data table or use default columns
		const columnHeaders = dataTable.querySelectorAll('label');
		let columns = Array.from(columnHeaders).map((header) => header.textContent.trim());

		// If no columns found, use default columns based on the data structure
		if (columns.length === 0) {
			columns =
				type === 'sheets'
					? ['Sheet Name', 'Google Sheet ID', 'Columns']
					: ['Google Drive Folder Name', 'Google Drive Folder ID'];
		}

		// Extract existing data from hidden input
		const hiddenInput = formGroup.querySelector(`input[name="${metaKey}"]`);
		let initialData = [];

		if (hiddenInput && hiddenInput.value) {
			try {
				const rawData = JSON.parse(hiddenInput.value);
				initialData = Array.isArray(rawData) ? rawData : [];
			} catch (e) {
				console.warn('Failed to parse existing data:', e);
				initialData = [];
			}
		}

		// Convert WordPress data format to Sheet format
		initialData = initialData
			.map((row, index) => {
				const sheet = {
					id: `sheet_${Date.now()}_${index}`,
					name: row[0] || '[New Sheet]',
					fields: {},
				};

				if (type === 'sheets') {
					sheet.sheetId = row[1] || '';
					sheet.columns = row[2] || '';
				} else if (type === 'drive') {
					sheet.folderId = row[1] || '';
				}

				return sheet;
			})
			.filter((sheet) => {
				let isEmpty = sheet.name === '[New Sheet]';

				if (type === 'sheets') {
					isEmpty = isEmpty && !sheet.sheetId && !sheet.columns;
				} else if (type === 'drive') {
					isEmpty = isEmpty && !sheet.folderId;
				}

				return !isEmpty;
			});

		const app = mount(PortalBuilder, {
			target: container,
			props: {
				columns,
				initialData: initialData.length > 0 ? initialData : undefined,
				type,
				metaKey,
			},
		});

		container[UNMOUNT_KEY] = () => unmount(app);
	});
}

/**
 * Mount WizardShell on each [data-portal-wizard] mount point.
 */
function initWizard() {
	const containers = document.querySelectorAll('[data-portal-wizard]');

	containers.forEach((container) => {
		if (container[UNMOUNT_KEY]) {
			return;
		}

		const portalId = Number(container.getAttribute('data-portal-id') || '0');
		const restRoot = container.getAttribute('data-rest-root') || '';
		const restNonce = container.getAttribute('data-rest-nonce') || '';
		const wpRestRoot =
			container.getAttribute('data-wp-rest-root') ||
			(typeof window !== 'undefined' && window.wpApiSettings?.root) ||
			'/wp-json/';
		const portalTitle = container.getAttribute('data-portal-title') || '';
		const publicUrl = container.getAttribute('data-public-url') || '';
		const postStatus = container.getAttribute('data-post-status') || 'draft';
		const productMode = container.getAttribute('data-product-mode') === '1';
		const listUrl = container.getAttribute('data-list-url') || '';
		const logoUrl = container.getAttribute('data-logo-url') || '';
		const seedNode = container.querySelector('script[type="application/json"][data-dg-definition]');
		let definitionSeed;
		if (seedNode) {
			try {
				definitionSeed = JSON.parse(seedNode.textContent || 'null');
			} catch {
				definitionSeed = null;
			}
		}
		const catalogNode = container.querySelector(
			'script[type="application/json"][data-dg-access-catalog]',
		);
		let accessCatalog = { membershipPlans: [], profileFields: [] };
		if (catalogNode) {
			try {
				const parsed = JSON.parse(catalogNode.textContent || 'null');
				if (parsed && typeof parsed === 'object') {
					accessCatalog = parsed;
				}
			} catch {
				accessCatalog = { membershipPlans: [], profileFields: [] };
			}
		}
		const defaultsNode = container.querySelector(
			'script[type="application/json"][data-dg-site-defaults]',
		);
		let siteDefaults = {};
		if (defaultsNode) {
			try {
				const parsed = JSON.parse(defaultsNode.textContent || 'null');
				if (parsed && typeof parsed === 'object') {
					siteDefaults = parsed;
				}
			} catch {
				siteDefaults = {};
			}
		}

		const app = mount(WizardShell, {
			target: container,
			props: {
				portalId,
				restRoot,
				restNonce,
				wpRestRoot,
				portalTitle,
				publicUrl,
				postStatus,
				productMode,
				listUrl,
				logoUrl,
				definitionSeed,
				accessCatalog,
				siteDefaults,
			},
		});
		container[UNMOUNT_KEY] = () => unmount(app);
	});
}

/**
 * Unmount all DragonGate Svelte roots.
 */
function cleanup() {
	const selectors = '[data-segmented-control], [data-portal-wizard]';
	document.querySelectorAll(selectors).forEach((container) => {
		if (typeof container[UNMOUNT_KEY] === 'function') {
			container[UNMOUNT_KEY]();
			container[UNMOUNT_KEY] = null;
		}
	});
}

function initAll() {
	initWizard();
	initPortalBuilder();
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initAll);
} else {
	initAll();
}

export { initPortalBuilder, initWizard, cleanup };
