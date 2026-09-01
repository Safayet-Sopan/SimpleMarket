# SimpleMarket tests

Nothing here is part of the app — these are development checks. Run them from the
**project root**, not from inside `tests/`.

## Static checks (no database needed)

```
php -l <file>                     # every .php file should be clean
python3 tests/bindcheck.py        # mysqli bind_param placeholder / type / arg arity
python3 tests/schemacheck.py      # every alias.column in every query vs setup.php's schema
python3 tests/linkcheck.py        # internal links and require paths resolve
```

`bindcheck.py` exists because the original checkout bug was a 7-character type string for 8
bound values — an `ArgumentCountError` fatal on PHP 8 that nothing catches until the query runs.
Run it after adding queries.

`linkcheck.py` understands that the shared renderers in `includes/` resolve their links from the
role directory that includes them, that scripts calling `chdir()` resolve paths at runtime, and
that a PHP-concatenated `href` is not a literal path. It should report **0 broken** — anything
it prints is real.

## Logic test (needs MySQL)

```
php tests/e2e_test.php
```

Builds a throwaway `simplemarket_test_db`, exercises checkout, the overselling guard, status
transitions, the rider claim race, earnings, bidding and double-redeem, cancellation restoring
stock, chat authorisation and the admin reporting queries — then drops the test database.
**It never touches `simplemarket_db`.**

## HTTP smoke test (needs Apache + MySQL)

```
php tests/seed_demo.php           # demo accounts + sample data in the REAL database
./tests/http_smoke.sh
```

Logs in as each role and loads every page, failing on any 500, PHP warning/notice, or blank
response, then verifies each role is redirected away from the other roles' pages.

## Lifecycle tests (need Apache + MySQL + seeded demo data)

```
./tests/flow_order_lifecycle.sh   # checkout -> confirm -> prepare -> claim -> deliver -> review
./tests/flow_bidding.sh           # bid -> counter -> accept -> order at bid price -> replay refused
./tests/flow_session.sh           # sessions, remember-me cookie, logout, fixation
```

These drive the **real page POST handlers** over HTTP rather than reimplementing the logic, then
assert against the database. They write real rows into `simplemarket_db`.

Two zsh traps these scripts have already hit, in case you write more:

- `((pass++))` returns a **non-zero exit status** when the result is 0, so `ok ... || no ...`
  fires both branches. Use `pass=$((pass+1))`.
- Do not name a shell variable `path`. In zsh, `path` is the array bound to `$PATH`, so
  `local path=...` inside a function wipes PATH and every command afterwards fails with
  "command not found".

Also: `curl` writing cookie jars into `/var/folders` can be blocked in a sandboxed shell. Every
script takes a jar directory as `$1` (or `SMOKE_JAR_DIR` for `http_smoke.sh`).

Demo accounts all use the password `Passw0rd!`:
`admin@demo.local`, `seller@demo.local`, `buyer@demo.local`, `rider@demo.local`.

`seed_demo.php` writes to the real database — it is idempotent and safe to re-run, but it is a
development convenience, not something to run on anything you care about.
