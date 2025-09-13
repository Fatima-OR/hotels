<?php

function authenticateUser(PDO $pdo, string $email, string $password) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        return $user;
    }
    return false;
}

// Sanitize user input
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
function getAvailableRoomTypes(PDO $pdo, int $hotelId, string $checkIn, string $checkOut): array {
    try {
        $sql = "
            SELECT 
                rt.id,
                rt.name,
                rt.description,
                rt.price_per_night,
                rt.max_occupancy,
                rt.size_sqm,
                rt.image_url,
                rt.amenities,
                rt.available_rooms,
                -- Calcul de la dispo réelle selon les réservations pendant la période demandée
                (rt.available_rooms - COALESCE(SUM(b.guests), 0)) AS available_rooms
            FROM room_types rt
            LEFT JOIN bookings b ON b.room_type_id = rt.id
                AND b.status IN ('pending', 'confirmed', 'checked_in')
                AND NOT (
                    b.check_out <= :checkIn OR
                    b.check_in >= :checkOut
                )
            WHERE rt.hotel_id = :hotelId
            GROUP BY rt.id
            HAVING available_rooms > 0
            ORDER BY rt.price_per_night ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':hotelId' => $hotelId,
            ':checkIn' => $checkIn,
            ':checkOut' => $checkOut
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Erreur getAvailableRoomTypes: " . $e->getMessage());
        return [];
    }
}


// Récupérer les hôtels en vedette
function getFeaturedHotels($pdo) {
    try {
        // Correction: utiliser 'is_featured' au lieu de 'featured' selon le schéma DB
        $stmt = $pdo->prepare("SELECT * FROM hotels WHERE is_featured = 1 AND status = 'active' LIMIT 5");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getFeaturedHotels: " . $e->getMessage());
        return [];
    }
}

