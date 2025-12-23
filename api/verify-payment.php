<?php
require_once '../config/constants.php';
require_once '../includes/StripePayment.php';
require_once '../includes/PayPalPayment.php';
require_once '../includes/InstaPayPayment.php';
require_once '../includes/CashPayment.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['gateway']) || empty($input['payment_id'])) {
        throw new Exception('Invalid input data');
    }
    
    $gateway = null;
    $response = null;
    
    switch (strtolower($input['gateway'])) {
        case 'stripe':
            $gateway = new StripePayment([
                'secret_key' => STRIPE_SECRET_KEY
            ]);
            $response = $gateway->verifyPayment($input['payment_id']);
            break;
            
        case 'paypal':
            $gateway = new PayPalPayment([
                'client_id' => PAYPAL_CLIENT_ID,
                'secret' => PAYPAL_SECRET,
                'environment' => PAYPAL_ENVIRONMENT
            ]);
            $response = $gateway->verifyPayment($input['payment_id']);
            break;
            
        case 'instapay':
            $gateway = new InstaPayPayment([
                'api_key' => INSTAPAY_API_KEY,
                'secret_key' => INSTAPAY_SECRET_KEY,
                'merchant_id' => INSTAPAY_MERCHANT_ID
            ]);
            $response = $gateway->verifyPayment($input['payment_id']);
            break;
            
        case 'cash':
            $gateway = new CashPayment();
            $response = $gateway->verifyPayment($input['payment_id']);
            break;
            
        default:
            throw new Exception('Unsupported payment gateway');
    }
    
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
            'error' => $response['error'] ?? 'Payment verification failed'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>