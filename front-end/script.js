// API Configuration
const API_BASE_URL = 'http://localhost/payment-gateway/api';
const STRIPE_PUBLIC_KEY = 'pk_test_your_public_key_here'; // Replace with your Stripe public key

// Global variables
let selectedPaymentMethod = 'stripe';
let stripe = null;
let cardElement = null;
let currentPaymentIntent = null;
let transactionData = null;

// Initialize the application
document.addEventListener('DOMContentLoaded', function () {
    // Initialize Stripe
    if (typeof Stripe !== 'undefined') {
        stripe = Stripe(STRIPE_PUBLIC_KEY);
        initializeStripeElements();
    }

    // Set up event listeners
    setupEventListeners();

    // Load InstaPay banks
    loadInstaPayBanks();

    // Set default values
    setDefaultValues();
});

// Set up event listeners
function setupEventListeners() {
    // Payment method selection
    document.querySelectorAll('.method-option').forEach(option => {
        option.addEventListener('click', function () {
            selectPaymentMethod(this.dataset.method);
        });
    });

    // Pay button
    document.getElementById('pay-button').addEventListener('click', processPayment);

    // Close modal button
    document.querySelector('.close-modal').addEventListener('click', closeModal);

    // Close modal when clicking outside
    document.getElementById('payment-modal').addEventListener('click', function (e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // Form validation
    document.getElementById('customer-name').addEventListener('input', validateForm);
    document.getElementById('customer-email').addEventListener('input', validateForm);
    document.getElementById('terms').addEventListener('change', validateForm);
}

// Initialize Stripe Elements
function initializeStripeElements() {
    const elements = stripe.elements();

    // Style for Stripe elements
    const style = {
        base: {
            fontSize: '16px',
            color: '#32325d',
            fontFamily: 'Segoe UI, Tahoma, Geneva, Verdana, sans-serif',
            '::placeholder': {
                color: '#aab7c4'
            }
        },
        invalid: {
            color: '#e74c3c'
        }
    };

    // Create card elements
    cardElement = elements.create('cardNumber', { style: style });
    cardElement.mount('#card-number');

    const cardExpiry = elements.create('cardExpiry', { style: style });
    cardExpiry.mount('#card-expiry');

    const cardCvc = elements.create('cardCvc', { style: style });
    cardCvc.mount('#card-cvc');

    // Handle real-time validation errors
    cardElement.on('change', function (event) {
        const displayError = document.getElementById('card-errors');
        if (event.error) {
            displayError.textContent = event.error.message;
        } else {
            displayError.textContent = '';
        }
    });
}

// Select payment method
function selectPaymentMethod(method) {
    // Update UI
    document.querySelectorAll('.method-option').forEach(option => {
        option.classList.remove('active');
    });

    document.querySelector(`.method-option[data-method="${method}"]`).classList.add('active');
    selectedPaymentMethod = method;

    // Show/hide relevant sections
    document.getElementById('stripe-section').style.display = method === 'stripe' ? 'block' : 'none';
    document.getElementById('instapay-section').style.display = method === 'instapay' ? 'block' : 'none';
    document.getElementById('cash-section').style.display = method === 'cash' ? 'block' : 'none';

    // Update pay button text
    const amount = document.getElementById('total-amount').textContent;
    document.getElementById('pay-button').innerHTML =
        `<i class="fas fa-lock"></i> Pay Now - ${amount}`;

    validateForm();
}

// Validate form
function validateForm() {
    const name = document.getElementById('customer-name').value.trim();
    const email = document.getElementById('customer-email').value.trim();
    const terms = document.getElementById('terms').checked;
    const payButton = document.getElementById('pay-button');

    // Basic validation
    let isValid = name && email && terms;

    // Additional validation based on payment method
    if (selectedPaymentMethod === 'stripe') {
        // Stripe validation would happen on the server
        // We could add client-side card validation here
    } else if (selectedPaymentMethod === 'instapay') {
        const bank = document.getElementById('instapay-bank').value;
        isValid = isValid && bank;
    }

    payButton.disabled = !isValid;
    return isValid;
}

// Load InstaPay banks
async function loadInstaPayBanks() {
    try {
        // This would normally come from your API
        const banks = [
            { id: 'bpi', name: 'Bank of the Philippine Islands (BPI)' },
            { id: 'bdo', name: 'Banco de Oro (BDO)' },
            { id: 'metrobank', name: 'Metropolitan Bank & Trust Company' },
            { id: 'landbank', name: 'Land Bank of the Philippines' },
            { id: 'securitybank', name: 'Security Bank' },
            { id: 'unionbank', name: 'UnionBank' },
            { id: 'chinabank', name: 'China Bank' },
            { id: 'rcbc', name: 'RCBC' },
            { id: 'pnb', name: 'Philippine National Bank (PNB)' }
        ];

        const select = document.getElementById('instapay-bank');
        banks.forEach(bank => {
            const option = document.createElement('option');
            option.value = bank.id;
            option.textContent = bank.name;
            select.appendChild(option);
        });

        select.addEventListener('change', validateForm);

    } catch (error) {
        console.error('Error loading banks:', error);
    }
}

// Set default values
function setDefaultValues() {
    // Set random user data for demo
    const users = [
        { name: 'John Doe', email: 'john@example.com', phone: '+1 (555) 123-4567' },
        { name: 'Jane Smith', email: 'jane@example.com', phone: '+1 (555) 987-6543' },
        { name: 'Bob Johnson', email: 'bob@example.com', phone: '+1 (555) 456-7890' }
    ];

    const user = users[Math.floor(Math.random() * users.length)];

    document.getElementById('customer-name').value = user.name;
    document.getElementById('customer-email').value = user.email;
    document.getElementById('customer-phone').value = user.phone;
    document.getElementById('card-name').value = user.name;

    validateForm();
}

// Process payment
async function processPayment() {
    if (!validateForm()) {
        showError('Please fill in all required fields');
        return;
    }

    // Collect payment data
    const paymentData = {
        gateway: selectedPaymentMethod,
        amount: parseFloat(document.getElementById('total-amount').textContent.replace('$', '')),
        currency: 'USD',
        customer_name: document.getElementById('customer-name').value.trim(),
        customer_email: document.getElementById('customer-email').value.trim(),
        customer_phone: document.getElementById('customer-phone').value.trim() || '',
        description: document.getElementById('product-name').textContent,
        items: [{
            name: document.getElementById('product-name').textContent,
            quantity: 1,
            price: parseFloat(document.getElementById('unit-price').textContent.replace('$', ''))
        }],
        metadata: {
            source: 'web_demo',
            timestamp: new Date().toISOString()
        }
    };

    // Add method-specific data
    if (selectedPaymentMethod === 'instapay') {
        paymentData.bank = document.getElementById('instapay-bank').value;
    }

    if (selectedPaymentMethod === 'cash') {
        paymentData.bank_accounts = [
            {
                bank_name: 'Bank of the Philippine Islands',
                account_number: '1234-5678-90',
                account_name: 'Your Company Name',
                branch: 'Makati Main'
            }
        ];
        paymentData.pickup_location = '123 Main Street, Makati City';
    }

    // Show processing modal
    showPaymentModal('Processing your payment...', 'Please wait while we initialize the payment.');

    try {
        let result;

        switch (selectedPaymentMethod) {
            case 'stripe':
                result = await processStripePayment(paymentData);
                break;
            case 'paypal':
                result = await processPayPalPayment(paymentData);
                break;
            case 'instapay':
                result = await processInstaPayPayment(paymentData);
                break;
            case 'cash':
                result = await processCashPayment(paymentData);
                break;
            default:
                throw new Error('Unsupported payment method');
        }

        if (result.success) {
            transactionData = result.data;
            await handlePaymentResult(result);
        } else {
            throw new Error(result.error || 'Payment failed');
        }

    } catch (error) {
        showPaymentError(error.message);
        console.error('Payment error:', error);
    }
}

// Process Stripe payment
async function processStripePayment(paymentData) {
    // First, create the payment intent on our server
    const createResponse = await fetch(`${API_BASE_URL}/create-payment.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(paymentData)
    });

    const createResult = await createResponse.json();

    if (!createResult.success) {
        throw new Error(createResult.error || 'Failed to create payment');
    }

    // Save payment intent data
    currentPaymentIntent = createResult.data;

    // Confirm the payment with Stripe
    const cardResult = await stripe.confirmCardPayment(currentPaymentIntent.client_secret, {
        payment_method: {
            card: cardElement,
            billing_details: {
                name: paymentData.customer_name,
                email: paymentData.customer_email,
                phone: paymentData.customer_phone
            }
        }
    });

    if (cardResult.error) {
        throw new Error(cardResult.error.message);
    }

    // Payment succeeded
    return {
        success: true,
        data: {
            ...currentPaymentIntent,
            status: 'completed',
            transaction_id: cardResult.paymentIntent.id
        }
    };
}

// Process PayPal payment
async function processPayPalPayment(paymentData) {
    // Create PayPal order
    const createResponse = await fetch(`${API_BASE_URL}/create-payment.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(paymentData)
    });

    const createResult = await createResponse.json();

    if (!createResult.success) {
        throw new Error(createResult.error || 'Failed to create PayPal order');
    }

    // Redirect to PayPal approval URL
    if (createResult.data.approve_url) {
        window.open(createResult.data.approve_url, '_blank');

        // Show instructions to user
        showPaymentModal(
            'Redirecting to PayPal...',
            'A new window has opened for PayPal authorization. Please complete the payment there.',
            true
        );

        // Start polling for payment completion
        return await pollPaymentStatus(createResult.data.order_id, 'paypal');
    }

    throw new Error('No PayPal approval URL received');
}

// Process InstaPay payment
async function processInstaPayPayment(paymentData) {
    // Create InstaPay payment
    const createResponse = await fetch(`${API_BASE_URL}/create-payment.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(paymentData)
    });

    const createResult = await createResponse.json();

    if (!createResult.success) {
        throw new Error(createResult.error || 'Failed to create InstaPay payment');
    }

    // If there's a payment URL, redirect to it
    if (createResult.data.payment_url) {
        window.open(createResult.data.payment_url, '_blank');

        showPaymentModal(
            'Redirecting to Bank...',
            'A new window has opened for bank payment. Please complete the transaction there.',
            true
        );

        // Start polling for payment completion
        return await pollPaymentStatus(createResult.data.payment_id, 'instapay');
    }

    // Show QR code if available
    if (createResult.data.qr_code) {
        showQRCodePayment(createResult.data);
        return createResult;
    }

    throw new Error('No payment method available');
}

// Process Cash payment
async function processCashPayment(paymentData) {
    // Create cash payment record
    const createResponse = await fetch(`${API_BASE_URL}/create-payment.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(paymentData)
    });

    const createResult = await createResponse.json();

    if (!createResult.success) {
        throw new Error(createResult.error || 'Failed to create cash payment');
    }

    // Show payment slip
    showPaymentSlip(createResult.data);

    return createResult;
}

