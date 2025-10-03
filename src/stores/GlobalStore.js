import { writable, derived } from 'svelte/store';

/**
 * Global state management for WordPress Portal Builder
 * Manages shared state across all Svelte components
 */
class GlobalStore {
    constructor() {
        // Core data stores
        this.recordKeeping = writable([]);
        this.fileBackups = writable([]);
        this.legalDisclaimers = writable([]);

        // Meta keys mapping
        this.metaKeys = {
            recordKeeping: '_portal_record_keeping',
            fileBackups: '_portal_file_backups',
            legalDisclaimers: 'pb_legal_disclaimers'
        };

        // Initialize from WordPress data
        this.initializeFromWordPress();
    }

    /**
     * Initialize stores from WordPress data
     */
    initializeFromWordPress() {
        // Get data from hidden inputs if they exist
        const recordKeepingInput = document.querySelector(`input[name="${this.metaKeys.recordKeeping}"]`);
        const fileBackupsInput = document.querySelector(`input[name="${this.metaKeys.fileBackups}"]`);
        const legalDisclaimersInput = document.querySelector(`input[name="${this.metaKeys.legalDisclaimers}"]`);

        if (recordKeepingInput && recordKeepingInput.value) {
            try {
                const data = JSON.parse(recordKeepingInput.value);
                this.recordKeeping.set(data);
            } catch (e) {
                console.warn('Failed to parse record keeping data:', e);
            }
        }

        if (fileBackupsInput && fileBackupsInput.value) {
            try {
                const data = JSON.parse(fileBackupsInput.value);
                this.fileBackups.set(data);
            } catch (e) {
                console.warn('Failed to parse file backups data:', e);
            }
        }

        if (legalDisclaimersInput && legalDisclaimersInput.value) {
            try {
                const data = JSON.parse(legalDisclaimersInput.value);
                this.legalDisclaimers.set(data);
            } catch (e) {
                console.warn('Failed to parse legal disclaimers data:', e);
            }
        }
    }

    /**
     * Update record keeping data
     */
    updateRecordKeeping(data) {
        this.recordKeeping.set(data);
        this.updateHiddenInput(this.metaKeys.recordKeeping, data);
    }

    /**
     * Update file backups data
     */
    updateFileBackups(data) {
        this.fileBackups.set(data);
        this.updateHiddenInput(this.metaKeys.fileBackups, data);
    }

    /**
     * Update legal disclaimers data
     */
    updateLegalDisclaimers(data) {
        this.legalDisclaimers.set(data);
        this.updateHiddenInput(this.metaKeys.legalDisclaimers, data);
    }

    /**
     * Update hidden input field with new data
     */
    updateHiddenInput(metaKey, data) {
        const input = document.querySelector(`input[name="${metaKey}"]`);
        if (input) {
            input.value = JSON.stringify(data);
        }
    }

    /**
     * Get record keeping data
     */
    getRecordKeeping() {
        return this.recordKeeping;
    }

    /**
     * Get file backups data
     */
    getFileBackups() {
        return this.fileBackups;
    }

    /**
     * Get legal disclaimers data
     */
    getLegalDisclaimers() {
        return this.legalDisclaimers;
    }
}

// Create singleton instance
export const globalStore = new GlobalStore();

