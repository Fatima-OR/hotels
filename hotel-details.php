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
    
    // Prévention des injections SQL
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
}

// Fonctions de base de données
function getHotelById($pdo, $hotelId) {
    try {
        $stmt = Security::prepareAndExecute($pdo, "
            SELECT 
                id,
                name as nom,
                description,
                location as quartier,
                city as ville,
                rating as note,
                image_url,
                amenities,
                is_featured,
                status,
                price_per_night
            FROM hotels 
            WHERE id = ? AND status = 'active'
        ", [$hotelId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Erreur getHotelById: " . $e->getMessage());
        return null;
    }
}

function getHotelRoomTypes($pdo, $hotelId, $checkIn = null, $checkOut = null) {
    try {
        $sql = "
            SELECT 
                rt.id,
                rt.name as nom,
                rt.description,
                rt.price_per_night,
                rt.max_occupancy as capacite,
                rt.size_sqm as superficie,
                rt.amenities,
                rt.image_url,
                COUNT(r.id) as total_rooms,
                (
                    SELECT COUNT(r2.id) 
                    FROM rooms r2 
                    WHERE r2.room_type_id = rt.id 
                    AND r2.hotel_id = rt.hotel_id 
                    AND r2.status = 'available'
                    AND r2.id NOT IN (
                        SELECT DISTINCT ra.room_id 
                        FROM room_availability ra 
                        WHERE ra.date >= ? AND ra.date < ? AND ra.status = 'booked'
                    )
                ) as available_rooms
            FROM room_types rt
            LEFT JOIN rooms r ON rt.id = r.room_type_id AND r.hotel_id = rt.hotel_id
            WHERE rt.hotel_id = ?
            GROUP BY rt.id, rt.name, rt.description, rt.price_per_night, rt.max_occupancy, rt.size_sqm, rt.amenities, rt.image_url
            ORDER BY rt.price_per_night ASC
        ";
        
        $stmt = Security::prepareAndExecute($pdo, $sql, [
            $checkIn ?: date('Y-m-d'),
            $checkOut ?: date('Y-m-d', strtotime('+1 day')),
            $hotelId
        ]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Erreur getHotelRoomTypes: " . $e->getMessage());
        return [];
    }
}

function getHotelImages($pdo, $hotelId) {
    try {
        $stmt = Security::prepareAndExecute($pdo, "
            SELECT image_url, alt_text, is_primary, display_order
            FROM hotel_images 
            WHERE hotel_id = ? 
            ORDER BY is_primary DESC, display_order ASC
        ", [$hotelId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Erreur getHotelImages: " . $e->getMessage());
        return [];
    }
}

function getHotelReviews($pdo, $hotelId, $limit = 10) {
    try {
        $stmt = Security::prepareAndExecute($pdo, "
            SELECT 
                r.id,
                r.rating,
                r.comment,
                r.created_at,
                u.full_name as user_name,
                r.is_verified
            FROM reviews r
            JOIN users u ON r.user_id = u.id
            WHERE r.hotel_id = ?
            ORDER BY r.created_at DESC
            LIMIT ?
        ", [$hotelId, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Erreur getHotelReviews: " . $e->getMessage());
        return [];
    }
}

function createBooking($pdo, $userId, $hotelId, $roomTypeId, $checkIn, $checkOut, $guests, $totalPrice, $specialRequests = '') {
    try {
        $pdo->beginTransaction();
        
        // Créer la réservation avec statut 'pending' (en attente de paiement)
        $stmt = Security::prepareAndExecute($pdo, "
            INSERT INTO bookings (
                user_id, hotel_id, room_type_id, check_in, check_out, 
                guests, total_price, special_requests, status, payment_status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', NOW())
        ", [
            $userId, $hotelId, $roomTypeId, $checkIn, $checkOut,
            $guests, $totalPrice, $specialRequests
        ]);
        
        $bookingId = $pdo->lastInsertId();
        
        // Réserver immédiatement une chambre disponible
        $stmt = Security::prepareAndExecute($pdo, "
            SELECT r.id 
            FROM rooms r 
            WHERE r.room_type_id = ? AND r.hotel_id = ? AND r.status = 'available'
            AND r.id NOT IN (
                SELECT DISTINCT room_id 
                FROM room_availability ra 
                WHERE ra.date >= ? AND ra.date < ? AND ra.status = 'booked'
            )
            LIMIT 1
        ", [$roomTypeId, $hotelId, $checkIn, $checkOut]);
        $room = $stmt->fetch();
        
        if ($room) {
            // Marquer la chambre comme réservée pour toutes les dates du séjour
            $currentDate = new DateTime($checkIn);
            $endDate = new DateTime($checkOut);
            
            while ($currentDate < $endDate) {
                $stmt = Security::prepareAndExecute($pdo, "
                    INSERT INTO room_availability (room_id, booking_id, date, status)
                    VALUES (?, ?, ?, 'booked')
                    ON DUPLICATE KEY UPDATE booking_id = ?, status = 'booked'
                ", [
                    $room['id'], 
                    $bookingId, 
                    $currentDate->format('Y-m-d'),
                    $bookingId
                ]);
                $currentDate->add(new DateInterval('P1D'));
            }
            
            $pdo->commit();
            return $bookingId;
        } else {
            $pdo->rollback();
            return false;
        }
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Erreur createBooking: " . $e->getMessage());
        return false;
    }
}

function validateBookingData($data) {
    $errors = [];
    
    // Validation des dates
    if (empty($data['check_in']) || empty($data['check_out'])) {
        $errors[] = 'Les dates d\'arrivée et de départ sont obligatoires';
    } else {
        $checkIn = new DateTime($data['check_in']);
        $checkOut = new DateTime($data['check_out']);
        $today = new DateTime();
        $today->setTime(0, 0, 0); // Réinitialiser l'heure pour comparer uniquement les dates
        
        if ($checkIn < $today) {
            $errors[] = 'La date d\'arrivée ne peut pas être dans le passé';
        }
        
        if ($checkOut <= $checkIn) {
            $errors[] = 'La date de départ doit être après la date d\'arrivée';
        }
    }
    
    // Validation du nombre d'invités
    if (empty($data['guests']) || $data['guests'] < 1 || $data['guests'] > 10) {
        $errors[] = 'Le nombre d\'invités doit être entre 1 et 10';
    }
    
    // Validation des demandes spéciales (optionnel mais limité)
    if (!empty($data['special_requests']) && strlen($data['special_requests']) > 1000) {
        $errors[] = 'Les demandes spéciales ne peuvent pas dépasser 1000 caractères';
    }
    
    return $errors;
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=bookings');
    exit;
}

// Récupérer l'ID de l'hôtel
$hotelId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$hotelId) {
    header('Location: hotels.php');
    exit;
}