// Enregistrer un nouvel utilisateur
function registerUser($pdo, $email, $password, $fullName, $phone, $accountType = 'client') {
    try {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $status = ($accountType === 'admin') ? 'pending' : 'active';

        $stmt = $pdo->prepare("
            INSERT INTO users (email, password, full_name, phone, account_type, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        return $stmt->execute([
            $email, 
            $hashedPassword, 
            $fullName, 
            $phone, 
            $accountType, 
            $status
        ]);
    } catch (PDOException $e) {
        error_log("Erreur registerUser: " . $e->getMessage());
        return false;
    }
}

// Récupérer tous les hôtels (avec filtres optionnels)
function getAllHotels($pdo, $city = null, $maxPrice = null) {
    try {
        $query = "SELECT * FROM hotels WHERE status = 'active'";
        $params = [];

        if ($city) {
            $query .= " AND city = :city";
            $params[':city'] = $city;
        }

        if ($maxPrice) {
            $query .= " AND price_per_night <= :maxPrice";
            $params[':maxPrice'] = $maxPrice;
        }

        $query .= " ORDER BY name ASC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getAllHotels: " . $e->getMessage());
        return [];
    }
}

// Récupérer la liste des villes
function getCities($pdo) {
    try {
        $stmt = $pdo->query("SELECT DISTINCT city FROM hotels WHERE status = 'active' ORDER BY city");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Erreur getCities: " . $e->getMessage());
        return [];
    }
}

// Récupérer les détails d'un hôtel par ID
function getHotelById($pdo, $hotelId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM hotels WHERE id = :id AND status = 'active'");
        $stmt->execute([':id' => $hotelId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getHotelById: " . $e->getMessage());
        return false;
    }
}

// Récupérer les images associées à un hôtel
function getHotelImages($pdo, $hotelId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM hotel_images WHERE hotel_id = :hotel_id ORDER BY is_primary DESC, id ASC");
        $stmt->execute([':hotel_id' => $hotelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getHotelImages: " . $e->getMessage());
        return [];
    }
}

// Récupérer les avis pour un hôtel donné
function getHotelReviews($pdo, $hotelId) {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, u.full_name 
            FROM reviews r
            JOIN users u ON r.user_id = u.id
            WHERE r.hotel_id = :hotel_id
            AND r.status = 'approved'
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([':hotel_id' => $hotelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getHotelReviews: " . $e->getMessage());
        return [];
    }
}

// Récupérer les types de chambres d'un hôtel
function getHotelRoomTypes($pdo, $hotelId, $checkIn = null, $checkOut = null) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM room_types WHERE hotel_id = ? ORDER BY price_per_night ASC");
        $stmt->execute([$hotelId]);
        $roomTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $roomTypes;
    } catch (PDOException $e) {
        error_log("Erreur getHotelRoomTypes: " . $e->getMessage());
        return [];
    }
}

// Générer le HTML pour les types de chambres (fonction séparée pour la logique d'affichage)
function generateRoomTypesHTML($roomTypes, $checkIn = null, $checkOut = null) {
    $html = '';
    
    if (empty($roomTypes)) {
        return '<p class="text-muted">Aucune chambre disponible pour cet hôtel.</p>';
    }

    foreach ($roomTypes as $room) {
        $amenities = $room['amenities'] ?? [];
        if (is_string($amenities)) {
            $amenities = json_decode($amenities, true) ?? [];
        }

        $html .= '<div class="room-card">';
        
        // Image de la chambre
        $imageUrl = !empty($room['image_url']) ? htmlspecialchars($room['image_url']) : 'assets/images/default-room.jpg';
        $roomName = !empty($room['name']) ? htmlspecialchars($room['name']) : 'Chambre';
        $html .= '<img src="' . $imageUrl . '" alt="' . $roomName . '" class="room-image">';
        
        // Informations de la chambre
        $html .= '<div class="room-info">';
        $html .= '<h3>' . $roomName . '</h3>';
        
        $description = !empty($room['description']) ? htmlspecialchars($room['description']) : 'Pas de description disponible.';
        $html .= '<p class="room-description">' . $description . '</p>';
        
        // Détails de la chambre
        $html .= '<div class="room-details">';
        $maxOccupancy = $room['max_occupancy'] ?? 1;
        $html .= '<p><i class="fas fa-users"></i> Jusqu\'à ' . intval($maxOccupancy) . ' personne(s)</p>';
        
        if (!empty($room['size_sqm'])) {
            $html .= '<p><i class="fas fa-ruler-combined"></i> ' . intval($room['size_sqm']) . ' m²</p>';
        }
        
        $availableRooms = $room['available_rooms'] ?? 0;
        $html .= '<p><i class="fas fa-bed"></i> ' . intval($availableRooms) . ' disponible(s)</p>';
        $html .= '</div>';

        // Équipements
        if (!empty($amenities)) {
            $html .= '<div class="room-amenities">';
            $html .= '<h4>Équipements:</h4>';
            $html .= '<ul>';
            foreach ($amenities as $amenity) {
                $html .= '<li><i class="fas fa-check"></i> ' . htmlspecialchars($amenity) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        // Prix et bouton de réservation
        $html .= '<div class="room-booking">';
        $pricePerNight = $room['price_per_night'] ?? 0;
        $html .= '<p class="room-price">' . number_format($pricePerNight, 2) . ' €/nuit</p>';

        if ($availableRooms > 0) {
            $html .= '<a href="booking.php?room_id=' . intval($room['id']) . '" class="btn btn-primary">Réserver</a>';
        } else {
            $html .= '<span class="btn btn-secondary disabled">Non disponible</span>';
        }
        $html .= '</div>';
        
        $html .= '</div>'; // Fermeture room-info
        $html .= '</div>'; // Fermeture room-card
    }

    return $html;
}

// Fonction pour calculer la note moyenne d'un hôtel
function getHotelAverageRating($pdo, $hotelId) {
    try {
        $stmt = $pdo->prepare("
            SELECT AVG(rating) as average_rating, COUNT(*) as total_reviews 
            FROM reviews 
            WHERE hotel_id = :hotel_id AND status = 'approved'
        ");
        $stmt->execute([':hotel_id' => $hotelId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'average_rating' => round($result['average_rating'], 1),
            'total_reviews' => $result['total_reviews']
        ];
    } catch (PDOException $e) {
        error_log("Erreur getHotelAverageRating: " . $e->getMessage());
        return ['average_rating' => 0, 'total_reviews' => 0];
    }
}

// Fonction pour vérifier la disponibilité d'une chambre
function checkRoomAvailability($pdo, $roomId, $checkIn, $checkOut) {
    try {
        $stmt = $pdo->prepare("
            SELECT rt.available_rooms,
                   COALESCE(SUM(b.quantity), 0) as booked_rooms
            FROM room_types rt
            LEFT JOIN bookings b ON rt.id = b.room_type_id 
                AND b.status IN ('confirmed', 'pending')
                AND (
                    (b.check_in_date <= :check_in AND b.check_out_date > :check_in) OR
                    (b.check_in_date < :check_out AND b.check_out_date >= :check_out) OR
                    (b.check_in_date >= :check_in AND b.check_out_date <= :check_out)
                )
            WHERE rt.id = :room_id
            GROUP BY rt.id, rt.available_rooms
        ");
        
        $stmt->execute([
            ':room_id' => $roomId,
            ':check_in' => $checkIn,
            ':check_out' => $checkOut
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $availableRooms = $result['available_rooms'] - $result['booked_rooms'];
            return max(0, $availableRooms);
        }
        
        return 0;
    } catch (PDOException $e) {
        error_log("Erreur checkRoomAvailability: " . $e->getMessage());
        return 0;
    }
}function getUserBookings(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT b.id, b.check_in, b.check_out, b.status, r.name AS room_name, h.name AS hotel_name
        FROM bookings b
        JOIN room_types r ON b.room_type_id = r.id
        JOIN hotels h ON b.hotel_id = h.id
        WHERE b.user_id = ?
        ORDER BY b.check_in DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCurrentUser() {
    global $pdo;

    if (!isset($_SESSION['user_id'])) {
        return null;
    }

  $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function formatDate($date) {
    if (!$date) return 'Non renseignée';
    return date('d/m/Y', strtotime($date));
}


?>