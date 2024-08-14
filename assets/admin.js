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
});