// Poll payment status
async function pollPaymentStatus(paymentId, gateway, interval = 3000, maxAttempts = 60) {
    let attempts = 0;

    return new Promise((resolve, reject) => {
        const poll = async () => {
            attempts++;

            try {
                const verifyResponse = await fetch(`${API_BASE_URL}/verify-payment.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        gateway: gateway,
                        payment_id: paymentId
                    })
                });

                const verifyResult = await verifyResponse.json();

                if (verifyResult.success) {
                    if (verifyResult.data.status === 'completed') {
                        resolve({
                            success: true,
                            data: verifyResult.data
                        });
                        return;
                    } else if (verifyResult.data.status === 'failed') {
                        reject(new Error('Payment failed'));
                        return;
                    }
                }

                // Continue polling if not completed yet
                if (attempts < maxAttempts) {
                    setTimeout(poll, interval);

                    // Update modal message
                    updatePaymentModalMessage(
                        'Waiting for payment confirmation...',
                        `Attempt ${attempts}/${maxAttempts}. Please don't close this window.`
                    );
                } else {
                    reject(new Error('Payment timeout'));
                }

            } catch (error) {
                if (attempts < maxAttempts) {
                    setTimeout(poll, interval);
                } else {
                    reject(error);
                }
            }
        };

        poll();
    });
}

