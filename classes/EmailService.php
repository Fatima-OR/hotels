<?php
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
        
        // Configuration des headers améliorée
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: Atlas Hotels <noreply@atlashotels.ma>',
            'Reply-To: contact@atlashotels.ma',
            'X-Mailer: PHP/' . phpversion(),
            'X-Priority: 1',
            'Return-Path: noreply@atlashotels.ma'
        ];
        
        // Tentative d'envoi avec gestion d'erreur améliorée
        $sent = @mail(
            $bookingDetails['user_email'],
            'Confirmation de réservation - Atlas Hotels',
            $emailContent['html'],
            implode("\r\n", $headers)
        );
        
        // Log détaillé pour debug
        error_log("Tentative d'envoi email pour booking $bookingId vers " . $bookingDetails['user_email'] . " - Résultat: " . ($sent ? 'SUCCESS' : 'FAILED'));
        
        if ($sent) {
            // Enregistrer dans les logs
            $this->logEmail($bookingId, 'confirmation', $bookingDetails['user_email'], 'sent');
            
            // Mettre à jour le statut email_sent dans bookings
            Security::prepareAndExecute($this->pdo,
                "UPDATE bookings SET email_sent = 1 WHERE id = ?",
                [$bookingId]
            );
            
            return true;
        } else {
            // Log de l'erreur
            $lastError = error_get_last();
            error_log("Erreur mail(): " . ($lastError['message'] ?? 'Erreur inconnue'));
            $this->logEmail($bookingId, 'confirmation', $bookingDetails['user_email'], 'failed');
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Erreur envoi email: " . $e->getMessage());
        $this->logEmail($bookingId, 'confirmation', $bookingDetails['user_email'] ?? '', 'failed');
        return false;
    }
}
    
    private function getBookingDetails($bookingId) {
        $stmt = Security::prepareAndExecute($this->pdo,
            "SELECT b.*, h.name as hotel_name, h.location, h.city, h.image_url as hotel_image,
                    rt.name as room_type_name, rt.description as room_description,
                    u.email as user_email, u.full_name as user_name, u.phone as user_phone
             FROM bookings b
             JOIN hotels h ON b.hotel_id = h.id
             JOIN room_types rt ON b.room_type_id = rt.id
             JOIN users u ON b.user_id = u.id
             WHERE b.id = ?",
            [$bookingId]
        );
        
        return $stmt->fetch();
    }
    
    private function generateConfirmationEmail($booking) {
        $confirmationCode = $this->generateConfirmationCode($booking['id']);
        
        // Mettre à jour le code de confirmation
        Security::prepareAndExecute($this->pdo,
            "UPDATE bookings SET confirmation_code = ? WHERE id = ?",
            [$confirmationCode, $booking['id']]
        );
        
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
                .hotel-image { width: 120px; height: 80px; object-fit: cover; border-radius: 8px; }
                .booking-details { background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin: 1rem 0; }
                .detail-row { display: flex; justify-content: space-between; margin: 0.5rem 0; }
                .total { font-weight: bold; font-size: 1.2rem; color: #D4AF37; border-top: 1px solid #ddd; padding-top: 0.5rem; }
                .footer { background: #2c3e50; color: white; padding: 1.5rem; text-align: center; }
                .btn { display: inline-block; background: #D4AF37; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 1rem 0; }
                @media (max-width: 600px) {
                    .hotel-info { flex-direction: column; }
                    .detail-row { flex-direction: column; }
                }
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
                        <div class='detail-row'>
                            <span>Type de chambre :</span>
                            <strong>" . htmlspecialchars($booking['room_type_name']) . "</strong>
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
                    
                    <p>Pour toute question, n'hésitez pas à nous contacter :</p>
                    <p>📞 <strong>+212 5 24 38 86 00</strong><br>
                    📧 <strong>contact@atlashotels.ma</strong></p>
                    
                    <p>Nous vous remercions de votre confiance et avons hâte de vous accueillir !</p>
                    <p><strong>L'équipe Atlas Hotels</strong></p>
                </div>
                
                <div class='footer'>
                    <p style='margin: 0;'>© 2024 Atlas Hotels - Tous droits réservés</p>
                    <p style='margin: 0.5rem 0 0 0; font-size: 0.9rem;'>
                        Marrakech, Maroc | contact@atlashotels.ma
                    </p>
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
            error_log("Erreur log email: " . $e->getMessage());
        }
    }
    
    public function sendCancellationEmail($bookingId) {
        try {
            $bookingDetails = $this->getBookingDetails($bookingId);
            
            if (!$bookingDetails) {
                throw new Exception("Réservation non trouvée");
            }
            
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: Atlas Hotels <noreply@atlashotels.ma>',
                'Reply-To: contact@atlashotels.ma',
                'X-Mailer: PHP/' . phpversion()
            ];
            
            $html = $this->getCancellationEmailTemplate($bookingDetails);
            
            $sent = mail(
                $bookingDetails['user_email'],
                'Annulation de réservation - Atlas Hotels',
                $html,
                implode("\r\n", $headers)
            );
            
            if ($sent) {
                $this->logEmail($bookingId, 'cancellation', $bookingDetails['user_email'], 'sent');
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Erreur envoi email annulation: " . $e->getMessage());
            return false;
        }
    }
    
    private function getCancellationEmailTemplate($booking) {
        return "
        <!DOCTYPE html>
        <html lang='fr'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Annulation de réservation</title>
            <style>
                body { font-family: 'Arial', sans-serif; margin: 0; padding: 0; background-color: #f8f9fa; }
                .container { max-width: 600px; margin: 0 auto; background: white; }
                .header { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); padding: 2rem; text-align: center; }
                .header h1 { color: white; margin: 0; font-size: 2rem; }
                .content { padding: 2rem; }
                .cancellation-box { background: #f8d7da; border-left: 4px solid #dc3545; padding: 1rem; margin: 1rem 0; }
                .footer { background: #2c3e50; color: white; padding: 1.5rem; text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🏨 Atlas Hotels</h1>
                    <p style='color: white; margin: 0.5rem 0 0 0;'>Annulation de réservation</p>
                </div>
                
                <div class='content'>
                    <h2 style='color: #2c3e50;'>Bonjour " . htmlspecialchars($booking['user_name']) . ",</h2>
                    <p>Nous confirmons l'annulation de votre réservation.</p>
                    
                    <div class='cancellation-box'>
                        <h3 style='margin: 0; color: #dc3545;'>Réservation annulée</h3>
                        <p style='margin: 0.5rem 0 0 0;'>Code: " . htmlspecialchars($booking['confirmation_code']) . "</p>
                    </div>
                    
                    <p><strong>Hôtel :</strong> " . htmlspecialchars($booking['hotel_name']) . "</p>
                    <p><strong>Dates :</strong> Du " . date('d/m/Y', strtotime($booking['check_in'])) . " au " . date('d/m/Y', strtotime($booking['check_out'])) . "</p>
                    
                    <p>Si vous avez effectué un paiement, le remboursement sera traité dans les 5-7 jours ouvrables.</p>
                    
                    <p>Pour toute question, contactez-nous :</p>
                    <p>📞 <strong>+212 5 24 38 86 00</strong><br>
                    📧 <strong>contact@atlashotels.ma</strong></p>
                    
                    <p>Nous espérons vous accueillir prochainement !</p>
                    <p><strong>L'équipe Atlas Hotels</strong></p>
                </div>
                
                <div class='footer'>
                    <p style='margin: 0;'>© 2024 Atlas Hotels - Tous droits réservés</p>
                </div>
            </div>
        </body>
        </html>";
    }

    public function testEmailConfiguration() {
        $testEmail = 'test@example.com';
        $subject = 'Test Email - Atlas Hotels';
        $message = '<h1>Test Email</h1><p>Si vous recevez cet email, la configuration fonctionne !</p>';
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: Atlas Hotels <noreply@atlashotels.ma>'
        ];
        
        $sent = @mail($testEmail, $subject, $message, implode("\r\n", $headers));
        
        return [
            'success' => $sent,
            'message' => $sent ? 'Email de test envoyé avec succès' : 'Échec de l\'envoi du test'
        ];
    }
}
?>
