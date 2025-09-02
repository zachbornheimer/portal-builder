jQuery(document).ready(function ($) {

    function updateTableValues($table) {
        let rowsData = [];
        $table.find('tbody tr').each(function () {
            let rowData = [];
            $(this).find('td').each(function () {
                let $inputs = $(this).find('input');

                if ($inputs.filter('.tag-hidden-field').length > 0) {
                    let value = $inputs.filter('.tag-hidden-field').val().trim();
                    rowData.push(value);
                } else if ($inputs.filter('.tag-input').length === 0) {
                    $inputs.each(function () {
                        let value = $(this).val().trim();
                        rowData.push(value);
                    });
                }
            });
            rowsData.push(rowData);
        });
        $table.siblings('input[type=hidden]').val(JSON.stringify(rowsData));
    }

    function extractUrl(value, type) {
        let match;
        if (type === 'google-sheet') {
            match = value.match(/\/d\/([a-zA-Z0-9-_]+)/);
            return match ? match[1] : value;
        } else if (type === 'google-drive') {
            match = value.match(/[-\w]{25,}/);
            return match ? match[0] : value;
        }
        return value;
    }

    function initializeTagInput(container) {
        const input = container.querySelector(".tag-input");

        input.addEventListener("keypress", function (event) {
            if (event.key === "Enter" && input.value.trim() !== "") {
                event.preventDefault();
                let inputValue = input.value.trim();

                // Check if input contains commas (multiple tags)
                if (inputValue.includes(',')) {
                    createTagsFromCommaSeparated(container, inputValue);
                } else {
                    // Single tag - add {{ }} wrapper if not already present
                    if (!inputValue.match(/^\s*\{\{\s*.*\s*\}\}\s*$/)) {
                        inputValue = "{{ " + inputValue + " }}";
                    }
                    createTag(container, inputValue);
                }

                input.value = "";
            }
        });

        // Listen for Alt + Shift + A key combination
        input.addEventListener("keydown", function (event) {
            if (event.altKey && event.shiftKey && event.code === "KeyA") {
                event.preventDefault();
                if (confirm("Add all detected names from the content as tags?")) {
                    addAllNamesAsTags(container);
                }
            }
        });

        container.querySelectorAll(".close-button").forEach(closeButton => {
            closeButton.addEventListener("click", function () {
                const tag = closeButton.parentElement;
                if (container.contains(tag)) {
                    container.removeChild(tag);
                    updateHiddenField(container);
                    let $table = $(container).closest('.data-table');
                    updateTableValues($table);
                }
            });
        });

        container.querySelectorAll(".tag").forEach(tag => {
            enableDragAndDrop(tag, container);
        });
    }

    function createTag(container, value) {
        if (!value) return;

        // Clean the value: remove {{ }} wrappers and trim
        let cleanValue = value.replace(/^\s*\{\{\s*|\s*\}\}\s*$/g, '').trim();
        if (!cleanValue) return;

        let tag = document.createElement("span");
        tag.className = "tag";
        tag.textContent = cleanValue;

        let closeButton = document.createElement("span");
        closeButton.className = "close-button";
        closeButton.textContent = "×";
        closeButton.addEventListener("click", function () {
            if (container.contains(tag)) {
                container.removeChild(tag);
                updateHiddenField(container);
                let $table = $(container).closest('.data-table');
                updateTableValues($table);
            }
        });

        tag.appendChild(closeButton);
        container.insertBefore(tag, container.querySelector(".tag-input"));
        enableDragAndDrop(tag, container);
        updateHiddenField(container);
        let $table = $(container).closest('.data-table');
        updateTableValues($table);
    }

    function createTagsFromCommaSeparated(container, inputValue) {
        if (!inputValue) return;

        // Split by comma and process each part
        let parts = inputValue.split(',').map(part => part.trim()).filter(part => part.length > 0);

        parts.forEach(part => {
            // For comma-separated input, we want to preserve the {{ }} wrappers
            // but clean up any extra whitespace
            let cleanPart = part.replace(/^\s*\{\{\s*/, '{{ ').replace(/\s*\}\}\s*$/, ' }}').trim();
            if (cleanPart && cleanPart !== '{{ }}') {
                createTag(container, cleanPart);
            }
        });
    }

    function updateHiddenField(container) {
        let tags = Array.from(container.getElementsByClassName("tag"))
            .map(tag => tag.textContent.replace("×", ""))
            .join(",");
        container.querySelector(".tag-hidden-field").value = tags;
    }

    function enableDragAndDrop(tag, container) {
        let ghostTag = null;

        tag.addEventListener('mousedown', function (e) {
            e.preventDefault();
            ghostTag = tag.cloneNode(true);
            ghostTag.style.position = 'absolute';
            ghostTag.style.opacity = '0.5';
            ghostTag.style.pointerEvents = 'none';
            ghostTag.style.zIndex = '1000';
            document.body.appendChild(ghostTag);

            const shiftX = e.clientX - tag.getBoundingClientRect().left;
            const shiftY = e.clientY - tag.getBoundingClientRect().top;

            moveAt(e.pageX, e.pageY);

            function moveAt(pageX, pageY) {
                ghostTag.style.left = pageX - shiftX + 'px';
                ghostTag.style.top = pageY - shiftY + 'px';
            }

            function onMouseMove(event) {
                moveAt(event.pageX, event.pageY);
            }

            document.addEventListener('mousemove', onMouseMove);

            document.addEventListener('mouseup', function onMouseUp(event) {
                document.removeEventListener('mousemove', onMouseMove);
                ghostTag.remove();

                let targetTag = document.elementFromPoint(event.clientX, event.clientY).closest('.tag');

                if (targetTag && targetTag !== tag) {
                    if (event.clientY < targetTag.getBoundingClientRect().top + targetTag.offsetHeight / 2) {
                        container.insertBefore(tag, targetTag);
                    } else {
                        container.insertBefore(tag, targetTag.nextSibling);
                    }
                } else if (!targetTag && event.clientY < container.querySelector(".tag").getBoundingClientRect().top) {
                    container.insertBefore(tag, container.querySelector(".tag"));
                }

                document.removeEventListener('mouseup', onMouseUp);

                updateHiddenField(container);
                let $table = $(container).closest('.data-table');
                updateTableValues($table);
            });
        });

        tag.addEventListener('dragstart', function () {
            return false;
        });
    }

    function initializeTableRow($row) {
        $row.find('.columns-tag-container').each(function () {
            initializeTagInput(this);
        });
    }

    $('.data-table').on('input', '.extract-url', function () {
        let $field = $(this);
        let value = $field.val();
        let type = $field.data('extraction-type');
        $field.val(extractUrl(value, type));
    });

    $('.data-table').on('input', 'input', function () {
        let $table = $(this).closest('.data-table');
        updateTableValues($table);

        let lastRow = $table.find('tbody tr:last-child');
        if (lastRow.find('input').filter(function () { return this.value.trim() !== ''; }).length > 0) {
            let $newRow = lastRow.clone();
            $newRow.find('input').val('');
            $newRow.find('.tag').remove();
            lastRow.after($newRow);
            initializeTableRow($newRow);
        }
    });

    $('.data-table').on('click', '.delete-row', function () {
        let $table = $(this).closest('.data-table');
        if (confirm('Are you sure you want to delete this row?')) {
            $(this).closest('tr').remove();
            updateTableValues($table);
        }
    });

    $('.data-table').on('click', '.copy-row', function () {
        let $row = $(this).closest('tr');
        let currentTags = [];

        // Collect all tags from the row
        $row.find('.tag').each(function () {
            currentTags.push($(this).text().replace('×', '').trim());
        });

        if (currentTags.length > 0) {
            // Join tags with commas and copy to clipboard
            let tagsText = currentTags.join(',');

            // Use modern clipboard API if available
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(tagsText).then(function () {
                    // Show success feedback
                    let $button = $(this);
                    let originalText = $button.text();
                    $button.text('Copied!').css('background-color', '#28a745');
                    setTimeout(() => {
                        $button.text(originalText).css('background-color', '');
                    }, 1000);
                }.bind(this)).catch(function (err) {
                    console.error('Failed to copy: ', err);
                    fallbackCopyTextToClipboard(tagsText, this);
                });
            } else {
                // Fallback for older browsers
                fallbackCopyTextToClipboard(tagsText, this);
            }
        }
    });

    // Fallback copy function for older browsers
    function fallbackCopyTextToClipboard(text, buttonElement) {
        let textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.top = "0";
        textArea.style.left = "0";
        textArea.style.position = "fixed";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            let successful = document.execCommand('copy');
            if (successful) {
                // Show success feedback
                let $button = $(buttonElement);
                let originalText = $button.text();
                $button.text('Copied!').css('background-color', '#28a745');
                setTimeout(() => {
                    $button.text(originalText).css('background-color', '');
                }, 1000);
            }
        } catch (err) {
            console.error('Fallback: Oops, unable to copy', err);
        }

        document.body.removeChild(textArea);
    }

    $('button.add-row').on('click', function () {
        let $table = $(this).siblings('.data-table');
        let $lastRow = $table.find('tbody tr:last-child');
        let $newRow = $lastRow.clone();
        $newRow.find('.tag').remove();
        $newRow.find('input').val('');
        $lastRow.after($newRow);
        initializeTableRow($newRow);
    });

    const tagContainers = document.querySelectorAll(".columns-tag-container");

    tagContainers.forEach(container => {
        initializeTagInput(container);
    });

    document.querySelector("form").addEventListener("submit", function () {
        tagContainers.forEach(container => {
            updateHiddenField(container);
            let $table = $(container).closest('.data-table');
            updateTableValues($table);
        });
    });

    document.querySelectorAll(".tag").forEach(tag => {
        if (tag.textContent.replace('×', '').trim() === "") {
            tag.remove();
        }
    });

    function addAllNamesAsTags(container) {
        // Extract all names from content
        let content = document.getElementById('content').innerHTML; // or replace this with the actual content area

        // if [portal-applicant-information] is used, add the following:
        const applicantInformation = ['sub_title', 'sub_name', 'sub_email', 'sub_inst_affil', 'sub_address_first_part', 'sub_city', 'sub_country', 'sub_state', 'sub_zip', 'sub_phone',];


        createTag(container, "{{ app_id }}");

        if (content.match(/\[\s*portal-applicant-information\s*\]/i)) {
            applicantInformation.forEach(name => {
                createTag(container, "{{ " + name + " }}");
            });
        }

        let matches = content.match(/name="([^"]+)"/g);
        if (matches) {
            matches.forEach(match => {
                let name = match.match(/name="([^"]+)"/)[1];
                createTag(container, "{{ " + name + " }}");
            });
        }

        createTag(container, "{{ drive_link }}");

        let $table = $(container).closest('.data-table');
        updateTableValues($table);
    }

});
