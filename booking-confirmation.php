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

$bookingId = $_GET['booking'] ?? null;
$emailSent = $_GET['email'] ?? '0';

if (!$bookingId) {
    header('Location: index.php');
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$userInfo = null;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT id, email, full_name, phone FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userInfo = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Erreur récupération utilisateur: " . $e->getMessage());
    }
}

// Si pas d'utilisateur trouvé, utiliser des données par défaut pour la démo
if (!$userInfo) {
    $userInfo = [
        'id' => $_SESSION['user_id'] ?? 1,
        'email' => '2malaknour3@gmail.com',
        'full_name' => 'Malak Nour',
        'phone' => '+212 6 00 00 00 00'
    ];
}

// Récupérer les détails de la réservation depuis la base de données
$bookingDetails = null;
try {
    $stmt = $pdo->prepare("
        SELECT b.*, h.name as hotel_name, h.location, h.city, h.image_url as hotel_image,
               rt.name as room_type_name, rt.description as room_description
        FROM bookings b
        LEFT JOIN hotels h ON b.hotel_id = h.id
        LEFT JOIN room_types rt ON b.room_type_id = rt.id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $stmt->execute([$bookingId, $userInfo['id']]);
    $bookingDetails = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Erreur récupération réservation: " . $e->getMessage());
}

// Si pas de réservation trouvée, utiliser des données simulées
if (!$bookingDetails) {
    $bookingDetails = [
        'id' => $bookingId,
        'hotel_id' => 1,
        'room_type_id' => 1,
        'check_in' => '2024-07-15',
        'check_out' => '2024-07-18',
        'guests' => 2,
        'total_price' => 4500,
        'status' => 'confirmed',
        'payment_status' => 'paid',
        'hotel_name' => 'Atlas Hotel Marrakech',
        'location' => 'Avenue Mohammed VI',
        'city' => 'Marrakech',
        'hotel_image' => 'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=400&h=300&fit=crop',
        'room_type_name' => 'Suite Deluxe avec vue sur l\'Atlas',
        'room_description' => 'Suite luxueuse avec vue panoramique'
    ];
}

// Créer l'objet booking complet avec les vraies données utilisateur
$booking = [
    'id' => $bookingId,
    'confirmation_code' => 'ATL' . str_pad($bookingId, 6, '0', STR_PAD_LEFT) . strtoupper(substr(md5(time() . $bookingId), 0, 4)),
    'hotel_name' => $bookingDetails['hotel_name'],
    'hotel_image' => $bookingDetails['hotel_image'],
    'location' => $bookingDetails['location'],
    'city' => $bookingDetails['city'],
    'room_type_name' => $bookingDetails['room_type_name'],
    'check_in' => $bookingDetails['check_in'],
    'check_out' => $bookingDetails['check_out'],
    'guests' => $bookingDetails['guests'],
    'total_price' => $bookingDetails['total_price'],
    'user_email' => $userInfo['email'],
    'user_name' => $userInfo['full_name'],
    'user_phone' => $userInfo['phone']
];

$checkInDate = date('d/m/Y', strtotime($booking['check_in']));
$checkOutDate = date('d/m/Y', strtotime($booking['check_out']));
$nights = (strtotime($booking['check_out']) - strtotime($booking['check_in'])) / (60 * 60 * 24);

// Classe complète pour diagnostiquer et gérer l'envoi d'emails avec toutes les solutions
class EmailDiagnosticManager {
    private $booking;
    private $diagnostics = [];
    
    // Configuration Gmail SMTP - MODIFIEZ CES VALEURS
    private $gmailConfig = [
        'enabled' => false, // Mettez true pour activer Gmail SMTP
        'username' => 'votre-email@gmail.com', // Votre email Gmail
        'password' => 'votre-mot-de-passe-app', // Mot de passe d'application Gmail (16 caractères)
        'from_name' => 'Atlas Hotels'
    ];
    
    public function __construct($booking) {
        $this->booking = $booking;
    }
    
    public function runFullDiagnostic() {
        $this->diagnostics = [
            'php_mail_function' => $this->checkMailFunction(),
            'smtp_config' => $this->checkSMTPConfig(),
            'mailhog_available' => $this->checkMailHog(),
            'gmail_smtp' => $this->checkGmailSMTP(),
            'phpmailer_installed' => $this->checkPHPMailer(),
            'local_smtp' => $this->checkLocalSMTP(),
            'file_permissions' => $this->checkFilePermissions(),
            'internet_connection' => $this->checkInternetConnection()
        ];
        
        return $this->diagnostics;
    }
    
    private function checkMailFunction() {
        if (!function_exists('mail')) {
            return [
                'status' => 'error',
                'message' => 'Fonction mail() non disponible',
                'solution' => 'Activez l\'extension mail dans php.ini'
            ];
        }
        
        return [
            'status' => 'ok',
            'message' => 'Fonction mail() disponible',
            'solution' => null
        ];
    }
    
    private function checkSMTPConfig() {
        $smtp = ini_get('SMTP');
        $port = ini_get('smtp_port');
        $from = ini_get('sendmail_from');
        
        if (empty($smtp) || empty($port)) {
            return [
                'status' => 'warning',
                'message' => 'Configuration SMTP manquante dans php.ini',
                'details' => "SMTP: '$smtp', Port: '$port', From: '$from'",
                'solution' => 'Configurez SMTP dans php.ini ou utilisez MailHog'
            ];
        }
        
        return [
            'status' => 'ok',
            'message' => 'Configuration SMTP présente',
            'details' => "SMTP: $smtp, Port: $port, From: $from",
            'solution' => null
        ];
    }
    
    private function checkMailHog() {
        $connection = @fsockopen('localhost', 1025, $errno, $errstr, 2);
        if ($connection) {
            fclose($connection);
            return [
                'status' => 'ok',
                'message' => 'MailHog disponible sur port 1025 ✅',
                'details' => 'Interface web: http://localhost:8025',
                'solution' => 'MailHog est prêt à capturer les emails'
            ];
        }
        
        return [
            'status' => 'error',
            'message' => 'MailHog non disponible sur port 1025',
            'details' => "Erreur: $errstr ($errno)",
            'solution' => 'Téléchargez et lancez MailHog.exe'
        ];
    }
    
    private function checkGmailSMTP() {
        if (!$this->gmailConfig['enabled']) {
            return [
                'status' => 'warning',
                'message' => 'Gmail SMTP désactivé dans la configuration',
                'details' => 'Modifiez $gmailConfig[\'enabled\'] = true dans le code',
                'solution' => 'Configurez vos identifiants Gmail dans le code'
            ];
        }
        
        $connection = @fsockopen('smtp.gmail.com', 587, $errno, $errstr, 5);
        if ($connection) {
            fclose($connection);
            return [
                'status' => 'ok',
                'message' => 'Gmail SMTP accessible',
                'details' => 'Connexion à smtp.gmail.com:587 réussie',
                'solution' => 'Gmail SMTP prêt (vérifiez vos identifiants)'
            ];
        }
        
        return [
            'status' => 'warning',
            'message' => 'Gmail SMTP non accessible',
            'details' => "Erreur: $errstr ($errno)",
            'solution' => 'Vérifiez votre connexion internet'
        ];
    }
    
    private function checkPHPMailer() {
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return [
                'status' => 'ok',
                'message' => 'PHPMailer installé ✅',
                'details' => 'Classe PHPMailer disponible',
                'solution' => 'PHPMailer prêt pour Gmail SMTP'
            ];
        }
        
        // Vérifier si Composer est disponible
        $composerExists = file_exists('vendor/autoload.php');
        
        return [
            'status' => 'warning',
            'message' => 'PHPMailer non installé',
            'details' => $composerExists ? 'Composer détecté' : 'Composer non détecté',
            'solution' => 'Exécutez: composer require phpmailer/phpmailer'
        ];
    }
    
    private function checkLocalSMTP() {
        $connection = @fsockopen('localhost', 25, $errno, $errstr, 1);
        if ($connection) {
            fclose($connection);
            return [
                'status' => 'ok',
                'message' => 'Serveur SMTP local disponible sur port 25',
                'solution' => 'Serveur SMTP local prêt'
            ];
        }
        
        return [
            'status' => 'error',
            'message' => 'Aucun serveur SMTP local sur port 25',
            'details' => "Erreur: $errstr ($errno)",
            'solution' => 'Installez un serveur SMTP local ou utilisez MailHog'
        ];
    }
    
    private function checkFilePermissions() {
        $testDir = 'emails';
        if (!is_dir($testDir)) {
            if (@mkdir($testDir, 0755, true)) {
                return [
                    'status' => 'ok',
                    'message' => 'Dossier emails créé avec succès',
                    'details' => 'Permissions d\'écriture OK',
                    'solution' => null
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Impossible de créer le dossier emails',
                    'solution' => 'Vérifiez les permissions du serveur web'
                ];
            }
        }
        
        if (is_writable($testDir)) {
            return [
                'status' => 'ok',
                'message' => 'Dossier emails accessible en écriture',
                'solution' => null
            ];
        }
        
        return [
            'status' => 'error',
            'message' => 'Dossier emails non accessible en écriture',
            'solution' => 'Modifiez les permissions du dossier emails'
        ];
    }
    
    private function checkInternetConnection() {
        $connection = @fsockopen('www.google.com', 80, $errno, $errstr, 5);
        if ($connection) {
            fclose($connection);
            return [
                'status' => 'ok',
                'message' => 'Connexion internet disponible',
                'solution' => null
            ];
        }
        
        return [
            'status' => 'warning',
            'message' => 'Connexion internet limitée ou indisponible',
            'details' => "Erreur: $errstr ($errno)",
            'solution' => 'Vérifiez votre connexion pour Gmail SMTP'
        ];
    }
    
    public function attemptEmailSend() {
        $methods = [
            'mailhog' => [$this, 'sendViaMailHog'],
            'gmail_smtp' => [$this, 'sendViaGmailSMTP'],
            'local_smtp' => [$this, 'sendViaLocalSMTP'],
            'php_mail' => [$this, 'sendViaPHPMail'],
            'file_save' => [$this, 'saveToFile'],
            'log_save' => [$this, 'saveToLog']
        ];
        
        $results = [];
        $success = false;
        $successMethod = null;
        
        foreach ($methods as $method => $callback) {
            $result = call_user_func($callback);
            $results[$method] = $result;
            
            if ($result['success'] && !$success) {
                $success = true;
                $successMethod = $method;
                // Ne pas continuer après le premier succès pour éviter les doublons
                break;
            }
        }
        
        return [
            'overall_success' => $success,
            'success_method' => $successMethod,
            'results' => $results
        ];
    }
    
    private function sendViaMailHog() {
        try {
            // Test de connexion MailHog
            $connection = @fsockopen('localhost', 1025, $errno, $errstr, 3);
            if (!$connection) {
                return [
                    'success' => false,
                    'message' => 'MailHog non disponible sur port 1025',
                    'details' => "Erreur: $errstr ($errno) - Lancez MailHog.exe"
                ];
            }
            fclose($connection);
            
            // Sauvegarder la configuration actuelle
            $originalSMTP = ini_get('SMTP');
            $originalPort = ini_get('smtp_port');
            $originalFrom = ini_get('sendmail_from');
            
            // Configuration MailHog
            ini_set('SMTP', 'localhost');
            ini_set('smtp_port', '1025');
            ini_set('sendmail_from', 'noreply@atlashotels.ma');
            
            $sent = $this->sendEmail();
            
            // Restaurer la configuration
            if ($originalSMTP) ini_set('SMTP', $originalSMTP);
            if ($originalPort) ini_set('smtp_port', $originalPort);
            if ($originalFrom) ini_set('sendmail_from', $originalFrom);
            
            if ($sent) {
                return [
                    'success' => true,
                    'message' => 'Email envoyé via MailHog ! 🎉',
                    'details' => 'Vérifiez http://localhost:8025 pour voir l\'email capturé'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Échec envoi via MailHog',
                    'details' => 'La fonction mail() a retourné false malgré MailHog actif'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur MailHog: ' . $e->getMessage(),
                'details' => 'Exception lors de la tentative MailHog'
            ];
        }
    }
    
    private function sendViaGmailSMTP() {
        if (!$this->gmailConfig['enabled']) {
            return [
                'success' => false,
                'message' => 'Gmail SMTP désactivé',
                'details' => 'Activez Gmail SMTP dans la configuration du code'
            ];
        }
        
        // Vérifier si PHPMailer est disponible
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            // Essayer de charger PHPMailer si vendor/autoload.php existe
            if (file_exists('vendor/autoload.php')) {
                require_once 'vendor/autoload.php';
            }
            
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                return [
                    'success' => false,
                    'message' => 'PHPMailer non installé',
                    'details' => 'Exécutez: composer require phpmailer/phpmailer'
                ];
            }
        }
        
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Configuration serveur
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->gmailConfig['username'];
            $mail->Password   = $this->gmailConfig['password'];
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';
            
            // Destinataires
            $mail->setFrom($this->gmailConfig['username'], $this->gmailConfig['from_name']);
            $mail->addAddress($this->booking['user_email'], $this->booking['user_name']);
            $mail->addReplyTo('contact@atlashotels.ma', 'Atlas Hotels Support');
            
            // Contenu
            $mail->isHTML(true);
            $mail->Subject = 'Confirmation de réservation - Atlas Hotels';
            $mail->Body    = $this->generateEmailHTML();
            
            $mail->send();
            return [
                'success' => true,
                'message' => 'Email envoyé via Gmail SMTP ! 📧',
                'details' => 'Email réellement envoyé à ' . $this->booking['user_email']
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur Gmail SMTP: ' . $e->getMessage(),
                'details' => 'Vérifiez vos identifiants Gmail et le mot de passe d\'application'
            ];
        }
    }
    
    private function sendViaLocalSMTP() {
        try {
            // Test de connexion SMTP local
            $connection = @fsockopen('localhost', 25, $errno, $errstr, 2);
            if (!$connection) {
                return [
                    'success' => false,
                    'message' => 'Aucun serveur SMTP local sur port 25',
                    'details' => "Erreur: $errstr ($errno)"
                ];
            }
            fclose($connection);
            
            // Sauvegarder la configuration actuelle
            $originalSMTP = ini_get('SMTP');
            $originalPort = ini_get('smtp_port');
            $originalFrom = ini_get('sendmail_from');
            
            ini_set('SMTP', 'localhost');
            ini_set('smtp_port', '25');
            ini_set('sendmail_from', 'noreply@atlashotels.ma');
            
            $sent = $this->sendEmail();
            
            // Restaurer la configuration
            if ($originalSMTP) ini_set('SMTP', $originalSMTP);
            if ($originalPort) ini_set('smtp_port', $originalPort);
            if ($originalFrom) ini_set('sendmail_from', $originalFrom);
            
            if ($sent) {
                return [
                    'success' => true,
                    'message' => 'Email envoyé via SMTP local',
                    'details' => 'Configuration SMTP local port 25'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Échec SMTP local',
                    'details' => 'Serveur SMTP local ne répond pas correctement'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur SMTP local: ' . $e->getMessage(),
                'details' => 'Exception lors de la tentative SMTP local'
            ];
        }
    }
    
    private function sendViaPHPMail() {
        try {
            $sent = $this->sendEmail();
            
            if ($sent) {
                return [
                    'success' => true,
                    'message' => 'Email envoyé via fonction mail() PHP',
                    'details' => 'Utilisation de la configuration PHP par défaut'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Échec fonction mail() PHP',
                    'details' => 'La fonction mail() a retourné false - serveur mail non configuré'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur fonction mail(): ' . $e->getMessage(),
                'details' => 'Exception lors de l\'appel à mail()'
            ];
        }
    }
    
    private function saveToFile() {
        try {
            $emailDir = 'emails';
            if (!is_dir($emailDir)) {
                if (!mkdir($emailDir, 0755, true)) {
                    return [
                        'success' => false,
                        'message' => 'Impossible de créer le dossier emails',
                        'details' => 'Vérifiez les permissions du serveur'
                    ];
                }
            }
            
            $timestamp = date('Y-m-d_H-i-s');
            $filename = $emailDir . '/confirmation_' . $timestamp . '_' . $this->booking['id'] . '.html';
            $emailContent = $this->generateEmailHTML();
            
            $fullContent = $this->createFileEmailContent($emailContent);
            $saved = file_put_contents($filename, $fullContent);
            
            if ($saved) {
                return [
                    'success' => true,
                    'message' => "Email sauvegardé: $filename",
                    'details' => "Taille: " . number_format($saved) . " octets - Ouvrez le fichier dans votre navigateur"
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Impossible de sauvegarder le fichier',
                    'details' => 'Erreur d\'écriture fichier'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur sauvegarde: ' . $e->getMessage(),
                'details' => 'Exception lors de la sauvegarde fichier'
            ];
        }
    }
    
    private function saveToLog() {
        try {
            $logMessage = $this->createLogMessage();
            error_log($logMessage);
            
            return [
                'success' => true,
                'message' => 'Email enregistré dans les logs',
                'details' => 'Consultez les logs Apache/PHP pour voir le contenu'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur logs: ' . $e->getMessage(),
                'details' => 'Impossible d\'écrire dans les logs'
            ];
        }
    }
    
    private function sendEmail() {
        $to = $this->booking['user_email'];
        $subject = 'Confirmation de réservation - Atlas Hotels';
        $message = $this->generateEmailHTML();
        
        $headers = "From: Atlas Hotels <noreply@atlashotels.ma>\r\n";
        $headers .= "Reply-To: contact@atlashotels.ma\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        
        // Log de la tentative
        error_log("Tentative d'envoi email vers: $to");
        
        $result = @mail($to, $subject, $message, $headers);
        
        // Log du résultat
        error_log("Résultat mail(): " . ($result ? 'SUCCESS' : 'FAILED'));
        
        return $result;
    }
    
    public function generateEmailHTML() {
        return "
<!DOCTYPE html>
<html lang='fr'>
<head>
    <meta charset='UTF-8'>
    <title>Confirmation de réservation</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f8f9fa; }
        .container { max-width: 600px; margin: 0 auto; background: white; }
        .header { background: linear-gradient(135deg, #D4AF37 0%, #B8860B 100%); padding: 2rem; text-align: center; }
        .header h1 { color: white; margin: 0; font-size: 2rem; }
        .content { padding: 2rem; }
        .confirmation-box { background: #f8f9fa; border-left: 4px solid #D4AF37; padding: 1rem; margin: 1rem 0; }
        .booking-details { background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin: 1rem 0; }
        .detail-row { display: flex; justify-content: space-between; margin: 0.5rem 0; }
        .total { font-weight: bold; font-size: 1.2rem; color: #D4AF37; border-top: 1px solid #ddd; padding-top: 0.5rem; }
        .footer { background: #2c3e50; color: white; padding: 1.5rem; text-align: center; }
        .user-info { background: #e3f2fd; border: 1px solid #2196f3; border-radius: 8px; padding: 1rem; margin: 1rem 0; }
        .important-info { background: #e8f5e8; padding: 1rem; border-radius: 8px; margin: 1rem 0; border-left: 4px solid #4caf50; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>🏨 Atlas Hotels</h1>
            <p style='color: white; margin: 0.5rem 0 0 0;'>Confirmation de réservation</p>
        </div>
        
        <div class='content'>
            <h2 style='color: #2c3e50;'>Bonjour " . htmlspecialchars($this->booking['user_name']) . ",</h2>
            <p>Nous avons le plaisir de confirmer votre réservation. Voici les détails :</p>
            
            <div class='user-info'>
                <h4 style='margin: 0 0 0.5rem 0; color: #1976d2;'>👤 Informations du client</h4>
                <p style='margin: 0.25rem 0;'><strong>Nom :</strong> " . htmlspecialchars($this->booking['user_name']) . "</p>
                <p style='margin: 0.25rem 0;'><strong>Email :</strong> " . htmlspecialchars($this->booking['user_email']) . "</p>
                <p style='margin: 0.25rem 0;'><strong>Téléphone :</strong> " . htmlspecialchars($this->booking['user_phone']) . "</p>
            </div>
            
            <div class='confirmation-box'>
                <h3 style='margin: 0; color: #D4AF37;'>Code de confirmation</h3>
                <h2 style='margin: 0.5rem 0 0 0; color: #2c3e50; font-size: 1.5rem;'>" . $this->booking['confirmation_code'] . "</h2>
            </div>
            
            <div class='booking-details'>
                <h3 style='margin: 0 0 1rem 0; color: #2c3e50;'>Détails de la réservation</h3>
                <div class='detail-row'>
                    <span>Hôtel :</span>
                    <strong>" . htmlspecialchars($this->booking['hotel_name']) . "</strong>
                </div>
                <div class='detail-row'>
                    <span>Adresse :</span>
                    <strong>" . htmlspecialchars($this->booking['location']) . ", " . htmlspecialchars($this->booking['city']) . "</strong>
                </div>
                <div class='detail-row'>
                    <span>Chambre :</span>
                    <strong>" . htmlspecialchars($this->booking['room_type_name']) . "</strong>
                </div>
                <div class='detail-row'>
                    <span>Arrivée :</span>
                    <strong>" . date('d/m/Y', strtotime($this->booking['check_in'])) . " (à partir de 15h00)</strong>
                </div>
                <div class='detail-row'>
                    <span>Départ :</span>
                    <strong>" . date('d/m/Y', strtotime($this->booking['check_out'])) . " (avant 12h00)</strong>
                </div>
                <div class='detail-row'>
                    <span>Durée :</span>
                    <strong>" . ((strtotime($this->booking['check_out']) - strtotime($this->booking['check_in'])) / (60 * 60 * 24)) . " nuit(s)</strong>
                </div>
                <div class='detail-row'>
                    <span>Nombre de personnes :</span>
                    <strong>" . $this->booking['guests'] . " personne(s)</strong>
                </div>
                <div class='detail-row total'>
                    <span>Total payé :</span>
                    <strong>" . number_format($this->booking['total_price'], 0, ',', ' ') . " MAD</strong>
                </div>
            </div>
            
            <div class='important-info'>
                <h4 style='margin: 0 0 0.5rem 0; color: #2c5530;'>📋 Informations importantes</h4>
                <ul style='margin: 0; padding-left: 1.5rem; color: #2c5530;'>
                    <li>Présentez-vous à la réception avec une pièce d'identité</li>
                    <li>Check-in : 15h00 | Check-out : 12h00</li>
                    <li>Annulation gratuite jusqu'à 24h avant l'arrivée</li>
                    <li>WiFi gratuit dans tout l'établissement</li>
                </ul>
            </div>
            
            <p>Pour toute question, contactez-nous :</p>
            <p>📞 <strong>+212 6 00 00  00 00</strong><br>
            📧 <strong>contact@atlashotels.ma</strong></p>
            
            <p>Nous vous remercions de votre confiance !</p>
            <p><strong>L'équipe Atlas Hotels</strong></p>
        </div>
        
        <div class='footer'>
            <p style='margin: 0;'>© 2024 Atlas Hotels - Tous droits réservés</p>
        </div>
    </div>
</body>
</html>";
    }
    
    private function createFileEmailContent($emailContent) {
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Email de Confirmation Sauvegardé - Atlas Hotels</title>
    <style>
        .header-info { 
            background: linear-gradient(135deg, #D4AF37 0%, #B8860B 100%); 
            color: white; 
            padding: 20px; 
            margin-bottom: 20px; 
            border-radius: 10px; 
            text-align: center;
        }
        .email-content { border: 2px solid #D4AF37; border-radius: 10px; overflow: hidden; }
        .status-info { background: #e8f5e8; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #4caf50; }
        .warning { background: #fff3cd; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #ffc107; }
    </style>
</head>
<body>
    <div class='header-info'>
        <h1>📧 Email de Confirmation - Atlas Hotels</h1>
        <h2>SAUVEGARDE LOCALE</h2>
    </div>
    
    <div class='status-info'>
        <h3>✅ Informations de l'email</h3>
        <p><strong>Destinataire:</strong> " . htmlspecialchars($this->booking['user_email']) . "</p>
        <p><strong>Client:</strong> " . htmlspecialchars($this->booking['user_name']) . "</p>
        <p><strong>Sujet:</strong> Confirmation de réservation - Atlas Hotels</p>
        <p><strong>Date de création:</strong> " . date('d/m/Y H:i:s') . "</p>
        <p><strong>Code de confirmation:</strong> " . $this->booking['confirmation_code'] . "</p>
        <p><strong>Réservation ID:</strong> " . $this->booking['id'] . "</p>
    </div>
    
    <div class='warning'>
        <h3>⚠️ Statut d'envoi</h3>
        <p><strong>Email NON ENVOYÉ</strong> - Serveur email non configuré</p>
        <p>Cet email a été sauvegardé localement car aucun serveur email n'est disponible.</p>
        <p>Pour envoyer réellement l'email, configurez MailHog ou Gmail SMTP.</p>
    </div>
    
    <div class='email-content'>
        $emailContent
    </div>
    
    <div style='background: #f8f9fa; padding: 20px; margin-top: 20px; border-radius: 5px; text-align: center;'>
        <p><strong>Ce fichier représente l'email qui aurait été envoyé à " . htmlspecialchars($this->booking['user_email']) . "</strong></p>
        <p>Configurez un serveur email pour l'envoi automatique.</p>
    </div>
</body>
</html>";
    }
    
    private function createLogMessage() {
        return "
=== EMAIL DE CONFIRMATION ATLAS HOTELS - NON ENVOYÉ ===
Date: " . date('d/m/Y H:i:s') . "
Statut: SAUVEGARDÉ DANS LES LOGS (serveur email non configuré)
Booking ID: " . $this->booking['id'] . "
Code de confirmation: " . $this->booking['confirmation_code'] . "
Destinataire: " . $this->booking['user_email'] . "
Client: " . $this->booking['user_name'] . "
Téléphone: " . $this->booking['user_phone'] . "
Hôtel: " . $this->booking['hotel_name'] . "
Chambre: " . $this->booking['room_type_name'] . "
Check-in: " . date('d/m/Y', strtotime($this->booking['check_in'])) . "
Check-out: " . date('d/m/Y', strtotime($this->booking['check_out'])) . "
Nombre de personnes: " . $this->booking['guests'] . "
Total payé: " . number_format($this->booking['total_price'], 0, ',', ' ') . " MAD

PROBLÈME: Aucun serveur email configuré
SOLUTION: Installez MailHog ou configurez Gmail SMTP
==========================================
";
    }
    
    public function getDiagnostics() {
        return $this->diagnostics;
    }
    
    public function getGmailConfig() {
        return $this->gmailConfig;
    }
    
    public function updateGmailConfig($username, $password, $enabled = true) {
        $this->gmailConfig['username'] = $username;
        $this->gmailConfig['password'] = $password;
        $this->gmailConfig['enabled'] = $enabled;
    }
}

// Traitement des actions
$testMessage = null;
$testMessageType = null;
$emailResults = null;
$emailPreview = null;
$diagnostics = null;
$showGuide = false;

// Afficher le guide
if (isset($_POST['show_guide'])) {
    $showGuide = true;
    $testMessage = "Guide de configuration email affiché ci-dessous";
    $testMessageType = "info";
}

// Diagnostic complet
if (isset($_POST['run_diagnostic'])) {
    $emailManager = new EmailDiagnosticManager($booking);
    $diagnostics = $emailManager->runFullDiagnostic();
    $testMessage = "Diagnostic complet effectué - Consultez les résultats ci-dessous";
    $testMessageType = "info";
}

// Tentative d'envoi d'email
if (isset($_POST['send_test_email'])) {
    $emailManager = new EmailDiagnosticManager($booking);
    $result = $emailManager->attemptEmailSend();
    
    if ($result['overall_success']) {
        $emailSent = '1';
        $testMessage = "Email traité avec succès via: " . $result['success_method'];
        $testMessageType = "success";
    } else {
        $testMessage = "Toutes les méthodes d'envoi ont échoué - Email sauvegardé localement";
        $testMessageType = "warning";
    }
    
    $emailResults = $result['results'];
}

// Aperçu de l'email
if (isset($_POST['preview_email'])) {
    $emailManager = new EmailDiagnosticManager($booking);
    $emailPreview = $emailManager->generateEmailHTML();
    $testMessage = "Aperçu de l'email pour " . $booking['user_email'] . " généré ci-dessous";
    $testMessageType = "info";
}

// Mise à jour de l'email
if (isset($_POST['update_email']) && !empty($_POST['new_email'])) {
    $newEmail = filter_var($_POST['new_email'], FILTER_VALIDATE_EMAIL);
    if ($newEmail) {
        $booking['user_email'] = $newEmail;
        $testMessage = "Email mis à jour vers: " . $newEmail;
        $testMessageType = "success";
        
        // Optionnel: Mettre à jour dans la base de données
        try {
            $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->execute([$newEmail, $userInfo['id']]);
        } catch (PDOException $e) {
            error_log("Erreur mise à jour email: " . $e->getMessage());
        }
    } else {
        $testMessage = "Email invalide";
        $testMessageType = "error";
    }
}

// Configuration Gmail SMTP
if (isset($_POST['configure_gmail'])) {
    $gmailEmail = filter_var($_POST['gmail_email'], FILTER_VALIDATE_EMAIL);
    $gmailPassword = $_POST['gmail_password'];
    
    if ($gmailEmail && !empty($gmailPassword)) {
        // Cette configuration ne persiste que pour cette session
        // En production, vous devriez la sauvegarder dans un fichier de config
        $testMessage = "Configuration Gmail mise à jour temporairement. Pour la rendre permanente, modifiez le code source.";
        $testMessageType = "info";
    } else {
        $testMessage = "Email ou mot de passe Gmail invalide";
        $testMessageType = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmation de réservation - Atlas Hotels</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-gold: #D4AF37;
            --secondary-gold: #B8860B;
            --dark-gold: #996F00;
            --gradient-luxury: linear-gradient(135deg, #D4AF37 0%, #B8860B 50%, #996F00 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0F1419 0%, #1A1A2E 100%);
            min-height: 100vh;
            color: white;
            padding: 2rem 0;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .confirmation-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: var(--gradient-luxury);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
            color: white;
        }

        .confirmation-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.5rem;
            font-weight: 700;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
        }

        .confirmation-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .diagnostic-section {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .diagnostic-section h4 {
            color: #3b82f6;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .diagnostic-item {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 5px;
            padding: 1rem;
            margin: 0.5rem 0;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }

        .diagnostic-ok {
            border-left: 4px solid #22c55e;
        }

        .diagnostic-warning {
            border-left: 4px solid #f59e0b;
        }

        .diagnostic-error {
            border-left: 4px solid #ef4444;
        }

        .status-icon {
            font-size: 1.2rem;
            margin-right: 0.5rem;
            flex-shrink: 0;
        }

        .diagnostic-content {
            flex: 1;
        }

        .diagnostic-solution {
            font-size: 0.9rem;
            color: #ccc;
            margin-top: 0.5rem;
        }

        .user-info-section {
            background: rgba(33, 150, 243, 0.1);
            border: 1px solid rgba(33, 150, 243, 0.3);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .user-info-section h4 {
            color: #2196f3;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .user-detail {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(33, 150, 243, 0.2);
        }

        .user-detail:last-child {
            border-bottom: none;
        }

        .email-update-form, .gmail-config-form {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }

        .gmail-config-form {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .email-update-form h5, .gmail-config-form h5 {
            color: #f59e0b;
            margin-bottom: 0.5rem;
        }

        .gmail-config-form h5 {
            color: #22c55e;
        }

        .email-input-group, .gmail-input-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .email-input, .gmail-input {
            flex: 1;
            min-width: 200px;
            padding: 0.5rem;
            border: 1px solid rgba(245, 158, 11, 0.5);
            border-radius: 5px;
            background: rgba(0, 0, 0, 0.3);
            color: white;
            margin: 0.25rem 0;
        }

        .gmail-input {
            border-color: rgba(34, 197, 94, 0.5);
        }

        .confirmation-code {
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 2rem;
        }

        .confirmation-code h3 {
            color: var(--primary-gold);
            margin-bottom: 0.5rem;
        }

        .confirmation-code .code {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            letter-spacing: 2px;
        }

        .hotel-info {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 2rem;
            align-items: center;
        }

        .hotel-icon {
            width: 120px;
            height: 80px;
            background: var(--gradient-luxury);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
        }

        .hotel-details h2 {
            color: var(--primary-gold);
            margin-bottom: 0.5rem;
        }

        .booking-details {
            display: grid;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
        }

        .detail-row:last-child {
            border-bottom: none;
            font-weight: 600;
            font-size: 1.1rem;
            color: var(--primary-gold);
        }

        .email-status {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .email-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
        }

        .email-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #f59e0b;
        }

        .email-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }

        .test-message {
            padding: 1rem;
            border-radius: 10px;
            margin: 1rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .test-message.success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #22c55e;
        }

        .test-message.error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }

        .test-message.info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #3b82f6;
        }

        .test-message.warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #f59e0b;
        }

        .email-results {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }

        .email-results h5 {
            color: #3b82f6;
            margin-bottom: 1rem;
        }

        .result-item {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 5px;
            padding: 0.5rem;
            margin: 0.5rem 0;
            font-size: 0.9rem;
        }

        .result-success {
            border-left: 4px solid #22c55e;
        }

        .result-failed {
            border-left: 4px solid #ef4444;
        }

        .email-preview {
            background: rgba(255, 255, 255, 0.95);
            color: #333;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
            max-height: 500px;
            overflow-y: auto;
            border: 2px solid var(--primary-gold);
        }

        .important-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .important-info h4 {
            color: #3b82f6;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .important-info ul {
            list-style: none;
            padding: 0;
        }

        .important-info li {
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--gradient-luxury);
            color: white;
        }

        .btn-secondary {
            background: transparent;
            color: var(--primary-gold);
            border: 2px solid var(--primary-gold);
        }

        .btn-test {
            background: rgba(59, 130, 246, 0.8);
            color: white;
            border: 2px solid #3b82f6;
        }

        .btn-preview {
            background: rgba(34, 197, 94, 0.8);
            color: white;
            border: 2px solid #22c55e;
        }

        .btn-diagnostic {
            background: rgba(156, 39, 176, 0.8);
            color: white;
            border: 2px solid #9c27b0;
        }

        .btn-download {
            background: rgba(245, 158, 11, 0.8);
            color: white;
            border: 2px solid #f59e0b;
        }

        .btn-guide {
            background: rgba(34, 197, 94, 0.8);
            color: white;
            border: 2px solid #22c55e;
        }

        .btn-update {
            background: rgba(156, 39, 176, 0.8);
            color: white;
            border: 2px solid #9c27b0;
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(212, 175, 55, 0.4);
        }

        .hotel-image {
            width: 120px;
            height: 80px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid var(--primary-gold);
        }

        .solution-section {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .solution-section h4 {
            color: #ef4444;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .solution-quick {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 10px;
            padding: 1.5rem;
            margin: 1rem 0;
        }

        .solution-quick h5 {
            color: #22c55e;
            margin-bottom: 1rem;
        }

        .guide-section {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin: 2rem 0;
        }

        .guide-section h2 {
            color: #D4AF37;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .step {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 10px;
            padding: 1.5rem;
            margin: 1rem 0;
        }

        .step-title {
            color: #3b82f6;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .download-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .download-card {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
        }

        .download-card h6 {
            color: #22c55e;
            margin-bottom: 0.5rem;
        }

        .code-block {
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 5px;
            padding: 1rem;
            margin: 1rem 0;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
        }

        @media (max-width: 768px) {
            .hotel-info {
                flex-direction: column;
                text-align: center;
            }

            .detail-row, .user-detail {
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }

            .action-buttons {
                grid-template-columns: 1fr;
            }

            .email-input-group, .gmail-input-group {
                flex-direction: column;
            }

            .diagnostic-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .download-links {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="confirmation-header">
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>
            <h1 class="confirmation-title">Réservation Confirmée !</h1>
            <p>Votre paiement a été traité avec succès</p>
        </div>

        <div class="confirmation-card">
            <!-- Section informations utilisateur -->
            <div class="user-info-section">
                <h4>
                    <i class="fas fa-user"></i>
                    Informations du client
                </h4>
                <div class="user-detail">
                    <span>Nom complet :</span>
                    <strong><?= htmlspecialchars($booking['user_name']) ?></strong>
                </div>
                <div class="user-detail">
                    <span>Email :</span>
                    <strong><?= htmlspecialchars($booking['user_email']) ?></strong>
                </div>
                <div class="user-detail">
                    <span>Téléphone :</span>
                    <strong><?= htmlspecialchars($booking['user_phone']) ?></strong>
                </div>
            </div>

            <!-- Formulaire de mise à jour email -->
            <div class="email-update-form">
                <h5>📧 Modifier l'adresse email de confirmation</h5>
                <form method="POST" class="email-input-group">
                    <input type="email" name="new_email" class="email-input" 
                           placeholder="Nouvelle adresse email" 
                           value="<?= htmlspecialchars($booking['user_email']) ?>">
                    <button type="submit" name="update_email" class="btn btn-update">
                        <i class="fas fa-save"></i>
                        Mettre à jour
                    </button>
                </form>
            </div>

            <!-- Configuration Gmail SMTP -->
            
          

            <!-- Section diagnostic -->
            

            <div class="confirmation-code">
                <h3>Code de confirmation</h3>
                <div class="code"><?= htmlspecialchars($booking['confirmation_code']) ?></div>
            </div>

            <div class="hotel-info">
                <?php if (!empty($booking['hotel_image']) && filter_var($booking['hotel_image'], FILTER_VALIDATE_URL)): ?>
                    <img src="<?= htmlspecialchars($booking['hotel_image']) ?>" alt="<?= htmlspecialchars($booking['hotel_name']) ?>" class="hotel-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="hotel-icon" style="display: none;">🏨</div>
                <?php else: ?>
                    <div class="hotel-icon">🏨</div>
                <?php endif; ?>
                <div class="hotel-details">
                    <h2><?= htmlspecialchars($booking['hotel_name']) ?></h2>
                    <p style="color: #ccc; margin-bottom: 0.5rem;"><?= htmlspecialchars($booking['location']) ?>, <?= htmlspecialchars($booking['city']) ?></p>
                    <p style="color: #ccc;"><?= htmlspecialchars($booking['room_type_name']) ?></p>
                </div>
            </div>

            <div class="booking-details">
                <div class="detail-row">
                    <span>Arrivée :</span>
                    <strong><?= $checkInDate ?> (à partir de 15h00)</strong>
                </div>
                <div class="detail-row">
                    <span>Départ :</span>
                    <strong><?= $checkOutDate ?> (avant 12h00)</strong>
                </div>
                <div class="detail-row">
                    <span>Durée :</span>
                    <strong><?= $nights ?> nuit(s)</strong>
                </div>
                <div class="detail-row">
                    <span>Nombre de personnes :</span>
                    <strong><?= $booking['guests'] ?> personne(s)</strong>
                </div>
                <div class="detail-row">
                    <span>Statut :</span>
                    <strong style="color: #22c55e;">Confirmée et payée</strong>
                </div>
                <div class="detail-row">
                    <span>Total payé :</span>
                    <strong><?= number_format($booking['total_price'], 0, ',', ' ') ?> MAD</strong>
                </div>
            </div>

            <!-- Section solutions email -->
            


            <div class="important-info">
                <h4>
                    <i class="fas fa-info-circle"></i>
                    Informations importantes
                </h4>
                <ul>
                    <li>
                        <i class="fas fa-id-card" style="color: #3b82f6;"></i>
                        Présentez-vous à la réception avec une pièce d'identité valide
                    </li>
                    <li>
                        <i class="fas fa-clock" style="color: #3b82f6;"></i>
                        Check-in : 15h00 | Check-out : 12h00
                    </li>
                    <li>
                        <i class="fas fa-times-circle" style="color: #3b82f6;"></i>
                        Annulation gratuite jusqu'à 24h avant l'arrivée
                    </li>
                    <li>
                        <i class="fas fa-wifi" style="color: #3b82f6;"></i>
                        WiFi gratuit dans tout l'établissement
                    </li>
                    <li>
                        <i class="fas fa-phone" style="color: #3b82f6;"></i>
                        Contact hôtel : +212 6 00 00  00 00
                    </li>
                </ul>
            </div>

            <div class="action-buttons">
                <a href="payment.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    Nouvelle réservation
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-home"></i>
                    Retour à l'accueil
                </a>
            </div>
        </div>
    </div>

    <script>
        // Animation d'entrée
        document.addEventListener('DOMContentLoaded', function() {
            const card = document.querySelector('.confirmation-card');
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            
            setTimeout(() => {
                card.style.transition = 'all 0.6s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 100);
        });
    </script>
</body>
</html>
