<?php
session_start();

// Nettoyer les données de réservation après confirmation
if (isset($_SESSION['booking_data'])) {
    unset($_SESSION['booking_data']);
}

echo json_encode(['status' => 'success']);
?>
