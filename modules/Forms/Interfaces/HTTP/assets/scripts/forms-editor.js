(function () {
    var list = document.getElementById('forms-fields');
    var template = document.getElementById('forms-row-template');
    var add = document.getElementById('forms-add-field');

    if (!list || !template || !add) {
        return;
    }

    var next = list.querySelectorAll('[data-form-row]').length;

    function reindex(row, index) {
        var named = row.querySelectorAll('[data-name]');
        var i;

        for (i = 0; i < named.length; i++) {
            var field = named[i];
            field.name = 'fields[' + index + '][' + field.getAttribute('data-name') + ']';
        }

        var legend = row.querySelector('legend');

        if (legend) {
            legend.textContent = 'Field ' + (index + 1);
        }
    }

    add.addEventListener('click', function () {
        var node = template.content.cloneNode(true);
        var row = node.querySelector('[data-form-row]');

        reindex(row, next);
        next++;
        list.appendChild(node);
    });

    list.addEventListener('click', function (event) {
        var button = event.target.closest('[data-form-remove]');

        if (!button) {
            return;
        }

        var row = button.closest('[data-form-row]');
        var rows = list.querySelectorAll('[data-form-row]');

        // Keep one row: an empty form is an editor with nothing to add to.
        if (rows.length <= 1) {
            return;
        }

        row.parentNode.removeChild(row);
    });
})();
