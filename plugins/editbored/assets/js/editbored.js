(function() {
    'use strict';

    const USERS = (window.Editbored && window.Editbored.users) ? window.Editbored.users : [];
    const UPLOAD_URL = (window.Editbored && window.Editbored.uploadUrl) ? window.Editbored.uploadUrl : '';
    const CSRF = (window.Editbored && window.Editbored.csrfToken) ? window.Editbored.csrfToken : '';

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function findTextareas() {
        return Array.from(document.querySelectorAll('textarea')).filter(function(el) {
            return !el.closest('.editbored-wrap');
        });
    }

    function wrapTextarea(ta) {
        if (ta.closest('.editbored-wrap')) {
            return;
        }

        const originalParent = ta.parentNode;
        const wrap = document.createElement('div');
        wrap.className = 'editbored-wrap';

        const toolbar = document.createElement('div');
        toolbar.className = 'editbored-toolbar';
        toolbar.innerHTML = '<button type="button" data-cmd="bold" title="Bold"><b>B</b></button>' +
            '<button type="button" data-cmd="italic" title="Italic"><i>I</i></button>' +
            '<button type="button" data-cmd="strikethrough" title="Strikethrough"><s>S</s></button>' +
            '<button type="button" data-cmd="h" title="Heading">H</button>' +
            '<button type="button" data-cmd="ul" title="Bullet list">• UL</button>' +
            '<button type="button" data-cmd="ol" title="Numbered list">1. OL</button>' +
            '<button type="button" data-cmd="code" title="Code block">&lt;/&gt;</button>' +
            '<button type="button" data-cmd="quote" title="Quote">❝</button>' +
            '<button type="button" data-cmd="link" title="Link">🔗</button>' +
            '<button type="button" data-cmd="mention" title="Mention">@</button>' +
            '<button type="button" data-cmd="image" title="Image upload">🖼️</button>' +
            '<button type="button" data-cmd="clear" title="Clear">✕</button>';

        const preview = document.createElement('div');
        preview.className = 'editbored-preview';

        const progress = document.createElement('div');
        progress.className = 'editbored-progress';
        const progressBar = document.createElement('div');
        progressBar.className = 'editbored-progress-bar';
        progress.appendChild(progressBar);

        const pasteOverlay = document.createElement('div');
        pasteOverlay.className = 'editbored-paste-overlay';
        pasteOverlay.textContent = 'Drop image to upload';

        const container = document.createElement('div');
        container.style.position = 'relative';
        container.style.flex = '1';
        container.appendChild(ta);
        container.appendChild(pasteOverlay);
        container.appendChild(progress);

        ta.style.display = 'block';
        ta.style.width = '100%';
        ta.style.boxSizing = 'border-box';

        wrap.appendChild(toolbar);
        wrap.appendChild(container);
        wrap.appendChild(preview);

        originalParent.appendChild(wrap);
        wrap.appendChild(ta);

        function updatePreview() {
            const raw = ta.value;
            try {
                if (typeof marked !== 'undefined') {
                    preview.innerHTML = marked.parse(raw || '');
                } else {
                    preview.innerHTML = '<pre>' + escapeHtml(raw) + '</pre>';
                }
            } catch (e) {
                preview.innerHTML = '<pre>' + escapeHtml(raw) + '</pre>';
            }
        }

        updatePreview();
        ta.addEventListener('input', updatePreview);

        toolbar.addEventListener('click', function(e) {
            const btn = e.target.closest('button[data-cmd]');
            if (!btn) return;
            const cmd = btn.getAttribute('data-cmd');
            handleCommand(ta, cmd);
        });

        function handleCommand(ta, cmd) {
            const start = ta.selectionStart;
            const end = ta.selectionEnd;
            const text = ta.value;
            const selected = text.substring(start, end);

            let insert = '';
            let cursorOffset = 0;

            if (cmd === 'bold') {
                insert = '**' + (selected || 'bold text') + '**';
                cursorOffset = selected ? 0 : -2;
            } else if (cmd === 'italic') {
                insert = '*' + (selected || 'italic text') + '*';
                cursorOffset = selected ? 0 : -1;
            } else if (cmd === 'strikethrough') {
                insert = '~~' + (selected || 'text') + '~~';
                cursorOffset = selected ? 0 : -2;
            } else if (cmd === 'h') {
                insert = '## ' + (selected || 'Heading');
                cursorOffset = selected ? 0 : -1;
            } else if (cmd === 'ul') {
                insert = '- ' + (selected || 'List item');
                cursorOffset = selected ? 0 : -1;
            } else if (cmd === 'ol') {
                insert = '1. ' + (selected || 'List item');
                cursorOffset = selected ? 0 : -1;
            } else if (cmd === 'code') {
                if (selected && selected.includes('\n')) {
                    insert = '```\n' + selected + '\n```';
                    cursorOffset = -4;
                } else {
                    insert = '`' + (selected || 'code') + '`';
                    cursorOffset = selected ? 0 : -1;
                }
            } else if (cmd === 'quote') {
                insert = '> ' + (selected || 'Quote');
                cursorOffset = selected ? 0 : -1;
            } else if (cmd === 'link') {
                insert = '[' + (selected || 'Link text') + '](https://example.com)';
                cursorOffset = -1;
            } else if (cmd === 'mention') {
                insert = '@';
                cursorOffset = 0;
            } else if (cmd === 'image') {
                triggerImageUpload(ta);
                return;
            } else if (cmd === 'clear') {
                ta.value = '';
                ta.dispatchEvent(new Event('input'));
                ta.focus();
                return;
            }

            ta.value = text.substring(0, start) + insert + text.substring(end);
            const newPos = start + insert.length + cursorOffset;
            ta.selectionStart = newPos;
            ta.selectionEnd = newPos;
            ta.focus();
            ta.dispatchEvent(new Event('input'));
        }

        function triggerImageUpload(ta) {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.onchange = function() {
                if (!input.files.length) return;
                uploadImage(input.files[0], ta);
            };
            input.click();
        }

        function uploadImage(file, ta) {
            if (!UPLOAD_URL) {
                alert('Image upload not configured');
                return;
            }
            const formData = new FormData();
            formData.append('editbored_image', file);
            formData.append('csrf_token', CSRF);

            progress.style.display = 'block';
            progressBar.style.width = '50%';

            const xhr = new XMLHttpRequest();
            xhr.open('POST', UPLOAD_URL, true);
            xhr.onload = function() {
                progress.style.display = 'none';
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.url) {
                            const pos = ta.selectionStart || ta.value.length;
                            const md = '![' + escapeHtml(file.name) + '](' + data.url + ')';
                            ta.value = ta.value.substring(0, pos) + md + ta.value.substring(pos);
                            ta.dispatchEvent(new Event('input'));
                        } else if (data.error) {
                            alert(data.error);
                        }
                    } catch (e) {
                        alert('Upload failed');
                    }
                } else {
                    alert('Upload failed: ' + xhr.status);
                }
            };
            xhr.onerror = function() {
                progress.style.display = 'none';
                alert('Network error during upload');
            };
            xhr.send(formData);
        }

        function setupDragAndDrop() {
            wrap.addEventListener('dragover', function(e) {
                e.preventDefault();
                if (e.dataTransfer && e.dataTransfer.types.includes('Files')) {
                    wrap.classList.add('image-drag-over');
                }
            });
            wrap.addEventListener('dragleave', function(e) {
                e.preventDefault();
                wrap.classList.remove('image-drag-over');
            });
            wrap.addEventListener('drop', function(e) {
                e.preventDefault();
                wrap.classList.remove('image-drag-over');
                const files = e.dataTransfer ? e.dataTransfer.files : [];
                if (files.length) {
                    const file = files[0];
                    if (file.type.startsWith('image/')) {
                        uploadImage(file, ta);
                    }
                }
            });

            ta.addEventListener('paste', function(e) {
                const items = e.clipboardData && e.clipboardData.items;
                if (!items) return;
                for (let i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf('image') !== -1) {
                        e.preventDefault();
                        const file = items[i].getAsFile();
                        if (file) {
                            uploadImage(file, ta);
                        }
                        break;
                    }
                }
            });
        }

        setupDragAndDrop();

        ta._editboredPreview = preview;
        ta._editboredUpdate = updatePreview;
    }

    function init() {
        findTextareas().forEach(wrapTextarea);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.Editbored = window.Editbored || {};
    window.Editbored.init = init;
    window.Editbored.refresh = function() {
        findTextareas().forEach(wrapTextarea);
    };
})();
