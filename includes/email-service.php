<?php
class EmailService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function sendBookingConfirmation($bookingId) {
        try {
            // Get booking details
            $stmt = $this->pdo->prepare("
                SELECT b.*, h.name as hotel_name, h.location, h.city,
                       rt.name as room_type_name, u.email, u.full_name
                FROM bookings b
                JOIN hotels h ON b.hotel_id = h.id
                JOIN room_types rt ON b.room_type_id = rt.id
                JOIN users u ON b.user_id = u.id
                WHERE b.id = ?
            ");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();
            
            if (!$booking) {
                throw new Exception("Booking not found");
            }
            
            // Generate confirmation code if not exists
            if (empty($booking['confirmation_code'])) {
                $confirmationCode = 'ATL' . strtoupper(substr(md5($bookingId . time()), 0, 8));
                $stmt = $this->pdo->prepare("UPDATE bookings SET confirmation_code = ? WHERE id = ?");
                $stmt->execute([$confirmationCode, $bookingId]);
                $booking['confirmation_code'] = $confirmationCode;
            }
            
            // Email content
            $subject = "Confirmation de réservation - " . $booking['hotel_name'];
            $message = $this->buildConfirmationEmail($booking);
            
            // Send email (using PHP mail or external service)
            $sent = $this->sendEmail($booking['email'], $subject, $message);
            
            // Log email
            $this->logEmail($bookingId, 'confirmation', $booking['email'], $subject, $sent ? 'sent' : 'failed');
            
            // Update booking
            if ($sent) {
                $stmt = $this->pdo->prepare("UPDATE bookings SET email_sent = TRUE WHERE id = ?");
                $stmt->execute([$bookingId]);
            }
            
            return $sent;
            
        } catch (Exception $e) {
            error_log("Email service error: " . $e->getMessage());
            return false;
        }
    }
    
    private function buildConfirmationEmail($booking) {
        $checkIn = date('d/m/Y', strtotime($booking['check_in']));
        $checkOut = date('d/m/Y', strtotime($booking['check_out']));
        $totalPrice = number_format($booking['total_price'], 2, ',', ' ');
        
        return "
        <html>
        <body style='font-family: Arial, sans-serif; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #D4AF37;'>Confirmation de Réservation</h2>
                
                <p>Cher/Chère {$booking['full_name']},</p>
                
                <p>Nous avons le plaisir de confirmer votre réservation :</p>
                
                <div style='background: #f8f8f8; padding: 20px; border-radius: 10px; margin: 20px 0;'>
                    <h3 style='color: #D4AF37; margin-top: 0;'>Détails de la réservation</h3>
                    <p><strong>Code de confirmation :</strong> {$booking['confirmation_code']}</p>
                    <p><strong>Hôtel :</strong> {$booking['hotel_name']}</p>
                    <p><strong>Adresse :</strong> {$booking['location']}, {$booking['city']}</p>
                    <p><strong>Type de chambre :</strong> {$booking['room_type_name']}</p>
                    <p><strong>Arrivée :</strong> {$checkIn}</p>
                    <p><strong>Départ :</strong> {$checkOut}</p>
                    <p><strong>Nombre d'invités :</strong> {$booking['guests']}</p>
                    <p><strong>Prix total :</strong> {$totalPrice} MAD</p>
                </div>
                
                <p>Vous pouvez gérer votre réservation en vous connectant à votre compte sur notre site.</p>
                
                <p>Nous vous remercions de votre confiance et avons hâte de vous accueillir.</p>
                
                <p>Cordialement,<br>L'équipe Atlas Hotels</p>
            </div>
        </body>
        </html>
        ";
    }
    
    private function sendEmail($to, $subject, $message) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: Atlas Hotels <noreply@atlashotels.ma>',
            'Reply-To: contact@atlashotels.ma'
        ];
        
        return mail($to, $subject, $message, implode("\r\n", $headers));
    }
    
    private function logEmail($bookingId, $type, $email, $subject, $status) {
        $stmt = $this->pdo->prepare("
            INSERT INTO email_logs (booking_id, email_type, recipient_email, subject, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$bookingId, $type, $email, $subject, $status]);
    }
}
?>
