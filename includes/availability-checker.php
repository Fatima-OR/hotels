<?php
class AvailabilityChecker {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function checkAvailability($hotelId, $roomTypeId, $checkIn, $checkOut, $requiredRooms = 1) {
        try {
            // Vérifier si les dates sont valides
            $checkInDate = new DateTime($checkIn);
            $checkOutDate = new DateTime($checkOut);
            $today = new DateTime();
            
            if ($checkInDate < $today) {
                return [
                    'available' => false,
                    'available_rooms' => 0,
                    'message' => "La date d'arrivée ne peut pas être dans le passé",
                    'error_type' => 'invalid_dates'
                ];
            }
            
            if ($checkOutDate <= $checkInDate) {
                return [
                    'available' => false,
                    'available_rooms' => 0,
                    'message' => "La date de départ doit être après la date d'arrivée",
                    'error_type' => 'invalid_dates'
                ];
            }
            
            // Requête optimisée pour vérifier la disponibilité
            $stmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT r.id) as available_rooms
                FROM rooms r
                WHERE r.room_type_id = ? 
                AND r.hotel_id = ? 
                AND r.status = 'available'
                AND r.id NOT IN (
                    SELECT DISTINCT ra.room_id 
                    FROM room_availability ra 
                    JOIN bookings b ON ra.booking_id = b.id
                    WHERE ra.date >= ? 
                    AND ra.date < ? 
                    AND ra.status = 'booked'
                    AND b.status IN ('confirmed', 'pending', 'checked_in')
                )
            ");
            
            $stmt->execute([$roomTypeId, $hotelId, $checkIn, $checkOut]);
            $result = $stmt->fetch();
            $availableRooms = $result['available_rooms'];
            
