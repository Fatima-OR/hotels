<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuration de la base de données
$host = 'localhost';
$dbname = 'atlass_hotels';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Classe pour la gestion avancée des disponibilités
class AdvancedAvailabilityManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function adaptRoomAvailabilityTable() {
        try {
            // Vérifier si la table existe
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'room_availability'");
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                // Créer la table si elle n'existe pas
                $createTable = "
                    CREATE TABLE room_availability (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        room_id INT NOT NULL,
                        date DATE NOT NULL,
                        status ENUM('available', 'occupied', 'maintenance') DEFAULT 'available',
                        reason VARCHAR(255) DEFAULT NULL,
                        price_override DECIMAL(10,2) DEFAULT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
                        UNIQUE KEY unique_room_date (room_id, date),
                        INDEX idx_date (date),
                        INDEX idx_status (status)
                    )
                ";
                $this->pdo->exec($createTable);
            } else {
                // Vérifier et ajouter les colonnes manquantes
                $this->addMissingColumns();
            }
            
            return true;
        } catch(PDOException $e) {
            // Si erreur, continuer sans bloquer
            error_log("Erreur adaptation table: " . $e->getMessage());
            return false;
        }
    }
    
    private function addMissingColumns() {
        $columns = ['reason', 'price_override', 'created_at', 'updated_at'];
        
        foreach ($columns as $column) {
            try {
                switch ($column) {
                    case 'reason':
                        $this->pdo->exec("ALTER TABLE room_availability ADD COLUMN reason VARCHAR(255) DEFAULT NULL");
                        break;
                    case 'price_override':
                        $this->pdo->exec("ALTER TABLE room_availability ADD COLUMN price_override DECIMAL(10,2) DEFAULT NULL");
                        break;
                    case 'created_at':
                        $this->pdo->exec("ALTER TABLE room_availability ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
                        break;
                    case 'updated_at':
                        $this->pdo->exec("ALTER TABLE room_availability ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
                        break;
                }
            } catch(PDOException $e) {
                // Ignorer si la colonne existe déjà
                if (strpos($e->getMessage(), 'Duplicate column name') === false) {
                    error_log("Erreur ajout colonne $column: " . $e->getMessage());
                }
            }
        }
    }
    
    public function setRoomAvailability($roomId, $startDate, $endDate, $status, $reason = null, $priceOverride = null) {
        try {
            // Validation des dates
            if (strtotime($startDate) === false || strtotime($endDate) === false) {
                throw new Exception("Format de date invalide");
            }
            
            if (strtotime($startDate) > strtotime($endDate)) {
                throw new Exception("La date de début doit être antérieure à la date de fin");
            }
            
            // Supprimer les anciennes disponibilités pour cette période
            $deleteStmt = $this->pdo->prepare("DELETE FROM room_availability WHERE room_id = ? AND date BETWEEN ? AND ?");
            $deleteStmt->execute([$roomId, $startDate, $endDate]);
            
            // Créer les nouvelles entrées
            $insertStmt = $this->pdo->prepare("
                INSERT INTO room_availability (room_id, date, status, reason, price_override) 
                VALUES (?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status), 
                    reason = VALUES(reason), 
                    price_override = VALUES(price_override),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $currentDate = new DateTime($startDate);
            $endDateTime = new DateTime($endDate);
            $datesProcessed = 0;
            
            while ($currentDate <= $endDateTime) {
                $insertStmt->execute([
                    $roomId, 
                    $currentDate->format('Y-m-d'), 
                    $status, 
                    $reason, 
                    $priceOverride
                ]);
                $currentDate->add(new DateInterval('P1D'));
                $datesProcessed++;
                
                if ($datesProcessed > 365) {
                    throw new Exception("Période trop longue (maximum 365 jours)");
                }
            }
            
            return $datesProcessed;
        } catch(Exception $e) {
            throw $e;
        }
    }
    
    public function getAvailabilityStats($hotelId = null) {
        try {
            $whereClause = $hotelId ? "WHERE r.hotel_id = ?" : "";
            $params = $hotelId ? [$hotelId] : [];
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT r.id) as total_rooms,
                    COUNT(DISTINCT CASE WHEN r.status = 'available' THEN r.id END) as available_rooms,
                    COUNT(DISTINCT CASE WHEN r.status = 'occupied' THEN r.id END) as occupied_rooms,
                    COUNT(DISTINCT CASE WHEN r.status = 'maintenance' THEN r.id END) as maintenance_rooms
                FROM rooms r
                LEFT JOIN hotels h ON r.hotel_id = h.id
                $whereClause
            ");
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [
                'total_rooms' => 0,
                'available_rooms' => 0,
                'occupied_rooms' => 0,
                'maintenance_rooms' => 0
            ];
        }
    }
    
    public function getUpcomingUnavailableDates($roomId, $limit = 5) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT date, status, reason 
                FROM room_availability 
                WHERE room_id = ? AND date >= CURDATE() AND status != 'available'
                ORDER BY date ASC 
                LIMIT ?
            ");
            $stmt->execute([$roomId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
}

// Classe pour la gestion des réservations (utilise la table bookings)
class ReservationManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function getReservationStats() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_reservations,
                    COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_reservations,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_reservations,
                    COUNT(CASE WHEN status = 'checked_in' THEN 1 END) as checked_in_reservations,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_reservations,
                    COALESCE(SUM(CASE WHEN status IN ('confirmed', 'checked_in', 'checked_out') THEN total_price ELSE 0 END), 0) as total_revenue,
                    ROUND(AVG(DATEDIFF(check_out, check_in)), 1) as average_stay_duration
                FROM bookings
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [
                'total_reservations' => 0,
                'confirmed_reservations' => 0,
                'pending_reservations' => 0,
                'checked_in_reservations' => 0,
                'cancelled_reservations' => 0,
                'total_revenue' => 0,
                'average_stay_duration' => 0
            ];
        }
    }

    public function getUpcomingArrivals($limit = 10) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT b.*, h.name AS hotel_name, 
                       rt.name AS room_type_name,
                       u.full_name AS guest_name,
                       u.email AS guest_email
                FROM bookings b
                LEFT JOIN hotels h ON b.hotel_id = h.id
                LEFT JOIN room_types rt ON b.room_type_id = rt.id
                LEFT JOIN users u ON b.user_id = u.id
                WHERE b.check_in >= CURDATE() AND b.status IN ('confirmed', 'pending')
                ORDER BY b.check_in ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }

    public function getTodayArrivals() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT b.*, h.name AS hotel_name, 
                       rt.name AS room_type_name,
                       u.full_name AS guest_name,
                       u.email AS guest_email
                FROM bookings b
                LEFT JOIN hotels h ON b.hotel_id = h.id
                LEFT JOIN room_types rt ON b.room_type_id = rt.id
                LEFT JOIN users u ON b.user_id = u.id
                WHERE b.check_in = CURDATE() AND b.status IN ('confirmed', 'pending')
                ORDER BY b.created_at DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }

    public function getTodayDepartures() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT b.*, h.name AS hotel_name, 
                       rt.name AS room_type_name,
                       u.full_name AS guest_name,
                       u.email AS guest_email
                FROM bookings b
                LEFT JOIN hotels h ON b.hotel_id = h.id
                LEFT JOIN room_types rt ON b.room_type_id = rt.id
                LEFT JOIN users u ON b.user_id = u.id
                WHERE b.check_out = CURDATE() AND b.status = 'checked_in'
                ORDER BY b.created_at DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }

    public function getMonthlyRevenue() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    SUM(CASE WHEN status IN ('confirmed', 'checked_in', 'checked_out') THEN total_price ELSE 0 END) as revenue
                FROM bookings
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month ASC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }

    public function getAllReservations() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT b.*, 
                       h.name AS hotel_name, 
                       rt.name AS room_type_name,
                       u.full_name AS guest_name,
                       u.email AS guest_email,
                       u.phone AS guest_phone,
                       u.address AS guest_address,
                       b.confirmation_code AS booking_reference,
                       'N/A' AS room_number
                FROM bookings b
                LEFT JOIN hotels h ON b.hotel_id = h.id
                LEFT JOIN room_types rt ON b.room_type_id = rt.id
                LEFT JOIN users u ON b.user_id = u.id
                ORDER BY b.created_at DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
}

// Initialisation des managers
$availabilityManager = new AdvancedAvailabilityManager($pdo);
$reservationManager = new ReservationManager($pdo);

// Adapter les tables au démarrage
try {
    $availabilityManager->adaptRoomAvailabilityTable();
} catch(Exception $e) {
    error_log("Erreur d'adaptation de table: " . $e->getMessage());
}

