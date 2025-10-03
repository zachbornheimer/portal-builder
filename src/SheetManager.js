import { writable, derived } from 'svelte/store';
import { Sheet } from './Sheet.js';

export class SheetManager {
    constructor(initialSheets = [], type = 'sheets') {
        this.type = type;
        // Create reactive store for sheets array
        const sheetObjects = initialSheets.map(sheetData => {
            return new Sheet(sheetData);
        });
        this.sheets = writable(sheetObjects);
        this.activeSheetId = writable(null);

        // Derived store for segmented control tabs
        this.tabs = derived(this.sheets, $sheets =>
            $sheets.map(sheet => ({
                key: sheet.name,
                value: sheet.id,
                sheet: sheet
            }))
        );

        // Derived store for active sheet
        this.activeSheet = derived(
            [this.sheets, this.activeSheetId],
            ([$sheets, $activeSheetId]) =>
                $sheets.find(sheet => sheet.id === $activeSheetId) || $sheets[0] || null
        );

        // Initialize with first sheet if available
        this.sheets.subscribe($sheets => {
            if ($sheets.length > 0 && !this.getActiveSheetId()) {
                this.setActiveSheet($sheets[0].id);
            }
        });
    }

    getSheets() {
        let sheets;
        this.sheets.subscribe($sheets => sheets = $sheets)();
        return sheets;
    }

    getActiveSheetId() {
        let activeId;
        this.activeSheetId.subscribe($activeId => activeId = $activeId)();
        return activeId;
    }

    getActiveSheet() {
        let activeSheet;
        this.activeSheet.subscribe($activeSheet => activeSheet = $activeSheet)();
        return activeSheet;
    }

    setActiveSheet(sheetId) {
        this.activeSheetId.set(sheetId);
    }

    addSheet(sheetData = {}) {
        const newSheet = new Sheet(sheetData);
        this.sheets.update(sheets => [...sheets, newSheet]);
        this.setActiveSheet(newSheet.id);
        return newSheet;
    }

    updateSheet(sheetId, updates) {
        this.sheets.update(sheets =>
            sheets.map(sheet =>
                sheet.id === sheetId
                    ? Object.assign(sheet, updates)
                    : sheet
            )
        );
    }

    deleteSheet(sheetId) {
        this.sheets.update(sheets => {
            const filteredSheets = sheets.filter(sheet => sheet.id !== sheetId);
            // If we deleted the active sheet, switch to the first available sheet
            if (this.getActiveSheetId() === sheetId && filteredSheets.length > 0) {
                this.setActiveSheet(filteredSheets[0].id);
            }
            return filteredSheets;
        });
    }

    updateSheetName(sheetId, name) {
        this.sheets.update(sheets =>
            sheets.map(sheet => {
                if (sheet.id === sheetId) {
                    sheet.name = name || '[New Sheet]';
                    // Trigger the sheet's internal store update
                    sheet.store.set(sheet);
                }
                return sheet;
            })
        );
    }

    updateSheetField(sheetId, fieldKey, value) {
        console.log(`🔧 updateSheetField: ${fieldKey} = ${value} for sheet ${sheetId}`);

        this.sheets.update(sheets => {
            return sheets.map(sheet => {
                if (sheet.id === sheetId) {
                    console.log(`✅ Found matching sheet: ${sheet.id}`);

                    // Handle direct sheet properties
                    if (fieldKey === 'name') {
                        sheet.name = value || '[New Sheet]';
                    } else if (fieldKey === 'sheetId') {
                        sheet.sheetId = value;
                    } else if (fieldKey === 'folderId') {
                        sheet.folderId = value;
                    } else if (fieldKey === 'columns') {
                        sheet.columns = value;
                    } else {
                        // Handle other fields in the fields object
                        sheet.fields[fieldKey] = value;
                    }

                    // Trigger the sheet's internal store update
                    sheet.store.set(sheet);
                    console.log(`✅ Updated ${fieldKey} to: ${value}`);

                    return sheet;
                } else {
                    console.log(`❌ Sheet ${sheet.id} does not match ${sheetId}`);
                }
                return sheet;
            });
        });
    }

    addTag(sheetId, tag) {
        console.log(`🏷️ addTag: ${tag} to sheet ${sheetId}`);

        this.sheets.update(sheets => {
            return sheets.map(sheet => {
                if (sheet.id === sheetId) {
                    console.log(`✅ Found matching sheet: ${sheet.id}`);

                    // Get current columns
                    const currentColumns = sheet.columns || '';

                    // Add the new tag
                    const updatedColumns = currentColumns
                        ? `${currentColumns},${tag}`
                        : tag;

                    // Update the sheet
                    sheet.columns = updatedColumns;
                    sheet.store.set(sheet);

                    console.log(`✅ Added tag: ${tag}, columns now: ${updatedColumns}`);
                    return sheet;
                } else {
                    console.log(`❌ Sheet ${sheet.id} does not match ${sheetId}`);
                }
                return sheet;
            });
        });
    }

