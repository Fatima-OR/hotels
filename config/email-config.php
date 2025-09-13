<?php
// Configuration email pour XAMPP
// Décommentez et configurez selon votre environnement

// Configuration Gmail SMTP (nécessite un mot de passe d'application)
/*
ini_set('SMTP', 'smtp.gmail.com');
ini_set('smtp_port', '587');
ini_set('sendmail_from', 'votre-email@gmail.com');
*/

// Configuration MailHog pour les tests locaux
/*
ini_set('SMTP', 'localhost');
ini_set('smtp_port', '1025');
ini_set('sendmail_from', 'test@localhost');
*/

// Configuration serveur local (si configuré)
/*
ini_set('SMTP', 'localhost');
ini_set('smtp_port', '25');
ini_set('sendmail_from', 'noreply@localhost');
*/

// Fonction pour vérifier la configuration
function checkEmailConfig() {
    return [
        'smtp' => ini_get('SMTP'),
        'port' => ini_get('smtp_port'),
        'from' => ini_get('sendmail_from'),
        'configured' => !empty(ini_get('SMTP'))
    ];
}
?>
