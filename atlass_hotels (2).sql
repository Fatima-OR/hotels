-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : ven. 04 juil. 2025 à 11:57
-- Version du serveur : 10.4.32-MariaDB
-- Version de PHP : 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `atlass_hotels`
--

DELIMITER $$
--
-- Procédures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `SearchAvailableRooms` (IN `p_city` VARCHAR(100), IN `p_check_in` DATE, IN `p_check_out` DATE, IN `p_guests` INT)   BEGIN
    -- Retourner TOUTES les chambres des hôtels actifs dans la ville demandée
    SELECT 
        h.id as hotel_id,
        h.name as hotel_name,
        h.description,
        h.location,
        h.city,
        h.rating,
        h.image_url as hotel_image,
        rt.id as room_type_id,
        rt.name as room_type_name,
        rt.description as room_description,
        rt.price_per_night,
        rt.max_occupancy,
        rt.size_sqm,
        rt.amenities,
        rt.image_url as room_image,
        COUNT(r.id) as available_rooms,
        'AVAILABLE' as availability_status,
        DATEDIFF(p_check_out, p_check_in) * rt.price_per_night as total_price
    FROM hotels h
    JOIN room_types rt ON h.id = rt.hotel_id
    LEFT JOIN rooms r ON rt.id = r.room_type_id AND r.status = 'available'
    WHERE h.status = 'active'
    AND (p_city IS NULL OR h.city LIKE CONCAT('%', p_city, '%'))
    AND rt.max_occupancy >= COALESCE(p_guests, 1)
    GROUP BY h.id, rt.id
    HAVING available_rooms > 0 OR available_rooms IS NULL
    ORDER BY h.rating DESC, rt.price_per_night ASC;
END$$

--
-- Fonctions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `check_room_availability` (`p_hotel_id` INT, `p_room_type_id` INT, `p_check_in` DATE, `p_check_out` DATE) RETURNS INT(11) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE available_count INT DEFAULT 0;
    
    -- Retourner TOUJOURS toutes les chambres du type demandé
    SELECT COUNT(*) INTO available_count
    FROM rooms r
    WHERE r.room_type_id = p_room_type_id 
    AND r.hotel_id = p_hotel_id;
    
    -- Si aucune chambre trouvée, retourner au moins 1 pour forcer la disponibilité
    IF available_count = 0 THEN
        SET available_count = 1;
    END IF;
    
    RETURN available_count;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `check_room_availability_enhanced` (`p_hotel_id` INT, `p_room_type_id` INT, `p_check_in` DATE, `p_check_out` DATE) RETURNS INT(11) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE available_count INT DEFAULT 0;
    DECLARE total_rooms INT DEFAULT 0;
    DECLARE booked_rooms INT DEFAULT 0;
    
    -- Compter le total de chambres de ce type dans cet hôtel
    SELECT COUNT(*) INTO total_rooms
    FROM rooms r
    WHERE r.room_type_id = p_room_type_id 
    AND r.hotel_id = p_hotel_id
    AND r.status = 'available';
    
    -- Compter les chambres déjà réservées pour cette période
    SELECT COUNT(DISTINCT ra.room_id) INTO booked_rooms
    FROM room_availability ra
    JOIN rooms r ON ra.room_id = r.id
    WHERE r.room_type_id = p_room_type_id 
    AND r.hotel_id = p_hotel_id
    AND ra.date >= p_check_in 
    AND ra.date < p_check_out
    AND ra.status = 'booked';
    
    -- Calculer les chambres disponibles
    SET available_count = total_rooms - booked_rooms;
    
    -- S'assurer qu'il y a toujours au moins une possibilité (création de chambre virtuelle)
    IF available_count <= 0 AND total_rooms > 0 THEN
        SET available_count = 1;
    END IF;
    
    -- Si aucune chambre physique n'existe, permettre la création virtuelle
    IF total_rooms = 0 THEN
        SET available_count = 1;
    END IF;
    
    RETURN available_count;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 5, 'booking_confirmed', 'bookings', 11, NULL, '{\"booking_id\": 11, \"hotel_id\": 1, \"room_type_id\": 8}', NULL, NULL, '2025-06-10 18:57:50'),
