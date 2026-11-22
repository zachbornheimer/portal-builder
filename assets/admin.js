jQuery(document).ready(function ($) {
    // Handle protected code fields
    $('.pb-protected-code-field').each(function () {
        var $field = $(this);
        var $wrapper = $field.closest('.pb-protected-wrapper');
        var originalValue = $field.val();

        // Apply blur if there is content in the field initially
        if (originalValue) {
            $field.addClass('blurred');
            $wrapper.addClass('blurred');
        }

        $field.focus(function () {
            $field.val('');
            $field.removeClass('blurred');
            $wrapper.removeClass('blurred');
        });

        $field.blur(function () {
            if ($field.val() === '') {
                $field.val(originalValue);
                if (originalValue) {
                    $field.addClass('blurred');
                    $wrapper.addClass('blurred');
                }
            }
        });
    });

    // Handle completion tracking for pb_group shortcodes
    $('.completion-tracker').each(function () {
        var $tracker = $(this);
        var $group = $tracker.closest('.portal-group');
        var $inputs = $group.find('input, select, textarea');
        var completeValue = $tracker.data('complete-value');
        var incompleteValue = $tracker.data('incomplete-value');

        function checkCompletion() {
            var isComplete = true;
            $inputs.each(function () {
                var $input = $(this);
                // Skip the completion tracker itself
                if ($input.hasClass('completion-tracker')) {
                    return;
                }
                // Check if required fields are filled
                if ($input.attr('required') && $input.val().trim() === '') {
                    isComplete = false;
                    return false; // break out of each loop
                }
            });
            $tracker.val(isComplete ? completeValue : incompleteValue);
        }

        // Check completion on any input change
        $inputs.on('change input', function () {
            checkCompletion();
        });

        // Initial check
        checkCompletion();
    });

});


jQuery(document).ready(function ($) {
    $('#add-disclaimer').on('click', function () {
        var $table = $('#pb_legal_disclaimers_table tbody');
        var index = $table.find('tr').length;
        var row = '<tr>' +
            '<td><input type="text" name="pb_legal_disclaimers[' + index + '][label]" class="regular-text"></td>' +
            '<td><textarea name="pb_legal_disclaimers[' + index + '][text]" class="large-text"></textarea></td>' +
            '<td><button type="button" class="button remove-disclaimer">' + portalBuilderLocalize.removeText + '</button></td>' +
            '</tr>';
        $table.append(row);
    });

    $(document).on('click', '.remove-disclaimer', function () {
        $(this).closest('tr').remove();
    });

    // Handle duplicate portal action
    $(document).on('click', '.duplicate-portal', function (e) {
        e.preventDefault();

        var $link = $(this);
        var postId = $link.data('post-id');
        var originalText = $link.text();

        // Show loading state
        $link.text('Duplicating...').addClass('disabled');

        // Make the request
        $.get($link.attr('href'))
            .done(function (response) {
                // Success - check if we got a redirect URL in the response
                if (response && response.success && response.data && response.data.redirect_url) {
                    // Redirect to the edit page
                    window.location.href = response.data.redirect_url;
                } else {
                    // Fallback: reload the page
                    $link.text('Duplicated!').removeClass('disabled');
                    setTimeout(function () {
                        window.location.reload();
                    }, 1000);
                }
            })
            .fail(function (xhr) {
                // Handle error response
                var errorMessage = 'Failed to duplicate portal. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                }

                // Restore original text on failure
                $link.text(originalText).removeClass('disabled');
                alert(errorMessage);
            });
    });

});