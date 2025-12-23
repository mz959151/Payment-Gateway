<?php
require_once __DIR__ . '/PaymentGateway.php';

class InstaPayPayment extends PaymentGateway {
    private $apiKey;
    private $secretKey;
    private $merchantId;
    private $environment;
    
    public function __construct($config = []) {
        parent::__construct($config);
        
        $this->apiKey = $config['api_key'] ?? INSTAPAY_API_KEY;
        $this->secretKey = $config['secret_key'] ?? INSTAPAY_SECRET_KEY;
        $this->merchantId = $config['merchant_id'] ?? INSTAPAY_MERCHANT_ID;
        $this->environment = ($config['environment'] ?? INSTAPAY_ENVIRONMENT) === 'production' ? 'production' : 'sandbox';
    }
    
    private function getBaseUrl() {
        return $this->environment === 'production' 
            ? 'https://api.instapay.ph/v2'
            : 'https://sandbox-api.instapay.ph/v2';
    }
    
    protected function generateSignature($data) {
        $stringToSign = json_encode($data);
        return hash_hmac('sha256', $stringToSign, $this->secretKey);
    }
    
    public function createPayment($params) {
        try {
            $amount = $this->validateAmount($params['amount']);
            $timestamp = time();
            
            $paymentData = [
                'merchant_id' => $this->merchantId,
                'reference_id' => $params['order_id'] ?? uniqid('instapay_'),
                'amount' => $amount,
                'currency' => 'PHP',
                'description' => $params['description'] ?? 'Payment',
                'customer' => [
                    'email' => $params['customer_email'],
                    'name' => $params['customer_name'] ?? '',
                    'phone' => $params['customer_phone'] ?? ''
                ],
                'redirect_urls' => [
                    'success' => $params['success_url'] ?? SITE_URL . '/payment-success.php',
                    'failure' => $params['failure_url'] ?? SITE_URL . '/payment-failed.php',
                    'cancel' => $params['cancel_url'] ?? SITE_URL . '/payment-cancel.php'
                ],
                'metadata' => $params['metadata'] ?? []
            ];
            
            $signature = $this->generateSignature($paymentData, $timestamp);
            
            $ch = curl_init($this->getBaseUrl() . '/payments');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($paymentData),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-API-Key: ' . $this->apiKey,
                    'X-Timestamp: ' . $timestamp,
                    'X-Signature: ' . $signature
                ]
            ]);
            
            $response = json_decode(curl_exec($ch), true);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && isset($response['payment_id'])) {
                // Save to database
                $transactionId = DBHelper::insert('transactions', [
                    'transaction_uuid' => uniqid('instapay_'),
                    'user_id' => $params['user_id'] ?? null,
                    'amount' => $amount,
                    'currency' => 'PHP',
                    'payment_gateway' => 'instapay',
                    'status' => 'pending',
                    'gateway_transaction_id' => $response['payment_id'],
                    'customer_email' => $params['customer_email'],
                    'customer_name' => $params['customer_name'] ?? '',
                    'description' => $params['description'] ?? '',
                    'items' => json_encode($params['items'] ?? []),
                    'total_amount' => $amount,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'metadata' => json_encode($response)
                ]);
                
                $this->logTransaction([
                    'action' => 'create_payment',
                    'payment_id' => $response['payment_id'],
                    'amount' => $amount,
                    'transaction_id' => $transactionId
                ]);
                
                return [
                    'success' => true,
                    'payment_id' => $response['payment_id'],
                    'payment_url' => $response['payment_url'] ?? '',
                    'qr_code' => $response['qr_code'] ?? '',
                    'transaction_id' => $transactionId,
                    'expires_at' => $response['expires_at'] ?? ''
                ];
            }
            
            throw new Exception('Failed to create InstaPay payment: ' . ($response['message'] ?? 'Unknown error'));
            
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
    
    public function processPayment($paymentId, $params = []) {
        // InstaPay payments are processed asynchronously via webhook
        // This method checks the payment status
        return $this->verifyPayment($paymentId);
    }
    
    public function verifyPayment($paymentId) {
        try {
            $timestamp = time();
            $data = ['payment_id' => $paymentId];
            $signature = $this->generateSignature($data, $timestamp);
            
            $ch = curl_init($this->getBaseUrl() . '/payments/' . $paymentId);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-API-Key: ' . $this->apiKey,
                    'X-Timestamp: ' . $timestamp,
                    'X-Signature: ' . $signature
                ]
            ]);
            
            $response = json_decode(curl_exec($ch), true);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                // Update transaction status based on response
                $status = $this->mapStatus($response['status']);
                
                if ($status !== 'pending') {
                    DBHelper::update('transactions', 
                        [
                            'status' => $status,
                            'gateway_response' => json_encode($response),
                            'processed_at' => date('Y-m-d H:i:s')
                        ],
                        ['gateway_transaction_id' => $paymentId]
                    );
                }
                
                return [
                    'success' => true,
                    'status' => $status,
                    'amount' => $response['amount'] ?? 0,
                    'currency' => $response['currency'] ?? 'PHP',
                    'created_at' => $response['created_at'] ?? '',
                    'updated_at' => $response['updated_at'] ?? ''
                ];
            }
            
            throw new Exception('Failed to verify payment: ' . ($response['message'] ?? 'Unknown error'));
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function refundPayment($paymentId, $amount = null, $reason = '') {
        try {
            $timestamp = time();
            $refundData = [
                'payment_id' => $paymentId,
                'amount' => $amount,
                'reason' => $reason
            ];
            
            $signature = $this->generateSignature($refundData, $timestamp);
            
            $ch = curl_init($this->getBaseUrl() . '/refunds');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($refundData),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-API-Key: ' . $this->apiKey,
                    'X-Timestamp: ' . $timestamp,
                    'X-Signature: ' . $signature
                ]
            ]);
            
            $response = json_decode(curl_exec($ch), true);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && isset($response['refund_id'])) {
                // Save refund to database
                $refundId = DBHelper::insert('refunds', [
                    'transaction_id' => $this->getTransactionIdByPayment($paymentId),
                    'refund_uuid' => uniqid('refund_'),
                    'amount' => $response['amount'],
                    'currency' => $response['currency'] ?? 'PHP',
                    'reason' => $reason,
                    'status' => $response['status'] === 'success' ? 'completed' : 'pending',
                    'gateway_refund_id' => $response['refund_id'],
                    'gateway_response' => json_encode($response),
                    'processed_at' => date('Y-m-d H:i:s')
                ]);
                
                // Update transaction status
                if ($response['status'] === 'success') {
                    DBHelper::update('transactions', 
                        ['status' => 'refunded'],
                        ['gateway_transaction_id' => $paymentId]
                    );
                }
                
                $this->logTransaction([
                    'action' => 'refund_created',
                    'refund_id' => $response['refund_id'],
                    'payment_id' => $paymentId,
                    'amount' => $response['amount']
                ]);
                
                return [
                    'success' => true,
                    'refund_id' => $response['refund_id'],
                    'amount' => $response['amount'],
                    'status' => $response['status']
                ];
            }
            
            throw new Exception('Failed to create refund: ' . ($response['message'] ?? 'Unknown error'));
            
        } catch (Exception $e) {
            $this->logTransaction([
                'action' => 'refund_error',
                'error' => $e->getMessage(),
                'payment_id' => $paymentId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function getSupportedBanks() {
        try {
            $timestamp = time();
            $signature = $this->generateSignature([], $timestamp);
            
            $ch = curl_init($this->getBaseUrl() . '/banks');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-API-Key: ' . $this->apiKey,
                    'X-Timestamp: ' . $timestamp,
                    'X-Signature: ' . $signature
                ]
            ]);
            
            $response = json_decode(curl_exec($ch), true);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                return [
                    'success' => true,
                    'banks' => $response['banks'] ?? []
                ];
            }
            
            throw new Exception('Failed to get banks list');
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function mapStatus($instapayStatus) {
        $statusMap = [
            'pending' => 'pending',
            'processing' => 'processing',
            'success' => 'completed',
            'failed' => 'failed',
            'expired' => 'cancelled',
            'refunded' => 'refunded'
        ];
        
        return $statusMap[$instapayStatus] ?? 'pending';
    }
    
    private function getTransactionIdByPayment($paymentId) {
        $result = DBHelper::selectOne('transactions', ['id'], ['gateway_transaction_id' => $paymentId]);
        return $result ? $result['id'] : null;
    }
}
?>