// Récupérer les paramètres de recherche
$checkIn = filter_input(INPUT_GET, 'check_in', FILTER_SANITIZE_STRING) ?: date('Y-m-d');
$checkOut = filter_input(INPUT_GET, 'check_out', FILTER_SANITIZE_STRING) ?: date('Y-m-d', strtotime('+1 day'));
$guests = filter_input(INPUT_GET, 'guests', FILTER_VALIDATE_INT) ?: 2;

// Récupérer les informations de l'hôtel
$hotel = getHotelById($pdo, $hotelId);
if (!$hotel) {
    header('Location: hotels.php');
    exit;
}

// Récupérer les types de chambres disponibles
$roomTypes = getHotelRoomTypes($pdo, $hotelId, $checkIn, $checkOut);
$hotelImages = getHotelImages($pdo, $hotelId);
$reviews = getHotelReviews($pdo, $hotelId);
$amenities = json_decode($hotel['amenities'], true) ?? [];

$bookingSuccess = '';
$bookingError = '';

// Traitement du formulaire de réservation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    try {
        // Vérification du token CSRF
        if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Token de sécurité invalide');
        }

        // Sanitisation et validation des données
        $postData = Security::sanitizeInput($_POST);
        
        $roomTypeId = filter_var($postData['room_type_id'], FILTER_VALIDATE_INT);
        $checkInDate = $postData['check_in'];
        $checkOutDate = $postData['check_out'];
        $guestCount = filter_var($postData['guests'], FILTER_VALIDATE_INT);
        $specialRequests = $postData['special_requests'] ?? '';

        // Validation des données
        $validationErrors = validateBookingData([
            'check_in' => $checkInDate,
            'check_out' => $checkOutDate,
            'guests' => $guestCount,
            'special_requests' => $specialRequests
        ]);

        if (!empty($validationErrors)) {
            throw new Exception(implode(', ', $validationErrors));
        }

        if (!$roomTypeId) {
            throw new Exception('Veuillez sélectionner une chambre.');
        }

        // Vérifier que le type de chambre existe et appartient à cet hôtel
        $stmt = Security::prepareAndExecute($pdo, 
            "SELECT * FROM room_types WHERE id = ? AND hotel_id = ?", 
            [$roomTypeId, $hotelId]
        );
        $roomType = $stmt->fetch();

        if (!$roomType) {
            throw new Exception('Type de chambre invalide.');
        }

        // Vérifier la disponibilité
        $stmt = Security::prepareAndExecute($pdo, "
            SELECT COUNT(DISTINCT r.id) as available_rooms
            FROM rooms r
            WHERE r.room_type_id = ? AND r.hotel_id = ? AND r.status = 'available'
            AND r.id NOT IN (
                SELECT DISTINCT ra.room_id 
                FROM room_availability ra 
                WHERE ra.date >= ? AND ra.date < ? AND ra.status = 'booked'
            )
        ", [$roomTypeId, $hotelId, $checkInDate, $checkOutDate]);
        $availableRooms = $stmt->fetchColumn();

        if ($availableRooms <= 0) {
            throw new Exception('Aucune chambre disponible pour ces dates.');
        }

        // Calculer le prix total
        $checkInDateTime = new DateTime($checkInDate);
        $checkOutDateTime = new DateTime($checkOutDate);
        $nights = $checkInDateTime->diff($checkOutDateTime)->days;
        $totalPrice = $nights * $roomType['price_per_night'];

        // Créer la réservation
        $bookingId = createBooking(
            $pdo, 
            $_SESSION['user_id'], 
            $hotelId, 
            $roomTypeId, 
            $checkInDate, 
            $checkOutDate, 
            $guestCount, 
            $totalPrice, 
            $specialRequests
        );

        if ($bookingId) {
            // Stocker les données de réservation dans la session
            $_SESSION['booking_data'] = [
                'booking_id' => $bookingId,
                'hotel_id' => $hotelId,
                'hotel_name' => $hotel['nom'],
                'room_type_id' => $roomTypeId,
                'room_type_name' => $roomType['name'],
                'check_in' => $checkInDate,
                'check_out' => $checkOutDate,
                'guests' => $guestCount,
                'total_price' => $totalPrice,
                'special_requests' => $specialRequests,
                'nights' => $nights
            ];
            
            // Rediriger vers la page de paiement
            header('Location: payment.php');
            exit;
        } else {
            throw new Exception('Erreur lors de la création de la réservation. Aucune chambre disponible.');
        }

    } catch (Exception $e) {
        $bookingError = $e->getMessage();
        error_log("Erreur de réservation: " . $e->getMessage());
    }
}

