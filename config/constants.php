<?php
// Payment Gateway Configuration

// Site Configuration
define('SITE_NAME', 'Payment Gateway Demo');
define('SITE_URL', 'https://yourdomain.com');
define('SITE_EMAIL', 'support@yourdomain.com');

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'payment_gateway');
define('DB_USER', 'root');
define('DB_PASS', '');

// Stripe Configuration
define('STRIPE_PUBLIC_KEY', 'pk_test_your_public_key_here');
define('STRIPE_SECRET_KEY', 'sk_test_your_secret_key_here');
define('STRIPE_WEBHOOK_SECRET', 'whsec_your_webhook_secret_here');

// PayPal Configuration
define('PAYPAL_CLIENT_ID', 'your_paypal_client_id');
define('PAYPAL_SECRET', 'your_paypal_secret');
define('PAYPAL_ENVIRONMENT', 'sandbox'); // 'sandbox' or 'live'
define('PAYPAL_WEBHOOK_ID', 'your_webhook_id');

// InstaPay Configuration (Philippines)
define('INSTAPAY_API_KEY', 'your_instapay_api_key');
define('INSTAPAY_SECRET_KEY', 'your_instapay_secret');
define('INSTAPAY_MERCHANT_ID', 'your_merchant_id');
define('INSTAPAY_ENVIRONMENT', 'sandbox'); // 'sandbox' or 'production'

// Security
define('ENCRYPTION_KEY', 'your_32_character_encryption_key_here');
define('JWT_SECRET', 'your_jwt_secret_key_here');
define('JWT_ALGORITHM', 'HS256');

// Paths
define('BASE_PATH', dirname(__DIR__));
define('LOG_PATH', BASE_PATH . '/logs/');
define('UPLOAD_PATH', BASE_PATH . '/uploads/');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 1 for development

// Timezone
date_default_timezone_set('Asia/Manila');

// CORS Headers
header("Access-Control-Allow-Origin: " . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
?>