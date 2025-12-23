<?php
require_once '../config/constants.php';
require_once '../includes/StripePayment.php';
require_once '../includes/PayPalPayment.php';
require_once '../includes/InstaPayPayment.php';
require_once '../includes/CashPayment.php';

header('Content-Type: application/json');

try {
    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid input data');
    }
    
    // Validate required fields
    $required = ['gateway', 'amount', 'currency', 'customer_email'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Validate amount
    if (!is_numeric($input['amount']) || $input['amount'] <= 0) {
        throw new Exception('Invalid amount');
    }
    
    // Initialize payment gateway
    $gateway = null;
    $response = null;
    
    switch (strtolower($input['gateway'])) {
        case 'stripe':
            $gateway = new StripePayment([
                'secret_key' => STRIPE_SECRET_KEY,
                'public_key' => STRIPE_PUBLIC_KEY
            ]);
            $response = $gateway->createPayment($input);
            break;
            
        case 'paypal':
            $gateway = new PayPalPayment([
                'client_id' => PAYPAL_CLIENT_ID,
                'secret' => PAYPAL_SECRET,
                'environment' => PAYPAL_ENVIRONMENT
            ]);
            $response = $gateway->createPayment($input);
            break;
            
        case 'instapay':
            $gateway = new InstaPayPayment([
                'api_key' => INSTAPAY_API_KEY,
                'secret_key' => INSTAPAY_SECRET_KEY,
                'merchant_id' => INSTAPAY_MERCHANT_ID,
                'environment' => INSTAPAY_ENVIRONMENT
            ]);
            $response = $gateway->createPayment($input);
            break;
            
        case 'cash':
            $gateway = new CashPayment();
            $response = $gateway->createPayment($input);
            break;
            
        default:
            throw new Exception('Unsupported payment gateway');
    }
    
    // Log API request
    $logger = new PaymentLogger();
    $logger->logApi('/api/create-payment.php', $input, $response, $response['success'] ? 200 : 400);
    
    if ($response['success']) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $response
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $response['error'] ?? 'Payment creation failed'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    
    // Log error
    error_log("Create Payment Error: " . $e->getMessage());
}
?>