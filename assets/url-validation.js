jQuery(document).ready(function ($) {
    // Handle URL validation
    $('.monospace-url').on('blur', function () {
        var $input = $(this);
        var url = $input.val();
        var $validationDiv = $('#' + $input.attr('id') + '-validation');

        if (url) {
            const check = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6" style="height:12px"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>';
            const x = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6" style="height:12px"><path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>';

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'validate_url',
                    url: url
                },
                success: function (response) {

                    if (response.success) {
                        $validationDiv.html('<span style="color: rgb(22, 101, 52); display:flex;align-items:center;gap:4px;">' + check + ' Valid URL</span>');
                    } else {
                        $validationDiv.html('<span style="color: rgba(185, 28, 28, 0.9); display:flex;align-items:center;gap:4px;">' + x + ' Invalid URL</span>');
                    }
                    // make the parent remove the .empty class
                    $validationDiv.removeClass('empty');
                },
                error: function () {
                    $validationDiv.html('<span style="color: rgba(185, 28, 28, 0.9); display:flex;align-items:center;gap:4px;">' + x + ' Invalid URL</span>');
                }
            });
        } else {
            $validationDiv.empty();
        }
    });

});