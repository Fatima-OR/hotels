<?php
session_start();

// Configuration de la base de données
try {
    $pdo = new PDO("mysql:host=localhost;dbname=atlas_hotels;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// Configuration email pour XAMPP
ini_set('SMTP', 'localhost');
ini_set('smtp_port', '25');
ini_set('sendmail_from', 'noreply@atlashotels.ma');

// Classe de sécurité
class Security {
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    public static function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public static function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeInput'], $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
    
    public static function prepareAndExecute($pdo, $sql, $params = []) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Erreur SQL: " . $e->getMessage());
            throw new Exception("Une erreur est survenue lors de l'exécution de la requête");
        }
    }
    
    public static function logSecurityEvent($pdo, $userId, $action, $details = [], $severity = 'low') {
        try {
            $stmt = Security::prepareAndExecute($pdo, 
                "INSERT INTO security_logs (user_id, ip_address, user_agent, action, details, severity) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $action,
                    json_encode($details),
                    $severity
                ]
            );
        } catch (Exception $e) {
            error_log("Erreur lors de l'enregistrement du log de sécurité: " . $e->getMessage());
        }
    }
}

// Classe de service email
class EmailService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function sendBookingConfirmation($bookingId) {
        try {
            // Récupérer les détails de la réservation
            $bookingDetails = $this->getBookingDetails($bookingId);
            
            if (!$bookingDetails) {
                throw new Exception("Réservation non trouvée");
            }
            
            // Générer le contenu de l'email
            $emailContent = $this->generateConfirmationEmail($bookingDetails);
            
            // Configuration des headers
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: Atlas Hotels <noreply@atlashotels.ma>',
                'Reply-To: contact@atlashotels.ma',
                'X-Mailer: PHP/' . phpversion(),
                'X-Priority: 1'
            ];
            
            // Tentative d'envoi d'email
            $sent = @mail(
                $bookingDetails['user_email'],
                'Confirmation de réservation - Atlas Hotels',
                $emailContent['html'],
                implode("\r\n", $headers)
            );
            
            // Log pour debug
            error_log("Email envoi pour booking $bookingId: " . ($sent ? 'SUCCESS' : 'FAILED'));
            
