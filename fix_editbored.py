import re

filepath = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\assets\js\editbored.js'

with open(filepath, 'r', encoding='utf-8') as f:
    lines = f.readlines()

# Find the keydown handler with ctrl+i
insert_after = None
for i, line in enumerate(lines):
    if 'document.execCommand' in line and 'italic' in line:
        for j in range(i+1, min(i+5, len(lines))):
            if lines[j].strip() == '});':
                insert_after = j
                break
        break

if insert_after is not None:
    new_code = '            // Exit code block or blockquote on Enter at end of block\n'
    new_code += '            if (e.key === "Enter" && !e.shiftKey) {\n'
    new_code += '                var sel = window.getSelection();\n'
    new_code += '                if (sel.rangeCount > 0) {\n'
    new_code += '                    var node = sel.anchorNode;\n'
    new_code += '                    var pre = node.parentElement.closest("pre");\n'
    new_code += '                    var bq = node.parentElement.closest("blockquote");\n'
    new_code += '                    if (pre || bq) {\n'
    new_code += '                        var block = pre || bq;\n'
    new_code += '                        var range = sel.getRangeAt(0);\n'
    new_code += '                        var blockRange = document.createRange();\n'
    new_code += '                        blockRange.selectNodeContents(block);\n'
    new_code += '                        blockRange.setStart(range.endContainer, range.endOffset);\n'
    new_code += '                        var isAtEnd = blockRange.toString().trim() === "";\n'
    new_code += '                        if (isAtEnd) {\n'
    new_code += '                            e.preventDefault();\n'
    new_code += '                            var p = document.createElement("p");\n'
    new_code += '                            p.innerHTML = "<br>";\n'
    new_code += '                            block.parentNode.insertBefore(p, block.nextSibling);\n'
    new_code += '                            var newRange = document.createRange();\n'
    new_code += '                            newRange.setStart(p, 0);\n'
    new_code += '                            newRange.collapse(true);\n'
    new_code += '                            sel.removeAllRanges();\n'
    new_code += '                            sel.addRange(newRange);\n'
    new_code += '                        }\n'
    new_code += '                    }\n'
    new_code += '                }\n'
    new_code += '            }\n'
    lines.insert(insert_after, new_code)
    print('OK: Inserted at line', insert_after)
else:
    print('SKIP: not found')

with open(filepath, 'w', encoding='utf-8') as f:
    f.writelines(lines)
print('OK: File written')

# Update cache buster
php_path = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\editbored.php'
with open(php_path, 'r', encoding='utf-8') as f:
    php_content = f.read()
php_content = re.sub(r'editbored\.js\?v=\d+', 'editbored.js?v=7', php_content)
with open(php_path, 'w', encoding='utf-8') as f:
    f.write(php_content)
print('OK: Cache buster updated')