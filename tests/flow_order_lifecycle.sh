#!/bin/zsh
export PATH="/usr/bin:/bin:/usr/sbin:/sbin:$PATH"
B=http://localhost/SimpleMarket
J="${1:-$(mktemp -d)}"
mkdir -p "$J"
q() { /Applications/XAMPP/xamppfiles/bin/mysql -u root simplemarket_db -sN -e "$1"; }

for r in seller customer rider; do rm -f $J/$r.txt; done
curl -s -o /dev/null -c $J/customer.txt -b $J/customer.txt -d "email=buyer@demo.local"  -d "password=Passw0rd!" $B/login.php
curl -s -o /dev/null -c $J/seller.txt   -b $J/seller.txt   -d "email=seller@demo.local" -d "password=Passw0rd!" $B/login.php
curl -s -o /dev/null -c $J/rider.txt    -b $J/rider.txt    -d "email=rider@demo.local"  -d "password=Passw0rd!" $B/login.php

PID=$(q "SELECT product_id FROM products ORDER BY product_id DESC LIMIT 1")
SB=$(q "SELECT stock_quantity FROM products WHERE product_id=$PID")
echo "product=$PID stock_before=$SB"

echo ""
echo "--- 1. checkout POST (customer/fast_delivery.php) ---"
OUT=$(curl -s -L -b $J/customer.txt -c $J/customer.txt \
  -d "product_id=$PID" -d "quantity=2" -d "delivery_address=Road 7, Dhanmondi" \
  -d "payment_method=bkash" -d "fast_delivery=1" "$B/customer/fast_delivery.php")
echo "$OUT" | grep -qiE "fatal error|warning:|notice:" && echo "  PHP ERROR" || echo "  no PHP errors"
echo "$OUT" | grep -oE "Order Tracking|Order #[0-9]+ placed" | head -1 | sed 's/^/  landed on: /'
OID=$(q "SELECT order_id FROM orders ORDER BY order_id DESC LIMIT 1")
q "SELECT CONCAT('  order #',order_id,' status=',status,' pay=',payment_method,' fast=',fast_delivery,' subtotal=',subtotal,' fee=',delivery_fee,' comm=',commission_amount,' total=',total_amount) FROM orders WHERE order_id=$OID"
echo "  stock now $(q "SELECT stock_quantity FROM products WHERE product_id=$PID") (was $SB)"

echo ""
echo "--- 2. seller confirms then prepares (seller/orders.php) ---"
curl -s -o /dev/null -b $J/seller.txt -d "order_id=$OID" -d "action=confirmed" "$B/seller/orders.php"
echo "  after confirm: $(q "SELECT status FROM orders WHERE order_id=$OID")"
curl -s -o /dev/null -b $J/seller.txt -d "order_id=$OID" -d "action=preparing" "$B/seller/orders.php"
echo "  after prepare: $(q "SELECT status FROM orders WHERE order_id=$OID")"
echo "  illegal jump to delivered:"
R=$(curl -s -b $J/seller.txt -d "order_id=$OID" -d "action=delivered" "$B/seller/orders.php")
echo "$R" | grep -oE "You cannot move an order from [^<]*" | head -1 | sed 's/^/    refused: /'
echo "    status still: $(q "SELECT status FROM orders WHERE order_id=$OID")"

echo ""
echo "--- 3. rider claims and delivers (rider/deliveries.php) ---"
curl -s -o /dev/null -b $J/rider.txt -d "order_id=$OID" -d "action=claim" "$B/rider/deliveries.php"
echo "  rider_id on order: $(q "SELECT COALESCE(rider_id,'NULL') FROM orders WHERE order_id=$OID")"
echo "  rider availability: $(q "SELECT availability_status FROM rider_profiles WHERE rider_id=(SELECT rider_id FROM orders WHERE order_id=$OID)")"
curl -s -o /dev/null -b $J/rider.txt -d "order_id=$OID" -d "action=out_for_delivery" "$B/rider/deliveries.php"
echo "  after start: $(q "SELECT status FROM orders WHERE order_id=$OID")"
curl -s -o /dev/null -b $J/rider.txt -d "order_id=$OID" -d "action=delivered" "$B/rider/deliveries.php"
echo "  after deliver: $(q "SELECT status FROM orders WHERE order_id=$OID")"
echo "  earnings rows: $(q "SELECT COUNT(*) FROM earnings WHERE order_id=$OID") amount=$(q "SELECT COALESCE(SUM(amount),0) FROM earnings WHERE order_id=$OID")"
echo "  rider freed: $(q "SELECT availability_status FROM rider_profiles WHERE rider_id=(SELECT rider_id FROM orders WHERE order_id=$OID)")"
curl -s -o /dev/null -b $J/rider.txt -d "order_id=$OID" -d "action=delivered" "$B/rider/deliveries.php"
echo "  after replay of deliver, earnings rows: $(q "SELECT COUNT(*) FROM earnings WHERE order_id=$OID")"

echo ""
echo "--- 4. chat over AJAX ---"
curl -s -b $J/customer.txt -d "order_id=$OID" -d "message_text=Is it on the way?" "$B/ajax/send_message.php" | sed 's/^/  send(customer): /'
curl -s -b $J/rider.txt    -d "order_id=$OID" -d "message_text=Just delivered it." "$B/ajax/send_message.php" | sed 's/^/  send(rider):    /'
curl -s -b $J/customer.txt "$B/ajax/poll_messages.php?order_id=$OID&after_id=0" | head -c 300 | sed 's/^/  poll: /'
echo ""
echo "  unrelated seller of another shop blocked?"
curl -s -b $J/seller.txt "$B/ajax/poll_messages.php?order_id=999999" | sed 's/^/    /'

echo ""
echo "--- 5. feedback gated on delivered ---"
curl -s -o /dev/null -b $J/customer.txt -d "order_id=$OID" -d "product_id=$PID" -d "rating=5" -d "comment=Excellent" "$B/customer/product_feedback.php"
echo "  reviews: $(q "SELECT COUNT(*) FROM reviews WHERE order_id=$OID")"
curl -s -o /dev/null -b $J/customer.txt -d "order_id=$OID" -d "rating=4" -d "comment=Good shop" "$B/customer/seller_rating.php"
echo "  seller_ratings: $(q "SELECT COUNT(*) FROM seller_ratings WHERE order_id=$OID")"
echo "  duplicate review refused:"
R=$(curl -s -b $J/customer.txt -d "order_id=$OID" -d "product_id=$PID" -d "rating=1" -d "comment=again" "$B/customer/product_feedback.php")
echo "$R" | grep -oE "already reviewed[^<]*" | head -1 | sed 's/^/    /'
echo "    reviews still: $(q "SELECT COUNT(*) FROM reviews WHERE order_id=$OID")"