            return [
                'available' => $availableRooms >= $requiredRooms,
                'available_rooms' => $availableRooms,
                'message' => $availableRooms >= $requiredRooms 
                    ? "Chambres disponibles" 
                    : "Aucune chambre disponible pour ces dates",
                'error_type' => $availableRooms >= $requiredRooms ? null : 'no_availability'
            ];
            
        } catch (Exception $e) {
            error_log("Availability check error: " . $e->getMessage());
            return [
                'available' => false,
                'available_rooms' => 0,
                'message' => "Erreur lors de la vérification de disponibilité",
                'error_type' => 'system_error'
            ];
        }
    }
    
    public function getAlternativeDates($hotelId, $roomTypeId, $checkIn, $checkOut, $dayRange = 14) {
        $alternatives = [];
        $originalCheckIn = new DateTime($checkIn);
        $originalCheckOut = new DateTime($checkOut);
        $nights = $originalCheckIn->diff($originalCheckOut)->days;
        
        // Vérifier d'autres types de chambres pour les mêmes dates
        $alternativeRooms = $this->getAlternativeRoomTypes($hotelId, $checkIn, $checkOut);
        
        // Vérifier les dates alternatives (avant et après)
        for ($i = 1; $i <= $dayRange; $i++) {
            // Vérifier les dates antérieures
            $earlierCheckIn = clone $originalCheckIn;
            $earlierCheckIn->sub(new DateInterval("P{$i}D"));
            $earlierCheckOut = clone $earlierCheckIn;
            $earlierCheckOut->add(new DateInterval("P{$nights}D"));
            
            $availability = $this->checkAvailability(
                $hotelId, 
                $roomTypeId, 
                $earlierCheckIn->format('Y-m-d'), 
                $earlierCheckOut->format('Y-m-d')
            );
            
            if ($availability['available']) {
                $alternatives[] = [
                    'type' => 'date',
                    'check_in' => $earlierCheckIn->format('Y-m-d'),
                    'check_out' => $earlierCheckOut->format('Y-m-d'),
                    'available_rooms' => $availability['available_rooms'],
                    'days_difference' => $i,
                    'direction' => 'earlier'
                ];
            }
            
            // Vérifier les dates ultérieures
            $laterCheckIn = clone $originalCheckIn;
            $laterCheckIn->add(new DateInterval("P{$i}D"));
            $laterCheckOut = clone $laterCheckIn;
            $laterCheckOut->add(new DateInterval("P{$nights}D"));
            
            $availability = $this->checkAvailability(
                $hotelId, 
                $roomTypeId, 
                $laterCheckIn->format('Y-m-d'), 
                $laterCheckOut->format('Y-m-d')
            );
            
            if ($availability['available']) {
                $alternatives[] = [
                    'type' => 'date',
                    'check_in' => $laterCheckIn->format('Y-m-d'),
                    'check_out' => $laterCheckOut->format('Y-m-d'),
                    'available_rooms' => $availability['available_rooms'],
                    'days_difference' => $i,
                    'direction' => 'later'
                ];
            }
        }
        
        // Combiner les alternatives de chambres et de dates
        $allAlternatives = array_merge($alternativeRooms, $alternatives);
        
        // Trier par pertinence (d'abord les chambres alternatives, puis par proximité de date)
        usort($allAlternatives, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'room' ? -1 : 1;
            }
            
            if ($a['type'] === 'date') {
                return $a['days_difference'] - $b['days_difference'];
            }
            
            return 0;
        });
        
        return array_slice($allAlternatives, 0, 5); // Retourner max 5 alternatives
    }
    
    public function getAlternativeRoomTypes($hotelId, $checkIn, $checkOut) {
        $alternatives = [];
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    rt.id, 
                    rt.name, 
                    rt.price_per_night,
                    rt.max_occupancy,
                    COUNT(DISTINCT r.id) as total_rooms,
                    (
                        SELECT COUNT(DISTINCT r2.id)
                        FROM rooms r2
                        WHERE r2.room_type_id = rt.id 
                        AND r2.hotel_id = rt.hotel_id 
                        AND r2.status = 'available'
                        AND r2.id NOT IN (
                            SELECT DISTINCT ra.room_id 
                            FROM room_availability ra 
                            JOIN bookings b ON ra.booking_id = b.id
                            WHERE ra.date >= ? 
                            AND ra.date < ? 
                            AND ra.status = 'booked'
                            AND b.status IN ('confirmed', 'pending', 'checked_in')
                        )
                    ) as available_rooms
                FROM room_types rt
                JOIN rooms r ON rt.id = r.room_type_id AND r.hotel_id = rt.hotel_id
                WHERE rt.hotel_id = ?
                GROUP BY rt.id, rt.name, rt.price_per_night, rt.max_occupancy
                HAVING available_rooms > 0
                ORDER BY rt.price_per_night ASC
            ");
            
            $stmt->execute([$checkIn, $checkOut, $hotelId]);
            
            while ($room = $stmt->fetch()) {
                $alternatives[] = [
                    'type' => 'room',
                    'room_type_id' => $room['id'],
                    'room_name' => $room['name'],
                    'price' => $room['price_per_night'],
                    'max_occupancy' => $room['max_occupancy'],
                    'available_rooms' => $room['available_rooms']
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error getting alternative room types: " . $e->getMessage());
        }
        
        return $alternatives;
    }
    
    public function getAlternativeHotels($cityName, $checkIn, $checkOut, $priceRange = 0.2) {
        $alternatives = [];
        
        try {
            // Récupérer l'hôtel Royal Marrakech pour référence
            $stmt = $this->pdo->prepare("
                SELECT id, price_per_night 
                FROM hotels 
                WHERE name LIKE '%Royal Marrakech%' 
                LIMIT 1
            ");
            $stmt->execute();
            $royalHotel = $stmt->fetch();
            
            if (!$royalHotel) {
                return $alternatives;
            }
            
            $basePrice = $royalHotel['price_per_night'];
            $minPrice = $basePrice * (1 - $priceRange);
            $maxPrice = $basePrice * (1 + $priceRange);
            
            // Trouver des hôtels similaires dans la même ville
            $stmt = $this->pdo->prepare("
                SELECT 
                    h.id, 
                    h.name, 
                    h.city, 
                    h.rating, 
                    h.price_per_night,
                    h.image_url,
                    EXISTS (
                        SELECT 1 FROM room_types rt 
                        JOIN rooms r ON rt.id = r.room_type_id
                        WHERE rt.hotel_id = h.id
                        AND r.id NOT IN (
                            SELECT DISTINCT ra.room_id 
                            FROM room_availability ra 
                            JOIN bookings b ON ra.booking_id = b.id
                            WHERE ra.date >= ? 
                            AND ra.date < ? 
                            AND ra.status = 'booked'
                            AND b.status IN ('confirmed', 'pending', 'checked_in')
                        )
                        LIMIT 1
                    ) as has_availability
                FROM hotels h
                WHERE h.city = ? 
                AND h.id != ?
                AND h.status = 'active'
                AND h.price_per_night BETWEEN ? AND ?
                HAVING has_availability = 1
                ORDER BY h.rating DESC, h.price_per_night ASC
                LIMIT 3
            ");
            
            $stmt->execute([
                $checkIn, 
                $checkOut, 
                'Marrakech', // Ville de Royal Marrakech
                $royalHotel['id'],
                $minPrice,
                $maxPrice
            ]);
            
            while ($hotel = $stmt->fetch()) {
                $alternatives[] = [
                    'type' => 'hotel',
                    'hotel_id' => $hotel['id'],
                    'hotel_name' => $hotel['name'],
                    'city' => $hotel['city'],
                    'rating' => $hotel['rating'],
                    'price' => $hotel['price_per_night'],
                    'image_url' => $hotel['image_url']
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error getting alternative hotels: " . $e->getMessage());
        }
        
        return $alternatives;
    }
}
?>