// Handle payment result
async function handlePaymentResult(result) {
    switch (result.data.status) {
        case 'completed':
            showPaymentSuccess(result.data);
            break;
        case 'pending':
            showPaymentPending(result.data);
            break;
        default:
            showPaymentError('Payment status unknown');
    }
}

// Show payment modal
function showPaymentModal(title, message, showCloseButton = false) {
    const modal = document.getElementById('payment-modal');
    const modalTitle = document.getElementById('modal-title');
    const statusMessage = document.getElementById('status-message');
    const statusDetails = document.getElementById('status-details');
    const statusIcon = document.getElementById('status-icon');
    const paymentDetails = document.getElementById('payment-details');
    const paymentActions = document.getElementById('payment-actions');

    modalTitle.textContent = title;
    statusMessage.textContent = title;
    statusDetails.textContent = message;

    // Reset icon and content
    statusIcon.className = 'status-icon';
    statusIcon.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    paymentDetails.innerHTML = '';
    paymentActions.innerHTML = '';

    // Show/hide close button
    document.querySelector('.close-modal').style.display = showCloseButton ? 'block' : 'none';

    modal.classList.add('active');
}

// Update payment modal message
function updatePaymentModalMessage(title, message) {
    const statusMessage = document.getElementById('status-message');
    const statusDetails = document.getElementById('status-details');

    statusMessage.textContent = title;
    statusDetails.textContent = message;
}

