import re, glob, sys

setup = open('setup.php').read()

# --- build the schema from setup.php ---
schema = {}
for m in re.finditer(r"\$tables\['(\w+)'\]\s*=\s*\"(.*?)\";", setup, re.S):
    table, body = m.group(1), m.group(2)
    cols = set()
    inner = body[body.index('(')+1:]
    for line in inner.split('\n'):
        line = line.strip().rstrip(',')
        if not line or line.startswith(')'): continue
        first = line.split()[0] if line.split() else ''
        if first.upper() in ('PRIMARY','FOREIGN','UNIQUE','KEY','INDEX','CONSTRAINT',')','ENGINE=INNODB'):
            continue
        if re.match(r'^\w+$', first):
            cols.add(first.lower())
    schema[table] = cols

# --- apply migrations ---
for m in re.finditer(r'ALTER TABLE (\w+) ADD COLUMN (\w+)', setup):
    schema.setdefault(m.group(1), set()).add(m.group(2).lower())

print("Schema parsed:")
for t in sorted(schema):
    print(f"  {t}: {len(schema[t])} columns")

# --- scan queries ---
problems = []
sql_kw = re.compile(r'\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b', re.I)

for path in sorted(glob.glob('**/*.php', recursive=True)):
    if path == 'setup.php': continue
    text = open(path).read()
    # crude: pull double-quoted strings that look like SQL
    for sm in re.finditer(r'"((?:[^"\\]|\\.)*)"', text, re.S):
        sql = sm.group(1)
        if not sql_kw.search(sql): continue
        line = text[:sm.start()].count('\n') + 1

        # alias map: FROM/JOIN/UPDATE <table> [AS] <alias>
        aliases = {}
        for am in re.finditer(r'\b(?:FROM|JOIN|UPDATE|INTO)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?', sql, re.I):
            tbl, al = am.group(1), am.group(2)
            if tbl.lower() not in schema: continue
            aliases[tbl.lower()] = tbl.lower()
            if al and al.upper() not in ('SET','WHERE','ON','VALUES','SELECT','ORDER','GROUP','LEFT','JOIN','INNER','AS','WHEN'):
                aliases[al.lower()] = tbl.lower()

        for cm in re.finditer(r'\b(\w+)\.(\w+)\b', sql):
            al, col = cm.group(1).lower(), cm.group(2).lower()
            if al not in aliases: continue          # unknown alias -> skip
            tbl = aliases[al]
            if col in ('*',): continue
            if col not in schema[tbl]:
                problems.append(f"{path}:{line}  {al}.{col} -> table '{tbl}' has no column '{col}'")

print(f"\n{len(problems)} problem(s)")
for p in sorted(set(problems)):
    print("  " + p)
