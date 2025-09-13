<?php class Reservation {
    private $conn;
    private $table_name = "reservations";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function createReservation($data) {
        // Validation des données
        $required_fields = ['hotel_id', 'guest_name', 'guest_email', 'check_in', 'check_out', 'total_price'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                throw new Exception("Le champ $field est requis");
            }
        }

        // Vérifier la disponibilité
        if (!$this->checkAvailability($data['hotel_id'], $data['check_in'], $data['check_out'])) {
            throw new Exception("L'hôtel n'est pas disponible pour ces dates");
        }

        $query = "INSERT INTO " . $this->table_name . " 
                  (hotel_id, guest_name, guest_email, guest_phone, check_in, check_out, 
                   number_of_guests, total_price, status, created_at) 
                  VALUES 
                  (:hotel_id, :guest_name, :guest_email, :guest_phone, :check_in, :check_out, 
                   :number_of_guests, :total_price, 'pending', NOW())";

        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':hotel_id', $data['hotel_id']);
        $stmt->bindParam(':guest_name', Security::validateInput($data['guest_name']));
        $stmt->bindParam(':guest_email', filter_var($data['guest_email'], FILTER_VALIDATE_EMAIL));
        $stmt->bindParam(':guest_phone', Security::validateInput($data['guest_phone'] ?? ''));
        $stmt->bindParam(':check_in', $data['check_in']);
        $stmt->bindParam(':check_out', $data['check_out']);
        $stmt->bindParam(':number_of_guests', (int)($data['number_of_guests'] ?? 2));
        $stmt->bindParam(':total_price', $data['total_price']);

        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        throw new Exception("Erreur lors de la création de la réservation");
    }

    private function checkAvailability($hotel_id, $check_in, $check_out) {
        // Vérifier s'il y a des réservations conflictuelles
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE hotel_id = :hotel_id 
                  AND status NOT IN ('cancelled', 'rejected')
                  AND ((check_in <= :check_in AND check_out > :check_in) 
                       OR (check_in < :check_out AND check_out >= :check_out)
                       OR (check_in >= :check_in AND check_out <= :check_out))";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':hotel_id', $hotel_id);
        $stmt->bindParam(':check_in', $check_in);
        $stmt->bindParam(':check_out', $check_out);
        $stmt->execute();
        
        $result = $stmt->fetch();
        return $result['count'] == 0;
    }
}