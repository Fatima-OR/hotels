<?php
session_start();

// Configuration de la base de données
try {
    $pdo = new PDO("mysql:host=localhost;dbname=atlass_hotels;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

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
            throw new Exception("Une erreur est survenue lors de l'exécution de la requête: " . $e->getMessage());
        }
    }
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=my-bookings');
    exit;
}

$userId = $_SESSION['user_id'];
$message = '';
$messageType = '';

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Token de sécurité invalide');
        }
        
        $action = $_POST['action'] ?? '';
        $bookingId = filter_var($_POST['booking_id'] ?? 0, FILTER_VALIDATE_INT);
        
        if (!$bookingId) {
            throw new Exception('ID de réservation invalide');
        }
        
        // Vérifier que la réservation appartient à l'utilisateur
        $stmt = Security::prepareAndExecute($pdo, 
            "SELECT * FROM bookings WHERE id = ? AND user_id = ?", 
            [$bookingId, $userId]
        );
        $booking = $stmt->fetch();
        
        if (!$booking) {
            throw new Exception('Réservation introuvable');
        }
        
        if ($action === 'cancel_booking') {
            // Annulation possible à tout moment selon votre demande
            $pdo->beginTransaction();
            
            try {
                // Mettre à jour le statut de la réservation
                $stmt = Security::prepareAndExecute($pdo, 
                    "UPDATE bookings SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?",
                    [$bookingId]
                );
                
                // Si la réservation était payée, créer un remboursement
                if ($booking['payment_status'] === 'paid') {
                    // Vérifier si la table refunds existe
                    $stmt = $pdo->query("SHOW TABLES LIKE 'refunds'");
                    if ($stmt->rowCount() > 0) {
                        $stmt = Security::prepareAndExecute($pdo, 
                            "INSERT INTO refunds (booking_id, amount, status, created_at) 
                            VALUES (?, ?, 'processing', NOW())",
                            [$bookingId, $booking['total_price']]
                        );
                    }
                }
                
                $pdo->commit();
                
                $message = 'Votre réservation a été annulée avec succès. Si vous aviez payé, un remboursement sera traité dans les prochains jours.';
                $messageType = 'success';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $e->getMessage();
        $messageType = 'error';
        error_log("Erreur my-bookings.php: " . $e->getMessage());
    }
}

// Récupérer les réservations avec informations disponibles
try {
    // D'abord, vérifier quelles colonnes existent
    $hotelColumns = [];
    $roomTypeColumns = [];
    
    try {
        $stmt = $pdo->query("DESCRIBE hotels");
        while ($row = $stmt->fetch()) {
            $hotelColumns[] = $row['Field'];
        }
    } catch (Exception $e) {
        $hotelColumns = ['id', 'name', 'city']; // colonnes de base
    }
    
    try {
        $stmt = $pdo->query("DESCRIBE room_types");
        while ($row = $stmt->fetch()) {
            $roomTypeColumns[] = $row['Field'];
        }
    } catch (Exception $e) {
        $roomTypeColumns = ['id', 'name', 'price_per_night']; // colonnes de base
    }
    
    // Construire la requête avec les colonnes disponibles
    $hotelFields = "COALESCE(h.name, 'Hôtel non trouvé') as hotel_name, 
                   COALESCE(h.city, 'Ville non spécifiée') as city";
    
    if (in_array('website', $hotelColumns)) {
        $hotelFields .= ", COALESCE(h.website, '#') as hotel_website";
    } else {
        $hotelFields .= ", '#' as hotel_website";
    }
    
    if (in_array('phone', $hotelColumns)) {
        $hotelFields .= ", COALESCE(h.phone, '') as hotel_phone";
    } else {
        $hotelFields .= ", '' as hotel_phone";
    }
    
    // Gestion améliorée des images d'hôtel
    if (in_array('image_url', $hotelColumns)) {
        $hotelFields .= ", COALESCE(h.image_url, '') as hotel_image";
    } else if (in_array('images', $hotelColumns)) {
        $hotelFields .= ", COALESCE(h.images, '[]') as hotel_images";
    } else {
        $hotelFields .= ", '[]' as hotel_images";
    }
    
    $roomFields = "COALESCE(rt.name, 'Type de chambre non spécifié') as room_type_name, 
                  COALESCE(rt.price_per_night, 0) as price_per_night";
    
    // Gestion améliorée des images de chambre
    if (in_array('image_url', $roomTypeColumns)) {
        $roomFields .= ", COALESCE(rt.image_url, '') as room_image";
    } else if (in_array('images', $roomTypeColumns)) {
        $roomFields .= ", COALESCE(rt.images, '[]') as room_images";
    } else {
        $roomFields .= ", '[]' as room_images";
    }
    
    if (in_array('description', $roomTypeColumns)) {
        $roomFields .= ", COALESCE(rt.description, '') as room_description";
    } else {
        $roomFields .= ", '' as room_description";
    }
    
    $sql = "SELECT 
        b.id, b.hotel_id, b.room_type_id, b.check_in, b.check_out, 
        b.guests, b.total_price, b.special_requests, b.status, b.payment_status,
        b.created_at,
        {$hotelFields},
        {$roomFields}
    FROM bookings b
    LEFT JOIN hotels h ON b.hotel_id = h.id
    LEFT JOIN room_types rt ON b.room_type_id = rt.id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC";
    
    $stmt = Security::prepareAndExecute($pdo, $sql, [$userId]);
    $bookings = $stmt->fetchAll();
    
} catch (Exception $e) {
    $bookings = [];
    $message = 'Erreur lors de la récupération des réservations: ' . $e->getMessage();
    $messageType = 'error';
    error_log("Erreur my-bookings.php: " . $e->getMessage());
}