            if ($sent) {
                $this->logEmail($bookingId, 'confirmation', $bookingDetails['user_email'], 'sent');
                
                // Mettre à jour le statut dans la base
                try {
                    Security::prepareAndExecute($this->pdo,
                        "UPDATE bookings SET email_sent = 1 WHERE id = ?",
                        [$bookingId]
                    );
                } catch (Exception $e) {
                    // Ignorer l'erreur si la table n'existe pas
                    error_log("Table bookings non trouvée: " . $e->getMessage());
                }
                
                return true;
            } else {
                $this->logEmail($bookingId, 'confirmation', $bookingDetails['user_email'], 'failed');
                return false;
            }
            
        } catch (Exception $e) {
            error_log("Erreur envoi email: " . $e->getMessage());
            return false;
        }
    }
    
    private function getBookingDetails($bookingId) {
        // Simuler les détails de réservation si pas de vraie base
        return [
            'id' => $bookingId,
            'user_email' => 'client@example.com',
            'user_name' => 'Ahmed Benali',
            'hotel_name' => 'Atlas Hotel Marrakech',
            'location' => 'Avenue Mohammed VI',
            'city' => 'Marrakech',
            'room_type_name' => 'Suite Deluxe avec vue sur l\'Atlas',
            'check_in' => '2024-07-15',
            'check_out' => '2024-07-18',
            'guests' => 2,
            'total_price' => 4500
        ];
    }
    
    private function generateConfirmationEmail($booking) {
        $confirmationCode = $this->generateConfirmationCode($booking['id']);
        
        $checkInDate = date('d/m/Y', strtotime($booking['check_in']));
        $checkOutDate = date('d/m/Y', strtotime($booking['check_out']));
        $nights = (strtotime($booking['check_out']) - strtotime($booking['check_in'])) / (60 * 60 * 24);
        
        $html = $this->getEmailTemplate($booking, $confirmationCode, $checkInDate, $checkOutDate, $nights);
        
        return ['html' => $html];
    }
    
    private function generateConfirmationCode($bookingId) {
        return 'ATL' . str_pad($bookingId, 6, '0', STR_PAD_LEFT) . strtoupper(substr(md5(time() . $bookingId), 0, 4));
    }
    
    private function getEmailTemplate($booking, $confirmationCode, $checkInDate, $checkOutDate, $nights) {
        return "
        <!DOCTYPE html>
        <html lang='fr'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Confirmation de réservation</title>
            <style>
                body { font-family: 'Arial', sans-serif; margin: 0; padding: 0; background-color: #f8f9fa; }
                .container { max-width: 600px; margin: 0 auto; background: white; }
                .header { background: linear-gradient(135deg, #D4AF37 0%, #B8860B 100%); padding: 2rem; text-align: center; }
                .header h1 { color: white; margin: 0; font-size: 2rem; }
                .content { padding: 2rem; }
                .confirmation-box { background: #f8f9fa; border-left: 4px solid #D4AF37; padding: 1rem; margin: 1rem 0; }
                .hotel-info { display: flex; gap: 1rem; margin: 1.5rem 0; }
                .booking-details { background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin: 1rem 0; }
                .detail-row { display: flex; justify-content: space-between; margin: 0.5rem 0; }
                .total { font-weight: bold; font-size: 1.2rem; color: #D4AF37; border-top: 1px solid #ddd; padding-top: 0.5rem; }
                .footer { background: #2c3e50; color: white; padding: 1.5rem; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🏨 Atlas Hotels</h1>
                    <p style='color: white; margin: 0.5rem 0 0 0;'>Confirmation de réservation</p>
                </div>
                
                <div class='content'>
                    <h2 style='color: #2c3e50;'>Bonjour " . htmlspecialchars($booking['user_name']) . ",</h2>
                    <p>Nous avons le plaisir de confirmer votre réservation. Voici les détails :</p>
                    
                    <div class='confirmation-box'>
                        <h3 style='margin: 0; color: #D4AF37;'>Code de confirmation</h3>
                        <h2 style='margin: 0.5rem 0 0 0; color: #2c3e50; font-size: 1.5rem;'>" . $confirmationCode . "</h2>
                    </div>
                    
                    <div class='hotel-info'>
                        <div style='width: 120px; height: 80px; background: #D4AF37; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem;'>🏨</div>
                        <div>
                            <h3 style='margin: 0; color: #D4AF37;'>" . htmlspecialchars($booking['hotel_name']) . "</h3>
                            <p style='margin: 0.5rem 0; color: #666;'>" . htmlspecialchars($booking['location']) . ", " . htmlspecialchars($booking['city']) . "</p>
                            <p style='margin: 0; color: #666;'>" . htmlspecialchars($booking['room_type_name']) . "</p>
                        </div>
                    </div>
                    
                    <div class='booking-details'>
                        <h3 style='margin: 0 0 1rem 0; color: #2c3e50;'>Détails de la réservation</h3>
                        <div class='detail-row'>
                            <span>Arrivée :</span>
                            <strong>" . $checkInDate . " (à partir de 15h00)</strong>
                        </div>
                        <div class='detail-row'>
                            <span>Départ :</span>
                            <strong>" . $checkOutDate . " (avant 12h00)</strong>
                        </div>
                        <div class='detail-row'>
                            <span>Durée :</span>
                            <strong>" . $nights . " nuit(s)</strong>
                        </div>
                        <div class='detail-row'>
                            <span>Nombre de personnes :</span>
                            <strong>" . $booking['guests'] . " personne(s)</strong>
                        </div>
                        <div class='detail-row total'>
                            <span>Total payé :</span>
                            <strong>" . number_format($booking['total_price'], 0, ',', ' ') . " MAD</strong>
                        </div>
                    </div>
                    
                    <div style='background: #e8f5e8; padding: 1rem; border-radius: 8px; margin: 1rem 0;'>
                        <h4 style='margin: 0 0 0.5rem 0; color: #2c5530;'>📋 Informations importantes</h4>
                        <ul style='margin: 0; padding-left: 1.5rem; color: #2c5530;'>
                            <li>Présentez-vous à la réception avec une pièce d'identité</li>
                            <li>Check-in : 15h00 | Check-out : 12h00</li>
                            <li>Annulation gratuite jusqu'à 24h avant l'arrivée</li>
                            <li>Wifi gratuit dans tout l'établissement</li>
                        </ul>
                    </div>
                    
                    <p>Pour toute question, contactez-nous :</p>
                    <p>📞 <strong>+212 6 00 00  00 00</strong><br>
                    📧 <strong>contact@atlashotels.ma</strong></p>
                    
                    <p>Nous vous remercions de votre confiance !</p>
                    <p><strong>L'équipe Atlas Hotels</strong></p>
                </div>
                
                <div class='footer'>
                    <p style='margin: 0;'>© 2024 Atlas Hotels - Tous droits réservés</p>
                </div>
            </div>
        </body>
        </html>";
    }
    
    private function logEmail($bookingId, $type, $email, $status) {
        try {
            Security::prepareAndExecute($this->pdo,
                "INSERT INTO email_logs (booking_id, email_type, recipient_email, subject, status) 
                 VALUES (?, ?, ?, ?, ?)",
                [
                    $bookingId,
                    $type,
                    $email,
                    'Confirmation de réservation - Atlas Hotels',
                    $status
                ]
            );
        } catch (Exception $e) {
            // Ignorer si la table n'existe pas
            error_log("Table email_logs non trouvée: " . $e->getMessage());
        }
    }
}

// Classe de gestion des paiements
class PaymentProcessor {
    private $pdo;
    private $emailService;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->emailService = new EmailService($pdo);
    }
    
    public function validateCardNumber($number) {
        $number = preg_replace('/\D/', '', $number);
        $length = strlen($number);
        
        if ($length < 13 || $length > 19) {
            return false;
        }
        
        // Algorithme de Luhn
        $sum = 0;
        $alternate = false;
        
        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = intval($number[$i]);
            
            if ($alternate) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit = ($digit % 10) + 1;
                }
            }
            
            $sum += $digit;
            $alternate = !$alternate;
        }
        
        return ($sum % 10) === 0;
    }
    
    public function processPayment($bookingId, $paymentData) {
        try {
            $this->pdo->beginTransaction();
            
            // Créer une transaction de paiement
            $transactionId = 'TXN_' . time() . '_' . rand(1000, 9999);
            
            try {
                $stmt = Security::prepareAndExecute($this->pdo,
                    "INSERT INTO payment_transactions (booking_id, amount, currency, payment_method, transaction_id, status) 
                     VALUES (?, ?, ?, ?, ?, 'pending')",
                    [
                        $bookingId,
                        $paymentData['amount'],
                        $paymentData['currency'] ?? 'MAD',
                        $paymentData['method'],
                        $transactionId
                    ]
                );
            } catch (Exception $e) {
                // Ignorer si la table n'existe pas
                error_log("Table payment_transactions non trouvée: " . $e->getMessage());
            }
            
            // Simuler le traitement du paiement
            $paymentResult = $this->simulatePaymentGateway($paymentData);
            
            if ($paymentResult['success']) {
                // Mettre à jour la transaction
                try {
                    Security::prepareAndExecute($this->pdo,
                        "UPDATE payment_transactions 
                         SET status = 'completed', gateway_transaction_id = ?, processed_at = NOW() 
                         WHERE transaction_id = ?",
                        [$paymentResult['gateway_id'], $transactionId]
                    );
                } catch (Exception $e) {
                    error_log("Erreur mise à jour transaction: " . $e->getMessage());
                }
                
                // Envoyer l'email de confirmation
                $emailSent = $this->emailService->sendBookingConfirmation($bookingId);
                
                $this->pdo->commit();
                return [
                    'success' => true, 
                    'transaction_id' => $transactionId,
                    'email_sent' => $emailSent
                ];
                
            } else {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => $paymentResult['message']];
            }
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Erreur de traitement du paiement: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur lors du traitement du paiement'];
        }
    }
    
    private function simulatePaymentGateway($paymentData) {
        // Simulation d'une passerelle de paiement
        usleep(500000); // Simuler un délai
        
        // Simuler un taux de succès de 95%
        $success = (rand(1, 100) <= 95);
        
        if ($success) {
            return [
                'success' => true,
                'gateway_id' => 'PAY_' . time() . '_' . rand(1000, 9999),
                'message' => 'Paiement traité avec succès'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Erreur lors du traitement du paiement. Veuillez réessayer.'
            ];
        }
    }
}

