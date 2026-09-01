#!/bin/zsh
# zsh may start with a minimal PATH depending on the environment's zshenv,
# so pin the tools this script needs.
export PATH="/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin:$PATH"
# Loads every page through Apache as a logged-in user of each role and fails on
# any PHP error, 500, or blank response. Needs Apache + MySQL running and the
# accounts created by seed_demo.php.
BASE="http://localhost/SimpleMarket"
# Cookie jars live here. Override with SMOKE_JAR_DIR if the default temp
# location is not writable in your environment.
JAR="${SMOKE_JAR_DIR:-$(mktemp -d)}"
mkdir -p "$JAR"
pass=0; fail=0; failed_list=()

hit () {  # hit <role> <page>
  # NOTE: do not name this variable 'path' — in zsh 'path' is the array bound to
  # $PATH, so a local assignment wipes PATH and nothing can be found afterwards.
  local role=$1 page=$2
  local body
  body=$(curl -s -L --max-time 10 -b "$JAR/$role.txt" -c "$JAR/$role.txt" "$BASE/$page")
  local code=$(curl -s -o /dev/null -w "%{http_code}" -L --max-time 10 -b "$JAR/$role.txt" "$BASE/$page")

  if [[ "$code" != "200" ]]; then
    echo "  FAIL  [$role] $page -> HTTP $code"; ((fail++)); failed_list+=("[$role] $page HTTP $code"); return
  fi
  if echo "$body" | grep -qiE "Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught"; then
    echo "  FAIL  [$role] $page -> PHP error in output"
    echo "$body" | grep -iE "Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught" | head -2 | sed 's/^/          /'
    ((fail++)); failed_list+=("[$role] $page PHP error"); return
  fi
  if [[ $(echo -n "$body" | wc -c) -lt 50 ]]; then
    echo "  FAIL  [$role] $page -> near-empty response"; ((fail++)); failed_list+=("[$role] $page empty"); return
  fi
  echo "  PASS  [$role] $page"; ((pass++))
}

hit_json () {  # hit_json <role> <page> -- JSON endpoints are small, so validate
               # the payload instead of applying the HTML length floor
  local role=$1 page=$2
  local body=$(curl -s -L --max-time 10 -b "$JAR/$role.txt" "$BASE/$page")
  if echo "$body" | python3 -c "import json,sys; json.load(sys.stdin)" 2>/dev/null; then
    echo "  PASS  [$role] $page (valid JSON)"; ((pass++))
  else
    echo "  FAIL  [$role] $page -> not valid JSON: $body"
    ((fail++)); failed_list+=("[$role] $page invalid JSON")
  fi
}

login () {  # login <role> <email>
  local role=$1 email=$2
  curl -s -o /dev/null -c "$JAR/$role.txt" "$BASE/login.php"
  local body=$(curl -s -L -b "$JAR/$role.txt" -c "$JAR/$role.txt" \
       -d "email=$email" -d "password=Passw0rd!" "$BASE/login.php")
  if echo "$body" | grep -qi "dashboard\|Welcome"; then
    echo "  PASS  [$role] logged in as $email"; ((pass++))
  else
    echo "  FAIL  [$role] login failed for $email"; ((fail++)); failed_list+=("[$role] login")
  fi
}

echo "=== Public pages ==="
hit anon "login.php"
hit anon "register.php"

echo ""
echo "=== Logins ==="
login admin    "admin@demo.local"
login seller   "seller@demo.local"
login customer "buyer@demo.local"
login rider    "rider@demo.local"

echo ""
echo "=== Admin ==="
for p in dashboard.php profile.php change_password.php search.php seller_approvals.php \
         notifications.php commission_calculator.php sales_overview.php; do
  hit admin "admin/$p"
done

echo ""
echo "=== Seller ==="
for p in dashboard.php profile.php change_password.php search.php products.php \
         low_stock_alert.php notifications.php orders.php price_bidding.php \
         payment_methods.php chat.php; do
  hit seller "seller/$p"
done

echo ""
echo "=== Customer ==="
for p in dashboard.php profile.php change_password.php search.php orders.php \
         order_tracking.php notifications.php make_offer.php seller_rating.php \
         product_feedback.php chat.php; do
  hit customer "customer/$p"
done

echo ""
echo "=== Rider ==="
for p in dashboard.php profile.php change_password.php search.php deliveries.php \
         notifications.php chatbox.php earnings_calculator.php; do
  hit rider "rider/$p"
done

echo ""
echo "=== AJAX endpoints ==="
hit_json customer "ajax/poll_notifications.php"

echo ""
echo "=== Access control (each should NOT return 200 content) ==="
for combo in "customer admin/dashboard.php" "rider seller/orders.php" "seller admin/sales_overview.php"; do
  role=${combo%% *}; page=${combo#* }
  # Assert on what was actually served, not the landing URL: login.php forwards an
  # already-logged-in user to their own dashboard, so the final URL is legitimately
  # not login.php.
  final=$(curl -s -L -o /dev/null -w "%{url_effective}" -b "$JAR/$role.txt" "$BASE/$page")
  if [[ "$final" != *"$page"* ]]; then
    echo "  PASS  [$role] blocked from $page (redirected to ${final##*/SimpleMarket/})"; pass=$((pass+1))
  else
    echo "  FAIL  [$role] reached $page (ended at $final)"; fail=$((fail+1)); failed_list+=("[$role] reached $page")
  fi
done

echo ""
echo "=== Summary ==="
echo "  $pass passed, $fail failed"
if (( fail > 0 )); then
  echo ""
  echo "Failures:"
  for f in $failed_list; do echo "  - $f"; done
fi
[[ -z "$SMOKE_JAR_DIR" ]] && rm -rf "$JAR"
exit $(( fail > 0 ? 1 : 0 ))