// Close modal
function closeModal() {
    document.getElementById('payment-modal').classList.remove('active');
}

// Show payment success
function showPaymentSuccess(paymentData) {
    const statusIcon = document.getElementById('status-icon');
    const statusMessage = document.getElementById('status-message');
    const statusDetails = document.getElementById('status-details');
    const paymentDetails = document.getElementById('payment-details');
    const paymentActions = document.getElementById('payment-actions');

    statusIcon.className = 'status-icon success';
    statusIcon.innerHTML = '<i class="fas fa-check-circle"></i>';
    statusMessage.textContent = 'Payment Successful!';
    statusDetails.textContent = 'Your payment has been processed successfully.';

    // Show payment details
    paymentDetails.innerHTML = `
        <p>
            <span class="label">Transaction ID:</span>
            <span class="value">${paymentData.transaction_id || paymentData.order_id || paymentData.reference}</span>
        </p>
        <p>
            <span class="label">Amount:</span>
            <span class="value">${paymentData.currency || 'USD'} ${paymentData.amount}</span>
        </p>
        <p>
            <span class="label">Date:</span>
            <span class="value">${new Date().toLocaleString()}</span>
        </p>
        <p>
            <span class="label">Status:</span>
            <span class="value">Completed</span>
        </p>
    `;

    // Show action buttons
    paymentActions.innerHTML = `
        <button class="btn-primary" onclick="printReceipt()">
            <i class="fas fa-print"></i> Print Receipt
        </button>
        <button class="btn-secondary" onclick="closeModal()">
            <i class="fas fa-home"></i> Return Home
        </button>
    `;

    // Send confirmation email (simulate)
    simulateEmailConfirmation(paymentData);
}

// Show payment pending (for cash/instapay)
function showPaymentPending(paymentData) {
    const statusIcon = document.getElementById('status-icon');
    const statusMessage = document.getElementById('status-message');
    const statusDetails = document.getElementById('status-details');
    const paymentDetails = document.getElementById('payment-details');
    const paymentActions = document.getElementById('payment-actions');

    statusIcon.className = 'status-icon pending';
    statusIcon.innerHTML = '<i class="fas fa-clock"></i>';
    statusMessage.textContent = 'Payment Pending';

    if (selectedPaymentMethod === 'cash') {
        statusDetails.textContent = 'Please follow the instructions below to complete your payment.';

        paymentDetails.innerHTML = `
            <div class="cash-instructions">
                <h4><i class="fas fa-file-invoice-dollar"></i> Payment Reference: ${paymentData.reference}</h4>
                <p><strong>Amount to Pay:</strong> ${paymentData.currency || 'PHP'} ${paymentData.amount}</p>
                <p><strong>Valid Until:</strong> ${paymentData.expiry_date}</p>
                <div class="instructions-text">
                    <pre>${paymentData.instructions}</pre>
                </div>
            </div>
        `;

        paymentActions.innerHTML = `
            <button class="btn-primary" onclick="printPaymentSlip()">
                <i class="fas fa-print"></i> Print Payment Slip
            </button>
            <button class="btn-secondary" onclick="closeModal()">
                <i class="fas fa-home"></i> Close
            </button>
        `;

    } else if (selectedPaymentMethod === 'instapay') {
        statusDetails.textContent = 'Your bank transfer is being processed.';

        if (paymentData.qr_code) {
            paymentDetails.innerHTML = `
                <div class="qr-code-instructions">
                    <h4><i class="fas fa-qrcode"></i> Scan to Pay</h4>
                    <div class="qr-container" id="qr-container"></div>
                    <p>Scan this QR code with your bank's mobile app to pay.</p>
                    <p><strong>Reference:</strong> ${paymentData.payment_id}</p>
                </div>
            `;

            // Generate QR code (using a library in production)
            generateQRCode(paymentData.qr_code);
        }

        paymentActions.innerHTML = `
            <button class="btn-primary" onclick="checkPaymentStatus('${paymentData.payment_id}')">
                <i class="fas fa-sync-alt"></i> Check Status
            </button>
            <button class="btn-secondary" onclick="closeModal()">
                <i class="fas fa-home"></i> Close
            </button>
        `;
    }
}

