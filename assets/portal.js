document.addEventListener("DOMContentLoaded", function () {
    var fields = document.querySelectorAll("[data-display-label]");
    fields.forEach(function (field) {
        let field_name = field.getAttribute("data-display-label");
        var label = document.querySelector("label[for=" + field_name + "]");
        if (label) {
            let text = label.innerText.replace("*", "");
            if (field.getAttribute("data-display-label-override")) {
                field.textContent = text;
            } else if (field.textContent.indexOf(text) !== 0) {
                // Prepend the label unless the field already starts with it.
                field.insertBefore(document.createTextNode(text + " "), field.firstChild);
            }
        }
    });
});
