/**
 * TagManager - Manages tag operations for TagsField
 * 
 * Responsibilities:
 * - Handle tag addition and removal
 * - Manage different tag storage systems (sheetManager vs legacy)
 * - Provide consistent tag interface
 * 
 * @see TagsField
 */

export class TagManager {
    constructor(sheetManager = null, sheetId = null, dispatch = null, fieldKey = null) {
        this.sheetManager = sheetManager;
        this.sheetId = sheetId;
        this.dispatch = dispatch;
        this.fieldKey = fieldKey;
    }

    /**
     * Add a new tag
     * @param {string} tagValue - The tag value to add
     * @param {string} fieldKey - The field key for legacy mode
     * @param {string} currentValue - Current field value for legacy mode
     */
    addTag(tagValue, fieldKey = null, currentValue = '') {
        if (this.sheetManager && this.sheetId) {
            // Use addColumn for adding new columns/tags
            this.sheetManager.addColumn(this.sheetId, `{{ ${tagValue} }}`);
        } else if (this.dispatch && fieldKey) {
            // Fallback to old method if sheetManager not available
            const updatedTags = currentValue ? `${currentValue},${tagValue}` : tagValue;
            this.dispatch('change', { field: fieldKey, value: updatedTags });
        }
    }

    /**
     * Remove a tag
     * @param {string} tagToRemove - The tag to remove
     * @param {Array} tags - Current tags array
     */
    removeTag(tagToRemove, tags = []) {
        if (this.sheetManager && this.sheetId) {
            // Find the tag object to get its UUID
            const tagObj = tags.find(tag => tag.value === tagToRemove || tag.label === tagToRemove);
            if (tagObj && tagObj.uuid) {
                // Get the current sheet and call removeColumn directly
                let currentSheets;
                this.sheetManager.sheets.subscribe(sheets => currentSheets = sheets)();
                const currentSheet = currentSheets.find(s => s.id === this.sheetId);
                if (currentSheet) {
                    currentSheet.removeColumn(tagObj.uuid);
                }
            } else {
                // Fallback to legacy method if no UUID
                this.sheetManager.removeTag(this.sheetId, tagToRemove);
            }
        } else if (this.dispatch) {
            // Fallback to old method if sheetManager not available
            const updatedTags = tags.filter(tag => tag.value !== tagToRemove && tag.label !== tagToRemove);
            this.dispatch('change', { field: this.fieldKey || 'tags', value: updatedTags });
        }
    }

    /**
     * Parse tags from various formats
     * @param {*} value - The value to parse (array, string, etc.)
     * @returns {Array} Parsed tags array
     */
    parseTags(value) {
        if (Array.isArray(value)) {
            // Handle Column objects or string arrays
            return value.map(item => {
                if (typeof item === 'object' && item.uuid) {
                    return { uuid: item.uuid, value: item.value, label: item.label };
                } else {
                    return { uuid: null, value: item, label: item };
                }
            });
        } else if (typeof value === 'string' && value.trim()) {
            // Handle comma-separated string
            return value.split(',').filter(tag => tag.trim()).map(tag => ({
                uuid: null,
                value: tag.trim(),
                label: tag.trim()
            }));
        }
        return [];
    }
}
