<?php
require_once __DIR__ . '/../config/database.php';

abstract class PaymentGateway {
    protected $config;
    protected $logger;
    
    public function __construct($config = []) {
        $this->config = $config;
        $this->logger = new PaymentLogger();
    }
    
    abstract public function createPayment($params);
    abstract public function processPayment($paymentId, $params);
    abstract public function verifyPayment($paymentId);
    abstract public function refundPayment($transactionId, $amount, $reason = '');
    
    protected function validateAmount($amount) {
        if (!is_numeric($amount) || $amount <= 0) {
            throw new Exception("Invalid amount: $amount");
        }
        return round($amount, 2);
    }
    
    protected function generateTransactionId() {
        return uniqid('txn_', true);
    }
    
    protected function logTransaction($data) {
        $this->logger->log('transaction', $data);
    }
    
    protected function sendWebhook($url, $data) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Webhook-Signature: ' . $this->generateSignature($data)
            ]
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response;
    }
    
    protected function generateSignature($data) {
        return hash_hmac('sha256', json_encode($data), $this->config['webhook_secret'] ?? '');
    }
}

class PaymentLogger {
    public function log($type, $data) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        $logFile = LOG_PATH . date('Y-m-d') . '.log';
        file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND);
    }
    
    public function logApi($endpoint, $request, $response, $statusCode) {
        DBHelper::insert('api_logs', [
            'endpoint' => $endpoint,
            'method' => $_SERVER['REQUEST_METHOD'],
            'request_body' => json_encode($request),
            'response_body' => json_encode($response),
            'status_code' => $statusCode,
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_id' => $_SESSION['user_id'] ?? null,
            'duration_ms' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000)
        ]);
    }
}
?>