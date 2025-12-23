-- Database: payment_gateway
CREATE DATABASE IF NOT EXISTS payment_gateway;
USE payment_gateway;

-- Users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    city VARCHAR(100),
    country VARCHAR(100),
    postal_code VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
);

-- Payment methods table
CREATE TABLE payment_methods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    method_type ENUM('stripe', 'paypal', 'instapay', 'cash') NOT NULL,
    provider_id VARCHAR(255) COMMENT 'Payment method ID from provider (Stripe PM ID, PayPal billing agreement, etc.)',
    last_four VARCHAR(4) COMMENT 'Last 4 digits for cards',
    expiry_month INT,
    expiry_year INT,
    is_default BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_method_type (method_type)
);

-- Transactions table
CREATE TABLE transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_uuid VARCHAR(36) UNIQUE NOT NULL COMMENT 'Public transaction ID',
    user_id INT,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    payment_method_id INT,
    payment_gateway ENUM('stripe', 'paypal', 'instapay', 'cash') NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed', 'refunded', 'cancelled') DEFAULT 'pending',
    gateway_transaction_id VARCHAR(255) COMMENT 'Transaction ID from payment gateway',
    gateway_response TEXT COMMENT 'Raw response from gateway',
    description VARCHAR(500),
    invoice_id VARCHAR(100),
    customer_email VARCHAR(255),
    customer_name VARCHAR(255),
    billing_address JSON,
    shipping_address JSON,
    items JSON COMMENT 'Array of items purchased',
    tax_amount DECIMAL(10,2) DEFAULT 0.00,
    shipping_amount DECIMAL(10,2) DEFAULT 0.00,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL,
    refund_amount DECIMAL(10,2) DEFAULT 0.00,
    ip_address VARCHAR(45),
    user_agent TEXT,
    metadata JSON,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    INDEX idx_transaction_uuid (transaction_uuid),
    INDEX idx_status (status),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_gateway (payment_gateway)
);

-- Refunds table
CREATE TABLE refunds (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id INT,
    refund_uuid VARCHAR(36) UNIQUE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    reason VARCHAR(500),
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    gateway_refund_id VARCHAR(255),
    gateway_response TEXT,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_refund_uuid (refund_uuid)
);

-- Webhook logs table
CREATE TABLE webhook_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    gateway ENUM('stripe', 'paypal') NOT NULL,
    event_type VARCHAR(100),
    payload JSON,
    headers TEXT,
    ip_address VARCHAR(45),
    processed BOOLEAN DEFAULT FALSE,
    processing_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gateway (gateway),
    INDEX idx_processed (processed)
);

-- API logs table
CREATE TABLE api_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    endpoint VARCHAR(255),
    method VARCHAR(10),
    request_body TEXT,
    response_body TEXT,
    status_code INT,
    ip_address VARCHAR(45),
    user_id INT,
    duration_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_endpoint (endpoint),
    INDEX idx_created_at (created_at)
);

-- Create stored procedure for transaction summary
DELIMITER //
CREATE PROCEDURE GetTransactionSummary(
    IN p_user_id INT,
    IN p_start_date DATE,
    IN p_end_date DATE
)
BEGIN
    SELECT 
        COUNT(*) as total_transactions,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_transactions,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
        SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as total_revenue,
        payment_gateway,
        DATE(created_at) as transaction_date
    FROM transactions
    WHERE user_id = p_user_id
        AND DATE(created_at) BETWEEN p_start_date AND p_end_date
    GROUP BY payment_gateway, DATE(created_at)
    ORDER BY transaction_date DESC;
END //
DELIMITER ;