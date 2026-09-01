import re, sys, glob

def split_args(s):
    """Split a PHP arg list on top-level commas."""
    args, depth, cur, i = [], 0, '', 0
    quote = None
    while i < len(s):
        c = s[i]
        if quote:
            if c == '\\': cur += s[i:i+2]; i += 2; continue
            if c == quote: quote = None
            cur += c
        elif c in '"\'':
            quote = c; cur += c
        elif c in '([': depth += 1; cur += c
        elif c in ')]': depth -= 1; cur += c
        elif c == ',' and depth == 0:
            args.append(cur.strip()); cur = ''
        else:
            cur += c
        i += 1
    if cur.strip(): args.append(cur.strip())
    return args

def match_paren(text, start):
    """start = index of '('. Return index of matching ')'."""
    depth, i, quote = 0, start, None
    while i < len(text):
        c = text[i]
        if quote:
            if c == '\\': i += 2; continue
            if c == quote: quote = None
        elif c in '"\'': quote = c
        elif c == '(': depth += 1
        elif c == ')':
            depth -= 1
            if depth == 0: return i
        i += 1
    return -1

problems, checked = [], 0
for path in sorted(glob.glob('**/*.php', recursive=True)):
    text = open(path).read()
    # Record each prepare's placeholder count by position
    prepares = []
    for m in re.finditer(r'mysqli_prepare\s*\(', text):
        end = match_paren(text, m.end()-1)
        if end < 0: continue
        inner = text[m.end():end]
        args = split_args(inner)
        if len(args) < 2: continue
        sql = args[1]
        # only count ? inside string literals; bail if SQL is built from variables
        dynamic = bool(re.search(r'\$\w+', sql))
        prepares.append((m.start(), sql.count('?'), dynamic))

    for m in re.finditer(r'mysqli_stmt_bind_param\s*\(', text):
        end = match_paren(text, m.end()-1)
        if end < 0: continue
        args = split_args(text[m.end():end])
        if len(args) < 2: continue
        tm = re.match(r"^'([a-z]+)'$", args[1])
        if not tm:
            continue
        types = tm.group(1)
        bound = args[2:]
        spread = any(a.startswith('...') for a in bound)
        prev = [p for p in prepares if p[0] < m.start()]
        line = text[:m.start()].count('\n') + 1
        if not prev: continue
        _, qcount, dynamic = prev[-1]
        checked += 1
        if not spread and len(types) != len(bound):
            problems.append(f"{path}:{line}  type string '{types}' has {len(types)} chars but {len(bound)} values bound")
        elif not spread and not dynamic and qcount != len(bound):
            problems.append(f"{path}:{line}  SQL has {qcount} placeholder(s) but {len(bound)} value(s) bound")

print(f"checked {checked} bind_param call(s)")
if problems:
    print("\nMISMATCHES:")
    for p in problems: print("  " + p)
else:
    print("no arity mismatches found")