// Générer le token CSRF
$csrfToken = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($hotel['nom']) ?> - Atlas Hotels</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-gold: #D4AF37;
            --secondary-gold: #B8860B;
            --dark-gold: #996F00;
            --champagne: #F7E7CE;
            --pearl: #F8F6F0;
            --deep-navy: #0F1419;
            --charcoal: #1A1A2E;
            --midnight: #0D1117;
            --platinum: #E5E4E2;
            --marble: #F5F5DC;
            --copper: #B87333;
            --bronze: #CD7F32;
            --shadow-gold: rgba(212, 175, 55, 0.4);
            --shadow-dark: rgba(0, 0, 0, 0.6);
            --gradient-luxury: linear-gradient(135deg, #D4AF37 0%, #B8860B 50%, #996F00 100%);
            --gradient-dark: linear-gradient(135deg, #0F1419 0%, #1A1A2E 100%);
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
        }

        /* Navigation */
        .navbar {
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            background: rgba(15, 20, 25, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
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
            background-clip: text;
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
            background-clip: text;
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

        /* Buttons */
        .btn-primary {
            background: var(--gradient-luxury);
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 10px 30px var(--shadow-gold);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px var(--shadow-gold);
        }

        .btn-secondary {
            background: transparent;
            color: var(--primary-gold);
            padding: 1rem 2rem;
            border: 2px solid var(--primary-gold);
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-secondary:hover {
            background: var(--gradient-luxury);
            color: white;
            transform: translateY(-3px);
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        /* Hotel Details Section */
        .hotel-details {
            padding: 2rem 0 6rem;
            background: black;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1rem 0;
        }

        .breadcrumb a {
            color: var(--primary-gold);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .breadcrumb a:hover {
            color: white;
        }

        .breadcrumb i {
            color: goldenrod;
            font-size: 0.8rem;
        }

        .breadcrumb span {
            color: white;
        }

        /* Hotel Gallery */
        .hotel-gallery {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            grid-template-rows: 250px 250px;
            gap: 1rem;
            margin-bottom: 3rem;
            border-radius: 20px;
            overflow: hidden;
        }

        .gallery-item {
            position: relative;
            overflow: hidden;
        }

        .gallery-item:first-child {
            grid-row: 1 / 3;
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .gallery-item:hover img {
            transform: scale(1.1);
        }

        /* Hotel Info */
        .hotel-info {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 3rem;
            align-items: start;
        }

        .hotel-main-info {
            background: rgba(212, 175, 55, 0.05);
            padding: 2rem;
            border-radius: 20px;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .hotel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 2rem;
        }

        .hotel-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.5rem;
            font-weight: 700;
            color: goldenrod;
            margin-bottom: 1rem;
        }

        .hotel-meta {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .hotel-rating {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .hotel-rating .fas.fa-star {
            color: #ccc;
        }

        .hotel-rating .fas.fa-star.active {
            color: var(--primary-gold);
        }

        .rating-text {
            color: white;
            font-weight: 500;
        }

        .hotel-location {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
        }

        .hotel-location i {
            color: var(--primary-gold);
        }

        .featured-badge {
            background: var(--gradient-luxury);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .hotel-description {
            color: white;
            line-height: 1.8;
            margin-bottom: 2rem;
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: goldenrod;
            margin-bottom: 1.5rem;
        }

        /* Amenities */
        .amenities-section {
            margin-bottom: 3rem;
        }

        .hotel-amenities {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .amenity {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0;
        }

        .amenity i {
            color: var(--primary-gold);
        }

        .amenity span {
            color: white;
        }

        /* Room Types */
        .room-types-section {
            margin-bottom: 3rem;
        }

        .room-types-grid {
            display: grid;
            gap: 2rem;
        }

        .room-type-card {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
            background: rgba(212, 175, 55, 0.05);
            border-radius: 15px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .room-type-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px var(--shadow-dark);
        }

        .room-image {
            height: 200px;
            overflow: hidden;
        }

        .room-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .room-type-card:hover .room-image img {
            transform: scale(1.1);
        }

        .room-content {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
        }

        .room-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: goldenrod;
            margin-bottom: 0.5rem;
        }

        .room-description {
            color: white;
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .room-details {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .room-detail {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            color: white;
            font-size: 0.9rem;
        }

        .room-detail i {
            color: var(--primary-gold);
        }

        .room-amenities {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .room-amenity {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            background: rgba(212, 175, 55, 0.1);
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            color: white;
        }

        .room-amenity i {
            color: var(--primary-gold);
        }

        .room-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: auto;
        }

        .room-price {
            display: flex;
            flex-direction: column;
        }

        .price-amount {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-gold);
        }

        .price-period {
            color: white;
            font-size: 0.9rem;
        }

        /* Booking Card */
        .booking-card {
            background: rgba(212, 175, 55, 0.05);
            padding: 2rem;
            border-radius: 20px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            height: fit-content;
            position: sticky;
            top: 100px;
        }

        .booking-header h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 600;
            color: goldenrod;
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .form-label {
            color: goldenrod;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-input,
        .form-select,
        .form-textarea {
            padding: 1rem;
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* Room Selection */
        .room-selection {
            margin-top: 1rem;
        }

        .room-option {
            background: rgba(0, 0, 0, 0.3);
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .room-option:hover {
            background: rgba(212, 175, 55, 0.1);
            border-color: var(--primary-gold);
        }

        .room-option.selected {
            border-color: var(--primary-gold);
            background: rgba(212, 175, 55, 0.15);
        }

        .room-option-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .room-option-name {
            color: goldenrod;
            font-weight: 600;
        }

        .room-option-price {
            color: var(--primary-gold);
            font-weight: 600;
        }

        .room-option-description {
            color: white;
            font-size: 0.9rem;
        }

        /* Footer */
        .footer {
            background: var(--deep-navy);
            padding: 4rem 0 2rem;
            border-top: 1px solid rgba(212, 175, 55, 0.2);
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
            margin-bottom: 2rem;
        }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .footer-logo i {
            font-size: 2rem;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .footer-description {
            color: white;
            line-height: 1.6;
        }

        .footer-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-gold);
            margin-bottom: 1rem;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
        }

        .footer-links a {
            color: white;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--primary-gold);
        }

        .contact-info p {
            color: white;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .contact-info i {
            color: var(--primary-gold);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(212, 175, 55, 0.2);
            color: white;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .hotel-info {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .booking-card {
                position: static;
            }
        }

        @media (max-width: 768px) {
            .hotel-gallery {
                grid-template-columns: 1fr;
                grid-template-rows: repeat(3, 200px);
            }

            .gallery-item:first-child {
                grid-row: 1;
            }

            .room-type-card {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .room-image {
                height: 250px;
            }

            .room-details {
                flex-direction: column;
                gap: 0.5rem;
            }

            .room-footer {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .nav-menu {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar" id="navbar">
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
                <a href="hotels.php" class="nav-link active">Hôtels</a>
                <a href="user.php" class="nav-link">Profile</a>
                <a href="about.php" class="nav-link">About</a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="bookings.php" class="nav-link">Mes Réservations</a>
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

    <!-- Hotel Details -->
    <section class="hotel-details">
        <div class="container">
            <!-- Breadcrumb -->
            <nav class="breadcrumb">
                <a href="index.php">Accueil</a>
                <i class="fas fa-chevron-right"></i>
                <a href="hotels.php">Hôtels</a>
                <i class="fas fa-chevron-right"></i>
                <span><?= htmlspecialchars($hotel['nom']) ?></span>
            </nav>

            <!-- Alert Messages -->
            <?php if ($bookingSuccess): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($bookingSuccess) ?>
                </div>
            <?php endif; ?>

            <?php if ($bookingError): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($bookingError) ?>
                </div>
            <?php endif; ?>

            <!-- Hotel Gallery -->
            <div class="hotel-gallery">
                <?php if (!empty($hotelImages)): ?>
                    <?php foreach (array_slice($hotelImages, 0, 5) as $index => $image): ?>
                        <div class="gallery-item">
                            <img src="<?= htmlspecialchars($image['image_url']) ?>" 
                                 alt="<?= htmlspecialchars($image['alt_text'] ?: $hotel['nom']) ?>">
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Images par défaut si aucune image n'est disponible -->
                    <div class="gallery-item">
                        <img src="<?= htmlspecialchars($hotel['image_url']) ?>" 
                             alt="<?= htmlspecialchars($hotel['nom']) ?>">
                    </div>
                    <div class="gallery-item">
                        <img src="https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=400" 
                             alt="Suite Luxe">
                    </div>
                    <div class="gallery-item">
                        <img src="https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=400" 
                             alt="Restaurant">
                    </div>
                    <div class="gallery-item">
                        <img src="https://images.pexels.com/photos/1571463/pexels-photo-1571463.jpeg?auto=compress&cs=tinysrgb&w=400" 
                             alt="Spa">
                    </div>
                    <div class="gallery-item">
                        <img src="https://images.pexels.com/photos/1838554/pexels-photo-1838554.jpeg?auto=compress&cs=tinysrgb&w=400" 
                             alt="Piscine">
                    </div>
                <?php endif; ?>
            </div>

            <!-- Hotel Information -->
            <div class="hotel-info">
                <div class="hotel-main-info">
                    <div class="hotel-header">
                        <div class="hotel-title-section">
                            <h1 class="hotel-title"><?= htmlspecialchars($hotel['nom']) ?></h1>
                            <div class="hotel-meta">
                                <div class="hotel-rating">
                                    <?php
                                    $rating = (int)$hotel['note'];
                                    for ($i = 1; $i <= 5; $i++) {
                                        $class = $i <= $rating ? 'fas fa-star active' : 'fas fa-star';
                                        echo "<i class=\"$class\"></i>";
                                    }
                                    ?>
                                    <span class="rating-text"><?= htmlspecialchars($hotel['note']) ?>/5</span>
                                </div>
                                <div class="hotel-location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?= htmlspecialchars($hotel['quartier']) ?>, <?= htmlspecialchars($hotel['ville']) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="featured-badge">
                            <i class="fas fa-crown"></i>
                            Hôtel d'Exception
                        </div>
                    </div>

                    <div class="hotel-description">
                        <p><?= nl2br(htmlspecialchars($hotel['description'])) ?></p>
                    </div>

                    <!-- Amenities Section -->
                    <div class="amenities-section">
                        <h2 class="section-title">Services & Équipements</h2>
                        <div class="hotel-amenities">
                            <?php if (!empty($amenities)): ?>
                                <?php foreach ($amenities as $amenity): ?>
                                    <div class="amenity">
                                        <i class="fas fa-check"></i>
                                        <span><?= htmlspecialchars($amenity) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <!-- Équipements par défaut -->
                                <div class="amenity">
                                    <i class="fas fa-wifi"></i>
                                    <span>WiFi Gratuit</span>
                                </div>
                                <div class="amenity">
                                    <i class="fas fa-swimming-pool"></i>
                                    <span>Piscine</span>
                                </div>
                                <div class="amenity">
                                    <i class="fas fa-spa"></i>
                                    <span>Spa</span>
                                </div>
                                <div class="amenity">
                                    <i class="fas fa-utensils"></i>
                                    <span>Restaurant</span>
                                </div>
                                <div class="amenity">
                                    <i class="fas fa-dumbbell"></i>
                                    <span>Salle de Sport</span>
                                </div>
                                <div class="amenity">
                                    <i class="fas fa-car"></i>
                                    <span>Parking</span>
                                </div>
                                <div class="amenity">
                                    <i class="fas fa-concierge-bell"></i>
                                    <span>Conciergerie 24h/24</span>
                                </div>
                                <div class="amenity">
                                    <i class="fas fa-glass-cheers"></i>
                                    <span>Bar Lounge</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Room Types Section -->
                    <?php if (!empty($roomTypes)): ?>
                        <div class="room-types-section">
                            <h2 class="section-title">Types de Chambres Disponibles</h2>
                            <div class="room-types-grid">
                                <?php foreach ($roomTypes as $roomType): ?>
                                    <div class="room-type-card">
                                        <div class="room-image">
                                            <img src="<?= htmlspecialchars($roomType['image_url'] ?: 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg?auto=compress&cs=tinysrgb&w=400') ?>" 
                                                 alt="<?= htmlspecialchars($roomType['nom']) ?>">
                                        </div>
                                        <div class="room-content">
                                            <h3 class="room-name"><?= htmlspecialchars($roomType['nom']) ?></h3>
                                            <p class="room-description"><?= htmlspecialchars($roomType['description']) ?></p>
                                            <div class="room-details">
                                                <div class="room-detail">
                                                    <i class="fas fa-users"></i>
                                                    <span><?= htmlspecialchars($roomType['capacite']) ?> Personnes</span>
                                                </div>
                                                <div class="room-detail">
                                                    <i class="fas fa-expand"></i>
                                                    <span><?= htmlspecialchars($roomType['superficie']) ?> m²</span>
                                                </div>
                                                <?php if (isset($roomType['available_rooms'])): ?>
                                                    <div class="room-detail">
                                                        <i class="fas fa-door-open"></i>
                                                        <span><?= $roomType['available_rooms'] ?> disponible(s)</span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="room-footer">
                                                <div class="room-price">
                                                    <span class="price-amount"><?= number_format($roomType['price_per_night'], 0, ',', ' ') ?> MAD</span>
                                                    <span class="price-period">par nuit</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Booking Card -->
                <div class="booking-card">
                    <div class="booking-header">
                        <h3>Réservez votre séjour</h3>
                    </div>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        
                        <div class="form-group">
                            <label class="form-label">Date d'arrivée</label>
                            <input type="date" name="check_in" class="form-input" 
                                   value="<?= htmlspecialchars($checkIn) ?>" 
                                   min="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Date de départ</label>
                            <input type="date" name="check_out" class="form-input" 
                                   value="<?= htmlspecialchars($checkOut) ?>" 
                                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Nombre d'invités</label>
                            <select name="guests" class="form-select" required>
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <option value="<?= $i ?>" <?= $guests == $i ? 'selected' : '' ?>>
                                        <?= $i ?> <?= $i == 1 ? 'Personne' : 'Personnes' ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <?php if (!empty($roomTypes)): ?>
                            <div class="form-group">
                                <label class="form-label">Type de chambre</label>
                                <div class="room-selection">
                                    <?php foreach ($roomTypes as $roomType): ?>
                                        <div class="room-option">
                                            <input type="radio" name="room_type_id" value="<?= $roomType['id'] ?>" 
                                                   id="room_<?= $roomType['id'] ?>" required style="display: none;">
                                            <label for="room_<?= $roomType['id'] ?>" style="cursor: pointer; display: block;">
                                                <div class="room-option-header">
                                                    <span class="room-option-name"><?= htmlspecialchars($roomType['nom']) ?></span>
                                                    <span class="room-option-price"><?= number_format($roomType['price_per_night'], 0, ',', ' ') ?> MAD</span>
                                                </div>
                                                <div class="room-option-description"><?= htmlspecialchars($roomType['description']) ?></div>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label">Demandes spéciales (optionnel)</label>
                            <textarea name="special_requests" class="form-textarea" 
                                      placeholder="Indiquez vos préférences ou demandes spéciales..."></textarea>
                        </div>

                         <button type="submit" class="btn-primary" style="width: 100%;">
                            <i class="fas fa-calendar-check"></i>
                            Réserver maintenant
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <div class="footer-logo">
                        <i class="fas fa-crown"></i>
                        <div class="logo-text">
                            <span class="logo-main">Atlas Hotels</span>
                            <span class="logo-sub">Luxury Collection</span>
                        </div>
                    </div>
                    <p class="footer-description">
                        Découvrez l'excellence hôtelière avec Atlas Hotels. Une collection d'établissements de luxe 
                        qui vous offrent des expériences inoubliables à travers le Maroc.
                    </p>
                </div>
                
                <div class="footer-section">
                    <h3 class="footer-title">Services</h3>
                    <ul class="footer-links">
                        <li><a href="#">Spa & Wellness</a></li>
                        <li><a href="#">Restaurants</a></li>
                        <li><a href="#">Événements</a></li>
                        <li><a href="#">Conciergerie</a></li>
                        <li><a href="#">Transport</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h3 class="footer-title">Contact</h3>
                    <div class="contact-info">
                        <p><i class="fas fa-map-marker-alt"></i> Hivernage, Marrakech, Maroc</p>
                        <p><i class="fas fa-phone"></i> +212 524 123 456</p>
                        <p><i class="fas fa-envelope"></i> contact@atlashotels.ma</p>
                        <p><i class="fas fa-globe"></i> www.atlashotels.ma</p>
                    </div>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; 2024 Atlas Hotels. Tous droits réservés.</p>
            </div>
        </div>
    </footer>

     <script>
        // Gestion de la sélection des chambres
        document.querySelectorAll('.room-option').forEach(option => {
            option.addEventListener('click', function() {
                // Retirer la sélection de toutes les options
                document.querySelectorAll('.room-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                // Ajouter la sélection à l'option cliquée
                this.classList.add('selected');
                
                // Cocher le radio button correspondant
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                }
            });
        });

        // Validation des dates
        const checkinInput = document.querySelector('input[name="check_in"]');
        const checkoutInput = document.querySelector('input[name="check_out"]');

        if (checkinInput && checkoutInput) {
            checkinInput.addEventListener('change', function() {
                const checkinDate = new Date(this.value);
                const minCheckoutDate = new Date(checkinDate);
                minCheckoutDate.setDate(minCheckoutDate.getDate() + 1);
                
                checkoutInput.min = minCheckoutDate.toISOString().split('T')[0];
                
                // Si la date de départ est antérieure à la nouvelle date minimum, la réinitialiser
                if (checkoutInput.value && new Date(checkoutInput.value) <= checkinDate) {
                    checkoutInput.value = minCheckoutDate.toISOString().split('T')[0];
                }
            });
        }

        // Validation du formulaire avant soumission
        document.querySelector('form').addEventListener('submit', function(e) {
            const checkin = checkinInput.value;
            const checkout = checkoutInput.value;
            const roomSelected = document.querySelector('input[name="room_type_id"]:checked');

            if (!checkin || !checkout) {
                e.preventDefault();
                alert('Veuillez sélectionner les dates d\'arrivée et de départ.');
                return;
            }

            if (new Date(checkout) <= new Date(checkin)) {
                e.preventDefault();
                alert('La date de départ doit être après la date d\'arrivée.');
                return;
            }

            if (!roomSelected) {
                e.preventDefault();
                alert('Veuillez sélectionner un type de chambre.');
                return;
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>
