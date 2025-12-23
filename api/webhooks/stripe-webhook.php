<?php
require_once '../../config/constants.php';
require_once '../../includes/StripePayment.php';

// Stripe webhook secret
$endpoint_secret = STRIPE_WEBHOOK_SECRET;

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$event = null;

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sig_header, $endpoint_secret
    );
} catch(\UnexpectedValueException $e) {
    // Invalid payload
    http_response_code(400);
    exit();
} catch(\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    http_response_code(400);
    exit();
}

// Log webhook
DBHelper::insert('webhook_logs', [
    'gateway' => 'stripe',
    'event_type' => $event->type,
    'payload' => $payload,
    'headers' => json_encode(getallheaders()),
    'ip_address' => $_SERVER['REMOTE_ADDR']
]);

// Handle the event
switch ($event->type) {
    case 'payment_intent.succeeded':
        $paymentIntent = $event->data->object;
        
        // Update transaction status
        DBHelper::update('transactions', 
            [
                'status' => 'completed',
                'gateway_response' => json_encode($paymentIntent),
                'processed_at' => date('Y-m-d H:i:s')
            ],
            ['gateway_transaction_id' => $paymentIntent->id]
        );
        
        // Send confirmation email (implement email function)
        // sendPaymentConfirmationEmail($paymentIntent);
        
        break;
        
    case 'payment_intent.payment_failed':
        $paymentIntent = $event->data->object;
        
        DBHelper::update('transactions', 
            [
                'status' => 'failed',
                'gateway_response' => json_encode($paymentIntent)
            ],
            ['gateway_transaction_id' => $paymentIntent->id]
        );
        
        break;
        
    case 'charge.refunded':
        $charge = $event->data->object;
        
        DBHelper::update('transactions', 
            ['status' => 'refunded'],
            ['gateway_transaction_id' => $charge->id]
        );
        
        break;
        
    default:
        // Unexpected event type
        error_log('Received unknown event type: ' . $event->type);
}

http_response_code(200);
echo json_encode(['received' => true]);
?>