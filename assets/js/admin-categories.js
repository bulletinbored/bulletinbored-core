(function() {
    'use strict';

    var tbody = null;
    var saveBtn = null;
    var csrfToken = '';

    function init() {
        tbody = document.getElementById('categories-sortable');
        saveBtn = document.getElementById('save-order-btn');
        if (!tbody || !saveBtn) return;

        csrfToken = document.querySelector('input[name="csrf_token"]') ? document.querySelector('input[name="csrf_token"]').value : '';

        Sortable.create(tbody, {
            handle: 'i.fa-grip-vertical',
            animation: 150,
            onEnd: function() {
                saveBtn.disabled = false;
                updatePositionNumbers();
            }
        });

        saveBtn.addEventListener('click', saveOrder);
    }

    function updatePositionNumbers() {
        var rows = tbody.querySelectorAll('tr[data-id]');
        rows.forEach(function(row, index) {
            var posCell = row.querySelector('.position-display');
            if (posCell) {
                posCell.textContent = index + 1;
            }
        });
    }

    function saveOrder() {
        var rows = tbody.querySelectorAll('tr[data-id]');
        var order = Array.from(rows).map(function(row) { return row.getAttribute('data-id'); });
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + SAVE_TEXT;

        fetch(UPDATE_ORDER_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken) + '&order=' + encodeURIComponent(JSON.stringify(order))
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                updatePositionNumbers();
                saveBtn.disabled = true;
            } else {
                alert(ERROR_TEXT);
                saveBtn.disabled = false;
            }
        })
        .catch(function() {
            alert(ERROR_TEXT);
            saveBtn.disabled = false;
        })
        .finally(function() {
            saveBtn.innerHTML = '<i class="fas fa-save me-1"></i>' + SAVE_TEXT;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
