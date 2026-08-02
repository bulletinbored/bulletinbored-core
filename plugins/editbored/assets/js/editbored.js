(function() {
    'use strict';

    const USERS = (window.editbored && window.editbored.users) ? window.editbored.users : [];
    const UPLOAD_URL = (window.editbored && window.editbored.uploadUrl) ? window.editbored.uploadUrl : '';
    const CSRF = (window.editbored && window.editbored.csrfToken) ? window.editbored.csrfToken : '';

    var icons = {
        bold: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4h8a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"></path><path d="M6 12h9a4 4 0 0 1 4 4 4 4 0 0 1-4 4H6z"></path></svg>',
        italic: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="4" x2="10" y2="4"></line><line x1="14" y1="20" x2="5" y2="20"></line><line x1="15" y1="4" x2="9" y2="20"></line></svg>',
        strikethrough: '<svg viewBox="0 0 20 20" fill="currentColor" height="16" width="16"><path d="M5.83 7.435a4.11 4.11 0 01-.26-1.473 3.906 3.906 0 01.547-2.077A3.65 3.65 0 017.647 2.5a5.027 5.027 0 012.267-.489 4.888 4.888 0 012.837.8c.46.312.866.698 1.2 1.143a.79.79 0 01-.366 1.188 1.391 1.391 0 01-1.43-.457 3.435 3.435 0 00-.968-.674A3 3 0 008.7 4c-.37.171-.686.442-.912.782A2.132 2.132 0 007.441 6a2.299 2.299 0 00.454 1.441L5.83 7.435zM18 8.998H2a.9.9 0 100 1.799h8.4c.3.116.565.227.791.332.469.23.89.545 1.243.928a2.199 2.199 0 01.611 1.583c.005.478-.134.947-.4 1.345a2.74 2.74 0 01-1.068.938 3.243 3.243 0 01-2.9-.005 3.447 3.447 0 01-1.126-.928 4.062 4.062 0 01-.34-.5.951.951 0 10-1.632.975 5.666 5.666 0 002.344 2.043c.701.334 1.47.505 2.246.5a5.052 5.052 0 002.353-.562 4.48 4.48 0 001.743-1.578 4.136 4.136 0 00.653-2.288 3.628 3.628 0 00-.781-2.39 6.709 6.709 0 00-.351-.39h4.213a.902.902 0 000-1.802z"></path></svg>',
        ul: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><circle cx="4" cy="6" r="1" fill="currentColor"></circle><circle cx="4" cy="12" r="1" fill="currentColor"></circle><circle cx="4" cy="18" r="1" fill="currentColor"></circle></svg>',
        ol: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="10" y1="6" x2="21" y2="6"></line><line x1="10" y1="12" x2="21" y2="12"></line><line x1="10" y1="18" x2="21" y2="18"></line><text x="3" y="8" font-size="8" fill="currentColor" stroke="none">1</text><text x="3" y="14" font-size="8" fill="currentColor" stroke="none">2</text><text x="3" y="20" font-size="8" fill="currentColor" stroke="none">3</text></svg>',
        link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>',
        image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>',
        code: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>',
        codeblock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><polyline points="8 8 8 16"></polyline><polyline points="16 8 16 16"></polyline><line x1="10" y1="12" x2="14" y2="12"></line></svg>',
        quote: '<span style="font-size: 16px; font-weight: bold;">"</span>',
        mention: '<span style="font-size: 16px; font-weight: bold;">@</span>',
        markdown: '<svg viewBox="0 0 208 128" style="width: 26px; height: 16px;"><path d="M30 98V30h20l20 25 20-25h20v68H90V59L70 84 50 59v39zm125 0l-30-33h20V30h20v35h20z" fill="currentColor" fill-rule="evenodd"/></svg>'
    };

    var toolbarConfig = [
        { cmd: 'bold', icon: 'bold', title: 'Bold (Ctrl+B)' },
        { cmd: 'italic', icon: 'italic', title: 'Italic (Ctrl+I)' },
        { cmd: 'strikethrough', icon: 'strikethrough', title: 'Strikethrough' },
        { type: 'divider' },
        { cmd: 'ul', icon: 'ul', title: 'Bullet List' },
        { cmd: 'ol', icon: 'ol', title: 'Numbered List' },
        { type: 'divider' },
        { cmd: 'link', icon: 'link', title: 'Insert Link' },
        { cmd: 'image', icon: 'image', title: 'Insert Image' },
        { cmd: 'code', icon: 'code', title: 'Inline Code' },
        { cmd: 'codeblock', icon: 'codeblock', title: 'Code Block' },
        { cmd: 'quote', icon: 'quote', title: 'Quote' },
        { type: 'divider' },
        { cmd: 'mention', icon: 'mention', title: 'Mention (@)' },
        { cmd: 'markdown', icon: 'markdown', title: 'Toggle Markdown View' }
    ];

    var mentionDropdown = null;
    var activeMentionEditor = null;
    var markdownMode = {};

    function findTextareas() {
        return Array.from(document.querySelectorAll('textarea')).filter(function(el) {
            return !el.closest('.editbored-wrap');
        });
    }

    function wrapTextarea(ta) {
        if (ta.closest('.editbored-wrap')) return;

        var wrap = document.createElement('div');
        wrap.className = 'editbored-wrap';

        var toolbar = document.createElement('div');
        toolbar.className = 'editbored-toolbar';
        toolbarConfig.forEach(function(item) {
            if (item.type === 'divider') {
                var div = document.createElement('div');
                div.className = 'editbored-toolbar-divider';
                toolbar.appendChild(div);
            } else {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'editbored-toolbar-btn';
                btn.setAttribute('data-cmd', item.cmd);
                btn.title = item.title;
                btn.innerHTML = icons[item.icon] || item.icon;
                toolbar.appendChild(btn);
            }
        });

        var editor = document.createElement('div');
        editor.className = 'editbored-editor';
        editor.contentEditable = 'true';
        editor.setAttribute('data-placeholder', 'Start writing...');

        var markdownDisplay = document.createElement('textarea');
        markdownDisplay.className = 'editbored-markdown-display';
        markdownDisplay.style.cssText = 'display:none;width:100%;min-height:300px;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;padding:12px 16px;font-family:monospace;font-size:14px;background:#f8f9fa;color:#333;resize:vertical;box-sizing:border-box;';
        markdownDisplay.readOnly = true;

        var backBtn = document.createElement('button');
        backBtn.type = 'button';
        backBtn.className = 'editbored-back-to-rich';
        backBtn.textContent = 'Back to rich text';
        backBtn.addEventListener('click', function() {
            toggleMarkdownView(editor, markdownDisplay);
        });

        var progress = document.createElement('div');
        progress.className = 'editbored-progress';
        var progressBar = document.createElement('div');
        progressBar.className = 'editbored-progress-bar';
        progress.appendChild(progressBar);

        wrap.appendChild(toolbar);
        wrap.appendChild(editor);
        wrap.appendChild(markdownDisplay);
        wrap.appendChild(backBtn);
        wrap.appendChild(progress);

        ta.parentNode.insertBefore(wrap, ta);
        wrap.appendChild(ta);
        ta.style.display = 'none';

        if (ta.value) {
            // Check if content looks like HTML (from new save method) or markdown (from old method)
            if (/<[a-z][\s\S]*>/i.test(ta.value)) {
                // Content is HTML, load directly
                editor.innerHTML = ta.value;
            } else if (typeof marked !== 'undefined') {
                // Content is markdown, parse with marked
                editor.innerHTML = marked.parse(ta.value);
            } else {
                editor.textContent = ta.value;
            }
        }

        var form = ta.closest('form');
        if (form) {
            form.setAttribute('novalidate', '');
            ta.removeAttribute('required');
            form.addEventListener('submit', function(e) {
                try {
                    // Save HTML directly instead of converting to markdown
                    // This preserves all formatting (bold, italic, etc.)
                    ta.value = editor.innerHTML;
                } catch(e) {
                    ta.value = editor.textContent || editor.innerText || '';
                }
                if (!editor.textContent || !editor.textContent.trim()) {
                    e.preventDefault();
                    editor.focus();
                    editor.style.border = '1px solid #ff3b30';
                    setTimeout(function() { editor.style.border = ''; }, 2000);
                }
            });
        }

        toolbar.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-cmd]');
            if (!btn) return;
            var cmd = btn.getAttribute('data-cmd');
            handleCommand(editor, markdownDisplay, cmd, ta);
        });

        editor.addEventListener('input', function() { updateToolbarState(toolbar); });
        editor.addEventListener('keyup', function() { updateToolbarState(toolbar); });
        editor.addEventListener('mouseup', function() { updateToolbarState(toolbar); });
        editor.addEventListener('click', function() { updateToolbarState(toolbar); });

        editor.addEventListener('input', function() { handleMention(editor, ta); });
        editor.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && mentionDropdown) { closeMentionDropdown(); }
            if (e.key === 'Enter' && mentionDropdown && mentionDropdown.style.display !== 'none') {
                e.preventDefault();
                var active = mentionDropdown.querySelector('.active');
                if (active) selectMention(active, editor, ta);
            }
            if (e.key === 'ArrowDown' && mentionDropdown && mentionDropdown.style.display !== 'none') {
                e.preventDefault();
                var items = mentionDropdown.querySelectorAll('.editbored-mention-item');
                var active = mentionDropdown.querySelector('.active');
                var idx = Array.from(items).indexOf(active);
                if (active) active.classList.remove('active');
                items[(idx + 1) % items.length].classList.add('active');
            }
            if (e.key === 'ArrowUp' && mentionDropdown && mentionDropdown.style.display !== 'none') {
                e.preventDefault();
                var items = mentionDropdown.querySelectorAll('.editbored-mention-item');
                var active = mentionDropdown.querySelector('.active');
                var idx = Array.from(items).indexOf(active);
                if (active) active.classList.remove('active');
                items[(idx - 1 + items.length) % items.length].classList.add('active');
            }
        });

        editor.addEventListener('paste', function(e) {
            e.preventDefault();
            var text = e.clipboardData.getData('text/plain');
            document.execCommand('insertText', false, text);
            setTimeout(function() { convertUrlsToEmbeds(editor); }, 50);
        });

        editor.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
                e.preventDefault();
                document.execCommand('bold', false, null);
                updateToolbarState(toolbar);
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'i') {
                e.preventDefault();
                document.execCommand('italic', false, null);
                updateToolbarState(toolbar);
            }
            // Exit code block or blockquote on Enter at end of block
            if (e.key === "Enter" && !e.shiftKey) {
                var sel = window.getSelection();
                if (sel.rangeCount > 0) {
                    var node = sel.anchorNode;
                    var pre = node.parentElement.closest("pre"); var codeEl = node.parentElement.closest("code");
                    var bq = node.parentElement.closest("blockquote");
                    if (pre || bq || codeEl) {
                        var block = pre || bq || codeEl;
                        var range = sel.getRangeAt(0);
                        var blockRange = document.createRange();
                        blockRange.selectNodeContents(block);
                        blockRange.setStart(range.endContainer, range.endOffset);
                        var isAtEnd = blockRange.toString().trim() === "";
                        if (isAtEnd) {
                            e.preventDefault();
                            var p = document.createElement("p");
                            p.innerHTML = "<br>";
                            block.parentNode.insertBefore(p, block.nextSibling);
                            var newRange = document.createRange();
                            newRange.setStart(p, 0);
                            newRange.collapse(true);
                            sel.removeAllRanges();
                            sel.addRange(newRange);
                        }
                    }
                }
            }
        });

        editor.addEventListener('blur', function() {
            setTimeout(function() { convertUrlsToEmbeds(editor); }, 100);
        });

        editor.focus();

        if (!mentionDropdown) {
            mentionDropdown = document.createElement('div');
            mentionDropdown.className = 'editbored-mentions-dropdown';
            document.body.appendChild(mentionDropdown);
        }
    }

    function handleCommand(editor, markdownDisplay, cmd, ta) {
        editor.focus();
        switch (cmd) {
            case 'bold': document.execCommand('bold', false, null); break;
            case 'italic': document.execCommand('italic', false, null); break;
            case 'strikethrough': document.execCommand('strikeThrough', false, null); break;
            case 'ul': document.execCommand('insertUnorderedList', false, null); break;
            case 'ol': document.execCommand('insertOrderedList', false, null); break;
            case 'link': insertLink(editor); break;
            case 'image': triggerImageUpload(editor, ta); break;
            case 'code': toggleInlineCode(editor); break;
            case 'codeblock': toggleCodeBlock(editor); break;
            case 'quote': toggleQuote(editor); break;
            case 'mention': document.execCommand('insertText', false, '@'); handleMention(editor, ta); break;
            case 'markdown': toggleMarkdownView(editor, markdownDisplay); break;
        }
        updateToolbarState(editor.closest('.editbored-wrap').querySelector('.editbored-toolbar'));
    }

    function toggleInlineCode(editor) {
        var selection = window.getSelection();
        if (selection.rangeCount > 0) {
            var range = selection.getRangeAt(0);
            var parent = range.startContainer.parentElement;
            if (parent && parent.closest && parent.closest('code')) {
                var code = parent.closest('code');
                var text = document.createTextNode(code.textContent);
                code.parentNode.replaceChild(text, code);
            } else {
                var text = selection.toString() || 'code';
                document.execCommand('insertHTML', false, '<code>' + escapeHtml(text) + '</code>');
            }
        }
    }

    function toggleCodeBlock(editor) {
        var selection = window.getSelection();
        if (selection.rangeCount > 0) {
            var range = selection.getRangeAt(0);
            var pre = range.startContainer.parentElement.closest('pre');
            if (pre) {
                var text = document.createTextNode(pre.textContent);
                pre.parentNode.replaceChild(text, pre);
            } else {
                var text = selection.toString() || 'code';
                document.execCommand('insertHTML', false, '<pre><code>' + escapeHtml(text) + '</code></pre>');
            }
        }
    }

    function toggleQuote(editor) {
        var selection = window.getSelection();
        if (selection.rangeCount > 0) {
            var range = selection.getRangeAt(0);
            var blockquote = range.startContainer.parentElement.closest('blockquote');
            if (blockquote) {
                var p = document.createElement('p');
                p.innerHTML = blockquote.innerHTML;
                blockquote.parentNode.replaceChild(p, blockquote);
            } else {
                var text = selection.toString() || 'Quote';
                document.execCommand('insertHTML', false, '<blockquote><p>' + escapeHtml(text) + '</p></blockquote>');
            }
        }
    }

    function toggleMarkdownView(editor, markdownDisplay) {
        var wrap = editor.closest('.editbored-wrap');
        var toolbar = wrap.querySelector('.editbored-toolbar');
        var backBtn = wrap.querySelector('.editbored-back-to-rich');
        var isMarkdownMode = markdownDisplay.style.display !== 'none';

        if (isMarkdownMode) {
            // Switch back to WYSIWYG
            markdownDisplay.style.display = 'none';
            editor.style.display = 'block';
            toolbar.style.display = 'flex';
            if (backBtn) backBtn.style.display = 'none';
            // Parse markdown back to HTML
            if (typeof marked !== 'undefined' && markdownDisplay.value) {
                editor.innerHTML = marked.parse(markdownDisplay.value);
            }
            editor.focus();
        } else {
            // Switch to markdown view
            markdownDisplay.value = htmlToMarkdown(editor.innerHTML);
            editor.style.display = 'none';
            markdownDisplay.style.display = 'block';
            toolbar.style.display = 'none';
            if (backBtn) backBtn.style.display = 'block';
        }
    }

    function insertLink(editor) {
        var url = prompt('Enter URL:');
        if (url) {
            var selection = window.getSelection();
            var text = selection.toString() || url;
            document.execCommand('insertHTML', false, '<a href="' + escapeHtml(url) + '" target="_blank">' + escapeHtml(text) + '</a>');
        }
    }

    function triggerImageUpload(editor, ta) {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.onchange = function() {
            if (!input.files.length) return;
            var wrap = editor.closest('.editbored-wrap');
            uploadImage(input.files[0], editor, ta, wrap.querySelector('.editbored-progress-bar'), wrap.querySelector('.editbored-progress'));
        };
        input.click();
    }

    function uploadImage(file, editor, ta, progressBar, progress) {
        if (!UPLOAD_URL) { alert('Image upload not configured'); return; }
        var formData = new FormData();
        formData.append('editbored_image', file);
        formData.append('csrf_token', CSRF);
        progress.style.display = 'block';
        progressBar.style.width = '50%';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', UPLOAD_URL, true);
        xhr.onload = function() {
            progress.style.display = 'none';
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.url) {
                        editor.focus();
                        document.execCommand('insertHTML', false, '<img src="' + escapeHtml(data.url) + '" alt="' + escapeHtml(file.name) + '" style="max-width:100%;">');
                    } else if (data.error) { alert(data.error); }
                } catch (e) { alert('Upload failed'); }
            } else { alert('Upload failed: ' + xhr.status); }
        };
        xhr.onerror = function() { progress.style.display = 'none'; alert('Network error'); };
        xhr.send(formData);
    }

    function handleMention(editor, ta) {
        var selection = window.getSelection();
        if (!selection.rangeCount) { closeMentionDropdown(); return; }
        var range = selection.getRangeAt(0);
        var node = selection.anchorNode;
        if (!node) { closeMentionDropdown(); return; }
        var text = node.textContent || '';
        var offset = selection.anchorOffset;
        var textBefore = text.substring(0, offset);
        var atIdx = textBefore.lastIndexOf('@');
        if (atIdx === -1 || (atIdx > 0 && textBefore.charAt(atIdx - 1).match(/\w/))) { closeMentionDropdown(); return; }
        var query = textBefore.substring(atIdx + 1);
        if (query === '' || query.indexOf(' ') === -1) {
            var filtered = USERS.filter(function(u) { return u.username.toLowerCase().startsWith(query.toLowerCase()); });
            if (filtered.length > 0) {
                mentionDropdown.innerHTML = '';
                filtered.forEach(function(u, i) {
                    var item = document.createElement('div');
                    item.className = 'editbored-mention-item' + (i === 0 ? ' active' : '');
                    item.textContent = '@' + u.username;
                    item.addEventListener('mousedown', function(e) { e.preventDefault(); selectMention(item, editor, ta); });
                    mentionDropdown.appendChild(item);
                });
                var rect = range.getBoundingClientRect();
                mentionDropdown.style.display = 'block';
                mentionDropdown.style.left = rect.left + 'px';
                mentionDropdown.style.top = (rect.bottom + 4) + 'px';
                mentionDropdown.style.position = 'fixed';
                activeMentionEditor = editor;
            } else { closeMentionDropdown(); }
        } else { closeMentionDropdown(); }
    }

    function selectMention(item, editor, ta) {
        var username = item.textContent.replace('@', '');
        var selection = window.getSelection();
        if (selection.rangeCount) {
            var range = selection.getRangeAt(0);
            var node = selection.anchorNode;
            var text = node.textContent || '';
            var offset = selection.anchorOffset;
            var textBefore = text.substring(0, offset);
            var atIdx = textBefore.lastIndexOf('@');
            if (atIdx !== -1) {
                var newRange = document.createRange();
                newRange.setStart(node, atIdx);
                newRange.setEnd(node, offset);
                newRange.deleteContents();
                var span = document.createElement('span');
                span.className = 'mention';
                span.contentEditable = 'false';
                span.textContent = '@' + username;
                newRange.insertNode(span);
                var space = document.createTextNode('\u00A0');
                span.parentNode.insertBefore(space, span.nextSibling);
                var newRange2 = document.createRange();
                newRange2.setStart(space, 1);
                newRange2.collapse(true);
                selection.removeAllRanges();
                selection.addRange(newRange2);
            }
        }
        closeMentionDropdown();
    }

    function closeMentionDropdown() {
        if (mentionDropdown) { mentionDropdown.style.display = 'none'; mentionDropdown.innerHTML = ''; }
        activeMentionEditor = null;
    }

    // ===== LINK PREVIEW SYSTEM =====

    function detectPreviewType(url) {
        if (/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/)|youtu\.be\/)/.test(url)) return 'youtube';
        if (/(?:twitter|x)\.com\/[a-zA-Z0-9_]+\/status\/(\d+)/.test(url)) return 'twitter';
        if (/instagram\.com\/(?:[a-zA-Z0-9_.]+\/)?p\/[a-zA-Z0-9_-]+\/?/.test(url)) return 'instagram';
        if (/facebook\.com\/(?:watch\/\?v=|reel\/|(?:[a-zA-Z0-9.]+\/)?(?:videos|posts)\/)/.test(url)) return 'facebook';
        if (/\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i.test(url)) return 'image';
        if (/^https?:\/\/[^\s]+$/.test(url)) return 'generic';
        return null;
    }

    function createEmbedHTML(url, type) {
        if (type === 'youtube') {
            var match = url.match(/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]+)/);
            var id = match ? match[1] : '';
            if (!id) return null;
            return '<div class="link-preview link-preview--youtube" style="position:relative;padding-top:56.25%;background:#000;border-radius:12px;overflow:hidden;">' +
                '<iframe src="https://www.youtube.com/embed/' + id + '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none;" allowfullscreen></iframe></div>';
        }
        if (type === 'twitter') {
            var match = url.match(/(?:twitter|x)\.com\/[a-zA-Z0-9_]+\/status\/(\d+)/);
            var tid = match ? match[1] : '';
            if (!tid) return null;
            return '<div class="link-preview link-preview--twitter" style="background:#000;border-radius:12px;overflow:hidden;">' +
                '<iframe src="https://platform.twitter.com/embed/Tweet.html?id=' + tid + '" style="border:none;width:100%;height:350px;display:block;"></iframe></div>';
        }
        if (type === 'instagram') {
            return '<div class="link-preview link-preview--instagram" style="min-height:400px;background:#fff;border:1px solid #dbdbdb;border-radius:12px;overflow:hidden;">' +
                '<blockquote class="instagram-media" data-instgrm-captioned data-instgrm-permalink="' + url + '" data-instgrm-version="15" style="background:#FFF;border:0;border-radius:3px;box-shadow:0 0 1px 0 rgba(0,0,0,0.5),0 1px 10px 0 rgba(0,0,0,0.15);margin:1px;max-width:540px;min-width:326px;padding:0;width:99.375%;width:-webkit-calc(100% - 2px);width:calc(100% - 2px);">' +
                '<div style="padding:16px;"><a href="' + url + '" style="background:#FFFFFF;line-height:0;padding:0 0;text-align:center;text-decoration:none;width:100%;" target="_blank">' +
                '<div style="display:flex;flex-direction:row;align-items:center;"><div style="background-color:#F4F4F4;border-radius:50%;flex-grow:0;height:40px;margin-right:14px;width:40px;"></div>' +
                '<div style="display:flex;flex-direction:column;flex-grow:1;justify-content:center;"><div style="background-color:#F4F4F4;border-radius:4px;flex-grow:0;height:14px;margin-bottom:6px;width:100px;"></div>' +
                '<div style="background-color:#F4F4F4;border-radius:4px;flex-grow:0;height:14px;width:60px;"></div></div></div>' +
                '<div style="padding:19% 0;"></div><div style="display:block;height:50px;margin:0 auto 12px;width:50px;">' +
                '<svg width="50px" height="50px" viewBox="0 0 60 60" version="1.1" xmlns="https://www.w3.org/2000/svg"><g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd"><g transform="translate(-511.000000, -20.000000)" fill="#000000"><g><path d="M556.869,30.41 C554.814,30.41 553.148,32.076 553.148,34.131 C553.148,36.186 554.814,37.852 556.869,37.852 C558.924,37.852 560.59,36.186 560.59,34.131 C560.59,32.076 558.924,30.41 556.869,30.41 M541,60.657 C535.114,60.657 530.342,55.887 530.342,50 C530.342,44.114 535.114,39.342 541,39.342 C546.887,39.342 551.658,44.114 551.658,50 C551.658,55.887 546.887,60.657 541,60.657 M541,33.886 C532.1,33.886 524.886,41.1 524.886,50 C524.886,58.899 532.1,66.113 541,66.113 C549.9,66.113 557.115,58.899 557.115,50 C557.115,41.1 549.9,33.886 541,33.886 M565.378,62.101 C565.244,65.022 564.756,66.606 564.346,67.663 C563.803,69.06 563.154,70.057 562.106,71.106 C561.058,72.155 560.06,72.803 558.662,73.347 C557.607,73.757 556.021,74.244 553.102,74.378 C549.944,74.521 548.997,74.552 541,74.552 C533.003,74.552 532.056,74.521 528.898,74.378 C525.979,74.244 524.393,73.757 523.338,73.347 C521.94,72.803 520.942,72.155 519.894,71.106 C518.846,70.057 518.197,69.06 517.654,67.663 C517.244,66.606 516.755,65.022 516.623,62.101 C516.479,58.943 516.448,57.996 516.448,50 C516.448,42.003 516.479,41.056 516.623,37.899 C516.755,34.978 517.244,33.391 517.654,32.338 C518.197,30.938 518.846,29.942 519.894,28.894 C520.942,27.846 521.94,27.196 523.338,26.654 C524.393,26.244 525.979,25.756 528.898,25.623 C532.057,25.479 533.004,25.448 541,25.448 C548.997,25.448 549.943,25.479 553.102,25.623 C556.021,25.756 557.607,26.244 558.662,26.654 C560.06,27.196 561.058,27.846 562.106,28.894 C563.154,29.942 563.803,30.938 564.346,32.338 C564.756,33.391 565.244,34.978 565.378,37.899 C565.522,41.056 565.552,42.003 565.552,50 C565.552,57.996 565.522,58.943 565.378,62.101"></path></g></g></g></svg></div>' +
                '<div style="padding-top:8px;"><div style="color:#3897f0;font-family:Arial,sans-serif;font-size:14px;font-style:normal;font-weight:550;line-height:18px;">Visualizza questo post su Instagram</div></div>' +
                '<div style="padding:12.5% 0;"></div></a></div></blockquote></div>';
        }
        if (type === 'facebook') {
            return '<div class="link-preview link-preview--facebook" style="min-height:200px;background:#f0f2f5;border-radius:12px;overflow:hidden;padding:16px;display:flex;align-items:center;justify-content:center;">' +
                '<div class="fb-post" data-href="' + url + '" data-width="540" data-show-text="false" style="width:100%;"></div></div>';
        }
        if (type === 'image') {
            return '<div class="link-preview link-preview--image" style="border-radius:12px;overflow:hidden;margin:0.6em 0;">' +
                '<img src="' + url + '" alt="Image" style="max-width:100%;display:block;border-radius:12px;"></div>';
        }
        if (type === 'generic') {
            var domain = '';
            try { domain = new URL(url).hostname; } catch(e) { domain = url.replace(/^https?:\/\//, '').split('/')[0]; }
            var favicon = 'https://www.google.com/s2/favicons?domain=' + domain + '&sz=32';
            return '<div class="link-preview link-preview--generic" style="border:1px solid #e0e0e0;border-radius:12px;overflow:hidden;background:#fff;">' +
                '<a href="' + url + '" target="_blank" style="display:flex;align-items:center;gap:12px;padding:12px 16px;text-decoration:none;color:inherit;">' +
                '<img src="' + favicon + '" alt="" style="width:32px;height:32px;border-radius:6px;flex-shrink:0;background:#f5f5f5;" onerror="this.style.display=\'none\'">' +
                '<div style="min-width:0;"><div style="font-weight:600;font-size:14px;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + domain + '</div>' +
                '<div style="font-size:12px;color:#888;margin-top:2px;">' + domain + '</div></div></a></div>';
        }
        return null;
    }

    function convertUrlsToEmbeds(editor) {
        var walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT, null, false);
        var textNodes = [];
        var node;
        while (node = walker.nextNode()) {
            if (node.parentElement.closest('a') || node.parentElement.closest('.link-preview-wrap')) continue;
            textNodes.push(node);
        }
        var urlRegex = /(https?:\/\/[^\s<>"']+)/g;
        textNodes.forEach(function(textNode) {
            var text = textNode.textContent;
            urlRegex.lastIndex = 0;
            var match = urlRegex.exec(text);
            if (match) {
                var url = match[0];
                var type = detectPreviewType(url);
                if (type) {
                    var range = document.createRange();
                    var idx = text.indexOf(url);
                    if (idx === -1) return;
                    range.setStart(textNode, idx);
                    range.setEnd(textNode, idx + url.length);

                    var embedHtml = createEmbedHTML(url, type);
                    if (!embedHtml) return;

                    var wrapper = document.createElement('div');
                    wrapper.className = 'link-preview-wrap';
                    wrapper.contentEditable = 'false';
                    wrapper.setAttribute('data-url', url);
                    wrapper.style.cssText = 'position:relative;margin:0.8em 0;';
                    wrapper.innerHTML = embedHtml;

                    var removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.innerHTML = '✕';
                    removeBtn.style.cssText = 'position:absolute;top:-10px;right:-10px;width:28px;height:28px;border-radius:50%;background:#ff3b30;border:2px solid #fff;color:#fff;font-size:14px;font-weight:bold;cursor:pointer;z-index:100;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.3);line-height:1;';
                    removeBtn.title = 'Remove preview';
                    removeBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var next = wrapper.nextSibling;
                        wrapper.remove();
                        if (next) {
                            next.focus();
                            var r = document.createRange();
                            r.setStart(next, 0);
                            r.collapse(true);
                            var s = window.getSelection();
                            s.removeAllRanges();
                            s.addRange(r);
                        } else {
                            editor.focus();
                        }
                    });
                    wrapper.appendChild(removeBtn);

                    range.deleteContents();
                    range.insertNode(wrapper);

                    var sel = window.getSelection();
                    var newRange = document.createRange();
                    newRange.setStartAfter(wrapper);
                    newRange.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(newRange);

                    processSocialEmbeds(wrapper, type);
                }
            }
        });
    }

    function processSocialEmbeds(wrapper, type) {
        if (type === 'instagram') {
            var tryInstagram = function(attempts) {
                if (attempts > 10) return;
                if (window.instgrm && window.instgrm.Embeds) {
                    window.instgrm.Embeds.process();
                } else {
                    setTimeout(function() { tryInstagram(attempts + 1); }, 300);
                }
            };
            setTimeout(function() { tryInstagram(0); }, 500);
        }
        if (type === 'facebook') {
            setTimeout(function() {
                if (typeof FB !== 'undefined') {
                    FB.XFBML.parse(wrapper);
                }
            }, 500);
        }
    }

    function updateToolbarState(toolbar) {
        toolbar.querySelectorAll('[data-cmd]').forEach(function(b) { b.classList.remove('active'); });
        var bb = toolbar.querySelector('[data-cmd="bold"]');
        var bi = toolbar.querySelector('[data-cmd="italic"]');
        var bs = toolbar.querySelector('[data-cmd="strikethrough"]');
        if (bb && document.queryCommandState('bold')) bb.classList.add('active');
        if (bi && document.queryCommandState('italic')) bi.classList.add('active');
        if (bs && document.queryCommandState('strikeThrough')) bs.classList.add('active');
    }

    function htmlToMarkdown(html) {
        var text = html;
        text = text.replace(/<h1[^>]*>([\s\S]*?)<\/h1>/gi, '# $1\n\n');
        text = text.replace(/<h2[^>]*>([\s\S]*?)<\/h2>/gi, '## $1\n\n');
        text = text.replace(/<h3[^>]*>([\s\S]*?)<\/h3>/gi, '### $1\n\n');
        text = text.replace(/<h4[^>]*>([\s\S]*?)<\/h4>/gi, '#### $1\n\n');
        text = text.replace(/<blockquote[^>]*>([\s\S]*?)<\/blockquote>/gi, function(m, c) {
            return '> ' + c.replace(/<br\s*\/?>/gi, '\n> ').replace(/<\/?p[^>]*>/gi, '\n> ') + '\n\n';
        });
        text = text.replace(/<pre[^>]*>([\s\S]*?)<\/pre>/gi, function(m, c) {
            c = c.replace(/<code[^>]*>([\s\S]*?)<\/code>/gi, '$1');
            c = c.replace(/<br\s*\/?>/gi, '\n');
            c = stripTags(c);
            return '```\n' + c + '\n```\n\n';
        });
        text = text.replace(/<code[^>]*>([\s\S]*?)<\/code>/gi, '`$1`');
        text = text.replace(/<li[^>]*>([\s\S]*?)<\/li>/gi, '- $1\n');
        text = text.replace(/<a[^>]*href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/gi, '[$2]($1)');
        text = text.replace(/<img[^>]*src="([^"]*)"[^>]*>/gi, '![image]($1)');
        text = text.replace(/<strong[^>]*>([\s\S]*?)<\/strong>/gi, '**$1**');
        text = text.replace(/<b[^>]*>([\s\S]*?)<\/b>/gi, '**$1**');
        text = text.replace(/<em[^>]*>([\s\S]*?)<\/em>/gi, '*$1*');
        text = text.replace(/<i[^>]*>([\s\S]*?)<\/i>/gi, '*$1*');
        text = text.replace(/<del[^>]*>([\s\S]*?)<\/del>/gi, '~~$1~~');
        text = text.replace(/<s[^>]*>([\s\S]*?)<\/s>/gi, '~~$1~~');
        text = text.replace(/<strike[^>]*>([\s\S]*?)<\/strike>/gi, '~~$1~~');
        text = text.replace(/<hr[^>]*>/gi, '\n---\n\n');
        text = text.replace(/<p[^>]*>([\s\S]*?)<\/p>/gi, '$1\n\n');
        text = text.replace(/<br\s*\/?>/gi, '\n');
        text = text.replace(/<span[^>]*class="mention"[^>]*>([\s\S]*?)<\/span>/gi, '$1');
        text = text.replace(/<div[^>]*class="link-preview-wrap"[^>]*data-url="([^"]*)"[^>]*>[\s\S]*?<\/div>/gi, '$1\n\n');
        text = stripTags(text);
        text = text.replace(/\n{4,}/g, '\n\n\n');
        return text.trim();
    }

    function stripTags(html) {
        var div = document.createElement('div');
        div.innerHTML = html;
        return div.textContent || div.innerText || '';
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function processContentEmbeds() {
        var containers = document.querySelectorAll('.thread-content, .post-content, .markdown-content');
        containers.forEach(function(container) {
            var walker = document.createTreeWalker(container, NodeFilter.SHOW_TEXT, null, false);
            var textNodes = [];
            var node;
            while (node = walker.nextNode()) {
                if (node.parentElement.closest('a') || node.parentElement.closest('.link-preview-wrap')) continue;
                textNodes.push(node);
            }
            var urlRegex = /(https?:\/\/[^\s<>"']+)/g;
            textNodes.forEach(function(textNode) {
                var text = textNode.textContent;
                urlRegex.lastIndex = 0;
                var match = urlRegex.exec(text);
                if (match) {
                    var url = match[0];
                    var type = detectPreviewType(url);
                    if (type) {
                        var range = document.createRange();
                        var idx = text.indexOf(url);
                        if (idx === -1) return;
                        range.setStart(textNode, idx);
                        range.setEnd(textNode, idx + url.length);

                        var embedHtml = createEmbedHTML(url, type);
                        if (!embedHtml) return;

                        var wrapper = document.createElement('div');
                        wrapper.className = 'link-preview-wrap';
                        wrapper.setAttribute('data-url', url);
                        wrapper.style.cssText = 'position:relative;margin:0.8em 0;';
                        wrapper.innerHTML = embedHtml;

                        range.deleteContents();
                        range.insertNode(wrapper);

                        processSocialEmbeds(wrapper, type);
                    }
                }
            });
        });
    }

    function renderMarkdownContent() {
        var containers = document.querySelectorAll('.markdown-content');
        if (containers.length === 0) return;
        containers.forEach(function(container) {
            if (container.getAttribute('data-rendered') === 'true') return;
            var raw = container.textContent || container.innerText || '';
            if (!raw.trim()) return;
            // Check if content is already HTML (saved by new method)
            var html = container.innerHTML;
            if (/<[a-z][\s\S]*>/i.test(html)) {
                // Content is already HTML, just mark as rendered
                container.setAttribute('data-rendered', 'true');
                return;
            }
            // Content is markdown (old method), parse with marked
            if (typeof marked === 'undefined') {
                console.log('editbored: marked not loaded yet, retrying...');
                setTimeout(renderMarkdownContent, 200);
                return;
            }
            container.setAttribute('data-raw', raw);
            try {
                if (marked.parse) {
                    container.innerHTML = marked.parse(raw);
                } else if (typeof marked === 'function') {
                    container.innerHTML = marked(raw);
                }
                container.setAttribute('data-rendered', 'true');
            } catch(e) {
                console.error('editbored: Error rendering markdown:', e);
                container.innerHTML = '<p>' + escapeHtml(raw).replace(/\n/g, '<br>') + '</p>';
            }
        });
        // Process embeds after rendering
        setTimeout(processContentEmbeds, 100);
    }

    function init() {
        findTextareas().forEach(wrapTextarea);
        renderMarkdownContent();
        setTimeout(processContentEmbeds, 200);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.editbored = window.editbored || {};
    window.editbored.init = init;
    window.editbored.refresh = function() {
        findTextareas().forEach(wrapTextarea);
    };
})();