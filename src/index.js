import PortalBuilder from './PortalBuilder.svelte';
import './styles.css';

// Initialize portal builder for each instance
function initPortalBuilder() {
    const containers = document.querySelectorAll('[data-segmented-control]');

    containers.forEach(container => {
        // Find the data table in the same form group
        const formGroup = container.closest('.form-group');
        const dataTable = formGroup ? formGroup.querySelector('.data-table') : null;

        if (!dataTable) {
            console.warn('No data table found for segmented control');
            return;
        }

        // Get meta key from container attributes
        const metaKey = container.getAttribute('data-meta-key');

        // Detect configuration type based on meta key or columns
        let type = 'sheets'; // default
        if (metaKey.includes('file_backups') || metaKey.includes('drive')) {
            type = 'drive';
        } else if (metaKey.includes('record_keeping') || metaKey.includes('sheet')) {
            type = 'sheets';
        }

        // Extract columns from the data table or use default columns
        const columnHeaders = dataTable.querySelectorAll('label');
        let columns = Array.from(columnHeaders).map(header => header.textContent.trim());

        // If no columns found, use default columns based on the data structure
        if (columns.length === 0) {
            columns = type === 'sheets'
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
        initialData = initialData.map((row, index) => {
            const sheet = {
                id: `sheet_${Date.now()}_${index}`, // Generate unique ID
                name: row[0] || '[New Sheet]', // First column is always name
                fields: {}
            };

            // Add type-specific fields based on configuration
            if (type === 'sheets') {
                sheet.sheetId = row[1] || ''; // Second column is Google Sheet ID
                sheet.columns = row[2] || ''; // Third column is columns
            } else if (type === 'drive') {
                sheet.folderId = row[1] || ''; // Second column is Google Drive Folder ID
            }

            return sheet;
        }).filter(sheet => {
            // Filter out completely empty sheets based on type
            let isEmpty = sheet.name === '[New Sheet]';

            if (type === 'sheets') {
                isEmpty = isEmpty && !sheet.sheetId && !sheet.columns;
            } else if (type === 'drive') {
                isEmpty = isEmpty && !sheet.folderId;
            }

            return !isEmpty;
        });

        // Create the main portal builder component
        const app = new PortalBuilder({
            target: container,
            props: {
                columns,
                initialData: initialData.length > 0 ? initialData : undefined,
                type,
                metaKey
            }
        });

        // Store reference for cleanup
        container._svelteApp = app;
    });
}

// Clean up function for when components are destroyed
function cleanup() {
    const containers = document.querySelectorAll('[data-segmented-control]');
    containers.forEach(container => {
        if (container._svelteApp) {
            container._svelteApp.$destroy();
            container._svelteApp = null;
        }
    });
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPortalBuilder);
} else {
    initPortalBuilder();
}

// Export for manual initialization
export { initPortalBuilder };
