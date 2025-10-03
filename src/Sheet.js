import { writable } from 'svelte/store';
import { Column } from './Column.js';

export class Sheet {
    constructor(data = {}) {
        this.id = data.id || this.generateUUID();
        this.name = data.name || '[New Sheet]';
        this.sheetId = data.sheetId || '';
        this.folderId = data.folderId || '';
        // Initialize columns as array of Column objects
        this.columns = this.initializeColumns(data.columns);
        this.fields = data.fields || {};

        // Create reactive store for this sheet
        this.store = writable(this);
    }

    generateUUID() {
        return 'sheet_' + Math.random().toString(36).substr(2, 9);
    }

    initializeColumns(columnsData) {
        if (Array.isArray(columnsData)) {
            // Handle array of Column objects or strings
            return columnsData.map(col => {
                if (col instanceof Column) {
                    return col;
                } else if (typeof col === 'object' && col.uuid) {
                    return new Column(col);
                } else {
                    // Handle string values
                    const cleanValue = `${col}`.replace(/\s*{\s*{\s*/, '').replace(/\s*}\s*}\s*/, '').trim();
                    return new Column({ value: cleanValue, label: cleanValue });
                }
            });
        } else if (typeof columnsData === 'string' && columnsData.trim()) {
            // Handle comma-separated string
            return columnsData.split(',').filter(col => col.trim()).map(col => {
                const cleanValue = col.replace(/\s*{\s*{\s*/, '').replace(/\s*}\s*}\s*/, '').trim();
                return new Column({ value: cleanValue, label: cleanValue });
            });
        }
        return [];
    }

    updateName(name) {
        this.name = name || '[New Sheet]';
        this.store.set(this);
    }

    updateField(fieldKey, value) {
        if (fieldKey === 'name') {
            this.updateName(value);
        } else if (fieldKey === 'sheetId') {
            this.sheetId = value;
        } else if (fieldKey === 'folderId') {
            this.folderId = value;
        } else if (fieldKey === 'columns') {
            this.columns = value;
        } else {
            this.fields[fieldKey] = value;
        }
        this.store.set(this);
    }

    updateSheetId(sheetId) {
        this.sheetId = sheetId;
        this.store.set(this);
    }

    updateColumns(columns) {
        this.columns = columns;
        this.store.set(this);
    }

    toJSON() {
        return {
            id: this.id,
            name: this.name,
            sheetId: this.sheetId,
            folderId: this.folderId,
            columns: this.getColumnString(), // Use derived getColumnString method
            fields: this.fields
        };
    }

    addColumn(column_string) {
        const cleanValue = `${column_string}`.replace(/\s*{\s*{\s*/, '').replace(/\s*}\s*}\s*/, '').trim();
        const newColumn = new Column({ value: cleanValue, label: cleanValue });
        this.columns.push(newColumn);
        this.store.set(this);
    }

    removeColumn(uuid) {
        this.columns = this.columns.filter(col => col.uuid !== uuid);
        console.log(`🏷️ Removed column: ${uuid}, columns now:`, this.columns);
        this.store.set(this);
    }

    getColumnString() {
        return this.columns.map(col => `{{ ${col.value} }}`).join(',');
    }

    static fromJSON(data) {
        return new Sheet(data);
    }
}
