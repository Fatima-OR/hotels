<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config/database.php';
require_once 'includes/Security.php';
require_once 'classes/EmailService.php';
require_once 'classes/PaymentGateway.php';

// Vérifier la connexion de l'utilisateur
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Vérifier que les données de réservation existent
if (!isset($_SESSION['booking_data'])) {
    header('Location: index.php');
    exit;
}

$database = new Database();
$pdo = $database->getConnection();
$bookingData = $_SESSION['booking_data'];
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Vérification du token CSRF
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Token de sécurité invalide.');
        }

        // Sanitize les inputs
        $paymentMethod = Security::sanitizeInput($_POST['payment_method']);
        $cardNumber = Security::sanitizeInput($_POST['card_number'] ?? '');
        $expiryDate = Security::sanitizeInput($_POST['expiry_date'] ?? '');
        $cvv = Security::sanitizeInput($_POST['cvv'] ?? '');
        $cardName = Security::sanitizeInput($_POST['card_name'] ?? '');

        if (empty($paymentMethod)) {
            throw new Exception('Veuillez sélectionner un mode de paiement.');
        }

        if ($paymentMethod === 'card') {
            if (empty($cardNumber) || empty($expiryDate) || empty($cvv) || empty($cardName)) {
                throw new Exception('Veuillez remplir tous les champs de la carte.');
            }

            if (!validateCardNumber($cardNumber)) {
                throw new Exception('Numéro de carte invalide.');
            }
        }

        // Traitement de paiement simulé
        $paymentGateway = new PaymentGateway();
        $paymentResult = $paymentGateway->processPayment([
            'method' => $paymentMethod,
            'amount' => $bookingData['total_price'],
            'currency' => 'MAD',
            'card_number' => $cardNumber,
            'expiry_date' => $expiryDate,
            'cvv' => $cvv,
            'card_name' => $cardName,
            'customer_email' => $_SESSION['user_email'],
            'booking_id' => $bookingData['booking_id']
        ]);

        if (!$paymentResult['success']) {
            throw new Exception($paymentResult['message'] ?? 'Paiement refusé.');
        }

        // Mise à jour de la base de données
        $stmt = Security::prepareAndExecute($pdo, "
            UPDATE bookings 
            SET status = 'confirmed',
                payment_status = 'paid',
                payment_method = ?,
                payment_reference = ?,
                payment_gateway_id = ?
            WHERE id = ? AND user_id = ?
        ", [
            $paymentMethod,
            $paymentResult['reference'],
            $paymentResult['gateway_transaction_id'],
            $bookingData['booking_id'],
            $_SESSION['user_id']
        ]);

        // Envoi de l'email
        $emailService = new EmailService();
        $emailService->sendPaymentConfirmationWithBookingId($pdo, $bookingData['booking_id'], $_SESSION['user_id']);

        // Nettoyage de session
        unset($_SESSION['booking_data']);

        // Réponse AJAX
        if (isset($_POST['ajax'])) {
            echo json_encode([
                'success' => true,
                'redirect' => 'booking-confirmation.php?booking=' . $bookingData['booking_id']
            ]);
            exit;
        }

        // Redirection
        header('Location: booking-confirmation.php?booking=' . $bookingData['booking_id']);
        exit;

    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';

        if (isset($_POST['ajax'])) {
            echo json_encode([
                'success' => false,
                'message' => $message
            ]);
            exit;
        }

        echo "<div style='color: red;'><strong>Erreur :</strong> $message</div>";
        error_log("Erreur de paiement (User {$_SESSION['user_id']}): $message");
    }
}

// Algorithme de Luhn pour validation de carte
function validateCardNumber($number) {
    $number = preg_replace('/\D/', '', $number);
    $sum = 0;
    $alternate = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = intval($number[$i]);
        if ($alternate) {
            $n *= 2;
            if ($n > 9) $n = ($n % 10) + 1;
        }
        $sum += $n;
        $alternate = !$alternate;
    }
    return ($sum % 10) === 0;
}

$csrfToken = Security::generateCSRFToken();
?>
