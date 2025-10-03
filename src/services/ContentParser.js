/**
 * ContentParser - Parses WordPress editor content to extract form field options
 * 
 * Responsibilities:
 * - Parse pb_* shortcodes from content
 * - Track group context during parsing
 * - Extract name and label attributes
 * - Filter out non-form elements
 * 
 * @see TagsField
 */

export class ContentParser {
    constructor() {
        this.regex = /^\[pb_(.*?)\]/gm;
    }

    /**
     * Parse content and extract form field options
     * @param {string} content - The content to parse
     * @returns {Array} Array of parsed options with name, value, label, and group
     */
    parse(content) {
        if (!content) return [];

        const options = [];
        let match;
        let currentGroup = '';

        while ((match = this.regex.exec(content)) !== null) {
            const shortcode = match[1];
            const fullMatch = match[0];

            // Update group context
            currentGroup = this.updateGroupContext(shortcode, fullMatch, currentGroup);

            // Skip group elements
            if (this.isGroupElement(shortcode)) {
                continue;
            }

            // Extract form field options
            const option = this.extractFormField(shortcode, fullMatch, currentGroup);
            if (option) {
                options.push(option);
            }
        }

        return options;
    }

    /**
     * Update group context based on shortcode
     * @private
     */
    updateGroupContext(shortcode, fullMatch, currentGroup) {
        // Check if this is a group start
        if (shortcode.startsWith('group') && !shortcode.includes('/')) {
            const groupLabelMatch = fullMatch.match(/label="([^"]*)"/);
            return groupLabelMatch ? groupLabelMatch[1] : currentGroup;
        }

        // Check if this is a group end
        if (shortcode === 'group' && fullMatch.includes('/')) {
            return '';
        }

        return currentGroup;
    }

    /**
     * Check if shortcode is a group element
     * @private
     */
    isGroupElement(shortcode) {
        return shortcode.startsWith('group') ||
            shortcode === 'group_selection_wrap1' ||
            shortcode === 'group_selection_wrap2';
    }

    /**
     * Extract form field from shortcode
     * @private
     */
    extractFormField(shortcode, fullMatch, currentGroup) {
        const nameMatch = fullMatch.match(/name="([^"]*)"/);
        const labelMatch = fullMatch.match(/label="([^"]*)"/);

        // Only include options that have a name parameter
        if (!nameMatch) return null;

        return {
            name: shortcode,
            value: nameMatch[1],
            label: labelMatch ? labelMatch[1] : nameMatch[1],
            group: currentGroup
        };
    }
}