// Traitement des actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_reservation':
                $requiredFields = ['hotel_id', 'room_type_id', 'guest_name', 'guest_email', 'check_in_date', 'check_out_date', 'total_amount'];
                foreach ($requiredFields as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception("Le champ '$field' est requis");
                    }
                }
                
                // Génération d'un code de confirmation
                $confirmationCode = 'ATL' . date('Y') . str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);
                
                // Vérifier si l'utilisateur existe ou le créer
                $userStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $userStmt->execute([$_POST['guest_email']]);
                $userId = $userStmt->fetchColumn();
                
                if (!$userId) {
                    // Créer un nouvel utilisateur
                    $createUserStmt = $pdo->prepare("INSERT INTO users (email, full_name, phone, address, password, role) VALUES (?, ?, ?, ?, ?, 'client')");
                    $createUserStmt->execute([
                        $_POST['guest_email'],
                        $_POST['guest_name'],
                        $_POST['guest_phone'] ?? '',
                        $_POST['guest_address'] ?? '',
                        password_hash('temp_password', PASSWORD_DEFAULT) // Mot de passe temporaire
                    ]);
                    $userId = $pdo->lastInsertId();
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO bookings (user_id, hotel_id, room_type_id, check_in, check_out, guests, total_price, 
                                         status, payment_status, confirmation_code, special_requests, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $userId,
                    $_POST['hotel_id'],
                    $_POST['room_type_id'],
                    $_POST['check_in_date'],
                    $_POST['check_out_date'],
                    ($_POST['adults'] ?? 1) + ($_POST['children'] ?? 0),
                    floatval($_POST['total_amount']),
                    $_POST['status'] ?? 'pending',
                    $_POST['payment_status'] ?? 'pending',
                    $confirmationCode,
                    $_POST['special_requests'] ?? ''
                ]);
                $message = "Réservation ajoutée avec succès! Référence: $confirmationCode";
                $messageType = "success";
                break;
                
            case 'update_reservation':
                $requiredFields = ['reservation_id', 'guest_name', 'guest_email', 'check_in_date', 'check_out_date', 'total_amount'];
                foreach ($requiredFields as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception("Le champ '$field' est requis");
                    }
                }
                
                // Mettre à jour l'utilisateur
                $bookingStmt = $pdo->prepare("SELECT user_id FROM bookings WHERE id = ?");
                $bookingStmt->execute([$_POST['reservation_id']]);
                $userId = $bookingStmt->fetchColumn();
                
                if ($userId) {
                    $updateUserStmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=?, address=? WHERE id=?");
                    $updateUserStmt->execute([
                        $_POST['guest_name'],
                        $_POST['guest_email'],
                        $_POST['guest_phone'] ?? '',
                        $_POST['guest_address'] ?? '',
                        $userId
                    ]);
                }
                
                $stmt = $pdo->prepare("
                    UPDATE bookings SET 
                        check_in=?, check_out=?, guests=?, 
                        total_price=?, status=?, payment_status=?, special_requests=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $_POST['check_in_date'],
                    $_POST['check_out_date'],
                    ($_POST['adults'] ?? 1) + ($_POST['children'] ?? 0),
                    floatval($_POST['total_amount']),
                    $_POST['status'],
                    $_POST['payment_status'],
                    $_POST['special_requests'] ?? '',
                    $_POST['reservation_id']
                ]);
                $message = "Réservation mise à jour avec succès!";
                $messageType = "success";
                break;
                
            case 'cancel_reservation':
                $stmt = $pdo->prepare("UPDATE bookings SET status='cancelled', cancellation_reason=?, cancelled_at=NOW() WHERE id=?");
                $stmt->execute([$_POST['cancellation_reason'] ?? 'Annulation administrative', $_POST['reservation_id']]);
                $message = "Réservation annulée avec succès!";
                $messageType = "success";
                break;
                
            case 'update_reservation_status':
                $stmt = $pdo->prepare("UPDATE bookings SET status=? WHERE id=?");
                $stmt->execute([$_POST['status'], $_POST['reservation_id']]);
                $message = "Statut de réservation mis à jour avec succès!";
                $messageType = "success";
                break;

            case 'bulk_update_reservations':
                $reservationIds = $_POST['reservation_ids'] ?? [];
                if (empty($reservationIds)) {
                    throw new Exception("Aucune réservation sélectionnée");
                }
                
                $placeholders = str_repeat('?,', count($reservationIds) - 1) . '?';
                $stmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id IN ($placeholders)");
                $stmt->execute(array_merge([$_POST['bulk_status']], $reservationIds));
                
                $message = count($reservationIds) . " réservations mises à jour!";
                $messageType = "success";
                break;
                
            case 'set_room_availability_dates':
                $datesProcessed = $availabilityManager->setRoomAvailability(
                    $_POST['room_id'],
                    $_POST['start_date'],
                    $_POST['end_date'],
                    $_POST['status'],
                    $_POST['reason'] ?? null,
                    !empty($_POST['price_override']) ? floatval($_POST['price_override']) : null
                );
                $message = "Disponibilité définie pour $datesProcessed jours!";
                $messageType = "success";
                break;
                
            case 'bulk_set_availability_dates':
                $roomIds = $_POST['room_ids'] ?? [];
                if (empty($roomIds)) {
                    throw new Exception("Aucune chambre sélectionnée");
                }
                
                $totalProcessed = 0;
                foreach ($roomIds as $roomId) {
                    if (!is_numeric($roomId)) continue;
                    
                    $datesProcessed = $availabilityManager->setRoomAvailability(
                        $roomId,
                        $_POST['bulk_start_date'],
                        $_POST['bulk_end_date'],
                        $_POST['bulk_status'],
                        $_POST['bulk_reason'] ?? null,
                        !empty($_POST['bulk_price_override']) ? floatval($_POST['bulk_price_override']) : null
                    );
                    $totalProcessed++;
                }
                
                $message = "$totalProcessed chambres mises à jour avec succès!";
                $messageType = "success";
                break;
                
            case 'update_room_availability':
                $stmt = $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?");
                $stmt->execute([$_POST['status'], $_POST['room_id']]);
                $message = "Statut mis à jour avec succès!";
                $messageType = "success";
                break;
                
            case 'bulk_update_availability':
                $roomIds = $_POST['room_ids'] ?? [];
                if (empty($roomIds)) {
                    throw new Exception("Aucune chambre sélectionnée");
                }
                
                $placeholders = str_repeat('?,', count($roomIds) - 1) . '?';
                $stmt = $pdo->prepare("UPDATE rooms SET status = ? WHERE id IN ($placeholders)");
                $stmt->execute(array_merge([$_POST['bulk_status']], $roomIds));
                
                $message = count($roomIds) . " chambres mises à jour!";
                $messageType = "success";
                break;
                
            case 'add_hotel':
                $requiredFields = ['name', 'description', 'location', 'city', 'price'];
                foreach ($requiredFields as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception("Le champ '$field' est requis");
                    }
                }
                
                $price = floatval($_POST['price']);
                if ($price < 0) {
                    throw new Exception("Le prix ne peut pas être négatif");
                }
                
                $stmt = $pdo->prepare("INSERT INTO hotels (name, description, location, city, rating, image_url, is_featured, price_per_night, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['location'],
                    $_POST['city'],
                    5.0,
                    $_POST['image_url'] ?? '',
                    isset($_POST['is_featured']) ? 1 : 0,
                    $price
                ]);
                $message = "Hôtel ajouté avec succès!";
                $messageType = "success";
                break;
                
            case 'update_hotel':
                $requiredFields = ['name', 'description', 'location', 'city', 'price'];
                foreach ($requiredFields as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception("Le champ '$field' est requis");
                    }
                }
                
                $price = floatval($_POST['price']);
                if ($price < 0) {
                    throw new Exception("Le prix ne peut pas être négatif");
                }
                
                $stmt = $pdo->prepare("UPDATE hotels SET name=?, description=?, location=?, city=?, image_url=?, is_featured=?, price_per_night=? WHERE id=?");
                $stmt->execute([
                    $_POST['name'],
                    $_POST['description'],
                    $_POST['location'],
                    $_POST['city'],
                    $_POST['image_url'] ?? '',
                    isset($_POST['is_featured']) ? 1 : 0,
                    $price,
                    $_POST['hotel_id']
                ]);
                $message = "Hôtel modifié avec succès!";
                $messageType = "success";
                break;
                
            case 'delete_hotel':
                $stmt = $pdo->prepare("UPDATE hotels SET status='inactive' WHERE id=?");
                $stmt->execute([$_POST['hotel_id']]);
                $message = "Hôtel supprimé avec succès!";
                $messageType = "success";
                break;
                
            case 'add_room':
                $requiredFields = ['hotel_id', 'room_number', 'room_type', 'bed_count', 'price'];
                foreach ($requiredFields as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception("Le champ '$field' est requis");
                    }
                }
                
                $price = floatval($_POST['price']);
                if ($price < 0) {
                    throw new Exception("Le prix ne peut pas être négatif");
                }
                
                // Récupérer ou créer le type de chambre
                $roomTypeStmt = $pdo->prepare("SELECT id FROM room_types WHERE name = ? AND hotel_id = ?");
                $roomTypeStmt->execute([$_POST['room_type'], $_POST['hotel_id']]);
                $roomTypeId = $roomTypeStmt->fetchColumn();
                
                if (!$roomTypeId) {
                    $insertTypeStmt = $pdo->prepare("INSERT INTO room_types (name, hotel_id, price_per_night, max_occupancy) VALUES (?, ?, ?, ?)");
                    $insertTypeStmt->execute([$_POST['room_type'], $_POST['hotel_id'], $price, $_POST['bed_count']]);
                    $roomTypeId = $pdo->lastInsertId();
                }
                
                $stmt = $pdo->prepare("INSERT INTO rooms (hotel_id, room_number, room_type_id, bed_count, price_per_night, amenities, status, image_url, room_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['hotel_id'],
                    $_POST['room_number'],
                    $roomTypeId,
                    $_POST['bed_count'],
                    $price,
                    $_POST['amenities'] ?? '',
                    'available',
                    $_POST['image_url'] ?? '',
                    $_POST['room_type']
                ]);
                $message = "Chambre ajoutée avec succès!";
                $messageType = "success";
                break;
                
            case 'update_room':
                $requiredFields = ['hotel_id', 'room_number', 'room_type', 'bed_count', 'price'];
                foreach ($requiredFields as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception("Le champ '$field' est requis");
                    }
                }
                
                $price = floatval($_POST['price']);
                if ($price < 0) {
                    throw new Exception("Le prix ne peut pas être négatif");
                }
                
                // Récupérer ou créer le type de chambre
                $roomTypeStmt = $pdo->prepare("SELECT id FROM room_types WHERE name = ? AND hotel_id = ?");
                $roomTypeStmt->execute([$_POST['room_type'], $_POST['hotel_id']]);
                $roomTypeId = $roomTypeStmt->fetchColumn();
                
                if (!$roomTypeId) {
                    $insertTypeStmt = $pdo->prepare("INSERT INTO room_types (name, hotel_id, price_per_night, max_occupancy) VALUES (?, ?, ?, ?)");
                    $insertTypeStmt->execute([$_POST['room_type'], $_POST['hotel_id'], $price, $_POST['bed_count']]);
                    $roomTypeId = $pdo->lastInsertId();
                }
                
                $stmt = $pdo->prepare("UPDATE rooms SET hotel_id=?, room_number=?, room_type_id=?, bed_count=?, price_per_night=?, amenities=?, status=?, image_url=?, room_type=? WHERE id=?");
                $stmt->execute([
                    $_POST['hotel_id'],
                    $_POST['room_number'],
                    $roomTypeId,
                    $_POST['bed_count'],
                    $price,
                    $_POST['amenities'] ?? '',
                    $_POST['status'],
                    $_POST['image_url'] ?? '',
                    $_POST['room_type'],
                    $_POST['room_id']
                ]);
                $message = "Chambre modifiée avec succès!";
                $messageType = "success";
                break;
                
            case 'delete_room':
                $stmt = $pdo->prepare("DELETE FROM rooms WHERE id=?");
                $stmt->execute([$_POST['room_id']]);
                $message = "Chambre supprimée avec succès!";
                $messageType = "success";
                break;
                
            case 'update_room_price':
                $price = floatval($_POST['price']);
                if ($price < 0) {
                    throw new Exception("Le prix ne peut pas être négatif");
                }
                
                $stmt = $pdo->prepare("UPDATE rooms SET price_per_night = ? WHERE id = ?");
                $stmt->execute([$price, $_POST['room_id']]);
                
                $message = "Prix mis à jour avec succès!";
                $messageType = "success";
                break;
                
            case 'update_room_image':
                $imageUrl = filter_var($_POST['image_url'], FILTER_VALIDATE_URL);
                if ($imageUrl === false && !empty($_POST['image_url'])) {
                    throw new Exception("URL d'image invalide");
                }
                
                $stmt = $pdo->prepare("UPDATE rooms SET image_url = ? WHERE id = ?");
                $stmt->execute([$_POST['image_url'], $_POST['room_id']]);
                $message = "Image mise à jour avec succès!";
                $messageType = "success";
                break;
        }
    } catch (Exception $e) {
        $message = "Erreur: " . $e->getMessage();
        $messageType = "error";
    }
}

