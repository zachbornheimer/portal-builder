/**
 * ContentProvider - Interface for accessing content from different sources
 * 
 * Responsibilities:
 * - Provide content from various sources (WordPress editor, TinyMCE, etc.)
 * - Handle content change events
 * - Abstract content access for testability
 * 
 * @see TagsField
 */

export class ContentProvider {
    constructor() {
        this.content = '';
        this.listeners = [];
    }

    /**
     * Get current content
     * @returns {string} Current content
     */
    getContent() {
        return this.content;
    }

    /**
     * Add content change listener
     * @param {Function} listener - Function to call when content changes
     */
    addChangeListener(listener) {
        this.listeners.push(listener);
    }

    /**
     * Remove content change listener
     * @param {Function} listener - Function to remove
     */
    removeChangeListener(listener) {
        this.listeners = this.listeners.filter(l => l !== listener);
    }

    /**
     * Notify listeners of content change
     * @private
     */
    notifyListeners() {
        this.listeners.forEach(listener => listener(this.content));
    }
}

/**
 * WordPressContentProvider - Provides content from WordPress editor
 */
export class WordPressContentProvider extends ContentProvider {
    constructor() {
        super();
        this.contentElement = null;
        this.tinyMceEditor = null;
        this.setupContentAccess();
    }

    setupContentAccess() {
        // Try to get content from WordPress editor
        this.contentElement = document.getElementById('content');
        if (this.contentElement) {
            this.content = this.contentElement.innerHTML || this.contentElement.value || '';
            this.contentElement.addEventListener('input', () => this.updateContent());
        }

        // Also try to get content from TinyMCE if available
        if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
            this.tinyMceEditor = tinymce.get('content');
            if (this.tinyMceEditor) {
                this.content = this.tinyMceEditor.getContent() || '';
                this.tinyMceEditor.on('change', () => this.updateContent());
            }
        }
    }

    updateContent() {
        let newContent = '';

        if (this.contentElement) {
            newContent = this.contentElement.innerHTML || this.contentElement.value || '';
        }

        if (this.tinyMceEditor) {
            newContent = this.tinyMceEditor.getContent() || '';
        }

        if (newContent !== this.content) {
            this.content = newContent;
            this.notifyListeners();
        }
    }

    destroy() {
        if (this.contentElement) {
            this.contentElement.removeEventListener('input', () => this.updateContent());
        }
        if (this.tinyMceEditor) {
            this.tinyMceEditor.off('change', () => this.updateContent());
        }
    }
}
