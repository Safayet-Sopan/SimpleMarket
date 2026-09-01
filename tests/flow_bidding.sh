#!/bin/zsh
export PATH="/usr/bin:/bin:/usr/sbin:/sbin:$PATH"
B=http://localhost/SimpleMarket
J="${1:-$(mktemp -d)}"
mkdir -p "$J"
q() { /Applications/XAMPP/xamppfiles/bin/mysql -u root simplemarket_db -sN -e "$1"; }

PID=$(q "SELECT product_id FROM products ORDER BY product_id DESC LIMIT 1")
q "DELETE FROM offers WHERE product_id=$PID" >/dev/null
echo "product=$PID listed=$(q "SELECT price FROM products WHERE product_id=$PID")"

echo ""
echo "--- customer bids 400 (below the 500 list price) ---"
curl -s -o /dev/null -b $J/customer.txt -d "product_id=$PID" -d "action=bid" -d "offered_price=400" "$B/customer/make_offer.php"
OFID=$(q "SELECT offer_id FROM offers ORDER BY offer_id DESC LIMIT 1")
q "SELECT CONCAT('  offer #',offer_id,' offered=',offered_price,' status=',status) FROM offers WHERE offer_id=$OFID"

echo ""
echo "--- bid at or above list price should be rejected ---"
R=$(curl -s -b $J/customer.txt -d "product_id=$PID" -d "action=bid" -d "offered_price=600" "$B/customer/make_offer.php")
echo "$R" | grep -oE "Offer must be below the listed price[^<]*" | head -1 | sed 's/^/  /'

echo ""
echo "--- duplicate open bid refused ---"
R=$(curl -s -b $J/customer.txt -d "product_id=$PID" -d "action=bid" -d "offered_price=350" "$B/customer/make_offer.php")
echo "$R" | grep -oE "already have an open bid[^<]*" | head -1 | sed 's/^/  /'
echo "  offers on product: $(q "SELECT COUNT(*) FROM offers WHERE product_id=$PID")"

echo ""
echo "--- seller counters at 450 ---"
curl -s -o /dev/null -b $J/seller.txt -d "offer_id=$OFID" -d "action=counter" -d "counter_price=450" "$B/seller/price_bidding.php"
q "SELECT CONCAT('  status=',status,' counter=',COALESCE(counter_price,'NULL')) FROM offers WHERE offer_id=$OFID"

echo ""
echo "--- seller counter above list price refused ---"
q "UPDATE offers SET status='countered' WHERE offer_id=$OFID" >/dev/null
R=$(curl -s -b $J/seller.txt -d "offer_id=$OFID" -d "action=counter" -d "counter_price=9999" "$B/seller/price_bidding.php")
echo "$R" | grep -oE "Counter price cannot exceed[^<]*" | head -1 | sed 's/^/  /'

echo ""
echo "--- customer accepts the counter ---"
curl -s -o /dev/null -b $J/customer.txt -d "offer_id=$OFID" -d "action=accept_counter" "$B/customer/make_offer.php"
q "SELECT CONCAT('  status=',status) FROM offers WHERE offer_id=$OFID"

echo ""
echo "--- customer checks out using the accepted bid ---"
SB=$(q "SELECT stock_quantity FROM products WHERE product_id=$PID")
curl -s -o /dev/null -L -b $J/customer.txt -d "product_id=$PID" -d "offer_id=$OFID" -d "quantity=1" \
  -d "delivery_address=Road 3" -d "payment_method=cod" "$B/customer/fast_delivery.php"
NID=$(q "SELECT order_id FROM orders ORDER BY order_id DESC LIMIT 1")
q "SELECT CONCAT('  order #',order_id,' subtotal=',subtotal,' comm=',commission_amount,' (list price would be 500.00)') FROM orders WHERE order_id=$NID"
q "SELECT CONCAT('  unit_price on the line item=',unit_price) FROM order_items WHERE order_id=$NID"
q "SELECT CONCAT('  offer converted_order_id=',COALESCE(converted_order_id,'NULL')) FROM offers WHERE offer_id=$OFID"

echo ""
echo "--- replaying the same bid must NOT give a second discount ---"
curl -s -o /dev/null -L -b $J/customer.txt -d "product_id=$PID" -d "offer_id=$OFID" -d "quantity=1" \
  -d "delivery_address=Road 3" -d "payment_method=cod" "$B/customer/fast_delivery.php"
N2=$(q "SELECT order_id FROM orders ORDER BY order_id DESC LIMIT 1")
q "SELECT CONCAT('  newest order #',order_id,' subtotal=',subtotal,'  <- should be 500.00, full price') FROM orders WHERE order_id=$N2"
echo "  offers still pointing at the first order: $(q "SELECT converted_order_id FROM offers WHERE offer_id=$OFID")"
