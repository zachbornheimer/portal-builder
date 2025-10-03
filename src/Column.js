import { writable } from 'svelte/store';

export class Column {
    constructor(data = {}) {
        this.uuid = data.uuid || this.generateUUID();
        this.value = data.value || '';
        this.label = data.label || data.value || '';

        // Create reactive store for this column
        this.store = writable(this);
    }

    generateUUID() {
        return 'col_' + Math.random().toString(36).substr(2, 9);
    }

    updateValue(value) {
        this.value = value;
        this.store.set(this);
    }

    updateLabel(label) {
        this.label = label;
        this.store.set(this);
    }

    toJSON() {
        return {
            uuid: this.uuid,
            value: this.value,
            label: this.label
        };
    }

    static fromJSON(data) {
        return new Column(data);
    }
}

