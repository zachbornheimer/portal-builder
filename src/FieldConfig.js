/**
 * Field configuration for different data table types
 */
export const FIELD_CONFIGS = {
    sheets: {
        type: 'sheets',
        fields: [
            {
                key: 'name',
                label: 'Google Sheet Name (For Identification Purposes)',
                type: 'text',
                required: true,
                hasDeleteButton: true
            },
            {
                key: 'sheetId',
                label: 'Google Sheet ID',
                type: 'id',
                service: 'sheets',
                hasExternalLink: true,
                linkText: 'View Sheet',
                linkUrl: (id) => `https://docs.google.com/spreadsheets/d/${id}`
            },
            {
                key: 'columns',
                label: 'Columns',
                type: 'tags',
                placeholder: 'Add tags...'
            }
        ]
    },

    drive: {
        type: 'drive',
        fields: [
            {
                key: 'name',
                label: 'Google Drive Folder Name (For Identification Purposes)',
                type: 'text',
                required: true,
                hasDeleteButton: true
            },
            {
                key: 'folderId',
                label: 'Google Drive Folder ID',
                type: 'id',
                service: 'drive',
                hasExternalLink: true,
                linkText: 'View Folder',
                linkUrl: (id) => `https://drive.google.com/drive/folders/${id}`
            }
        ]
    }
};

/**
 * Get field configuration for a specific type
 * @param {string} type - The configuration type ('sheets' or 'drive')
 * @returns {Object} Field configuration object
 */
export function getFieldConfig(type) {
    return FIELD_CONFIGS[type] || FIELD_CONFIGS.sheets;
}

/**
 * Get field definition by key
 * @param {string} type - The configuration type
 * @param {string} key - The field key
 * @returns {Object|null} Field definition or null if not found
 */
export function getFieldDefinition(type, key) {
    const config = getFieldConfig(type);
    return config.fields.find(field => field.key === key) || null;
}