// Simuler des données de réservation pour les tests
if (!isset($_SESSION['booking_data'])) {
    $_SESSION['booking_data'] = [
        'booking_id' => rand(1000, 9999),
        'hotel_name' => 'Atlas Hotel Marrakech',
        'hotel_image' => 'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&h=300&fit=crop',
        'room_type_name' => 'Suite Deluxe avec vue sur l\'Atlas',
        'check_in' => '2024-07-15',
        'check_out' => '2024-07-18',
        'guests' => 2,
        'nights' => 3,
        'total_price' => 4500
    ];
}

// Simuler un utilisateur connecté pour les tests
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

$userId = $_SESSION['user_id'];
$bookingData = $_SESSION['booking_data'];
$message = '';
$messageType = '';

// Traitement du paiement
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Token de sécurité invalide');
        }

        $paymentMethod = Security::sanitizeInput($_POST['payment_method']);
        $cardNumber = Security::sanitizeInput($_POST['card_number'] ?? '');
        $expiryDate = Security::sanitizeInput($_POST['expiry_date'] ?? '');
        $cvv = Security::sanitizeInput($_POST['cvv'] ?? '');
        $cardName = Security::sanitizeInput($_POST['card_name'] ?? '');

        // Validation des données
        if (empty($paymentMethod)) {
            throw new Exception('Veuillez sélectionner un mode de paiement');
        }

        if ($paymentMethod === 'card') {
            if (empty($cardNumber) || empty($expiryDate) || empty($cvv) || empty($cardName)) {
                throw new Exception('Veuillez remplir tous les champs de la carte');
            }
            
            $processor = new PaymentProcessor($pdo);
            if (!$processor->validateCardNumber($cardNumber)) {
                throw new Exception('Numéro de carte invalide');
            }
        }

        // Traiter le paiement
        $processor = new PaymentProcessor($pdo);
        $paymentResult = $processor->processPayment($bookingData['booking_id'], [
            'amount' => $bookingData['total_price'],
            'currency' => 'MAD',
            'method' => $paymentMethod,
            'card_number' => $cardNumber,
            'expiry_date' => $expiryDate,
            'cvv' => $cvv,
            'card_name' => $cardName
        ]);

        if ($paymentResult['success']) {
            // Log de sécurité
            Security::logSecurityEvent($pdo, $userId, 'payment_completed', [
                'booking_id' => $bookingData['booking_id'],
                'amount' => $bookingData['total_price'],
                'method' => $paymentMethod,
                'email_sent' => $paymentResult['email_sent']
            ]);
            
            // Rediriger vers la confirmation
            $confirmationUrl = '?success=1&booking=' . $bookingData['booking_id'] . '&email=' . ($paymentResult['email_sent'] ? '1' : '0');
            header('Location: ' . $confirmationUrl);
            exit;
            
        } else {
            throw new Exception($paymentResult['message']);
        }

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
        
        // Log de sécurité pour les échecs
        Security::logSecurityEvent($pdo, $userId, 'payment_failed', [
            'booking_id' => $bookingData['booking_id'] ?? null,
            'error' => $e->getMessage()
        ], 'medium');
        
        error_log("Erreur de paiement pour l'utilisateur $userId: " . $e->getMessage());
    }
}