    removeColumn(sheetId, columnUuid) {
        console.log(`🏷️ removeColumn: ${columnUuid} from sheet ${sheetId}`);

        this.sheets.update(sheets => {
            return sheets.map(sheet => {
                if (sheet.id === sheetId) {
                    sheet.removeColumn(columnUuid);
                    console.log(`✅ Removed column: ${columnUuid}, columns now:`, sheet.columns);
                    sheet.store.set(sheet);
                    return sheet;
                } else {
                    console.log(`❌ Sheet ${sheet.id} does not match ${sheetId}`);
                }
                return sheet;
            });
        });
    }

    // Legacy method for backward compatibility
    removeTag(sheetId, tag) {
        console.log(`🏷️ removeTag: ${tag} from sheet ${sheetId} (legacy method)`);

        this.sheets.update(sheets => {
            return sheets.map(sheet => {
                if (sheet.id === sheetId) {
                    console.log(`✅ Found matching sheet: ${sheet.id}`);

                    // Find column by value and remove by UUID
                    const columnToRemove = sheet.columns.find(col => col.value === tag || col.label === tag);
                    if (columnToRemove) {
                        this.removeColumn(sheetId, columnToRemove.uuid);
                    }

                    return sheet;
                } else {
                    console.log(`❌ Sheet ${sheet.id} does not match ${sheetId}`);
                }
                return sheet;
            });
        });
    }

    addColumn(sheetId, column) {
        console.log(`📝 addColumn: ${column} to sheet ${sheetId}`);

        this.sheets.update(sheets => {
            return sheets.map(sheet => {
                if (sheet.id === sheetId) {
                    console.log(`✅ Found matching sheet: ${sheet.id}`);

                    // Use the sheet's addColumn method
                    sheet.addColumn(column);

                    console.log(`✅ Added column: ${column}, columns now: ${sheet.columns}`);
                    return sheet;
                } else {
                    console.log(`❌ Sheet ${sheet.id} does not match ${sheetId}`);
                }
                return sheet;
            });
        });
    }

    moveColumn(sheetId, startIndex, endIndex) {
        console.log(`🔄 moveColumn: moving column from ${startIndex} to ${endIndex} for sheet ${sheetId}`);
        this.sheets.update(sheets => {
            return sheets.map(sheet => {
                if (sheet.id === sheetId) {
                    sheet.moveColumn(startIndex, endIndex);
    
                    console.log(`✅ Moved column from ${startIndex} to ${endIndex}, columns now: ${sheet.columns}`);
                    sheet.store.set(sheet);
                    return sheet;
                } else {
                    console.log(`❌ Sheet ${sheet.id} does not match ${sheetId}`);
                }
                return sheet;
            });
        });
    }

    updateSheetColumns(sheetId, columns) {
        console.log(`🔄 updateSheetColumns: updating columns for sheet ${sheetId}`);
        this.sheets.update(sheets => {
            return sheets.map(sheet => {
                if (sheet.id === sheetId) {
                    console.log(`✅ Found matching sheet: ${sheet.id}`);
                    // Use the sheet's updateColumns method
                    sheet.updateColumns(columns);
                    console.log(`✅ Updated columns, new count: ${columns.length}`);
                    sheet.store.set(sheet);
                    return sheet;
                } else {
                    console.log(`❌ Sheet ${sheet.id} does not match ${sheetId}`);
                }
                return sheet;
            });
        });
    }

    // Export data for form submission
    exportData() {
        return this.getSheets().map(sheet => {
            // Return data in the format WordPress expects: [name, sheetId, columns] or [name, folderId]
            if (this.type === 'drive') {
                return [sheet.name || '', sheet.folderId || ''];
            } else {
                // Use sheet's getColumnString method for consistent serialization
                const columnsString = sheet.getColumnString ? sheet.getColumnString() : '';
                return [sheet.name || '', sheet.sheetId || '', columnsString];
            }
        });
    }

    // Import data from form
    importData(data) {
        const sheets = data.map((row, index) => {
            // Handle both 2D array format [name, sheetId, columns] and object format
            if (Array.isArray(row)) {
                if (this.type === 'drive') {
                    return new Sheet({
                        id: `sheet_${Date.now()}_${index}`,
                        name: row[0] || '',
                        folderId: row[1] || '',
                        columns: '',
                        fields: {}
                    });
                } else {
                    return new Sheet({
                        id: `sheet_${Date.now()}_${index}`,
                        name: row[0] || '',
                        sheetId: row[1] || '',
                        columns: row[2] || '',
                        fields: {}
                    });
                }
            } else {
                // Handle object format (for backward compatibility)
                return Sheet.fromJSON(row);
            }
        });
        this.sheets.set(sheets);
        if (sheets.length > 0) {
            this.setActiveSheet(sheets[0].id);
        }
    }
}