(2, 5, 'booking_status_changed_to_confirmed', 'bookings', 11, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 1, \"room_type_id\": 8, \"check_in\": \"2025-06-10\", \"check_out\": \"2025-06-11\"}', NULL, NULL, '2025-06-10 18:57:50'),
(3, 5, 'booking_confirmed', 'bookings', 12, NULL, '{\"booking_id\": 12, \"hotel_id\": 1, \"room_type_id\": 71}', NULL, NULL, '2025-06-10 20:28:46'),
(4, 5, 'booking_status_changed_to_confirmed', 'bookings', 12, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 1, \"room_type_id\": 71, \"check_in\": \"2025-06-10\", \"check_out\": \"2025-06-11\"}', NULL, NULL, '2025-06-10 20:28:46'),
(5, 5, 'booking_cancelled', 'bookings', 12, NULL, '{\"booking_id\": 12, \"cancellation_reason\": null}', NULL, NULL, '2025-06-10 20:29:08'),
(6, 5, 'booking_status_changed_to_cancelled', 'bookings', 12, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"cancelled\", \"hotel_id\": 1, \"room_type_id\": 71, \"check_in\": \"2025-06-10\", \"check_out\": \"2025-06-11\"}', NULL, NULL, '2025-06-10 20:29:08'),
(7, 5, 'booking_confirmed', 'bookings', 13, NULL, '{\"booking_id\": 13, \"hotel_id\": 2, \"room_type_id\": 4}', NULL, NULL, '2025-06-11 10:57:48'),
(8, 5, 'booking_status_changed_to_confirmed', 'bookings', 13, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 2, \"room_type_id\": 4, \"check_in\": \"2025-06-11\", \"check_out\": \"2025-06-12\"}', NULL, NULL, '2025-06-11 10:57:48'),
(9, 5, 'booking_confirmed', 'bookings', 14, NULL, '{\"booking_id\": 14, \"hotel_id\": 3, \"room_type_id\": 75}', NULL, NULL, '2025-06-11 11:10:48'),
(10, 5, 'booking_status_changed_to_confirmed', 'bookings', 14, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 3, \"room_type_id\": 75, \"check_in\": \"2025-06-11\", \"check_out\": \"2025-06-19\"}', NULL, NULL, '2025-06-11 11:10:48'),
(11, 5, 'booking_cancelled', 'bookings', 11, NULL, '{\"booking_id\": 11, \"cancellation_reason\": null}', NULL, NULL, '2025-06-11 11:37:53'),
(12, 5, 'booking_status_changed_to_cancelled', 'bookings', 11, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"cancelled\", \"hotel_id\": 1, \"room_type_id\": 8, \"check_in\": \"2025-06-10\", \"check_out\": \"2025-06-11\"}', NULL, NULL, '2025-06-11 11:37:53'),
(13, 5, 'booking_confirmed', 'bookings', 15, NULL, '{\"booking_id\": 15, \"hotel_id\": 1, \"room_type_id\": 3}', NULL, NULL, '2025-06-11 17:09:40'),
(14, 5, 'booking_status_changed_to_confirmed', 'bookings', 15, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 1, \"room_type_id\": 3, \"check_in\": \"2025-06-11\", \"check_out\": \"2025-06-12\"}', NULL, NULL, '2025-06-11 17:09:40'),
(15, 5, 'booking_confirmed', 'bookings', 16, NULL, '{\"booking_id\": 16, \"hotel_id\": 2, \"room_type_id\": 11}', NULL, NULL, '2025-06-11 17:18:34'),
(16, 5, 'booking_status_changed_to_confirmed', 'bookings', 16, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 2, \"room_type_id\": 11, \"check_in\": \"2025-06-11\", \"check_out\": \"2025-06-12\"}', NULL, NULL, '2025-06-11 17:18:34'),
(17, 5, 'booking_status_changed_to_checked_out', 'bookings', 10, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"checked_out\", \"hotel_id\": 21, \"room_type_id\": 67, \"room_id\": null, \"check_in\": \"2025-06-10\", \"check_out\": \"2025-06-19\"}', NULL, NULL, '2025-06-21 15:38:31'),
(18, 5, 'booking_status_changed_to_checked_out', 'bookings', 13, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"checked_out\", \"hotel_id\": 2, \"room_type_id\": 4, \"room_id\": null, \"check_in\": \"2025-06-11\", \"check_out\": \"2025-06-12\"}', NULL, NULL, '2025-06-21 15:38:31'),
(19, 5, 'booking_status_changed_to_checked_out', 'bookings', 14, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"checked_out\", \"hotel_id\": 3, \"room_type_id\": 75, \"room_id\": null, \"check_in\": \"2025-06-11\", \"check_out\": \"2025-06-19\"}', NULL, NULL, '2025-06-21 15:38:31'),
(20, 5, 'booking_status_changed_to_checked_out', 'bookings', 15, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"checked_out\", \"hotel_id\": 1, \"room_type_id\": 3, \"room_id\": null, \"check_in\": \"2025-06-11\", \"check_out\": \"2025-06-12\"}', NULL, NULL, '2025-06-21 15:38:31'),
(21, 5, 'booking_status_changed_to_checked_out', 'bookings', 16, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"checked_out\", \"hotel_id\": 2, \"room_type_id\": 11, \"room_id\": null, \"check_in\": \"2025-06-11\", \"check_out\": \"2025-06-12\"}', NULL, NULL, '2025-06-21 15:38:31'),
(22, 5, 'booking_status_changed_to_confirmed', 'bookings', 19, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 2, \"room_type_id\": 11, \"room_id\": null, \"check_in\": \"2025-06-26\", \"check_out\": \"2025-06-27\"}', NULL, NULL, '2025-06-26 13:43:03'),
(23, 5, 'booking_cancelled', 'bookings', 10, NULL, '{\"booking_id\": 10, \"cancellation_reason\": null}', NULL, NULL, '2025-06-26 14:46:46'),
(24, 5, 'booking_status_changed_to_cancelled', 'bookings', 10, NULL, '{\"old_status\": \"checked_out\", \"new_status\": \"cancelled\", \"hotel_id\": 21, \"room_type_id\": 67, \"room_id\": null, \"check_in\": \"2025-06-10\", \"check_out\": \"2025-06-19\"}', NULL, NULL, '2025-06-26 14:46:46'),
(25, 5, 'booking_cancelled', 'bookings', 13, NULL, '{\"booking_id\": 13, \"cancellation_reason\": null}', NULL, NULL, '2025-06-26 22:09:44'),
(26, 5, 'booking_status_changed_to_cancelled', 'bookings', 13, NULL, '{\"old_status\": \"checked_out\", \"new_status\": \"cancelled\", \"hotel_id\": 2, \"room_type_id\": 4, \"room_id\": null, \"check_in\": \"2025-06-11\", \"check_out\": \"2025-06-12\"}', NULL, NULL, '2025-06-26 22:09:44'),
(27, 7, 'booking_cancelled', 'bookings', 25, NULL, '{\"booking_id\": 25, \"cancellation_reason\": null}', NULL, NULL, '2025-07-01 19:59:44'),
(28, 7, 'booking_status_changed_to_cancelled', 'bookings', 25, NULL, '{\"old_status\": \"pending\", \"new_status\": \"cancelled\", \"hotel_id\": 14, \"room_type_id\": 45, \"room_id\": null, \"check_in\": \"2025-07-01\", \"check_out\": \"2025-07-02\"}', NULL, NULL, '2025-07-01 19:59:44'),
(29, 8, 'booking_status_changed_to_confirmed', 'bookings', 26, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-02 14:01:48'),
(30, 8, 'booking_status_changed_to_confirmed', 'bookings', 27, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-02 14:02:31'),
(31, 8, 'booking_status_changed_to_confirmed', 'bookings', 29, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-02 14:04:09'),
(32, 8, 'booking_created', 'bookings', 30, NULL, '{\"booking_id\":\"30\",\"hotel_id\":1,\"status\":\"pending\",\"total_price\":1500}', NULL, NULL, '2025-07-02 14:09:18'),
(33, 8, 'booking_created', 'bookings', 31, NULL, '{\"booking_id\":\"31\",\"hotel_id\":1,\"status\":\"pending\",\"total_price\":1500}', NULL, NULL, '2025-07-02 15:21:47'),
(34, 8, 'booking_created', 'bookings', 35, NULL, '{\"booking_id\":\"35\",\"hotel_id\":1,\"status\":\"confirmed\",\"payment_status\":\"paid\",\"total_price\":4500}', NULL, NULL, '2025-07-02 16:08:46'),
(35, 8, 'booking_created', 'bookings', 36, NULL, '{\"booking_id\":\"36\",\"hotel_id\":1,\"status\":\"pending\",\"payment_status\":\"pending\",\"total_price\":4500}', NULL, NULL, '2025-07-02 16:15:42'),
(36, 8, 'booking_status_changed_to_confirmed', 'bookings', 36, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-02 16:15:43'),
(37, 8, 'booking_created', 'bookings', 37, NULL, '{\"booking_id\":\"37\",\"hotel_id\":1,\"status\":\"pending\",\"payment_status\":\"pending\",\"total_price\":4500}', NULL, NULL, '2025-07-02 16:22:24'),
(38, 8, 'booking_status_changed_to_confirmed', 'bookings', 37, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-02 16:22:25'),
(39, 8, 'booking_created', 'bookings', 38, NULL, '{\"booking_id\":\"38\",\"hotel_id\":1,\"status\":\"pending\",\"payment_status\":\"pending\",\"total_price\":4500}', NULL, NULL, '2025-07-02 16:22:53'),
(40, 8, 'booking_status_changed_to_confirmed', 'bookings', 38, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-02 16:22:53'),
(41, 8, 'booking_created', 'bookings', 40, NULL, '{\"booking_id\":\"40\",\"hotel_id\":1,\"status\":\"pending\",\"payment_status\":\"pending\",\"total_price\":4500}', NULL, NULL, '2025-07-02 16:29:53'),
(42, 8, 'booking_status_changed_to_confirmed', 'bookings', 40, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-02 16:29:53'),
(43, 8, 'booking_cancelled', 'bookings', 40, NULL, '{\"booking_id\": 40, \"cancellation_reason\": \"bbbb\"}', NULL, NULL, '2025-07-02 16:31:13'),
(44, 8, 'booking_status_changed_to_cancelled', 'bookings', 40, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"cancelled\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-02 16:31:13'),
(45, 7, 'booking_status_changed_to_confirmed', 'bookings', 43, NULL, '{\"old_status\": \"pending\", \"new_status\": \"confirmed\", \"hotel_id\": 7, \"room_type_id\": 25, \"room_id\": null, \"check_in\": \"2025-07-02\", \"check_out\": \"2025-07-03\"}', NULL, NULL, '2025-07-02 16:50:46'),
(46, 8, 'booking_cancelled', 'bookings', 38, NULL, '{\"booking_id\": 38, \"cancellation_reason\": \"nnnn\\r\\n\"}', NULL, NULL, '2025-07-03 06:28:19'),
(47, 8, 'booking_status_changed_to_cancelled', 'bookings', 38, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"cancelled\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-03 06:28:19'),
(48, 8, 'booking_cancelled', 'bookings', 37, NULL, '{\"booking_id\": 37, \"cancellation_reason\": \"bbb\"}', NULL, NULL, '2025-07-03 06:28:35'),
(49, 8, 'booking_status_changed_to_cancelled', 'bookings', 37, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"cancelled\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-03 06:28:35'),
(50, 8, 'booking_cancelled', 'bookings', 36, NULL, '{\"booking_id\": 36, \"cancellation_reason\": \"bbbbbb\"}', NULL, NULL, '2025-07-03 06:28:49'),
(51, 8, 'booking_status_changed_to_cancelled', 'bookings', 36, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"cancelled\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-03 06:28:49'),
(52, 8, 'booking_cancelled', 'bookings', 35, NULL, '{\"booking_id\": 35, \"cancellation_reason\": \"vvvvv\"}', NULL, NULL, '2025-07-03 06:29:02'),
(53, 8, 'booking_status_changed_to_cancelled', 'bookings', 35, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"cancelled\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-03 06:29:02'),
(54, 8, 'booking_cancelled', 'bookings', 29, NULL, '{\"booking_id\": 29, \"cancellation_reason\": \"bbbbbb\"}', NULL, NULL, '2025-07-03 06:29:16'),
(55, 8, 'booking_status_changed_to_cancelled', 'bookings', 29, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"cancelled\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-03 06:29:16'),
(56, 8, 'booking_cancelled', 'bookings', 27, NULL, '{\"booking_id\": 27, \"cancellation_reason\": \"ccc\"}', NULL, NULL, '2025-07-03 06:29:29'),
(57, 8, 'booking_status_changed_to_cancelled', 'bookings', 27, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"cancelled\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-03 06:29:29'),
(58, 8, 'booking_cancelled', 'bookings', 26, NULL, '{\"booking_id\": 26, \"cancellation_reason\": \"nnnnn\"}', NULL, NULL, '2025-07-03 06:29:45'),
(59, 8, 'booking_status_changed_to_cancelled', 'bookings', 26, NULL, '{\"old_status\": \"confirmed\", \"new_status\": \"cancelled\", \"hotel_id\": 1, \"room_type_id\": 1, \"room_id\": null, \"check_in\": \"2025-07-03\", \"check_out\": \"2025-07-06\"}', NULL, NULL, '2025-07-03 06:29:45');

-- --------------------------------------------------------

--
-- Structure de la table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `room_type_id` int(11) NOT NULL,
  `check_in` date NOT NULL,
  `check_out` date NOT NULL,
  `guests` int(11) NOT NULL DEFAULT 1,
  `total_price` decimal(10,2) NOT NULL,
  `status` enum('pending','confirmed','checked_in','checked_out','cancelled') DEFAULT 'pending',
  `payment_status` enum('pending','paid','refunded','failed') DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  `confirmation_code` varchar(50) DEFAULT NULL,
  `email_sent` tinyint(1) DEFAULT 0,
  `special_requests` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `payment_gateway_id` varchar(100) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `last_modified_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `bookings`
--

INSERT INTO `bookings` (`id`, `user_id`, `hotel_id`, `room_type_id`, `check_in`, `check_out`, `guests`, `total_price`, `status`, `payment_status`, `payment_method`, `payment_reference`, `confirmation_code`, `email_sent`, `special_requests`, `cancellation_reason`, `cancelled_at`, `created_at`, `updated_at`, `payment_gateway_id`, `room_id`, `last_modified_at`) VALUES
(10, 5, 21, 67, '2025-06-10', '2025-06-19', 2, 4320.00, 'cancelled', 'pending', NULL, NULL, NULL, 0, 'bbbb', NULL, '2025-06-26 14:46:46', '2025-06-09 17:42:29', '2025-06-26 14:46:46', NULL, NULL, '2025-06-26 14:46:46'),
(11, 5, 1, 8, '2025-06-10', '2025-06-11', 2, 650.00, 'cancelled', 'paid', 'paypal', 'PAY_1749581870_4936', NULL, 0, '', NULL, '2025-06-11 11:37:53', '2025-06-10 18:18:04', '2025-06-11 11:37:53', NULL, NULL, '2025-06-26 14:03:56'),
(12, 5, 1, 71, '2025-06-10', '2025-06-11', 2, 3500.00, 'cancelled', 'paid', 'paypal', 'PAY_1749587326_9379', NULL, 0, '', NULL, '2025-06-10 20:29:08', '2025-06-10 20:28:32', '2025-06-10 20:29:08', NULL, NULL, '2025-06-26 14:03:56'),
(13, 5, 2, 4, '2025-06-11', '2025-06-12', 2, 2500.00, 'cancelled', 'paid', 'paypal', 'PAY_1749639468_9923', NULL, 0, '', NULL, '2025-06-26 22:09:44', '2025-06-11 10:57:42', '2025-06-26 22:09:44', NULL, NULL, '2025-06-26 22:09:44'),
(14, 5, 3, 75, '2025-06-11', '2025-06-19', 2, 14400.00, 'checked_out', 'paid', 'paypal', 'PAY_1749640248_5519', NULL, 0, '', NULL, NULL, '2025-06-11 11:09:38', '2025-06-21 15:38:31', NULL, NULL, '2025-06-26 14:03:56'),
(15, 5, 1, 3, '2025-06-11', '2025-06-12', 2, 650.00, 'checked_out', 'paid', 'paypal', 'PAY-6849b8543a3ee', NULL, 0, '', NULL, NULL, '2025-06-11 17:01:35', '2025-06-21 15:38:31', 'TRX-6849b8543a3f6', NULL, '2025-06-26 14:03:56'),
(16, 5, 2, 11, '2025-06-11', '2025-06-12', 2, 850.00, 'checked_out', 'paid', 'paypal', 'REF825405', NULL, 0, '', NULL, NULL, '2025-06-11 17:18:29', '2025-06-21 15:38:31', 'GTW815551', NULL, '2025-06-26 14:03:56'),
(17, 5, 14, 45, '2025-06-11', '2025-06-12', 2, 396.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-06-11 17:21:28', '2025-06-11 17:21:28', NULL, NULL, '2025-06-26 14:03:56'),
(18, 5, 1, 7, '2025-06-26', '2025-06-27', 2, 780.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-06-26 13:38:37', '2025-06-26 13:38:37', NULL, NULL, '2025-06-26 14:03:56'),
(19, 5, 2, 11, '2025-06-26', '2025-06-27', 2, 850.00, 'confirmed', 'paid', 'paypal', 'PAY_1750945383_4898', NULL, 0, '', NULL, NULL, '2025-06-26 13:42:56', '2025-06-26 13:43:03', NULL, NULL, '2025-06-26 14:03:56'),
(20, 5, 14, 45, '2025-06-26', '2025-06-27', 2, 396.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-06-26 14:55:09', '2025-06-26 14:55:09', NULL, NULL, '2025-06-26 14:55:09'),
(21, 5, 2, 5, '2025-06-27', '2025-06-28', 2, 1250.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-06-26 22:10:25', '2025-06-26 22:10:25', NULL, NULL, '2025-06-26 22:10:25'),
(22, 5, 1, 1, '2025-06-27', '2025-06-28', 2, 1200.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-06-27 11:30:25', '2025-06-27 11:30:25', NULL, NULL, '2025-06-27 11:30:25'),
(23, 5, 14, 45, '2025-06-30', '2025-07-01', 2, 396.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-06-30 09:45:25', '2025-06-30 09:45:25', NULL, NULL, '2025-06-30 09:45:25'),
(24, 7, 1, 3, '2025-07-01', '2025-07-02', 1, 650.00, 'pending', 'pending', NULL, NULL, NULL, 0, 'bbbb', NULL, NULL, '2025-07-01 19:44:49', '2025-07-01 19:49:47', NULL, NULL, '2025-07-01 19:49:47'),
(25, 7, 14, 45, '2025-07-01', '2025-07-02', 2, 396.00, 'cancelled', 'pending', NULL, NULL, NULL, 0, '', NULL, '2025-07-01 19:59:44', '2025-07-01 19:56:57', '2025-07-01 19:59:44', NULL, NULL, '2025-07-01 19:59:44'),
(26, 8, 1, 1, '2025-07-03', '2025-07-06', 2, 4500.00, 'cancelled', 'paid', 'paypal', NULL, 'ATL17514649088290', 0, NULL, 'nnnnn', '2025-07-03 06:29:45', '2025-07-02 14:01:48', '2025-07-03 06:29:45', NULL, NULL, '2025-07-03 06:29:45'),
(27, 8, 1, 1, '2025-07-03', '2025-07-06', 2, 4500.00, 'cancelled', 'paid', 'paypal', NULL, 'ATL17514649504068', 0, NULL, 'ccc', '2025-07-03 06:29:29', '2025-07-02 14:02:30', '2025-07-03 06:29:29', NULL, NULL, '2025-07-03 06:29:29'),
(29, 8, 1, 1, '2025-07-03', '2025-07-06', 2, 4500.00, 'cancelled', 'paid', 'paypal', NULL, 'ATL17514650495318', 0, NULL, 'bbbbbb', '2025-07-03 06:29:16', '2025-07-02 14:04:09', '2025-07-03 06:29:16', NULL, NULL, '2025-07-03 06:29:16'),
(30, 8, 1, 1, '2025-07-09', '2025-07-12', 2, 1500.00, 'pending', 'pending', NULL, NULL, 'ATL202550451', 0, NULL, NULL, NULL, '2025-07-02 14:09:18', '2025-07-02 14:09:18', NULL, NULL, '2025-07-02 14:09:18'),
(31, 8, 1, 1, '2025-07-09', '2025-07-12', 2, 1500.00, 'pending', 'pending', NULL, NULL, 'ATL202597802', 0, NULL, NULL, NULL, '2025-07-02 15:21:47', '2025-07-02 15:21:47', NULL, NULL, '2025-07-02 15:21:47'),
(32, 5, 2, 73, '2025-07-02', '2025-07-03', 2, 2200.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-07-02 15:31:47', '2025-07-02 15:31:47', NULL, NULL, '2025-07-02 15:31:47'),
(33, 5, 2, 9, '2025-07-02', '2025-07-03', 2, 1530.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-07-02 15:32:19', '2025-07-02 15:32:19', NULL, NULL, '2025-07-02 15:32:19'),
(35, 8, 1, 1, '2025-07-03', '2025-07-06', 2, 4500.00, 'cancelled', 'paid', 'paypal', NULL, 'ATL202541377', 0, NULL, 'vvvvv', '2025-07-03 06:29:02', '2025-07-02 16:08:46', '2025-07-03 06:29:02', NULL, NULL, '2025-07-03 06:29:02'),
(36, 8, 1, 1, '2025-07-03', '2025-07-06', 2, 4500.00, 'cancelled', 'paid', 'paypal', NULL, 'ATL202565277', 0, NULL, 'bbbbbb', '2025-07-03 06:28:49', '2025-07-02 16:15:42', '2025-07-03 06:28:49', NULL, NULL, '2025-07-03 06:28:49'),
(37, 8, 1, 1, '2025-07-03', '2025-07-06', 2, 4500.00, 'cancelled', 'paid', 'paypal', NULL, 'ATL202558669', 0, NULL, 'bbb', '2025-07-03 06:28:35', '2025-07-02 16:22:24', '2025-07-03 06:28:35', NULL, NULL, '2025-07-03 06:28:35'),
(38, 8, 1, 1, '2025-07-03', '2025-07-06', 2, 4500.00, 'cancelled', 'paid', 'cash', NULL, 'ATL202552331', 0, NULL, 'nnnn\r\n', '2025-07-03 06:28:19', '2025-07-02 16:22:53', '2025-07-03 06:28:19', NULL, NULL, '2025-07-03 06:28:19'),
(39, 5, 9, 30, '2025-07-02', '2025-07-03', 2, 738.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-07-02 16:29:45', '2025-07-02 16:29:45', NULL, NULL, '2025-07-02 16:29:45'),
(40, 8, 1, 1, '2025-07-03', '2025-07-06', 2, 4500.00, 'cancelled', 'paid', 'cash', NULL, 'ATL202575019', 0, NULL, 'bbbb', '2025-07-02 16:31:13', '2025-07-02 16:29:53', '2025-07-02 16:31:13', NULL, NULL, '2025-07-02 16:31:13'),
(41, 7, 2, 11, '2025-07-02', '2025-07-03', 2, 850.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-07-02 16:47:42', '2025-07-02 16:47:42', NULL, NULL, '2025-07-02 16:47:42'),
(42, 7, 5, 20, '2025-07-02', '2025-07-03', 2, 580.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-07-02 16:49:26', '2025-07-02 16:49:26', NULL, NULL, '2025-07-02 16:49:26'),
(43, 7, 7, 25, '2025-07-02', '2025-07-03', 1, 588.00, 'confirmed', 'paid', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-07-02 16:50:16', '2025-07-02 16:58:19', NULL, NULL, '2025-07-02 16:58:19'),
(44, 5, 5, 19, '2025-07-03', '2025-07-04', 2, 696.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-07-03 06:33:15', '2025-07-03 06:33:15', NULL, NULL, '2025-07-03 06:33:15'),
(45, 5, 14, 47, '2025-07-03', '2025-07-04', 2, 220.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-07-03 06:38:33', '2025-07-03 06:38:33', NULL, NULL, '2025-07-03 06:38:33'),
(46, 5, 19, 61, '2025-07-03', '2025-07-05', 4, 888.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-07-03 08:59:24', '2025-07-03 08:59:24', NULL, NULL, '2025-07-03 08:59:24'),
(47, 5, 18, 58, '2025-07-03', '2025-07-04', 2, 216.00, 'pending', 'pending', NULL, NULL, NULL, 0, '', NULL, NULL, '2025-07-03 16:29:27', '2025-07-03 16:29:27', NULL, NULL, '2025-07-03 16:29:27');

--
-- Déclencheurs `bookings`
--
DELIMITER $$
CREATE TRIGGER `manage_room_availability_on_booking_update_fixed` AFTER UPDATE ON `bookings` FOR EACH ROW BEGIN
    -- Éviter la récursion en vérifiant si c'est un changement de statut
    IF NEW.status != OLD.status THEN
        
        -- Si le statut change vers "cancelled"
        IF NEW.status = 'cancelled' AND OLD.status != 'cancelled' THEN
            -- Libérer les chambres bloquées
            DELETE FROM room_availability 
            WHERE booking_id = NEW.id;
            
            -- Logger l'annulation (sans modifier la table bookings)
            INSERT INTO activity_logs (user_id, action, table_name, record_id, new_values)
            VALUES (NEW.user_id, 'booking_cancelled', 'bookings', NEW.id, 
                    JSON_OBJECT('booking_id', NEW.id, 'cancellation_reason', NEW.cancellation_reason));
        END IF;
        
        -- Logger tous les changements de statut (sans modifier bookings)
        INSERT INTO activity_logs (user_id, action, table_name, record_id, new_values, created_at)
        VALUES (
            COALESCE(NEW.user_id, 0), 
            CONCAT('booking_status_changed_to_', NEW.status), 
            'bookings', 
            NEW.id, 
            JSON_OBJECT(
                'old_status', OLD.status, 
                'new_status', NEW.status,
                'hotel_id', NEW.hotel_id,
                'room_type_id', NEW.room_type_id,
                'room_id', NEW.room_id,
                'check_in', NEW.check_in,
                'check_out', NEW.check_out
            ),
            NOW()
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `email_type` enum('confirmation','cancellation','reminder') NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('sent','failed','pending') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `email_logs`
--

INSERT INTO `email_logs` (`id`, `booking_id`, `email_type`, `recipient_email`, `subject`, `sent_at`, `status`) VALUES
(1, 21, 'confirmation', 'client@example.com', 'Confirmation de réservation - Atlas Hotels', '2025-06-26 22:10:31', 'failed'),
(2, 22, 'confirmation', 'client@example.com', 'Confirmation de réservation - Atlas Hotels', '2025-06-27 11:31:25', 'failed'),
(5, 23, 'confirmation', 'client@example.com', 'Confirmation de réservation - Atlas Hotels', '2025-06-30 09:45:33', 'failed'),
(8, 24, 'confirmation', 'client@example.com', 'Confirmation de réservation - Atlas Hotels', '2025-07-01 19:44:57', 'failed'),
(12, 25, 'confirmation', 'client@example.com', 'Confirmation de réservation - Atlas Hotels', '2025-07-01 19:58:37', 'failed'),
(14, 26, 'confirmation', 'client@example.com', 'Confirmation de réservation - Atlas Hotels', '2025-07-02 14:01:50', 'failed'),
(15, 27, 'confirmation', 'client@example.com', 'Confirmation de réservation - Atlas Hotels', '2025-07-02 14:02:33', 'failed'),
(16, 29, 'confirmation', 'client@example.com', 'Confirmation de réservation - Atlas Hotels', '2025-07-02 14:04:11', 'failed'),
(17, 30, 'confirmation', 'client@example.com', 'Confirmation de réservation - Atlas Hotels', '2025-07-02 14:09:20', 'failed'),
(18, 33, 'confirmation', 'client@example.com', 'Confirmation de réservation - Atlas Hotels', '2025-07-02 15:32:25', 'failed');

-- --------------------------------------------------------

--
-- Structure de la table `hotels`
--

CREATE TABLE `hotels` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `location` varchar(255) NOT NULL,
  `city` varchar(100) NOT NULL,
  `country` varchar(100) DEFAULT 'Maroc',
  `rating` decimal(3,2) DEFAULT 5.00,
  `image_url` varchar(500) NOT NULL,
  `amenities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`amenities`)),
  `is_featured` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `price_per_night` decimal(10,2) NOT NULL DEFAULT 0.00,
  `featured` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `hotels`
--

INSERT INTO `hotels` (`id`, `name`, `description`, `location`, `city`, `country`, `rating`, `image_url`, `amenities`, `is_featured`, `status`, `created_at`, `updated_at`, `price_per_night`, `featured`) VALUES
(1, 'La Mamounia Palace', 'Palace légendaire au cœur de Marrakech, symbole du luxe marocain depuis 1923. Jardins somptueux, architecture raffinée et service d\'exception.', 'Avenue Bab Jdid', 'Marrakech', 'Maroc', 5.00, 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=1200', '[\"Spa de luxe\", \"Piscine chauffée\", \"Restaurant gastronomique\", \"Wifi gratuit\", \"Service voiturier\", \"Concierge 24h/24\"]', 1, 'active', '2025-06-07 12:20:29', '2025-06-07 12:38:31', 650.00, 0),
(2, 'Royal Marrakech', 'Riad royal exceptionnel offrant l\'art de vivre marocain dans son expression la plus raffinée. Architecture traditionnelle et modernité.', 'Rue Abou Abbas El Sebti', 'Marrakech', 'Maroc', 5.00, 'https://i.pinimg.com/736x/67/7b/18/677b1868f26cda3d136d8cd8d089943f.jpg', '[\"Spa marocain\", \"Trois restaurants\", \"Piscine privée\", \"Bibliothèque\", \"Hammam traditionnel\", \"Service majordome\"]', 1, 'active', '2025-06-07 12:20:29', '2025-06-07 14:13:57', 850.00, 0),
(3, 'FResort Marrakech', 'Resort contemporain au pied de l\'Atlas, alliance parfaite entre luxe moderne et charme berbère. Expériences authentiques garanties.', '1 Boulevard de la Menara', 'Marrakech', 'Maroc', 4.90, 'https://i.pinimg.com/736x/95/f0/48/95f048ba053f66a8464951b0e33bf11f.jpg', '[\"Spa Four Seasons\", \"Golf 18 trous\", \"Quatre restaurants\", \"Kids Club\", \"Tennis\", \"Excursions Atlas\"]', 1, 'active', '2025-06-07 12:20:29', '2025-06-07 14:12:58', 700.00, 0),
(4, 'Royal Mansour Marrakech', 'Riad royal exceptionnel offrant l\'art de vivre marocain dans un cadre somptueux avec jardins et fontaines.', 'Rue Abou Abbas El Sebti', 'Marrakech', 'Maroc', 5.00, 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=1200', '[\"Spa marocain\", \"Trois restaurants\", \"Piscine privée\", \"Concierge\", \"Wifi gratuit\"]', 0, 'active', '2025-06-07 12:45:06', '2025-06-08 10:45:32', 720.00, 0),
(5, 'Four Seasons Resort Marrakech', 'Resort contemporain au pied de l\'Atlas, alliance parfaite entre luxe et nature.', '1 Boulevard de la Menara', 'Marrakech', 'Maroc', 4.90, 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=1200', '[\"Spa Four Seasons\", \"Golf 18 trous\", \"Quatre restaurants\", \"Piscine extérieure\", \"Wifi gratuit\"]', 1, 'active', '2025-06-07 12:45:06', '2025-06-07 12:45:06', 580.00, 0),
(6, 'Sofitel Marrakech Lounge and Spa', 'Hôtel moderne avec spa et restaurant, situé proche de la médina.', 'Avenue Mohamed V', 'Marrakech', 'Maroc', 4.60, 'https://i.pinimg.com/736x/b4/29/52/b42952e7bddb90dbeddf90a91ccd8219.jpg', '[\"Spa complet\", \"Piscine\", \"Restaurant français\", \"Centre de fitness\", \"Wifi\"]', 0, 'active', '2025-06-07 12:45:06', '2025-06-07 14:10:02', 320.00, 0),
(7, 'Selman Marrakech', 'Hôtel de luxe avec une décoration orientale et un centre équestre.', 'Route d\'Amizmiz', 'Marrakech', 'Maroc', 4.80, 'https://i.pinimg.com/736x/b9/88/31/b98831bf43101ce5cf75fbee7c5673b9.jpg', '[\"Spa\", \"Centre équestre\", \"Piscine\", \"Restaurant gastronomique\", \"Wifi gratuit\"]', 1, 'active', '2025-06-07 12:45:06', '2025-06-07 14:10:01', 490.00, 0),
(8, 'Riad Kniza', 'Charmant riad traditionnel avec une hospitalité marocaine authentique.', 'Derb L\'Hajjama', 'Marrakech', 'Maroc', 4.70, 'https://i.pinimg.com/736x/37/e6/c6/37e6c63ca0370e1a2d168a69ab7da083.jpg', '[\"Patio\", \"Restaurant local\", \"Wifi\", \"Terrasse\", \"Service personnalisé\"]', 0, 'active', '2025-06-07 12:45:06', '2025-06-07 14:10:02', 180.00, 0),
(9, 'Mazagan Beach Resort', 'Resort de luxe en bord de mer avec golf et casino.', 'El Jadida', 'El Jadida', 'Maroc', 4.50, 'https://images.pexels.com/photos/261106/pexels-photo-261106.jpeg?auto=compress&cs=tinysrgb&w=1200', '[\"Golf\", \"Casino\", \"Piscine\", \"Plage privée\", \"Spa\"]', 1, 'active', '2025-06-07 12:45:06', '2025-07-02 17:07:42', 412.00, 0),
(10, 'Sofitel Casablanca Tour Blanche', 'Hôtel moderne et chic au cœur de Casablanca.', 'Place des Nations Unies', 'Casablanca', 'Maroc', 4.40, 'https://images.pexels.com/photos/258154/pexels-photo-258154.jpeg?auto=compress&cs=tinysrgb&w=1200', '[\"Spa\", \"Restaurant\", \"Centre d\'affaires\", \"Wifi\", \"Piscine\"]', 0, 'active', '2025-06-07 12:45:06', '2025-06-07 12:45:06', 290.00, 0),
(11, 'Hotel Le Doge', 'Boutique hôtel art déco élégant au centre de Casablanca.', 'Angle Boulevard Brahim Roudani', 'Casablanca', 'Maroc', 4.30, 'https://i.pinimg.com/736x/08/dd/b3/08ddb327f0eab6bbc18efef0edfb9950.jpg', '[\"Restaurant\", \"Bar\", \"Wifi\", \"Terrasse\"]', 0, 'active', '2025-06-07 12:45:06', '2025-06-07 14:10:02', 210.00, 0),
(12, 'Hotel Sahrai', 'Luxueux hôtel contemporain dans la médina de Fès.', 'Avenue Hassan II', 'Fès', 'Maroc', 4.70, 'https://i.pinimg.com/736x/e3/ce/e0/e3cee05a5bb8c43921cb489809bb0caf.jpg', '[\"Spa\", \"Piscine\", \"Restaurant\", \"Bar\", \"Wifi\"]', 1, 'active', '2025-06-07 12:45:06', '2025-06-07 14:11:30', 370.00, 0),
(13, 'Riad Fes', 'Riad traditionnel rénové avec une hospitalité chaleureuse.', 'Talaa Kebira', 'Fès', 'Maroc', 4.60, 'https://i.pinimg.com/736x/f1/0b/ed/f10bedaf3e22ac624bd9ef9eb34743e8.jpg', '[\"Patio\", \"Restaurant\", \"Spa\", \"Wifi\", \"Service personnalisé\"]', 0, 'active', '2025-06-07 12:45:06', '2025-06-07 14:10:02', 200.00, 0),
(14, 'The Oberoi Marrakech', 'Hôtel de luxe offrant une expérience marocaine contemporaine.', 'Route de l\'Ourika', 'Marrakech', 'Maroc', 4.90, 'https://i.pinimg.com/736x/7c/58/63/7c5863c855b535cc9a6d3c09d56148ae.jpg', '[\"Spa\", \"Piscine\", \"Restaurant\", \"Centre fitness\", \"Wifi\"]', 1, 'active', '2025-06-07 12:45:06', '2025-06-07 14:10:01', 690.00, 0),
(15, 'Kasbah Tamadot', 'Refuge idyllique dans les montagnes de l\'Atlas, propriété de Richard Branson.', 'Toubkal National Park', 'Asni', 'Maroc', 4.80, 'https://i.pinimg.com/736x/4e/7c/80/4e7c8035d9e0c9d9fd59e0c20ebf9629.jpg', '[\"Spa\", \"Randonnée\", \"Restaurant\", \"Piscine\", \"Wifi\"]', 1, 'active', '2025-06-07 12:45:06', '2025-06-07 14:10:02', 720.00, 0),
(16, 'Palais Amani', 'Palais rénové avec une décoration authentique dans la médina de Fès.', 'Quartier Andalous', 'Fès', 'Maroc', 4.70, 'https://i.pinimg.com/736x/12/8b/b4/128bb4a42015b07ca9c6575278244a09.jpg', '[\"Spa\", \"Patio\", \"Restaurant\", \"Wifi\", \"Terrasse\"]', 1, 'active', '2025-06-07 12:45:06', '2025-06-27 13:50:32', 230.00, 0),
(17, 'Riad Yasmine', 'Riad charmant et intime, très prisé des voyageurs.', 'Rue Assouel', 'Marrakech', 'Maroc', 4.50, 'https://images.pexels.com/photos/261101/pexels-photo-261101.jpeg?auto=compress&cs=tinysrgb&w=1200', '[\"Piscine\", \"Terrasse\", \"Wifi\", \"Service personnalisé\"]', 0, 'active', '2025-06-07 12:45:06', '2025-06-07 12:45:06', 150.00, 0),
(18, 'Hotel Sahar', 'Hôtel simple et confortable à Agadir, près de la plage.', 'Boulevard du 20 Août', 'Agadir', 'Maroc', 4.20, 'https://images.pexels.com/photos/164338/pexels-photo-164338.jpeg?auto=compress&cs=tinysrgb&w=1200', '[\"Piscine\", \"Restaurant\", \"Bar\", \"Wifi\"]', 0, 'active', '2025-06-07 12:45:06', '2025-06-07 12:45:06', 120.00, 0),
(19, 'Sofitel Agadir Thalassa Sea & Spa', 'Hôtel de luxe avec centre thalasso et spa en bord de mer.', 'Boulevard du 20 Août', 'Agadir', 'Maroc', 4.70, 'https://i.pinimg.com/736x/f5/e2/4d/f5e24dfd641b4aabd0d522712ec1a3e2.jpg', '[\"Thalasso\", \"Spa\", \"Piscine\", \"Restaurant\", \"Wifi\"]', 1, 'active', '2025-06-07 12:45:06', '2025-06-07 14:10:02', 400.00, 0),
(20, 'Riad Les Yeux Bleus', 'Riad authentique avec un cadre paisible dans la médina de Marrakech.', 'Derb El Ferrane', 'Marrakech', 'Maroc', 4.60, 'https://i.pinimg.com/736x/08/0d/15/080d15f25dfde15290c5433294e9d8c1.jpg', '[\"Patio\", \"Terrasse\", \"Piscine\", \"Wifi\"]', 0, 'active', '2025-06-07 12:45:06', '2025-06-07 14:10:02', 180.00, 0),
(21, 'Hotel Kenzi Tower', 'Hôtel moderne avec vues panoramiques sur Casablanca.', 'Boulevard de la Corniche', 'Casablanca', 'Maroc', 4.40, 'https://i.pinimg.com/736x/c3/c7/cf/c3c7cf345a85c6142b9e035ebe76cf07.jpg', '[\"Piscine\", \"Restaurant\", \"Spa\", \"Wifi\"]', 0, 'active', '2025-06-07 12:45:06', '2025-06-07 14:10:02', 310.00, 0),
(27, 'bbbb', 'bbbbb', 'nnnnn', 'bbbb', 'Maroc', 5.00, 'https://i.pinimg.com/736x/1c/75/c5/1c75c52bafa72193f1daf8b2be24baf8.jpg', NULL, 0, 'inactive', '2025-06-09 12:42:37', '2025-06-09 12:42:55', 158.00, 0),
(30, 'Beach Resort Agadir', 'Resort en bord de mer avec accès direct à la plage et installations de loisirs complètes.', 'Front de mer', 'Agadir', 'Maroc', 4.00, 'https://i.pinimg.com/736x/31/4b/0a/314b0a37abc2933e4cddff9fda833387.jpg', '[\"Piscine\", \"Plage privée\", \"Restaurant\", \"WiFi\", \"Salle de sport\", \"Spa\", \"Animation\"]', 1, 'active', '2025-06-10 09:52:05', '2025-06-10 09:59:43', 1200.00, 0),
(31, 'Atlas Mountain Lodge', 'Lodge écologique dans les montagnes de l\'Atlas avec vue spectaculaire et activités nature.', 'Haut Atlas', 'Imlil', 'Maroc', 4.00, 'https://i.pinimg.com/736x/f8/09/c5/f809c54b80f139420d49d202194a4df8.jpg', '[\"Restaurant\", \"WiFi\", \"Randonnée\", \"Spa\", \"Parking\"]', 1, 'active', '2025-06-10 09:52:05', '2025-06-26 22:10:59', 900.00, 0),
(32, 'bbb', 'bbbb', 'El Jadida', 'El Jadida', 'Maroc', 5.00, 'https://images.pexels.com/photos/261106/pexels-photo-261106.jpeg?auto=compress&cs=tinysrgb&w=1200', NULL, 0, 'inactive', '2025-07-03 08:33:15', '2025-07-03 08:34:03', 412.00, 0);

-- --------------------------------------------------------

--
-- Structure de la table `hotel_bookings`
--

CREATE TABLE `hotel_bookings` (
  `id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `guest_name` varchar(255) NOT NULL,
  `guest_email` varchar(255) NOT NULL,
  `guest_phone` varchar(50) DEFAULT NULL,
  `checkin_date` date NOT NULL,
  `checkout_date` date NOT NULL,
  `nights` int(11) NOT NULL,
  `adults` int(11) DEFAULT 1,
  `children` int(11) DEFAULT 0,
  `rooms_count` int(11) DEFAULT 1,
  `room_type` enum('standard','deluxe','suite','presidential') DEFAULT 'standard',
  `total_price` decimal(10,2) NOT NULL,
  `status` enum('pending','confirmed','cancelled','checked_in','checked_out','completed') DEFAULT 'pending',
  `special_requests` text DEFAULT NULL,
  `confirmation_code` varchar(50) DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `hotel_images`
--

CREATE TABLE `hotel_images` (
  `id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `nearby_services`
--

CREATE TABLE `nearby_services` (
  `id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('restaurant','attraction','transport','shopping','medical','other') NOT NULL,
  `distance_km` decimal(5,2) NOT NULL,
  `description` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `nearby_services`
--

INSERT INTO `nearby_services` (`id`, `hotel_id`, `name`, `type`, `distance_km`, `description`, `address`, `phone`, `website`, `created_at`) VALUES
(1, 1, 'Restaurant La Mamounia', 'restaurant', 0.50, 'Restaurant gastronomique marocain', 'Avenue Bab Jdid, Marrakech', NULL, NULL, '2025-06-10 09:50:17'),
(2, 1, 'Jardin Majorelle', 'attraction', 2.10, 'Jardin botanique et musée berbère', 'Rue Yves Saint Laurent, Marrakech', NULL, NULL, '2025-06-10 09:50:17'),
(3, 1, 'Aéroport Marrakech-Ménara', 'transport', 6.80, 'Aéroport international', 'Marrakech', NULL, NULL, '2025-06-10 09:50:17'),
(4, 2, 'Médina de Fès', 'attraction', 0.30, 'Centre historique classé UNESCO', 'Fès el-Bali, Fès', NULL, NULL, '2025-06-10 09:50:17'),
(5, 2, 'Restaurant Palais Amani', 'restaurant', 0.80, 'Cuisine traditionnelle dans un palais', 'Fès', NULL, NULL, '2025-06-10 09:50:17'),
(6, 3, 'Plage d\'Agadir', 'attraction', 0.10, 'Plage de sable fin', 'Front de mer, Agadir', NULL, NULL, '2025-06-10 09:50:17'),
(7, 3, 'Souk El Had', 'shopping', 3.20, 'Grand marché traditionnel', 'Agadir', NULL, NULL, '2025-06-10 09:50:17'),
(8, 4, 'Parc National du Toubkal', 'attraction', 5.00, 'Parc national de montagne', 'Haut Atlas', NULL, NULL, '2025-06-10 09:50:17');

-- --------------------------------------------------------

--
-- Structure de la table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `payment_method` enum('card','paypal','bank_transfer') NOT NULL,
  `payment_gateway` varchar(50) NOT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'MAD',
  `status` enum('pending','processing','completed','failed','cancelled','refunded') DEFAULT 'pending',
  `gateway_response` text DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `payment_transactions`
--

CREATE TABLE `payment_transactions` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'EUR',
  `payment_method` varchar(50) NOT NULL,
  `gateway_type` enum('stripe','paypal','card') DEFAULT 'card',
  `transaction_id` varchar(255) DEFAULT NULL,
  `gateway_transaction_id` varchar(255) DEFAULT NULL,
  `gateway_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gateway_response`)),
  `status` enum('pending','completed','failed','refunded') DEFAULT 'pending',
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `payment_transactions`
--

INSERT INTO `payment_transactions` (`id`, `booking_id`, `amount`, `currency`, `payment_method`, `gateway_type`, `transaction_id`, `gateway_transaction_id`, `gateway_response`, `status`, `processed_at`, `created_at`) VALUES
(1, 21, 1250.00, 'MAD', 'paypal', 'card', 'TXN_1750975829_8760', 'PAY_1750975829_2274', NULL, 'completed', '2025-06-26 22:10:29', '2025-06-26 22:10:29'),
(2, 22, 1200.00, 'MAD', 'paypal', 'card', 'TXN_1751023882_8036', 'PAY_1751023883_3877', NULL, 'completed', '2025-06-27 11:31:23', '2025-06-27 11:31:22'),
(6, 23, 396.00, 'MAD', 'paypal', 'card', 'TXN_1751276730_9585', 'PAY_1751276731_5790', NULL, 'completed', '2025-06-30 09:45:31', '2025-06-30 09:45:30'),
(9, 24, 650.00, 'MAD', 'paypal', 'card', 'TXN_1751399094_2323', 'PAY_1751399095_7384', NULL, 'completed', '2025-07-01 19:44:55', '2025-07-01 19:44:54'),
(15, 25, 396.00, 'MAD', 'paypal', 'card', 'TXN_1751399914_5976', 'PAY_1751399915_8869', NULL, 'completed', '2025-07-01 19:58:35', '2025-07-01 19:58:34'),
(17, 26, 4500.00, 'MAD', 'paypal', 'card', 'TXN_1751464908_4140', 'PAY_1751464908_4317', NULL, 'completed', '2025-07-02 14:01:48', '2025-07-02 14:01:48'),
(18, 27, 4500.00, 'MAD', 'paypal', 'card', 'TXN_1751464950_3123', 'PAY_1751464951_4962', NULL, 'completed', '2025-07-02 14:02:31', '2025-07-02 14:02:30'),
(20, 29, 4500.00, 'MAD', 'paypal', 'card', 'TXN_1751465049_3413', 'PAY_1751465049_2627', NULL, 'completed', '2025-07-02 14:04:09', '2025-07-02 14:04:09'),
(21, 30, 1500.00, 'MAD', 'paypal', 'card', 'TXN_1751465360_2750', NULL, NULL, 'pending', NULL, '2025-07-02 14:09:20'),
(23, 33, 1530.00, 'MAD', 'paypal', 'card', 'TXN_1751470342_7317', 'PAY_1751470343_4394', NULL, 'completed', '2025-07-02 15:32:23', '2025-07-02 15:32:22'),
(26, 35, 4500.00, 'MAD', 'paypal', 'card', 'PP_1751472526_1719', 'PAYPAL_6865598FF14D9', NULL, 'completed', '2025-07-02 16:08:47', '2025-07-02 16:08:46'),
(27, 36, 4500.00, 'MAD', 'paypal', 'card', 'PP_1751472942_4364', 'PAYPAL_68655B2F580B3', NULL, 'completed', '2025-07-02 16:15:43', '2025-07-02 16:15:42'),
(28, 37, 4500.00, 'MAD', 'paypal', 'card', 'PP_1751473344_6131', 'PAYPAL_68655CC1DBB9D', NULL, 'completed', '2025-07-02 16:22:25', '2025-07-02 16:22:24'),
(29, 38, 4500.00, 'MAD', 'cash', 'card', 'CASH_1751473373_1574', 'CASH_68655CDD69744', NULL, 'completed', '2025-07-02 16:22:53', '2025-07-02 16:22:53'),
(30, 40, 4500.00, 'MAD', 'cash', 'card', 'CASH_1751473793_8302', 'CASH_68655E815C5AA', NULL, 'completed', '2025-07-02 16:29:53', '2025-07-02 16:29:53');

-- --------------------------------------------------------

--
-- Structure de la table `promotions`
--

CREATE TABLE `promotions` (
  `id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `discount_type` enum('percentage','fixed_amount') NOT NULL,
  `discount_value` decimal(10,2) NOT NULL,
  `min_nights` int(11) DEFAULT 1,
  `valid_from` date NOT NULL,
  `valid_to` date NOT NULL,
  `max_uses` int(11) DEFAULT NULL,
  `current_uses` int(11) DEFAULT 0,
  `status` enum('active','inactive','expired') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `promotions`
--

INSERT INTO `promotions` (`id`, `hotel_id`, `title`, `description`, `discount_type`, `discount_value`, `min_nights`, `valid_from`, `valid_to`, `max_uses`, `current_uses`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 'Offre Spéciale Été', 'Réduction de 20% pour les séjours de 3 nuits minimum', 'percentage', 20.00, 3, '2024-06-01', '2024-08-31', NULL, 0, 'active', '2025-06-10 09:50:17', '2025-06-10 09:50:17'),
(2, 2, 'Week-end Découverte', 'Forfait week-end avec petit-déjeuner inclus', 'fixed_amount', 500.00, 2, '2024-01-01', '2024-12-31', NULL, 0, 'active', '2025-06-10 09:50:17', '2025-06-10 09:50:17'),
(3, 3, 'Séjour Famille', 'Réduction de 15% pour les familles avec enfants', 'percentage', 15.00, 4, '2024-07-01', '2024-09-15', NULL, 0, 'active', '2025-06-10 09:50:17', '2025-06-10 09:50:17'),
(4, 4, 'Aventure Montagne', 'Package randonnée avec guide inclus', 'fixed_amount', 300.00, 2, '2024-03-01', '2024-11-30', NULL, 0, 'active', '2025-06-10 09:50:17', '2025-06-10 09:50:17');

-- --------------------------------------------------------

--
-- Structure de la table `promo_codes`
--

CREATE TABLE `promo_codes` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `discount_type` enum('percentage','fixed_amount') NOT NULL,
  `discount_value` decimal(10,2) NOT NULL,
  `min_amount` decimal(10,2) DEFAULT 0.00,
  `max_discount` decimal(10,2) DEFAULT NULL,
  `usage_limit` int(11) DEFAULT NULL,
  `used_count` int(11) DEFAULT 0,
  `valid_from` timestamp NOT NULL DEFAULT current_timestamp(),
  `valid_until` timestamp NULL DEFAULT NULL,
  `status` enum('active','inactive','expired') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `promo_codes`
--

INSERT INTO `promo_codes` (`id`, `code`, `description`, `discount_type`, `discount_value`, `min_amount`, `max_discount`, `usage_limit`, `used_count`, `valid_from`, `valid_until`, `status`, `created_at`, `updated_at`) VALUES
(1, 'WELCOME10', 'Réduction de 10% pour les nouveaux clients', 'percentage', 10.00, 200.00, NULL, NULL, 0, '2025-06-08 13:10:09', '2025-12-31 22:59:59', 'active', '2025-06-08 13:10:09', '2025-06-08 13:10:09'),
(2, 'SUMMER50', 'Réduction de 50 MAD pour l\'été', 'fixed_amount', 50.00, 300.00, NULL, NULL, 0, '2025-06-08 13:10:09', '2025-09-30 22:59:59', 'active', '2025-06-08 13:10:09', '2025-06-08 13:10:09'),
(3, 'LUXURY15', 'Réduction de 15% sur les hôtels de luxe', 'percentage', 15.00, 500.00, NULL, NULL, 0, '2025-06-08 13:10:09', '2025-08-31 22:59:59', 'active', '2025-06-08 13:10:09', '2025-06-08 13:10:09');

-- --------------------------------------------------------

--
-- Structure de la table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `check_in` date NOT NULL,
  `check_out` date NOT NULL,
  `guests` int(11) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `status` enum('pending','confirmed','cancelled') DEFAULT 'pending',
  `payment_status` enum('pending','paid','failed') DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `special_requests` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `rating` int(11) NOT NULL CHECK (`rating` >= 1 and `rating` <= 5),
  `comment` text DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `rooms`
--

CREATE TABLE `rooms` (
  `id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `room_type_id` int(11) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `room_type` varchar(100) NOT NULL,
  `floor_number` int(11) DEFAULT NULL,
  `status` enum('available','occupied','maintenance','out_of_order') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `bed_count` int(11) DEFAULT 0,
  `price_per_night` decimal(10,2) DEFAULT 0.00,
  `amenities` text DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `rooms`
--

INSERT INTO `rooms` (`id`, `hotel_id`, `room_type_id`, `room_number`, `room_type`, `floor_number`, `status`, `created_at`, `updated_at`, `bed_count`, `price_per_night`, `amenities`, `image_url`, `type`, `capacity`, `price`) VALUES
(136, 3, 1, '104', 'Deluxe', 2, 'occupied', '2025-06-08 13:27:44', '2025-06-26 12:20:08', 2, 670.00, '[\"Spa\", \"WiFi\", \"Mini-bar\"]', 'https://images.pexels.com/photos/261145/pexels-photo-261145.jpeg', 'Deluxe', 2, 670.00),
(138, 4, 1, '101', 'Deluxe', 1, 'available', '2025-06-08 13:28:56', '2025-06-08 13:28:56', 2, 720.00, '[\"Spa marocain\", \"WiFi\", \"Mini-bar\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg', 'Deluxe', 2, 720.00),
(139, 4, 2, '102', 'Standard', 1, 'available', '2025-06-08 13:28:56', '2025-06-08 13:28:56', 1, 650.00, '[\"WiFi\", \"TV\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Standard', 1, 650.00),
(140, 4, 3, '103', 'Suite', 2, 'available', '2025-06-08 13:28:56', '2025-06-08 13:28:56', 3, 780.00, '[\"Spa\", \"WiFi\", \"Balcony\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg', 'Suite', 3, 780.00),
(141, 4, 1, '104', 'Deluxe', 2, 'available', '2025-06-08 13:28:56', '2025-06-08 13:28:56', 2, 700.00, '[\"Spa marocain\", \"WiFi\", \"Mini-bar\"]', 'https://images.pexels.com/photos/261145/pexels-photo-261145.jpeg', 'Deluxe', 2, 700.00),
(147, 5, 80, '105', 'Triple', 2, 'occupied', '2025-06-08 13:28:56', '2025-07-01 19:50:51', 1, 550.00, '[\"WiFi\", \"TV\"]', 'https://i.pinimg.com/736x/3d/ee/0a/3dee0a437d1dbaacb34bd71b9d504880.jpg', 'Standard', 1, 540.00),
(151, 6, 1, '104', 'Deluxe', 2, 'available', '2025-06-08 13:28:56', '2025-06-10 11:40:05', 2, 330.00, '[\"Spa complet\", \"WiFi\", \"Mini-bar\"]', 'https://i.pinimg.com/736x/3d/ee/0a/3dee0a437d1dbaacb34bd71b9d504880.jpg', 'Deluxe', 2, 330.00),
(154, 7, 2, '102', 'Standard', 1, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 1, 390.00, '[\"WiFi\", \"TV\"]', 'https://images.pexels.com/photos/271625/pexels-photo-271625.jpeg', 'Standard', 1, 390.00),
(162, 8, 2, '105', 'Standard', 2, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 1, 490.00, '[\"WiFi\", \"TV\"]', 'https://images.pexels.com/photos/271626/pexels-photo-271626.jpeg', 'Standard', 1, 490.00),
(166, 9, 1, '104', 'Deluxe', 2, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 2, 620.00, '[\"Spa\", \"WiFi\", \"Mini-bar\"]', 'https://images.pexels.com/photos/261105/pexels-photo-261105.jpeg', 'Deluxe', 2, 620.00),
(167, 9, 2, '105', 'Standard', 2, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 1, 580.00, '[\"WiFi\", \"TV\"]', 'https://images.pexels.com/photos/271627/pexels-photo-271627.jpeg', 'Standard', 1, 580.00),
(175, 11, 3, '103', 'Suite', 2, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 3, 560.00, '[\"Spa\", \"WiFi\", \"Balcony\"]', 'https://images.pexels.com/photos/261107/pexels-photo-261107.jpeg', 'Suite', 3, 560.00),
(192, 14, 2, '105', 'Standard', 2, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 1, 540.00, '[\"WiFi\", \"TV\"]', 'https://images.pexels.com/photos/271632/pexels-photo-271632.jpeg', 'Standard', 1, 540.00),
(193, 15, 1, '101', 'Deluxe', 1, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 2, 650.00, '[\"Spa\", \"WiFi\", \"Mini-bar\"]', 'https://images.pexels.com/photos/261111/pexels-photo-261111.jpeg', 'Deluxe', 2, 650.00),
(202, 16, 2, '105', 'Standard', 2, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 1, 360.00, '[\"WiFi\", \"TV\"]', 'https://images.pexels.com/photos/271634/pexels-photo-271634.jpeg', 'Standard', 1, 360.00),
(210, 18, 3, '103', 'Suite', 2, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 3, 620.00, '[\"Spa\", \"WiFi\", \"Balcony\"]', 'https://images.pexels.com/photos/261114/pexels-photo-261114.jpeg', 'Suite', 3, 620.00),
(213, 19, 1, '101', 'Deluxe', 1, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 2, 450.00, '[\"Spa\", \"WiFi\", \"Mini-bar\"]', 'https://images.pexels.com/photos/261115/pexels-photo-261115.jpeg', 'Deluxe', 2, 450.00),
(214, 19, 2, '102', 'Standard', 1, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 1, 410.00, '[\"WiFi\", \"TV\"]', 'https://images.pexels.com/photos/271637/pexels-photo-271637.jpeg', 'Standard', 1, 410.00),
(219, 20, 2, '102', 'Standard', 1, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 1, 350.00, '[\"WiFi\", \"TV\"]', 'https://images.pexels.com/photos/271638/pexels-photo-271638.jpeg', 'Standard', 1, 350.00),
(223, 21, 1, '101', 'Deluxe', 1, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 2, 600.00, '[\"Spa\", \"WiFi\", \"Mini-bar\"]', 'https://images.pexels.com/photos/261117/pexels-photo-261117.jpeg', 'Deluxe', 2, 600.00),
(224, 21, 2, '102', 'Standard', 1, 'available', '2025-06-08 13:42:53', '2025-06-08 13:42:53', 1, 560.00, '[\"WiFi\", \"TV\"]', 'https://images.pexels.com/photos/271639/pexels-photo-271639.jpeg', 'Standard', 1, 560.00),
(760, 1, 1, 'AUTO-1-1-273', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1200.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Minibar premium\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Royale', 4, 1200.00),
(761, 1, 2, 'AUTO-1-2-358', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 890.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoirs\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Deluxe', 2, 890.00),
(762, 1, 3, 'AUTO-1-3-745', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Standard', 2, 650.00),
(763, 1, 6, 'AUTO-1-6-144', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1170.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1170.00),
(764, 1, 7, 'AUTO-1-7-535', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 780.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 780.00),
(765, 1, 8, 'AUTO-1-8-769', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 650.00),
(766, 1, 71, 'AUTO-1-71-800', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 3500.00, '[\"Balcon privé\", \"Jacuzzi\", \"Service en chambre 24h\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Royale', 4, 3500.00),
(767, 1, 72, 'AUTO-1-72-185', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 2500.00, '[\"Terrasse\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Deluxe', 2, 2500.00),
(768, 2, 4, 'AUTO-2-4-817', 'Riad Présidentiel', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 2500.00, '[\"Piscine privée\", \"Majordome 24h\", \"Cuisine équipée\", \"Terrasse panoramique\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=800', 'Riad Présidentiel', 6, 2500.00),
(769, 2, 5, 'AUTO-2-5-369', 'Suite Riad', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 1250.00, '[\"Salon marocain\", \"Cheminée\", \"Terrasse privée\", \"Service thé\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Riad', 3, 1250.00),
(770, 2, 9, 'AUTO-2-9-561', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1530.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1530.00),
(771, 2, 10, 'AUTO-2-10-135', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1020.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 1020.00),
(772, 2, 11, 'AUTO-2-11-222', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 850.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 850.00),
(773, 2, 73, 'AUTO-2-73-308', 'Suite Traditionnelle', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 2200.00, '[\"Climatisation\", \"TV\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Traditionnelle', 3, 2200.00),
(774, 3, 12, 'AUTO-3-12-253', 'Suite Royale', 3, 'occupied', '2025-06-10 18:16:56', '2025-06-26 12:20:08', 4, 1260.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1260.00),
(775, 3, 13, 'AUTO-3-13-250', 'Chambre Deluxe', 2, 'occupied', '2025-06-10 18:16:56', '2025-06-26 12:20:08', 2, 840.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 840.00),
(776, 3, 14, 'AUTO-3-14-668', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 700.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 700.00),
(777, 3, 74, 'AUTO-3-74-544', 'Chambre Vue Mer', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1400.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Vue Mer', 2, 1400.00),
(778, 3, 75, 'AUTO-3-75-739', 'Suite Familiale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 1800.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\", \"Kitchenette\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Familiale', 6, 1800.00),
(779, 4, 15, 'AUTO-4-15-137', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1296.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1296.00),
(780, 4, 16, 'AUTO-4-16-463', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 864.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 864.00),
(781, 4, 17, 'AUTO-4-17-370', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 720.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 720.00),
(782, 4, 76, 'AUTO-4-76-167', 'Chalet Montagne', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1100.00, '[\"Cheminée\", \"Chauffage\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chalet Montagne', 4, 1100.00),
(783, 5, 78, 'AUTO-5-18-754', 'Suite Royale', 5, 'occupied', '2025-06-10 18:16:56', '2025-06-26 12:20:08', 4, 1044.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1044.00),
(784, 5, 79, 'AUTO-5-19-216', 'Chambre Deluxe', 1, 'maintenance', '2025-06-10 18:16:56', '2025-06-26 13:48:21', 2, 696.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 696.00),
(786, 6, 21, 'AUTO-6-21-340', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 576.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 576.00),
(787, 6, 22, 'AUTO-6-22-842', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 384.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 384.00),
(788, 6, 23, 'AUTO-6-23-332', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 320.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 320.00),
(789, 7, 24, 'AUTO-7-24-547', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 882.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 882.00),
(790, 7, 25, 'AUTO-7-25-197', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 588.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 588.00),
(791, 7, 26, 'AUTO-7-26-628', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 490.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 490.00),
(792, 8, 27, 'AUTO-8-27-263', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(793, 8, 28, 'AUTO-8-28-242', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(794, 8, 29, 'AUTO-8-29-445', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(795, 9, 30, 'AUTO-9-30-881', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 738.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 738.00),
(796, 9, 31, 'AUTO-9-31-140', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 492.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 492.00),
(797, 9, 32, 'AUTO-9-32-136', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 410.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 410.00),
(798, 9, 70, 'AUTO-9-70-420', 'Simple', 5, 'available', '2025-06-10 18:16:56', '2025-07-02 17:09:04', 2, 550.00, '', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Simple', 2, 0.00),
(799, 10, 33, 'AUTO-10-33-461', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 522.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 522.00),
(800, 10, 34, 'AUTO-10-34-480', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 348.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 348.00),
(801, 10, 35, 'AUTO-10-35-479', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 290.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 290.00),
(802, 11, 36, 'AUTO-11-36-281', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 432.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 432.00),
(803, 11, 37, 'AUTO-11-37-140', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 288.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 288.00),
(804, 11, 38, 'AUTO-11-38-136', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 240.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 240.00),
(805, 12, 39, 'AUTO-12-39-421', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(806, 12, 40, 'AUTO-12-40-490', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(807, 12, 41, 'AUTO-12-41-117', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(808, 13, 42, 'AUTO-13-42-512', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 270.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 270.00),
(809, 13, 43, 'AUTO-13-43-591', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 180.00),
(810, 13, 44, 'AUTO-13-44-319', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 150.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 150.00),
(811, 14, 45, 'AUTO-14-45-754', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 396.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 396.00),
(812, 14, 46, 'AUTO-14-46-652', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 264.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 264.00),
(813, 14, 47, 'AUTO-14-47-217', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 220.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 220.00),
(814, 15, 48, 'AUTO-15-48-957', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(815, 15, 49, 'AUTO-15-49-940', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(816, 15, 50, 'AUTO-15-50-256', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(817, 16, 51, 'AUTO-16-51-908', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 450.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 450.00),
(818, 16, 52, 'AUTO-16-52-555', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 300.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 300.00),
(819, 16, 53, 'AUTO-16-53-567', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 250.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 250.00),
(820, 17, 54, 'AUTO-17-54-381', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 468.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 468.00),
(821, 17, 55, 'AUTO-17-55-345', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 312.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 312.00),
(822, 17, 56, 'AUTO-17-56-428', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 260.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 260.00),
(823, 18, 57, 'AUTO-18-57-534', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(824, 18, 58, 'AUTO-18-58-892', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(825, 18, 59, 'AUTO-18-59-451', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(826, 19, 60, 'AUTO-19-60-545', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 666.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 666.00),
(827, 19, 61, 'AUTO-19-61-901', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 444.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 444.00),
(828, 19, 62, 'AUTO-19-62-511', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 370.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 370.00),
(829, 20, 63, 'AUTO-20-63-714', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 252.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 252.00),
(830, 20, 64, 'AUTO-20-64-876', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 168.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 168.00),
(831, 20, 65, 'AUTO-20-65-315', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 140.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 140.00),
(832, 21, 66, 'AUTO-21-66-816', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 720.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 720.00),
(833, 21, 67, 'AUTO-21-67-972', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 480.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 480.00),
(834, 21, 68, 'AUTO-21-68-328', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 400.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 400.00),
(887, 1, 1, 'EXTRA-1-1-1', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1200.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Minibar premium\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Royale', 4, 1200.00),
(888, 1, 2, 'EXTRA-1-2-1', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 890.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoirs\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Deluxe', 2, 890.00),
(889, 1, 3, 'EXTRA-1-3-1', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Standard', 2, 650.00),
(890, 1, 6, 'EXTRA-1-6-1', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1170.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1170.00),
(891, 1, 7, 'EXTRA-1-7-1', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 780.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 780.00),
(892, 1, 8, 'EXTRA-1-8-1', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 650.00),
(893, 1, 71, 'EXTRA-1-71-1', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 3500.00, '[\"Balcon privé\", \"Jacuzzi\", \"Service en chambre 24h\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Royale', 4, 3500.00),
(894, 1, 72, 'EXTRA-1-72-1', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 2500.00, '[\"Terrasse\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Deluxe', 2, 2500.00),
(895, 2, 4, 'EXTRA-2-4-1', 'Riad Présidentiel', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 2500.00, '[\"Piscine privée\", \"Majordome 24h\", \"Cuisine équipée\", \"Terrasse panoramique\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=800', 'Riad Présidentiel', 6, 2500.00),
(896, 2, 5, 'EXTRA-2-5-1', 'Suite Riad', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 1250.00, '[\"Salon marocain\", \"Cheminée\", \"Terrasse privée\", \"Service thé\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Riad', 3, 1250.00),
(897, 2, 9, 'EXTRA-2-9-1', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1530.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1530.00),
(898, 2, 10, 'EXTRA-2-10-1', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1020.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 1020.00),
(899, 2, 11, 'EXTRA-2-11-1', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 850.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 850.00),
(900, 2, 73, 'EXTRA-2-73-1', 'Suite Traditionnelle', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 2200.00, '[\"Climatisation\", \"TV\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Traditionnelle', 3, 2200.00),
(901, 3, 12, 'EXTRA-3-12-1', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1260.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1260.00),
(902, 3, 13, 'EXTRA-3-13-1', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 840.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 840.00),
(903, 3, 14, 'EXTRA-3-14-1', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 700.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 700.00),
(904, 3, 74, 'EXTRA-3-74-1', 'Chambre Vue Mer', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1400.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Vue Mer', 2, 1400.00),
(905, 3, 75, 'EXTRA-3-75-1', 'Suite Familiale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 1800.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\", \"Kitchenette\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Familiale', 6, 1800.00),
(906, 4, 15, 'EXTRA-4-15-1', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1296.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1296.00),
(907, 4, 16, 'EXTRA-4-16-1', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 864.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 864.00),
(908, 4, 17, 'EXTRA-4-17-1', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 720.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 720.00),
(909, 4, 76, 'EXTRA-4-76-1', 'Chalet Montagne', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1100.00, '[\"Cheminée\", \"Chauffage\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chalet Montagne', 4, 1100.00),
(911, 5, 19, 'EXTRA-5-19-1', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 696.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 696.00),
(912, 5, 20, 'EXTRA-5-20-1', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 580.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 580.00),
(913, 6, 21, 'EXTRA-6-21-1', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 576.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 576.00),
(914, 6, 22, 'EXTRA-6-22-1', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 384.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 384.00),
(915, 6, 23, 'EXTRA-6-23-1', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 320.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 320.00),
(916, 7, 24, 'EXTRA-7-24-1', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 882.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 882.00),
(917, 7, 25, 'EXTRA-7-25-1', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 588.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 588.00),
(918, 7, 26, 'EXTRA-7-26-1', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 490.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 490.00),
(919, 8, 27, 'EXTRA-8-27-1', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(920, 8, 28, 'EXTRA-8-28-1', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(921, 8, 29, 'EXTRA-8-29-1', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(922, 9, 30, 'EXTRA-9-30-1', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 738.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 738.00),
(923, 9, 31, 'EXTRA-9-31-1', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 492.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 492.00),
(924, 9, 32, 'EXTRA-9-32-1', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 410.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 410.00),
(926, 10, 33, 'EXTRA-10-33-1', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 522.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 522.00),
(927, 10, 34, 'EXTRA-10-34-1', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 348.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 348.00),
(928, 10, 35, 'EXTRA-10-35-1', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 290.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 290.00),
(929, 11, 36, 'EXTRA-11-36-1', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 432.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 432.00),
(930, 11, 37, 'EXTRA-11-37-1', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 288.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 288.00),
(931, 11, 38, 'EXTRA-11-38-1', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 240.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 240.00),
(932, 12, 39, 'EXTRA-12-39-1', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(933, 12, 40, 'EXTRA-12-40-1', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(934, 12, 41, 'EXTRA-12-41-1', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(935, 13, 42, 'EXTRA-13-42-1', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 270.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 270.00),
(936, 13, 43, 'EXTRA-13-43-1', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 180.00),
(937, 13, 44, 'EXTRA-13-44-1', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 150.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 150.00),
(938, 14, 45, 'EXTRA-14-45-1', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 396.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 396.00),
(939, 14, 46, 'EXTRA-14-46-1', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 264.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 264.00),
(940, 14, 47, 'EXTRA-14-47-1', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 220.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 220.00),
(941, 15, 48, 'EXTRA-15-48-1', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(942, 15, 49, 'EXTRA-15-49-1', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(943, 15, 50, 'EXTRA-15-50-1', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(944, 16, 51, 'EXTRA-16-51-1', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 450.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 450.00),
(945, 16, 52, 'EXTRA-16-52-1', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 300.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 300.00),
(946, 16, 53, 'EXTRA-16-53-1', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 250.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 250.00),
(947, 17, 54, 'EXTRA-17-54-1', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 468.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 468.00),
(948, 17, 55, 'EXTRA-17-55-1', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 312.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 312.00),
(949, 17, 56, 'EXTRA-17-56-1', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 260.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 260.00),
(950, 18, 57, 'EXTRA-18-57-1', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(951, 18, 58, 'EXTRA-18-58-1', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(952, 18, 59, 'EXTRA-18-59-1', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(953, 19, 60, 'EXTRA-19-60-1', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 666.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 666.00),
(954, 19, 61, 'EXTRA-19-61-1', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 444.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 444.00),
(955, 19, 62, 'EXTRA-19-62-1', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 370.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 370.00),
(956, 20, 63, 'EXTRA-20-63-1', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 252.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 252.00),
(957, 20, 64, 'EXTRA-20-64-1', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 168.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 168.00);
INSERT INTO `rooms` (`id`, `hotel_id`, `room_type_id`, `room_number`, `room_type`, `floor_number`, `status`, `created_at`, `updated_at`, `bed_count`, `price_per_night`, `amenities`, `image_url`, `type`, `capacity`, `price`) VALUES
(958, 20, 65, 'EXTRA-20-65-1', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 140.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 140.00),
(959, 21, 66, 'EXTRA-21-66-1', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 720.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 720.00),
(960, 21, 67, 'EXTRA-21-67-1', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 480.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 480.00),
(961, 21, 68, 'EXTRA-21-68-1', 'Chambre Standard', 2, 'maintenance', '2025-06-10 18:16:56', '2025-06-26 12:20:48', 2, 400.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 400.00),
(962, 1, 1, 'EXTRA-1-1-2', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1200.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Minibar premium\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Royale', 4, 1200.00),
(963, 1, 2, 'EXTRA-1-2-2', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 890.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoirs\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Deluxe', 2, 890.00),
(964, 1, 3, 'EXTRA-1-3-2', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Standard', 2, 650.00),
(965, 1, 6, 'EXTRA-1-6-2', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1170.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1170.00),
(966, 1, 7, 'EXTRA-1-7-2', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 780.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 780.00),
(967, 1, 8, 'EXTRA-1-8-2', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 650.00),
(968, 1, 71, 'EXTRA-1-71-2', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 3500.00, '[\"Balcon privé\", \"Jacuzzi\", \"Service en chambre 24h\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Royale', 4, 3500.00),
(969, 1, 72, 'EXTRA-1-72-2', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 2500.00, '[\"Terrasse\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Deluxe', 2, 2500.00),
(970, 2, 4, 'EXTRA-2-4-2', 'Riad Présidentiel', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 2500.00, '[\"Piscine privée\", \"Majordome 24h\", \"Cuisine équipée\", \"Terrasse panoramique\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=800', 'Riad Présidentiel', 6, 2500.00),
(971, 2, 5, 'EXTRA-2-5-2', 'Suite Riad', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 1250.00, '[\"Salon marocain\", \"Cheminée\", \"Terrasse privée\", \"Service thé\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Riad', 3, 1250.00),
(972, 2, 9, 'EXTRA-2-9-2', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1530.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1530.00),
(973, 2, 10, 'EXTRA-2-10-2', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1020.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 1020.00),
(974, 2, 11, 'EXTRA-2-11-2', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 850.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 850.00),
(975, 2, 73, 'EXTRA-2-73-2', 'Suite Traditionnelle', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 2200.00, '[\"Climatisation\", \"TV\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Traditionnelle', 3, 2200.00),
(976, 3, 12, 'EXTRA-3-12-2', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1260.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1260.00),
(977, 3, 13, 'EXTRA-3-13-2', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 840.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 840.00),
(978, 3, 14, 'EXTRA-3-14-2', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 700.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 700.00),
(979, 3, 74, 'EXTRA-3-74-2', 'Chambre Vue Mer', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1400.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Vue Mer', 2, 1400.00),
(980, 3, 75, 'EXTRA-3-75-2', 'Suite Familiale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 1800.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\", \"Kitchenette\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Familiale', 6, 1800.00),
(981, 4, 15, 'EXTRA-4-15-2', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1296.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1296.00),
(982, 4, 16, 'EXTRA-4-16-2', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 864.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 864.00),
(983, 4, 17, 'EXTRA-4-17-2', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 720.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 720.00),
(984, 4, 76, 'EXTRA-4-76-2', 'Chalet Montagne', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1100.00, '[\"Cheminée\", \"Chauffage\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chalet Montagne', 4, 1100.00),
(985, 5, 77, 'EXTRA-5-18-2', 'Suite Royale', 1, 'occupied', '2025-06-10 18:16:56', '2025-06-26 22:11:21', 4, 1044.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1044.00),
(986, 5, 19, 'EXTRA-5-19-2', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 696.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 696.00),
(987, 5, 20, 'EXTRA-5-20-2', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 580.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 580.00),
(988, 6, 21, 'EXTRA-6-21-2', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 576.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 576.00),
(989, 6, 22, 'EXTRA-6-22-2', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 384.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 384.00),
(990, 6, 23, 'EXTRA-6-23-2', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 320.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 320.00),
(991, 7, 24, 'EXTRA-7-24-2', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 882.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 882.00),
(992, 7, 25, 'EXTRA-7-25-2', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 588.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 588.00),
(993, 7, 26, 'EXTRA-7-26-2', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 490.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 490.00),
(994, 8, 27, 'EXTRA-8-27-2', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(995, 8, 28, 'EXTRA-8-28-2', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(996, 8, 29, 'EXTRA-8-29-2', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(997, 9, 30, 'EXTRA-9-30-2', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 738.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 738.00),
(998, 9, 31, 'EXTRA-9-31-2', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 492.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 492.00),
(999, 9, 32, 'EXTRA-9-32-2', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 410.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 410.00),
(1000, 9, 70, 'EXTRA-9-70-2', 'Simple', 5, 'available', '2025-06-10 18:16:56', '2025-07-02 16:36:39', 2, 330.00, NULL, 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Simple', 2, 0.00),
(1001, 10, 33, 'EXTRA-10-33-2', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 522.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 522.00),
(1002, 10, 34, 'EXTRA-10-34-2', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 348.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 348.00),
(1003, 10, 35, 'EXTRA-10-35-2', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 290.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 290.00),
(1004, 11, 36, 'EXTRA-11-36-2', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 432.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 432.00),
(1005, 11, 37, 'EXTRA-11-37-2', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 288.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 288.00),
(1006, 11, 38, 'EXTRA-11-38-2', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 240.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 240.00),
(1007, 12, 39, 'EXTRA-12-39-2', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(1008, 12, 40, 'EXTRA-12-40-2', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(1009, 12, 41, 'EXTRA-12-41-2', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(1010, 13, 42, 'EXTRA-13-42-2', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 270.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 270.00),
(1011, 13, 43, 'EXTRA-13-43-2', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 180.00),
(1012, 13, 44, 'EXTRA-13-44-2', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 150.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 150.00),
(1013, 14, 45, 'EXTRA-14-45-2', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 396.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 396.00),
(1014, 14, 46, 'EXTRA-14-46-2', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 264.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 264.00),
(1015, 14, 47, 'EXTRA-14-47-2', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 220.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 220.00),
(1016, 15, 48, 'EXTRA-15-48-2', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(1017, 15, 49, 'EXTRA-15-49-2', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(1018, 15, 50, 'EXTRA-15-50-2', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(1019, 16, 51, 'EXTRA-16-51-2', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 450.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 450.00),
(1020, 16, 52, 'EXTRA-16-52-2', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 300.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 300.00),
(1021, 16, 53, 'EXTRA-16-53-2', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 250.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 250.00),
(1022, 17, 54, 'EXTRA-17-54-2', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 468.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 468.00),
(1023, 17, 55, 'EXTRA-17-55-2', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 312.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 312.00),
(1024, 17, 56, 'EXTRA-17-56-2', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 260.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 260.00),
(1025, 18, 57, 'EXTRA-18-57-2', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(1026, 18, 58, 'EXTRA-18-58-2', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(1027, 18, 59, 'EXTRA-18-59-2', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(1028, 19, 60, 'EXTRA-19-60-2', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 666.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 666.00),
(1029, 19, 61, 'EXTRA-19-61-2', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 444.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 444.00),
(1030, 19, 62, 'EXTRA-19-62-2', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 370.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 370.00),
(1031, 20, 63, 'EXTRA-20-63-2', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 252.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 252.00),
(1032, 20, 64, 'EXTRA-20-64-2', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 168.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 168.00),
(1033, 20, 65, 'EXTRA-20-65-2', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 140.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 140.00),
(1034, 21, 66, 'EXTRA-21-66-2', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 720.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 720.00),
(1035, 21, 67, 'EXTRA-21-67-2', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 480.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 480.00),
(1036, 21, 68, 'EXTRA-21-68-2', 'Chambre Standard', 1, 'maintenance', '2025-06-10 18:16:56', '2025-06-26 12:20:48', 2, 400.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 400.00),
(1037, 1, 1, 'EXTRA-1-1-3', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1200.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Minibar premium\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Royale', 4, 1200.00),
(1038, 1, 2, 'EXTRA-1-2-3', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 890.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoirs\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Deluxe', 2, 890.00),
(1039, 1, 3, 'EXTRA-1-3-3', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Standard', 2, 650.00),
(1040, 1, 6, 'EXTRA-1-6-3', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1170.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1170.00),
(1041, 1, 7, 'EXTRA-1-7-3', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 780.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 780.00),
(1042, 1, 8, 'EXTRA-1-8-3', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 650.00),
(1043, 1, 71, 'EXTRA-1-71-3', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 3500.00, '[\"Balcon privé\", \"Jacuzzi\", \"Service en chambre 24h\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Royale', 4, 3500.00),
(1044, 1, 72, 'EXTRA-1-72-3', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 2500.00, '[\"Terrasse\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Deluxe', 2, 2500.00),
(1045, 2, 4, 'EXTRA-2-4-3', 'Riad Présidentiel', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 2500.00, '[\"Piscine privée\", \"Majordome 24h\", \"Cuisine équipée\", \"Terrasse panoramique\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=800', 'Riad Présidentiel', 6, 2500.00),
(1046, 2, 5, 'EXTRA-2-5-3', 'Suite Riad', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 1250.00, '[\"Salon marocain\", \"Cheminée\", \"Terrasse privée\", \"Service thé\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Riad', 3, 1250.00),
(1047, 2, 9, 'EXTRA-2-9-3', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1530.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1530.00),
(1048, 2, 10, 'EXTRA-2-10-3', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1020.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 1020.00),
(1049, 2, 11, 'EXTRA-2-11-3', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 850.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 850.00),
(1050, 2, 73, 'EXTRA-2-73-3', 'Suite Traditionnelle', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 2200.00, '[\"Climatisation\", \"TV\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Traditionnelle', 3, 2200.00),
(1051, 3, 12, 'EXTRA-3-12-3', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1260.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1260.00),
(1052, 3, 13, 'EXTRA-3-13-3', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 840.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 840.00),
(1053, 3, 14, 'EXTRA-3-14-3', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 700.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 700.00),
(1054, 3, 74, 'EXTRA-3-74-3', 'Chambre Vue Mer', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1400.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Vue Mer', 2, 1400.00),
(1055, 3, 75, 'EXTRA-3-75-3', 'Suite Familiale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 1800.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\", \"Kitchenette\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Familiale', 6, 1800.00),
(1056, 4, 15, 'EXTRA-4-15-3', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1296.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1296.00),
(1057, 4, 16, 'EXTRA-4-16-3', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 864.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 864.00),
(1058, 4, 17, 'EXTRA-4-17-3', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 720.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 720.00),
(1059, 4, 76, 'EXTRA-4-76-3', 'Chalet Montagne', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1100.00, '[\"Cheminée\", \"Chauffage\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chalet Montagne', 4, 1100.00),
(1060, 5, 18, 'EXTRA-5-18-3', 'Suite Royale', 2, 'maintenance', '2025-06-10 18:16:56', '2025-06-26 22:11:41', 4, 1044.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1044.00),
(1061, 5, 19, 'EXTRA-5-19-3', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 696.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 696.00),
(1062, 5, 20, 'EXTRA-5-20-3', 'Chambre Standard', 3, 'occupied', '2025-06-10 18:16:56', '2025-06-26 12:20:08', 2, 580.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 580.00),
(1063, 6, 21, 'EXTRA-6-21-3', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 576.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 576.00),
(1064, 6, 22, 'EXTRA-6-22-3', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 384.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 384.00),
(1065, 6, 23, 'EXTRA-6-23-3', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 320.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 320.00),
(1066, 7, 24, 'EXTRA-7-24-3', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 882.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 882.00),
(1067, 7, 25, 'EXTRA-7-25-3', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 588.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 588.00),
(1068, 7, 26, 'EXTRA-7-26-3', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 490.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 490.00),
(1069, 8, 27, 'EXTRA-8-27-3', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(1070, 8, 28, 'EXTRA-8-28-3', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(1071, 8, 29, 'EXTRA-8-29-3', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(1072, 9, 30, 'EXTRA-9-30-3', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 738.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 738.00),
(1073, 9, 31, 'EXTRA-9-31-3', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 492.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 492.00),
(1074, 9, 32, 'EXTRA-9-32-3', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 410.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 410.00),
(1075, 9, 70, 'EXTRA-9-70-3', 'Simple', 3, 'available', '2025-06-10 18:16:56', '2025-07-02 16:36:00', 2, 550.00, NULL, 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Simple', 2, 0.00),
(1076, 10, 33, 'EXTRA-10-33-3', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 522.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 522.00),
(1077, 10, 34, 'EXTRA-10-34-3', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 348.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 348.00),
(1078, 10, 35, 'EXTRA-10-35-3', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 290.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 290.00),
(1079, 11, 36, 'EXTRA-11-36-3', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 432.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 432.00),
(1080, 11, 37, 'EXTRA-11-37-3', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 288.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 288.00),
(1081, 11, 38, 'EXTRA-11-38-3', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 240.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 240.00),
(1082, 12, 39, 'EXTRA-12-39-3', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(1083, 12, 40, 'EXTRA-12-40-3', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(1084, 12, 41, 'EXTRA-12-41-3', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(1085, 13, 42, 'EXTRA-13-42-3', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 270.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 270.00),
(1086, 13, 43, 'EXTRA-13-43-3', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 180.00),
(1087, 13, 44, 'EXTRA-13-44-3', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 150.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 150.00),
(1088, 14, 45, 'EXTRA-14-45-3', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 396.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 396.00),
(1089, 14, 46, 'EXTRA-14-46-3', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 264.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 264.00),
(1090, 14, 47, 'EXTRA-14-47-3', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 220.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 220.00),
(1091, 15, 48, 'EXTRA-15-48-3', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(1092, 15, 49, 'EXTRA-15-49-3', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(1093, 15, 50, 'EXTRA-15-50-3', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(1094, 16, 51, 'EXTRA-16-51-3', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 450.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 450.00),
(1095, 16, 52, 'EXTRA-16-52-3', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 300.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 300.00),
(1096, 16, 53, 'EXTRA-16-53-3', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 250.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 250.00),
(1097, 17, 54, 'EXTRA-17-54-3', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 468.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 468.00),
(1098, 17, 55, 'EXTRA-17-55-3', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 312.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 312.00),
(1099, 17, 56, 'EXTRA-17-56-3', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 260.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 260.00),
(1100, 18, 57, 'EXTRA-18-57-3', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(1101, 18, 58, 'EXTRA-18-58-3', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(1102, 18, 59, 'EXTRA-18-59-3', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(1103, 19, 60, 'EXTRA-19-60-3', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 666.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 666.00),
(1104, 19, 61, 'EXTRA-19-61-3', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 444.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 444.00),
(1105, 19, 62, 'EXTRA-19-62-3', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 370.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 370.00),
(1106, 20, 63, 'EXTRA-20-63-3', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 252.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 252.00),
(1107, 20, 64, 'EXTRA-20-64-3', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 168.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 168.00),
(1108, 20, 65, 'EXTRA-20-65-3', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 140.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 140.00),
(1109, 21, 66, 'EXTRA-21-66-3', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 720.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 720.00),
(1110, 21, 67, 'EXTRA-21-67-3', 'Chambre Deluxe', 3, 'maintenance', '2025-06-10 18:16:56', '2025-06-26 12:20:48', 2, 480.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 480.00),
(1111, 21, 68, 'EXTRA-21-68-3', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 400.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 400.00),
(1112, 1, 1, 'EXTRA-1-1-4', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1200.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Minibar premium\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Royale', 4, 1200.00),
(1113, 1, 2, 'EXTRA-1-2-4', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 890.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoirs\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Deluxe', 2, 890.00),
(1114, 1, 3, 'EXTRA-1-3-4', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Standard', 2, 650.00),
(1115, 1, 6, 'EXTRA-1-6-4', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1170.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1170.00);
INSERT INTO `rooms` (`id`, `hotel_id`, `room_type_id`, `room_number`, `room_type`, `floor_number`, `status`, `created_at`, `updated_at`, `bed_count`, `price_per_night`, `amenities`, `image_url`, `type`, `capacity`, `price`) VALUES
(1116, 1, 7, 'EXTRA-1-7-4', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 780.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 780.00),
(1117, 1, 8, 'EXTRA-1-8-4', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 650.00),
(1118, 1, 71, 'EXTRA-1-71-4', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 3500.00, '[\"Balcon privé\", \"Jacuzzi\", \"Service en chambre 24h\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Royale', 4, 3500.00),
(1119, 1, 72, 'EXTRA-1-72-4', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 2500.00, '[\"Terrasse\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Deluxe', 2, 2500.00),
(1120, 2, 4, 'EXTRA-2-4-4', 'Riad Présidentiel', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 2500.00, '[\"Piscine privée\", \"Majordome 24h\", \"Cuisine équipée\", \"Terrasse panoramique\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=800', 'Riad Présidentiel', 6, 2500.00),
(1121, 2, 5, 'EXTRA-2-5-4', 'Suite Riad', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 1250.00, '[\"Salon marocain\", \"Cheminée\", \"Terrasse privée\", \"Service thé\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Riad', 3, 1250.00),
(1122, 2, 9, 'EXTRA-2-9-4', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1530.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1530.00),
(1123, 2, 10, 'EXTRA-2-10-4', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1020.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 1020.00),
(1124, 2, 11, 'EXTRA-2-11-4', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 850.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 850.00),
(1125, 2, 73, 'EXTRA-2-73-4', 'Suite Traditionnelle', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 2200.00, '[\"Climatisation\", \"TV\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Traditionnelle', 3, 2200.00),
(1126, 3, 12, 'EXTRA-3-12-4', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1260.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1260.00),
(1127, 3, 13, 'EXTRA-3-13-4', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 840.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 840.00),
(1128, 3, 14, 'EXTRA-3-14-4', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 700.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 700.00),
(1129, 3, 74, 'EXTRA-3-74-4', 'Chambre Vue Mer', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1400.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Vue Mer', 2, 1400.00),
(1130, 3, 75, 'EXTRA-3-75-4', 'Suite Familiale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 1800.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\", \"Kitchenette\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Familiale', 6, 1800.00),
(1131, 4, 15, 'EXTRA-4-15-4', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1296.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1296.00),
(1132, 4, 16, 'EXTRA-4-16-4', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 864.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 864.00),
(1133, 4, 17, 'EXTRA-4-17-4', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 720.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 720.00),
(1134, 4, 76, 'EXTRA-4-76-4', 'Chalet Montagne', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1100.00, '[\"Cheminée\", \"Chauffage\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chalet Montagne', 4, 1100.00),
(1135, 5, 18, 'EXTRA-5-18-4', 'Suite Royale', 4, 'occupied', '2025-06-10 18:16:56', '2025-07-01 19:50:35', 4, 1044.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1044.00),
(1136, 5, 19, 'EXTRA-5-19-4', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 696.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 696.00),
(1137, 5, 20, 'EXTRA-5-20-4', 'Chambre Standard', 1, 'occupied', '2025-06-10 18:16:56', '2025-06-26 12:20:08', 2, 580.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 580.00),
(1138, 6, 21, 'EXTRA-6-21-4', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 576.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 576.00),
(1139, 6, 22, 'EXTRA-6-22-4', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 384.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 384.00),
(1140, 6, 23, 'EXTRA-6-23-4', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 320.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 320.00),
(1141, 7, 24, 'EXTRA-7-24-4', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 882.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 882.00),
(1142, 7, 25, 'EXTRA-7-25-4', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 588.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 588.00),
(1143, 7, 26, 'EXTRA-7-26-4', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 490.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 490.00),
(1144, 8, 27, 'EXTRA-8-27-4', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(1145, 8, 28, 'EXTRA-8-28-4', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(1146, 8, 29, 'EXTRA-8-29-4', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(1147, 9, 30, 'EXTRA-9-30-4', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 738.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 738.00),
(1148, 9, 31, 'EXTRA-9-31-4', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 492.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 492.00),
(1149, 9, 32, 'EXTRA-9-32-4', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 410.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 410.00),
(1150, 9, 70, 'EXTRA-9-70-4', 'Simple', 3, 'available', '2025-06-10 18:16:56', '2025-07-02 16:40:03', 2, 770.00, '', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Simple', 2, 0.00),
(1151, 10, 33, 'EXTRA-10-33-4', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 522.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 522.00),
(1152, 10, 34, 'EXTRA-10-34-4', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 348.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 348.00),
(1153, 10, 35, 'EXTRA-10-35-4', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 290.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 290.00),
(1154, 11, 36, 'EXTRA-11-36-4', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 432.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 432.00),
(1155, 11, 37, 'EXTRA-11-37-4', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 288.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 288.00),
(1156, 11, 38, 'EXTRA-11-38-4', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 240.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 240.00),
(1157, 12, 39, 'EXTRA-12-39-4', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(1158, 12, 40, 'EXTRA-12-40-4', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(1159, 12, 41, 'EXTRA-12-41-4', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(1160, 13, 42, 'EXTRA-13-42-4', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 270.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 270.00),
(1161, 13, 43, 'EXTRA-13-43-4', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 180.00),
(1162, 13, 44, 'EXTRA-13-44-4', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 150.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 150.00),
(1163, 14, 45, 'EXTRA-14-45-4', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 396.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 396.00),
(1164, 14, 46, 'EXTRA-14-46-4', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 264.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 264.00),
(1165, 14, 47, 'EXTRA-14-47-4', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 220.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 220.00),
(1166, 15, 48, 'EXTRA-15-48-4', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(1167, 15, 49, 'EXTRA-15-49-4', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(1168, 15, 50, 'EXTRA-15-50-4', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(1169, 16, 51, 'EXTRA-16-51-4', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 450.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 450.00),
(1170, 16, 52, 'EXTRA-16-52-4', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 300.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 300.00),
(1171, 16, 53, 'EXTRA-16-53-4', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 250.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 250.00),
(1172, 17, 54, 'EXTRA-17-54-4', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 468.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 468.00),
(1173, 17, 55, 'EXTRA-17-55-4', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 312.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 312.00),
(1174, 17, 56, 'EXTRA-17-56-4', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 260.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 260.00),
(1175, 18, 57, 'EXTRA-18-57-4', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(1176, 18, 58, 'EXTRA-18-58-4', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(1177, 18, 59, 'EXTRA-18-59-4', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(1178, 19, 60, 'EXTRA-19-60-4', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 666.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 666.00),
(1179, 19, 61, 'EXTRA-19-61-4', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 444.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 444.00),
(1180, 19, 62, 'EXTRA-19-62-4', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 370.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 370.00),
(1181, 20, 63, 'EXTRA-20-63-4', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 252.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 252.00),
(1182, 20, 64, 'EXTRA-20-64-4', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 168.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 168.00),
(1183, 20, 65, 'EXTRA-20-65-4', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 140.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 140.00),
(1184, 21, 66, 'EXTRA-21-66-4', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 720.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 720.00),
(1185, 21, 67, 'EXTRA-21-67-4', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 480.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 480.00),
(1186, 21, 68, 'EXTRA-21-68-4', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 400.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 400.00),
(1187, 1, 1, 'EXTRA-1-1-5', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1200.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Minibar premium\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Royale', 4, 1200.00),
(1188, 1, 2, 'EXTRA-1-2-5', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 890.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoirs\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Deluxe', 2, 890.00),
(1189, 1, 3, 'EXTRA-1-3-5', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=800', 'Chambre Standard', 2, 650.00),
(1190, 1, 6, 'EXTRA-1-6-5', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1170.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1170.00),
(1191, 1, 7, 'EXTRA-1-7-5', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 780.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 780.00),
(1192, 1, 8, 'EXTRA-1-8-5', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 650.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 650.00),
(1193, 1, 71, 'EXTRA-1-71-5', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 3500.00, '[\"Balcon privé\", \"Jacuzzi\", \"Service en chambre 24h\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Royale', 4, 3500.00),
(1194, 1, 72, 'EXTRA-1-72-5', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 2500.00, '[\"Terrasse\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Deluxe', 2, 2500.00),
(1195, 2, 4, 'EXTRA-2-4-5', 'Riad Présidentiel', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 6, 2500.00, '[\"Piscine privée\", \"Majordome 24h\", \"Cuisine équipée\", \"Terrasse panoramique\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=800', 'Riad Présidentiel', 6, 2500.00),
(1196, 2, 5, 'EXTRA-2-5-5', 'Suite Riad', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 1250.00, '[\"Salon marocain\", \"Cheminée\", \"Terrasse privée\", \"Service thé\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', 'Suite Riad', 3, 1250.00),
(1197, 2, 9, 'EXTRA-2-9-5', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1530.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1530.00),
(1198, 2, 10, 'EXTRA-2-10-5', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1020.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 1020.00),
(1199, 2, 11, 'EXTRA-2-11-5', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 850.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 850.00),
(1200, 2, 73, 'EXTRA-2-73-5', 'Suite Traditionnelle', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 3, 2200.00, '[\"Climatisation\", \"TV\", \"Minibar\", \"Coffre-fort\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Traditionnelle', 3, 2200.00),
(1201, 3, 12, 'EXTRA-3-12-5', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1260.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1260.00),
(1202, 3, 13, 'EXTRA-3-13-5', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 840.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 840.00),
(1203, 3, 14, 'EXTRA-3-14-5', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 700.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 700.00),
(1204, 3, 74, 'EXTRA-3-74-5', 'Chambre Vue Mer', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 1400.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chambre Vue Mer', 2, 1400.00),
(1205, 3, 75, 'EXTRA-3-75-5', 'Suite Familiale', 1, 'maintenance', '2025-06-10 18:16:56', '2025-06-26 12:20:48', 6, 1800.00, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\", \"Kitchenette\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Suite Familiale', 6, 1800.00),
(1206, 4, 15, 'EXTRA-4-15-5', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1296.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1296.00),
(1207, 4, 16, 'EXTRA-4-16-5', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 864.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 864.00),
(1208, 4, 17, 'EXTRA-4-17-5', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 720.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 720.00),
(1209, 4, 76, 'EXTRA-4-76-5', 'Chalet Montagne', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1100.00, '[\"Cheminée\", \"Chauffage\", \"TV\", \"Minibar\"]', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Chalet Montagne', 4, 1100.00),
(1210, 5, 18, 'EXTRA-5-18-5', 'Suite Royale', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 1044.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 1044.00),
(1211, 5, 19, 'EXTRA-5-19-5', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 696.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 696.00),
(1212, 5, 20, 'EXTRA-5-20-5', 'Chambre Standard', 3, 'occupied', '2025-06-10 18:16:56', '2025-06-26 12:20:08', 2, 580.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 580.00),
(1213, 6, 21, 'EXTRA-6-21-5', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 576.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 576.00),
(1214, 6, 22, 'EXTRA-6-22-5', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 384.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 384.00),
(1215, 6, 23, 'EXTRA-6-23-5', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 320.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 320.00),
(1216, 7, 24, 'EXTRA-7-24-5', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 882.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 882.00),
(1217, 7, 25, 'EXTRA-7-25-5', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 588.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 588.00),
(1218, 7, 26, 'EXTRA-7-26-5', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 490.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 490.00),
(1219, 8, 27, 'EXTRA-8-27-5', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(1220, 8, 28, 'EXTRA-8-28-5', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(1221, 8, 29, 'EXTRA-8-29-5', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(1222, 9, 30, 'EXTRA-9-30-5', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 738.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 738.00),
(1223, 9, 31, 'EXTRA-9-31-5', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 492.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 492.00),
(1224, 9, 32, 'EXTRA-9-32-5', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 410.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 410.00),
(1225, 9, 70, 'EXTRA-9-70-5', 'Simple', 5, 'available', '2025-06-10 18:16:56', '2025-07-02 16:35:28', 2, 430.00, NULL, 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', 'Simple', 2, 0.00),
(1226, 10, 33, 'EXTRA-10-33-5', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 522.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 522.00),
(1227, 10, 34, 'EXTRA-10-34-5', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 348.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 348.00),
(1228, 10, 35, 'EXTRA-10-35-5', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 290.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 290.00),
(1229, 11, 36, 'EXTRA-11-36-5', 'Suite Royale', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 432.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 432.00),
(1230, 11, 37, 'EXTRA-11-37-5', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 288.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 288.00),
(1231, 11, 38, 'EXTRA-11-38-5', 'Chambre Standard', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 240.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 240.00),
(1232, 12, 39, 'EXTRA-12-39-5', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(1233, 12, 40, 'EXTRA-12-40-5', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(1234, 12, 41, 'EXTRA-12-41-5', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(1235, 13, 42, 'EXTRA-13-42-5', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 270.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 270.00),
(1236, 13, 43, 'EXTRA-13-43-5', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 180.00),
(1237, 13, 44, 'EXTRA-13-44-5', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 150.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 150.00),
(1238, 14, 45, 'EXTRA-14-45-5', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 396.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 396.00),
(1239, 14, 46, 'EXTRA-14-46-5', 'Chambre Deluxe', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 264.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 264.00),
(1240, 14, 47, 'EXTRA-14-47-5', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 220.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 220.00),
(1241, 15, 48, 'EXTRA-15-48-5', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 630.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 630.00),
(1242, 15, 49, 'EXTRA-15-49-5', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 420.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 420.00),
(1243, 15, 50, 'EXTRA-15-50-5', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 350.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 350.00),
(1244, 16, 51, 'EXTRA-16-51-5', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 450.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 450.00),
(1245, 16, 52, 'EXTRA-16-52-5', 'Chambre Deluxe', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 300.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 300.00),
(1246, 16, 53, 'EXTRA-16-53-5', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 250.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 250.00),
(1247, 17, 54, 'EXTRA-17-54-5', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 468.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 468.00),
(1248, 17, 55, 'EXTRA-17-55-5', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 312.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 312.00),
(1249, 17, 56, 'EXTRA-17-56-5', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 260.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 260.00),
(1250, 18, 57, 'EXTRA-18-57-5', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 324.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 324.00),
(1251, 18, 58, 'EXTRA-18-58-5', 'Chambre Deluxe', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 216.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 216.00),
(1252, 18, 59, 'EXTRA-18-59-5', 'Chambre Standard', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 180.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 180.00),
(1253, 19, 60, 'EXTRA-19-60-5', 'Suite Royale', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 666.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 666.00),
(1254, 19, 61, 'EXTRA-19-61-5', 'Chambre Deluxe', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 444.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 444.00),
(1255, 19, 62, 'EXTRA-19-62-5', 'Chambre Standard', 1, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 370.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 370.00),
(1256, 20, 63, 'EXTRA-20-63-5', 'Suite Royale', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 252.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 252.00),
(1257, 20, 64, 'EXTRA-20-64-5', 'Chambre Deluxe', 3, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 168.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 168.00),
(1258, 20, 65, 'EXTRA-20-65-5', 'Chambre Standard', 2, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 140.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 140.00),
(1259, 21, 66, 'EXTRA-21-66-5', 'Suite Royale', 5, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 4, 720.00, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', 'Suite Royale', 4, 720.00),
(1260, 21, 67, 'EXTRA-21-67-5', 'Chambre Deluxe', 4, 'maintenance', '2025-06-10 18:16:56', '2025-06-26 12:20:48', 2, 480.00, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Deluxe', 2, 480.00),
(1261, 21, 68, 'EXTRA-21-68-5', 'Chambre Standard', 4, 'available', '2025-06-10 18:16:56', '2025-06-10 18:16:56', 2, 400.00, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', 'Chambre Standard', 2, 400.00),
(1262, 32, 81, '105', 'Triple', NULL, 'available', '2025-07-03 08:33:56', '2025-07-03 08:33:56', 1, 1200.00, 'b', 'https://images.pexels.com/photos/271624/pexels-photo-271624.jpeg', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Structure de la table `room_availability`
--

CREATE TABLE `room_availability` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `date_available` date NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `date` date NOT NULL,
  `status` enum('available','booked','maintenance') DEFAULT 'available',
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `price_override` decimal(10,2) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `room_availability`
--

INSERT INTO `room_availability` (`id`, `room_id`, `date_available`, `booking_id`, `date`, `status`, `reason`, `created_at`, `price_override`, `updated_at`) VALUES
(24584, 765, '0000-00-00', 11, '2025-06-10', 'booked', NULL, '2025-06-10 18:18:04', NULL, '2025-06-10 20:16:41'),
(24585, 766, '0000-00-00', 12, '2025-06-10', 'booked', NULL, '2025-06-10 20:28:32', NULL, '2025-06-10 20:28:32'),
(24587, 778, '0000-00-00', 14, '2025-06-11', 'booked', NULL, '2025-06-11 11:09:38', NULL, '2025-06-11 11:09:38'),
(24588, 778, '0000-00-00', 14, '2025-06-12', 'booked', NULL, '2025-06-11 11:09:38', NULL, '2025-06-11 11:09:38'),
(24589, 778, '0000-00-00', 14, '2025-06-13', 'booked', NULL, '2025-06-11 11:09:38', NULL, '2025-06-11 11:09:38'),
(24590, 778, '0000-00-00', 14, '2025-06-14', 'booked', NULL, '2025-06-11 11:09:38', NULL, '2025-06-11 11:09:38'),
(24591, 778, '0000-00-00', 14, '2025-06-15', 'booked', NULL, '2025-06-11 11:09:38', NULL, '2025-06-11 11:09:38'),
(24592, 778, '0000-00-00', 14, '2025-06-16', 'booked', NULL, '2025-06-11 11:09:38', NULL, '2025-06-11 11:09:38'),
(24593, 778, '0000-00-00', 14, '2025-06-17', 'booked', NULL, '2025-06-11 11:09:38', NULL, '2025-06-11 11:09:38'),
(24594, 778, '0000-00-00', 14, '2025-06-18', 'booked', NULL, '2025-06-11 11:09:38', NULL, '2025-06-11 11:09:38'),
(24595, 762, '0000-00-00', 15, '2025-06-11', 'booked', NULL, '2025-06-11 17:01:35', NULL, '2025-06-11 17:01:35'),
(24596, 772, '0000-00-00', 16, '2025-06-11', 'booked', NULL, '2025-06-11 17:18:29', NULL, '2025-06-11 17:18:29'),
(24597, 811, '0000-00-00', 17, '2025-06-11', 'booked', NULL, '2025-06-11 17:21:28', NULL, '2025-06-11 17:21:28'),
(24598, 147, '0000-00-00', NULL, '2025-06-27', 'available', '', '2025-06-26 12:17:09', 1000.00, '2025-06-26 12:17:09'),
(24599, 147, '0000-00-00', NULL, '2025-06-28', 'available', '', '2025-06-26 12:17:09', 1000.00, '2025-06-26 12:17:09'),
(24600, 147, '0000-00-00', NULL, '2025-06-29', 'available', '', '2025-06-26 12:17:09', 1000.00, '2025-06-26 12:17:09'),
(24601, 147, '0000-00-00', NULL, '2025-06-30', 'available', '', '2025-06-26 12:17:09', 1000.00, '2025-06-26 12:17:09'),
(24602, 147, '0000-00-00', NULL, '2025-07-01', 'available', '', '2025-06-26 12:17:09', 1000.00, '2025-06-26 12:17:09'),
(24603, 764, '0000-00-00', 18, '2025-06-26', 'booked', NULL, '2025-06-26 13:38:37', NULL, '2025-06-26 13:38:37'),
(24604, 772, '0000-00-00', 19, '2025-06-26', 'booked', NULL, '2025-06-26 13:42:56', NULL, '2025-06-26 13:42:56'),
(24605, 811, '0000-00-00', 20, '2025-06-26', 'booked', NULL, '2025-06-26 14:55:09', NULL, '2025-06-26 14:55:09'),
(24606, 769, '0000-00-00', 21, '2025-06-27', 'booked', NULL, '2025-06-26 22:10:25', NULL, '2025-06-26 22:10:25'),
(24607, 760, '0000-00-00', 22, '2025-06-27', 'booked', NULL, '2025-06-27 11:30:25', NULL, '2025-06-27 11:30:25'),
(24608, 811, '0000-00-00', 23, '2025-06-30', 'booked', NULL, '2025-06-30 09:45:25', NULL, '2025-06-30 09:45:25'),
(24609, 762, '0000-00-00', 24, '2025-07-01', 'booked', NULL, '2025-07-01 19:44:49', NULL, '2025-07-01 19:44:49'),
(24611, 773, '0000-00-00', 32, '2025-07-02', 'booked', NULL, '2025-07-02 15:31:47', NULL, '2025-07-02 15:31:47'),
(24612, 770, '0000-00-00', 33, '2025-07-02', 'booked', NULL, '2025-07-02 15:32:19', NULL, '2025-07-02 15:32:19'),
(24613, 795, '0000-00-00', 39, '2025-07-02', 'booked', NULL, '2025-07-02 16:29:45', NULL, '2025-07-02 16:29:45'),
(24614, 772, '0000-00-00', 41, '2025-07-02', 'booked', NULL, '2025-07-02 16:47:42', NULL, '2025-07-02 16:47:42'),
(24615, 912, '0000-00-00', 42, '2025-07-02', 'booked', NULL, '2025-07-02 16:49:26', NULL, '2025-07-02 16:49:26'),
(24616, 790, '0000-00-00', 43, '2025-07-02', 'booked', NULL, '2025-07-02 16:50:16', NULL, '2025-07-02 16:50:16'),
(24617, 911, '0000-00-00', 44, '2025-07-03', 'booked', NULL, '2025-07-03 06:33:15', NULL, '2025-07-03 06:33:15'),
(24618, 813, '0000-00-00', 45, '2025-07-03', 'booked', NULL, '2025-07-03 06:38:33', NULL, '2025-07-03 06:38:33'),
(24619, 827, '0000-00-00', 46, '2025-07-03', 'booked', NULL, '2025-07-03 08:59:24', NULL, '2025-07-03 08:59:24'),
(24620, 827, '0000-00-00', 46, '2025-07-04', 'booked', NULL, '2025-07-03 08:59:24', NULL, '2025-07-03 08:59:24'),
(24621, 824, '0000-00-00', 47, '2025-07-03', 'booked', NULL, '2025-07-03 16:29:27', NULL, '2025-07-03 16:29:27');

-- --------------------------------------------------------

--
-- Doublure de structure pour la vue `room_availability_view`
-- (Voir ci-dessous la vue réelle)
--
CREATE TABLE `room_availability_view` (
`room_id` int(11)
,`hotel_id` int(11)
,`room_type_id` int(11)
,`room_number` varchar(20)
,`room_type` varchar(100)
,`available_date` date
,`status` varchar(9)
,`price_per_night` decimal(10,2)
,`room_type_name` varchar(255)
,`max_occupancy` int(11)
,`hotel_name` varchar(255)
,`city` varchar(100)
);

-- --------------------------------------------------------

--
-- Structure de la table `room_types`
--

CREATE TABLE `room_types` (
  `id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price_per_night` decimal(10,2) NOT NULL,
  `max_occupancy` int(11) NOT NULL DEFAULT 2,
  `size_sqm` int(11) DEFAULT NULL,
  `amenities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`amenities`)),
  `image_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `room_types`
--

INSERT INTO `room_types` (`id`, `hotel_id`, `name`, `description`, `price_per_night`, `max_occupancy`, `size_sqm`, `amenities`, `image_url`, `created_at`, `updated_at`) VALUES
(1, 1, 'Suite Royale', 'Suite somptueuse avec vue sur les jardins de la Mamounia', 1200.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Minibar premium\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', '2025-06-07 12:20:30', '2025-06-07 12:20:30'),
(2, 1, 'Chambre Deluxe', 'Chambre élégante avec balcon donnant sur les jardins', 890.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoirs\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=800', '2025-06-07 12:20:30', '2025-06-07 12:20:30'),
(3, 1, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne', 650.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=800', '2025-06-07 12:20:30', '2025-06-07 12:20:30'),
(4, 2, 'Riad Présidentiel', 'Riad privé avec piscine et service dédié', 2500.00, 6, 150, '[\"Piscine privée\", \"Majordome 24h\", \"Cuisine équipée\", \"Terrasse panoramique\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=800', '2025-06-07 12:20:30', '2025-06-07 12:20:30'),
(5, 2, 'Suite Riad', 'Suite spacieuse dans l\'esprit riad traditionnel', 1250.00, 3, 75, '[\"Salon marocain\", \"Cheminée\", \"Terrasse privée\", \"Service thé\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=800', '2025-06-07 12:20:30', '2025-06-07 12:20:30'),
(6, 1, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 1170.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(7, 1, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 780.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(8, 1, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 650.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(9, 2, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 1530.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(10, 2, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 1020.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(11, 2, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 850.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(12, 3, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 1260.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(13, 3, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 840.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(14, 3, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 700.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(15, 4, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 1296.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(16, 4, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 864.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(17, 4, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 720.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(18, 5, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 1044.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(19, 5, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 696.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(20, 5, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 580.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(21, 6, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 576.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(22, 6, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 384.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(23, 6, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 320.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(24, 7, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 882.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(25, 7, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 588.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(26, 7, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 490.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(27, 8, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 324.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(28, 8, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 216.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(29, 8, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 180.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(30, 9, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 738.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(31, 9, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 492.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(32, 9, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 410.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(33, 10, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 522.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(34, 10, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 348.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(35, 10, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 290.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(36, 11, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 432.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(37, 11, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 288.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(38, 11, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 240.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(39, 12, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 630.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(40, 12, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 420.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(41, 12, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 350.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(42, 13, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 270.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(43, 13, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 180.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(44, 13, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 150.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(45, 14, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 396.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(46, 14, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 264.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(47, 14, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 220.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(48, 15, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 630.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(49, 15, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 420.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(50, 15, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 350.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(51, 16, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 450.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(52, 16, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 300.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(53, 16, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 250.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(54, 17, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 468.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(55, 17, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 312.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(56, 17, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 260.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(57, 18, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 324.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(58, 18, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 216.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(59, 18, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 180.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(60, 19, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 666.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(61, 19, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 444.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(62, 19, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 370.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(63, 20, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 252.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(64, 20, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 168.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(65, 20, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 140.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(66, 21, 'Suite Royale', 'Suite somptueuse avec vue panoramique.', 720.00, 4, 85, '[\"Salon privé\", \"Terrasse\", \"Service majordome\", \"Jacuzzi\"]', 'https://images.pexels.com/photos/1134176/pexels-photo-1134176.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(67, 21, 'Chambre Deluxe', 'Chambre élégante avec balcon.', 480.00, 2, 45, '[\"Balcon privé\", \"Minibar\", \"Coffre-fort\", \"Peignoir\"]', 'https://images.pexels.com/photos/261102/pexels-photo-261102.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(68, 21, 'Chambre Standard', 'Chambre confortable avec tout le confort moderne.', 400.00, 2, 35, '[\"Climatisation\", \"TV satellite\", \"Wifi\", \"Minibar\"]', 'https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=600', '2025-06-07 13:22:20', '2025-06-07 13:22:20'),
(70, 9, 'Simple', NULL, 0.00, 2, NULL, NULL, NULL, '2025-06-08 19:44:47', '2025-06-08 19:44:47'),
(71, 1, 'Suite Royale', 'Suite luxueuse avec terrasse privée et vue panoramique', 3500.00, 4, 85, '[\"Balcon privé\", \"Jacuzzi\", \"Service en chambre 24h\"]', NULL, '2025-06-10 09:52:05', '2025-06-10 09:52:05'),
(72, 1, 'Chambre Deluxe', 'Chambre élégante avec vue sur les jardins', 2500.00, 2, 45, '[\"Terrasse\", \"Minibar\", \"Coffre-fort\"]', NULL, '2025-06-10 09:52:05', '2025-06-10 09:52:05'),
(73, 2, 'Suite Traditionnelle', 'Suite avec décoration marocaine authentique', 2200.00, 3, 60, '[\"Climatisation\", \"TV\", \"Minibar\", \"Coffre-fort\"]', NULL, '2025-06-10 09:52:05', '2025-06-10 09:52:05'),
(74, 3, 'Chambre Vue Mer', 'Chambre avec balcon et vue directe sur l\'océan', 1400.00, 2, 35, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\"]', NULL, '2025-06-10 09:52:05', '2025-06-10 09:52:05'),
(75, 3, 'Suite Familiale', 'Suite spacieuse idéale pour les familles', 1800.00, 6, 70, '[\"Balcon\", \"Climatisation\", \"TV\", \"Minibar\", \"Kitchenette\"]', NULL, '2025-06-10 09:52:05', '2025-06-10 09:52:05'),
(76, 4, 'Chalet Montagne', 'Chalet confortable avec cheminée et vue montagne', 1100.00, 4, 50, '[\"Cheminée\", \"Chauffage\", \"TV\", \"Minibar\"]', NULL, '2025-06-10 09:52:05', '2025-06-10 09:52:05'),
(77, 5, 'Simple', NULL, 540.00, 1, NULL, NULL, NULL, '2025-06-21 15:46:11', '2025-06-21 15:46:11'),
(78, 5, 'Double', NULL, 1044.00, 4, NULL, NULL, NULL, '2025-06-21 15:47:51', '2025-06-21 15:47:51'),
(79, 5, 'Suite', NULL, 696.00, 2, NULL, NULL, NULL, '2025-06-26 13:48:21', '2025-06-26 13:48:21'),
(80, 5, 'Triple', NULL, 540.00, 1, NULL, NULL, NULL, '2025-07-01 19:50:21', '2025-07-01 19:50:21'),
(81, 32, 'Triple', NULL, 1200.00, 1, NULL, NULL, NULL, '2025-07-03 08:33:56', '2025-07-03 08:33:56');

-- --------------------------------------------------------

--
-- Structure de la table `search_logs`
--

CREATE TABLE `search_logs` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `search_params` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`search_params`)),
  `results_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `search_logs`
--

INSERT INTO `search_logs` (`id`, `ip_address`, `search_params`, `results_count`, `created_at`) VALUES
(1, '::1', '{\"destination\":\"Agadir\",\"check_in\":\"2025-06-10\",\"check_out\":\"2025-06-11\",\"guests\":2,\"min_price\":0,\"max_price\":0,\"room_type\":\"\"}', 0, '2025-06-10 09:52:53');

-- --------------------------------------------------------

--
-- Structure de la table `search_sessions`
--

CREATE TABLE `search_sessions` (
  `id` int(11) NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `search_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`search_data`)),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `security_logs`
--

CREATE TABLE `security_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `severity` enum('low','medium','high','critical') DEFAULT 'low',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `security_logs`
--

INSERT INTO `security_logs` (`id`, `user_id`, `ip_address`, `user_agent`, `action`, `details`, `severity`, `created_at`) VALUES
(1, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-09 13:22:58'),
(2, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-11 11:52:38'),
(3, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'profile_update', '{\"updated_fields\":[\"full_name\",\"phone\",\"address\",\"date_of_birth\"]}', 'low', '2025-06-11 11:52:38'),
(4, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-26 22:09:29'),
(5, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'profile_update', '{\"updated_fields\":[\"full_name\",\"phone\",\"address\",\"date_of_birth\"]}', 'low', '2025-06-26 22:09:29'),
(6, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'payment_completed', '{\"booking_id\":\"21\",\"amount\":1250,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-06-26 22:10:31'),
(7, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'payment_completed', '{\"booking_id\":\"22\",\"amount\":1200,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-06-27 11:31:25'),
(8, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-27 12:48:51'),
(9, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'profile_photo_upload', '{\"filename\":\"profile_5_1751028531_4da64fb9d928a580.png\",\"original_name\":\"image-removebg-preview (3).png\",\"size\":154922,\"mime_type\":\"image\\/png\"}', 'low', '2025-06-27 12:48:51'),
(10, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-27 12:49:24'),
(11, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'profile_photo_upload', '{\"filename\":\"profile_5_1751028564_2f6ac8ce3149c694.png\",\"original_name\":\"66b6c0cea8d41f25e3076c361dd39a85-removebg-preview.png\",\"size\":154092,\"mime_type\":\"image\\/png\"}', 'low', '2025-06-27 12:49:24'),
(12, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-27 13:19:07'),
(13, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'profile_photo_upload', '{\"filename\":\"profile_5_1751030347_35edfaa3df09436f.png\",\"original_name\":\"Brown Orange Gradient Geometric Profile Picture Instagram Post.png\",\"size\":465516,\"mime_type\":false}', 'low', '2025-06-27 13:19:07'),
(14, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-27 13:19:08'),
(15, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'profile_photo_upload', '{\"filename\":\"profile_5_1751030348_85bbf17c0cee18f9.png\",\"original_name\":\"Brown Orange Gradient Geometric Profile Picture Instagram Post.png\",\"size\":465516,\"mime_type\":false}', 'low', '2025-06-27 13:19:08'),
(16, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-27 13:19:09'),
(17, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'profile_photo_upload', '{\"filename\":\"profile_5_1751030349_e1468276e561d95d.png\",\"original_name\":\"Brown Orange Gradient Geometric Profile Picture Instagram Post.png\",\"size\":465516,\"mime_type\":false}', 'low', '2025-06-27 13:19:09'),
(18, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-27 13:19:10'),
(19, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'profile_photo_upload', '{\"filename\":\"profile_5_1751030350_479b810df99a8f76.png\",\"original_name\":\"Brown Orange Gradient Geometric Profile Picture Instagram Post.png\",\"size\":465516,\"mime_type\":false}', 'low', '2025-06-27 13:19:10'),
(20, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-27 13:29:50'),
(21, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36 Edg/137.0.0.0', 'profile_photo_upload', '{\"filename\":\"profile_5_1751030990_f4a8403db9d622d7.png\",\"original_name\":\"image-removebg-preview (2).png\",\"size\":128517,\"mime_type\":\"image\\/png\"}', 'low', '2025-06-27 13:29:50'),
(22, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_failed', '{\"booking_id\":9799,\"error\":\"Erreur lors du traitement du paiement. Veuillez r\\u00e9essayer.\"}', 'medium', '2025-06-30 08:29:13'),
(23, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":9799,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-06-30 08:29:32'),
(24, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":5415,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-06-30 08:33:17'),
(25, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"23\",\"amount\":396,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-06-30 09:45:33'),
(26, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-06-30 09:46:13'),
(27, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'profile_photo_upload', '{\"filename\":\"profile_5_1751276773_bcfe75d1f0ec1052.png\",\"original_name\":\"Brown Orange Gradient Geometric Profile Picture Instagram Post.png\",\"size\":465516,\"mime_type\":\"image\\/png\"}', 'low', '2025-06-30 09:46:13'),
(28, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":4510,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-06-30 10:02:16'),
(29, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":6621,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-06-30 10:03:19'),
(30, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"24\",\"amount\":650,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-01 19:44:57'),
(31, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":6349,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-01 19:45:39'),
(32, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":7781,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-01 19:46:35'),
(33, 7, NULL, NULL, 'profile_update', '{\"email\": \"2malaknour3@gmail.com\"}', 'low', '2025-07-01 19:49:47'),
(34, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":8922,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-01 19:53:38'),
(35, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_failed', '{\"booking_id\":\"25\",\"error\":\"Erreur lors du traitement du paiement PayPal: Erreur lors de l\'authentification PayPal\"}', 'medium', '2025-07-01 19:57:03'),
(36, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_failed', '{\"booking_id\":\"25\",\"error\":\"Erreur lors du traitement du paiement PayPal: Erreur lors de l\'authentification PayPal\"}', 'medium', '2025-07-01 19:57:17'),
(37, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"25\",\"amount\":396,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-01 19:58:37'),
(38, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_failed', '{\"booking_id\":9363,\"error\":\"Num\\u00e9ro de carte invalide\"}', 'medium', '2025-07-01 19:59:15'),
(39, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":9363,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-01 19:59:24'),
(40, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-07-02 13:55:06'),
(41, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'profile_update', '{\"updated_fields\":[\"full_name\",\"phone\",\"address\",\"date_of_birth\"]}', 'low', '2025-07-02 13:55:06'),
(42, 5, NULL, NULL, 'profile_update', '{\"email\": \"codeteste649@gmail.com\"}', 'low', '2025-07-02 13:55:41'),
(43, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'profile_photo_upload', '{\"filename\":\"profile_5_1751464541_ce759f19f69c86c8.jpg\",\"original_name\":\"66b6c0cea8d41f25e3076c361dd39a85.jpg\",\"size\":51544,\"mime_type\":\"image\\/jpeg\"}', 'low', '2025-07-02 13:55:41'),
(44, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"26\",\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-02 14:01:50'),
(45, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"27\",\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-02 14:02:33'),
(46, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_failed', '{\"booking_id\":8358,\"error\":\"Erreur lors du traitement du paiement. Veuillez r\\u00e9essayer.\"}', 'medium', '2025-07-02 14:04:05'),
(47, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"29\",\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-02 14:04:11'),
(49, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'booking_failed', '{\"error\":\"Erreur lors du traitement de la r\\u00e9servation: There is no active transaction\",\"payment_method\":\"paypal\",\"ip\":\"::1\"}', 'medium', '2025-07-02 14:50:05'),
(50, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'booking_failed', '{\"error\":\"Erreur lors du traitement de la r\\u00e9servation: There is no active transaction\",\"payment_method\":\"paypal\",\"ip\":\"::1\"}', 'medium', '2025-07-02 14:50:11'),
(55, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"33\",\"amount\":1530,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-02 15:32:25'),
(56, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":4828,\"amount\":4500,\"method\":\"cash\",\"email_sent\":false}', 'low', '2025-07-02 15:38:00'),
(57, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":5238,\"amount\":4500,\"method\":\"paypal\",\"status\":\"confirmed\",\"email_sent\":false}', 'low', '2025-07-02 15:44:55'),
(58, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_failed', '{\"booking_id\":null,\"error\":\"Erreur lors du traitement du paiement: Erreur lors de la cr\\u00e9ation de la r\\u00e9servation: There is already an active transaction\",\"payment_method\":\"paypal\"}', 'medium', '2025-07-02 15:53:33'),
(59, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_failed', '{\"booking_id\":null,\"error\":\"Erreur lors du traitement du paiement: Erreur lors de la cr\\u00e9ation de la r\\u00e9servation: There is already an active transaction\",\"payment_method\":\"paypal\"}', 'medium', '2025-07-02 15:53:48'),
(60, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_failed', '{\"booking_id\":null,\"error\":\"Num\\u00e9ro de carte invalide\",\"payment_method\":\"card\"}', 'medium', '2025-07-02 15:54:37'),
(61, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_failed', '{\"booking_id\":null,\"error\":\"Erreur lors du traitement du paiement: Erreur lors de la cr\\u00e9ation de la r\\u00e9servation: There is already an active transaction\",\"payment_method\":\"cash\"}', 'medium', '2025-07-02 15:54:45'),
(62, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_failed', '{\"booking_id\":null,\"error\":\"Erreur lors du traitement du paiement: Erreur lors de la cr\\u00e9ation de la r\\u00e9servation: There is already an active transaction\",\"payment_method\":\"paypal\"}', 'medium', '2025-07-02 15:58:51'),
(63, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"35\",\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-02 16:08:50'),
(64, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"36\",\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-02 16:15:45'),
(65, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"37\",\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-02 16:22:28'),
(66, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"38\",\"amount\":4500,\"method\":\"cash\",\"email_sent\":false}', 'low', '2025-07-02 16:22:55'),
(67, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":\"40\",\"amount\":4500,\"method\":\"cash\",\"email_sent\":false}', 'low', '2025-07-02 16:29:55'),
(68, 5, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":9401,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-02 16:43:59'),
(69, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":9911,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-02 16:45:27'),
(70, 7, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edg/138.0.0.0', 'payment_completed', '{\"booking_id\":6067,\"amount\":4500,\"method\":\"paypal\",\"email_sent\":false}', 'low', '2025-07-02 16:45:34'),
(71, 7, NULL, NULL, 'profile_update', '{\"email\": \"2malaknour3@gmail.com\"}', 'low', '2025-07-02 16:50:46'),
(72, 7, NULL, NULL, 'profile_update', '{\"email\": \"2malaknour3@gmail.com\"}', 'low', '2025-07-02 16:58:19');

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `role` enum('client','admin') DEFAULT 'client',
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `profile_photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `full_name`, `role`, `phone`, `address`, `date_of_birth`, `created_at`, `updated_at`, `profile_photo`) VALUES
(5, 'codeteste649@gmail.com', '$2y$10$KpeJnSWr9B3mrYFLY5hCEeZ8vRojHspOT0/u5CmJLoncUA9iFp2ji', 'cod', 'admin', '0666666666', 'rabat', '2006-06-20', '2025-06-07 19:55:24', '2025-07-02 13:55:41', 'uploads/profiles/profile_5_1751464541_ce759f19f69c86c8.jpg'),
(6, 'codeteste640@gmail.com', '$2y$10$IREfBkpgzvR5sLAA8ls3c.Yfq8wouh1Z8coNKmg3/8Be67XiNAkzK', 'ppp', 'admin', '+212600000000', 'hhhhh', '2006-06-20', '2025-06-09 13:31:27', '2025-06-09 13:31:27', NULL),
(7, '2malaknour3@gmail.com', '$2y$10$3CHCrzimuYO0DUsww5T46ONSxHn5GrNE4.D6Qowk77f.5xaj7Vxh2', 'projet', 'admin', '+21260000000', 'hhhhh', '2006-06-20', '2025-06-27 13:53:28', '2025-06-27 13:53:28', NULL),
(8, 'client@example.com', '', 'Ahmed Benali', 'client', NULL, NULL, NULL, '2025-07-02 14:01:48', '2025-07-02 14:01:48', NULL);

--
-- Déclencheurs `users`
--
DELIMITER $$
CREATE TRIGGER `log_user_login` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
    IF NEW.updated_at != OLD.updated_at THEN
        INSERT INTO security_logs (user_id, action, details, severity)
        VALUES (NEW.id, 'profile_update', JSON_OBJECT('email', NEW.email), 'low');
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `user_favorites`
--

CREATE TABLE `user_favorites` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `hotel_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la vue `room_availability_view`
--
DROP TABLE IF EXISTS `room_availability_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `room_availability_view`  AS SELECT `r`.`id` AS `room_id`, `r`.`hotel_id` AS `hotel_id`, `r`.`room_type_id` AS `room_type_id`, `r`.`room_number` AS `room_number`, `r`.`room_type` AS `room_type`, curdate() + interval `seq`.`seq` day AS `available_date`, 'available' AS `status`, `r`.`price_per_night` AS `price_per_night`, `rt`.`name` AS `room_type_name`, `rt`.`max_occupancy` AS `max_occupancy`, `h`.`name` AS `hotel_name`, `h`.`city` AS `city` FROM (((`rooms` `r` join `room_types` `rt` on(`r`.`room_type_id` = `rt`.`id`)) join `hotels` `h` on(`r`.`hotel_id` = `h`.`id`)) join (select 0 AS `seq` union select 1 AS `1` union select 2 AS `2` union select 3 AS `3` union select 4 AS `4` union select 5 AS `5` union select 6 AS `6` union select 7 AS `7` union select 8 AS `8` union select 9 AS `9` union select 10 AS `10` union select 11 AS `11` union select 12 AS `12` union select 13 AS `13` union select 14 AS `14` union select 15 AS `15` union select 16 AS `16` union select 17 AS `17` union select 18 AS `18` union select 19 AS `19` union select 20 AS `20` union select 21 AS `21` union select 22 AS `22` union select 23 AS `23` union select 24 AS `24` union select 25 AS `25` union select 26 AS `26` union select 27 AS `27` union select 28 AS `28` union select 29 AS `29` union select 30 AS `30` union select 31 AS `31` union select 32 AS `32` union select 33 AS `33` union select 34 AS `34` union select 35 AS `35` union select 36 AS `36` union select 37 AS `37` union select 38 AS `38` union select 39 AS `39` union select 40 AS `40` union select 41 AS `41` union select 42 AS `42` union select 43 AS `43` union select 44 AS `44` union select 45 AS `45` union select 46 AS `46` union select 47 AS `47` union select 48 AS `48` union select 49 AS `49` union select 50 AS `50` union select 51 AS `51` union select 52 AS `52` union select 53 AS `53` union select 54 AS `54` union select 55 AS `55` union select 56 AS `56` union select 57 AS `57` union select 58 AS `58` union select 59 AS `59` union select 60 AS `60` union select 61 AS `61` union select 62 AS `62` union select 63 AS `63` union select 64 AS `64` union select 65 AS `65` union select 66 AS `66` union select 67 AS `67` union select 68 AS `68` union select 69 AS `69` union select 70 AS `70` union select 71 AS `71` union select 72 AS `72` union select 73 AS `73` union select 74 AS `74` union select 75 AS `75` union select 76 AS `76` union select 77 AS `77` union select 78 AS `78` union select 79 AS `79` union select 80 AS `80` union select 81 AS `81` union select 82 AS `82` union select 83 AS `83` union select 84 AS `84` union select 85 AS `85` union select 86 AS `86` union select 87 AS `87` union select 88 AS `88` union select 89 AS `89` union select 90 AS `90` union select 91 AS `91` union select 92 AS `92` union select 93 AS `93` union select 94 AS `94` union select 95 AS `95` union select 96 AS `96` union select 97 AS `97` union select 98 AS `98` union select 99 AS `99` union select 100 AS `100` union select 101 AS `101` union select 102 AS `102` union select 103 AS `103` union select 104 AS `104` union select 105 AS `105` union select 106 AS `106` union select 107 AS `107` union select 108 AS `108` union select 109 AS `109` union select 110 AS `110` union select 111 AS `111` union select 112 AS `112` union select 113 AS `113` union select 114 AS `114` union select 115 AS `115` union select 116 AS `116` union select 117 AS `117` union select 118 AS `118` union select 119 AS `119` union select 120 AS `120` union select 121 AS `121` union select 122 AS `122` union select 123 AS `123` union select 124 AS `124` union select 125 AS `125` union select 126 AS `126` union select 127 AS `127` union select 128 AS `128` union select 129 AS `129` union select 130 AS `130` union select 131 AS `131` union select 132 AS `132` union select 133 AS `133` union select 134 AS `134` union select 135 AS `135` union select 136 AS `136` union select 137 AS `137` union select 138 AS `138` union select 139 AS `139` union select 140 AS `140` union select 141 AS `141` union select 142 AS `142` union select 143 AS `143` union select 144 AS `144` union select 145 AS `145` union select 146 AS `146` union select 147 AS `147` union select 148 AS `148` union select 149 AS `149` union select 150 AS `150` union select 151 AS `151` union select 152 AS `152` union select 153 AS `153` union select 154 AS `154` union select 155 AS `155` union select 156 AS `156` union select 157 AS `157` union select 158 AS `158` union select 159 AS `159` union select 160 AS `160` union select 161 AS `161` union select 162 AS `162` union select 163 AS `163` union select 164 AS `164` union select 165 AS `165` union select 166 AS `166` union select 167 AS `167` union select 168 AS `168` union select 169 AS `169` union select 170 AS `170` union select 171 AS `171` union select 172 AS `172` union select 173 AS `173` union select 174 AS `174` union select 175 AS `175` union select 176 AS `176` union select 177 AS `177` union select 178 AS `178` union select 179 AS `179` union select 180 AS `180` union select 181 AS `181` union select 182 AS `182` union select 183 AS `183` union select 184 AS `184` union select 185 AS `185` union select 186 AS `186` union select 187 AS `187` union select 188 AS `188` union select 189 AS `189` union select 190 AS `190` union select 191 AS `191` union select 192 AS `192` union select 193 AS `193` union select 194 AS `194` union select 195 AS `195` union select 196 AS `196` union select 197 AS `197` union select 198 AS `198` union select 199 AS `199` union select 200 AS `200` union select 201 AS `201` union select 202 AS `202` union select 203 AS `203` union select 204 AS `204` union select 205 AS `205` union select 206 AS `206` union select 207 AS `207` union select 208 AS `208` union select 209 AS `209` union select 210 AS `210` union select 211 AS `211` union select 212 AS `212` union select 213 AS `213` union select 214 AS `214` union select 215 AS `215` union select 216 AS `216` union select 217 AS `217` union select 218 AS `218` union select 219 AS `219` union select 220 AS `220` union select 221 AS `221` union select 222 AS `222` union select 223 AS `223` union select 224 AS `224` union select 225 AS `225` union select 226 AS `226` union select 227 AS `227` union select 228 AS `228` union select 229 AS `229` union select 230 AS `230` union select 231 AS `231` union select 232 AS `232` union select 233 AS `233` union select 234 AS `234` union select 235 AS `235` union select 236 AS `236` union select 237 AS `237` union select 238 AS `238` union select 239 AS `239` union select 240 AS `240` union select 241 AS `241` union select 242 AS `242` union select 243 AS `243` union select 244 AS `244` union select 245 AS `245` union select 246 AS `246` union select 247 AS `247` union select 248 AS `248` union select 249 AS `249` union select 250 AS `250` union select 251 AS `251` union select 252 AS `252` union select 253 AS `253` union select 254 AS `254` union select 255 AS `255` union select 256 AS `256` union select 257 AS `257` union select 258 AS `258` union select 259 AS `259` union select 260 AS `260` union select 261 AS `261` union select 262 AS `262` union select 263 AS `263` union select 264 AS `264` union select 265 AS `265` union select 266 AS `266` union select 267 AS `267` union select 268 AS `268` union select 269 AS `269` union select 270 AS `270` union select 271 AS `271` union select 272 AS `272` union select 273 AS `273` union select 274 AS `274` union select 275 AS `275` union select 276 AS `276` union select 277 AS `277` union select 278 AS `278` union select 279 AS `279` union select 280 AS `280` union select 281 AS `281` union select 282 AS `282` union select 283 AS `283` union select 284 AS `284` union select 285 AS `285` union select 286 AS `286` union select 287 AS `287` union select 288 AS `288` union select 289 AS `289` union select 290 AS `290` union select 291 AS `291` union select 292 AS `292` union select 293 AS `293` union select 294 AS `294` union select 295 AS `295` union select 296 AS `296` union select 297 AS `297` union select 298 AS `298` union select 299 AS `299` union select 300 AS `300` union select 301 AS `301` union select 302 AS `302` union select 303 AS `303` union select 304 AS `304` union select 305 AS `305` union select 306 AS `306` union select 307 AS `307` union select 308 AS `308` union select 309 AS `309` union select 310 AS `310` union select 311 AS `311` union select 312 AS `312` union select 313 AS `313` union select 314 AS `314` union select 315 AS `315` union select 316 AS `316` union select 317 AS `317` union select 318 AS `318` union select 319 AS `319` union select 320 AS `320` union select 321 AS `321` union select 322 AS `322` union select 323 AS `323` union select 324 AS `324` union select 325 AS `325` union select 326 AS `326` union select 327 AS `327` union select 328 AS `328` union select 329 AS `329` union select 330 AS `330` union select 331 AS `331` union select 332 AS `332` union select 333 AS `333` union select 334 AS `334` union select 335 AS `335` union select 336 AS `336` union select 337 AS `337` union select 338 AS `338` union select 339 AS `339` union select 340 AS `340` union select 341 AS `341` union select 342 AS `342` union select 343 AS `343` union select 344 AS `344` union select 345 AS `345` union select 346 AS `346` union select 347 AS `347` union select 348 AS `348` union select 349 AS `349` union select 350 AS `350` union select 351 AS `351` union select 352 AS `352` union select 353 AS `353` union select 354 AS `354` union select 355 AS `355` union select 356 AS `356` union select 357 AS `357` union select 358 AS `358` union select 359 AS `359` union select 360 AS `360` union select 361 AS `361` union select 362 AS `362` union select 363 AS `363` union select 364 AS `364` union select 365 AS `365`) `seq`) WHERE `r`.`status` = 'available' AND `h`.`status` = 'active' AND curdate() + interval `seq`.`seq` day <= curdate() + interval 365 day ;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Index pour la table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `confirmation_code` (`confirmation_code`),
  ADD KEY `room_type_id` (`room_type_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_hotel_id` (`hotel_id`),
  ADD KEY `idx_dates` (`check_in`,`check_out`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_room_id` (`room_id`),
  ADD KEY `idx_bookings_status_payment` (`status`,`payment_status`),
  ADD KEY `idx_bookings_dates` (`check_in`,`check_out`);

--
-- Index pour la table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Index pour la table `hotels`
--
ALTER TABLE `hotels`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_city` (`city`),
  ADD KEY `idx_featured` (`is_featured`),
  ADD KEY `idx_status` (`status`);

--
-- Index pour la table `hotel_bookings`
--
ALTER TABLE `hotel_bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `hotel_id` (`hotel_id`),
  ADD KEY `idx_dates` (`checkin_date`,`checkout_date`),
  ADD KEY `idx_status` (`status`);

--
-- Index pour la table `hotel_images`
--
ALTER TABLE `hotel_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hotel_id` (`hotel_id`),
  ADD KEY `idx_primary` (`is_primary`);

--
-- Index pour la table `nearby_services`
--
ALTER TABLE `nearby_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hotel_distance` (`hotel_id`,`distance_km`),
  ADD KEY `idx_type` (`type`);

--
-- Index pour la table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reservation_id` (`reservation_id`),
  ADD KEY `idx_transaction_id` (`transaction_id`),
  ADD KEY `idx_status` (`status`);

--
-- Index pour la table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_booking_id` (`booking_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_transaction_id` (`transaction_id`);

--
-- Index pour la table `promotions`
--
ALTER TABLE `promotions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hotel_dates` (`hotel_id`,`valid_from`,`valid_to`),
  ADD KEY `idx_status` (`status`);

--
-- Index pour la table `promo_codes`
--
ALTER TABLE `promo_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_validity` (`valid_from`,`valid_until`);

--
-- Index pour la table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `hotel_id` (`hotel_id`),
  ADD KEY `room_id` (`room_id`);

--
-- Index pour la table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `idx_hotel_id` (`hotel_id`),
  ADD KEY `idx_rating` (`rating`),
  ADD KEY `idx_verified` (`is_verified`);

--
-- Index pour la table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_room` (`hotel_id`,`room_number`),
  ADD KEY `idx_hotel_id` (`hotel_id`),
  ADD KEY `idx_room_type` (`room_type_id`),
  ADD KEY `idx_status` (`status`);

--
-- Index pour la table `room_availability`
--
ALTER TABLE `room_availability`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_room_date` (`room_id`,`date`),
  ADD KEY `idx_room_date` (`room_id`,`date`),
  ADD KEY `idx_date_status` (`date`,`status`),
  ADD KEY `idx_room_availability_booking_id` (`booking_id`),
  ADD KEY `idx_room_availability_date_status` (`date`,`status`);

--
-- Index pour la table `room_types`
--
ALTER TABLE `room_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_hotel_id` (`hotel_id`),
  ADD KEY `idx_price` (`price_per_night`);

--
-- Index pour la table `search_logs`
--
ALTER TABLE `search_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_date` (`ip_address`,`created_at`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Index pour la table `search_sessions`
--
ALTER TABLE `search_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_session` (`session_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_last_activity` (`last_activity`);

--
-- Index pour la table `security_logs`
--
ALTER TABLE `security_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_severity` (`severity`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_users_profile_photo` (`profile_photo`);

--
-- Index pour la table `user_favorites`
--
ALTER TABLE `user_favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_favorite` (`user_id`,`hotel_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_hotel` (`hotel_id`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT pour la table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT pour la table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT pour la table `hotels`
--
ALTER TABLE `hotels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT pour la table `hotel_bookings`
--
ALTER TABLE `hotel_bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `hotel_images`
--
ALTER TABLE `hotel_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `nearby_services`
--
ALTER TABLE `nearby_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT pour la table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT pour la table `promotions`
--
ALTER TABLE `promotions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT pour la table `promo_codes`
--
ALTER TABLE `promo_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1263;

--
-- AUTO_INCREMENT pour la table `room_availability`
--
ALTER TABLE `room_availability`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24622;

--
-- AUTO_INCREMENT pour la table `room_types`
--
ALTER TABLE `room_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- AUTO_INCREMENT pour la table `search_logs`
--
ALTER TABLE `search_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT pour la table `search_sessions`
--
ALTER TABLE `search_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `security_logs`
--
ALTER TABLE `security_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT pour la table `user_favorites`
--
ALTER TABLE `user_favorites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `email_logs`
--
ALTER TABLE `email_logs`
  ADD CONSTRAINT `email_logs_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `hotel_bookings`
--
ALTER TABLE `hotel_bookings`
  ADD CONSTRAINT `hotel_bookings_ibfk_1` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`);

--
-- Contraintes pour la table `hotel_images`
--
ALTER TABLE `hotel_images`
  ADD CONSTRAINT `hotel_images_ibfk_1` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `nearby_services`
--
ALTER TABLE `nearby_services`
  ADD CONSTRAINT `nearby_services_ibfk_1` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `payment_transactions`
--
ALTER TABLE `payment_transactions`
  ADD CONSTRAINT `payment_transactions_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `promotions`
--
ALTER TABLE `promotions`
  ADD CONSTRAINT `promotions_ibfk_1` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservations_ibfk_3` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `rooms`
--
ALTER TABLE `rooms`
  ADD CONSTRAINT `rooms_ibfk_1` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `rooms_ibfk_2` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `room_availability`
--
ALTER TABLE `room_availability`
  ADD CONSTRAINT `room_availability_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `room_availability_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `room_types`
--
ALTER TABLE `room_types`
  ADD CONSTRAINT `room_types_ibfk_1` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `search_sessions`
--
ALTER TABLE `search_sessions`
  ADD CONSTRAINT `search_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `security_logs`
--
ALTER TABLE `security_logs`
  ADD CONSTRAINT `security_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `user_favorites`
--
ALTER TABLE `user_favorites`
  ADD CONSTRAINT `user_favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_favorites_ibfk_2` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`) ON DELETE CASCADE;

DELIMITER $$
--
-- Évènements
--
CREATE DEFINER=`root`@`localhost` EVENT `cleanup_expired_bookings` ON SCHEDULE EVERY 1 HOUR STARTS '2025-06-21 16:38:31' ON COMPLETION NOT PRESERVE ENABLE DO BEGIN
    -- Annuler les réservations non payées après 24h
    UPDATE bookings 
    SET status = 'cancelled', 
        cancellation_reason = 'Paiement non effectué dans les délais',
        cancelled_at = NOW()
    WHERE status = 'pending' 
    AND payment_status = 'pending' 
    AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
    
    -- Libérer les chambres pour les réservations annulées
    DELETE ra FROM room_availability ra
    JOIN bookings b ON ra.booking_id = b.id
    WHERE b.status = 'cancelled';
    
    -- Marquer comme terminé les séjours dont la date de départ est passée
    UPDATE bookings 
    SET status = 'checked_out'
    WHERE status IN ('confirmed', 'checked_in') 
    AND check_out < CURDATE();
    
    -- Libérer les chambres pour les séjours terminés
    DELETE ra FROM room_availability ra
    JOIN bookings b ON ra.booking_id = b.id
    WHERE b.status = 'checked_out' AND b.check_out < CURDATE();
END$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
