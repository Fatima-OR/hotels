<?php
session_start();

// Charger la connexion à la base de données en premier (doit définir $pdo)
require_once 'config/database.php';

// Charger les fonctions (qui utilisent $pdo)
require_once 'includes/functions.php';

// Initialisation variable d'erreur
$error = '';

// Vérifier que $pdo est bien un objet PDO
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Erreur de connexion à la base de données.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        // Appel de la fonction d'authentification
        $user = authenticateUser($pdo, $email, $password);

        if ($user) {
            // Création des variables de session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['logged_in'] = true;  // Indispensable pour vérifier la session
            $_SESSION['user_name'] = $user['full_name']; // optionnel
            $_SESSION['user_email'] = $user['email'];   // optionnel
            $_SESSION['user_role'] = $user['role']; // AJOUT ESSENTIE

            // Redirection vers la page utilisateur
            header('Location: user.php');
            exit();
        } else {
            $error = 'Email ou mot de passe incorrect.';
        }
    }
}
?>




<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Atlas Hotels</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .auth-container {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--royal-blue), var(--deep-burgundy));
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--spacing-md);
            position: relative;
            overflow: hidden;
        }
        
        .auth-container::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="moroccan" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="8" fill="none" stroke="rgba(212,175,55,0.1)" stroke-width="1"/></pattern></defs><rect width="100" height="100" fill="url(%23moroccan)"/></svg>');
            opacity: 0.1;
        }
        
        .auth-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: var(--spacing-xxl);
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-width: 400px;
            width: 100%;
            position: relative;
        }
        
        .auth-logo {
            text-align: center;
            margin-bottom: var(--spacing-xl);
        }
        
        .auth-logo i {
            font-size: 3rem;
            color: var(--primary-gold);
            margin-bottom: var(--spacing-md);
            display: block;
        }
        
        .auth-title {
            text-align: center;
            margin-bottom: var(--spacing-xl);
            color: var(--charcoal);
        }
        
        .auth-form {
            margin-bottom: var(--spacing-lg);
        }
        
        .auth-links {
            text-align: center;
        }
        
        .auth-links a {
            color: var(--primary-gold);
            text-decoration: none;
            font-weight: 500;
            transition: all var(--transition-fast);
        }
        
        .auth-links a:hover {
            color: var(--secondary-gold);
        }
        
        .input-group {
            position: relative;
            margin-bottom: var(--spacing-md);
        }
        
        .input-group i {
            position: absolute;
            left: var(--spacing-sm);
            top: 50%;
            transform: translateY(-50%);
            color: var(--charcoal);
            opacity: 0.5;
        }
        
        .input-group input {
            padding-left: 3rem;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-logo">
                <i class="fas fa-crown"></i>
                <div class="logo-text">
                    <div class="logo-main">Atlas Hotels</div>
                    <div class="logo-sub">Luxury Collection</div>
                </div>
            </div>
            
            <h2 class="auth-title">Connexion</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="auth-form">
                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" class="form-input" placeholder="Adresse email" required>
                </div>
                
                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" class="form-input" placeholder="Mot de passe" required>
                </div>
                
                <button type="submit" class="btn-primary w-full">
                    <i class="fas fa-sign-in-alt"></i>
                    Se connecter
                </button>
            </form>
            
            <div class="auth-links">
                <p>Pas encore de compte ? <a href="register.php">S'inscrire</a></p>
                <p><a href="index.php">Retour à l'accueil</a></p>
            </div>
        </div>
    </div>
    
    <script src="assets/js/main.js"></script>
</body>
</html>