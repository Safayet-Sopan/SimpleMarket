#!/bin/zsh
# Sessions and the "remember me" cookie, driven over real HTTP.
# Needs Apache + MySQL and the accounts from seed_demo.php.
#   ./tests/flow_session.sh
export PATH="/usr/bin:/bin:/usr/sbin:/sbin:$PATH"
setopt nonomatch   # an empty jar dir must not abort the glob
B=http://localhost/SimpleMarket
J="${1:-$(mktemp -d)}"
mkdir -p "$J"
# NOTE: never name a shell variable 'path' here — in zsh it is bound to $PATH.
q() { /Applications/XAMPP/xamppfiles/bin/mysql -u root simplemarket_db -sN -e "$1"; }

pass=0; fail=0; failed_list=()
# NOTE: use pass=$((pass+1)), not ((pass++)). In zsh the latter returns a
# non-zero exit status when the result is 0, which makes `ok ... || no ...`
# report both a pass and a fail.
ok()  { echo "  PASS  $1"; pass=$((pass+1)) }
no()  { echo "  FAIL  $1"; fail=$((fail+1)); failed_list+=("$1") }

EMAIL=buyer@demo.local
UID_=$(q "SELECT user_id FROM users WHERE email='$EMAIL'")
q "DELETE FROM remember_tokens WHERE user_id=$UID_" >/dev/null
rm -f $J/s*.txt 2>/dev/null

echo "=== 1. Login WITHOUT remember me ==="
curl -s -o /dev/null -c $J/s1.txt -b $J/s1.txt -d "email=$EMAIL" -d "password=Passw0rd!" "$B/login.php"
SESS=$(grep -c PHPSESSID $J/s1.txt)
REM=$(grep -c simplemarket_remember $J/s1.txt)
[[ "$SESS" -ge 1 ]] && ok "session cookie issued" || no "session cookie issued"
[[ "$REM" -eq 0 ]] && ok "no remember cookie issued" || no "no remember cookie issued (found $REM)"
TOKENS=$(q "SELECT COUNT(*) FROM remember_tokens WHERE user_id=$UID_")
[[ "$TOKENS" -eq 0 ]] && ok "no token row written" || no "no token row written (found $TOKENS)"
BODY=$(curl -s -b $J/s1.txt "$B/customer/dashboard.php")
echo "$BODY" | grep -q "Welcome" && ok "session grants access to the dashboard" || no "session grants access"

echo ""
echo "=== 2. Login WITH remember me ==="
rm -f $J/s2.txt 2>/dev/null
curl -s -o /dev/null -c $J/s2.txt -b $J/s2.txt -d "email=$EMAIL" -d "password=Passw0rd!" -d "remember=1" "$B/login.php"
grep -q simplemarket_remember $J/s2.txt && ok "remember cookie issued" || no "remember cookie issued"
TOKENS=$(q "SELECT COUNT(*) FROM remember_tokens WHERE user_id=$UID_")
[[ "$TOKENS" -eq 1 ]] && ok "exactly one token row written" || no "one token row written (found $TOKENS)"

RAW=$(grep simplemarket_remember $J/s2.txt | awk '{print $7}')
STORED=$(q "SELECT token_hash FROM remember_tokens WHERE user_id=$UID_ LIMIT 1")
EXPECT=$(printf '%s' "$RAW" | shasum -a 256 | awk '{print $1}')
[[ "$STORED" == "$EXPECT" ]] && ok "database stores the SHA-256 hash, not the raw token" || no "stored hash matches sha256(cookie)"
[[ "$STORED" != "$RAW" ]] && ok "raw token is NOT in the database" || no "raw token is not in the database"
grep -q "^#HttpOnly_.*simplemarket_remember" $J/s2.txt \
  && ok "remember cookie is HttpOnly" || no "remember cookie is HttpOnly"

echo ""
echo "=== 3. Session lost, remember cookie survives ==="
# keep ONLY the remember cookie, as if the browser was closed and reopened
grep simplemarket_remember $J/s2.txt > $J/s3.txt
grep -qc PHPSESSID $J/s3.txt && no "session cookie should be gone" || ok "session cookie discarded"
BODY=$(curl -s -L -c $J/s3.txt -b $J/s3.txt "$B/customer/dashboard.php")
echo "$BODY" | grep -q "Welcome" && ok "remember cookie rebuilds the session" || no "remember cookie rebuilds the session"
echo "$BODY" | grep -qiE "fatal error|warning:|notice:" && no "no PHP errors on restore" || ok "no PHP errors on restore"

