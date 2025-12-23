<?php
require_once __DIR__ . '/PaymentGateway.php';
require_once __DIR__ . '/../vendor/autoload.php'; // Composer autoloader


class StripePayment extends PaymentGateway {
    private $stripe;
    
    public function __construct($config = []) {
        parent::__construct($config);
        
        \Stripe\Stripe::setApiKey($config['secret_key'] ?? STRIPE_SECRET_KEY);
        \Stripe\Stripe::setApiVersion('2023-10-16');
        $this->stripe = new \Stripe\StripeClient($config['secret_key'] ?? STRIPE_SECRET_KEY);
    }
    
    public function createPayment($params) {
        try {
            $amount = $this->validateAmount($params['amount']);
            $currency = strtolower($params['currency'] ?? 'usd');
            
            // Create PaymentIntent
            $paymentIntent = $this->stripe->paymentIntents->create([
                'amount' => $amount * 100, // Convert to cents
                'currency' => $currency,
                'payment_method_types' => ['card'],
                'description' => $params['description'] ?? 'Payment',
                'metadata' => [
                    'customer_email' => $params['customer_email'],
                    'order_id' => $params['order_id'] ?? uniqid()
                ]
            ]);
            
            // Save to database
            $transactionId = new DBHelper();
            $transactionId = $transactionId->insert('transactions', [
                'transaction_uuid' => uniqid('stripe_'),
                'user_id' => $params['user_id'] ?? null,
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'payment_gateway' => 'stripe',
                'status' => 'pending',
                'gateway_transaction_id' => $paymentIntent->id,
                'customer_email' => $params['customer_email'],
                'customer_name' => $params['customer_name'] ?? '',
                'description' => $params['description'] ?? '',
                'items' => json_encode($params['items'] ?? []),
                'total_amount' => $amount,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'metadata' => json_encode(['payment_intent' => $paymentIntent->id])
            ]);
            
            $this->logTransaction([
                'action' => 'create_payment',
                'payment_intent_id' => $paymentIntent->id,
                'amount' => $amount,
                'transaction_id' => $transactionId
            ]);
            
            return [
                'success' => true,
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'currency' => $currency
            ];
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logTransaction([
                'action' => 'create_payment_error',
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function processPayment($paymentIntentId, $params = []) {
        try {
            $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);
            
            // Update transaction status
            if ($paymentIntent->status === 'succeeded') {
                DBHelper::update('transactions', 
                    [
                        'status' => 'completed',
                        'gateway_response' => json_encode($paymentIntent),
                        'processed_at' => date('Y-m-d H:i:s')
                    ],
                    ['gateway_transaction_id' => $paymentIntentId]
                );
                
                // Save payment method if available
                if ($paymentIntent->payment_method) {
                    $this->savePaymentMethod($paymentIntent->customer, $paymentIntent->payment_method);
                }
                
                $this->logTransaction([
                    'action' => 'payment_success',
                    'payment_intent_id' => $paymentIntentId,
                    'amount' => $paymentIntent->amount / 100
                ]);
                
                return [
                    'success' => true,
                    'status' => 'completed',
                    'transaction_id' => $paymentIntentId,
                    'amount' => $paymentIntent->amount / 100
                ];
            }
            
            return [
                'success' => false,
                'status' => $paymentIntent->status,
                'error' => 'Payment not completed'
            ];
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logTransaction([
                'action' => 'process_payment_error',
                'error' => $e->getMessage(),
                'payment_intent_id' => $paymentIntentId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function verifyPayment($paymentIntentId) {
        try {
            $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);
            
            return [
                'success' => true,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount / 100,
                'currency' => $paymentIntent->currency,
                'created' => $paymentIntent->created,
                'customer' => $paymentIntent->customer,
                'payment_method' => $paymentIntent->payment_method
            ];
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function refundPayment($chargeId, $amount = null, $reason = '') {
        try {
            $refundParams = [
                'charge' => $chargeId,
                'reason' => $reason ?: 'requested_by_customer'
            ];
            
            if ($amount) {
                $refundParams['amount'] = $amount * 100;
            }
            
            $refund = $this->stripe->refunds->create($refundParams);
            
            // Save refund to database
            $refundId = DBHelper::insert('refunds', [
                'transaction_id' => $this->getTransactionIdByCharge($chargeId),
                'refund_uuid' => uniqid('refund_'),
                'amount' => $refund->amount / 100,
                'currency' => strtoupper($refund->currency),
                'reason' => $reason,
                'status' => $refund->status === 'succeeded' ? 'completed' : 'pending',
                'gateway_refund_id' => $refund->id,
                'gateway_response' => json_encode($refund),
                'processed_at' => date('Y-m-d H:i:s')
            ]);
            
            // Update transaction status
            if ($refund->status === 'succeeded') {
                DBHelper::update('transactions', 
                    ['status' => 'refunded'],
                    ['gateway_transaction_id' => $chargeId]
                );
            }
            
            $this->logTransaction([
                'action' => 'refund_created',
                'refund_id' => $refund->id,
                'amount' => $refund->amount / 100,
                'charge_id' => $chargeId
            ]);
            
            return [
                'success' => true,
                'refund_id' => $refund->id,
                'amount' => $refund->amount / 100,
                'status' => $refund->status
            ];
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logTransaction([
                'action' => 'refund_error',
                'error' => $e->getMessage(),
                'charge_id' => $chargeId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function createCustomer($email, $name = '', $paymentMethodId = null) {
        try {
            $customerData = ['email' => $email];
            
            if ($name) {
                $customerData['name'] = $name;
            }
            
            if ($paymentMethodId) {
                $customerData['payment_method'] = $paymentMethodId;
            }
            
            $customer = $this->stripe->customers->create($customerData);
            
            return [
                'success' => true,
                'customer_id' => $customer->id
            ];
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function savePaymentMethod($customerId, $paymentMethodId) {
        try {
            $paymentMethod = $this->stripe->paymentMethods->retrieve($paymentMethodId);
            
            if ($paymentMethod->type === 'card') {
                $data = [
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'method_type' => 'stripe',
                    'provider_id' => $paymentMethod->id,
                    'last_four' => $paymentMethod->card->last4,
                    'expiry_month' => $paymentMethod->card->exp_month,
                    'expiry_year' => $paymentMethod->card->exp_year,
                    'metadata' => json_encode([
                        'brand' => $paymentMethod->card->brand,
                        'country' => $paymentMethod->card->country
                    ])
                ];
                
                DBHelper::insert('payment_methods', $data);
            }
            
        } catch (Exception $e) {
            error_log("Error saving payment method: " . $e->getMessage());
        }
    }
    
    private function getTransactionIdByCharge($chargeId) {
        $result = DBHelper::selectOne('transactions', ['id'], ['gateway_transaction_id' => $chargeId]);
        return $result ? $result['id'] : null;
    }
}
?>