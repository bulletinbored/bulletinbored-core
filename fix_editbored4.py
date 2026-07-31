import re

js_path = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\assets\js\editbored.js'
with open(js_path, 'r', encoding='utf-8') as f:
    js_content = f.read()

# Replace renderMarkdownContent with a version that retries until marked is loaded
old_func = """function renderMarkdownContent() {
        var containers = document.querySelectorAll('.markdown-content');
        containers.forEach(function(container) {
            if (container.getAttribute('data-rendered') === 'true') return;
            var markdown = container.textContent || container.innerText || '';
            if (!markdown.trim()) return;
            if (typeof marked !== 'undefined' && marked.parse) {
                container.innerHTML = marked.parse(markdown);
            } else if (typeof marked !== 'undefined' && typeof marked === 'function') {
                container.innerHTML = marked(markdown);
            } else {
                container.innerHTML = '<p>' + escapeHtml(markdown).replace(/\\n/g, '<br>') + '</p>';
            }
            container.setAttribute('data-rendered', 'true');
        });
        // Process embeds after rendering
        setTimeout(processContentEmbeds, 100);
    }"""

new_func = """function renderMarkdownContent() {
        var containers = document.querySelectorAll('.markdown-content');
        if (containers.length === 0) return;
        // Check if marked is available, if not, retry
        if (typeof marked === 'undefined') {
            console.log('Editbored: marked not loaded yet, retrying...');
            setTimeout(renderMarkdownContent, 200);
            return;
        }
        containers.forEach(function(container) {
            if (container.getAttribute('data-rendered') === 'true') return;
            // Store raw markdown in data attribute before rendering
            var markdown = container.textContent || container.innerText || '';
            if (!markdown.trim()) return;
            container.setAttribute('data-raw', markdown);
            try {
                if (marked.parse) {
                    container.innerHTML = marked.parse(markdown);
                } else if (typeof marked === 'function') {
                    container.innerHTML = marked(markdown);
                }
                container.setAttribute('data-rendered', 'true');
            } catch(e) {
                console.error('Editbored: Error rendering markdown:', e);
                container.innerHTML = '<p>' + escapeHtml(markdown).replace(/\\n/g, '<br>') + '</p>';
            }
        });
        // Process embeds after rendering
        setTimeout(processContentEmbeds, 100);
    }"""

if old_func in js_content:
    js_content = js_content.replace(old_func, new_func)
    print('OK: Updated renderMarkdownContent with retry mechanism')
else:
    print('SKIP: renderMarkdownContent not found')

with open(js_path, 'w', encoding='utf-8') as f:
    f.write(js_content)
print('OK: editbored.js written')

# Update cache buster
php_path = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\editbored.php'
with open(php_path, 'r', encoding='utf-8') as f:
    php_content = f.read()
php_content = re.sub(r'editbored\.js\?v=\d+', 'editbored.js?v=10', php_content)
with open(php_path, 'w', encoding='utf-8') as f:
    f.write(php_content)
print('OK: Cache buster updated to v=10')