echo ""
echo "=== 4. Forged / unknown remember cookie is rejected ==="
printf 'localhost\tFALSE\t/\tFALSE\t0\tsimplemarket_remember\t%s\n' \
  "deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef" > $J/s4.txt
FINAL=$(curl -s -L -o /dev/null -w "%{url_effective}" -b $J/s4.txt "$B/customer/dashboard.php")
[[ "$FINAL" == *"login.php"* ]] && ok "forged token cannot log in" || no "forged token cannot log in (landed $FINAL)"

echo ""
echo "=== 5. Logout clears both cookie and token ==="
curl -s -o /dev/null -L -c $J/s2.txt -b $J/s2.txt "$B/logout.php"
TOKENS=$(q "SELECT COUNT(*) FROM remember_tokens WHERE user_id=$UID_")
[[ "$TOKENS" -eq 0 ]] && ok "token row deleted on logout" || no "token row deleted (found $TOKENS)"
FINAL=$(curl -s -L -o /dev/null -w "%{url_effective}" -b $J/s2.txt "$B/customer/dashboard.php")
[[ "$FINAL" == *"login.php"* ]] && ok "cannot reach dashboard after logout" || no "blocked after logout (landed $FINAL)"
# The revoked token must not work on its own. s3.txt picked up a live session
# cookie during step 3, so strip that out and present ONLY the remember cookie.
grep simplemarket_remember $J/s3.txt > $J/s3_tokenonly.txt
FINAL=$(curl -s -L -o /dev/null -w "%{url_effective}" -b $J/s3_tokenonly.txt "$B/customer/dashboard.php")
[[ "$FINAL" == *"login.php"* ]] && ok "revoked remember cookie no longer works" || no "revoked cookie rejected (landed $FINAL)"

echo ""
echo "=== 6. Suspended account cannot ride an old cookie back in ==="
rm -f $J/s5.txt 2>/dev/null
curl -s -o /dev/null -c $J/s5.txt -b $J/s5.txt -d "email=$EMAIL" -d "password=Passw0rd!" -d "remember=1" "$B/login.php"
grep simplemarket_remember $J/s5.txt > $J/s6.txt
q "UPDATE users SET status='suspended' WHERE user_id=$UID_" >/dev/null
FINAL=$(curl -s -L -o /dev/null -w "%{url_effective}" -b $J/s6.txt "$B/customer/dashboard.php")
[[ "$FINAL" == *"login.php"* ]] && ok "suspended user is refused despite a valid token" || no "suspended user refused (landed $FINAL)"
q "UPDATE users SET status='active' WHERE user_id=$UID_" >/dev/null
q "DELETE FROM remember_tokens WHERE user_id=$UID_" >/dev/null

echo ""
echo "=== 7. Session id changes on login (fixation) ==="
rm -f $J/s7.txt 2>/dev/null
curl -s -o /dev/null -c $J/s7.txt "$B/login.php"
BEFORE=$(grep PHPSESSID $J/s7.txt | awk '{print $7}')
curl -s -o /dev/null -c $J/s7.txt -b $J/s7.txt -d "email=$EMAIL" -d "password=Passw0rd!" "$B/login.php"
AFTER=$(grep PHPSESSID $J/s7.txt | awk '{print $7}')
if [[ -n "$BEFORE" && -n "$AFTER" && "$BEFORE" != "$AFTER" ]]; then
  ok "session id regenerated on login"
else
  no "session id regenerated on login (before=$BEFORE after=$AFTER)"
fi

echo ""
echo "=== Summary ==="
echo "  $pass passed, $fail failed"
if (( fail > 0 )); then
  echo ""; echo "Failures:"; for f in $failed_list; do echo "  - $f"; done
fi
[[ -z "$1" ]] && rm -rf "$J"
exit $(( fail > 0 ? 1 : 0 ))
