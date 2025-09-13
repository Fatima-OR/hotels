<?php

class PaymentGateway {
    private $apiKey;
    private $apiSecret;
    private $apiUrl;
    private $environment;
    
    public function __construct() {
        // Configuration depuis les variables d'environnement ou config
        $this->environment = $_ENV['PAYMENT_ENVIRONMENT'] ?? 'sandbox';
        $this->apiKey = $_ENV['PAYMENT_API_KEY'] ?? 'test_key';
        $this->apiSecret = $_ENV['PAYMENT_API_SECRET'] ?? 'test_secret';
        
        // URLs selon l'environnement
        $this->apiUrl = $this->environment === 'production' 
            ? 'https://api.payment-gateway.com/v1/'
            : 'https://sandbox-api.payment-gateway.com/v1/';
    }
    
    public function processPayment($paymentData) {
        try {
            switch ($paymentData['method']) {
                case 'card':
                    return $this->processCardPayment($paymentData);
                case 'paypal':
                    return $this->processPayPalPayment($paymentData);
                default:
                    throw new Exception('Mode de paiement non supporté');
            }
        } catch (Exception $e) {
            error_log("Erreur PaymentGateway: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erreur lors du traitement du paiement: ' . $e->getMessage(),
                'reference' => null
            ];
        }
    }
    
    private function processCardPayment($data) {
        // Préparer les données pour l'API
        $payload = [
            'amount' => $data['amount'] * 100, // Convertir en centimes
            'currency' => $data['currency'],
            'payment_method' => [
                'type' => 'card',
                'card' => [
                    'number' => $data['card_number'],
                    'exp_month' => substr($data['expiry_date'], 0, 2),
                    'exp_year' => '20' . substr($data['expiry_date'], 3, 2),
                    'cvc' => $data['cvv'],
                    'name' => $data['card_name']
                ]
            ],
            'description' => $data['description'],
            'metadata' => [
                'booking_id' => $data['booking_id'],
                'customer_email' => $data['customer_email']
            ]
        ];
        
        // Appel à l'API de paiement
        $response = $this->makeApiCall('charges', $payload);
        
        if ($response && isset($response['id']) && $response['status'] === 'succeeded') {
            return [
                'success' => true,
                'reference' => $response['id'],
                'message' => 'Paiement traité avec succès',
                'gateway_response' => $response
            ];
        } else {
            $errorMessage = $response['error']['message'] ?? 'Erreur de paiement inconnue';
            return [
                'success' => false,
                'message' => $errorMessage,
                'reference' => null,
                'gateway_response' => $response
            ];
        }
    }
    
    private function processPayPalPayment($data) {
        // Simulation PayPal - à remplacer par l'intégration réelle
        $payload = [
            'intent' => 'sale',
            'payer' => [
                'payment_method' => 'paypal'
            ],
            'transactions' => [[
                'amount' => [
                    'total' => number_format($data['amount'], 2, '.', ''),
                    'currency' => $data['currency']
                ],
                'description' => $data['description']
            ]],
            'redirect_urls' => [
                'return_url' => 'https://votre-site.com/payment-success',
                'cancel_url' => 'https://votre-site.com/payment-cancel'
            ]
        ];
        
        // Pour PayPal, vous devriez rediriger vers PayPal
        // Ici c'est une simulation
        return [
            'success' => true,
            'reference' => 'PP_' . time() . '_' . rand(1000, 9999),
            'message' => 'Redirection vers PayPal',
            'redirect_url' => 'https://www.sandbox.paypal.com/...',
            'gateway_response' => $payload
        ];
    }
    
    private function makeApiCall($endpoint, $data) {
        $url = $this->apiUrl . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: AtlasHotels/1.0'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Erreur cURL: ' . $error);
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMsg = $decodedResponse['error']['message'] ?? 'Erreur API inconnue';
            throw new Exception('Erreur API (' . $httpCode . '): ' . $errorMsg);
        }
        
        return $decodedResponse;
    }
    
    public function refundPayment($paymentReference, $amount = null) {
        try {
            $payload = [];
            if ($amount !== null) {
                $payload['amount'] = $amount * 100; // Convertir en centimes
            }
            
            $response = $this->makeApiCall('charges/' . $paymentReference . '/refunds', $payload);
            
            return [
                'success' => true,
                'refund_id' => $response['id'],
                'message' => 'Remboursement traité avec succès'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur lors du remboursement: ' . $e->getMessage()
            ];
        }
    }
    
    public function getPaymentStatus($paymentReference) {
        try {
            $response = $this->makeApiCall('charges/' . $paymentReference, [], 'GET');
            
            return [
                'success' => true,
                'status' => $response['status'],
                'amount' => $response['amount'] / 100,
                'currency' => $response['currency']
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur lors de la vérification: ' . $e->getMessage()
            ];
        }
    }
}