$csrfToken = Security::generateCSRFToken();

// Fonction améliorée pour obtenir la première image
function getFirstImage($booking) {
    // Image par défaut
    $defaultImage = 'data:image/svg+xml;base64,' . base64_encode('
        <svg width="380" height="220" viewBox="0 0 380 220" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="380" height="220" fill="#333333"/>
            <rect x="140" y="80" width="100" height="60" rx="8" fill="#D4AF37" opacity="0.3"/>
            <circle cx="160" cy="100" r="8" fill="#D4AF37"/>
            <path d="M180 120 L200 100 L220 120 L220 130 L180 130 Z" fill="#D4AF37"/>
            <text x="190" y="150" font-family="Arial" font-size="12" fill="#D4AF37" text-anchor="middle">Atlas Hotels</text>
            <text x="190" y="170" font-family="Arial" font-size="10" fill="#B8860B" text-anchor="middle">Image non disponible</text>
        </svg>
    ');
    
    // Vérifier d'abord l'image directe de la chambre
    if (!empty($booking['room_image'])) {
        return $booking['room_image'];
    }
    
    // Vérifier ensuite l'image directe de l'hôtel
    if (!empty($booking['hotel_image'])) {
        return $booking['hotel_image'];
    }
    
    // Essayer de récupérer depuis le JSON des images de chambre
    if (!empty($booking['room_images']) && $booking['room_images'] !== '[]') {
        $images = json_decode($booking['room_images'], true);
        if (is_array($images) && !empty($images)) {
            $firstImage = $images[0];
            if (!empty($firstImage)) {
                if (filter_var($firstImage, FILTER_VALIDATE_URL)) {
                    return $firstImage;
                }
                if (str_starts_with($firstImage, '/')) {
                    return $firstImage;
                }
                return '/images/' . ltrim($firstImage, '/');
            }
        }
    }
    
    // Essayer de récupérer depuis le JSON des images d'hôtel
    if (!empty($booking['hotel_images']) && $booking['hotel_images'] !== '[]') {
        $images = json_decode($booking['hotel_images'], true);
        if (is_array($images) && !empty($images)) {
            $firstImage = $images[0];
            if (!empty($firstImage)) {
                if (filter_var($firstImage, FILTER_VALIDATE_URL)) {
                    return $firstImage;
                }
                if (str_starts_with($firstImage, '/')) {
                    return $firstImage;
                }
                return '/images/' . ltrim($firstImage, '/');
            }
        }
    }
    
    // Si aucune image n'est trouvée, retourner l'image par défaut
    return $defaultImage;
}

// Fonction pour déterminer le statut d'affichage
function getDisplayStatus($booking) {
    switch ($booking['status']) {
        case 'cancelled':
            return ['class' => 'status-cancelled', 'text' => 'Annulée'];
        case 'confirmed':
            if ($booking['payment_status'] === 'paid') {
                return ['class' => 'status-occupied', 'text' => 'Confirmé'];
            } else {
                return ['class' => 'status-pending', 'text' => 'En attente de paiement'];
            }
        case 'pending':
            return ['class' => 'status-pending', 'text' => 'En attente'];
        default:
            return ['class' => 'status-available', 'text' => 'disponible'];
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Réservations - Atlas Hotels</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-gold: #D4AF37;
            --secondary-gold: #B8860B;
            --dark-gold: #996F00;
            --gradient-luxury: linear-gradient(135deg, #D4AF37 0%, #B8860B 50%, #996F00 100%);
            --success: #22c55e;
            --warning: #f59e0b;
            --error: #ef4444;
            --info: #3b82f6;
            --occupied: #8b5cf6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: black;
            color: goldenrod;
            line-height: 1.6;
            padding-top: 80px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            background: rgba(15,20,25,0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(212,175,55,0.2);
        }

        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .nav-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
        }

        .nav-logo i {
            font-size: 2rem;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
        }

        .logo-main {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 700;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo-sub {
            font-size: 0.8rem;
            color: white;
            font-weight: 300;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .nav-menu {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .nav-link {
            color: goldenrod;
            text-decoration: none;
            font-weight: 500;
            position: relative;
            padding: 0.5rem 0;
            transition: color 0.3s ease;
        }

        .nav-link::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--gradient-luxury);
            transition: width 0.3s ease;
        }

        .nav-link:hover::before,
        .nav-link.active::before {
            width: 100%;
        }

        .nav-link:hover,
        .nav-link.active {
            color: var(--primary-gold);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: rgba(212, 175, 55, 0.1);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            border: 1px solid var(--primary-gold);
        }

        .user-name {
            color: var(--primary-gold);
            font-weight: 500;
        }

        .btn-logout {
            color: goldenrod;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .btn-logout:hover {
            background: var(--primary-gold);
            color: white;
            transform: rotate(360deg);
        }

        .main-content {
            flex: 1;
            padding: 2rem 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-gold);
            margin-bottom: 2rem;
            text-align: center;
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            color: var(--success);
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .bookings-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 2rem;
        }

        .booking-card {
            background: rgba(212, 175, 55, 0.05);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.3);
        }

        .booking-image {
            height: 220px;
            overflow: hidden;
            position: relative;
            background: #333;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .booking-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: all 0.3s ease;
            opacity: 0;
        }

        .booking-image img.loaded {
            opacity: 1;
        }

        .booking-image img.error {
            opacity: 0.7;
            filter: grayscale(100%);
        }

        .booking-card:hover .booking-image img.loaded {
            transform: scale(1.1);
        }

        .image-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: var(--primary-gold);
            font-size: 2rem;
            z-index: 1;
        }

        .booking-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            backdrop-filter: blur(10px);
            z-index: 2;
        }

        .status-confirmed {
            background: rgba(34, 197, 94, 0.9);
            color: white;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.9);
            color: white;
        }

        .status-cancelled {
            background: rgba(239, 68, 68, 0.9);
            color: white;
        }

        .status-occupied {
            background:  rgba(34, 197, 94, 0.9);
            color: white;
        }

        .status-available {
            background: rgba(34, 197, 94, 0.9);
            color: white;
        }

        .booking-content {
            padding: 1.5rem;
        }

        .booking-header {
            margin-bottom: 1rem;
        }

        .hotel-name-link {
            font-family: 'Playfair Display', serif;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-gold);
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .hotel-name-link:hover {
            color: var(--secondary-gold);
            transform: translateX(5px);
        }

        .booking-id {
            color: white;
            font-size: 0.9rem;
            opacity: 0.7;
            margin-top: 0.5rem;
        }

        .booking-details {
            margin-bottom: 1.5rem;
        }

        .booking-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            color: white;
        }

        .booking-detail i {
            color: var(--primary-gold);
            width: 20px;
            text-align: center;
        }

        .booking-price {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
        }

        .price-amount {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-gold);
        }

        .booking-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.8rem 1.2rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.8rem;
            flex: 1;
            min-width: 100px;
        }

        .btn-primary {
            background: var(--gradient-luxury);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(212, 175, 55, 0.3);
        }

        .btn-pay {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            animation: pulse-green 2s infinite;
        }

        @keyframes pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
            100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }

        .btn-pay:hover {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(34, 197, 94, 0.4);
            animation: none;
        }

        .btn-cancel {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .btn-cancel::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-cancel:hover::before {
            left: 100%;
        }

        .btn-cancel:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(239, 68, 68, 0.4);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            background: rgba(212, 175, 55, 0.05);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 20px;
        }

        .empty-icon {
            font-size: 4rem;
            color: var(--primary-gold);
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-gold);
            margin-bottom: 1rem;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1100;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: linear-gradient(135deg, #0F1419 0%, #1A1A2E 100%);
            border: 2px solid rgba(212, 175, 55, 0.5);
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            padding: 2rem;
            transform: translateY(20px) scale(0.9);
            transition: all 0.3s ease;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.8);
        }

        .modal-overlay.active .modal {
            transform: translateY(0) scale(1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.3);
        }

        .modal-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-gold);
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            color: var(--error);
            background: rgba(239, 68, 68, 0.1);
            transform: rotate(90deg);
        }

        .modal-body {
            margin-bottom: 2rem;
            color: white;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(212, 175, 55, 0.3);
        }

        .hotel-info {
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
            text-align: center;
        }

        .hotel-info strong {
            color: var(--primary-gold);
            font-size: 1.2rem;
        }

        /* Footer */
        .footer {
            background: linear-gradient(135deg, #0F1419 0%, #1A1A2E 100%);
            color: white;
            padding: 3rem 0 1rem;
            margin-top: 4rem;
            border-top: 1px solid rgba(212, 175, 55, 0.2);
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer-section h3 {
            font-family: 'Playfair Display', serif;
            color: var(--primary-gold);
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }

        .footer-section p,
        .footer-section a {
            color: #ccc;
            text-decoration: none;
            line-height: 1.8;
            transition: color 0.3s ease;
        }

        .footer-section a:hover {
            color: var(--primary-gold);
        }

        .footer-section ul {
            list-style: none;
        }

        .footer-section ul li {
            margin-bottom: 0.5rem;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .social-links a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 50%;
            color: var(--primary-gold);
            transition: all 0.3s ease;
        }

        .social-links a:hover {
            background: var(--primary-gold);
            color: white;
            transform: translateY(-3px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            margin-top: 2rem;
            border-top: 1px solid rgba(212, 175, 55, 0.2);
            color: #999;
        }

        @media (max-width: 768px) {
            .bookings-container {
                grid-template-columns: 1fr;
            }

            .booking-actions {
                flex-direction: column;
            }

            .nav-menu {
                display: none;
            }

            .modal {
                width: 95%;
                padding: 1.5rem;
            }

            .modal-footer {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="nav-logo">
                <i class="fas fa-crown"></i>
                <div class="logo-text">
                    <span class="logo-main">Atlas Hotels</span>
                    <span class="logo-sub">Luxury Collection</span>
                </div>
            </a>
            
           <div class="nav-menu" id="nav-menu">
                <a href="index.php" class="nav-link ">Accueil</a>
                <a href="hotels.php" class="nav-link">Hôtels</a>
                <a href="user.php" class="nav-link">Profile</a>
                <a href="about.php" class="nav-link">About</a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="bookings.php" class="nav-link active">Mes Réservations</a>
                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
    <a href="admin.php" class="nav-link">Administration</a>
<?php endif; ?>

                    <div class="user-menu">
                        <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                        <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i></a>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="nav-link">Connexion</a>
                    <a href="register.php" class="btn-primary">S'inscrire</a>
                <?php endif; ?>
            </div>
            
            <div class="nav-toggle" id="nav-toggle">
                <span class="bar"></span><span class="bar"></span><span class="bar"></span>
            </div>
        </div>
    </nav>

    <main class="main-content">
        <div class="container">
            <h1 class="page-title">Mes Réservations</h1>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>">
                    <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <h2 class="empty-title">Aucune réservation trouvée</h2>
                    <p style="color: white; margin-bottom: 2rem;">
                        Vous n'avez pas encore effectué de réservation. Explorez nos hôtels et réservez votre prochain séjour.
                    </p>
                    <a href="hotels.php" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        Découvrir nos hôtels
                    </a>
                </div>
            <?php else: ?>
                <div class="bookings-container">
                    <?php foreach ($bookings as $booking): ?>
                        <?php
                        $checkIn = new DateTime($booking['check_in']);
                        $checkOut = new DateTime($booking['check_out']);
                        $nights = $checkIn->diff($checkOut)->days;
                        $now = new DateTime();
                        
                        // Obtenir le statut d'affichage
                        $statusInfo = getDisplayStatus($booking);
                        
                        // Obtenir l'image (avec la fonction améliorée)
                        $finalImage = getFirstImage($booking);
                        
                        // Déterminer si on peut annuler (toujours possible selon votre demande)
                        $canCancel = $booking['status'] !== 'cancelled';
                        
                        // Déterminer si on peut payer
                        $canPay = ($booking['status'] === 'pending' || $booking['status'] === 'confirmed') && 
                                  $booking['payment_status'] !== 'paid';
                        ?>
                        <div class="booking-card">
                            <div class="booking-image">
                                <div class="image-loading">
                                    <i class="fas fa-spinner fa-spin"></i>
                                </div>
                                <img src="<?= htmlspecialchars($finalImage) ?>" 
                                     alt="<?= htmlspecialchars($booking['room_type_name'] . ' - ' . $booking['hotel_name']) ?>"
                                     onload="handleImageLoad(this)"
                                     onerror="handleImageError(this)">
                                <div class="booking-status <?= $statusInfo['class'] ?>">
                                    <?= $statusInfo['text'] ?>
                                </div>
                            </div>
                            <div class="booking-content">
                                <div class="booking-header">
                                    <a href="<?= isset($booking['hotel_website']) && $booking['hotel_website'] !== '#' ? htmlspecialchars($booking['hotel_website']) : 'javascript:void(0)' ?>" 
                                       class="hotel-name-link" 
                                       <?= isset($booking['hotel_website']) && $booking['hotel_website'] !== '#' ? 'target="_blank"' : '' ?>
                                       title="<?= isset($booking['hotel_website']) && $booking['hotel_website'] !== '#' ? 'Visiter le site de l\'hôtel' : 'Site web non disponible' ?>">
                                        <?= htmlspecialchars($booking['hotel_name']) ?>
                                        <?php if (isset($booking['hotel_website']) && $booking['hotel_website'] !== '#'): ?>
                                            <i class="fas fa-external-link-alt" style="font-size: 0.8rem;"></i>
                                        <?php endif; ?>
                                    </a>
                                    <div class="booking-id">Réservation #<?= $booking['id'] ?></div>
                                </div>
                                
                                <div class="booking-details">
                                    <div class="booking-detail">
                                        <i class="fas fa-bed"></i>
                                        <span><?= htmlspecialchars($booking['room_type_name']) ?></span>
                                    </div>
                                    <div class="booking-detail">
                                        <i class="fas fa-calendar-check"></i>
                                        <span>Arrivée: <?= $checkIn->format('d/m/Y') ?></span>
                                    </div>
                                    <div class="booking-detail">
                                        <i class="fas fa-calendar-times"></i>
                                        <span>Départ: <?= $checkOut->format('d/m/Y') ?></span>
                                    </div>
                                    <div class="booking-detail">
                                        <i class="fas fa-moon"></i>
                                        <span><?= $nights ?> nuit<?= $nights > 1 ? 's' : '' ?></span>
                                    </div>
                                    <div class="booking-detail">
                                        <i class="fas fa-users"></i>
                                        <span><?= $booking['guests'] ?> personne<?= $booking['guests'] > 1 ? 's' : '' ?></span>
                                    </div>
                                    <div class="booking-detail">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?= htmlspecialchars($booking['city']) ?></span>
                                    </div>
                                    <?php if (!empty($booking['hotel_phone'])): ?>
                                    <div class="booking-detail">
                                        <i class="fas fa-phone"></i>
                                        <span><?= htmlspecialchars($booking['hotel_phone']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="booking-price">
                                    <span style="color: white;">Total</span>
                                    <span class="price-amount"><?= number_format($booking['total_price'], 0, ',', ' ') ?> MAD</span>
                                </div>
                                
                                <div class="booking-actions">
                                    <?php if ($canPay): ?>
                                        <a href="payment.php?booking=<?= $booking['id'] ?>" class="btn btn-pay">
                                            <i class="fas fa-credit-card"></i>
                                            Payer Maintenant
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($booking['status'] === 'confirmed' || $booking['payment_status'] === 'paid'): ?>
                                        <a href="booking-confirmation.php?booking=<?= $booking['id'] ?>" class="btn btn-primary">
                                            <i class="fas fa-info-circle"></i>
                                            Détails
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($canCancel): ?>
                                        <button class="btn btn-cancel" onclick="confirmCancellation(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['hotel_name'], ENT_QUOTES) ?>')">
                                            <i class="fas fa-times-circle"></i>
                                            Annuler
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <div class="footer-section">
                <h3>Atlas Hotels</h3>
                <p>Découvrez l'excellence de l'hospitalité marocaine avec notre collection d'hôtels de luxe à travers le royaume.</p>
                
            </div>
            
            <div class="footer-section">
                <h3>Nos Destinations</h3>
                <ul>
                    <li><a href="#">Marrakech</a></li>
                    <li><a href="#">Casablanca</a></li>
                    <li><a href="#">Agadir</a></li>
                    <li><a href="#">Fès</a></li>
                    <li><a href="#">Rabat</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h3>Services</h3>
            <ul>
                    <li><a href="#">Réservation en ligne</a></li>
                    <li><a href="#">Service clientèle 24/7</a></li>
                    <li><a href="#">Transfert aéroport</a></li>
                    <li><a href="#">Spa & Wellness</a></li>
                    <li><a href="#">Événements & Mariages</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h3>Contact</h3>
                <p><i class="fas fa-phone"></i> +212 5 22 123 456</p>
                <p><i class="fas fa-envelope"></i> contact@atlashotels.ma</p>
                <p><i class="fas fa-map-marker-alt"></i> Casablanca, Maroc</p>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>&copy; 2024 Atlas Hotels. Tous droits réservés.</p>
        </div>
    </footer>

    <!-- Cancellation Modal -->
    <div class="modal-overlay" id="cancellationModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-exclamation-triangle" style="color: var(--error); margin-right: 0.5rem;"></i>
                    Annulation de Réservation
                </h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="hotel-info">
                    <p>Vous êtes sur le point d'annuler votre réservation pour :</p>
                    <strong id="hotelName"></strong>
                </div>
                
                <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 15px; padding: 1.5rem; margin: 1.5rem 0;">
                    <h4 style="color: var(--warning); margin-bottom: 1rem; font-size: 1.1rem;">
                        <i class="fas fa-info-circle"></i> Informations importantes
                    </h4>
                    <ul style="color: white; margin-left: 1.5rem; line-height: 1.8;">
                        <li>Vous pouvez annuler votre réservation à tout moment</li>
                        <li>Si vous avez payé, un remboursement sera traité dans 5-7 jours ouvrables</li>
                        <li>Cette action est définitive et irréversible</li>
                        <li>Vous recevrez un email de confirmation d'annulation</li>
                    </ul>
                </div>
                
                <p style="margin-top: 1rem; color: var(--error); font-weight: 600; text-align: center; font-size: 1.1rem;">
                    <i class="fas fa-exclamation-triangle"></i>
                    Êtes-vous sûr de vouloir annuler cette réservation ?
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn" style="background: #666; color: white;" onclick="closeModal()">
                    <i class="fas fa-arrow-left"></i>
                    Non, garder ma réservation
                </button>
                <form method="POST" id="cancellationForm" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="cancel_booking">
                    <input type="hidden" name="booking_id" id="cancellationBookingId">
                    <button type="submit" class="btn btn-cancel">
                        <i class="fas fa-trash-alt"></i>
                        Oui, annuler définitivement
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Fonction améliorée pour gérer le chargement des images
        function handleImageLoad(img) {
            const loadingIcon = img.parentElement.querySelector('.image-loading');
            if (loadingIcon) {
                loadingIcon.style.display = 'none';
            }
            img.classList.add('loaded');
            console.log('Image loaded successfully:', img.src);
        }

        // Fonction améliorée pour gérer les erreurs d'images
        function handleImageError(img) {
            const loadingIcon = img.parentElement.querySelector('.image-loading');
            if (loadingIcon) {
                loadingIcon.style.display = 'none';
            }
            
            console.log('Image failed to load:', img.src);
            
            // Utiliser l'image par défaut SVG
            img.classList.add('error');
            img.src = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzgwIiBoZWlnaHQ9IjIyMCIgdmlld0JveD0iMCAwIDM4MCAyMjAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxyZWN0IHdpZHRoPSIzODAiIGhlaWdodD0iMjIwIiBmaWxsPSIjMzMzMzMzIi8+CjxyZWN0IHg9IjE0MCIgeT0iODAiIHdpZHRoPSIxMDAiIGhlaWdodD0iNjAiIHJ4PSI4IiBmaWxsPSIjRDRBRjM3IiBvcGFjaXR5PSIwLjMiLz4KPGNpcmNsZSBjeD0iMTYwIiBjeT0iMTAwIiByPSI4IiBmaWxsPSIjRDRBRjM3Ii8+CjxwYXRoIGQ9Ik0xODAgMTIwIEwyMDAgMTAwIEwyMjAgMTIwIEwyMjAgMTMwIEwxODAgMTMwIFoiIGZpbGw9IiNENEFGMzciLz4KPHR4dCB4PSIxOTAiIHk9IjE1MCIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjEyIiBmaWxsPSIjRDRBRjM3IiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5BdGxhcyBIb3RlbHM8L3R4dD4KPHR4dCB4PSIxOTAiIHk9IjE3MCIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjEwIiBmaWxsPSIjQjg4NjBCIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5JbWFnZSBub24gZGlzcG9uaWJsZTwvdHh0Pgo8L3N2Zz4=';
            img.alt = 'Image non disponible - Atlas Hotels';
            console.log('Using default SVG image');
        }

        function confirmCancellation(bookingId, hotelName) {
            document.getElementById('cancellationBookingId').value = bookingId;
            document.getElementById('hotelName').textContent = hotelName;
            document.getElementById('cancellationModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('cancellationModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Fermer la modale si on clique en dehors
        document.getElementById('cancellationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Fermer la modale avec la touche Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Auto-hide alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 7000);

        // Animation d'entrée
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.booking-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });

        // Confirmation avant soumission du formulaire d'annulation
        document.getElementById('cancellationForm').addEventListener('submit', function(e) {
            const confirmed = confirm('DERNIÈRE CONFIRMATION : Voulez-vous vraiment annuler cette réservation ?');
            if (!confirmed) {
                e.preventDefault();
            } else {
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Annulation en cours...';
                submitBtn.disabled = true;
            }
        });
    </script>
</body>
</html>
