(function() {
    // The sort numbers are spaced so a later insert can land between two rows
    // without renumbering the table.
    var STEP = 10;

    function rows(tbody) {
        return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-nav-id]'));
    }

    function clearMarks(tbody) {
        Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function(row) {
            row.classList.remove('m-drag-over');
            row.classList.remove('m-dragging');
        });
    }

    function wireDrag(tbody) {
        var dragged = null;

        tbody.addEventListener('dragstart', function(e) {
            var row = e.target.closest('tr[data-nav-id]');
            if (!row) return;
            dragged = row;
            row.classList.add('m-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', row.dataset.navId);
        });

        tbody.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var row = e.target.closest('tr[data-nav-id]');
            if (!row || row === dragged) return;
            clearMarks(tbody);
            row.classList.add('m-drag-over');
        });

        tbody.addEventListener('dragleave', function(e) {
            var row = e.target.closest('tr[data-nav-id]');
            if (row) row.classList.remove('m-drag-over');
        });

        tbody.addEventListener('drop', function(e) {
            e.preventDefault();
            var target = e.target.closest('tr[data-nav-id]');
            if (!target || !dragged || target === dragged) return;
            var list = rows(tbody);
            var dragIdx = list.indexOf(dragged);
            var dropIdx = list.indexOf(target);
            if (dragIdx < dropIdx) {
                tbody.insertBefore(dragged, target.nextSibling);
            } else {
                tbody.insertBefore(dragged, target);
            }
            clearMarks(tbody);
            dragged = null;
        });

        tbody.addEventListener('dragend', function() {
            clearMarks(tbody);
            dragged = null;
        });
    }

    function csrfToken(table) {
        var card = table.closest('.m-card');
        var input = card ? card.querySelector('input[name="_token"]') : null;
        return input ? input.value : '';
    }

    function saveOrder(table, tbody) {
        var order = rows(tbody).map(function(row, index) {
            return { id: row.dataset.navId, sort_order: (index + 1) * STEP };
        });
        var params = new URLSearchParams();
        params.append('_token', csrfToken(table));
        params.append('items_json', JSON.stringify(order));

        // The table names the endpoint it belongs to — the public nav and the
        // admin nav share this widget, and posting one table's order to the
        // other's route would silently renumber the wrong menu.
        fetch(table.dataset.reorderUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(function(resp) {
            if (!resp.ok) {
                window.mToast('Failed to save order', 'err');
                return;
            }
            window.mToast('Order saved!', 'ok');
            // A table that shows its sort numbers declares the cell; the rest
            // have no column to write into, and writing into the third cell
            // would replace the href a reader is looking at.
            Array.prototype.forEach.call(tbody.querySelectorAll('[data-sort-order]'), function(cell) {
                var row = cell.closest('tr');
                var position = row ? rows(tbody).indexOf(row) : -1;
                if (position >= 0) cell.textContent = String((position + 1) * STEP);
            });
        }).catch(function() {
            window.mToast('Network error', 'err');
        });
    }

    function wireTable(table) {
        var tbody = table.querySelector('tbody');
        if (!tbody || !table.dataset.reorderUrl) return;

        wireDrag(tbody);

        var card = table.closest('.m-card');
        var saveBtn = card ? card.querySelector('[data-nav-save-order]') : null;
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                saveOrder(table, tbody);
            });
        }
    }

    Array.prototype.forEach.call(document.querySelectorAll('table[data-nav-table]'), wireTable);
})();