// Récupération des données
try {
    $featuredHotels = $pdo->query("SELECT * FROM hotels WHERE is_featured = 1 AND status = 'active' ORDER BY rating DESC")->fetchAll();
    $allHotels = $pdo->query("SELECT * FROM hotels WHERE status = 'active' ORDER BY created_at DESC")->fetchAll();
    
    // Récupération des chambres avec jointures
    $stmt = $pdo->query("
        SELECT r.*, h.name AS hotel_name, rt.name AS room_type_name 
        FROM rooms r
        LEFT JOIN hotels h ON r.hotel_id = h.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE h.status = 'active'
        ORDER BY h.name, r.room_number
    ");
    $allRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupération des types de chambres pour le formulaire de réservation
    $roomTypesStmt = $pdo->query("
        SELECT rt.*, h.name AS hotel_name 
        FROM room_types rt
        LEFT JOIN hotels h ON rt.hotel_id = h.id
        WHERE h.status = 'active'
        ORDER BY h.name, rt.name
    ");
    $allRoomTypes = $roomTypesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupération des réservations depuis la table bookings
    $allReservations = $reservationManager->getAllReservations();
    
    $stats = $availabilityManager->getAvailabilityStats();
    $reservationStats = $reservationManager->getReservationStats();
    $upcomingArrivals = $reservationManager->getUpcomingArrivals();
    $todayArrivals = $reservationManager->getTodayArrivals();
    $todayDepartures = $reservationManager->getTodayDepartures();
    $monthlyRevenue = $reservationManager->getMonthlyRevenue();
} catch(PDOException $e) {
    $featuredHotels = [];
    $allHotels = [];
    $allRooms = [];
    $allRoomTypes = [];
    $allReservations = [];
    $stats = ['total_rooms' => 0, 'available_rooms' => 0, 'occupied_rooms' => 0, 'maintenance_rooms' => 0];
    $reservationStats = ['total_reservations' => 0, 'confirmed_reservations' => 0, 'pending_reservations' => 0, 'checked_in_reservations' => 0, 'cancelled_reservations' => 0, 'total_revenue' => 0, 'average_stay_duration' => 0];
    $upcomingArrivals = [];
    $todayArrivals = [];
    $todayDepartures = [];
    $monthlyRevenue = [];
}

// Fonctions utilitaires
function getUpcomingUnavailableDates($pdo, $roomId, $limit = 5) {
    try {
        $stmt = $pdo->prepare("
            SELECT date, status, reason 
            FROM room_availability 
            WHERE room_id = ? AND date >= CURDATE() AND status != 'available'
            ORDER BY date ASC 
            LIMIT ?
        ");
        $stmt->execute([$roomId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        return [];
    }
}

function getTimeCategory($checkInDate, $checkOutDate) {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $weekFromNow = date('Y-m-d', strtotime('+7 days'));
    
    if ($checkInDate == $today) {
        return 'today';
    } elseif ($checkInDate == $tomorrow) {
        return 'tomorrow';
    } elseif ($checkInDate <= $weekFromNow) {
        return 'week';
    } elseif ($checkOutDate < $today) {
        return 'past';
    } elseif ($checkInDate <= $today && $checkOutDate >= $today) {
        return 'current';
    } else {
        return 'future';
    }
}

function getStatusBadgeClass($status) {
    switch($status) {
        case 'pending':
            return 'badge-pending';
        case 'confirmed':
            return 'badge-confirmed';
        case 'checked_in':
            return 'badge-checked-in';
        case 'checked_out':
            return 'badge-checked-out';
        case 'cancelled':
            return 'badge-cancelled';
        default:
            return 'badge-luxury';
    }
}

function getStatusText($status) {
    switch($status) {
        case 'pending':
            return 'En attente';
        case 'confirmed':
            return 'Confirmée';
        case 'checked_in':
            return 'Arrivée';
        case 'checked_out':
            return 'Départ';
        case 'cancelled':
            return 'Annulée';
        default:
            return ucfirst($status);
    }
}

function getPaymentStatusBadgeClass($paymentStatus) {
    switch($paymentStatus) {
        case 'pending':
            return 'badge-pending';
        case 'paid':
            return 'badge-confirmed';
        case 'refunded':
            return 'badge-cancelled';
        default:
            return 'badge-luxury';
    }
}

function getPaymentStatusText($paymentStatus) {
    switch($paymentStatus) {
        case 'pending':
            return 'En attente';
        case 'paid':
            return 'Payé';
        case 'refunded':
            return 'Remboursé';
        default:
            return ucfirst($paymentStatus);
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atlas Hotels - Administration Complète</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --luxury-gold: #d4af37;
            --luxury-dark: #1a1a1a;
            --luxury-gray: #2c2c2c;
            --luxury-light: #ecf0f1;
            --luxury-blue: #3498db;
            --luxury-green: #27ae60;
            --luxury-red: #e74c3c;
            --luxury-orange: #f39c12;
            --luxury-purple: #9b59b6;
        }

        body {
            background: linear-gradient(135deg, var(--luxury-dark) 0%, var(--luxury-gray) 100%);
            color: var(--luxury-light);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        .sidebar {
            background: linear-gradient(180deg, var(--luxury-dark) 0%, var(--luxury-gray) 100%);
            min-height: 100vh;
            box-shadow: 4px 0 15px rgba(0,0,0,0.3);
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            z-index: 1000;
        }

        .sidebar .nav-link {
            color: var(--luxury-light);
            padding: 15px 20px;
            border-radius: 10px;
            margin: 5px 10px;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: linear-gradient(45deg, var(--luxury-gold), #f4d03f);
            color: var(--luxury-dark);
            transform: translateX(5px);
            border-left: 3px solid var(--luxury-dark);
        }

        .main-content {
            margin-left: 250px;
            background: rgba(44, 44, 44, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            margin-top: 20px;
            margin-right: 20px;
            margin-bottom: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            min-height: calc(100vh - 40px);
        }

        .stats-card {
            background: linear-gradient(135deg, var(--luxury-gray) 0%, var(--luxury-dark) 100%);
            border: 2px solid var(--luxury-gold);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(212, 175, 55, 0.1), transparent);
            transition: left 0.5s;
        }

        .stats-card:hover::before {
            left: 100%;
        }

        .stats-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 20px 40px rgba(212, 175, 55, 0.3);
        }

        .stats-number {
            font-size: 2.8rem;
            font-weight: bold;
            color: var(--luxury-gold);
            display: block;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .stats-label {
            color: var(--luxury-light);
            font-size: 1rem;
            margin-top: 10px;
            font-weight: 500;
        }

        .btn-luxury {
            background: linear-gradient(45deg, var(--luxury-gold) 0%, #f4d03f 100%);
            border: none;
            color: var(--luxury-dark);
            font-weight: bold;
            padding: 12px 25px;
            border-radius: 25px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
        }

        .btn-luxury:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(212, 175, 55, 0.5);
            color: var(--luxury-dark);
        }

        .btn-outline-luxury {
            border: 2px solid var(--luxury-gold);
            color: var(--luxury-gold);
            background: transparent;
            padding: 10px 20px;
            border-radius: 20px;
            transition: all 0.3s ease;
        }

        .btn-outline-luxury:hover {
            background: var(--luxury-gold);
            color: var(--luxury-dark);
            transform: translateY(-2px);
        }

        .luxury-table {
            background: rgba(26, 26, 26, 0.95);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .luxury-table thead {
            background: linear-gradient(45deg, var(--luxury-gold), #f4d03f);
            color: var(--luxury-dark);
        }

        .luxury-table thead th {
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 15px;
        }

        .luxury-table tbody tr {
            background: rgba(44, 44, 44, 0.8);
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
        }

        .luxury-table tbody tr:hover {
            background: rgba(212, 175, 55, 0.1);
            transform: scale(1.01);
        }

        .luxury-table tbody td {
            padding: 12px 15px;
            vertical-align: middle;
        }

        .room-image, .hotel-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid var(--luxury-gold);
        }

        .rating-stars {
            color: var(--luxury-gold);
            font-size: 1.2rem;
        }

        .badge-featured {
            background: var(--luxury-gold);
            color: var(--luxury-dark);
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
        }

        .badge-available {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid #28a745;
            padding: 5px 15px;
            border-radius: 20px;
        }

        .badge-occupied {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid #dc3545;
            padding: 5px 15px;
            border-radius: 20px;
        }

        .badge-maintenance {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid #ffc107;
            padding: 5px 15px;
            border-radius: 20px;
        }

        .badge-luxury {
            background: rgba(212, 175, 55, 0.2);
            color: var(--luxury-gold);
            border: 1px solid var(--luxury-gold);
            padding: 5px 15px;
            border-radius: 20px;
        }

        .badge-pending {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid #ffc107;
            padding: 5px 15px;
            border-radius: 20px;
        }

        .badge-confirmed {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid #28a745;
            padding: 5px 15px;
            border-radius: 20px;
        }

        .badge-checked-in {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
            border: 1px solid #3498db;
            padding: 5px 15px;
            border-radius: 20px;
        }

        .badge-checked-out {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
            border: 1px solid #6c757d;
            padding: 5px 15px;
            border-radius: 20px;
        }

        .badge-cancelled {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid #dc3545;
            padding: 5px 15px;
            border-radius: 20px;
        }

        .badge-no-show {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
            border: 1px solid #6c757d;
            padding: 5px 15px;
            border-radius: 20px;
        }

        .badge-today {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
            border: 1px solid #e74c3c;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
        }

        .badge-tomorrow {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid #ffc107;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
        }

        .badge-week {
            background: rgba(52, 152, 219, 0.2);
            color: #3498db;
            border: 1px solid #3498db;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
        }

        .badge-current {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid #28a745;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
        }

        .badge-past {
            background: rgba(108, 117, 125, 0.2);
            color: #6c757d;
            border: 1px solid #6c757d;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
        }

        .modal-content {
            background: var(--luxury-gray);
            border: 2px solid var(--luxury-gold);
            border-radius: 15px;
        }

        .modal-header {
            background: linear-gradient(45deg, var(--luxury-gold), #f4d03f);
            color: var(--luxury-dark);
            border-radius: 13px 13px 0 0;
        }

        .form-control, .form-select {
            background: rgba(44, 44, 44, 0.9);
            border: 2px solid rgba(212, 175, 55, 0.3);
            color: var(--luxury-light);
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            background: rgba(44, 44, 44, 1);
            border-color: var(--luxury-gold);
            color: var(--luxury-light);
            box-shadow: 0 0 0 0.2rem rgba(212, 175, 55, 0.25);
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.2);
            border: 1px solid #28a745;
            color: #d4edda;
            border-radius: 10px;
        }

        .alert-danger {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid #e74c3c;
            color: #f8d7da;
            border-radius: 10px;
        }

        .quick-actions, .bulk-actions, .advanced-controls {
            background: rgba(26, 26, 26, 0.95);
            border: 1px solid var(--luxury-gold);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .calendar-container {
            background: rgba(26, 26, 26, 0.95);
            border: 2px solid var(--luxury-gold);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-top: 15px;
        }

        .calendar-day {
            width: 45px;
            height: 45px;
            border: 2px solid rgba(212, 175, 55, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .calendar-day.available {
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.3), rgba(46, 204, 113, 0.3));
            color: #27ae60;
            border-color: #27ae60;
        }

        .calendar-day.occupied {
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.3), rgba(192, 57, 43, 0.3));
            color: #e74c3c;
            border-color: #e74c3c;
        }

        .calendar-day.maintenance {
            background: linear-gradient(135deg, rgba(241, 196, 15, 0.3), rgba(243, 156, 18, 0.3));
            color: #f39c12;
            border-color: #f39c12;
        }

        .calendar-day:hover {
            transform: scale(1.1);
            box-shadow: 0 5px 15px rgba(212, 175, 55, 0.4);
            z-index: 10;
        }

        .chart-container {
            background: rgba(26, 26, 26, 0.95);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--luxury-gold);
        }

        .price-input {
            width: 100px;
            display: inline-block;
        }

        .status-select {
            width: 120px;
            display: inline-block;
        }

        .filter-controls {
            background: rgba(26, 26, 26, 0.95);
            border: 1px solid var(--luxury-gold);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .view-toggle {
            background: rgba(26, 26, 26, 0.95);
            border: 1px solid var(--luxury-gold);
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .reservation-card {
            background: rgba(26, 26, 26, 0.95);
            border: 1px solid var(--luxury-gold);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .reservation-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(212, 175, 55, 0.3);
        }

        .reservation-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--luxury-gold);
        }

        .upcoming-section {
            background: rgba(26, 26, 26, 0.95);
            border: 1px solid var(--luxury-gold);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="position-sticky pt-3">
            <div class="text-center mb-4">
                <h3 style="color: var(--luxury-gold); font-weight: bold;">
                    <i class="fas fa-crown"></i> Atlas Hotels
                </h3>
                <p style="color: var(--luxury-light); font-size: 0.9rem;">Administration Complète</p>
            </div>
            
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item">
                    <a href="#" class="nav-link active" onclick="showSection('dashboard')">
                        <i class="fas fa-tachometer-alt me-2"></i>
                        Tableau de bord
                    </a>
                </li>
                <li>
                    <a href="#" class="nav-link" onclick="showSection('reservations')">
                        <i class="fas fa-calendar-check me-2"></i>
                        Gestion Réservations
                    </a>
                </li>
                <li>
                    <a href="#" class="nav-link" onclick="showSection('hotels')">
                        <i class="fas fa-hotel me-2"></i>
                        Gestion Hôtels
                    </a>
                </li>
                <li>
                    <a href="#" class="nav-link" onclick="showSection('rooms')">
                        <i class="fas fa-bed me-2"></i>
                        Gestion Chambres
                    </a>
                </li>
                <li>
                    <a href="#" class="nav-link" onclick="showSection('availability')">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Disponibilité
                    </a>
                </li>
                <li>
                    <a href="#" class="nav-link" onclick="showSection('date-availability')">
                        <i class="fas fa-calendar-check me-2"></i>
                        Calendrier Disponibilité
                    </a>
                </li>
                <li>
                    <a href="#" class="nav-link" onclick="showSection('pricing')">
                        <i class="fas fa-euro-sign me-2"></i>
                        Gestion Prix
                    </a>
                </li>
                <li>
                    <a href="#" class="nav-link" onclick="showSection('reports')">
                        <i class="fas fa-chart-line me-2"></i>
                        Rapports
                    </a>
                </li>
                <li>
                    <a href="index.php" class="nav-link">
                        <i class="fas fa-home me-2"></i>
                        Accueil Site
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main content -->
    <main class="main-content">
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Dashboard Section -->
        <div id="dashboard" class="section">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom" style="border-color: var(--luxury-gold) !important;">
                <h1 class="h2" style="color: var(--luxury-gold);">
                    <i class="fas fa-crown me-2"></i>Tableau de Bord
                </h1>
                <div>
                    <button class="btn btn-luxury" onclick="refreshStats()">
                        <i class="fas fa-sync me-2"></i>Actualiser
                    </button>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-xl-2 col-md-6 mb-4">
                    <div class="stats-card">
                        <div class="stats-number" id="totalRooms"><?php echo $stats['total_rooms']; ?></div>
                        <div class="stats-label">
                            <i class="fas fa-bed me-2"></i>Chambres Total
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-6 mb-4">
                    <div class="stats-card">
                        <div class="stats-number" id="availableRooms"><?php echo $stats['available_rooms']; ?></div>
                        <div class="stats-label">
                            <i class="fas fa-check-circle me-2"></i>Disponibles
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-6 mb-4">
                    <div class="stats-card">
                        <div class="stats-number" id="totalReservations"><?php echo $reservationStats['total_reservations']; ?></div>
                        <div class="stats-label">
                            <i class="fas fa-calendar-check me-2"></i>Réservations
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-6 mb-4">
                    <div class="stats-card">
                        <div class="stats-number" id="confirmedReservations"><?php echo $reservationStats['confirmed_reservations']; ?></div>
                        <div class="stats-label">
                            <i class="fas fa-check me-2"></i>Confirmées
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-6 mb-4">
                    <div class="stats-card">
                        <div class="stats-number" id="pendingReservations"><?php echo $reservationStats['pending_reservations']; ?></div>
                        <div class="stats-label">
                            <i class="fas fa-clock me-2"></i>En Attente
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-6 mb-4">
                    <div class="stats-card">
                        <div class="stats-number" id="totalRevenue">€<?php echo number_format($reservationStats['total_revenue'], 0); ?></div>
                        <div class="stats-label">
                            <i class="fas fa-euro-sign me-2"></i>Revenus 30j
                        </div>
                    </div>
                </div>
            </div>

            <!-- Today's Activity -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="upcoming-section">
                        <h5 style="color: var(--luxury-gold); margin-bottom: 20px;">
                            <i class="fas fa-calendar-day me-2"></i>Arrivées Aujourd'hui (<?php echo count($todayArrivals); ?>)
                        </h5>
                        <?php if (!empty($todayArrivals)): ?>
                            <?php foreach (array_slice($todayArrivals, 0, 5) as $arrival): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background: rgba(212, 175, 55, 0.1); border-radius: 8px;">
                                    <div>
                                        <strong><?php echo htmlspecialchars($arrival['guest_name'] ?? 'N/A'); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($arrival['hotel_name']); ?> - <?php echo htmlspecialchars($arrival['room_type_name']); ?></small>
                                    </div>
                                    <span class="badge <?php echo getStatusBadgeClass($arrival['status']); ?>"><?php echo getStatusText($arrival['status']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted">Aucune arrivée prévue aujourd'hui</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="upcoming-section">
                        <h5 style="color: var(--luxury-gold); margin-bottom: 20px;">
                            <i class="fas fa-sign-out-alt me-2"></i>Départs Aujourd'hui (<?php echo count($todayDepartures); ?>)
                        </h5>
                        <?php if (!empty($todayDepartures)): ?>
                            <?php foreach (array_slice($todayDepartures, 0, 5) as $departure): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background: rgba(231, 76, 60, 0.1); border-radius: 8px;">
                                    <div>
                                        <strong><?php echo htmlspecialchars($departure['guest_name'] ?? 'N/A'); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($departure['hotel_name']); ?> - <?php echo htmlspecialchars($departure['room_type_name']); ?></small>
                                    </div>
                                    <span class="badge badge-checked-in">En cours</span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted">Aucun départ prévu aujourd'hui</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <h5 style="color: var(--luxury-gold); margin-bottom: 20px;">
                    <i class="fas fa-bolt me-2"></i>Actions Rapides
                </h5>
                <div class="row">
                    <div class="col-md-3">
                        <button class="btn btn-luxury w-100 mb-2" onclick="showSection('reservations')">
                            <i class="fas fa-plus me-2"></i>Nouvelle Réservation
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-info w-100 mb-2" onclick="showSection('availability')">
                            <i class="fas fa-calendar-plus me-2"></i>Disponibilité en Lot
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-warning w-100 mb-2" onclick="showSection('pricing')">
                            <i class="fas fa-euro-sign me-2"></i>Prix Dynamiques
                        </button>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-success w-100 mb-2" onclick="exportReservationData()">
                            <i class="fas fa-download me-2"></i>Exporter Réservations
                        </button>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row">
                <div class="col-md-6">
                    <div class="chart-container">
                        <h5 style="color: var(--luxury-gold); margin-bottom: 20px;">
                            <i class="fas fa-chart-pie me-2"></i>Répartition des Statuts Chambres
                        </h5>
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <h5 style="color: var(--luxury-gold); margin-bottom: 20px;">
                            <i class="fas fa-chart-line me-2"></i>Statuts des Réservations
                        </h5>
                        <canvas id="reservationChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Featured Hotels Table -->
            <div class="content-section">
                <h3 style="color: var(--luxury-gold); margin-bottom: 20px;">
                    <i class="fas fa-star me-2"></i>Hôtels en Vedette
                </h3>
                <div class="table-responsive">
                    <table class="table luxury-table">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Nom</th>
                                <th>Ville</th>
                                <th>Note</th>
                                <th>Prix/Nuit</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($featuredHotels as $hotel): ?>
                            <tr>
                                <td><img src="<?php echo htmlspecialchars($hotel['image_url']); ?>" alt="<?php echo htmlspecialchars($hotel['name']); ?>" class="hotel-image"></td>
                                <td><strong><?php echo htmlspecialchars($hotel['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($hotel['city']); ?></td>
                                <td>
                                    <span class="rating-stars">
                                        <?php echo str_repeat('★', floor($hotel['rating'])); ?>
                                    </span>
                                    <?php echo $hotel['rating']; ?>
                                </td>
                                <td>€<?php echo number_format($hotel['price_per_night'], 2); ?></td>
                                <td><span class="badge badge-featured">Vedette</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Reservations Management Section -->
        <div id="reservations" class="section" style="display: none;">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom" style="border-color: var(--luxury-gold) !important;">
                <h1 class="h2" style="color: var(--luxury-gold);">
                    <i class="fas fa-calendar-check me-2"></i>Gestion des Réservations (<?php echo count($allReservations); ?>)
                </h1>
                <button class="btn btn-luxury" onclick="showAddReservationModal()">
                    <i class="fas fa-plus me-2"></i>Nouvelle Réservation
                </button>
            </div>

            <!-- View Toggle -->
            <div class="view-toggle">
                <div>
                    <h6 style="color: var(--luxury-gold); margin: 0;">
                        <i class="fas fa-eye me-2"></i>Mode d'affichage
                    </h6>
                </div>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-outline-luxury active" id="tableViewBtn" onclick="switchView('table')">
                        <i class="fas fa-table me-2"></i>Tableau
                    </button>
                    <button type="button" class="btn btn-outline-luxury" id="cardViewBtn" onclick="switchView('cards')">
                        <i class="fas fa-th-large me-2"></i>Cartes
                    </button>
                </div>
            </div>

            <!-- Filter Controls -->
            <div class="filter-controls">
                <h5 style="color: var(--luxury-gold); margin-bottom: 15px;">
                    <i class="fas fa-filter me-2"></i>Filtres de Recherche
                </h5>
                <div class="row">
                    <div class="col-md-2">
                        <label class="form-label">Statut:</label>
                        <select class="form-select" id="statusFilter" onchange="filterReservations()">
                            <option value="">Tous les statuts</option>
                            <option value="pending">En attente</option>
                            <option value="confirmed">Confirmée</option>
                            <option value="checked_in">Arrivée</option>
                            <option value="checked_out">Départ</option>
                            <option value="cancelled">Annulée</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Paiement:</label>
                        <select class="form-select" id="paymentFilter" onchange="filterReservations()">
                            <option value="">Tous</option>
                            <option value="pending">En attente</option>
                            <option value="paid">Payé</option>
                            <option value="refunded">Remboursé</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Hôtel:</label>
                        <select class="form-select" id="hotelFilter" onchange="filterReservations()">
                            <option value="">Tous les hôtels</option>
                            <?php foreach ($allHotels as $hotel): ?>
                                <option value="<?php echo $hotel['id']; ?>"><?php echo htmlspecialchars($hotel['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Période:</label>
                        <select class="form-select" id="dateRangeFilter" onchange="filterReservations()">
                            <option value="all">Toutes</option>
                            <option value="today">Aujourd'hui</option>
                            <option value="week">Cette semaine</option>
                            <option value="month">Ce mois</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date d'arrivée:</label>
                        <input type="date" class="form-control" id="checkinFilter" onchange="filterReservations()">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Rechercher:</label>
                        <input type="text" class="form-control" id="guestSearch" placeholder="Nom, email, référence" onkeyup="debounceSearch()">
                    </div>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div class="bulk-actions" id="bulkActions" style="display: none;">
                <h5 style="color: var(--luxury-gold); margin-bottom: 15px;">
                    <i class="fas fa-tasks me-2"></i>Actions en Lot (<span id="selectedCount">0</span> sélectionnées)
                </h5>
                <form method="POST" id="bulkReservationForm">
                    <input type="hidden" name="action" value="bulk_update_reservations">
                    <div class="row align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Nouveau statut:</label>
                            <select class="form-select" name="bulk_status" required>
                                <option value="">Choisir un statut</option>
                                <option value="pending">En attente</option>
                                <option value="confirmed">Confirmée</option>
                                <option value="checked_in">Arrivée</option>
                                <option value="checked_out">Départ</option>
                                <option value="cancelled">Annulée</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-luxury">
                                <i class="fas fa-sync me-2"></i>Mettre à jour
                            </button>
                            <button type="button" class="btn btn-outline-danger" onclick="clearSelection()">
                                <i class="fas fa-times me-2"></i>Annuler
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-outline-success" onclick="exportSelectedReservations()">
                                <i class="fas fa-download me-2"></i>Exporter sélection
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Table View -->
            <div id="tableView">
                <div class="table-responsive">
                    <table class="table luxury-table" id="reservationsTable">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="selectAllReservations" onchange="toggleAllReservations()">
                                </th>
                                <th>ID</th>
                                <th>Client</th>
                                <th>Hôtel</th>
                                <th>Type Chambre</th>
                                <th>Dates</th>
                                <th>Montant</th>
                                <th>Statut</th>
                                <th>Paiement</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($allReservations)): ?>
                                <?php foreach ($allReservations as $reservation): ?>
                                <?php $timeCategory = getTimeCategory($reservation['check_in'], $reservation['check_out']); ?>
                                <tr data-status="<?php echo $reservation['status']; ?>" 
                                    data-payment="<?php echo $reservation['payment_status']; ?>"
                                    data-hotel="<?php echo $reservation['hotel_id']; ?>" 
                                    data-checkin="<?php echo $reservation['check_in']; ?>" 
                                    data-guest="<?php echo strtolower(($reservation['guest_name'] ?? '') . ' ' . ($reservation['guest_email'] ?? '') . ' ' . ($reservation['booking_reference'] ?? '')); ?>"
                                    data-time-category="<?php echo $timeCategory; ?>">
                                    <td>
                                        <input type="checkbox" name="reservation_ids[]" value="<?php echo $reservation['id']; ?>" class="reservation-checkbox" onchange="updateBulkActions()">
                                    </td>
                                    <td>
                                        <strong>#<?php echo $reservation['id']; ?></strong>
                                        <br><span class="badge badge-<?php echo $timeCategory; ?>"><?php echo ucfirst($timeCategory); ?></span>
                                        <?php if ($reservation['booking_reference']): ?>
                                        <br><small class="text-muted">Ref: <?php echo htmlspecialchars($reservation['booking_reference']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($reservation['guest_name'] ?? 'N/A'); ?></strong></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($reservation['guest_email'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($reservation['hotel_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($reservation['room_type_name'] ?? 'N/A'); ?>
                                        <br><small class="text-muted"><?php echo (int)$reservation['guests']; ?> pers.</small>
                                    </td>
                                    <td>
                                        <div><?php echo date('d/m/Y', strtotime($reservation['check_in'])); ?></div>
                                        <div class="text-muted small">au <?php echo date('d/m/Y', strtotime($reservation['check_out'])); ?></div>
                                    </td>
                                    <td><strong>€<?php echo number_format($reservation['total_price'], 2); ?></strong></td>
                                    <td>
                                        <span class="badge <?php echo getStatusBadgeClass($reservation['status']); ?>"><?php echo getStatusText($reservation['status']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getPaymentStatusBadgeClass($reservation['payment_status']); ?>"><?php echo getPaymentStatusText($reservation['payment_status']); ?></span>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-outline-luxury btn-sm" onclick="viewReservation(<?php echo $reservation['id']; ?>)" title="Voir détails">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-info btn-sm" onclick="editReservation(<?php echo $reservation['id']; ?>)" title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($reservation['status'] !== 'cancelled'): ?>
                                            <button class="btn btn-outline-warning btn-sm" onclick="changeReservationStatus(<?php echo $reservation['id']; ?>)" title="Changer statut">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-sm" onclick="cancelReservation(<?php echo $reservation['id']; ?>)" title="Annuler">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center">Aucune réservation trouvée.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Cards View -->
            <div id="cardsView" style="display: none;">
                <div class="row" id="reservationCards">
                    <?php if (!empty($allReservations)): ?>
                        <?php foreach ($allReservations as $reservation): ?>
                        <?php $timeCategory = getTimeCategory($reservation['check_in'], $reservation['check_out']); ?>
                        <div class="col-md-6 col-lg-4 mb-3 reservation-card-container" 
                             data-status="<?php echo $reservation['status']; ?>" 
                             data-payment="<?php echo $reservation['payment_status']; ?>"
                             data-hotel="<?php echo $reservation['hotel_id']; ?>" 
                             data-checkin="<?php echo $reservation['check_in']; ?>" 
                             data-guest="<?php echo strtolower(($reservation['guest_name'] ?? '') . ' ' . ($reservation['guest_email'] ?? '') . ' ' . ($reservation['booking_reference'] ?? '')); ?>"
                             data-time-category="<?php echo $timeCategory; ?>">
                            <div class="reservation-card">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h6 class="mb-1" style="color: var(--luxury-gold);">#<?php echo $reservation['id']; ?></h6>
                                        <span class="badge badge-<?php echo $timeCategory; ?>"><?php echo ucfirst($timeCategory); ?></span>
                                        <?php if ($reservation['booking_reference']): ?>
                                        <br><small class="text-muted">Ref: <?php echo htmlspecialchars($reservation['booking_reference']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <input type="checkbox" name="reservation_ids[]" value="<?php echo $reservation['id']; ?>" class="reservation-checkbox" onchange="updateBulkActions()">
                                </div>
                                
                                <div class="mb-3">
                                    <strong><?php echo htmlspecialchars($reservation['guest_name'] ?? 'N/A'); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($reservation['guest_email'] ?? 'N/A'); ?></small>
                                </div>
                                
                                <div class="mb-3">
                                    <div><strong><?php echo htmlspecialchars($reservation['hotel_name'] ?? 'N/A'); ?></strong></div>
                                    <div class="text-muted"><?php echo htmlspecialchars($reservation['room_type_name'] ?? 'N/A'); ?> - <?php echo (int)$reservation['guests']; ?> pers.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div><i class="fas fa-calendar-check me-2"></i><?php echo date('d/m/Y', strtotime($reservation['check_in'])); ?></div>
                                    <div><i class="fas fa-calendar-times me-2"></i><?php echo date('d/m/Y', strtotime($reservation['check_out'])); ?></div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <strong>€<?php echo number_format($reservation['total_price'], 2); ?></strong>
                                    </div>
                                    <div>
                                        <span class="badge <?php echo getStatusBadgeClass($reservation['status']); ?>"><?php echo getStatusText($reservation['status']); ?></span>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <button class="btn btn-outline-luxury btn-sm" onclick="viewReservation(<?php echo $reservation['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-outline-info btn-sm" onclick="editReservation(<?php echo $reservation['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($reservation['status'] !== 'cancelled'): ?>
                                    <button class="btn btn-outline-warning btn-sm" onclick="changeReservationStatus(<?php echo $reservation['id']; ?>)">
                                        <i class="fas fa-exchange-alt"></i>
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" onclick="cancelReservation(<?php echo $reservation['id']; ?>)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="text-center">
                                <p class="text-muted">Aucune réservation trouvée.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Hotels Management Section -->
        <div id="hotels" class="section" style="display: none;">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom" style="border-color: var(--luxury-gold) !important;">
                <h1 class="h2" style="color: var(--luxury-gold);">
                    <i class="fas fa-hotel me-2"></i>Gestion des Hôtels
                </h1>
                <button class="btn btn-luxury" onclick="showAddHotelModal()">
                    <i class="fas fa-plus me-2"></i>Ajouter un Hôtel
                </button>
            </div>

            <div class="table-responsive">
                <table class="table luxury-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Nom</th>
                            <th>Description</th>
                            <th>Ville</th>
                            <th>Note</th>
                            <th>Prix/Nuit</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allHotels as $hotel): ?>
                        <tr>
                            <td><img src="<?php echo htmlspecialchars($hotel['image_url']); ?>" alt="<?php echo htmlspecialchars($hotel['name']); ?>" class="hotel-image"></td>
                            <td><strong><?php echo htmlspecialchars($hotel['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars(substr($hotel['description'], 0, 80)) . '...'; ?></td>
                            <td><?php echo htmlspecialchars($hotel['city']); ?></td>
                            <td>
                                <span class="rating-stars">
                                    <?php echo str_repeat('★', floor($hotel['rating'])); ?>
                                </span>
                                <?php echo $hotel['rating']; ?>
                            </td>
                            <td>€<?php echo number_format($hotel['price_per_night'], 2); ?></td>
                            <td>
                                <?php if ($hotel['is_featured']): ?>
                                    <span class="badge badge-featured">Vedette</span>
                                <?php else: ?>
                                    <span class="badge badge-luxury">Standard</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-outline-luxury btn-sm me-2" onclick="editHotel(<?php echo $hotel['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-outline-danger btn-sm" onclick="deleteHotel(<?php echo $hotel['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Section Gestion des Chambres -->
        <div id="rooms" class="section" style="display: none;">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom" style="border-color: var(--luxury-gold) !important;">
                <h1 class="h2" style="color: var(--luxury-gold);">
                    <i class="fas fa-bed me-2"></i>Gestion des Chambres
                </h1>
                <button class="btn btn-luxury" onclick="showAddRoomModal()">
                    <i class="fas fa-plus me-2"></i>Ajouter une Chambre
                </button>
            </div>

            <div class="table-responsive">
                <table class="table luxury-table">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Hôtel</th>
                            <th>Numéro</th>
                            <th>Type</th>
                            <th>Lits</th>
                            <th>Prix/Nuit</th>
                            <th>Équipements</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($allRooms)): ?>
                            <?php foreach ($allRooms as $room): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($room['image_url'])): ?>
                                            <img src="<?php echo htmlspecialchars($room['image_url']); ?>" alt="Chambre <?php echo htmlspecialchars($room['room_number']); ?>" class="room-image">
                                        <?php else: ?>
                                            <div class="room-image d-flex align-items-center justify-content-center" style="background: var(--luxury-gray);">
                                                <i class="fas fa-image text-muted"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($room['hotel_name'] ?? ''); ?></strong></td>
                                    <td><?php echo htmlspecialchars($room['room_number'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($room['room_type_name'] ?? ''); ?></td>
                                    <td><?php echo (int)($room['bed_count'] ?? 0); ?></td>
                                    <td>€<?php echo number_format($room['price_per_night'] ?? 0, 2); ?></td>
                                    <td><?php echo htmlspecialchars(substr($room['amenities'] ?? 'N/A', 0, 30)) . '...'; ?></td>
                                    <td>
                                        <?php 
                                        $statusClass = '';
                                        $statusText = '';
                                        switch($room['status']) {
                                            case 'available':
                                                $statusClass = 'badge-available';
                                                $statusText = 'Disponible';
                                                break;
                                            case 'occupied':
                                                $statusClass = 'badge-occupied';
                                                $statusText = 'Occupée';
                                                break;
                                            case 'maintenance':
                                                $statusClass = 'badge-maintenance';
                                                $statusText = 'Maintenance';
                                                break;
                                            default:
                                                $statusClass = 'badge-luxury';
                                                $statusText = htmlspecialchars($room['status']);
                                        }
                                        ?>
                                        <span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                    </td>
                                    <td>
                                        <button class="btn btn-outline-luxury btn-sm me-1" onclick="editRoom(<?php echo $room['id']; ?>)" title="Modifier">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-outline-info btn-sm me-1" onclick="updateRoomImage(<?php echo $room['id']; ?>)" title="Changer image">
                                            <i class="fas fa-image"></i>
                                        </button>
                                        <button class="btn btn-outline-warning btn-sm me-1" onclick="setRoomAvailabilityDates(<?php echo $room['id']; ?>)" title="Gérer dates">
                                            <i class="fas fa-calendar-alt"></i>
                                        </button>
                                        <button class="btn btn-outline-danger btn-sm" onclick="deleteRoom(<?php echo $room['id']; ?>)" title="Supprimer">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center">Aucune chambre trouvée.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section Gestion Disponibilité -->
        <div id="availability" class="section" style="display: none;">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom" style="border-color: var(--luxury-gold) !important;">
                <h1 class="h2" style="color: var(--luxury-gold);">
                    <i class="fas fa-calendar-alt me-2"></i>Gestion de la Disponibilité
                </h1>
            </div>

            <!-- Actions en lot -->
            <div class="bulk-actions">
                <h5 style="color: var(--luxury-gold); margin-bottom: 15px;">
                    <i class="fas fa-tasks me-2"></i>Actions en Lot
                </h5>
                <form method="POST" id="bulkUpdateForm">
                    <input type="hidden" name="action" value="bulk_update_availability">
                    <div class="row align-items-end">
                        <div class="col-md-6">
                            <label class="form-label">Nouveau statut pour les chambres sélectionnées:</label>
                            <select class="form-select" name="bulk_status" required>
                                <option value="">Choisir un statut</option>
                                <option value="available">Disponible</option>
                                <option value="occupied">Occupée</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <button type="submit" class="btn btn-luxury">
                                <i class="fas fa-sync me-2"></i>Mettre à jour les chambres sélectionnées
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table luxury-table">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAll" onchange="toggleAllRooms()">
                            </th>
                            <th>Hôtel</th>
                            <th>Chambre</th>
                            <th>Type</th>
                            <th>Statut Actuel</th>
                            <th>Changer Statut</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allRooms as $room): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="room_ids[]" value="<?php echo $room['id']; ?>" class="room-checkbox">
                            </td>
                            <td><strong><?php echo htmlspecialchars($room['hotel_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($room['room_number']); ?></td>
                            <td><?php echo htmlspecialchars($room['room_type_name']); ?></td>
                            <td>
                                <?php 
                                $statusClass = '';
                                $statusText = '';
                                switch($room['status']) {
                                    case 'available':
                                        $statusClass = 'badge-available';
                                        $statusText = 'Disponible';
                                        break;
                                    case 'occupied':
                                        $statusClass = 'badge-occupied';
                                        $statusText = 'Occupée';
                                        break;
                                    case 'maintenance':
                                        $statusClass = 'badge-maintenance';
                                        $statusText = 'Maintenance';
                                        break;
                                }
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="update_room_availability">
                                    <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                    <select class="form-select status-select" name="status" onchange="this.form.submit()">
                                        <option value="available" <?php echo $room['status'] === 'available' ? 'selected' : ''; ?>>Disponible</option>
                                        <option value="occupied" <?php echo $room['status'] === 'occupied' ? 'selected' : ''; ?>>Occupée</option>
                                        <option value="maintenance" <?php echo $room['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <button class="btn btn-outline-luxury btn-sm" onclick="quickStatusChange(<?php echo $room['id']; ?>, '<?php echo $room['status'] === 'available' ? 'occupied' : 'available'; ?>')">
                                    <i class="fas fa-sync"></i>
                                    <?php echo $room['status'] === 'available' ? 'Occuper' : 'Libérer'; ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section Calendrier Disponibilité -->
        <div id="date-availability" class="section" style="display: none;">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom" style="border-color: var(--luxury-gold) !important;">
                <h1 class="h2" style="color: var(--luxury-gold);">
                    <i class="fas fa-calendar-check me-2"></i>Calendrier de Disponibilité
                </h1>
            </div>

            <!-- Gestion des dates en lot -->
            <div class="advanced-controls">
                <h5 style="color: var(--luxury-gold); margin-bottom: 15px;">
                    <i class="fas fa-calendar-week me-2"></i>Gestion des Dates en Lot
                </h5>
                <form method="POST" id="bulkDateForm">
                    <input type="hidden" name="action" value="bulk_set_availability_dates">
                    <div class="row">
                        <div class="col-md-2">
                            <label class="form-label">Date de début:</label>
                            <input type="date" class="form-control" name="bulk_start_date" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Date de fin:</label>
                            <input type="date" class="form-control" name="bulk_end_date" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Statut:</label>
                            <select class="form-select" name="bulk_status" required>
                                <option value="">Choisir un statut</option>
                                <option value="available">Disponible</option>
                                <option value="occupied">Occupée</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Prix Override (€):</label>
                            <input type="number" step="0.01" class="form-control" name="bulk_price_override" placeholder="Optionnel">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Raison:</label>
                            <input type="text" class="form-control" name="bulk_reason" placeholder="Optionnel">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-luxury w-100">
                                <i class="fas fa-save me-2"></i>Appliquer
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Liste des chambres avec gestion des dates -->
            <div class="table-responsive">
                <table class="table luxury-table">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAllDates" onchange="toggleAllDateRooms()">
                            </th>
                            <th>Hôtel</th>
                            <th>Chambre</th>
                            <th>Type</th>
                            <th>Prochaines Indisponibilités</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allRooms as $room): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="room_ids[]" value="<?php echo $room['id']; ?>" class="date-room-checkbox">
                            </td>
                            <td><strong><?php echo htmlspecialchars($room['hotel_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($room['room_number']); ?></td>
                            <td><?php echo htmlspecialchars($room['room_type_name']); ?></td>
                            <td>
                                <?php 
                                $upcomingDates = getUpcomingUnavailableDates($pdo, $room['id'], 3);
                                if (!empty($upcomingDates)): 
                                ?>
                                    <?php foreach ($upcomingDates as $dateInfo): ?>
                                        <span class="badge badge-<?php echo $dateInfo['status'] === 'occupied' ? 'occupied' : 'maintenance'; ?> me-1">
                                            <?php echo date('d/m', strtotime($dateInfo['date'])); ?>
                                            <?php if ($dateInfo['reason']): ?>
                                                (<?php echo htmlspecialchars(substr($dateInfo['reason'], 0, 10)); ?>)
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">Aucune indisponibilité prévue</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-outline-luxury btn-sm me-1" onclick="setRoomAvailabilityDates(<?php echo $room['id']; ?>)" title="Gérer les dates">
                                    <i class="fas fa-calendar-alt"></i>
                                </button>
                                <button class="btn btn-outline-info btn-sm" onclick="viewRoomCalendar(<?php echo $room['id']; ?>)" title="Voir calendrier">
                                    <i class="fas fa-calendar"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section Gestion Prix -->
        <div id="pricing" class="section" style="display: none;">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom" style="border-color: var(--luxury-gold) !important;">
                <h1 class="h2" style="color: var(--luxury-gold);">
                    <i class="fas fa-euro-sign me-2"></i>Gestion des Prix
                </h1>
            </div>

            <div class="table-responsive">
                <table class="table luxury-table">
                    <thead>
                        <tr>
                            <th>Hôtel</th>
                            <th>Chambre</th>
                            <th>Type</th>
                            <th>Prix Actuel</th>
                            <th>Nouveau Prix</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allRooms as $room): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($room['hotel_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($room['room_number']); ?></td>
                            <td><?php echo htmlspecialchars($room['room_type_name']); ?></td>
                            <td>
                                <span class="badge badge-luxury">€<?php echo number_format($room['price_per_night'], 2); ?></span>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="update_room_price">
                                    <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                    <input type="number" step="0.01" class="form-control price-input" name="price" 
                                           value="<?php echo $room['price_per_night']; ?>" min="0">
                            </td>
                            <td>
                                    <button type="submit" class="btn btn-outline-luxury btn-sm">
                                        <i class="fas fa-save"></i> Sauvegarder
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section Rapports -->
        <div id="reports" class="section" style="display: none;">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom" style="border-color: var(--luxury-gold) !important;">
                <h1 class="h2" style="color: var(--luxury-gold);">
                    <i class="fas fa-chart-line me-2"></i>Rapports Avancés
                </h1>
            </div>

            <!-- Report Generation Controls -->
            <div class="advanced-controls">
                <h5 style="color: var(--luxury-gold); margin-bottom: 15px;">
                    <i class="fas fa-file-chart-line me-2"></i>Génération de Rapports
                </h5>
                <div class="row">
                    <div class="col-md-3">
                        <label class="form-label">Date début:</label>
                        <input type="date" class="form-control" id="reportStartDate">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date fin:</label>
                        <input type="date" class="form-control" id="reportEndDate">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Hôtel:</label>
                        <select class="form-select" id="reportHotelFilter">
                            <option value="">Tous les hôtels</option>
                            <?php foreach ($allHotels as $hotel): ?>
                                <option value="<?php echo $hotel['id']; ?>"><?php echo htmlspecialchars($hotel['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid">
                            <button class="btn btn-luxury" onclick="generateAdvancedReport()">
                                <i class="fas fa-chart-bar me-2"></i>Générer Rapport
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Results -->
            <div id="reportResults" class="chart-container" style="display: none;">
                <h5 style="color: var(--luxury-gold); margin-bottom: 20px;">
                    <i class="fas fa-chart-area me-2"></i>Résultats du Rapport
                </h5>
                <div id="reportContent">
                    <!-- Le contenu sera généré par JavaScript -->
                </div>
            </div>
        </div>
    </main>

    <!-- Modal d'ajout/modification de réservation -->
    <div class="modal fade" id="addReservationModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-check me-2"></i>Nouvelle Réservation
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addReservationForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_reservation" id="reservationFormAction">
                        <input type="hidden" name="reservation_id" id="reservationId">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="hotel_id" class="form-label">Hôtel</label>
                                    <select class="form-select" name="hotel_id" id="reservation_hotel_id" required onchange="updateRoomTypeOptions()">
                                        <option value="">Sélectionner un hôtel</option>
                                        <?php foreach ($allHotels as $hotel): ?>
                                            <option value="<?php echo $hotel['id']; ?>"><?php echo htmlspecialchars($hotel['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="room_type_id" class="form-label">Type de chambre</label>
                                    <select class="form-select" name="room_type_id" id="reservation_room_type_id" required>
                                        <option value="">Sélectionner d'abord un hôtel</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="guest_name" class="form-label">Nom du client</label>
                                    <input type="text" class="form-control" name="guest_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="guest_email" class="form-label">Email du client</label>
                                    <input type="email" class="form-control" name="guest_email" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="guest_phone" class="form-label">Téléphone</label>
                                    <input type="tel" class="form-control" name="guest_phone">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="guest_address" class="form-label">Adresse</label>
                                    <input type="text" class="form-control" name="guest_address">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="check_in_date" class="form-label">Date d'arrivée</label>
                                    <input type="date" class="form-control" name="check_in_date" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="check_out_date" class="form-label">Date de départ</label>
                                    <input type="date" class="form-control" name="check_out_date" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="adults" class="form-label">Adultes</label>
                                    <input type="number" class="form-control" name="adults" min="1" max="10" value="1">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="children" class="form-label">Enfants</label>
                                    <input type="number" class="form-control" name="children" min="0" max="10" value="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="total_amount" class="form-label">Montant total (€)</label>
                                    <input type="number" step="0.01" class="form-control" name="total_amount" required min="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Statut</label>
                                    <select class="form-select" name="status">
                                        <option value="pending">En attente</option>
                                        <option value="confirmed">Confirmée</option>
                                        <option value="checked_in">Arrivée</option>
                                        <option value="checked_out">Départ</option>
                                        <option value="cancelled">Annulée</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="payment_status" class="form-label">Statut paiement</label>
                                    <select class="form-select" name="payment_status">
                                        <option value="pending">En attente</option>
                                        <option value="paid">Payé</option>
                                        <option value="refunded">Remboursé</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="special_requests" class="form-label">Demandes spéciales</label>
                            <textarea class="form-control" name="special_requests" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-luxury" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-luxury">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de détails de réservation -->
    <div class="modal fade" id="viewReservationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-eye me-2"></i>Détails de la Réservation
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="reservationDetailsContent">
                    <!-- Le contenu sera généré par JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-luxury" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal d'annulation de réservation -->
    <div class="modal fade" id="cancelReservationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-times me-2"></i>Annuler la Réservation
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="cancelReservationForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="cancel_reservation">
                        <input type="hidden" name="reservation_id" id="cancelReservationId">
                        
                        <div class="mb-3">
                            <label for="cancellation_reason" class="form-label">Raison de l'annulation</label>
                            <textarea class="form-control" name="cancellation_reason" rows="3" required placeholder="Veuillez indiquer la raison de l'annulation..."></textarea>
                        </div>
                        
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Cette action est irréversible. La réservation sera marquée comme annulée.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-luxury" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-danger">Confirmer l'annulation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de changement de statut -->
    <div class="modal fade" id="changeStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exchange-alt me-2"></i>Changer le Statut
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="changeStatusForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_reservation_status">
                        <input type="hidden" name="reservation_id" id="statusReservationId">
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Nouveau statut</label>
                            <select class="form-select" name="status" required>
                                <option value="">Choisir un statut</option>
                                <option value="pending">En attente</option>
                                <option value="confirmed">Confirmée</option>
                                <option value="checked_in">Arrivée</option>
                                <option value="checked_out">Départ</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-luxury" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-luxury">Mettre à jour</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal d'ajout/modification d'hôtel -->
    <div class="modal fade" id="addHotelModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-hotel me-2"></i>Ajouter un Hôtel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addHotelForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_hotel" id="formAction">
                        <input type="hidden" name="hotel_id" id="hotelId">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="name" class="form-label">Nom de l'hôtel</label>
                                    <input type="text" class="form-control" name="name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="city" class="form-label">Ville</label>
                                    <input type="text" class="form-control" name="city" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="location" class="form-label">Adresse</label>
                            <input type="text" class="form-control" name="location" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="price" class="form-label">Prix par nuit (€)</label>
                                    <input type="number" step="0.01" class="form-control" name="price" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="image_url" class="form-label">URL de l'image</label>
                                    <input type="url" class="form-control" name="image_url" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_featured" id="is_featured">
                                <label class="form-check-label" for="is_featured">
                                    Hôtel en vedette
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-luxury" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-luxury">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal d'ajout/modification de chambre -->
    <div class="modal fade" id="addRoomModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-bed me-2"></i>Ajouter une Chambre
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addRoomForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_room" id="roomFormAction">
                        <input type="hidden" name="room_id" id="roomId">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="hotel_id" class="form-label">Hôtel</label>
                                    <select class="form-select" name="hotel_id" required>
                                        <option value="">Sélectionner un hôtel</option>
                                        <?php foreach ($allHotels as $hotel): ?>
                                            <option value="<?php echo $hotel['id']; ?>"><?php echo htmlspecialchars($hotel['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="room_number" class="form-label">Numéro de chambre</label>
                                    <input type="text" class="form-control" name="room_number" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="room_type" class="form-label">Type de chambre</label>
                                    <select class="form-select" name="room_type" required>
                                        <option value="">Sélectionner le type</option>
                                        <option value="Simple">Simple</option>
                                        <option value="Double">Double</option>
                                        <option value="Triple">Triple</option>
                                        <option value="Suite">Suite</option>
                                        <option value="Suite Deluxe">Suite Deluxe</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="bed_count" class="form-label">Nombre de lits</label>
                                    <input type="number" class="form-control" name="bed_count" min="1" max="4" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="price" class="form-label">Prix par nuit (€)</label>
                                    <input type="number" step="0.01" class="form-control" name="price" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Statut</label>
                                    <select class="form-select" name="status">
                                        <option value="available">Disponible</option>
                                        <option value="occupied">Occupée</option>
                                        <option value="maintenance">Maintenance</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="image_url" class="form-label">URL de l'image de la chambre</label>
                            <input type="url" class="form-control" name="image_url" placeholder="https://exemple.com/image.jpg">
                        </div>
                        
                        <div class="mb-3">
                            <label for="amenities" class="form-label">Équipements</label>
                            <textarea class="form-control" name="amenities" rows="3" placeholder="WiFi, Climatisation, Minibar, TV, etc."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-luxury" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-luxury">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de mise à jour d'image -->
    <div class="modal fade" id="updateImageModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-image me-2"></i>Mettre à jour l'image
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="updateImageForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_room_image">
                        <input type="hidden" name="room_id" id="imageRoomId">
                        
                        <div class="mb-3">
                            <label for="image_url" class="form-label">URL de la nouvelle image</label>
                            <input type="url" class="form-control" name="image_url" required placeholder="https://exemple.com/image.jpg">
                        </div>
                        
                        <div class="mb-3">
                            <small class="text-muted">
                                Formats recommandés: JPG, PNG, WebP<br>
                                Taille recommandée: 800x600 pixels minimum
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-luxury" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-luxury">Mettre à jour</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de gestion des dates de disponibilité -->
    <div class="modal fade" id="roomAvailabilityModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt me-2"></i>Gérer la Disponibilité par Dates
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="roomAvailabilityForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="set_room_availability_dates">
                        <input type="hidden" name="room_id" id="availabilityRoomId">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_date" class="form-label">Date de début</label>
                                    <input type="date" class="form-control" name="start_date" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="end_date" class="form-label">Date de fin</label>
                                    <input type="date" class="form-control" name="end_date" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="status" class="form-label">Statut</label>
                                    <select class="form-select" name="status" required>
                                        <option value="">Choisir un statut</option>
                                        <option value="available">Disponible</option>
                                        <option value="occupied">Occupée</option>
                                        <option value="maintenance">Maintenance</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="price_override" class="form-label">Prix Override (€)</label>
                                    <input type="number" step="0.01" class="form-control" name="price_override" placeholder="Optionnel">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="reason" class="form-label">Raison (optionnel)</label>
                                    <input type="text" class="form-control" name="reason" placeholder="Ex: Rénovation, Réservation privée">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Cette action remplacera toute disponibilité existante pour cette période.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-luxury" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-luxury">Définir la Disponibilité</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de calendrier de chambre -->
    <div class="modal fade" id="roomCalendarModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar me-2"></i>Calendrier de la Chambre
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="roomCalendarContent">
                        <!-- Le contenu du calendrier sera généré par JavaScript -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-luxury" data-bs-dismiss="modal">Fermer</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Données PHP converties en JavaScript
        const allHotels = <?php echo json_encode($allHotels); ?>;
        const allRooms = <?php echo json_encode($allRooms); ?>;
        const allRoomTypes = <?php echo json_encode($allRoomTypes); ?>;
        const allReservations = <?php echo json_encode($allReservations); ?>;
        const stats = <?php echo json_encode($stats); ?>;
        const reservationStats = <?php echo json_encode($reservationStats); ?>;
        const monthlyRevenue = <?php echo json_encode($monthlyRevenue); ?>;

        // Variables globales
        let currentSection = 'dashboard';
        let charts = {};
        let currentView = 'table';
        let searchTimeout;

        // Fonction pour afficher les sections
        function showSection(sectionName) {
            // Cacher toutes les sections
            document.querySelectorAll('.section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Afficher la section sélectionnée
            document.getElementById(sectionName).style.display = 'block';
            currentSection = sectionName;
            
            // Mettre à jour la navigation
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Initialiser les fonctionnalités spécifiques à la section
            initSectionFeatures(sectionName);
        }

        // Initialiser les fonctionnalités spécifiques à chaque section
        function initSectionFeatures(sectionName) {
            switch(sectionName) {
                case 'dashboard':
                    initCharts();
                    break;
                case 'reports':
                    setDefaultReportDates();
                    break;
                case 'reservations':
                    updateBulkActions();
                    break;
            }
        }

        // Initialiser les graphiques
        function initCharts() {
            // Graphique de statut des chambres
            const statusCtx = document.getElementById('statusChart');
            if (statusCtx && !charts.statusChart) {
                charts.statusChart = new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Disponibles', 'Occupées', 'Maintenance'],
                        datasets: [{
                            data: [stats.available_rooms, stats.occupied_rooms, stats.maintenance_rooms],
                            backgroundColor: ['#27ae60', '#e74c3c', '#f39c12'],
                            borderWidth: 3,
                            borderColor: '#d4af37'
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                labels: {
                                    color: '#ecf0f1',
                                    font: {
                                        size: 14
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Graphique des réservations
            const reservationCtx = document.getElementById('reservationChart');
            if (reservationCtx && !charts.reservationChart) {
                charts.reservationChart = new Chart(reservationCtx, {
                    type: 'bar',
                    data: {
                        labels: ['En attente', 'Confirmées', 'Arrivées', 'Annulées'],
                        datasets: [{
                            label: 'Réservations',
                            data: [
                                reservationStats.pending_reservations,
                                reservationStats.confirmed_reservations,
                                reservationStats.checked_in_reservations,
                                reservationStats.cancelled_reservations
                            ],
                            backgroundColor: ['#f39c12', '#27ae60', '#3498db', '#e74c3c'],
                            borderColor: '#d4af37',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                labels: {
                                    color: '#ecf0f1'
                                }
                            }
                        },
                        scales: {
                            y: {
                                ticks: {
                                    color: '#ecf0f1'
                                },
                                grid: {
                                    color: 'rgba(212, 175, 55, 0.2)'
                                }
                            },
                            x: {
                                ticks: {
                                    color: '#ecf0f1'
                                },
                                grid: {
                                    color: 'rgba(212, 175, 55, 0.2)'
                                }
                            }
                        }
                    }
                });
            }
        }

        // Changer de vue (table/cartes)
        function switchView(view) {
            currentView = view;
            
            if (view === 'table') {
                document.getElementById('tableView').style.display = 'block';
                document.getElementById('cardsView').style.display = 'none';
                document.getElementById('tableViewBtn').classList.add('active');
                document.getElementById('cardViewBtn').classList.remove('active');
            } else {
                document.getElementById('tableView').style.display = 'none';
                document.getElementById('cardsView').style.display = 'block';
                document.getElementById('tableViewBtn').classList.remove('active');
                document.getElementById('cardViewBtn').classList.add('active');
            }
        }

        // Recherche avec debounce
        function debounceSearch() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(filterReservations, 300);
        }

        // Fonctions de gestion des réservations
        function showAddReservationModal() {
            document.getElementById('addReservationForm').reset();
            document.getElementById('reservationFormAction').value = 'add_reservation';
            document.getElementById('reservationId').value = '';
            document.querySelector('#addReservationModal .modal-title').innerHTML = '<i class="fas fa-calendar-check me-2"></i>Nouvelle Réservation';
            const modal = new bootstrap.Modal(document.getElementById('addReservationModal'));
            modal.show();
        }

        function updateRoomTypeOptions() {
            const hotelId = document.getElementById('reservation_hotel_id').value;
            const roomTypeSelect = document.getElementById('reservation_room_type_id');
            
            // Clear existing options
            roomTypeSelect.innerHTML = '<option value="">Sélectionner un type de chambre</option>';
            
            if (hotelId) {
                const hotelRoomTypes = allRoomTypes.filter(roomType => roomType.hotel_id == hotelId);
                hotelRoomTypes.forEach(roomType => {
                    const option = document.createElement('option');
                    option.value = roomType.id;
                    option.textContent = `${roomType.name} (€${roomType.price_per_night})`;
                    roomTypeSelect.appendChild(option);
                });
            }
        }

        function viewReservation(id) {
            const reservation = allReservations.find(r => r.id == id);
            if (reservation) {
                const content = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6 style="color: var(--luxury-gold);">Informations Client</h6>
                            <p><strong>Nom:</strong> ${reservation.guest_name || 'N/A'}</p>
                            <p><strong>Email:</strong> ${reservation.guest_email || 'N/A'}</p>
                            <p><strong>Téléphone:</strong> ${reservation.guest_phone || 'Non renseigné'}</p>
                            <p><strong>Adresse:</strong> ${reservation.guest_address || 'Non renseignée'}</p>
                        </div>
                        <div class="col-md-6">
                            <h6 style="color: var(--luxury-gold);">Détails Réservation</h6>
                            <p><strong>ID:</strong> #${reservation.id}</p>
                            <p><strong>Référence:</strong> ${reservation.booking_reference || 'N/A'}</p>
                            <p><strong>Hôtel:</strong> ${reservation.hotel_name || 'N/A'}</p>
                            <p><strong>Type chambre:</strong> ${reservation.room_type_name || 'N/A'}</p>
                            <p><strong>Séjour:</strong> Du ${new Date(reservation.check_in).toLocaleDateString()} au ${new Date(reservation.check_out).toLocaleDateString()}</p>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <h6 style="color: var(--luxury-gold);">Occupants</h6>
                            <p><strong>Personnes:</strong> ${reservation.guests || 1}</p>
                        </div>
                        <div class="col-md-6">
                            <h6 style="color: var(--luxury-gold);">Financier</h6>
                            <p><strong>Montant total:</strong> €${parseFloat(reservation.total_price || 0).toFixed(2)}</p>
                            <p><strong>Statut paiement:</strong> ${reservation.payment_status || 'N/A'}</p>
                        </div>
                    </div>
                    ${reservation.special_requests ? `
                    <div class="mt-3">
                        <h6 style="color: var(--luxury-gold);">Demandes spéciales</h6>
                        <p>${reservation.special_requests}</p>
                    </div>` : ''}
                    ${reservation.cancellation_reason ? `
                    <div class="mt-3">
                        <h6 style="color: var(--luxury-red);">Raison d'annulation</h6>
                        <p>${reservation.cancellation_reason}</p>
                    </div>` : ''}
                `;
                
                document.getElementById('reservationDetailsContent').innerHTML = content;
                const modal = new bootstrap.Modal(document.getElementById('viewReservationModal'));
                modal.show();
            }
        }

        function editReservation(id) {
            const reservation = allReservations.find(r => r.id == id);
            if (reservation) {
                // Remplir le formulaire avec les données existantes
                document.querySelector('#addReservationForm [name="guest_name"]').value = reservation.guest_name || '';
                document.querySelector('#addReservationForm [name="guest_email"]').value = reservation.guest_email || '';
                document.querySelector('#addReservationForm [name="guest_phone"]').value = reservation.guest_phone || '';
                document.querySelector('#addReservationForm [name="guest_address"]').value = reservation.guest_address || '';
                document.querySelector('#addReservationForm [name="check_in_date"]').value = reservation.check_in;
                document.querySelector('#addReservationForm [name="check_out_date"]').value = reservation.check_out;
                document.querySelector('#addReservationForm [name="total_amount"]').value = reservation.total_price;
                document.querySelector('#addReservationForm [name="status"]').value = reservation.status;
                document.querySelector('#addReservationForm [name="payment_status"]').value = reservation.payment_status;
                document.querySelector('#addReservationForm [name="special_requests"]').value = reservation.special_requests || '';
                
                // Sélectionner l'hôtel et mettre à jour les types de chambres
                document.getElementById('reservation_hotel_id').value = reservation.hotel_id;
                updateRoomTypeOptions();
                setTimeout(() => {
                    document.getElementById('reservation_room_type_id').value = reservation.room_type_id;
                }, 100);
                
                document.getElementById('reservationFormAction').value = 'update_reservation';
                document.getElementById('reservationId').value = reservation.id;
                document.querySelector('#addReservationModal .modal-title').innerHTML = '<i class="fas fa-edit me-2"></i>Modifier la Réservation';
                
                const modal = new bootstrap.Modal(document.getElementById('addReservationModal'));
                modal.show();
            }
        }

        function cancelReservation(id) {
            document.getElementById('cancelReservationId').value = id;
            const modal = new bootstrap.Modal(document.getElementById('cancelReservationModal'));
            modal.show();
        }

        function changeReservationStatus(id) {
            document.getElementById('statusReservationId').value = id;
            const modal = new bootstrap.Modal(document.getElementById('changeStatusModal'));
            modal.show();
        }

        // Gestion des sélections en lot
        function toggleAllReservations() {
            const selectAll = document.getElementById('selectAllReservations');
            const checkboxes = document.querySelectorAll('.reservation-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateBulkActions();
        }

        function updateBulkActions() {
            const checkedBoxes = document.querySelectorAll('.reservation-checkbox:checked');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            
            if (checkedBoxes.length > 0) {
                bulkActions.style.display = 'block';
                selectedCount.textContent = checkedBoxes.length;
            } else {
                bulkActions.style.display = 'none';
            }
        }

        function clearSelection() {
            document.querySelectorAll('.reservation-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            document.getElementById('selectAllReservations').checked = false;
            updateBulkActions();
        }

        // Filtrage des réservations
        function filterReservations() {
            const statusFilter = document.getElementById('statusFilter').value;
            const paymentFilter = document.getElementById('paymentFilter').value;
            const hotelFilter = document.getElementById('hotelFilter').value;
            const dateRangeFilter = document.getElementById('dateRangeFilter').value;
            const checkinFilter = document.getElementById('checkinFilter').value;
            const guestSearch = document.getElementById('guestSearch').value.toLowerCase();
            
            const today = new Date().toISOString().split('T')[0];
            const weekFromNow = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
            const monthFromNow = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
            
            // Filtrer les lignes du tableau
            const tableRows = document.querySelectorAll('#reservationsTable tbody tr');
            const cardContainers = document.querySelectorAll('.reservation-card-container');
            
            function filterElement(element) {
                let show = true;
                
                if (statusFilter && element.dataset.status !== statusFilter) {
                    show = false;
                }
                
                if (paymentFilter && element.dataset.payment !== paymentFilter) {
                    show = false;
                }
                
                if (hotelFilter && element.dataset.hotel !== hotelFilter) {
                    show = false;
                }
                
                if (checkinFilter && element.dataset.checkin !== checkinFilter) {
                    show = false;
                }
                
                if (dateRangeFilter && dateRangeFilter !== 'all') {
                    const checkinDate = element.dataset.checkin;
                    switch (dateRangeFilter) {
                        case 'today':
                            if (checkinDate !== today) show = false;
                            break;
                        case 'week':
                            if (checkinDate < today || checkinDate > weekFromNow) show = false;
                            break;
                        case 'month':
                            if (checkinDate < today || checkinDate > monthFromNow) show = false;
                            break;
                    }
                }
                
                if (guestSearch && !element.dataset.guest.includes(guestSearch)) {
                    show = false;
                }
                
                element.style.display = show ? '' : 'none';
            }
            
            tableRows.forEach(filterElement);
            cardContainers.forEach(filterElement);
        }

        // Export des données
        function exportReservationData() {
            const data = {
                reservations: allReservations,
                stats: reservationStats,
                exportDate: new Date().toISOString(),
                filters: {
                    status: document.getElementById('statusFilter').value,
                    payment: document.getElementById('paymentFilter').value,
                    hotel: document.getElementById('hotelFilter').value,
                    dateRange: document.getElementById('dateRangeFilter').value
                }
            };
            
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `reservations_export_${new Date().toISOString().split('T')[0]}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        function exportSelectedReservations() {
            const checkedBoxes = document.querySelectorAll('.reservation-checkbox:checked');
            const selectedIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value));
            const selectedReservations = allReservations.filter(r => selectedIds.includes(r.id));
            
            const data = {
                reservations: selectedReservations,
                exportDate: new Date().toISOString(),
                note: `Export de ${selectedReservations.length} réservations sélectionnées`
            };
            
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `selected_reservations_${new Date().toISOString().split('T')[0]}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // Afficher le modal d'ajout d'hôtel
        function showAddHotelModal() {
            document.getElementById('addHotelForm').reset();
            document.getElementById('formAction').value = 'add_hotel';
            document.getElementById('hotelId').value = '';
            document.querySelector('#addHotelModal .modal-title').innerHTML = '<i class="fas fa-hotel me-2"></i>Ajouter un Hôtel';
            const modal = new bootstrap.Modal(document.getElementById('addHotelModal'));
            modal.show();
        }

        // Afficher le modal d'ajout de chambre
        function showAddRoomModal() {
            document.getElementById('addRoomForm').reset();
            document.getElementById('roomFormAction').value = 'add_room';
            document.getElementById('roomId').value = '';
            document.querySelector('#addRoomModal .modal-title').innerHTML = '<i class="fas fa-bed me-2"></i>Ajouter une Chambre';
            const modal = new bootstrap.Modal(document.getElementById('addRoomModal'));
            modal.show();
        }

        // Modifier un hôtel
        function editHotel(id) {
            const hotel = allHotels.find(h => h.id == id);
            if (hotel) {
                document.querySelector('#addHotelForm [name="name"]').value = hotel.name;
                document.querySelector('#addHotelForm [name="city"]').value = hotel.city;
                document.querySelector('#addHotelForm [name="description"]').value = hotel.description;
                document.querySelector('#addHotelForm [name="location"]').value = hotel.location;
                document.querySelector('#addHotelForm [name="price"]').value = hotel.price_per_night;
                document.querySelector('#addHotelForm [name="image_url"]').value = hotel.image_url;
                document.querySelector('#addHotelForm [name="is_featured"]').checked = hotel.is_featured == 1;
                
                document.getElementById('formAction').value = 'update_hotel';
                document.getElementById('hotelId').value = hotel.id;
                document.querySelector('#addHotelModal .modal-title').innerHTML = '<i class="fas fa-edit me-2"></i>Modifier l\'Hôtel';
                
                const modal = new bootstrap.Modal(document.getElementById('addHotelModal'));
                modal.show();
            }
        }

        // Modifier une chambre
        function editRoom(id) {
            const room = allRooms.find(r => r.id == id);
            if (room) {
                document.querySelector('#addRoomForm [name="hotel_id"]').value = room.hotel_id;
                document.querySelector('#addRoomForm [name="room_number"]').value = room.room_number;
                document.querySelector('#addRoomForm [name="room_type"]').value = room.room_type || room.room_type_name;
                document.querySelector('#addRoomForm [name="bed_count"]').value = room.bed_count;
                document.querySelector('#addRoomForm [name="price"]').value = room.price_per_night;
                document.querySelector('#addRoomForm [name="amenities"]').value = room.amenities;
                document.querySelector('#addRoomForm [name="status"]').value = room.status;
                document.querySelector('#addRoomForm [name="image_url"]').value = room.image_url || '';
                
                document.getElementById('roomFormAction').value = 'update_room';
                document.getElementById('roomId').value = room.id;
                document.querySelector('#addRoomModal .modal-title').innerHTML = '<i class="fas fa-edit me-2"></i>Modifier la Chambre';
                
                const modal = new bootstrap.Modal(document.getElementById('addRoomModal'));
                modal.show();
            }
        }

        // Mettre à jour l'image d'une chambre
        function updateRoomImage(id) {
            document.getElementById('imageRoomId').value = id;
            const modal = new bootstrap.Modal(document.getElementById('updateImageModal'));
            modal.show();
        }

        // Gérer les dates de disponibilité d'une chambre
        function setRoomAvailabilityDates(id) {
            document.getElementById('availabilityRoomId').value = id;
            const modal = new bootstrap.Modal(document.getElementById('roomAvailabilityModal'));
            modal.show();
        }

        // Voir le calendrier d'une chambre
        function viewRoomCalendar(id) {
            const room = allRooms.find(r => r.id == id);
            if (room) {
                document.querySelector('#roomCalendarModal .modal-title').innerHTML = 
                    `<i class="fas fa-calendar me-2"></i>Calendrier - ${room.hotel_name} - Chambre ${room.room_number}`;
                
                // Générer le calendrier (simplifié pour cet exemple)
                const calendarContent = document.getElementById('roomCalendarContent');
                calendarContent.innerHTML = `
                    <div class="calendar-container">
                        <h6 style="color: var(--luxury-gold);">Calendrier de disponibilité</h6>
                        <p class="text-muted">Aperçu des prochains jours</p>
                        <div class="calendar-grid">
                            ${generateCalendarDays()}
                        </div>
                        <div class="mt-3">
                            <span class="badge badge-available me-2">Disponible</span>
                            <span class="badge badge-occupied me-2">Occupée</span>
                            <span class="badge badge-maintenance">Maintenance</span>
                        </div>
                    </div>
                `;
                
                const modal = new bootstrap.Modal(document.getElementById('roomCalendarModal'));
                modal.show();
            }
        }

        // Générer les jours du calendrier
        function generateCalendarDays() {
            let html = '';
            const today = new Date();
            for (let i = 0; i < 21; i++) {
                const date = new Date(today);
                date.setDate(today.getDate() + i);
                const dayNumber = date.getDate();
                const status = Math.random() > 0.7 ? (Math.random() > 0.5 ? 'occupied' : 'maintenance') : 'available';
                html += `<div class="calendar-day ${status}" title="${date.toLocaleDateString()}">${dayNumber}</div>`;
            }
            return html;
        }

        // Supprimer un hôtel
        function deleteHotel(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cet hôtel?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_hotel">
                    <input type="hidden" name="hotel_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Supprimer une chambre
        function deleteRoom(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette chambre?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_room">
                    <input type="hidden" name="room_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Changement rapide de statut
        function quickStatusChange(roomId, newStatus) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="update_room_availability">
                <input type="hidden" name="room_id" value="${roomId}">
                <input type="hidden" name="status" value="${newStatus}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // Sélectionner/désélectionner toutes les chambres
        function toggleAllRooms() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.room-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }

        // Sélectionner/désélectionner toutes les chambres pour les dates
        function toggleAllDateRooms() {
            const selectAll = document.getElementById('selectAllDates');
            const checkboxes = document.querySelectorAll('.date-room-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }

        // Définir les dates par défaut pour les rapports
        function setDefaultReportDates() {
            const today = new Date();
            const lastMonth = new Date(today);
            lastMonth.setMonth(today.getMonth() - 1);
            
            document.getElementById('reportStartDate').value = lastMonth.toISOString().split('T')[0];
            document.getElementById('reportEndDate').value = today.toISOString().split('T')[0];
        }

        // Générer un rapport avancé
        function generateAdvancedReport() {
            const startDate = document.getElementById('reportStartDate').value;
            const endDate = document.getElementById('reportEndDate').value;
            const hotelId = document.getElementById('reportHotelFilter').value;
            
            if (!startDate || !endDate) {
                alert('Veuillez sélectionner les dates de début et de fin.');
                return;
            }
            
            // Simuler la génération de rapport
            const reportResults = document.getElementById('reportResults');
            const reportContent = document.getElementById('reportContent');
            
            reportContent.innerHTML = `
                <div class="row">
                    <div class="col-md-4">
                        <h6 style="color: var(--luxury-gold);">Taux d'Occupation Moyen</h6>
                        <div class="stats-card mb-3">
                            <div class="stats-number">${Math.floor(Math.random() * 30) + 50}%</div>
                            <div class="stats-label">Du ${startDate} au ${endDate}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <h6 style="color: var(--luxury-gold);">Revenus Générés</h6>
                        <div class="stats-card mb-3">
                            <div class="stats-number">€${(Math.random() * 50000 + 10000).toFixed(0)}</div>
                            <div class="stats-label">Revenus totaux</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <h6 style="color: var(--luxury-gold);">Réservations</h6>
                        <div class="stats-card mb-3">
                            <div class="stats-number">${Math.floor(Math.random() * 50) + 20}</div>
                            <div class="stats-label">Total réservations</div>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <canvas id="reportChart"></canvas>
                </div>
            `;
            
            reportResults.style.display = 'block';
            
            // Créer un graphique pour le rapport
            setTimeout(() => {
                const ctx = document.getElementById('reportChart');
                if (ctx) {
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: ['Semaine 1', 'Semaine 2', 'Semaine 3', 'Semaine 4'],
                            datasets: [{
                                label: 'Taux d\'occupation (%)',
                                data: [65, 72, 58, 81],
                                backgroundColor: 'rgba(212, 175, 55, 0.6)',
                                borderColor: '#d4af37',
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    labels: {
                                        color: '#ecf0f1'
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    ticks: {
                                        color: '#ecf0f1'
                                    },
                                    grid: {
                                        color: 'rgba(212, 175, 55, 0.2)'
                                    }
                                },
                                x: {
                                    ticks: {
                                        color: '#ecf0f1'
                                    },
                                    grid: {
                                        color: 'rgba(212, 175, 55, 0.2)'
                                    }
                                }
                            }
                        }
                    });
                }
            }, 100);
        }

        // Actualiser les statistiques
        function refreshStats() {
            location.reload();
        }

        // Gestion des formulaires en lot
        document.getElementById('bulkUpdateForm').addEventListener('submit', function(e) {
            const checkedBoxes = document.querySelectorAll('.room-checkbox:checked');
            
            if (checkedBoxes.length === 0) {
                e.preventDefault();
                alert('Veuillez sélectionner au moins une chambre.');
                return;
            }

            // Ajouter les IDs des chambres sélectionnées
            checkedBoxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'room_ids[]';
                input.value = checkbox.value;
                this.appendChild(input);
            });
        });

        // Gestion du formulaire de dates en lot
        document.getElementById('bulkDateForm').addEventListener('submit', function(e) {
            const checkedBoxes = document.querySelectorAll('.date-room-checkbox:checked');
            
            if (checkedBoxes.length === 0) {
                e.preventDefault();
                alert('Veuillez sélectionner au moins une chambre.');
                return;
            }

            // Ajouter les IDs des chambres sélectionnées
            checkedBoxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'room_ids[]';
                input.value = checkbox.value;
                this.appendChild(input);
            });
        });

        // Gestion du formulaire de réservations en lot
        document.getElementById('bulkReservationForm').addEventListener('submit', function(e) {
            const checkedBoxes = document.querySelectorAll('.reservation-checkbox:checked');
            
            if (checkedBoxes.length === 0) {
                e.preventDefault();
                alert('Veuillez sélectionner au moins une réservation.');
                return;
            }

            // Ajouter les IDs des réservations sélectionnées
            checkedBoxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'reservation_ids[]';
                input.value = checkbox.value;
                this.appendChild(input);
            });
        });

        // Animation des statistiques au chargement
        function animateStats() {
            const statsNumbers = document.querySelectorAll('.stats-number');
            statsNumbers.forEach((stat, index) => {
                const finalText = stat.textContent;
                const finalValue = parseInt(finalText.replace(/[€,]/g, ''));
                let currentValue = 0;
                const increment = finalValue / 50;
                
                setTimeout(() => {
                    const timer = setInterval(() => {
                        currentValue += increment;
                        if (currentValue >= finalValue) {
                            currentValue = finalValue;
                            clearInterval(timer);
                        }
                        
                        if (finalText.includes('€')) {
                            stat.textContent = '€' + Math.floor(currentValue).toLocaleString();
                        } else {
                            stat.textContent = Math.floor(currentValue);
                        }
                    }, 20);
                }, index * 200);
            });
        }

        // Initialisation au chargement de la page
        document.addEventListener('DOMContentLoaded', function() {
            // Définir les dates minimales pour aujourd'hui
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                input.min = today;
            });
            
            // Animer les statistiques
            animateStats();
            
            // Initialiser les graphiques
            initCharts();

            // Ajouter les event listeners pour les checkboxes de réservation
            document.querySelectorAll('.reservation-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', updateBulkActions);
            });

            console.log(`Administration chargée avec ${allReservations.length} réservations`);
        });
    </script>
</body>
</html>