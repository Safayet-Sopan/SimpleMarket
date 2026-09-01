<?php
// Shared configuration. Included by any page that needs these constants.

// Guarded so including this twice cannot trigger a redefinition warning.
if (!defined('SITE_NAME')) {

    define('SITE_NAME', 'SimpleMarket');
    define('BASE_URL', '/SimpleMarket/');

    // Product and profile images land here, relative to the project root
    define('UPLOAD_PATH', 'uploads/');

    // Flat delivery rates. There is no maps/distance API, so these are fixed.
    define('STANDARD_DELIVERY_FEE', 30.00);
    define('FAST_DELIVERY_FEE', 70.00);

    // Share of the delivery fee the rider keeps on a completed delivery.
    // The remainder stays with the platform.
    define('RIDER_EARNING_RATE', 0.80);
}

// Payment methods a seller can offer. No gateway is involved — these are
// instructions the customer follows, and the seller confirms payment manually.
// Set outside the guard above so it is always available to the including page.
$PAYMENT_METHODS = [
    'cod'   => 'Cash on Delivery',
    'bkash' => 'bKash (manual, send to the shop number)',
    'nagad' => 'Nagad (manual, send to the shop number)',
    'bank'  => 'Bank Transfer',
];
