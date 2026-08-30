(function() {
    'use strict';

    var currentTarget = null;
    var mediaModal = null;

    function init() {
        mediaModal = new bootstrap.Modal(document.getElementById('mediaLibraryModal'));

        var uploadLogoBtn = document.getElementById('uploadLogoBtn');
        if (uploadLogoBtn) uploadLogoBtn.addEventListener('click', uploadLogo);

        var uploadFaviconBtn = document.getElementById('uploadFaviconBtn');
        if (uploadFaviconBtn) uploadFaviconBtn.addEventListener('click', uploadFavicon);

        var libraryLogoBtn = document.getElementById('libraryLogoBtn');
        if (libraryLogoBtn) libraryLogoBtn.addEventListener('click', function() { openMediaLibrary('logo'); });

        var libraryFaviconBtn = document.getElementById('libraryFaviconBtn');
        if (libraryFaviconBtn) libraryFaviconBtn.addEventListener('click', function() { openMediaLibrary('favicon'); });

        var removeLogoBtn = document.getElementById('removeLogoBtn');
        if (removeLogoBtn) removeLogoBtn.addEventListener('click', removeLogo);

        var removeFaviconBtn = document.getElementById('removeFaviconBtn');
        if (removeFaviconBtn) removeFaviconBtn.addEventListener('click', removeFavicon);
    }

    function uploadLogo() {
        var input = document.getElementById('logoUploadInput');
        input.onchange = function() {
            if (input.files.length > 0) {
                uploadFile(input.files[0], 'logo');
            }
        };
        input.click();
    }

    function uploadFavicon() {
        var input = document.getElementById('faviconUploadInput');
        input.onchange = function() {
            if (input.files.length > 0) {
                uploadFile(input.files[0], 'favicon');
            }
        };
        input.click();
    }

    function uploadFile(file, target) {
        var formData = new FormData();
        formData.append('site_image', file);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        fetch(BASE_URL + '/admin/upload-site-image', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) {
            if (!r.ok) {
                return r.text().then(function(text) { throw new Error('HTTP ' + r.status + ': ' + text.substring(0, 200)); });
            }
            return r.json().catch(function(e) { throw new Error('Invalid JSON response'); });
        })
        .then(function(data) {
            if (data.ok) {
                if (data.csrf_token) {
                    var csrfInput = document.querySelector('input[name="csrf_token"]');
                    if (csrfInput) csrfInput.value = data.csrf_token;
                }
                if (target === 'logo') {
                    setLogo(data.url);
                } else {
                    setFavicon(data.url);
                }
            } else {
                alert(data.error || 'Upload failed');
            }
        })
        .catch(function(err) { alert('Upload error: ' + err); });
    }

    function setLogo(url) {
        document.getElementById('siteLogoInput').value = url;
        var preview = document.getElementById('logoPreview');
        preview.querySelector('img').src = url;
        preview.classList.remove('d-none');
        document.getElementById('noLogoMsg').classList.add('d-none');
        document.getElementById('removeLogoBtn').classList.remove('d-none');
    }

    function setFavicon(url) {
        document.getElementById('siteFaviconInput').value = url;
        var preview = document.getElementById('faviconPreview');
        preview.querySelector('img').src = url;
        preview.classList.remove('d-none');
        document.getElementById('noFaviconMsg').classList.add('d-none');
        document.getElementById('removeFaviconBtn').classList.remove('d-none');
    }

    function removeLogo() {
        document.getElementById('siteLogoInput').value = '';
        document.getElementById('logoPreview').classList.add('d-none');
        document.getElementById('noLogoMsg').classList.remove('d-none');
        document.getElementById('removeLogoBtn').classList.add('d-none');
    }

    function removeFavicon() {
        document.getElementById('siteFaviconInput').value = '';
        document.getElementById('faviconPreview').classList.add('d-none');
        document.getElementById('noFaviconMsg').classList.remove('d-none');
        document.getElementById('removeFaviconBtn').classList.add('d-none');
    }

    function openMediaLibrary(target) {
        currentTarget = target;
        mediaModal.show();
        loadImages();
    }

    function loadImages() {
        fetch(BASE_URL + '/admin/get-images')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('mediaLibraryLoading').classList.add('d-none');
            var grid = document.getElementById('mediaLibraryGrid');
            var empty = document.getElementById('mediaLibraryEmpty');

            if (!data.ok || !data.images || data.images.length === 0) {
                empty.classList.remove('d-none');
                grid.classList.add('d-none');
                return;
            }

            empty.classList.add('d-none');
            grid.classList.remove('d-none');
            grid.innerHTML = '';

            data.images.forEach(function(img) {
                var col = document.createElement('div');
                col.className = 'col-4 col-md-3 col-lg-2';
                col.innerHTML = '<div class="media-library-item" data-url="' + img.url + '">' +
                    '<div class="media-library-thumb">' +
                        '<img src="' + img.url + '" alt="' + img.filename + '" loading="lazy">' +
                    '</div>' +
                    '<div class="media-library-select">' +
                        '<button type="button" class="btn btn-sm btn-primary w-100">' + SELECT_IMAGE_TEXT + '</button>' +
                    '</div>' +
                '</div>';
                grid.appendChild(col);
            });

            grid.querySelectorAll('.media-library-item').forEach(function(item) {
                item.style.cursor = 'pointer';
                item.addEventListener('click', function() {
                    var url = this.dataset.url;
                    if (currentTarget === 'logo') {
                        setLogo(url);
                    } else {
                        setFavicon(url);
                    }
                    mediaModal.hide();
                });
            });
        })
        .catch(function(err) {
            document.getElementById('mediaLibraryLoading').classList.add('d-none');
            document.getElementById('mediaLibraryEmpty').classList.remove('d-none');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