// Affichage de la page de confirmation si succès
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $bookingId = $_GET['booking'] ?? null;
    $emailSent = $_GET['email'] ?? '0';
    
    $booking = [
        'id' => $bookingId,
        'confirmation_code' => 'ATL' . str_pad($bookingId, 6, '0', STR_PAD_LEFT) . 'A1B2',
        'hotel_name' => $bookingData['hotel_name'],
        'hotel_image' => $bookingData['hotel_image'],
        'location' => 'Avenue Mohammed VI',
        'city' => 'Marrakech',
        'room_type_name' => $bookingData['room_type_name'],
        'check_in' => $bookingData['check_in'],
        'check_out' => $bookingData['check_out'],
        'guests' => $bookingData['guests'],
        'total_price' => $bookingData['total_price'],
        'user_email' => 'client@example.com',
        'user_name' => 'Ahmed Benali'
    ];
    
    $checkInDate = date('d/m/Y', strtotime($booking['check_in']));
    $checkOutDate = date('d/m/Y', strtotime($booking['check_out']));
    $nights = (strtotime($booking['check_out']) - strtotime($booking['check_in'])) / (60 * 60 * 24);
    
    // Nettoyer les données de session
    unset($_SESSION['booking_data']);
    ?>
    
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Confirmation de réservation - Atlas Hotels</title>
        <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            :root {
                --primary-gold: #D4AF37;
                --secondary-gold: #B8860B;
                --dark-gold: #996F00;
                --gradient-luxury: linear-gradient(135deg, #D4AF37 0%, #B8860B 50%, #996F00 100%);
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Inter', sans-serif;
                background: linear-gradient(135deg, #0F1419 0%, #1A1A2E 100%);
                min-height: 100vh;
                color: white;
                padding: 2rem 0;
            }

            .container {
                max-width: 800px;
                margin: 0 auto;
                padding: 0 2rem;
            }

            .confirmation-header {
                text-align: center;
                margin-bottom: 3rem;
            }

            .success-icon {
                width: 80px;
                height: 80px;
                background: var(--gradient-luxury);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 1rem;
                font-size: 2rem;
                color: white;
            }

            .confirmation-title {
                font-family: 'Playfair Display', serif;
                font-size: 2.5rem;
                font-weight: 700;
                background: var(--gradient-luxury);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
                margin-bottom: 1rem;
            }

            .confirmation-card {
                background: rgba(255, 255, 255, 0.05);
                backdrop-filter: blur(20px);
                border: 1px solid rgba(212, 175, 55, 0.2);
                border-radius: 20px;
                padding: 2rem;
                margin-bottom: 2rem;
            }

            .confirmation-code {
                background: rgba(212, 175, 55, 0.1);
                border: 1px solid rgba(212, 175, 55, 0.3);
                border-radius: 10px;
                padding: 1.5rem;
                text-align: center;
                margin-bottom: 2rem;
            }

            .confirmation-code h3 {
                color: var(--primary-gold);
                margin-bottom: 0.5rem;
            }

            .confirmation-code .code {
                font-size: 1.5rem;
                font-weight: 700;
                color: white;
                letter-spacing: 2px;
            }

            .hotel-info {
                display: flex;
                gap: 1.5rem;
                margin-bottom: 2rem;
                align-items: center;
            }

            .hotel-image {
                width: 120px;
                height: 80px;
                object-fit: cover;
                border-radius: 10px;
                border: 2px solid var(--primary-gold);
            }

            .hotel-icon {
                width: 120px;
                height: 80px;
                background: var(--gradient-luxury);
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 2rem;
                color: white;
            }

            .hotel-details h2 {
                color: var(--primary-gold);
                margin-bottom: 0.5rem;
            }

            .booking-details {
                display: grid;
                gap: 1rem;
                margin-bottom: 2rem;
            }

            .detail-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.75rem 0;
                border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            }

            .detail-row:last-child {
                border-bottom: none;
                font-weight: 600;
                font-size: 1.1rem;
                color: var(--primary-gold);
            }

            .email-status {
                padding: 1rem;
                border-radius: 10px;
                margin-bottom: 2rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .email-success {
                background: rgba(34, 197, 94, 0.1);
                border: 1px solid rgba(34, 197, 94, 0.3);
                color: #22c55e;
            }

            .email-warning {
                background: rgba(245, 158, 11, 0.1);
                border: 1px solid rgba(245, 158, 11, 0.3);
                color: #f59e0b;
            }

            .important-info {
                background: rgba(59, 130, 246, 0.1);
                border: 1px solid rgba(59, 130, 246, 0.3);
                border-radius: 10px;
                padding: 1.5rem;
                margin-bottom: 2rem;
            }

            .important-info h4 {
                color: #3b82f6;
                margin-bottom: 1rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .important-info ul {
                list-style: none;
                padding: 0;
            }

            .important-info li {
                padding: 0.5rem 0;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .action-buttons {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1rem;
                margin-top: 2rem;
            }

            .btn {
                padding: 1rem 2rem;
                border: none;
                border-radius: 50px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 1px;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none;
                text-align: center;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
            }

            .btn-primary {
                background: var(--gradient-luxury);
                color: white;
            }

            .btn-secondary {
                background: transparent;
                color: var(--primary-gold);
                border: 2px solid var(--primary-gold);
            }

            .btn:hover {
                transform: translateY(-3px);
                box-shadow: 0 15px 40px rgba(212, 175, 55, 0.4);
            }

            @media (max-width: 768px) {
                .hotel-info {
                    flex-direction: column;
                    text-align: center;
                }

                .detail-row {
                    flex-direction: column;
                    gap: 0.5rem;
                    text-align: center;
                }

                .action-buttons {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="confirmation-header">
                <div class="success-icon">
                    <i class="fas fa-check"></i>
                </div>
                <h1 class="confirmation-title">Réservation Confirmée !</h1>
                <p>Votre paiement a été traité avec succès</p>
            </div>

            <div class="confirmation-card">
                <?php if ($emailSent == '1'): ?>
                    <div class="email-status email-success">
                        <i class="fas fa-envelope-check"></i>
                        <span>Email de confirmation envoyé à <?= htmlspecialchars($booking['user_email']) ?></span>
                    </div>
                <?php else: ?>

                <?php endif; ?>

                <div class="confirmation-code">
                    <h3>Code de confirmation</h3>
                    <div class="code"><?= htmlspecialchars($booking['confirmation_code']) ?></div>
                </div>

                <div class="hotel-info">
                    <?php if (!empty($booking['hotel_image']) && filter_var($booking['hotel_image'], FILTER_VALIDATE_URL)): ?>
                        <img src="<?= htmlspecialchars($booking['hotel_image']) ?>" alt="<?= htmlspecialchars($booking['hotel_name']) ?>" class="hotel-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="hotel-icon" style="display: none;">🏨</div>
                    <?php else: ?>
                        <div class="hotel-icon">🏨</div>
                    <?php endif; ?>
                    <div class="hotel-details">
                        <h2><?= htmlspecialchars($booking['hotel_name']) ?></h2>
                        <p style="color: #ccc; margin-bottom: 0.5rem;"><?= htmlspecialchars($booking['location']) ?>, <?= htmlspecialchars($booking['city']) ?></p>
                        <p style="color: #ccc;"><?= htmlspecialchars($booking['room_type_name']) ?></p>
                    </div>
                </div>

                <div class="booking-details">
                    <div class="detail-row">
                        <span>Arrivée :</span>
                        <strong><?= $checkInDate ?> (à partir de 15h00)</strong>
                    </div>
                    <div class="detail-row">
                        <span>Départ :</span>
                        <strong><?= $checkOutDate ?> (avant 12h00)</strong>
                    </div>
                    <div class="detail-row">
                        <span>Durée :</span>
                        <strong><?= $nights ?> nuit(s)</strong>
                    </div>
                    <div class="detail-row">
                        <span>Nombre de personnes :</span>
                        <strong><?= $booking['guests'] ?> personne(s)</strong>
                    </div>
                    <div class="detail-row">
                        <span>Statut :</span>
                        <strong style="color: #22c55e;">Confirmée et payée</strong>
                    </div>
                    <div class="detail-row">
                        <span>Total payé :</span>
                        <strong><?= number_format($booking['total_price'], 0, ',', ' ') ?> MAD</strong>
                    </div>
                </div>

                <div class="important-info">
                    <h4>
                        <i class="fas fa-info-circle"></i>
                        Informations importantes
                    </h4>
                    <ul>
                        <li>
                            <i class="fas fa-id-card" style="color: #3b82f6;"></i>
                            Présentez-vous à la réception avec une pièce d'identité valide
                        </li>
                        <li>
                            <i class="fas fa-clock" style="color: #3b82f6;"></i>
                            Check-in : 15h00 | Check-out : 12h00
                        </li>
                        <li>
                            <i class="fas fa-times-circle" style="color: #3b82f6;"></i>
                            Annulation gratuite jusqu'à 24h avant l'arrivée
                        </li>
                        <li>
                            <i class="fas fa-wifi" style="color: #3b82f6;"></i>
                            Wifi gratuit dans tout l'établissement
                        </li>
                        <li>
                            <i class="fas fa-phone" style="color: #3b82f6;"></i>
                            Contact hôtel : +212 6 00 00  00 00
                        </li>
                    </ul>
                </div>

                <div class="action-buttons">
                    <a href="payment.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        Nouvelle réservation
                    </a>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-home"></i>
                        Retour à l'accueil
                    </a>
                </div>
            </div>
        </div>

        <script>
            // Animation d'entrée
            document.addEventListener('DOMContentLoaded', function() {
                const card = document.querySelector('.confirmation-card');
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 100);
            });
        </script>
    </body>
    </html>
    
    <?php
    exit;
}

$csrfToken = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paiement Sécurisé - Atlas Hotels</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-gold: #D4AF37;
            --secondary-gold: #B8860B;
            --dark-gold: #996F00;
            --gradient-luxury: linear-gradient(135deg, #D4AF37 0%, #B8860B 50%, #996F00 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0F1419 0%, #1A1A2E 100%);
            min-height: 100vh;
            color: white;
            padding: 2rem 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .payment-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .payment-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.5rem;
            font-weight: 700;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
        }

        .payment-content {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 3rem;
            align-items: start;
        }

        .payment-form {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 20px;
            padding: 2rem;
        }

        .booking-summary {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 20px;
            padding: 2rem;
            height: fit-content;
            position: sticky;
            top: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            color: var(--primary-gold);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-input {
            width: 100%;
            padding: 1rem;
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.3);
            color: white;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }

        .payment-methods {
            display: grid;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .payment-method {
            border: 2px solid rgba(212, 175, 55, 0.3);
            border-radius: 10px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .payment-method:hover {
            border-color: var(--primary-gold);
            background: rgba(212, 175, 55, 0.1);
        }

        .payment-method.selected {
            border-color: var(--primary-gold);
            background: rgba(212, 175, 55, 0.15);
        }

        .payment-method input[type="radio"] {
            display: none;
        }

        .card-form {
            display: none;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(212, 175, 55, 0.2);
        }

        .card-form.active {
            display: block;
        }

        .card-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .btn-primary {
            width: 100%;
            padding: 1rem 2rem;
            background: var(--gradient-luxury);
            color: white;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(212, 175, 55, 0.4);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .security-info {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .security-info h4 {
            color: #22c55e;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .email-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .email-info h4 {
            color: #3b82f6;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        @media (max-width: 768px) {
            .payment-content {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .booking-summary {
                position: static;
            }

            .card-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="payment-header">
            <h1 class="payment-title">Paiement Sécurisé</h1>
            <p>Finalisez votre réservation en toute sécurité</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="fas fa-<?= $messageType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="payment-content">
            <div class="payment-form">
                <h2 style="color: var(--primary-gold); margin-bottom: 1.5rem;">Informations de Paiement</h2>
                
                <form method="POST" id="payment-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Mode de paiement</label>
                        <div class="payment-methods">
                            <div class="payment-method" data-method="card">
                                <input type="radio" name="payment_method" value="card" id="card" required>
                                <div style="font-size: 1.5rem; color: var(--primary-gold);">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div>
                                    <h4>Carte bancaire</h4>
                                    <p style="color: #ccc; font-size: 0.9rem;">Visa, Mastercard, American Express</p>
                                </div>
                            </div>
                            
                            <div class="payment-method" data-method="paypal">
                                <input type="radio" name="payment_method" value="paypal" id="paypal" required>
                                <div style="font-size: 1.5rem; color: var(--primary-gold);">
                                    <i class="fab fa-paypal"></i>
                                </div>
                                <div>
                                    <h4>PayPal</h4>
                                    <p style="color: #ccc; font-size: 0.9rem;">Paiement sécurisé via PayPal</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-form" id="card-form">
                        <div class="form-group">
                            <label class="form-label">Numéro de carte</label>
                            <input type="text" name="card_number" class="form-input" 
                                   placeholder="1234 5678 9012 3456" maxlength="19" autocomplete="cc-number">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Nom sur la carte</label>
                            <input type="text" name="card_name" class="form-input" 
                                   placeholder="Nom complet" autocomplete="cc-name">
                        </div>
                        
                        <div class="card-row">
                            <div class="form-group">
                                <label class="form-label">Date d'expiration</label>
                                <input type="text" name="expiry_date" class="form-input" 
                                       placeholder="MM/AA" maxlength="5" autocomplete="cc-exp">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">CVV</label>
                                <input type="text" name="cvv" class="form-input" 
                                       placeholder="123" maxlength="4" autocomplete="cc-csc">
                            </div>
                        </div>
                    </div>

                    <div class="email-info">
                        <h4>
                            <i class="fas fa-envelope"></i>
                            Confirmation par email
                        </h4>
                        <p style="color: #ccc; font-size: 0.9rem;">Vous recevrez automatiquement un email de confirmation avec tous les détails de votre réservation et votre code de confirmation.</p>
                    </div>

                    <div class="security-info">
                        <h4>
                            <i class="fas fa-shield-alt"></i>
                            Paiement 100% sécurisé
                        </h4>
                        <p style="color: #ccc; font-size: 0.9rem;">Vos informations de paiement sont protégées par un cryptage SSL 256 bits. Nous ne stockons aucune donnée de carte bancaire.</p>
                    </div>

                    <button type="submit" class="btn-primary" id="submit-btn">
                        <i class="fas fa-lock"></i>
                        Finaliser le paiement
                    </button>
                </form>
            </div>

            <div class="booking-summary">
                <h2 style="color: var(--primary-gold); margin-bottom: 1.5rem;">Récapitulatif</h2>
                
                <div style="margin-bottom: 1.5rem;">
                    <div style="font-family: 'Playfair Display', serif; font-size: 1.2rem; font-weight: 600; color: var(--primary-gold); margin-bottom: 0.5rem;">
                        <?= htmlspecialchars($bookingData['hotel_name']) ?>
                    </div>
                    <div style="color: #ccc; font-size: 0.9rem; line-height: 1.4;">
                        <?= htmlspecialchars($bookingData['room_type_name']) ?><br>
                        Du <?= date('d/m/Y', strtotime($bookingData['check_in'])) ?> 
                        au <?= date('d/m/Y', strtotime($bookingData['check_out'])) ?><br>
                        <?= $bookingData['guests'] ?> personne(s) • <?= $bookingData['nights'] ?> nuit(s)
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid rgba(212, 175, 55, 0.2);">
                    <span>Sous-total</span>
                    <span><?= number_format($bookingData['total_price'], 0, ',', ' ') ?> MAD</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid rgba(212, 175, 55, 0.2);">
                    <span>Taxes et frais</span>
                    <span>Inclus</span>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; font-weight: 600; font-size: 1.1rem; color: var(--primary-gold);">
                    <span>Total à payer</span>
                    <span><?= number_format($bookingData['total_price'], 0, ',', ' ') ?> MAD</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Gestion de la sélection du mode de paiement
        document.querySelectorAll('.payment-method').forEach(method => {
            method.addEventListener('click', function() {
                document.querySelectorAll('.payment-method').forEach(m => {
                    m.classList.remove('selected');
                });
                
                this.classList.add('selected');
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                }
                
                const cardForm = document.getElementById('card-form');
                if (this.dataset.method === 'card') {
                    cardForm.classList.add('active');
                } else {
                    cardForm.classList.remove('active');
                }
            });
        });

        // Formatage du numéro de carte
        const cardNumberInput = document.querySelector('input[name="card_number"]');
        if (cardNumberInput) {
            cardNumberInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                value = value.replace(/(\d{4})(?=\d)/g, '$1 ');
                e.target.value = value;
            });
        }

        // Formatage de la date d'expiration
        const expiryInput = document.querySelector('input[name="expiry_date"]');
        if (expiryInput) {
            expiryInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length >= 2) {
                    value = value.substring(0, 2) + '/' + value.substring(2, 4);
                }
                e.target.value = value;
            });
        }

        // Validation du CVV
        const cvvInput = document.querySelector('input[name="cvv"]');
        if (cvvInput) {
            cvvInput.addEventListener('input', function(e) {
                e.target.value = e.target.value.replace(/\D/g, '');
            });
        }

        // Validation du formulaire
        document.getElementById('payment-form').addEventListener('submit', function(e) {
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
            
            if (!paymentMethod) {
                e.preventDefault();
                alert('Veuillez sélectionner un mode de paiement');
                return;
            }

            if (paymentMethod.value === 'card') {
                const cardNumber = document.querySelector('input[name="card_number"]').value.replace(/\s/g, '');
                const cardName = document.querySelector('input[name="card_name"]').value.trim();
                const expiryDate = document.querySelector('input[name="expiry_date"]').value;
                const cvv = document.querySelector('input[name="cvv"]').value;

                if (!cardNumber || !cardName || !expiryDate || !cvv) {
                    e.preventDefault();
                    alert('Veuillez remplir tous les champs de la carte');
                    return;
                }
            }

            // Afficher l'indicateur de chargement
            const submitBtn = document.getElementById('submit-btn');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Traitement en cours...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>
