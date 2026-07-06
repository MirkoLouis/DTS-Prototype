import os
import re

views_dir = '/home/mirkolouis/Documents/DTS Prototype/working-php/src/Views'

# 1. Spans replacing in table files
pattern_span = re.compile(r'<span class="[^"]*rounded-full[^"]*">\s*<\?= htmlspecialchars\([^>]+(?:status)[^>]+\)\s*\?>\s*</span>')
replacement_span = r"<?php $status = $doc['status']; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>"

# 2. TD replacing in integrity-monitor
pattern_td = re.compile(r'<td class="px-6 py-4">\s*<\?= htmlspecialchars\(\$doc\[\'status\'\]\)\s*\?>\s*</td>')
replacement_td = r"<td class=\"px-6 py-4\">\n                                    <?php $status = $doc['status']; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>\n                                </td>"

# 3. Show document replacing
pattern_show = re.compile(r'<\?php echo htmlspecialchars\(\$document\[\'status\'\]\);\s*\?>')
replacement_show = r"<?php $status = $document['status']; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>"

# 4. Guest document card replacing
pattern_guest = re.compile(r'<\?= htmlspecialchars\(ucfirst\(str_replace\([^\)]+\)\)\)\s*\?>')
replacement_guest = r"<?php require BASE_PATH . '/src/Views/components/status-badge.php'; ?>"

for root, dirs, files in os.walk(views_dir):
    for file in files:
        if file.endswith('.php') and file != 'status-badge.php':
            filepath = os.path.join(root, file)
            with open(filepath, 'r') as f:
                content = f.read()
            
            orig = content
            content = pattern_span.sub(replacement_span, content)
            content = pattern_td.sub(replacement_td, content)
            
            if file == 'show-document.php':
                content = pattern_show.sub(replacement_show, content)
            elif file == 'document-card.php':
                content = pattern_guest.sub(replacement_guest, content)
                
            if content != orig:
                with open(filepath, 'w') as f:
                    f.write(content)
                print(f"Updated {filepath}")
