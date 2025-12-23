<?php
require_once __DIR__ . '/PaymentGateway.php';

class CashPayment extends PaymentGateway {
    
    public function createPayment($params) {
        try {
            $amount = $this->validateAmount($params['amount']);
            
            // Generate reference number
            $reference = 'CASH-' . date('Ymd') . '-' . strtoupper(uniqid());
            
            // Save to database
            $transactionId = DBHelper::insert('transactions', [
                'transaction_uuid' => uniqid('cash_'),
                'user_id' => $params['user_id'] ?? null,
                'amount' => $amount,
                'currency' => strtoupper($params['currency'] ?? 'PHP'),
                'payment_gateway' => 'cash',
                'status' => 'pending',
                'gateway_transaction_id' => $reference,
                'description' => $params['description'] ?? 'Cash Payment',
                'customer_email' => $params['customer_email'] ?? '',
                'customer_name' => $params['customer_name'] ?? '',
                'customer_phone' => $params['customer_phone'] ?? '',
                'items' => json_encode($params['items'] ?? []),
                'total_amount' => $amount,
                'billing_address' => json_encode($params['billing_address'] ?? []),
                'shipping_address' => json_encode($params['shipping_address'] ?? []),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'metadata' => json_encode([
                    'payment_method' => 'cash',
                    'reference' => $reference,
                    'instructions' => $this->getPaymentInstructions($params)
                ])
            ]);
            
            $this->logTransaction([
                'action' => 'create_cash_payment',
                'reference' => $reference,
                'amount' => $amount,
                'transaction_id' => $transactionId
            ]);
            
            return [
                'success' => true,
                'reference' => $reference,
                'transaction_id' => $transactionId,
                'instructions' => $this->getPaymentInstructions($params),
                'amount' => $amount,
                'expiry_date' => date('Y-m-d', strtotime('+3 days'))
            ];
            
        } catch (Exception $e) {
            $this->logTransaction([
                'action' => 'create_cash_payment_error',
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function processPayment($reference, $params = []) {
        try {
            // For cash payments, we mark as completed when payment is received
            $transaction = DBHelper::selectOne('transactions', ['*'], ['gateway_transaction_id' => $reference]);
            
            if (!$transaction) {
                throw new Exception('Transaction not found');
            }
            
            // Update based on action
            if (isset($params['action'])) {
                switch ($params['action']) {
                    case 'confirm':
                        $status = 'completed';
                        $notes = 'Payment confirmed by staff';
                        break;
                    case 'cancel':
                        $status = 'cancelled';
                        $notes = 'Payment cancelled';
                        break;
                    default:
                        $status = $transaction['status'];
                        $notes = '';
                }
                
                DBHelper::update('transactions', 
                    [
                        'status' => $status,
                        'gateway_response' => json_encode([
                            'confirmed_by' => $params['confirmed_by'] ?? 'system',
                            'confirmed_at' => date('Y-m-d H:i:s'),
                            'notes' => $notes
                        ]),
                        'processed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null
                    ],
                    ['gateway_transaction_id' => $reference]
                );
                
                $this->logTransaction([
                    'action' => 'cash_payment_' . $params['action'],
                    'reference' => $reference,
                    'status' => $status,
                    'confirmed_by' => $params['confirmed_by'] ?? 'system'
                ]);
                
                return [
                    'success' => true,
                    'status' => $status,
                    'reference' => $reference,
                    'message' => "Payment $status successfully"
                ];
            }
            
            // Return current status
            return [
                'success' => true,
                'status' => $transaction['status'],
                'reference' => $reference,
                'amount' => $transaction['amount'],
                'created_at' => $transaction['created_at']
            ];
            
        } catch (Exception $e) {
            $this->logTransaction([
                'action' => 'process_cash_payment_error',
                'error' => $e->getMessage(),
                'reference' => $reference
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function verifyPayment($reference) {
        try {
            $transaction = DBHelper::selectOne('transactions', ['*'], ['gateway_transaction_id' => $reference]);
            
            if (!$transaction) {
                throw new Exception('Transaction not found');
            }
            
            return [
                'success' => true,
                'status' => $transaction['status'],
                'amount' => $transaction['amount'],
                'currency' => $transaction['currency'],
                'created_at' => $transaction['created_at'],
                'customer_name' => $transaction['customer_name'],
                'description' => $transaction['description']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function refundPayment($reference, $amount = null, $reason = '') {
        try {
            $transaction = DBHelper::selectOne('transactions', ['*'], ['gateway_transaction_id' => $reference]);
            
            if (!$transaction) {
                throw new Exception('Transaction not found');
            }
            
            $refundAmount = $amount ?: $transaction['amount'];
            
            // Create refund record
            $refundId = DBHelper::insert('refunds', [
                'transaction_id' => $transaction['id'],
                'refund_uuid' => uniqid('cash_refund_'),
                'amount' => $refundAmount,
                'currency' => $transaction['currency'],
                'reason' => $reason,
                'status' => 'pending',
                'gateway_response' => json_encode([
                    'refund_method' => 'cash',
                    'reason' => $reason,
                    'processed_by' => $_SESSION['user_id'] ?? 'system'
                ]),
                'processed_at' => date('Y-m-d H:i:s')
            ]);
            
            // Update transaction status
            DBHelper::update('transactions', 
                [
                    'status' => 'refunded',
                    'refund_amount' => $refundAmount
                ],
                ['gateway_transaction_id' => $reference]
            );
            
            $this->logTransaction([
                'action' => 'cash_refund',
                'reference' => $reference,
                'refund_id' => $refundId,
                'amount' => $refundAmount,
                'reason' => $reason
            ]);
            
            return [
                'success' => true,
                'refund_id' => $refundId,
                'amount' => $refundAmount,
                'status' => 'pending',
                'instructions' => 'Please contact customer service to process your cash refund.'
            ];
            
        } catch (Exception $e) {
            $this->logTransaction([
                'action' => 'cash_refund_error',
                'error' => $e->getMessage(),
                'reference' => $reference
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function getPaymentInstructions($params) {
        $instructions = [];
        
        $instructions[] = "Please make your payment using one of the following methods:";
        $instructions[] = "";
        
        // Bank Deposit
        if (isset($params['bank_accounts'])) {
            $instructions[] = "BANK DEPOSIT:";
            foreach ($params['bank_accounts'] as $bank) {
                $instructions[] = "• " . $bank['bank_name'] . " - " . $bank['account_number'];
                $instructions[] = "  Account Name: " . $bank['account_name'];
                if (isset($bank['branch'])) {
                    $instructions[] = "  Branch: " . $bank['branch'];
                }
                $instructions[] = "";
            }
        }
        
        // Over-the-counter
        $instructions[] = "OVER-THE-COUNTER PAYMENT:";
        $instructions[] = "• You can pay at any " . (isset($params['payment_centers']) ? implode(', ', $params['payment_centers']) : "authorized payment center");
        $instructions[] = "• Present your reference number: " . ($params['reference'] ?? 'Will be provided');
        $instructions[] = "";
        
        // Cash on Delivery/Pickup
        if (isset($params['pickup_location'])) {
            $instructions[] = "CASH ON PICKUP:";
            $instructions[] = "• Location: " . $params['pickup_location'];
            $instructions[] = "• Hours: " . ($params['pickup_hours'] ?? '9:00 AM - 6:00 PM');
            $instructions[] = "";
        }
        
        $instructions[] = "IMPORTANT:";
        $instructions[] = "• Keep your payment receipt";
        $instructions[] = "• Payment must be made within 3 days";
        $instructions[] = "• Reference number must be included in all transactions";
        
        return implode("\n", $instructions);
    }
    
    public function generatePaymentSlip($transactionId) {
        $transaction = DBHelper::selectOne('transactions', ['*'], ['id' => $transactionId]);
        
        if (!$transaction) {
            return null;
        }
        
        $slip = [
            'header' => SITE_NAME . ' - Payment Slip',
            'reference_number' => $transaction['gateway_transaction_id'],
            'date' => date('F j, Y', strtotime($transaction['created_at'])),
            'customer' => [
                'name' => $transaction['customer_name'],
                'email' => $transaction['customer_email'],
                'phone' => json_decode($transaction['metadata'], true)['customer_phone'] ?? ''
            ],
            'payment_details' => [
                'amount' => number_format($transaction['amount'], 2),
                'currency' => $transaction['currency'],
                'description' => $transaction['description']
            ],
            'instructions' => json_decode($transaction['metadata'], true)['instructions'] ?? '',
            'footer' => 'This payment slip is valid for 3 days from date of issue.'
        ];
        
        return $slip;
    }
}
?>