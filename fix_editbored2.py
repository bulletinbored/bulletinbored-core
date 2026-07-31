import re

# Fix 1: Update marked_parse() in index.php to NOT double-escape
php_path = r'c:\xampp\htdocs\forum-nuovo\index.php'
with open(php_path, 'r', encoding='utf-8') as f:
    php_content = f.read()

old_func = """function marked_parse($text) {
    if (empty($text)) return '';
    // Output raw markdown in a div - JavaScript will render it with marked.parse()
    return '<div class="markdown-content">' . escape($text) . '</div>';
}"""

new_func = """function marked_parse($text) {
    if (empty($text)) return '';
    // Content is already escaped by validate_input(), don't escape again
    // JavaScript renderMarkdownContent() will parse it with marked.parse()
    return '<div class="markdown-content">' . $text . '</div>';
}"""

if old_func in php_content:
    php_content = php_content.replace(old_func, new_func)
    print('OK: Fixed marked_parse() - removed double escaping')
else:
    print('SKIP: marked_parse() not found')

with open(php_path, 'w', encoding='utf-8') as f:
    f.write(php_content)
print('OK: index.php written')

# Fix 2: Add inline code to exit handler in editbored.js
js_path = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\assets\js\editbored.js'
with open(js_path, 'r', encoding='utf-8') as f:
    js_content = f.read()

# Add inline code exit on Enter
old_exit = 'var pre = node.parentElement.closest("pre");'
new_exit = 'var pre = node.parentElement.closest("pre"); var codeEl = node.parentElement.closest("code");'

if old_exit in js_content and 'codeEl' not in js_content:
    js_content = js_content.replace(old_exit, new_exit)
    print('OK: Added codeEl variable')
else:
    print('SKIP: codeEl already exists or pre not found')

# Update the condition to also check for code
old_cond = 'if (pre || bq) {'
new_cond = 'if (pre || bq || codeEl) {'

if old_cond in js_content and 'codeEl' not in js_content.split('if (pre || bq || codeEl)')[0][-100:]:
    js_content = js_content.replace(old_cond, new_cond, 1)
    print('OK: Updated condition to include codeEl')
else:
    print('SKIP: condition already updated or not found')

# Update the block variable
old_block = 'var block = pre || bq;'
new_block = 'var block = pre || bq || codeEl;'

if old_block in js_content and 'codeEl' not in js_content.split('var block = pre || bq || codeEl)')[0][-100:]:
    js_content = js_content.replace(old_block, new_block, 1)
    print('OK: Updated block variable')
else:
    print('SKIP: block already updated or not found')

with open(js_path, 'w', encoding='utf-8') as f:
    f.write(js_content)
print('OK: editbored.js written')

# Fix 3: Update cache buster
php_path2 = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\editbored.php'
with open(php_path2, 'r', encoding='utf-8') as f:
    php_content2 = f.read()
php_content2 = re.sub(r'editbored\.js\?v=\d+', 'editbored.js?v=8', php_content2)
with open(php_path2, 'w', encoding='utf-8') as f:
    f.write(php_content2)
print('OK: Cache buster updated to v=8')