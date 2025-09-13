<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Vérifie si l'utilisateur est connecté
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Classe UserManager intégrée
class UserManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function getCurrentUser() {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erreur getCurrentUser: " . $e->getMessage());
            return null;
        }
    }
    
    public function updateProfile($userId, $data) {
        try {
            // Validation des données
            if (empty($data['full_name'])) {
                throw new Exception('Le nom complet est requis');
            }
            
            // Validation du téléphone si fourni
            if (!empty($data['phone']) && !preg_match('/^[\+]?[0-9\s\-()]{10,}$/', $data['phone'])) {
                throw new Exception('Format de téléphone invalide');
            }
            
            // Validation de la date de naissance si fournie
            if (!empty($data['date_of_birth'])) {
                $date = DateTime::createFromFormat('Y-m-d', $data['date_of_birth']);
                if (!$date || $date->format('Y-m-d') !== $data['date_of_birth']) {
                    throw new Exception('Format de date invalide');
                }
                
                // Vérifier que la date n'est pas dans le futur
                if ($date > new DateTime()) {
                    throw new Exception('La date de naissance ne peut pas être dans le futur');
                }
            }
            
            $sql = "UPDATE users SET 
                    full_name = ?, 
                    phone = ?, 
                    address = ?, 
                    date_of_birth = ?,
                    updated_at = NOW()
                    WHERE id = ?";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                $data['full_name'],
                $data['phone'] ?: null,
                $data['address'] ?: null,
                $data['date_of_birth'] ?: null,
                $userId
            ]);
            
            if ($result) {
                // Log de sécurité
                $this->logSecurityEvent($userId, 'profile_update', [
                    'updated_fields' => array_keys($data)
                ]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Erreur updateProfile: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function uploadProfilePhoto($userId, $file) {
        try {
            // Debug: Log les informations du fichier
            error_log("Upload attempt - File info: " . print_r($file, true));
            
            // Vérification des erreurs d'upload
            if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
                $errorMessages = [
                    UPLOAD_ERR_INI_SIZE => 'Le fichier dépasse la taille maximale autorisée par le serveur.',
                    UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale du formulaire.',
                    UPLOAD_ERR_PARTIAL => 'Le fichier n\'a été que partiellement uploadé.',
                    UPLOAD_ERR_NO_FILE => 'Aucun fichier n\'a été uploadé.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant.',
                    UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire le fichier sur le disque.',
                    UPLOAD_ERR_EXTENSION => 'Upload stoppé par une extension PHP.'
                ];
                
                $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
                $errorMsg = $errorMessages[$errorCode] ?? 'Erreur d\'upload inconnue.';
                error_log("Upload error: " . $errorMsg . " (Code: " . $errorCode . ")");
                return ['success' => false, 'message' => $errorMsg];
            }
            
            // Vérifier que le fichier existe
            if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                error_log("File not uploaded properly or tmp_name missing");
                return ['success' => false, 'message' => 'Fichier non valide ou corrompu.'];
            }
            
            // Validation du fichier
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            // Vérifier le type MIME
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
            } else {
                // Fallback si finfo n'est pas disponible
                $mimeType = mime_content_type($file['tmp_name']);
            }
            
            if (!$mimeType || !in_array($mimeType, $allowedTypes)) {
                error_log("Invalid file type: " . ($mimeType ?: 'unknown'));
                return ['success' => false, 'message' => 'Type de fichier non autorisé. Utilisez JPG, PNG, GIF ou WebP.'];
            }
            
            if (!isset($file['size']) || $file['size'] > $maxSize) {
                error_log("File too large: " . ($file['size'] ?? 'unknown') . " bytes");
                return ['success' => false, 'message' => 'Le fichier est trop volumineux (max 5MB).'];
            }
            
            if ($file['size'] <= 0) {
                error_log("File size is zero or negative");
                return ['success' => false, 'message' => 'Le fichier semble être vide.'];
            }
            
            // Créer le dossier uploads s'il n'existe pas
            $uploadDir = 'uploads/profiles/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    error_log("Failed to create upload directory: " . $uploadDir);
                    return ['success' => false, 'message' => 'Impossible de créer le dossier d\'upload.'];
                }
            }
            
            // Vérifier les permissions du dossier
            if (!is_writable($uploadDir)) {
                error_log("Upload directory not writable: " . $uploadDir);
                return ['success' => false, 'message' => 'Le dossier d\'upload n\'est pas accessible en écriture.'];
            }
            
            // Générer un nom de fichier unique et sécurisé
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (empty($extension)) {
                // Déterminer l'extension basée sur le type MIME
                $mimeToExt = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp'
                ];
                $extension = $mimeToExt[$mimeType] ?? 'jpg';
            }
            
            $filename = 'profile_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $filepath = $uploadDir . $filename;
            
            // Vérifier les dimensions de l'image avant de la déplacer
            $imageInfo = getimagesize($file['tmp_name']);
            if (!$imageInfo) {
                error_log("Invalid image file: " . $file['tmp_name']);
                return ['success' => false, 'message' => 'Fichier image invalide.'];
            }

            if ($imageInfo[0] < 50 || $imageInfo[1] < 50) {
                error_log("Image too small: " . $imageInfo[0] . "x" . $imageInfo[1]);
                return ['success' => false, 'message' => 'L\'image doit faire au moins 50x50 pixels.'];
            }

            // Supprimer l'ancienne photo si elle existe
            $this->deleteOldProfilePhoto($userId);
            
            // Déplacer le fichier uploadé
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                error_log("Failed to move uploaded file from " . $file['tmp_name'] . " to " . $filepath);
                return ['success' => false, 'message' => 'Erreur lors du déplacement du fichier.'];
            }
            
            // Vérifier que le fichier a bien été déplacé
            if (!file_exists($filepath)) {
                error_log("File does not exist after move: " . $filepath);
                return ['success' => false, 'message' => 'Le fichier n\'a pas pu être sauvegardé.'];
            }
            
            // Redimensionner l'image
            if (!$this->resizeImage($filepath, 300, 300)) {
                error_log("Failed to resize image: " . $filepath);
                // Ne pas échouer si le redimensionnement échoue, continuer avec l'image originale
            }
            
            // Mettre à jour la base de données
            try {
                $stmt = $this->pdo->prepare("UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$filepath, $userId]);
                
                if (!$result) {
                    error_log("Database update failed for user " . $userId);
                    // Supprimer le fichier si l'update DB échoue
                    if (file_exists($filepath)) {
                        unlink($filepath);
                    }
                    return ['success' => false, 'message' => 'Erreur lors de la mise à jour de la base de données.'];
                }
                
                // Vérifier que la mise à jour a bien eu lieu
                if ($stmt->rowCount() === 0) {
                    error_log("No rows updated for user " . $userId);
                    if (file_exists($filepath)) {
                        unlink($filepath);
                    }
                    return ['success' => false, 'message' => 'Utilisateur non trouvé.'];
                }
                
            } catch (PDOException $e) {
                error_log("Database error: " . $e->getMessage());
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
                return ['success' => false, 'message' => 'Erreur de base de données: ' . $e->getMessage()];
            }
            
            // Log de sécurité
            $this->logSecurityEvent($userId, 'profile_photo_upload', [
                'filename' => $filename,
                'original_name' => $file['name'] ?? 'unknown',
                'size' => $file['size'],
                'mime_type' => $mimeType
            ]);
            
            error_log("Photo upload successful for user " . $userId . ": " . $filepath);
            
            return [
                'success' => true, 
                'message' => 'Photo de profil mise à jour avec succès',
                'photo_url' => $filepath
            ];
            
        } catch (Exception $e) {
            error_log("Exception in uploadProfilePhoto: " . $e->getMessage());
            return ['success' => false, 'message' => 'Erreur lors de l\'upload: ' . $e->getMessage()];
        }
    }
    
    public function deleteProfilePhoto($userId) {
        try {
            // Récupérer l'ancienne photo
            $stmt = $this->pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && !empty($user['profile_photo'])) {
                // Supprimer le fichier physique
                if (file_exists($user['profile_photo'])) {
                    unlink($user['profile_photo']);
                }
                
                // Mettre à jour la base de données
                $stmt = $this->pdo->prepare("UPDATE users SET profile_photo = NULL, updated_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$userId]);
                
                if ($result) {
                    $this->logSecurityEvent($userId, 'profile_photo_delete', []);
                }
                
                return $result;
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Erreur deleteProfilePhoto: " . $e->getMessage());
            return false;
        }
    }
    
    private function deleteOldProfilePhoto($userId) {
        try {
            $stmt = $this->pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && !empty($user['profile_photo']) && file_exists($user['profile_photo'])) {
                unlink($user['profile_photo']);
            }
        } catch (Exception $e) {
            error_log("Erreur deleteOldProfilePhoto: " . $e->getMessage());
        }
    }
    
    private function resizeImage($filepath, $maxWidth, $maxHeight) {
        try {
            // Vérifier que le fichier existe
            if (!file_exists($filepath)) {
                error_log("File does not exist for resize: " . $filepath);
                return false;
            }
            
            // Vérifier que GD est disponible
            if (!extension_loaded('gd')) {
                error_log("GD extension not loaded");
                return false;
            }
            
            $imageInfo = getimagesize($filepath);
            if (!$imageInfo) {
                error_log("Cannot get image info for: " . $filepath);
                return false;
            }
            
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $type = $imageInfo[2];
            
            // Si l'image est déjà plus petite que les dimensions max, ne pas redimensionner
            if ($width <= $maxWidth && $height <= $maxHeight) {
                return true;
            }
            
            // Calculer les nouvelles dimensions
            $ratio = min($maxWidth / $width, $maxHeight / $height);
            $newWidth = round($width * $ratio);
            $newHeight = round($height * $ratio);
            
            // Créer l'image source
            $source = null;
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg($filepath);
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng($filepath);
                    break;
                case IMAGETYPE_GIF:
                    $source = imagecreatefromgif($filepath);
                    break;
                case IMAGETYPE_WEBP:
                    if (function_exists('imagecreatefromwebp')) {
                        $source = imagecreatefromwebp($filepath);
                    }
                    break;
                default:
                    error_log("Unsupported image type: " . $type);
                    return false;
            }
            
            if (!$source) {
                error_log("Failed to create image source from: " . $filepath);
                return false;
            }
            
            // Créer la nouvelle image
            $destination = imagecreatetruecolor($newWidth, $newHeight);
            if (!$destination) {
                imagedestroy($source);
                error_log("Failed to create destination image");
                return false;
            }
            
            // Préserver la transparence pour PNG et GIF
            if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
                imagealphablending($destination, false);
                imagesavealpha($destination, true);
                $transparent = imagecolorallocatealpha($destination, 255, 255, 255, 127);
                imagefilledrectangle($destination, 0, 0, $newWidth, $newHeight, $transparent);
            }
            
            // Redimensionner
            if (!imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
                imagedestroy($source);
                imagedestroy($destination);
                error_log("Failed to resample image");
                return false;
            }
            
            // Sauvegarder
            $saved = false;
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $saved = imagejpeg($destination, $filepath, 90);
                    break;
                case IMAGETYPE_PNG:
                    $saved = imagepng($destination, $filepath);
                    break;
                case IMAGETYPE_GIF:
                    $saved = imagegif($destination, $filepath);
                    break;
                case IMAGETYPE_WEBP:
                    if (function_exists('imagewebp')) {
                        $saved = imagewebp($destination, $filepath, 90);
                    }
                    break;
            }
            
            // Libérer la mémoire
            imagedestroy($source);
            imagedestroy($destination);
            
            if (!$saved) {
                error_log("Failed to save resized image: " . $filepath);
                return false;
            }
            
            error_log("Image resized successfully: " . $filepath);
            return true;
            
        } catch (Exception $e) {
            error_log("Exception in resizeImage: " . $e->getMessage());
            return false;
        }
    }
    
    public function getUserStats($userId) {
        try {
            $stats = [
                'total_bookings' => 0,
                'confirmed_bookings' => 0,
                'total_spent' => 0,
                'favorite_city' => 'Aucune'
            ];
            
            // Vérifier si la table bookings existe
            try {
                $stmt = $this->pdo->query("SHOW TABLES LIKE 'bookings'");
                if ($stmt->rowCount() === 0) {
                    error_log("Table 'bookings' does not exist");
                    return $stats;
                }
            } catch (Exception $e) {
                error_log("Error checking bookings table: " . $e->getMessage());
                return $stats;
            }
            
            // Total des réservations (toutes les réservations de l'utilisateur)
            $stmt = $this->pdo->prepare("SELECT COUNT(*) as total FROM bookings WHERE user_id = ?");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['total_bookings'] = (int)$result['total'];
            
            // Réservations confirmées (statut confirmed ET payées)
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as confirmed 
                FROM bookings 
                WHERE user_id = ? AND (status = 'confirmed' OR payment_status = 'paid')
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['confirmed_bookings'] = (int)$result['confirmed'];
            
            // Total dépensé (seulement les réservations payées)
            $stmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(total_price), 0) as total_spent 
                FROM bookings 
                WHERE user_id = ? AND payment_status = 'paid'
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['total_spent'] = (float)$result['total_spent'];
            
            // Ville préférée (basée sur le nombre de réservations par ville)
            try {
                $stmt = $this->pdo->prepare("
                    SELECT h.city, COUNT(*) as count 
                    FROM bookings b 
                    LEFT JOIN hotels h ON b.hotel_id = h.id 
                    WHERE b.user_id = ? AND h.city IS NOT NULL AND h.city != ''
                    GROUP BY h.city 
                    ORDER BY count DESC, h.city ASC
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result && !empty($result['city'])) {
                    $stats['favorite_city'] = $result['city'];
                }
            } catch (Exception $e) {
                error_log("Error getting favorite city: " . $e->getMessage());
            }
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Erreur getUserStats: " . $e->getMessage());
            return [
                'total_bookings' => 0,
                'confirmed_bookings' => 0,
                'total_spent' => 0,
                'favorite_city' => 'Aucune'
            ];
        }
    }
    
    public function getRecentBookings($userId, $limit = 5) {
        try {
            // Vérifier si les tables existent
            $tablesExist = $this->checkTablesExist(['bookings', 'hotels', 'room_types']);
            if (!$tablesExist['bookings']) {
                error_log("Table 'bookings' does not exist");
                return [];
            }
            
            // Vérifier d'abord quelles colonnes existent dans les tables
            $hotelColumns = [];
            $roomTypeColumns = [];
            
            if ($tablesExist['hotels']) {
                try {
                    $stmt = $this->pdo->query("DESCRIBE hotels");
                    while ($row = $stmt->fetch()) {
                        $hotelColumns[] = $row['Field'];
                    }
                } catch (Exception $e) {
                    $hotelColumns = ['id', 'name', 'city'];
                }
            }
            
            if ($tablesExist['room_types']) {
                try {
                    $stmt = $this->pdo->query("DESCRIBE room_types");
                    while ($row = $stmt->fetch()) {
                        $roomTypeColumns[] = $row['Field'];
                    }
                } catch (Exception $e) {
                    $roomTypeColumns = ['id', 'name', 'price_per_night'];
                }
            }
            
            // Construire la requête avec les colonnes disponibles
            $hotelFields = "COALESCE(h.name, 'Hôtel non trouvé') as hotel_name, 
                           COALESCE(h.city, 'Ville non spécifiée') as city";
            
            if (in_array('address', $hotelColumns)) {
                $hotelFields .= ", COALESCE(h.address, '') as hotel_address";
            } else {
                $hotelFields .= ", '' as hotel_address";
            }
            
            if (in_array('phone', $hotelColumns)) {
                $hotelFields .= ", COALESCE(h.phone, '') as hotel_phone";
            } else {
                $hotelFields .= ", '' as hotel_phone";
            }
            
            if (in_array('email', $hotelColumns)) {
                $hotelFields .= ", COALESCE(h.email, '') as hotel_email";
            } else {
                $hotelFields .= ", '' as hotel_email";
            }
            
            $roomFields = "COALESCE(rt.name, 'Type de chambre non spécifié') as room_type_name";
            
            if (in_array('price_per_night', $roomTypeColumns)) {
                $roomFields .= ", COALESCE(rt.price_per_night, 0) as price_per_night";
            } else {
                $roomFields .= ", 0 as price_per_night";
            }
            
            if (in_array('description', $roomTypeColumns)) {
                $roomFields .= ", COALESCE(rt.description, '') as room_description";
            } else {
                $roomFields .= ", '' as room_description";
            }
            
            if (in_array('capacity', $roomTypeColumns)) {
                $roomFields .= ", COALESCE(rt.capacity, 1) as room_capacity";
            } else {
                $roomFields .= ", 1 as room_capacity";
            }
            
            $sql = "SELECT 
                b.id, b.hotel_id, b.room_type_id, b.check_in, b.check_out, 
                b.guests, b.total_price, b.special_requests, b.status, b.payment_status,
                b.created_at, b.updated_at,
                {$hotelFields},
                {$roomFields}
            FROM bookings b";
            
            if ($tablesExist['hotels']) {
                $sql .= " LEFT JOIN hotels h ON b.hotel_id = h.id";
            } else {
                $sql .= " LEFT JOIN (SELECT NULL as id, 'Hôtel non trouvé' as name, 'Ville non spécifiée' as city, '' as address, '' as phone, '' as email) h ON 1=1";
            }
            
            if ($tablesExist['room_types']) {
                $sql .= " LEFT JOIN room_types rt ON b.room_type_id = rt.id";
            } else {
                $sql .= " LEFT JOIN (SELECT NULL as id, 'Type de chambre non spécifié' as name, 0 as price_per_night, '' as description, 1 as capacity) rt ON 1=1";
            }
            
            $sql .= " WHERE b.user_id = ? ORDER BY b.created_at DESC LIMIT ?";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$userId, $limit]);
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Enrichir les données avec des calculs
            foreach ($bookings as &$booking) {
                // Calculer le nombre de nuits
                if ($booking['check_in'] && $booking['check_out']) {
                    $checkIn = new DateTime($booking['check_in']);
                    $checkOut = new DateTime($booking['check_out']);
                    $booking['nights'] = max(1, $checkIn->diff($checkOut)->days);
                } else {
                    $booking['nights'] = 1;
                }
                
                // Déterminer si la réservation est passée, actuelle ou future
                $now = new DateTime();
                $checkIn = new DateTime($booking['check_in']);
                $checkOut = new DateTime($booking['check_out']);
                
                if ($checkOut < $now) {
                    $booking['booking_period'] = 'past';
                } elseif ($checkIn <= $now && $checkOut >= $now) {
                    $booking['booking_period'] = 'current';
                } else {
                    $booking['booking_period'] = 'future';
                }
                
                // Calculer le prix par nuit si pas disponible
                if ($booking['nights'] > 0 && $booking['total_price'] > 0) {
                    $booking['calculated_price_per_night'] = $booking['total_price'] / $booking['nights'];
                } else {
                    $booking['calculated_price_per_night'] = $booking['price_per_night'];
                }
            }
            
            return $bookings;
            
        } catch (Exception $e) {
            error_log("Erreur getRecentBookings: " . $e->getMessage());
            return [];
        }
    }

    public function getAllBookings($userId, $limit = 50, $offset = 0, $status_filter = null, $sort_by = 'created_at', $sort_order = 'DESC') {
        try {
            // Vérifier si les tables existent
            $tablesExist = $this->checkTablesExist(['bookings', 'hotels', 'room_types']);
            if (!$tablesExist['bookings']) {
                error_log("Table 'bookings' does not exist");
                return [
                    'bookings' => [],
                    'total' => 0,
                    'limit' => $limit,
                    'offset' => $offset
                ];
            }
            
            // Validation des paramètres
            $valid_sort_fields = ['created_at', 'check_in', 'check_out', 'total_price', 'status'];
            $valid_sort_orders = ['ASC', 'DESC'];
            
            if (!in_array($sort_by, $valid_sort_fields)) {
                $sort_by = 'created_at';
            }
            
            if (!in_array($sort_order, $valid_sort_orders)) {
                $sort_order = 'DESC';
            }
            
            // Construire la clause WHERE
            $whereClause = "WHERE b.user_id = ?";
            $params = [$userId];
            
            if ($status_filter && $status_filter !== 'all') {
                $whereClause .= " AND b.status = ?";
                $params[] = $status_filter;
            }
            
            // Vérifier les colonnes disponibles (même logique que getRecentBookings)
            $hotelColumns = [];
            $roomTypeColumns = [];
            
            if ($tablesExist['hotels']) {
                try {
                    $stmt = $this->pdo->query("DESCRIBE hotels");
                    while ($row = $stmt->fetch()) {
                        $hotelColumns[] = $row['Field'];
                    }
                } catch (Exception $e) {
                    $hotelColumns = ['id', 'name', 'city'];
                }
            }
            
            if ($tablesExist['room_types']) {
                try {
                    $stmt = $this->pdo->query("DESCRIBE room_types");
                    while ($row = $stmt->fetch()) {
                        $roomTypeColumns[] = $row['Field'];
                    }
                } catch (Exception $e) {
                    $roomTypeColumns = ['id', 'name', 'price_per_night'];
                }
            }
            
            // Construire les champs
            $hotelFields = "COALESCE(h.name, 'Hôtel non trouvé') as hotel_name, 
                           COALESCE(h.city, 'Ville non spécifiée') as city";
            
            if (in_array('address', $hotelColumns)) {
                $hotelFields .= ", COALESCE(h.address, '') as hotel_address";
            } else {
                $hotelFields .= ", '' as hotel_address";
            }
            
            if (in_array('phone', $hotelColumns)) {
                $hotelFields .= ", COALESCE(h.phone, '') as hotel_phone";
            } else {
                $hotelFields .= ", '' as hotel_phone";
            }
            
            if (in_array('email', $hotelColumns)) {
                $hotelFields .= ", COALESCE(h.email, '') as hotel_email";
            } else {
                $hotelFields .= ", '' as hotel_email";
            }
            
            $roomFields = "COALESCE(rt.name, 'Type de chambre non spécifié') as room_type_name";
            
            if (in_array('price_per_night', $roomTypeColumns)) {
                $roomFields .= ", COALESCE(rt.price_per_night, 0) as price_per_night";
            } else {
                $roomFields .= ", 0 as price_per_night";
            }
            
            if (in_array('description', $roomTypeColumns)) {
                $roomFields .= ", COALESCE(rt.description, '') as room_description";
            } else {
                $roomFields .= ", '' as room_description";
            }
            
            if (in_array('capacity', $roomTypeColumns)) {
                $roomFields .= ", COALESCE(rt.capacity, 1) as room_capacity";
            } else {
                $roomFields .= ", 1 as room_capacity";
            }
            
            // Requête principale
            $sql = "SELECT 
                b.id, b.hotel_id, b.room_type_id, b.check_in, b.check_out, 
                b.guests, b.total_price, b.special_requests, b.status, b.payment_status,
                b.created_at, b.updated_at,
                {$hotelFields},
                {$roomFields}
            FROM bookings b";
            
            if ($tablesExist['hotels']) {
                $sql .= " LEFT JOIN hotels h ON b.hotel_id = h.id";
            } else {
                $sql .= " LEFT JOIN (SELECT NULL as id, 'Hôtel non trouvé' as name, 'Ville non spécifiée' as city, '' as address, '' as phone, '' as email) h ON 1=1";
            }
            
            if ($tablesExist['room_types']) {
                $sql .= " LEFT JOIN room_types rt ON b.room_type_id = rt.id";
            } else {
                $sql .= " LEFT JOIN (SELECT NULL as id, 'Type de chambre non spécifié' as name, 0 as price_per_night, '' as description, 1 as capacity) rt ON 1=1";
            }
            
            $sql .= " {$whereClause} ORDER BY b.{$sort_by} {$sort_order} LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Enrichir les données
            foreach ($bookings as &$booking) {
                // Calculer le nombre de nuits
                if ($booking['check_in'] && $booking['check_out']) {
                    $checkIn = new DateTime($booking['check_in']);
                    $checkOut = new DateTime($booking['check_out']);
                    $booking['nights'] = max(1, $checkIn->diff($checkOut)->days);
                } else {
                    $booking['nights'] = 1;
                }
                
                // Déterminer la période
                $now = new DateTime();
                $checkIn = new DateTime($booking['check_in']);
                $checkOut = new DateTime($booking['check_out']);
                
                if ($checkOut < $now) {
                    $booking['booking_period'] = 'past';
                } elseif ($checkIn <= $now && $checkOut >= $now) {
                    $booking['booking_period'] = 'current';
                } else {
                    $booking['booking_period'] = 'future';
                }
                
                // Calculer le prix par nuit
                if ($booking['nights'] > 0 && $booking['total_price'] > 0) {
                    $booking['calculated_price_per_night'] = $booking['total_price'] / $booking['nights'];
                } else {
                    $booking['calculated_price_per_night'] = $booking['price_per_night'];
                }
            }
            
            // Compter le total pour la pagination
            $countSql = "SELECT COUNT(*) as total FROM bookings b {$whereClause}";
            $countParams = array_slice($params, 0, -2); // Retirer limit et offset
            $countStmt = $this->pdo->prepare($countSql);
            $countStmt->execute($countParams);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            return [
                'bookings' => $bookings,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ];
            
        } catch (Exception $e) {
            error_log("Erreur getAllBookings: " . $e->getMessage());
            return [
                'bookings' => [],
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset
            ];
        }
    }
    
    private function checkTablesExist($tables) {
        $result = [];
        foreach ($tables as $table) {
            try {
                $stmt = $this->pdo->query("SHOW TABLES LIKE '{$table}'");
                $result[$table] = $stmt->rowCount() > 0;
            } catch (Exception $e) {
                error_log("Error checking table {$table}: " . $e->getMessage());
                $result[$table] = false;
            }
        }
        return $result;
    }
    
    private function logSecurityEvent($userId, $action, $details) {
        try {
            // Vérifier si la table security_logs existe
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'security_logs'");
            if ($stmt->rowCount() > 0) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO security_logs (user_id, action, details, ip_address, user_agent, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $userId,
                    $action,
                    json_encode($details),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            }
        } catch (Exception $e) {
            error_log("Erreur logSecurityEvent: " . $e->getMessage());
        }
    }
}

