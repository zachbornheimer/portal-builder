document.addEventListener("DOMContentLoaded", function () {
    var fields = document.querySelectorAll("[data-display-label]");
    fields.forEach(function (field) {
        let field_name = field.getAttribute("data-display-label");
        var label = document.querySelector("label[for=" + field_name + "]");
        if (label) {
            let text = label.innerText.replace("\*", "");
            field.innerHTML = text + " " + field.innerHTML;
            // replace multiple text in a row.
            field.innerHTML = field.innerHTML.replace(text + " " + text, text);

            if (field.getAttribute("data-display-label-override")) {
                field.innerHTML = text;
            }
        }
    });
});