<?php
require_once __DIR__ . '/PaymentGateway.php';

class PayPalPayment extends PaymentGateway {
    private $clientId;
    private $clientSecret;
    private $environment;
    private $accessToken;
    
    public function __construct($config = []) {
        parent::__construct($config);
        
        $this->clientId = $config['client_id'] ?? PAYPAL_CLIENT_ID;
        $this->clientSecret = $config['secret'] ?? PAYPAL_SECRET;
        $this->environment = ($config['environment'] ?? PAYPAL_ENVIRONMENT) === 'live' ? 'live' : 'sandbox';
        
        $this->authenticate();
    }
    
    private function authenticate() {
        $authUrl = $this->getBaseUrl() . '/v1/oauth2/token';
        
        $ch = curl_init($authUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: en_US',
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_USERPWD => $this->clientId . ':' . $this->clientSecret
        ]);
        
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
        } else {
            throw new Exception('PayPal authentication failed');
        }
    }
    
    private function getBaseUrl() {
        return $this->environment === 'live' 
            ? 'https://api-m.paypal.com' 
            : 'https://api-m.sandbox.paypal.com';
    }
    
    public function createPayment($params) {
        try {
            $amount = $this->validateAmount($params['amount']);
            $currency = strtoupper($params['currency'] ?? 'USD');
            
            $orderData = [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => number_format($amount, 2, '.', '')
                    ],
                    'description' => $params['description'] ?? 'Payment',
                    'custom_id' => $params['order_id'] ?? uniqid()
                ]],
                'application_context' => [
                    'return_url' => $params['return_url'] ?? SITE_URL . '/payment-success.php',
                    'cancel_url' => $params['cancel_url'] ?? SITE_URL . '/payment-cancel.php',
                    'brand_name' => SITE_NAME,
                    'user_action' => 'PAY_NOW'
                ]
            ];
            
            $response = $this->makeRequest('/v2/checkout/orders', 'POST', $orderData);
            
            if (isset($response['id'])) {
                // Save to database
                $transactionId = DBHelper::insert('transactions', [
                    'transaction_uuid' => uniqid('paypal_'),
                    'user_id' => $params['user_id'] ?? null,
                    'amount' => $amount,
                    'currency' => $currency,
                    'payment_gateway' => 'paypal',
                    'status' => 'pending',
                    'gateway_transaction_id' => $response['id'],
                    'customer_email' => $params['customer_email'] ?? '',
                    'customer_name' => $params['customer_name'] ?? '',
                    'description' => $params['description'] ?? '',
                    'items' => json_encode($params['items'] ?? []),
                    'total_amount' => $amount,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'metadata' => json_encode(['order_id' => $response['id']])
                ]);
                
                $this->logTransaction([
                    'action' => 'create_payment',
                    'order_id' => $response['id'],
                    'amount' => $amount,
                    'transaction_id' => $transactionId
                ]);
                
                // Find approve link
                $approveUrl = '';
                foreach ($response['links'] as $link) {
                    if ($link['rel'] === 'approve') {
                        $approveUrl = $link['href'];
                        break;
                    }
                }
                
                return [
                    'success' => true,
                    'order_id' => $response['id'],
                    'approve_url' => $approveUrl,
                    'transaction_id' => $transactionId
                ];
            }
            
            throw new Exception('Failed to create PayPal order');
            
        } catch (Exception $e) {
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
    
    public function processPayment($orderId, $params = []) {
        try {
            // Capture the payment
            $response = $this->makeRequest("/v2/checkout/orders/$orderId/capture", 'POST');
            
            if (isset($response['status']) && $response['status'] === 'COMPLETED') {
                $capture = $response['purchase_units'][0]['payments']['captures'][0];
                
                // Update transaction status
                DBHelper::update('transactions', 
                    [
                        'status' => 'completed',
                        'gateway_response' => json_encode($response),
                        'gateway_transaction_id' => $capture['id'],
                        'processed_at' => date('Y-m-d H:i:s')
                    ],
                    ['gateway_transaction_id' => $orderId]
                );
                
                $this->logTransaction([
                    'action' => 'payment_captured',
                    'order_id' => $orderId,
                    'capture_id' => $capture['id'],
                    'amount' => $capture['amount']['value']
                ]);
                
                return [
                    'success' => true,
                    'status' => 'completed',
                    'capture_id' => $capture['id'],
                    'amount' => $capture['amount']['value'],
                    'currency' => $capture['amount']['currency_code']
                ];
            }
            
            return [
                'success' => false,
                'status' => $response['status'] ?? 'unknown',
                'error' => 'Payment not completed'
            ];
            
        } catch (Exception $e) {
            $this->logTransaction([
                'action' => 'process_payment_error',
                'error' => $e->getMessage(),
                'order_id' => $orderId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function verifyPayment($orderId) {
        try {
            $response = $this->makeRequest("/v2/checkout/orders/$orderId", 'GET');
            
            return [
                'success' => true,
                'status' => $response['status'],
                'amount' => $response['purchase_units'][0]['amount']['value'],
                'currency' => $response['purchase_units'][0]['amount']['currency_code'],
                'create_time' => $response['create_time'],
                'update_time' => $response['update_time']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function refundPayment($captureId, $amount = null, $reason = '') {
        try {
            $refundData = [];
            
            if ($amount) {
                $refundData['amount'] = [
                    'value' => number_format($amount, 2, '.', ''),
                    'currency_code' => 'USD'
                ];
            }
            
            $response = $this->makeRequest("/v2/payments/captures/$captureId/refund", 'POST', $refundData);
            
            if (isset($response['id'])) {
                // Save refund to database
                $refundId = DBHelper::insert('refunds', [
                    'transaction_id' => $this->getTransactionIdByCapture($captureId),
                    'refund_uuid' => uniqid('refund_'),
                    'amount' => $response['amount']['value'],
                    'currency' => $response['amount']['currency_code'],
                    'reason' => $reason,
                    'status' => $response['status'] === 'COMPLETED' ? 'completed' : 'pending',
                    'gateway_refund_id' => $response['id'],
                    'gateway_response' => json_encode($response),
                    'processed_at' => date('Y-m-d H:i:s')
                ]);
                
                // Update transaction status
                if ($response['status'] === 'COMPLETED') {
                    DBHelper::update('transactions', 
                        ['status' => 'refunded'],
                        ['gateway_transaction_id' => $captureId]
                    );
                }
                
                $this->logTransaction([
                    'action' => 'refund_created',
                    'refund_id' => $response['id'],
                    'capture_id' => $captureId,
                    'amount' => $response['amount']['value']
                ]);
                
                return [
                    'success' => true,
                    'refund_id' => $response['id'],
                    'amount' => $response['amount']['value'],
                    'status' => $response['status']
                ];
            }
            
            throw new Exception('Failed to create refund');
            
        } catch (Exception $e) {
            $this->logTransaction([
                'action' => 'refund_error',
                'error' => $e->getMessage(),
                'capture_id' => $captureId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->getBaseUrl() . $endpoint;
        
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
                'PayPal-Request-Id: ' . uniqid()
            ]
        ];
        
        if ($data && ($method === 'POST' || $method === 'PUT')) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        
        curl_setopt_array($ch, $options);
        
        $response = json_decode(curl_exec($ch), true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception('PayPal API Error: ' . ($response['message'] ?? 'Unknown error'));
        }
        
        return $response;
    }
    
    private function getTransactionIdByCapture($captureId) {
        $result = DBHelper::selectOne('transactions', ['id'], ['gateway_transaction_id' => $captureId]);
        return $result ? $result['id'] : null;
    }
}
?>