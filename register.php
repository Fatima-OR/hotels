<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php'; // sanitizeInput() etc.

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupération et nettoyage des champs
    $full_name = sanitizeInput($_POST['full_name'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $address = sanitizeInput($_POST['address'] ?? '');
    $date_of_birth = sanitizeInput($_POST['date_of_birth'] ?? '');
    $role = sanitizeInput($_POST['role'] ?? 'user');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $terms = isset($_POST['terms']);
    $newsletter = isset($_POST['newsletter']) ? 1 : 0;

    // Validation simple
    if (!$terms) {
        $error = "Vous devez accepter les conditions d'utilisation.";
    } elseif (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = "Veuillez remplir tous les champs obligatoires.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "L'adresse email est invalide.";
    } elseif ($password !== $confirm_password) {
        $error = "Les mots de passe ne correspondent pas.";
    } elseif (!in_array($role, ['user', 'admin'])) {
        $error = "Rôle invalide.";
    } else {
        // Vérifier si email existe déjà
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            $error = "Cet email est déjà utilisé.";
        } else {
            // Hasher le mot de passe
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Préparation de la requête INSERT
            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, phone, address, role, date_of_birth, password, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

            $success_insert = $stmt->execute([
                $full_name,
                $email,
                $phone ?: null,
                $address ?: null,
                $role,
                $date_of_birth ?: null,
                $hashed_password
            ]);

            if ($success_insert) {
                $success = "Compte créé avec succès. Vous pouvez maintenant vous connecter.";
                // Optionnel : envoyer un mail de bienvenue, enregistrer newsletter etc.
            } else {
                $error = "Une erreur est survenue lors de la création du compte.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription - Atlas Hotels</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            --spacing-xs: 0.5rem;
            --spacing-sm: 1rem;
            --spacing-md: 1.5rem;
            --spacing-lg: 2rem;
            --spacing-xl: 3rem;
            --light-gray: #f0f0f0;
            --transition-fast: 0.2s ease;
            --transition-medium: 0.3s ease;
            --transition-slow: 0.5s ease;
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
            color: #D4AF37;
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

        /* Loading Screen */
        .loading-screen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--midnight);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            animation: loadingFadeOut 3s ease-in-out forwards;
        }

        .loading-content {
            text-align: center;
        }

        .loading-logo {
            font-size: 4rem;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: loadingPulse 2s ease-in-out infinite;
        }

        .loading-text {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            color: #D4AF37;
            margin-top: 1rem;
        }

        @keyframes loadingPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        @keyframes loadingFadeOut {
            0% { opacity: 1; visibility: visible; }
            90% { opacity: 1; visibility: visible; }
            100% { opacity: 0; visibility: hidden; }
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
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.3s ease;
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
            color: #888;
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
            color: #D4AF37;
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

        .nav-link:hover::before {
            width: 100%;
        }

        .nav-link:hover {
            color: var(--primary-gold);
        }

        /* Button Styles */
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
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
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

        .btn-large {
            padding: 1.5rem 3rem;
            font-size: 1.1rem;
        }

        .btn-full {
            width: 100%;
            justify-content: center;
        }

        /* Auth Container */
        .auth-container {
            min-height: 100vh;
            display: flex;
            position: relative;
            padding-top: 80px;
        }

        .auth-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
        }

        .auth-background img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: brightness(0.3);
        }

        .auth-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, 
                rgba(15, 20, 25, 0.9) 0%, 
                rgba(212, 175, 55, 0.1) 50%, 
                rgba(15, 20, 25, 0.95) 100%);
        }

        .auth-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            z-index: 2;
        }

        .form-container {
            background: rgba(15, 20, 25, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 3rem;
            max-width: 600px;
            width: 100%;
            border: 1px solid rgba(212, 175, 55, 0.2);
            box-shadow: 0 25px 50px var(--shadow-dark);
        }

        .auth-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .auth-logo {
            font-size: 3rem;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
        }

        .form-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.5rem;
            font-weight: 700;
            color: #D4AF37;
            margin-bottom: 0.5rem;
        }

        .form-subtitle {
            color: #888;
            font-size: 1.1rem;
        }

        /* Form Styles */
        .auth-form {
            margin-bottom: 2rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--spacing-md);
        }

        .form-group {
            margin-bottom: var(--spacing-lg);
        }

        .form-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-gold);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-input {
            width: 100%;
            padding: 1rem 1.5rem;
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 15px;
            color: #D4AF37;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-gold);
            background: rgba(212, 175, 55, 0.15);
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.2);
        }

        .form-input::placeholder {
            color: #666;
        }

        .password-input {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--primary-gold);
            cursor: pointer;
            padding: 0.5rem;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: #fff;
        }

        .password-strength {
            margin-top: var(--spacing-xs);
        }

        .strength-bar {
            height: 4px;
            background: var(--light-gray);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: var(--spacing-xs);
        }

        .strength-fill {
            height: 100%;
            transition: all var(--transition-medium);
            border-radius: 2px;
            width: 0%;
        }

        .strength-fill.weak { background: #ff4757; }
        .strength-fill.fair { background: #ff6b35; }
        .strength-fill.good { background: #f39c12; }
        .strength-fill.strong { background: #2ed573; }
        .strength-fill.very-strong { background: #1dd1a1; }

        .strength-text {
            font-size: 0.8rem;
            color: var(--charcoal);
            opacity: 0.7;
        }

        .form-options {
            margin-bottom: 2rem;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #888;
            margin-bottom: 1rem;
            cursor: pointer;
        }

        .checkbox-label input[type="checkbox"] {
            appearance: none;
            width: 20px;
            height: 20px;
            border: 2px solid var(--primary-gold);
            border-radius: 4px;
            position: relative;
            cursor: pointer;
        }

        .checkbox-label input[type="checkbox"]:checked {
            background: var(--gradient-luxury);
        }

        .checkbox-label input[type="checkbox"]:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: var(--midnight);
            font-weight: bold;
        }

        .terms-link {
            color: var(--primary-gold);
            text-decoration: none;
        }

        .terms-link:hover {
            text-decoration: underline;
        }

        /* Alert Styles */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-error {
            background: rgba(255, 71, 87, 0.2);
            border: 1px solid rgba(255, 71, 87, 0.5);
            color: #ff4757;
        }

        .alert-success {
            background: rgba(46, 213, 115, 0.2);
            border: 1px solid rgba(46, 213, 115, 0.5);
            color: #2ed573;
        }

        .auth-footer {
            text-align: center;
            color: #888;
        }

        .auth-link {
            color: var(--primary-gold);
            text-decoration: none;
            font-weight: 500;
        }

        .auth-link:hover {
            text-decoration: underline;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: var(--spacing-sm);
            }

            .form-container {
                padding: 2rem;
                margin: 1rem;
            }

            .form-title {
                font-size: 2rem;
            }

            .nav-menu {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .auth-content {
                padding: 1rem;
            }

            .form-container {
                padding: 1.5rem;
            }
        }
        /* Style pour le label du rôle */
label[for="role"] {
    display: block;
    font-weight: 600;
    color: var(--primary-gold);
    margin-bottom: 0.5rem;
    font-family: 'Playfair Display', serif;
    letter-spacing: 1px;
}

/* Style pour le select rôle */
#role {
    width: 100%;
    padding: 0.8rem 1.2rem;
    border-radius: 15px;
    background: rgba(212, 175, 55, 0.1);
    border: 1px solid rgba(212, 175, 55, 0.3);
    color: #D4AF37;
    font-size: 1rem;
    font-family: 'Inter', sans-serif;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    cursor: pointer;
    transition: border-color 0.3s ease, background 0.3s ease;
    background-image: linear-gradient(45deg, transparent 50%, var(--primary-gold) 50%),
                      linear-gradient(135deg, var(--primary-gold) 50%, transparent 50%);
    background-position: calc(100% - 20px) calc(1em + 2px), calc(100% - 15px) calc(1em + 2px);
    background-size: 5px 5px, 5px 5px;
    background-repeat: no-repeat;
}

#role:focus {
    outline: none;
    border-color: var(--primary-gold);
    background: rgba(212, 175, 55, 0.15);
    box-shadow: 0 0 15px var(--primary-gold);
}

    </style>
</head>
<body>
    
    <!-- Luxury Loading Screen -->
    <div class="loading-screen">
        <div class="loading-content">
            <div class="loading-logo"><i class="fas fa-crown"></i></div>
            <div class="loading-text">Atlas Hotels</div>
        </div>
    </div>

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
            <div class="nav-menu">
                <a href="index.php" class="nav-link">Accueil</a>
                <a href="hotels.php" class="nav-link">Hôtels</a>
                <a href="login.php" class="btn-primary">Se Connecter</a>
            </div>
        </div>
    </nav>

    <!-- Register Form -->
    <div class="auth-container">
        <div class="auth-background">
            <img src="https://images.pexels.com/photos/338504/pexels-photo-338504.jpeg?auto=compress&cs=tinysrgb&w=1920" alt="Luxury Hotel Background">
            <div class="auth-overlay"></div>
        </div>
        
        <div class="auth-content">
            <div class="form-container">
                <div class="auth-header">
                    <div class="auth-logo"><i class="fas fa-crown"></i></div>
                    <h1 class="form-title">Rejoignez-nous</h1>
                    <p class="form-subtitle">Créez votre compte privilégié Atlas Hotels</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="auth-form" novalidate>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user"></i> Nom Complet *</label>
                        <input type="text" name="full_name" class="form-input" placeholder="Votre nom complet" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required maxlength="100" />
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-envelope"></i> Adresse Email *</label>
                        <input type="email" name="email" class="form-input" placeholder="votre@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required maxlength="150" />
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-phone"></i> Téléphone</label>
                        <input type="tel" name="phone" class="form-input" placeholder="+212 6 00 00 00 00" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" maxlength="20" />
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-map-marker-alt"></i> Adresse</label>
                        <input type="text" name="address" class="form-input" placeholder="Votre adresse" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" maxlength="255" />
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-birthday-cake"></i> Date de naissance</label>
                        <input type="date" name="date_of_birth" class="form-input" value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>" />
                    </div>

                    <div class="form-group">
                        <label for="role">Type d'utilisateur *</label>
                        <select name="role" id="role" required>
                            <option value="user" <?= (($_POST['role'] ?? '') === 'user') ? 'selected' : '' ?>>Client</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-lock"></i> Mot de Passe *</label>
                            <div class="password-input">
                                <input type="password" name="password" class="form-input" placeholder="••••••••••" required minlength="6" maxlength="50" />
                                <button type="button" class="password-toggle" data-target="password"><i class="fas fa-eye"></i></button>
                            </div>
                            <div class="password-strength">
                                <div class="strength-bar"><div class="strength-fill"></div></div>
                                <span class="strength-text">Saisissez un mot de passe</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-lock"></i> Confirmer le Mot de Passe *</label>
                            <div class="password-input">
                                <input type="password" name="confirm_password" class="form-input" placeholder="••••••••••" required minlength="6" maxlength="50" />
                                <button type="button" class="password-toggle" data-target="confirm_password"><i class="fas fa-eye"></i></button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-options">
                        <label class="checkbox-label">
                            <input type="checkbox" name="terms" required <?= isset($_POST['terms']) ? 'checked' : '' ?> />
                            J'accepte les <a href="terms.php" class="terms-link">conditions d'utilisation</a>
                        </label>
                        
                        <label class="checkbox-label">
                            <input type="checkbox" name="newsletter" <?= isset($_POST['newsletter']) ? 'checked' : '' ?> />
                            Recevoir les offres exclusives par email
                        </label>
                    </div>
                    
                    <button type="submit" class="btn-primary btn-large btn-full">
                        <i class="fas fa-user-plus"></i> Créer mon Compte
                    </button>
                </form>
                
                <div class="auth-footer">
                    <p>Déjà un compte ? <a href="login.php" class="auth-link">Se connecter</a></p>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Password toggle functionality
        document.querySelectorAll('.password-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const target = this.dataset.target;
                const passwordInput = document.querySelector(`input[name="${target}"]`);
                const icon = this.querySelector('i');
                
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    passwordInput.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });

        // Password strength indicator
        const passwordInput = document.querySelector('input[name="password"]');
        const strengthBar = document.querySelector('.strength-fill');
        const strengthText = document.querySelector('.strength-text');

        if (passwordInput && strengthBar && strengthText) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                let strengthLabel = '';

                if (password.length >= 6) strength += 1;
                if (password.match(/[a-z]/)) strength += 1;
                if (password.match(/[A-Z]/)) strength += 1;
                if (password.match(/[0-9]/)) strength += 1;
                if (password.match(/[^a-zA-Z0-9]/)) strength += 1;

                const percentage = (strength / 5) * 100;
                strengthBar.style.width = percentage + '%';

                switch (strength) {
                    case 0:
                    case 1:
                        strengthBar.className = 'strength-fill weak';
                        strengthLabel = 'Très faible';
                        break;
                    case 2:
                        strengthBar.className = 'strength-fill fair';
                        strengthLabel = 'Faible';
                        break;
                    case 3:
                        strengthBar.className = 'strength-fill good';
                        strengthLabel = 'Correct';
                        break;
                    case 4:
                        strengthBar.className = 'strength-fill strong';
                        strengthLabel = 'Fort';
                        break;
                    case 5:
                        strengthBar.className = 'strength-fill very-strong';
                        strengthLabel = 'Très fort';
                        break;
                }

                strengthText.textContent = strengthLabel;
            });
        }
 
        // Luxury Cursor
        const cursor = document.querySelector('.luxury-cursor');
        if (cursor) {
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
        }

        // 3D Background Animation with Three.js
        // 3D Background Animation with Three.js
        function initThreeJSBackground() {
            const canvas = document.getElementById('bg-canvas');
            if (!canvas) return;

            const scene = new THREE.Scene();
            const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
            const renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true });
            
            renderer.setSize(window.innerWidth, window.innerHeight);
            renderer.setClearColor(0x000000, 0);

            // Create floating luxury elements
            const geometries = [
                new THREE.SphereGeometry(0.5, 32, 32),
                new THREE.OctahedronGeometry(0.7),
                new THREE.TetrahedronGeometry(0.6),
                new THREE.IcosahedronGeometry(0.5)
            ];

            const materials = [
                new THREE.MeshBasicMaterial({ 
                    color: 0xD4AF37, 
                    wireframe: true, 
                    transparent: true, 
                    opacity: 0.3 
                }),
                new THREE.MeshBasicMaterial({ 
                    color: 0xB8860B, 
                    wireframe: true, 
                    transparent: true, 
                    opacity: 0.2 
                }),
                new THREE.MeshBasicMaterial({ 
                    color: 0x996F00, 
                    wireframe: true, 
                    transparent: true, 
                    opacity: 0.25 
                })
            ];

            const objects = [];
            
            // Create 50 floating objects
            for (let i = 0; i < 50; i++) {
                const geometry = geometries[Math.floor(Math.random() * geometries.length)];
                const material = materials[Math.floor(Math.random() * materials.length)];
                const mesh = new THREE.Mesh(geometry, material);
                
                mesh.position.x = (Math.random() - 0.5) * 100;
                mesh.position.y = (Math.random() - 0.5) * 100;
                mesh.position.z = (Math.random() - 0.5) * 100;
                
                mesh.rotation.x = Math.random() * Math.PI;
                mesh.rotation.y = Math.random() * Math.PI;
                
                mesh.userData = {
                    rotationSpeed: {
                        x: (Math.random() - 0.5) * 0.02,
                        y: (Math.random() - 0.5) * 0.02,
                        z: (Math.random() - 0.5) * 0.02
                    },
                    floatSpeed: Math.random() * 0.005 + 0.001,
                    floatOffset: Math.random() * Math.PI * 2
                };
                
                scene.add(mesh);
                objects.push(mesh);
            }

            camera.position.z = 30;

            // Animation loop
            function animate() {
                requestAnimationFrame(animate);
                
                const time = Date.now() * 0.001;
                
                objects.forEach((obj, index) => {
                    // Rotation animation
                    obj.rotation.x += obj.userData.rotationSpeed.x;
                    obj.rotation.y += obj.userData.rotationSpeed.y;
                    obj.rotation.z += obj.userData.rotationSpeed.z;
                    
                    // Floating animation
                    obj.position.y += Math.sin(time * obj.userData.floatSpeed + obj.userData.floatOffset) * 0.01;
                });
                
                // Gentle camera movement
                camera.rotation.y = Math.sin(time * 0.1) * 0.1;
                camera.rotation.x = Math.cos(time * 0.15) * 0.05;
                
                renderer.render(scene, camera);
            }
            
            animate();

            // Handle window resize
            window.addEventListener('resize', () => {
                camera.aspect = window.innerWidth / window.innerHeight;
                camera.updateProjectionMatrix();
                renderer.setSize(window.innerWidth, window.innerHeight);
            });
        }

        // Initialize Three.js background
        initThreeJSBackground();

        // Form validation enhancements
        const form = document.querySelector('.auth-form');
        const inputs = form.querySelectorAll('.form-input');

        // Real-time validation
        inputs.forEach(input => {
            input.addEventListener('blur', validateField);
            input.addEventListener('input', clearFieldError);
        });

        function validateField(e) {
            const field = e.target;
            const value = field.value.trim();
            let isValid = true;
            let errorMessage = '';

            // Remove existing error styling
            field.classList.remove('error');
            removeFieldError(field);

            switch (field.name) {
                case 'full_name':
                    if (!value) {
                        isValid = false;
                        errorMessage = 'Le nom complet est requis';
                    } else if (value.length < 2) {
                        isValid = false;
                        errorMessage = 'Le nom doit contenir au moins 2 caractères';
                    }
                    break;

                case 'email':
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!value) {
                        isValid = false;
                        errorMessage = 'L\'adresse email est requise';
                    } else if (!emailRegex.test(value)) {
                        isValid = false;
                        errorMessage = 'Veuillez saisir une adresse email valide';
                    }
                    break;

                case 'phone':
                    if (value && !/^[\+]?[\s\-\(\)]*([0-9][\s\-\(\)]*){10,}$/.test(value)) {
                        isValid = false;
                        errorMessage = 'Veuillez saisir un numéro de téléphone valide';
                    }
                    break;

                case 'password':
                    if (!value) {
                        isValid = false;
                        errorMessage = 'Le mot de passe est requis';
                    } else if (value.length < 6) {
                        isValid = false;
                        errorMessage = 'Le mot de passe doit contenir au moins 6 caractères';
                    }
                    break;

                case 'confirm_password':
                    const password = form.querySelector('input[name="password"]').value;
                    if (!value) {
                        isValid = false;
                        errorMessage = 'Veuillez confirmer votre mot de passe';
                    } else if (value !== password) {
                        isValid = false;
                        errorMessage = 'Les mots de passe ne correspondent pas';
                    }
                    break;
            }

            if (!isValid) {
                showFieldError(field, errorMessage);
            }

            return isValid;
        }

        function showFieldError(field, message) {
            field.classList.add('error');
            
            const errorElement = document.createElement('div');
            errorElement.className = 'field-error';
            errorElement.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${message}`;
            
            field.parentNode.appendChild(errorElement);
        }

        function removeFieldError(field) {
            const errorElement = field.parentNode.querySelector('.field-error');
            if (errorElement) {
                errorElement.remove();
            }
        }

        function clearFieldError(e) {
            const field = e.target;
            field.classList.remove('error');
            removeFieldError(field);
        }

        // Form submission with enhanced validation
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            let isFormValid = true;
            const requiredFields = ['full_name', 'email', 'password', 'confirm_password'];
            
            // Validate all required fields
            requiredFields.forEach(fieldName => {
                const field = form.querySelector(`input[name="${fieldName}"]`);
                if (field && !validateField({ target: field })) {
                    isFormValid = false;
                }
            });

            // Check terms acceptance
            const termsCheckbox = form.querySelector('input[name="terms"]');
            if (!termsCheckbox.checked) {
                isFormValid = false;
                showAlert('Vous devez accepter les conditions d\'utilisation', 'error');
            }

            if (isFormValid) {
                // Add loading state
                const submitButton = form.querySelector('button[type="submit"]');
                const originalText = submitButton.innerHTML;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Création en cours...';
                submitButton.disabled = true;

                // Submit the form
                setTimeout(() => {
                    this.submit();
                }, 500);
            }
        });

        // Enhanced alert system
        function showAlert(message, type = 'info') {
            // Remove existing alerts
            document.querySelectorAll('.alert').forEach(alert => alert.remove());

            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            
            const icon = type === 'error' ? 'fa-exclamation-circle' : 
                        type === 'success' ? 'fa-check-circle' : 'fa-info-circle';
            
            alertDiv.innerHTML = `<i class="fas ${icon}"></i> ${message}`;
            
            const formContainer = document.querySelector('.form-container');
            const authHeader = formContainer.querySelector('.auth-header');
            authHeader.insertAdjacentElement('afterend', alertDiv);

            // Auto-hide alert after 5 seconds
            setTimeout(() => {
                alertDiv.style.opacity = '0';
                setTimeout(() => alertDiv.remove(), 300);
            }, 5000);
        }

        // Enhanced navbar scroll effect
        const navbar = document.querySelector('.navbar');
        let lastScrollY = window.scrollY;

        window.addEventListener('scroll', () => {
            const currentScrollY = window.scrollY;
            
            if (currentScrollY > 50) {
                navbar.style.background = 'rgba(15, 20, 25, 0.98)';
                navbar.style.boxShadow = '0 5px 20px rgba(0, 0, 0, 0.3)';
            } else {
                navbar.style.background = 'rgba(15, 20, 25, 0.95)';
                navbar.style.boxShadow = 'none';
            }

            // Hide/show navbar on scroll
            if (currentScrollY > lastScrollY && currentScrollY > 100) {
                navbar.style.transform = 'translateY(-100%)';
            } else {
                navbar.style.transform = 'translateY(0)';
            }
            
            lastScrollY = currentScrollY;
        });

        // Smooth form animations
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, { threshold: 0.1 });

        // Animate form elements on load
        document.querySelectorAll('.form-group').forEach((group, index) => {
            group.style.opacity = '0';
            group.style.transform = 'translateY(30px)';
            group.style.transition = `all 0.6s ease ${index * 0.1}s`;
            observer.observe(group);
        });

        // Enhanced loading screen
        window.addEventListener('load', () => {
            const loadingScreen = document.querySelector('.loading-screen');
            setTimeout(() => {
                loadingScreen.style.opacity = '0';
                setTimeout(() => {
                    loadingScreen.style.display = 'none';
                }, 500);
            }, 2000);
        });

        // Add hover effects for interactive elements
        document.querySelectorAll('.form-input').forEach(input => {
            input.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 5px 15px rgba(212, 175, 55, 0.2)';
            });
            
            input.addEventListener('mouseleave', function() {
                if (this !== document.activeElement) {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'none';
                }
            });
        });

        // Add styles for field errors
        const style = document.createElement('style');
        style.textContent = `
            .form-input.error {
                border-color: #ff4757 !important;
                background-color: rgba(255, 71, 87, 0.1) !important;
            }
            
            .field-error {
                color: #ff4757;
                font-size: 0.8rem;
                margin-top: 0.5rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
                animation: slideInError 0.3s ease;
            }
            
            @keyframes slideInError {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        `;
        document.head.appendChild(style);

        // Console welcome message
        console.log('%c🏨 Atlas Hotels Registration System', 'color: #D4AF37; font-size: 16px; font-weight: bold;');
        console.log('%cWelcome to our luxury registration experience!', 'color: #B8860B; font-size: 12px;');
    </script>
</body>
</html>