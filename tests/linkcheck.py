import re, glob, os

problems = []
checked = 0

# Files under includes/ that are require'd from a role directory resolve their
# relative links against THAT directory, not includes/. Check them from there.
SHARED_RENDERERS = {'includes/notifications_page.php', 'includes/chat_page.php'}

for path in sorted(glob.glob('**/*.php', recursive=True)):
    text = open(path).read()
    base = os.path.dirname(path)

    # A script that chdir()s sets its own working directory; relative paths in it
    # are resolved at runtime, not from the file's location.
    if 'chdir(' in text:
        continue

    if path in SHARED_RENDERERS:
        base = 'admin'   # any role dir; they all contain the same page names

    # href/src/action targets that are local files
    for m in re.finditer(r'(?:href|src|action)\s*=\s*"([^"<>]+)"', text):
        target = m.group(1)
        if target.startswith(('http://','https://','#','mailto:','?')): continue
        if '<?php' in target: continue
        if "' ." in target or ". '" in target: continue   # PHP string concatenation, not a path
        line = text[:m.start()].count('\n') + 1
        clean = target.split('?')[0].split('#')[0]
        if not clean: continue
        resolved = os.path.normpath(os.path.join(base, clean))
        checked += 1
        if not os.path.exists(resolved):
            problems.append(f"{path}:{line}  -> {target}  (missing: {resolved})")

    # require/include paths
    for m in re.finditer(r"(?:require|include)(?:_once)?\s*\(?\s*'([^']+)'", text):
        target = m.group(1)
        line = text[:m.start()].count('\n') + 1
        if target.startswith('/'): continue
        resolved = os.path.normpath(os.path.join(base, target))
        checked += 1
        if not os.path.exists(resolved):
            problems.append(f"{path}:{line}  require '{target}'  (missing: {resolved})")

print(f"checked {checked} local reference(s)")
print(f"{len(problems)} broken:")
for p in problems: print("  " + p)
