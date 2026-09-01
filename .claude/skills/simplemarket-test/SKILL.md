---
name: simplemarket-test
description: Run and interpret the SimpleMarket test suites — static checks (lint, bind_param arity, SQL columns, links) and runtime suites (logic, HTTP page load, order lifecycle, bidding, sessions). Use after any code change to this project, and before claiming anything works.
---

# Running the SimpleMarket tests

All commands run from the **project root**, not from inside `tests/`.
See `tests/README.md` for the same information in the repo.

## Static checks — no database needed, run these always

```bash
# lint every PHP file
for f in $(find . -name "*.php" -not -path "./.git/*"); do php -l "$f" >/dev/null || echo "FAIL $f"; done

python3 tests/bindcheck.py     # mysqli bind_param placeholder / type / arg arity
python3 tests/schemacheck.py   # every alias.column in every query vs setup.php's schema
python3 tests/linkcheck.py     # internal links and require paths resolve
```

Expected: lint clean, **no arity mismatches**, **0 problem(s)**, **0 broken**. Anything else is
real — these checkers have had their false positives removed.

`bindcheck.py` matters most. It catches the exact bug class that once broke checkout: a type
string whose length does not match the number of bound values, a PHP 8 fatal that nothing
surfaces until that query runs. **Run it after touching any query.**

## Runtime suites — need XAMPP

Start Apache **and** MySQL in the XAMPP control panel first, then check:

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root -e "SELECT 1"
curl -s -o /dev/null -w "%{http_code}\n" http://localhost/SimpleMarket/login.php   # want 200
```

If MySQL is down it cannot be started without the user's sudo password — ask them to start it
rather than trying.

### Logic suite — safe, uses a throwaway database

```bash
/Applications/XAMPP/xamppfiles/bin/php tests/e2e_test.php
```

Builds and drops `simplemarket_test_db`. **Never touches `simplemarket_db`.** Covers checkout,
the overselling guard, status transitions, the rider claim race, earnings, bidding and
double-redeem, cancellation restoring stock, chat authorisation, admin reporting.

### The rest — these write to the REAL database

```bash
/Applications/XAMPP/xamppfiles/bin/php tests/seed_demo.php   # demo accounts + sample data
./tests/http_smoke.sh              # loads every page as all 4 roles
./tests/flow_order_lifecycle.sh    # checkout → confirm → claim → deliver → review
./tests/flow_bidding.sh            # bid → counter → accept → order at bid price → replay refused
./tests/flow_session.sh            # sessions, remember-me cookie, logout, fixation
```

The `flow_*` scripts drive the **real page POST handlers** over HTTP and then assert against the
database. They are what actually proves a feature works; `http_smoke.sh` only does GETs.

Demo accounts all use password `Passw0rd!`: `admin@demo.local`, `seller@demo.local`,
`buyer@demo.local`, `rider@demo.local`.

## After a schema change

Re-run `setup.php` before the suites, or every query touching the new column fails:

```bash
curl -s "http://localhost/SimpleMarket/setup.php" | sed -e 's/<[^>]*>//g' | grep -iE "OK|FAILED"
```

Also update the table-count assertion in `tests/e2e_test.php` if you added a table.

## Interpreting failures

Before changing app code, check whether the **test** is what is wrong. It has happened
repeatedly on this project:

- an assertion counting rows without exercising the code that writes them
- a table-count assertion left behind after a new table was added
- an assertion on a redirect's final URL after the redirect target legitimately changed
- a byte-length floor applied to a compact JSON endpoint

Confirm the actual behaviour (query the database, curl the page and read the body) before
concluding the app is broken.

## zsh traps in the shell suites

If you write or edit a `.sh` test, two things will silently ruin it:

- **`((pass++))` returns a non-zero exit status when the result is 0**, so `ok ... || no ...`
  fires *both* branches. Use `pass=$((pass+1))`.
- **Never name a variable `path`.** In zsh `path` is the array bound to `$PATH`, so
  `local path=...` inside a function wipes PATH and every later command fails with
  "command not found".

Also: `curl` writing cookie jars into `/var/folders` can be blocked in a sandboxed shell. All
the scripts accept a jar directory as `$1` (or `SMOKE_JAR_DIR` for `http_smoke.sh`) — point it
somewhere writable.
