<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>À Propos - Atlas Hotels</title>
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
            color: goldenrod;
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

        .navbar.scrolled {
            background: rgba(15, 20, 25, 0.98);
            box-shadow: 0 10px 40px var(--shadow-dark);
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
            cursor: pointer;
            transition: transform 0.3s ease;
            text-decoration: none;
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
            color: white;
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
            color: goldenrod;
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

        .nav-link:hover::before,
        .nav-link.active::before {
            width: 100%;
        }

        .nav-link:hover,
        .nav-link.active {
            color: var(--primary-gold);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: rgba(212, 175, 55, 0.1);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            border: 1px solid var(--primary-gold);
        }

        .user-name {
            color: var(--primary-gold);
            font-weight: 500;
        }

        .btn-logout {
            color: goldenrod;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .btn-logout:hover {
            background: var(--primary-gold);
            color: var(--midnight);
            transform: rotate(360deg);
        }

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

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        /* Hero Section */
        .hero {
            height: 100vh;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .hero-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
        }

        .hero-background img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: brightness(0.4) contrast(1.2);
            animation: heroZoom 20s ease-in-out infinite alternate;
        }

        @keyframes heroZoom {
            0% { transform: scale(1); }
            100% { transform: scale(1.1); }
        }

        .hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, 
                rgba(15, 20, 25, 0.8) 0%, 
                rgba(212, 175, 55, 0.1) 50%, 
                rgba(15, 20, 25, 0.9) 100%);
        }

        .hero-content {
            text-align: center;
            z-index: 2;
            max-width: 800px;
            padding: 0 2rem;
            animation: heroFadeIn 2s ease-out;
        }

        @keyframes heroFadeIn {
            0% { opacity: 0; transform: translateY(50px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        .hero-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(3rem, 8vw, 6rem);
            font-weight: 700;
            margin-bottom: 1.5rem;
            line-height: 1.2;
            color: goldenrod;
        }

        .gradient-text {
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: textShine 3s ease-in-out infinite alternate;
        }

        @keyframes textShine {
            0% { filter: brightness(1); }
            100% { filter: brightness(1.3); }
        }

        .hero-subtitle {
            font-size: 1.3rem;
            color: white;
            margin-bottom: 3rem;
            font-weight: 300;
            letter-spacing: 1px;
        }

        /* Story Section */
        .story-section {
            padding: 8rem 0;
            background: linear-gradient(135deg, var(--charcoal) 0%, var(--midnight) 100%);
            position: relative;
        }

        .story-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
            margin-bottom: 6rem;
        }

        .story-text h2 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(2.5rem, 4vw, 3.5rem);
            font-weight: 700;
            color: goldenrod;
            margin-bottom: 2rem;
            line-height: 1.3;
        }

        .story-text p {
            font-size: 1.2rem;
            color: white;
            margin-bottom: 2rem;
            line-height: 1.8;
        }

        .story-image {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
        }

        .story-image img {
            width: 100%;
            height: 500px;
            object-fit: cover;
            border-radius: 20px;
            transition: transform 0.5s ease;
        }

        .story-image:hover img {
            transform: scale(1.05);
        }

        /* Values Section */
        .values-section {
            padding: 8rem 0;
            background: var(--midnight);
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(2.5rem, 5vw, 4rem);
            font-weight: 700;
            color: goldenrod;
            text-align: center;
            margin-bottom: 1rem;
        }

        .section-subtitle {
            font-size: 1.2rem;
            color: white;
            text-align: center;
            max-width: 600px;
            margin: 0 auto 4rem;
            font-weight: 300;
        }

        .values-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 3rem;
        }

        .value-card {
            text-align: center;
            padding: 3rem 2rem;
            background: rgba(212, 175, 55, 0.05);
            border-radius: 20px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.5s ease;
            transform-style: preserve-3d;
        }

        .value-card:hover {
            transform: translateY(-15px) rotateX(5deg);
            background: rgba(212, 175, 55, 0.1);
            box-shadow: 0 25px 50px var(--shadow-dark);
        }

        .value-icon {
            font-size: 3rem;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1.5rem;
            animation: iconFloat 3s ease-in-out infinite;
        }

        @keyframes iconFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .value-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 600;
            color: goldenrod;
            margin-bottom: 1rem;
        }

        .value-description {
            color: white;
            line-height: 1.6;
        }

        /* Team Section */
        .team-section {
            padding: 8rem 0;
            background: linear-gradient(135deg, var(--charcoal) 0%, var(--midnight) 100%);
        }

        .team-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 3rem;
        }

        .team-card {
            text-align: center;
            background: rgba(212, 175, 55, 0.05);
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.5s ease;
            transform-style: preserve-3d;
        }

        .team-card:hover {
            transform: translateY(-15px) rotateY(5deg);
            box-shadow: 0 25px 50px var(--shadow-dark);
        }

        .team-image {
            height: 300px;
            overflow: hidden;
            position: relative;
        }

        .team-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .team-card:hover .team-image img {
            transform: scale(1.1);
        }

        .team-content {
            padding: 2rem;
        }

        .team-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: goldenrod;
            margin-bottom: 0.5rem;
        }

        .team-position {
            color: var(--primary-gold);
            font-weight: 500;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }

        .team-description {
            color: white;
            line-height: 1.6;
        }

        /* Timeline Section */
        .timeline-section {
            padding: 8rem 0;
            background: var(--midnight);
        }

        .timeline {
            position: relative;
            max-width: 1000px;
            margin: 0 auto;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 50%;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--gradient-luxury);
            transform: translateX(-50%);
        }

        .timeline-item {
            position: relative;
            margin-bottom: 4rem;
            display: flex;
            align-items: center;
        }

        .timeline-item:nth-child(even) {
            flex-direction: row-reverse;
        }

        .timeline-content {
            flex: 1;
            padding: 2rem;
            background: rgba(212, 175, 55, 0.05);
            border-radius: 20px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            margin: 0 2rem;
            transition: all 0.3s ease;
        }

        .timeline-content:hover {
            background: rgba(212, 175, 55, 0.1);
            transform: translateY(-5px);
        }

        .timeline-year {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-gold);
            margin-bottom: 1rem;
        }

        .timeline-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: goldenrod;
            margin-bottom: 1rem;
        }

        .timeline-description {
            color: white;
            line-height: 1.6;
        }

        .timeline-marker {
            position: absolute;
            left: 50%;
            width: 20px;
            height: 20px;
            background: var(--gradient-luxury);
            border-radius: 50%;
            transform: translateX(-50%);
            border: 4px solid var(--midnight);
            z-index: 2;
        }

        /* Footer */
        .footer {
            background: var(--deep-navy);
            padding: 4rem 0 2rem;
            border-top: 1px solid rgba(212, 175, 55, 0.2);
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
            margin-bottom: 2rem;
        }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            text-decoration: none;
        }

        .footer-logo i {
            font-size: 2rem;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .footer-description {
            color: white;
            line-height: 1.6;
        }

        .footer-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-gold);
            margin-bottom: 1rem;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.5rem;
        }

        .footer-links a {
            color: white;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--primary-gold);
        }

        .contact-info p {
            color: white;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .contact-info i {
            color: var(--primary-gold);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(212, 175, 55, 0.2);
            color: white;
        }

        /* Navigation Toggle */
        .nav-toggle {
            display: none;
            flex-direction: column;
            cursor: pointer;
            padding: 0.5rem;
        }

        .bar {
            width: 25px;
            height: 3px;
            background: var(--primary-gold);
            margin: 3px 0;
            transition: 0.3s;
            border-radius: 2px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .nav-toggle {
                display: flex;
            }

            .nav-menu {
                position: fixed;
                top: 100%;
                left: 0;
                width: 100%;
                background: rgba(15, 20, 25, 0.98);
                flex-direction: column;
                padding: 2rem;
                gap: 1rem;
                transform: translateY(-100%);
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
            }

            .nav-menu.active {
                transform: translateY(0);
                opacity: 1;
                visibility: visible;
            }

            .story-content,
            .timeline::before {
                grid-template-columns: 1fr;
            }

            .timeline::before {
                left: 20px;
            }

            .timeline-item {
                flex-direction: row !important;
                margin-left: 40px;
            }

            .timeline-marker {
                left: 20px;
            }

            .timeline-content {
                margin-left: 0;
            }
        }

        /* Loading Animation */
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
            color: goldenrod;
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
    </style>
</head>
<body>
    <!-- Luxury Loading Screen -->
    <div class="loading-screen">
        <div class="loading-content">
            <div class="loading-logo">
                <i class="fas fa-crown"></i>
            </div>
            <div class="loading-text">Atlas Hotels</div>
        </div>
    </div>

    <!-- Luxury Cursor -->
    <div class="luxury-cursor"></div>

    <!-- 3D Background Canvas -->
    <canvas id="bg-canvas"></canvas>

    <!-- Navigation -->
    <nav class="navbar" id="navbar">
        <div class="nav-container">
            <a href="index.php" class="nav-logo">
                <i class="fas fa-crown"></i>
                <div class="logo-text">
                    <span class="logo-main">Atlas Hotels</span>
                    <span class="logo-sub">Luxury Collection</span>
                </div>
            </a>
            
           <div class="nav-menu" id="nav-menu">
                <a href="index.php" class="nav-link ">Accueil</a>
                <a href="hotels.php" class="nav-link">Hôtels</a>
                <a href="user.php" class="nav-link">Profile</a>
                <a href="about.php" class="nav-link active">About</a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="bookings.php" class="nav-link">Mes Réservations</a>
                    <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
    <a href="admin.php" class="nav-link">Administration</a>
<?php endif; ?>

                    <div class="user-menu">
                        <span class="user-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                        <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i></a>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="nav-link">Connexion</a>
                    <a href="register.php" class="btn-primary">S'inscrire</a>
                <?php endif; ?>
            </div>
            
            <div class="nav-toggle" id="nav-toggle">
                <span class="bar"></span><span class="bar"></span><span class="bar"></span>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-background">
            <img src="https://images.unsplash.com/photo-1571896349842-33c89424de2d?w=1920&h=1080&fit=crop" alt="Luxury Hotel Lobby">
        </div>
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title">
                Notre <span class="gradient-text">Histoire</span>
            </h1>
            <p class="hero-subtitle">
                Une tradition d'excellence et d'hospitalité marocaine depuis des générations
            </p>
        </div>
    </section>

    <!-- Story Section -->
    <section class="story-section">
        <div class="container">
            <div class="story-content">
                <div class="story-text">
                    <h2>L'Art de l'Hospitalité Marocaine</h2>
                    <p>
                        Depuis trois générations, la famille Atlas perpétue une tradition d'excellence dans l'art de recevoir. 
                        Née de la passion pour l'hospitalité authentique marocaine, Atlas Hotels incarne l'élégance intemporelle 
                        et le raffinement contemporain.
                    </p>
                    <p>
                        Chaque établissement de notre collection reflète l'âme du Maroc moderne, alliant patrimoine culturel 
                        et innovations luxueuses. Nous créons des expériences uniques où chaque détail raconte une histoire, 
                        où chaque moment devient un souvenir précieux.
                    </p>
                    <p>
                        Notre mission transcende l'hébergement : nous sommes les gardiens d'un art de vivre, les ambassadeurs 
                        d'une culture riche et généreuse, les créateurs d'émotions authentiques.
                    </p>
                </div>
                <div class="story-image">
                    <video width="600" height="500" controls autoplay muted loop>
  <source src="https://v1.pinimg.com/videos/mc/720p/35/f8/5c/35f85c193fe123056ef6d40047a905b3.mp4" type="video/mp4">
  Votre navigateur ne supporte pas la lecture de vidéos.
</video>

                </div>
            </div>
        </div>
    </section>
    <section>
          
    </section>


    <!-- Values Section -->
    <section class="values-section">
        <div class="container">
            <h2 class="section-title">Nos Valeurs</h2>
            <p class="section-subtitle">
                Les piliers qui guident chacune de nos actions et définissent notre identité
            </p>
            
            <div class="values-grid">
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-gem"></i>
                    </div>
                    <h3 class="value-title">Excellence</h3>
                    <p class="value-description">
                        Nous poursuivons l'excellence dans chaque détail, de l'accueil à l'expérience globale, 
                        dépassant constamment les attentes de nos invités les plus exigeants.
                </p>
                </div>
                
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-heart"></i>
                    </div>
                    <h3 class="value-title">Authenticité</h3>
                    <p class="value-description">
                        Chaque expérience est imprégnée de l'authenticité marocaine, respectant nos traditions 
                        tout en embrassant la modernité pour créer des moments uniques et mémorables.
                    </p>
                </div>
                
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <h3 class="value-title">Hospitalité</h3>
                    <p class="value-description">
                        L'hospitalité marocaine est dans notre ADN. Nous accueillons chaque invité comme un membre 
                        de la famille, avec chaleur, générosité et attention personnalisée.
                    </p>
                </div>
                
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-leaf"></i>
                    </div>
                    <h3 class="value-title">Durabilité</h3>
                    <p class="value-description">
                        Nous nous engageons pour un tourisme responsable, préservant notre patrimoine naturel 
                        et culturel pour les générations futures tout en soutenant les communautés locales.
                    </p>
                </div>
                
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-lightbulb"></i>
                    </div>
                    <h3 class="value-title">Innovation</h3>
                    <p class="value-description">
                        Nous intégrons les dernières technologies et innovations pour améliorer constamment 
                        l'expérience client tout en préservant l'essence de notre hospitalité traditionnelle.
                    </p>
                </div>
                
                <div class="value-card">
                    <div class="value-icon">
                        <i class="fas fa-crown"></i>
                    </div>
                    <h3 class="value-title">Prestige</h3>
                    <p class="value-description">
                        Nous cultivons l'art du luxe discret et raffiné, créant des environnements prestigieux 
                        où chaque détail contribue à une expérience exceptionnelle et inoubliable.
                    </p>
                </div>
            </div>
        </div>
    </section>


    <!-- Timeline Section -->
    <section class="timeline-section">
        <div class="container">
            <h2 class="section-title">Notre Parcours</h2>
            <p class="section-subtitle">
                Les étapes marquantes de notre histoire
            </p>
            
          
                <div class="timeline-item">
                    <div class="timeline-marker"></div>
                    <div class="timeline-content">
                        <div class="timeline-year">2025</div>
                        <h3 class="timeline-title">Avenir Digital</h3>
                        <p class="timeline-description">
                            Lancement de notre plateforme digitale nouvelle génération, intégrant 
                            intelligence artificielle et personnalisation pour redéfinir l'hospitalité moderne.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div>
                    <a href="index.php" class="footer-logo">
                        <i class="fas fa-crown"></i>
                        <div class="logo-text">
                            <span class="logo-main">Atlas Hotels</span>
                            <span class="logo-sub">Luxury Collection</span>
                        </div>
                    </a>
                    <p class="footer-description">
                        Découvrez l'art de l'hospitalité marocaine à travers notre collection d'hôtels de luxe. 
                        Chaque séjour est une invitation au voyage et à l'émerveillement.
                    </p>
                </div>
                
                <div>
                    <h3 class="footer-title">Navigation</h3>
                    <ul class="footer-links">
                        <li><a href="index.php">Accueil</a></li>
                        <li><a href="hotels.php">Nos Hôtels</a></li>
                        <li><a href="about.php">À Propos</a></li>
                        <li><a href="contact.php">Contact</a></li>
                        <li><a href="login.php">Connexion</a></li>
                    </ul>
                </div>
                
                <div>
                    <h3 class="footer-title">Services</h3>
                    <ul class="footer-links">
                        <li><a href="#">Réservations</a></li>
                        <li><a href="#">Spa & Bien-être</a></li>
                        <li><a href="#">Gastronomie</a></li>
                        <li><a href="#">Événements</a></li>
                        <li><a href="#">Concierge</a></li>
                    </ul>
                </div>
                
                <div class="contact-info">
                    <h3 class="footer-title">Contact</h3>
                    <p><i class="fas fa-map-marker-alt"></i> 123 Boulevard Mohammed V, Casablanca</p>
                    <p><i class="fas fa-phone"></i> +212 522 123 456</p>
                    <p><i class="fas fa-envelope"></i> info@atlashotels.ma</p>
                    <p><i class="fas fa-globe"></i> www.atlashotels.ma</p>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; 2025 Atlas Hotels. Tous droits réservés. | Développé avec passion pour l'excellence marocaine.</p>
            </div>
        </div>
    </footer>

    <script>
        // Luxury Cursor
        const cursor = document.querySelector('.luxury-cursor');
        let mouseX = 0, mouseY = 0;
        let cursorX = 0, cursorY = 0;

        document.addEventListener('mousemove', (e) => {
            mouseX = e.clientX;
            mouseY = e.clientY;
        });

        function animateCursor() {
            cursorX += (mouseX - cursorX) * 0.1;
            cursorY += (mouseY - cursorY) * 0.1;
            cursor.style.transform = `translate(${cursorX - 10}px, ${cursorY - 10}px)`;
            requestAnimationFrame(animateCursor);
        }
        animateCursor();

        // Navigation Scroll Effect
        const navbar = document.getElementById('navbar');
        let lastScrollTop = 0;

        window.addEventListener('scroll', () => {
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            
            if (scrollTop > 100) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
            
            // Hide/show navbar on scroll
            if (scrollTop > lastScrollTop && scrollTop > 200) {
                navbar.style.transform = 'translateY(-100%)';
            } else {
                navbar.style.transform = 'translateY(0)';
            }
            lastScrollTop = scrollTop;
        });

        // Mobile Navigation Toggle
        const navToggle = document.getElementById('nav-toggle');
        const navMenu = document.getElementById('nav-menu');

        navToggle.addEventListener('click', () => {
            navMenu.classList.toggle('active');
            navToggle.classList.toggle('active');
            
            // Animate hamburger bars
            const bars = navToggle.querySelectorAll('.bar');
            bars.forEach((bar, index) => {
                if (navToggle.classList.contains('active')) {
                    if (index === 0) bar.style.transform = 'rotate(45deg) translate(5px, 5px)';
                    if (index === 1) bar.style.opacity = '0';
                    if (index === 2) bar.style.transform = 'rotate(-45deg) translate(7px, -6px)';
                } else {
                    bar.style.transform = 'none';
                    bar.style.opacity = '1';
                }
            });
        });

        // Close mobile menu when clicking on a link
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                navMenu.classList.remove('active');
                navToggle.classList.remove('active');
                
                // Reset hamburger bars
                const bars = navToggle.querySelectorAll('.bar');
                bars.forEach(bar => {
                    bar.style.transform = 'none';
                    bar.style.opacity = '1';
                });
            });
        });

        // 3D Background Scene
        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
        const renderer = new THREE.WebGLRenderer({ canvas: document.getElementById('bg-canvas'), alpha: true });
        renderer.setSize(window.innerWidth, window.innerHeight);

        // Create floating golden particles
        const particlesGeometry = new THREE.BufferGeometry();
        const particlesCount = 100;
        const posArray = new Float32Array(particlesCount * 3);

        for (let i = 0; i < particlesCount * 3; i++) {
            posArray[i] = (Math.random() - 0.5) * 50;
        }

        particlesGeometry.setAttribute('position', new THREE.BufferAttribute(posArray, 3));

        const particlesMaterial = new THREE.PointsMaterial({
            size: 0.1,
            color: 0xD4AF37,
            transparent: true,
            opacity: 0.8
        });

        const particlesMesh = new THREE.Points(particlesGeometry, particlesMaterial);
        scene.add(particlesMesh);

        camera.position.z = 20;

        // Animation loop
        function animate() {
            requestAnimationFrame(animate);
            
            particlesMesh.rotation.x += 0.001;
            particlesMesh.rotation.y += 0.002;
            
            renderer.render(scene, camera);
        }
        animate();

        // Resize handler
        window.addEventListener('resize', () => {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
        });

        // Smooth scrolling for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animation = 'fadeInUp 1s ease-out forwards';
                }
            });
        }, observerOptions);

        // Observe elements for animation
        document.querySelectorAll('.value-card, .team-card, .timeline-item').forEach(el => {
            observer.observe(el);
        });

        // Add CSS for fade in animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        `;
        document.head.appendChild(style);

        // Initialize elements as hidden
        document.querySelectorAll('.value-card, .team-card, .timeline-item').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
        });

        // Parallax effect for hero section
        window.addEventListener('scroll', () => {
            const scrolled = window.pageYOffset;
            const heroBackground = document.querySelector('.hero-background img');
            if (heroBackground) {
                heroBackground.style.transform = `translateY(${scrolled * 0.5}px)`;
            }
        });

        // Remove loading screen
        window.addEventListener('load', () => {
            setTimeout(() => {
                const loadingScreen = document.querySelector('.loading-screen');
                if (loadingScreen) {
                    loadingScreen.style.opacity = '0';
                    setTimeout(() => {
                        loadingScreen.style.display = 'none';
                    }, 500);
                }
            }, 2000);
        });
    </script>
</body>
</html>
