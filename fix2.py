import re  
filepath = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\assets\js\editbored.js'  
with open(filepath, 'r', encoding='utf-8') as f:  
    lines = f.readlines()  
for i, line in enumerate(lines):  
    if 'document.execCommand(\"italic\", false, null)' in line:  
        for j in range(i+1, min(i+5, len(lines))):  
            if lines[j].strip() == '});':  
                insert_after = j  
                break  
        break  
if 'insert_after' in dir():  
    lines.insert(insert_after, '            // Exit code block or blockquote on Enter at end of block\n')  
    print('OK: Inserted at line', insert_after)  
else:  
    print('SKIP: not found')  
with open(filepath, 'w', encoding='utf-8') as f:  
    f.writelines(lines)  
print('OK: Written')  