// Instancie UserManager
$userManager = new UserManager($pdo);

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_profile':
                    $updateData = [
                        'full_name' => trim($_POST['full_name']),
                        'phone' => trim($_POST['phone']),
                        'address' => trim($_POST['address']),
                        'date_of_birth' => $_POST['date_of_birth'] ?: null
                    ];
                    
                    $result = $userManager->updateProfile($_SESSION['user_id'], $updateData);
                    
                    if ($result) {
                        echo json_encode(['success' => true, 'message' => 'Profil mis à jour avec succès']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
                    }
                    break;
                    
                case 'upload_photo':
                    if (isset($_FILES['profile_photo'])) {
                        $uploadResult = $userManager->uploadProfilePhoto($_SESSION['user_id'], $_FILES['profile_photo']);
                        echo json_encode($uploadResult);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Aucun fichier sélectionné']);
                    }
                    break;
                    
                case 'delete_photo':
                    $result = $userManager->deleteProfilePhoto($_SESSION['user_id']);
                    
                    if ($result) {
                        echo json_encode(['success' => true, 'message' => 'Photo de profil supprimée']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression']);
                    }
                    break;

                case 'get_all_bookings':
                    $limit = isset($_POST['limit']) ? max(1, min(100, (int)$_POST['limit'])) : 10;
                    $offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;
                    $status_filter = isset($_POST['status_filter']) ? $_POST['status_filter'] : null;
                    $sort_by = isset($_POST['sort_by']) ? $_POST['sort_by'] : 'created_at';
                    $sort_order = isset($_POST['sort_order']) ? $_POST['sort_order'] : 'DESC';
                    
                    $result = $userManager->getAllBookings($_SESSION['user_id'], $limit, $offset, $status_filter, $sort_by, $sort_order);
                    echo json_encode(['success' => true, 'data' => $result]);
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Action non reconnue']);
                    break;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Aucune action spécifiée']);
        }
    } catch (Exception $e) {
        error_log("Error in POST handler: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    }
    exit();
}

// Récupère les données utilisateur
$user = $userManager->getCurrentUser();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Définir le rôle utilisateur dans la session
if (isset($user['role'])) {
    $_SESSION['user_role'] = $user['role'];
}

// Récupère les statistiques
$stats = $userManager->getUserStats($user['id']);
$recentBookings = $userManager->getRecentBookings($user['id'], 5);

// Récupère toutes les réservations pour la nouvelle section
$allBookingsData = $userManager->getAllBookings($user['id'], 20, 0);
$allBookings = $allBookingsData['bookings'];
$totalBookings = $allBookingsData['total'];

// Fonction de formatage du prix
function formatPrice($price) {
    if ($price === null || $price === '' || $price === 0) {
        return '0 MAD';
    }
    
    // Convertir en nombre si c'est une chaîne
    $price = (float)$price;
    
    // Formater avec des espaces comme séparateurs de milliers
    return number_format($price, 0, ',', ' ') . ' MAD';
}

// Fonction pour obtenir le badge de statut
function getStatusBadge($status, $paymentStatus = null) {
    // Déterminer le statut réel basé sur le statut et le paiement
    if ($status === 'cancelled') {
        return '<span class="status-badge status-danger"><i class="fas fa-times-circle"></i> Annulée</span>';
    }
    
    if ($paymentStatus === 'paid') {
        return '<span class="status-badge status-success"><i class="fas fa-check-circle"></i> Confirmé & Payé</span>';
    }
    
    switch ($status) {
        case 'confirmed':
            if ($paymentStatus === 'pending' || $paymentStatus === null) {
                return '<span class="status-badge status-warning"><i class="fas fa-clock"></i> Confirmé - Paiement en attente</span>';
            }
            return '<span class="status-badge status-success"><i class="fas fa-check-circle"></i> Confirmé</span>';
            
        case 'pending':
            return '<span class="status-badge status-warning"><i class="fas fa-hourglass-half"></i> En attente</span>';
            
        case 'completed':
            return '<span class="status-badge status-info"><i class="fas fa-flag-checkered"></i> Terminé</span>';
            
        default:
            return '<span class="status-badge status-secondary"><i class="fas fa-question-circle"></i> ' . ucfirst($status) . '</span>';
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Profil - Atlas Hotels</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&family=Cormorant+Garamond:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
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

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--midnight);
            color: goldenrod;
            overflow-x: hidden;
            line-height: 1.6;
            cursor: none;
        }

        /* Luxury Cursor */
        .luxury-cursor {
            position: fixed;
            width: 20px;
            height: 20px;
            background: var(--gradient-luxury);
            border-radius: 50%;
            pointer-events: none;
            z-index: 9999;
            transition: transform 0.1s ease;
            mix-blend-mode: difference;
        }

        .luxury-cursor::after {
            content: '';
            position: absolute;
            width: 40px;
            height: 40px;
            border: 2px solid var(--primary-gold);
            border-radius: 50%;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.5;
            animation: cursorPulse 2s infinite;
        }

        @keyframes cursorPulse {
            0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.5; }
            50% { transform: translate(-50%, -50%) scale(1.2); opacity: 0.8; }
        }

        /* 3D Background Scene */
        #bg-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.3;
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
            cursor: pointer;
            transition: transform 0.3s ease;
            text-decoration: none;
        }

        .nav-logo:hover {
            transform: translateY(-2px);
        }

        .nav-logo i {
            font-size: 2rem;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: crownFloat 3s ease-in-out infinite;
        }

        @keyframes crownFloat {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-5px) rotate(5deg); }
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
            color: var(--platinum);
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
            text-decoration: none;
        }

        .btn-logout:hover {
            background: var(--primary-gold);
            color: var(--midnight);
            transform: rotate(360deg);
        }

        /* Main Content */
        .main-content {
            margin-top: 100px;
            padding: 4rem 0;
            min-height: calc(100vh - 100px);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .page-header {
            text-align: center;
            margin-bottom: 4rem;
            animation: fadeInUp 1s ease-out;
        }

        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(2.5rem, 5vw, 4rem);
            font-weight: 700;
            color: goldenrod;
            margin-bottom: 1rem;
        }

        .gradient-text {
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-subtitle {
            font-size: 1.2rem;
            color: var(--platinum);
            font-weight: 300;
        }

        /* Profile Grid */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 3rem;
            margin-bottom: 4rem;
        }

        /* Profile Card */
        .profile-card {
            background: rgba(212, 175, 55, 0.05);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
            height: fit-content;
        }

        .profile-card:hover {
            transform: translateY(-5px);
            background: rgba(212, 175, 55, 0.1);
            box-shadow: 0 20px 40px var(--shadow-dark);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--gradient-luxury);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            font-size: 3rem;
            color: var(--midnight);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile-avatar::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }

        .profile-avatar:hover::before {
            left: 100%;
        }

        .photo-upload-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 50%;
        }

        .profile-avatar:hover .photo-upload-overlay {
            opacity: 1;
        }

        .photo-upload-text {
            color: white;
            font-size: 0.9rem;
            text-align: center;
        }

        .profile-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: goldenrod;
            text-align: center;
            margin-bottom: 0.5rem;
        }

        .profile-email {
            color: var(--platinum);
            text-align: center;
            margin-bottom: 2rem;
        }

        .profile-info {
            space-y: 1rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
        }

        .info-label {
            color: var(--platinum);
            font-weight: 500;
        }

        .info-value {
            color: goldenrod;
            font-weight: 600;
        }

        /* Stats Section */
        .stats-section {
            background: rgba(212, 175, 55, 0.05);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            margin-bottom: 3rem;
        }

        .stats-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: goldenrod;
            margin-bottom: 2rem;
            text-align: center;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
        }

        .stat-item {
            text-align: center;
            padding: 1.5rem;
            background: rgba(212, 175, 55, 0.1);
            border-radius: 15px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-5px);
            background: rgba(212, 175, 55, 0.15);
        }

        .stat-icon {
            font-size: 2rem;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
        }

        .stat-number {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-gold);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--platinum);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }

        /* Bookings Section */
        .bookings-section {
            background: rgba(212, 175, 55, 0.05);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            margin-bottom: 3rem;
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 600;
            color: goldenrod;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .section-title i {
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .booking-card {
            background: rgba(212, 175, 55, 0.1);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .booking-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(212, 175, 55, 0.1), transparent);
            transition: left 0.5s ease;
        }

        .booking-card:hover::before {
            left: 100%;
        }

        .booking-card:hover {
            transform: translateY(-3px);
            background: rgba(212, 175, 55, 0.15);
            box-shadow: 0 10px 30px var(--shadow-dark);
        }

        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .hotel-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: goldenrod;
        }

        .booking-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            color: var(--platinum);
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            color: goldenrod;
            font-weight: 600;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-warning { background: rgba(255, 193, 7, 0.2); color: #ffc107; border: 1px solid #ffc107; }
        .status-success { background: rgba(40, 167, 69, 0.2); color: #28a745; border: 1px solid #28a745; }
        .status-info { background: rgba(23, 162, 184, 0.2); color: #17a2b8; border: 1px solid #17a2b8; }
        .status-secondary { background: rgba(108, 117, 125, 0.2); color: #6c757d; border: 1px solid #6c757d; }
        .status-danger { background: rgba(220, 53, 69, 0.2); color: #dc3545; border: 1px solid #dc3545; }

        /* Buttons */
        .btn-primary {
            background: var(--gradient-luxury);
            color: var(--midnight);
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
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }

        .btn-primary:hover::before {
            left: 100%;
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
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .btn-secondary::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            height: 100%;
            background: var(--gradient-luxury);
            transition: width 0.3s ease;
            z-index: -1;
        }

        .btn-secondary:hover::before {
            width: 100%;
        }

        .btn-secondary:hover {
            color: var(--midnight);
            transform: translateY(-3px);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            color: var(--platinum);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-input {
            width: 100%;
            padding: 1rem;
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 10px;
            color: goldenrod;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-gold);
            background: rgba(212, 175, 55, 0.15);
            box-shadow: 0 0 20px var(--shadow-gold);
        }

        .form-input::placeholder {
            color: var(--platinum);
            opacity: 0.7;
        }

        /* File Upload Styles */
        .file-upload {
            position: relative;
            display: inline-block;
            cursor: pointer;
            width: 100%;
        }

        .file-upload input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-upload-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 2rem;
            background: rgba(212, 175, 55, 0.1);
            border: 2px dashed rgba(212, 175, 55, 0.3);
            border-radius: 10px;
            color: goldenrod;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            flex-direction: column;
        }

        .file-upload-label:hover {
            background: rgba(212, 175, 55, 0.2);
            border-color: var(--primary-gold);
        }

        .file-upload-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.7;
        }

        .file-upload-text {
            font-weight: 500;
        }

        .file-upload-hint {
            font-size: 0.9rem;
            opacity: 0.7;
            margin-top: 0.5rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .profile-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .booking-details {
                grid-template-columns: 1fr;
            }

            .booking-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .nav-menu {
                display: none;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 1s ease-out;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
            backdrop-filter: blur(10px);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--charcoal);
            border-radius: 20px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            border: 1px solid rgba(212, 175, 55, 0.3);
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .modal-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 600;
            color: goldenrod;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--platinum);
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: var(--primary-gold);
        }

        /* Photo Actions */
        .photo-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            justify-content: center;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            border-radius: 25px;
        }

        /* Loading Spinner */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(212, 175, 55, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary-gold);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            border: 1px solid;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border-color: #28a745;
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border-color: #dc3545;
        }

        .booking-current {
            border: 2px solid #22c55e !important;
            background: rgba(34, 197, 94, 0.1) !important;
        }

        .booking-past {
            opacity: 0.8;
            border: 1px solid rgba(108, 117, 125, 0.3) !important;
        }

        .booking-future {
            border: 1px solid rgba(59, 130, 246, 0.3) !important;
            background: rgba(59, 130, 246, 0.05) !important;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .detail-label {
            color: var(--platinum);
            font-size: 0.9rem;
            font-weight: 500;
        }

        .detail-label i {
            color: var(--primary-gold);
            margin-right: 0.5rem;
            width: 16px;
            text-align: center;
        }

        .detail-value {
            color: goldenrod;
            font-weight: 600;
        }

        /* Filter and Controls */
        .booking-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .control-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .control-label {
            font-size: 0.9rem;
            color: var(--platinum);
            font-weight: 500;
        }

        .control-select {
            padding: 0.5rem 1rem;
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 8px;
            color: goldenrod;
            font-size: 0.9rem;
        }

        .control-select:focus {
            outline: none;
            border-color: var(--primary-gold);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
        }

        .pagination-btn {
            padding: 0.5rem 1rem;
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 8px;
            color: goldenrod;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .pagination-btn:hover:not(:disabled) {
            background: rgba(212, 175, 55, 0.2);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-info {
            color: var(--platinum);
            font-size: 0.9rem;
        }

        /* All Bookings Section */
        .all-bookings-section {
            background: rgba(212, 175, 55, 0.05);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(212, 175, 55, 0.2);
            margin-bottom: 3rem;
        }

        .bookings-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .bookings-count {
            color: var(--platinum);
            font-size: 0.9rem;
        }

        .load-more-btn {
            background: transparent;
            color: var(--primary-gold);
            padding: 0.75rem 1.5rem;
            border: 2px solid var(--primary-gold);
            border-radius: 25px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .load-more-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            height: 100%;
            background: var(--gradient-luxury);
            transition: width 0.3s ease;
            z-index: -1;
        }

        .load-more-btn:hover::before {
            width: 100%;
        }

        .load-more-btn:hover {
            color: var(--midnight);
            transform: translateY(-2px);
        }

        .load-more-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .load-more-btn:disabled:hover {
            transform: none;
        }

        .load-more-btn:disabled::before {
            width: 0;
        }
    </style>
</head>
<body>
    <!-- Luxury Cursor -->
    <div class="luxury-cursor"></div>

    <!-- 3D Background Canvas -->
    <canvas id="bg-canvas"></canvas>

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
                <a href="user.php" class="nav-link active">Profile</a>
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

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Mon <span class="gradient-text">Profil</span></h1>
                <p class="page-subtitle">Gérez vos informations personnelles et consultez vos réservations</p>
            </div>

            <!-- Profile Grid -->
            <div class="profile-grid">
                <!-- Profile Card -->
                <div class="profile-card fade-in-up">
                    <div class="profile-avatar" onclick="openPhotoModal()">
                        <?php if (!empty($user['profile_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($user['profile_photo']); ?>" alt="Photo de profil">
                        <?php else: ?>
                            <i class="fas fa-user"></i>
                        <?php endif; ?>
                        <div class="photo-upload-overlay">
                            <div class="photo-upload-text">
                                <i class="fas fa-camera"></i><br>
                                Changer la photo
                            </div>
                        </div>
                    </div>
                    <h2 class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                    <p class="profile-email"><?php echo htmlspecialchars($user['email']); ?></p>
                    
                    <div class="profile-info">
                        <div class="info-item">
                            <span class="info-label">Téléphone</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['phone'] ?? 'Non renseigné'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Adresse</span>
                            <span class="info-value"><?php echo htmlspecialchars($user['address'] ?? 'Non renseignée'); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Date de naissance</span>
                            <span class="info-value"><?php echo $user['date_of_birth'] ? date('d/m/Y', strtotime($user['date_of_birth'])) : 'Non renseignée'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Membre depuis</span>
                            <span class="info-value"><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></span>
                        </div>
                    </div>
                    
                    <button class="btn-primary" onclick="openEditModal()" style="width: 100%; margin-top: 2rem;">
                        <i class="fas fa-edit"></i> Modifier le profil
                    </button>

                    <button class="btn-secondary" onclick="openPhotoModal()" style="width: 100%; margin-top: 1rem;">
                        <i class="fas fa-camera"></i> 
                        <?php echo !empty($user['profile_photo']) ? 'Changer la photo' : 'Ajouter une photo'; ?>
                    </button>
                </div>

                <!-- Stats Section -->
                <div class="stats-section fade-in-up">
                    <h2 class="stats-title">Mes Statistiques</h2>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                            <div class="stat-number"><?php echo $stats['total_bookings'] ?? 0; ?></div>
                            <div class="stat-label">Réservations</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="stat-number"><?php echo $stats['confirmed_bookings'] ?? 0; ?></div>
                            <div class="stat-label">Confirmées</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon"><i class="fas fa-coins"></i></div>
                            <div class="stat-number"><?php echo formatPrice($stats['total_spent'] ?? 0); ?></div>
                            <div class="stat-label">Total dépensé</div>
                        </div>
                        
                    </div>
                </div>
            </div>

          
    <!-- Edit Profile Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Modifier mon profil</h3>
                <button class="modal-close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div id="editAlert"></div>
            
            <form id="editForm">
                <div class="form-group">
                    <label class="form-label">Nom complet</label>
                    <input type="text" class="form-input" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Téléphone</label>
                    <input type="tel" class="form-input" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Adresse</label>
                    <input type="text" class="form-input" name="address" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Date de naissance</label>
                    <input type="date" class="form-input" name="date_of_birth" value="<?php echo $user['date_of_birth']; ?>">
                </div>
                
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn-secondary" onclick="closeEditModal()">Annuler</button>
                    <button type="submit" class="btn-primary" id="editSubmitBtn">Sauvegarder</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Photo Upload Modal -->
    <div class="modal" id="photoModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Gérer ma photo de profil</h3>
                <button class="modal-close" onclick="closePhotoModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div id="photoAlert"></div>
            
            <form id="photoForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label">Choisir une nouvelle photo</label>
                    <div class="file-upload">
                        <input type="file" id="photoInput" name="profile_photo" accept="image/*" onchange="previewPhoto(this)">
                        <label for="photoInput" class="file-upload-label">
                            <div class="file-upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="file-upload-text">Cliquez pour sélectionner une photo</div>
                            <div class="file-upload-hint">Formats acceptés: JPG, PNG, GIF, WebP (max 5MB)</div>
                        </label>
                    </div>
                </div>
                
                <div id="photoPreview" style="display: none; text-align: center; margin: 1rem 0;">
                    <img id="previewImage" style="max-width: 200px; max-height: 200px; border-radius: 10px; border: 2px solid var(--primary-gold);">
                </div>
                
                <div class="photo-actions">
                    <button type="button" class="btn-secondary btn-small" onclick="uploadPhoto()">
                        <i class="fas fa-upload"></i> Envoyer
                    </button>
                    <?php if (!empty($user['profile_photo'])): ?>
                        <button type="button" class="btn-secondary btn-small" onclick="deletePhoto()" style="border-color: #dc3545; color: #dc3545;">
                            <i class="fas fa-trash"></i> Supprimer
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

   
    <script>
        // Global variables for pagination
        let currentOffset = <?php echo count($allBookings); ?>;
        let currentStatusFilter = 'all';
        let currentSortBy = 'created_at';
        let currentSortOrder = 'DESC';
        let isLoading = false;

        // Luxury Cursor
        const cursor = document.querySelector('.luxury-cursor');
        let mouseX = 0, mouseY = 0;
        let cursorX = 0, cursorY = 0;

        document.addEventListener('mousemove', (e) => {
            mouseX = e.clientX;
            mouseY = e.clientY;
        });

        function animateCursor() {
            cursorX += (mouseX - cursorX) * 0.1;
            cursorY += (mouseY - cursorY) * 0.1;
            cursor.style.left = cursorX + 'px';
            cursor.style.top = cursorY + 'px';
            requestAnimationFrame(animateCursor);
        }
        animateCursor();

        // 3D Background Animation
        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
        const renderer = new THREE.WebGLRenderer({ 
            canvas: document.getElementById('bg-canvas'),
            alpha: true 
        });

        renderer.setSize(window.innerWidth, window.innerHeight);
        renderer.setPixelRatio(window.devicePixelRatio);

        // Create luxury particles
        const particlesGeometry = new THREE.BufferGeometry();
        const particlesCount = 800;
        const posArray = new Float32Array(particlesCount * 3);

        for(let i = 0; i < particlesCount * 3; i++) {
            posArray[i] = (Math.random() - 0.5) * 100;
        }

        particlesGeometry.setAttribute('position', new THREE.BufferAttribute(posArray, 3));

        const particlesMaterial = new THREE.PointsMaterial({
            size: 0.005,
            color: 0xD4AF37,
            transparent: true,
            opacity: 0.8
        });

        const particlesMesh = new THREE.Points(particlesGeometry, particlesMaterial);
        scene.add(particlesMesh);

        camera.position.z = 20;

        function animate() {
            requestAnimationFrame(animate);
            particlesMesh.rotation.x += 0.0005;
            particlesMesh.rotation.y += 0.0005;
            renderer.render(scene, camera);
        }
        animate();

        // Handle window resize
        window.addEventListener('resize', () => {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
        });

        // Modal functions
        function openEditModal() {
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
            document.getElementById('editAlert').innerHTML = '';
        }

        function openPhotoModal() {
            document.getElementById('photoModal').classList.add('active');
        }

        function closePhotoModal() {
            document.getElementById('photoModal').classList.remove('active');
            document.getElementById('photoAlert').innerHTML = '';
            document.getElementById('photoPreview').style.display = 'none';
            document.getElementById('photoInput').value = '';
        }

        // Show alert function
        function showAlert(containerId, message, type) {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-error';
            document.getElementById(containerId).innerHTML = `
                <div class="alert ${alertClass}">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                    ${message}
                </div>
            `;
        }

        // Preview photo function
        function previewPhoto(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImage').src = e.target.result;
                    document.getElementById('photoPreview').style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Handle profile form submission
        document.getElementById('editForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const submitBtn = document.getElementById('editSubmitBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="loading"></span> Mise à jour...';
            submitBtn.disabled = true;
            
            const formData = new FormData(e.target);
            formData.append('action', 'update_profile');
            
            try {
                const response = await fetch('user.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('editAlert', result.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert('editAlert', result.message, 'error');
                }
            } catch (error) {
                showAlert('editAlert', 'Erreur lors de la mise à jour du profil', 'error');
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });

        // Handle photo upload
        async function uploadPhoto() {
            const fileInput = document.getElementById('photoInput');
            const file = fileInput.files[0];
            
            if (!file) {
                showAlert('photoAlert', 'Veuillez sélectionner une photo', 'error');
                return;
            }
            
            // Validate file
            if (file.size > 5 * 1024 * 1024) {
                showAlert('photoAlert', 'Le fichier est trop volumineux (max 5MB)', 'error');
                return;
            }
            
            if (!file.type.startsWith('image/')) {
                showAlert('photoAlert', 'Veuillez sélectionner une image valide', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('profile_photo', file);
            formData.append('action', 'upload_photo');
            
            try {
                showAlert('photoAlert', 'Upload en cours...', 'success');
                
                const response = await fetch('user.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('photoAlert', result.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert('photoAlert', result.message, 'error');
                }
            } catch (error) {
                showAlert('photoAlert', 'Erreur lors de l\'upload de la photo', 'error');
            }
        }

        // Handle photo deletion
        async function deletePhoto() {
            if (!confirm('Êtes-vous sûr de vouloir supprimer votre photo de profil ?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_photo');
            
            try {
                const response = await fetch('user.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('photoAlert', result.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert('photoAlert', result.message, 'error');
                }
            } catch (error) {
                showAlert('photoAlert', 'Erreur lors de la suppression de la photo', 'error');
            }
        }

        // Load more bookings function
        async function loadMoreBookings() {
            if (isLoading) return;
            
            isLoading = true;
            const loadMoreBtn = document.getElementById('loadMoreBtn');
            const originalText = loadMoreBtn.innerHTML;
            loadMoreBtn.innerHTML = '<span class="loading"></span> Chargement...';
            loadMoreBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'get_all_bookings');
            formData.append('limit', '10');
            formData.append('offset', currentOffset.toString());
            formData.append('status_filter', currentStatusFilter);
            formData.append('sort_by', currentSortBy);
            formData.append('sort_order', currentSortOrder);
            
            try {
                const response = await fetch('user.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success && result.data.bookings.length > 0) {
                    const container = document.getElementById('allBookingsContainer');
                    const loadMoreContainer = container.querySelector('.load-more-btn').parentElement;
                    
                    // Add new bookings before the load more button
                    result.data.bookings.forEach(booking => {
                        const bookingElement = createBookingElement(booking);
                        container.insertBefore(bookingElement, loadMoreContainer);
                    });
                    
                    currentOffset += result.data.bookings.length;
                    
                    // Hide load more button if no more bookings
                    if (currentOffset >= result.data.total) {
                        loadMoreContainer.style.display = 'none';
                    }
                } else {
                    // No more bookings
                    const loadMoreContainer = document.getElementById('loadMoreBtn').parentElement;
                    loadMoreContainer.style.display = 'none';
                }
            } catch (error) {
                console.error('Error loading more bookings:', error);
            } finally {
                loadMoreBtn.innerHTML = originalText;
                loadMoreBtn.disabled = false;
                isLoading = false;
            }
        }

        // Create booking element function
        function createBookingElement(booking) {
            const div = document.createElement('div');
            const checkIn = new Date(booking.check_in);
            const checkOut = new Date(booking.check_out);
            const now = new Date();
            
            let periodClass = '';
            if (checkOut < now) {
                periodClass = 'booking-past';
            } else if (checkIn <= now && checkOut >= now) {
                periodClass = 'booking-current';
            } else {
                periodClass = 'booking-future';
            }
            
            div.className = `booking-card ${periodClass}`;
            div.innerHTML = `
                <div class="booking-header">
                    <div>
                        <h3 class="hotel-name">${booking.hotel_name}</h3>
                        <p style="color: var(--platinum); font-size: 0.9rem; margin-top: 0.25rem;">
                            <i class="fas fa-map-marker-alt"></i> 
                            ${booking.city}
                            ${booking.hotel_address ? '- ' + booking.hotel_address : ''}
                        </p>
                    </div>
                    ${getStatusBadgeJS(booking.status, booking.payment_status)}
                </div>
                
                <div class="booking-details">
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-bed"></i> Type de chambre</span>
                        <span class="detail-value">${booking.room_type_name}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-calendar-check"></i> Arrivée</span>
                        <span class="detail-value">${checkIn.toLocaleDateString('fr-FR')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-calendar-times"></i> Départ</span>
                        <span class="detail-value">${checkOut.toLocaleDateString('fr-FR')}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-moon"></i> Durée</span>
                        <span class="detail-value">${booking.nights} nuit${booking.nights > 1 ? 's' : ''}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-users"></i> Invités</span>
                        <span class="detail-value">${booking.guests} personne${booking.guests > 1 ? 's' : ''}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-coins"></i> Prix total</span>
                        <span class="detail-value" style="font-weight: 700; color: var(--primary-gold);">
                            ${formatPriceJS(booking.total_price)}
                        </span>
                    </div>
                    ${booking.nights > 0 ? `
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-calculator"></i> Prix par nuit</span>
                        <span class="detail-value">${formatPriceJS(booking.calculated_price_per_night)}</span>
                    </div>
                    ` : ''}
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-clock"></i> Réservé le</span>
                        <span class="detail-value">${new Date(booking.created_at).toLocaleDateString('fr-FR')} à ${new Date(booking.created_at).toLocaleTimeString('fr-FR', {hour: '2-digit', minute: '2-digit'})}</span>
                    </div>
                    ${booking.special_requests ? `
                    <div class="detail-item" style="grid-column: 1 / -1;">
                        <span class="detail-label"><i class="fas fa-comment"></i> Demandes spéciales</span>
                        <span class="detail-value" style="font-style: italic;">
                            "${booking.special_requests}"
                        </span>
                    </div>
                    ` : ''}
                    ${booking.hotel_phone ? `
                    <div class="detail-item">
                        <span class="detail-label"><i class="fas fa-phone"></i> Contact hôtel</span>
                        <span class="detail-value">
                            <a href="tel:${booking.hotel_phone}" 
                               style="color: var(--primary-gold); text-decoration: none;">
                                ${booking.hotel_phone}
                            </a>
                        </span>
                    </div>
                    ` : ''}
                </div>
                
                ${booking.booking_period === 'current' ? `
                <div style="background: rgba(34, 197, 94, 0.1); border: 1px solid #22c55e; border-radius: 10px; padding: 1rem; margin-top: 1rem; text-align: center;">
                    <i class="fas fa-star" style="color: #22c55e; margin-right: 0.5rem;"></i>
                    <strong style="color: #22c55e;">Séjour en cours - Profitez bien !</strong>
                </div>
                ` : ''}
            `;
            
            return div;
        }

        // Helper functions for JavaScript
        function getStatusBadgeJS(status, paymentStatus) {
            if (status === 'cancelled') {
                return '<span class="status-badge status-danger"><i class="fas fa-times-circle"></i> Annulée</span>';
            }
            
            if (paymentStatus === 'paid') {
                return '<span class="status-badge status-success"><i class="fas fa-check-circle"></i> Confirmé & Payé</span>';
            }
            
            switch (status) {
                case 'confirmed':
                    if (paymentStatus === 'pending' || paymentStatus === null) {
                        return '<span class="status-badge status-warning"><i class="fas fa-clock"></i> Confirmé - Paiement en attente</span>';
                    }
                    return '<span class="status-badge status-success"><i class="fas fa-check-circle"></i> Confirmé</span>';
                    
                case 'pending':
                    return '<span class="status-badge status-warning"><i class="fas fa-hourglass-half"></i> En attente</span>';
                    
                case 'completed':
                    return '<span class="status-badge status-info"><i class="fas fa-flag-checkered"></i> Terminé</span>';
                    
                default:
                    return '<span class="status-badge status-secondary"><i class="fas fa-question-circle"></i> ' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
            }
        }

        function formatPriceJS(price) {
            if (price === null || price === '' || price === 0) {
                return '0 MAD';
            }
            
            price = parseFloat(price);
            return new Intl.NumberFormat('fr-FR').format(price) + ' MAD';
        }

        // Filter and sort event listeners
        document.getElementById('statusFilter').addEventListener('change', function() {
            currentStatusFilter = this.value;
            refreshBookings();
        });

        document.getElementById('sortBy').addEventListener('change', function() {
            currentSortBy = this.value;
            refreshBookings();
        });

        document.getElementById('sortOrder').addEventListener('change', function() {
            currentSortOrder = this.value;
            refreshBookings();
        });

        // Refresh bookings function
        async function refreshBookings() {
            const container = document.getElementById('allBookingsContainer');
            container.innerHTML = '<div style="text-align: center; padding: 2rem;"><span class="loading"></span> Chargement...</div>';
            
            currentOffset = 0;
            
            const formData = new FormData();
            formData.append('action', 'get_all_bookings');
            formData.append('limit', '20');
            formData.append('offset', '0');
            formData.append('status_filter', currentStatusFilter);
            formData.append('sort_by', currentSortBy);
            formData.append('sort_order', currentSortOrder);
            
            try {
                const response = await fetch('user.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    container.innerHTML = '';
                    
                    if (result.data.bookings.length === 0) {
                        container.innerHTML = `
                            <div style="text-align: center; padding: 3rem; color: var(--platinum);">
                                <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>Aucune réservation trouvée avec ces critères</p>
                            </div>
                        `;
                    } else {
                        result.data.bookings.forEach(booking => {
                            const bookingElement = createBookingElement(booking);
                            container.appendChild(bookingElement);
                        });
                        
                        currentOffset = result.data.bookings.length;
                        
                        // Add load more button if there are more bookings
                        if (currentOffset < result.data.total) {
                            const loadMoreContainer = document.createElement('div');
                            loadMoreContainer.style.textAlign = 'center';
                            loadMoreContainer.style.marginTop = '2rem';
                            loadMoreContainer.innerHTML = `
                                <button class="load-more-btn" id="loadMoreBtn" onclick="loadMoreBookings()">
                                    <i class="fas fa-plus"></i> Charger plus de réservations
                                </button>
                            `;
                            container.appendChild(loadMoreContainer);
                        }
                    }
                }
            } catch (error) {
                console.error('Error refreshing bookings:', error);
                container.innerHTML = `
                    <div style="text-align: center; padding: 3rem; color: var(--platinum);">
                        <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                        <p>Erreur lors du chargement des réservations</p>
                    </div>
                `;
            }
        }

        // Close modals when clicking outside
        document.getElementById('editModal').addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                closeEditModal();
            }
        });

        document.getElementById('photoModal').addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                closePhotoModal();
            }
        });

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animation = 'fadeInUp 1s ease-out forwards';
                }
            });
        }, observerOptions);

        // Observe elements for animation
        document.querySelectorAll('.profile-card, .stats-section, .bookings-section, .booking-card, .all-bookings-section').forEach(el => {
            observer.observe(el);
        });

        // Counter animation for stats
        const counters = document.querySelectorAll('.stat-number');
        const countObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const counter = entry.target;
                    const text = counter.textContent;
                    const target = parseInt(text.replace(/\D/g, ''));
                    
                    if (isNaN(target)) return;
                    
                    let current = 0;
                    const increment = target / 50;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= target) {
                            current = target;
                            clearInterval(timer);
                        }
                        if (text.includes('MAD')) {
                            counter.textContent = Math.floor(current).toLocaleString() + ' MAD';
                        } else {
                            counter.textContent = Math.floor(current);
                        }
                    }, 50);
                }
            });
        }, observerOptions);

        counters.forEach(counter => countObserver.observe(counter));
    </script>
</body>
</html>