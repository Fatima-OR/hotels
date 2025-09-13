<?php
// classes/UserManager.php
class UserManager {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getCurrentUser() {
        if (!isset($_SESSION['user_id'])) return null;
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getUserStats($userId) {
        // Nombre total de réservations
        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS total_bookings FROM reservations WHERE user_id = ?");
        $stmt->execute([$userId]);
        $totalBookings = $stmt->fetchColumn();

        // Nombre de réservations confirmées
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = ? AND status = 'confirmed'");
        $stmt->execute([$userId]);
        $confirmedBookings = $stmt->fetchColumn();

        // Total dépensé
        $stmt = $this->pdo->prepare("SELECT SUM(total_price) FROM reservations WHERE user_id = ? AND status = 'confirmed'");
        $stmt->execute([$userId]);
        $totalSpent = $stmt->fetchColumn() ?? 0;

        // Ville préférée (celle avec le plus de réservations)
        $stmt = $this->pdo->prepare("
            SELECT hotels.city, COUNT(*) AS count 
            FROM reservations 
            JOIN rooms ON reservations.room_id = rooms.id
            JOIN hotels ON rooms.hotel_id = hotels.id
            WHERE reservations.user_id = ?
            GROUP BY hotels.city
            ORDER BY count DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $favCity = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_bookings' => (int)$totalBookings,
            'confirmed_bookings' => (int)$confirmedBookings,
            'total_spent' => (float)$totalSpent,
            'favorite_city' => $favCity['city'] ?? 'Non renseignée'
        ];
    }
}

?>
