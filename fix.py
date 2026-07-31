import re  
filepath = r'c:\xampp\htdocs\forum-nuovo\plugins\editbored\assets\js\editbored.js'  
with open(filepath, 'r', encoding='utf-8') as f:  
    content = f.read()  
content = content.replace('.thread-content, .post-content', '.thread-content, .post-content, .markdown-content')  
with open(filepath, 'w', encoding='utf-8') as f:  
    f.write(content)  
print('OK: Updated selector')  