// Show payment error
function showPaymentError(errorMessage) {
    const statusIcon = document.getElementById('status-icon');
    const statusMessage = document.getElementById('status-message');
    const statusDetails = document.getElementById('status-details');
    const paymentActions = document.getElementById('payment-actions');

    statusIcon.className = 'status-icon error';
    statusIcon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
    statusMessage.textContent = 'Payment Failed';
    statusDetails.textContent = errorMessage;

    paymentActions.innerHTML = `
        <button class="btn-primary" onclick="retryPayment()">
            <i class="fas fa-redo"></i> Try Again
        </button>
        <button class="btn-secondary" onclick="closeModal()">
            <i class="fas fa-home"></i> Return Home
        </button>
    `;
}

// Show QR code payment
function showQRCodePayment(paymentData) {
    showPaymentModal(
        'Scan QR Code to Pay',
        'Please scan the QR code with your bank\'s mobile app.',
        true
    );

    const paymentDetails = document.getElementById('payment-details');
    paymentDetails.innerHTML = `
        <div class="qr-payment">
            <h4><i class="fas fa-qrcode"></i> InstaPay QR Code</h4>
            <div class="qr-placeholder">
                <p>QR Code Image Here</p>
                <p><small>(In production, this would show the actual QR code)</small></p>
            </div>
            <div class="payment-info">
                <p><strong>Amount:</strong> PHP ${paymentData.amount}</p>
                <p><strong>Reference:</strong> ${paymentData.payment_id}</p>
                <p><strong>Expires:</strong> ${paymentData.expires_at}</p>
            </div>
        </div>
    `;
}

// Show payment slip
function showPaymentSlip(paymentData) {
    showPaymentModal(
        'Cash Payment Instructions',
        'Please follow the instructions below to complete your payment.',
        true
    );

    const paymentDetails = document.getElementById('payment-details');
    paymentDetails.innerHTML = `
        <div class="payment-slip">
            <div class="slip-header">
                <h3><i class="fas fa-receipt"></i> Payment Slip</h3>
                <p class="reference">Reference: ${paymentData.reference}</p>
            </div>
            <div class="slip-body">
                <div class="slip-section">
                    <h4>Customer Information</h4>
                    <p><strong>Name:</strong> ${document.getElementById('customer-name').value}</p>
                    <p><strong>Email:</strong> ${document.getElementById('customer-email').value}</p>
                    <p><strong>Date:</strong> ${new Date().toLocaleDateString()}</p>
                </div>
                <div class="slip-section">
                    <h4>Payment Details</h4>
                    <p><strong>Amount Due:</strong> ${paymentData.currency || 'PHP'} ${paymentData.amount}</p>
                    <p><strong>Description:</strong> ${paymentData.description || 'Payment'}</p>
                    <p><strong>Valid Until:</strong> ${paymentData.expiry_date}</p>
                </div>
                <div class="slip-section">
                    <h4>Payment Instructions</h4>
                    <div class="instructions">
                        <p>1. Visit any authorized payment center</p>
                        <p>2. Present this payment slip or reference number</p>
                        <p>3. Pay the exact amount due</p>
                        <p>4. Keep your receipt for reference</p>
                    </div>
                </div>
                <div class="slip-section">
                    <h4>Authorized Banks/Payment Centers</h4>
                    <ul>
                        <li>Bank of the Philippine Islands (BPI)</li>
                        <li>Banco de Oro (BDO)</li>
                        <li>7-Eleven (CLIQQ)</li>
                        <li>Bayad Center</li>
                        <li>Cebuana Lhuillier</li>
                    </ul>
                </div>
            </div>
            <div class="slip-footer">
                <p><i class="fas fa-info-circle"></i> This slip is valid for 3 business days</p>
            </div>
        </div>
    `;
}

