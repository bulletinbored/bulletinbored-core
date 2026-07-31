import re

# Fix: Make renderMarkdownContent() idempotent - skip already rendered divs
js_path = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\assets\js\editbored.js'
with open(js_path, 'r', encoding='utf-8') as f:
    js_content = f.read()

# Add data-rendered check to renderMarkdownContent
old_render = """function renderMarkdownContent() {
        var containers = document.querySelectorAll('.markdown-content');
        containers.forEach(function(container) {
            var markdown = container.textContent || container.innerText || '';
            if (!markdown.trim()) return;"""

new_render = """function renderMarkdownContent() {
        var containers = document.querySelectorAll('.markdown-content');
        containers.forEach(function(container) {
            if (container.getAttribute('data-rendered') === 'true') return;
            var markdown = container.textContent || container.innerText || '';
            if (!markdown.trim()) return;"""

if old_render in js_content:
    js_content = js_content.replace(old_render, new_render)
    print('OK: Added data-rendered check')
else:
    print('SKIP: renderMarkdownContent not found')

# Add data-rendered attribute after rendering
old_set = """            if (typeof marked !== 'undefined' && marked.parse) {
                container.innerHTML = marked.parse(markdown);
            } else if (typeof marked !== 'undefined' && typeof marked === 'function') {
                container.innerHTML = marked(markdown);
            } else {
                container.innerHTML = '<p>' + escapeHtml(markdown).replace(/\\n/g, '<br>') + '</p>';
            }"""

new_set = """            if (typeof marked !== 'undefined' && marked.parse) {
                container.innerHTML = marked.parse(markdown);
            } else if (typeof marked !== 'undefined' && typeof marked === 'function') {
                container.innerHTML = marked(markdown);
            } else {
                container.innerHTML = '<p>' + escapeHtml(markdown).replace(/\\n/g, '<br>') + '</p>';
            }
            container.setAttribute('data-rendered', 'true');"""

if old_set in js_content:
    js_content = js_content.replace(old_set, new_set)
    print('OK: Added data-rendered attribute')
else:
    print('SKIP: set innerHTML not found')

with open(js_path, 'w', encoding='utf-8') as f:
    f.write(js_content)
print('OK: editbored.js written')

# Update cache buster
php_path = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\editbored.php'
with open(php_path, 'r', encoding='utf-8') as f:
    php_content = f.read()
php_content = re.sub(r'editbored\.js\?v=\d+', 'editbored.js?v=9', php_content)
with open(php_path, 'w', encoding='utf-8') as f:
    f.write(php_content)
print('OK: Cache buster updated to v=9')