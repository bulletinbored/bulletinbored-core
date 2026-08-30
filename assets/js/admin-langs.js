(function() {
    'use strict';

    function init() {
        var list = document.getElementById('github-langs-list');
        var loading = document.getElementById('github-langs-loading');
        if (!list) return;

        var errorBox = document.getElementById('github-langs-error');

        var codes = Object.keys(REMOTE_LANGS);
        
        // Hide loading spinner
        if (loading) loading.classList.add('d-none');
        
        if (codes.length === 0) {
            errorBox.textContent = NO_LANGUAGES_TEXT;
            errorBox.classList.remove('d-none');
            return;
        }

        var table = document.createElement('table');
        table.className = 'table table-bordered';
        table.innerHTML = '<thead><tr><th>' + esc(CODE_TEXT) + '</th><th>' + esc(FILE_TEXT) + '</th><th>' + esc(ACTION_TEXT) + '</th></tr></thead><tbody></tbody>';
        var tbody = table.querySelector('tbody');

        codes.forEach(function(code) {
            var info = REMOTE_LANGS[code];
            var isInstalled = INSTALLED.indexOf(code) !== -1;
            var localSha = LOCAL_META[code] && LOCAL_META[code].sha ? LOCAL_META[code].sha : null;
            var changed = localSha !== null && localSha !== info.sha;
            var fullUrl = MIRROR_BASE.replace(/\/+$/, '') + '/' + String(info.url).replace(/^\/+/, '');

            var actionCell;
            var btnClass = 'btn btn-sm w-100';
            if (!isInstalled) {
                actionCell = '<form method="POST" class="d-inline w-100">'
                    + '<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">'
                    + '<input type="hidden" name="install_github_lang" value="1">'
                    + '<input type="hidden" name="lang_code" value="' + esc(code) + '">'
                    + '<input type="hidden" name="download_url" value="' + esc(fullUrl) + '">'
                    + '<button type="submit" class="' + btnClass + ' btn-primary">' + esc(INSTALL_TEXT) + '</button></form>';
            } else if (changed) {
                actionCell = '<span class="badge bg-warning text-dark me-1">' + esc(INSTALLED_TEXT) + '</span>'
                    + '<form method="POST" class="d-inline w-100">'
                    + '<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">'
                    + '<input type="hidden" name="update_github_lang" value="1">'
                    + '<input type="hidden" name="lang_code" value="' + esc(code) + '">'
                    + '<input type="hidden" name="download_url" value="' + esc(fullUrl) + '">'
                    + '<input type="hidden" name="remote_sha" value="' + esc(info.sha) + '">'
                    + '<button type="submit" class="' + btnClass + ' btn-warning">' + esc(UPDATE_TEXT) + '</button></form>';
            } else {
                actionCell = '<button type="button" class="' + btnClass + ' btn-success" disabled>' + esc(INSTALLED_TEXT) + '</button>';
            }

            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + esc(code) + '</td><td>' + esc(info.file) + '</td><td>' + actionCell + '</td>';
            tbody.appendChild(tr);
        });
        list.appendChild(table);
    }

    function esc(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