// Generate QR code (simplified)
function generateQRCode(data) {
    // In production, use a QR code library like qrcode.js
    const container = document.getElementById('qr-container');
    container.innerHTML = `
        <div style="width: 200px; height: 200px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; margin: 0 auto; border-radius: 10px;">
            <div style="text-align: center;">
                <i class="fas fa-qrcode" style="font-size: 48px; color: #666;"></i>
                <p style="margin-top: 10px; font-size: 12px;">QR Code Placeholder</p>
            </div>
        </div>
    `;
}

// Check payment status
async function checkPaymentStatus(paymentId) {
    try {
        showPaymentModal('Checking Status...', 'Please wait while we check your payment status.');

        const response = await fetch(`${API_BASE_URL}/verify-payment.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                gateway: selectedPaymentMethod,
                payment_id: paymentId
            })
        });

        const result = await response.json();

        if (result.success) {
            if (result.data.status === 'completed') {
                showPaymentSuccess(result.data);
            } else if (result.data.status === 'pending') {
                showPaymentPending(result.data);
            } else {
                showPaymentError(`Payment status: ${result.data.status}`);
            }
        } else {
            showPaymentError(result.error);
        }

    } catch (error) {
        showPaymentError('Failed to check payment status');
    }
}

// Print receipt
function printReceipt() {
    const receiptContent = `
        <div style="font-family: Arial, sans-serif; padding: 20px;">
            <h2 style="text-align: center; color: #333;">Payment Receipt</h2>
            <hr>
            <p><strong>Date:</strong> ${new Date().toLocaleString()}</p>
            <p><strong>Transaction ID:</strong> ${transactionData.transaction_id || transactionData.reference}</p>
            <p><strong>Customer:</strong> ${document.getElementById('customer-name').value}</p>
            <p><strong>Email:</strong> ${document.getElementById('customer-email').value}</p>
            <hr>
            <p><strong>Amount:</strong> ${transactionData.currency || 'USD'} ${transactionData.amount}</p>
            <p><strong>Payment Method:</strong> ${selectedPaymentMethod.toUpperCase()}</p>
            <p><strong>Status:</strong> Completed</p>
            <hr>
            <p style="text-align: center; margin-top: 30px;">Thank you for your payment!</p>
            <p style="text-align: center; font-size: 12px; color: #666;">${SITE_NAME}</p>
        </div>
    `;

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Payment Receipt</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
                    @media print {
                        button { display: none; }
                    }
                </style>
            </head>
            <body>
                ${receiptContent}
                <br>
                <div style="text-align: center;">
                    <button onclick="window.print()" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer;">
                        Print Receipt
                    </button>
                    <button onclick="window.close()" style="padding: 10px 20px; background: #ccc; color: #333; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">
                        Close
                    </button>
                </div>
            </body>
        </html>
    `);
    printWindow.document.close();
}

// Print payment slip
function printPaymentSlip() {
    window.print();
}

// Retry payment
function retryPayment() {
    closeModal();
    processPayment();
}

// Simulate email confirmation
function simulateEmailConfirmation(paymentData) {
    console.log('Simulating email confirmation for:', paymentData);
    // In production, you would send an actual email here
    setTimeout(() => {
        console.log('Email sent to:', document.getElementById('customer-email').value);
    }, 1000);
}

// Show error message
function showError(message) {
    alert(message); // In production, use a better notification system
}

// Global functions for HTML onclick handlers
window.printReceipt = printReceipt;
window.printPaymentSlip = printPaymentSlip;
window.checkPaymentStatus = checkPaymentStatus;
window.retryPayment = retryPayment;
window.closeModal = closeModal;