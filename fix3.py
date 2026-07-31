import re  
filepath = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\assets\js\editbored.js'  
with open(filepath, 'r', encoding='utf-8') as f:  
    lines = f.readlines()  
for i, line in enumerate(lines):  
    if 'document.execCommand' in line and 'italic' in line:  
        for j in range(i+1, min(i+5, len(lines))):  
            if lines[j].strip() == '});':  
                insert_after = j  
                break  
        break  
if 'insert_after' in dir():  
    lines.insert(insert_after, '            // Exit code block or blockquote on Enter at end of block\n')  
import re  
filepath = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\assets\js\editbored.js'  
with open(filepath, 'r', encoding='utf-8') as f:  
    lines = f.readlines()  
for i, line in enumerate(lines):  
    if 'document.execCommand' in line and 'italic' in line:  
        for j in range(i+1, min(i+5, len(lines))):  
            if lines[j].strip() == '});':  
                insert_after = j  
                break  
        break  
if 'insert_after' in dir():  
    lines.insert(insert_after, '            // Exit code block or blockquote on Enter at end of block\n')  
    lines.insert(insert_after+1, '            if (e.key === "Enter" ^!e.shiftKey) {\n')  
    lines.insert(insert_after+2, '                var sel = window.getSelection();\n')  
    lines.insert(insert_after+3, '                if (sel.rangeCount  {\n')  
    lines.insert(insert_after+4, '                    var node = sel.anchorNode;\n')  
    lines.insert(insert_after+5, '                    var pre = node.parentElement.closest("pre");\n')  
    lines.insert(insert_after+6, '                    var bq = node.parentElement.closest("blockquote");\n')  
