<?php
// Webhook pour recevoir les notifications de paiement
require_once 'config/database.php';
require_once 'includes/Security.php';

// Vérifier que c'est bien un webhook de votre passerelle de paiement
$webhookSecret = $_ENV['PAYMENT_WEBHOOK_SECRET'] ?? 'your_webhook_secret';
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

// Lire le contenu brut de la requête
$payload = file_get_contents('php://input');

// Vérifier la signature (exemple pour Stripe)
if (!verifyWebhookSignature($payload, $signature, $webhookSecret)) {
    http_response_code(400);
    exit('Signature invalide');
}

// Décoder les données
$event = json_decode($payload, true);

if (!$event) {
    http_response_code(400);
    exit('Données invalides');
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    switch ($event['type']) {
        case 'charge.succeeded':
            handlePaymentSuccess($pdo, $event['data']['object']);
            break;
            
        case 'charge.failed':
            handlePaymentFailure($pdo, $event['data']['object']);
            break;
            
        case 'charge.dispute.created':
            handleChargeback($pdo, $event['data']['object']);
            break;
            
        default:
            error_log('Événement webhook non géré: ' . $event['type']);
    }
    
    http_response_code(200);
    echo 'OK';
    
} catch (Exception $e) {
    error_log('Erreur webhook: ' . $e->getMessage());
    http_response_code(500);
    exit('Erreur serveur');
}

function verifyWebhookSignature($payload, $signature, $secret) {
    $expectedSignature = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expectedSignature, $signature);
}

function handlePaymentSuccess($pdo, $charge) {
    $paymentReference = $charge['id'];
    $bookingId = $charge['metadata']['booking_id'] ?? null;
    
    if (!$bookingId) {
        error_log('Booking ID manquant dans le webhook de paiement');
        return;
    }
    
    // Mettre à jour le statut de la transaction
    $stmt = Security::prepareAndExecute($pdo, "
        UPDATE payment_transactions 
        SET status = 'completed', gateway_response = ?, updated_at = NOW()
        WHERE gateway_reference = ?
    ", [json_encode($charge), $paymentReference]);
    
    // Confirmer la réservation si pas déjà fait
    $stmt = Security::prepareAndExecute($pdo, "
        UPDATE bookings 
        SET status = 'confirmed', payment_status = 'paid', confirmed_at = NOW()
        WHERE id = ? AND payment_status != 'paid'
    ", [$bookingId]);
    
    if ($stmt->rowCount() > 0) {
        error_log("Réservation $bookingId confirmée via webhook");
    }
}

function handlePaymentFailure($pdo, $charge) {
    $paymentReference = $charge['id'];
    $bookingId = $charge['metadata']['booking_id'] ?? null;
    
    if (!$bookingId) {
        return;
    }
    
    // Mettre à jour le statut de la transaction
    $stmt = Security::prepareAndExecute($pdo, "
        UPDATE payment_transactions 
        SET status = 'failed', gateway_response = ?, updated_at = NOW()
        WHERE gateway_reference = ?
    ", [json_encode($charge), $paymentReference]);
    
    // Annuler la réservation
    $stmt = Security::prepareAndExecute($pdo, "
        UPDATE bookings 
        SET status = 'cancelled', payment_status = 'failed'
        WHERE id = ?
    ", [$bookingId]);
    
    error_log("Réservation $bookingId annulée suite à échec de paiement");
}

function handleChargeback($pdo, $dispute) {
    $paymentReference = $dispute['charge'];
    
    // Marquer comme litigieux
    $stmt = Security::prepareAndExecute($pdo, "
        UPDATE payment_transactions 
        SET status = 'disputed', gateway_response = ?, updated_at = NOW()
        WHERE gateway_reference = ?
    ", [json_encode($dispute), $paymentReference]);
    
    error_log("Litige créé pour le paiement $paymentReference");
}
?>