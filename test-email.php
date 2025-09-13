<?php
require_once 'classes/EmailService.php';
require_once 'config/database.php';

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

$emailService = new EmailService($pdo);

// Test de configuration email
echo "<h1>Test de Configuration Email</h1>";

// Vérifier la configuration PHP
echo "<h2>Configuration PHP Mail</h2>";
echo "<p><strong>SMTP:</strong> " . ini_get('SMTP') . "</p>";
echo "<p><strong>smtp_port:</strong> " . ini_get('smtp_port') . "</p>";
echo "<p><strong>sendmail_from:</strong> " . ini_get('sendmail_from') . "</p>";

// Test d'envoi simple
echo "<h2>Test d'envoi simple</h2>";
$testResult = $emailService->testEmailConfiguration();
echo "<p>Résultat: " . ($testResult['success'] ? '✅' : '❌') . " " . $testResult['message'] . "</p>";

// Informations pour configurer XAMPP
echo "<h2>Configuration XAMPP pour l'envoi d'emails</h2>";
echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
echo "<h3>Option 1: Configuration Gmail SMTP</h3>";
echo "<p>1. Éditez le fichier <code>php.ini</code> dans XAMPP</p>";
echo "<p>2. Ajoutez ces lignes :</p>";
echo "<pre>
[mail function]
SMTP = smtp.gmail.com
smtp_port = 587
sendmail_from = votre-email@gmail.com
sendmail_path = \"C:\xampp\sendmail\sendmail.exe -t\"
</pre>";
echo "<p>3. Éditez <code>sendmail.ini</code> dans le dossier sendmail de XAMPP :</p>";
echo "<pre>
[sendmail]
smtp_server=smtp.gmail.com
smtp_port=587
auth_username=votre-email@gmail.com
auth_password=votre-mot-de-passe-app
hostname=localhost
</pre>";
echo "</div>";

echo "<div style='background: #e8f4fd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
echo "<h3>Option 2: Utiliser MailHog (Recommandé pour les tests)</h3>";
echo "<p>1. Téléchargez MailHog depuis GitHub</p>";
echo "<p>2. Lancez MailHog.exe</p>";
echo "<p>3. Configurez PHP pour utiliser MailHog :</p>";
echo "<pre>
[mail function]
SMTP = localhost
smtp_port = 1025
sendmail_from = test@localhost
</pre>";
echo "<p>4. Accédez à http://localhost:8025 pour voir les emails</p>";
echo "</div>";
?>
