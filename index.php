<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Get featured hotels
$featured_hotels = getFeaturedHotels($pdo);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atlas Hotels - Collection de Luxe Marocaine</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Inter:wght@300;400;500;600&family=Cormorant+Garamond:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            color:goldenrod;
            overflow-x: hidden;
            line-height: 1.6;
        }

        /* Luxury Cursor */
        body {
            cursor: none;
        }

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
            color: var(--platinum);
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
            color:goldenrod;
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
            color:goldenrod;
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

        /* Buttons */
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

        .btn-secondary {
            background: transparent;
            color: var(--primary-gold);
            padding: 1rem 2rem;
            border: 2px solid var(--primary-gold);
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-secondary::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            height: 100%;
            background: var(--gradient-luxury);
            transition: width 0.3s ease;
            z-index: -1;
        }

        .btn-secondary:hover::before {
            width: 100%;
        }

        .btn-secondary:hover {
            color: var(--midnight);
            transform: translateY(-3px);
        }

        .btn-large {
            padding: 1.5rem 3rem;
            font-size: 1.1rem;
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
            color:goldenrod;
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
            color: var(--platinum);
            margin-bottom: 3rem;
            font-weight: 300;
            letter-spacing: 1px;
        }

        .hero-buttons {
            display: flex;
            gap: 2rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .scroll-indicator {
            position: absolute;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            animation: scrollBounce 2s infinite;
        }

        @keyframes scrollBounce {
            0%, 20%, 50%, 80%, 100% { transform: translateX(-50%) translateY(0); }
            40% { transform: translateX(-50%) translateY(-10px); }
            60% { transform: translateX(-50%) translateY(-5px); }
        }

        .scroll-mouse {
            width: 30px;
            height: 50px;
            border: 2px solid var(--primary-gold);
            border-radius: 15px;
            position: relative;
        }

        .scroll-wheel {
            width: 4px;
            height: 10px;
            background: var(--primary-gold);
            border-radius: 2px;
            position: absolute;
            top: 8px;
            left: 50%;
            transform: translateX(-50%);
            animation: wheelMove 2s infinite;
        }

        @keyframes wheelMove {
            0% { top: 8px; opacity: 1; }
            100% { top: 25px; opacity: 0; }
        }

        /* Stats Section */
        .stats {
            padding: 6rem 0;
            background: linear-gradient(135deg, var(--charcoal) 0%, var(--midnight) 100%);
            position: relative;
        }

        .stats::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="%23D4AF37" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            position: relative;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
        }

        .stat-item {
            text-align: center;
            padding: 2rem;
            background: rgba(212, 175, 55, 0.05);
            border-radius: 20px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
            transform-style: preserve-3d;
        }

        .stat-item:hover {
            transform: translateY(-10px) rotateX(5deg);
            background: rgba(212, 175, 55, 0.1);
            box-shadow: 0 20px 40px var(--shadow-dark);
        }

        .stat-icon {
            font-size: 3rem;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
            animation: iconFloat 3s ease-in-out infinite;
        }

        @keyframes iconFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .stat-number {
            font-family: 'Playfair Display', serif;
            font-size: 3rem;
            font-weight: 700;
            color: var(--primary-gold);
            margin-bottom: 0.5rem;
            counter-reset: number;
        }

        .stat-label {
            color: var(--platinum);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Featured Hotels */
        .featured-hotels {
            padding: 8rem 0;
            background: var(--midnight);
            position: relative;
        }

        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(2.5rem, 5vw, 4rem);
            font-weight: 700;
            color:goldenrod;
            margin-bottom: 1rem;
        }

        .section-subtitle {
            font-size: 1.2rem;
            color: var(--platinum);
            max-width: 600px;
            margin: 0 auto;
            font-weight: 300;
        }

        .hotels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 3rem;
            margin-bottom: 4rem;
        }

        .hotel-card {
            background: rgba(212, 175, 55, 0.05);
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.5s ease;
            border: 1px solid rgba(212, 175, 55, 0.2);
            position: relative;
            transform-style: preserve-3d;
        }

        .hotel-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-luxury);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: -1;
        }

        .hotel-card:hover {
            transform: translateY(-15px) rotateX(2deg) rotateY(2deg);
            box-shadow: 0 25px 50px var(--shadow-dark);
        }

        .hotel-card:hover::before {
            opacity: 0.1;
        }

        .hotel-image {
            position: relative;
            height: 250px;
            overflow: hidden;
        }

        .hotel-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .hotel-card:hover .hotel-image img {
            transform: scale(1.1);
        }

        .hotel-overlay {
            position: absolute;
            top: 1rem;
            right: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .hotel-rating,
        .hotel-location {
            background: rgba(15, 20, 25, 0.9);
            color: var(--primary-gold);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            backdrop-filter: blur(10px);
        }

        .hotel-content {
            padding: 2rem;
        }

        .hotel-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 600;
            color:goldenrod;
            margin-bottom: 1rem;
        }

        .hotel-description {
            color: var(--platinum);
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .hotel-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .hotel-price {
            display: flex;
            flex-direction: column;
        }

        .price-amount {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-gold);
        }

        .price-period {
            color: var(--platinum);
            font-size: 0.9rem;
        }

        /* Experience Section */
        .experience {
            padding: 8rem 0;
            background: linear-gradient(135deg, var(--charcoal) 0%, var(--midnight) 100%);
            position: relative;
        }

        .experience-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
        }

        .experience-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(2.5rem, 4vw, 3.5rem);
            font-weight: 700;
            color:goldenrod;
            margin-bottom: 2rem;
            line-height: 1.3;
        }

        .experience-description {
            font-size: 1.2rem;
            color: var(--platinum);
            margin-bottom: 3rem;
            line-height: 1.8;
        }

        .amenities-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .amenity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: rgba(212, 175, 55, 0.1);
            border-radius: 15px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            transition: all 0.3s ease;
        }

        .amenity-item:hover {
            background: rgba(212, 175, 55, 0.2);
            transform: translateX(10px);
        }

        .amenity-item i {
            font-size: 1.5rem;
            color: var(--primary-gold);
        }

        .amenity-item span {
            color:goldenrod;
            font-weight: 500;
        }

        .experience-image {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
        }

        .experience-image img {
            width: 100%;
            height: 400px;
            object-fit: cover;
            border-radius: 20px;
        }

        .image-decoration {
            position: absolute;
            border: 2px solid var(--primary-gold);
            border-radius: 20px;
        }

        .decoration-1 {
            top: -20px;
            right: -20px;
            width: 100px;
            height: 100px;
            animation: decorationFloat1 4s ease-in-out infinite;
        }

        .decoration-2 {
            bottom: -20px;
            left: -20px;
            width: 150px;
            height: 150px;
            animation: decorationFloat2 4s ease-in-out infinite reverse;
        }

        @keyframes decorationFloat1 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(10px, -10px) rotate(5deg); }
        }

        @keyframes decorationFloat2 {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(-10px, 10px) rotate(-5deg); }
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
        }

        .footer-logo i {
            font-size: 2rem;
            background: var(--gradient-luxury);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .footer-description {
            color: var(--platinum);
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
            color: var(--platinum);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--primary-gold);
        }

        .contact-info p {
            color: var(--platinum);
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
            color: var(--platinum);
        }

        .section-footer {
            text-align: center;
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

            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }

            .experience-content {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .amenities-grid {
                grid-template-columns: 1fr;
            }

            .hotels-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Luxury Loading Animation */
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
            color:goldenrod;
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
            <div class="nav-logo">
                <i class="fas fa-crown"></i>
                <div class="logo-text">
                    <span class="logo-main">Atlas Hotels</span>
                    <span class="logo-sub">Luxury Collection</span>
                </div>
            </div>
            
            <div class="nav-menu" id="nav-menu">
                <a href="index.php" class="nav-link active">Accueil</a>
                <a href="hotels.php" class="nav-link">Hôtels</a>
                <a href="user.php" class="nav-link">Profile</a>
                <a href="about.php" class="nav-link">About</a>
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
            <img src="https://images.pexels.com/photos/1707310/pexels-photo-1707310.jpeg?auto=compress&cs=tinysrgb&w=1920" alt="Luxury Moroccan Hotel">
            <div class="hero-overlay"></div>
        </div>
        <div class="hero-content">
            <h1 class="hero-title">
                Découvrez le <span class="gradient-text">Luxe Marocain</span>
            </h1>
            <p class="hero-subtitle">
                Une collection exclusive des plus beaux hôtels et riads du Maroc
            </p>
            <div class="hero-buttons">
                <a href="hotels.php" class="btn-primary btn-large">Explorer nos Hôtels <i class="fas fa-arrow-right"></i></a>
                <a href="register.php" class="btn-secondary btn-large">Commencer</a>
            </div>
        </div>
        <div class="scroll-indicator">
            <div class="scroll-mouse"><div class="scroll-wheel"></div></div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-crown"></i></div>
                    <div class="stat-number">50+</div>
                    <div class="stat-label">Hôtels de Luxe</div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-award"></i></div>
                    <div class="stat-number">25+</div>
                    <div class="stat-label">Prix Remportés</div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-number">10K+</div>
                    <div class="stat-label">Clients Satisfaits</div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                    <div class="stat-number">4.9</div>
                    <div class="stat-label">Note Moyenne</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Featured Hotels -->
    <section class="featured-hotels">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Hôtels d'Exception</h2>
                <p class="section-subtitle">Découvrez notre sélection exclusive des plus beaux établissements du Maroc</p>
            </div>
            
            <div class="hotels-grid">
                <?php foreach ($featured_hotels as $hotel): ?>
                    <div class="hotel-card">
                        <div class="hotel-image">
                            <img src="<?php echo htmlspecialchars($hotel['image_url']); ?>" alt="<?php echo htmlspecialchars($hotel['name']); ?>">
                            <div class="hotel-overlay">
                                <div class="hotel-rating"><i class="fas fa-star"></i> <span><?php echo htmlspecialchars($hotel['rating']); ?></span></div>
                                <div class="hotel-location"><i class="fas fa-map-marker-alt"></i> <span><?php echo htmlspecialchars($hotel['city']); ?></span></div>
                            </div>
                        </div>
                        <div class="hotel-content">
                            <h3 class="hotel-name"><?php echo htmlspecialchars($hotel['name']); ?></h3>
                            <p class="hotel-description"><?php echo htmlspecialchars(substr($hotel['description'], 0, 120)) . '...'; ?></p>
                            <div class="hotel-footer">
                                <div class="hotel-price">
                                    <span class="price-amount">
                                        <?php echo isset($hotel['price_per_night']) ? htmlspecialchars($hotel['price_per_night']) . 'MAD' : 'N/A'; ?>
                                    </span>
                                    <span class="price-period">/nuit</span>
                                </div>
                                <a href="hotel-details.php?id=<?php echo htmlspecialchars($hotel['id']); ?>" class="btn-primary">Voir Détails</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="section-footer">
                <a href="hotels.php" class="btn-secondary btn-large">Voir Tous les Hôtels <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </section>

    <!-- Experience Section -->
    <section class="experience">
        <div class="container">
            <div class="experience-content">
                <div class="experience-text">
                    <h2 class="experience-title">L'Art de l'Hospitalité <span class="gradient-text">Marocaine</span></h2>
                    <p class="experience-description">
                        Plongez dans l'authenticité du Maroc à travers des expériences uniques, 
                        dans des lieux d'exception où tradition et modernité se rencontrent harmonieusement.
                    </p>
                    <div class="amenities-grid">
                        <div class="amenity-item"><i class="fas fa-wifi"></i> <span>WiFi Haut Débit</span></div>
                        <div class="amenity-item"><i class="fas fa-car"></i> <span>Service Voiturier</span></div>
                        <div class="amenity-item"><i class="fas fa-utensils"></i> <span>Gastronomie Fine</span></div>
                        <div class="amenity-item"><i class="fas fa-spa"></i> <span>Spa & Bien-être</span></div>
                    </div>
                    <a href="register.php" class="btn-primary btn-large">Réserver Maintenant</a>
                </div>
                <div class="experience-image">
                    <img src="https://images.pexels.com/photos/1591373/pexels-photo-1591373.jpeg?auto=compress&cs=tinysrgb&w=800" alt="Expérience marocaine">
                    <div class="image-decoration decoration-1"></div>
                    <div class="image-decoration decoration-2"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <div class="footer-logo">
                        <i class="fas fa-crown"></i>
                        <div class="logo-text">
                            <span class="logo-main">Atlas Hotels</span>
                            <span class="logo-sub">Luxury Collection</span>
                        </div>
                    </div>
                    <p class="footer-description">Collection exclusive des plus beaux hôtels de luxe du Maroc.</p>
                </div>
                <div class="footer-section">
                    <h4 class="footer-title">Navigation</h4>
                    <ul class="footer-links">
                        <li><a href="index.php">Accueil</a></li>
                        <li><a href="hotels.php">Hôtels</a></li>
                        <li><a href="about.php">À Propos</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="footer-title">Services</h3>
                    <ul class="footer-links">
                        <li><a href="#">Spa & Wellness</a></li>
                        <li><a href="#">Restaurant Gastronomique</a></li>
                        <li><a href="#">Centre d'Affaires</a></li>
                        <li><a href="#">Service de Conciergerie</a></li>
                        <li><a href="#">Transfert Aéroport</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h4 class="footer-title">Contact</h4>
                    <div class="contact-info">
                        <p><i class="fas fa-phone"></i> +212 6 00 00 00 00</p>
                        <p><i class="fas fa-envelope"></i> contact@atlashotels.ma</p>
                        <p><i class="fas fa-map-marker-alt"></i> Rabat, Maroc</p>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2024 Atlas Hotels. Collection de Luxe Marocaine. Tous droits réservés.</p>
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
            cursor.style.left = cursorX + 'px';
            cursor.style.top = cursorY + 'px';
            requestAnimationFrame(animateCursor);
        }
        animateCursor();

        // 3D Background Animation with Three.js
        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
        const renderer = new THREE.WebGLRenderer({ 
            canvas: document.getElementById('bg-canvas'),
            alpha: true 
        });

        renderer.setSize(window.innerWidth, window.innerHeight);
        renderer.setPixelRatio(window.devicePixelRatio);

        // Create luxury particles
        const particlesGeometry = new THREE.BufferGeometry();
        const particlesCount = 1000;
        const posArray = new Float32Array(particlesCount * 3);

        for(let i = 0; i < particlesCount * 3; i++) {
            posArray[i] = (Math.random() - 0.5) * 100;
        }

        particlesGeometry.setAttribute('position', new THREE.BufferAttribute(posArray, 3));

        const particlesMaterial = new THREE.PointsMaterial({
            size: 0.005,
            color: 0xD4AF37,
            transparent: true,
            opacity: 0.8
        });

        const particlesMesh = new THREE.Points(particlesGeometry, particlesMaterial);
        scene.add(particlesMesh);

        // Create floating geometric shapes
        const geometries = [
            new THREE.OctahedronGeometry(0.5),
            new THREE.TetrahedronGeometry(0.5),
            new THREE.IcosahedronGeometry(0.5)
        ];

        const material = new THREE.MeshBasicMaterial({
            color: 0xD4AF37,
            wireframe: true,
            transparent: true,
            opacity: 0.3
        });

        const shapes = [];
        for(let i = 0; i < 20; i++) {
            const geometry = geometries[Math.floor(Math.random() * geometries.length)];
            const mesh = new THREE.Mesh(geometry, material);
            
            mesh.position.x = (Math.random() - 0.5) * 40;
            mesh.position.y = (Math.random() - 0.5) * 40;
            mesh.position.z = (Math.random() - 0.5) * 40;
            
            mesh.rotation.x = Math.random() * Math.PI;
            mesh.rotation.y = Math.random() * Math.PI;
            
            mesh.userData = {
                rotationSpeed: {
                    x: (Math.random() - 0.5) * 0.02,
                    y: (Math.random() - 0.5) * 0.02,
                    z: (Math.random() - 0.5) * 0.02
                }
            };
            
            shapes.push(mesh);
            scene.add(mesh);
        }

        camera.position.z = 20;

        // Animation loop
        function animate() {
            requestAnimationFrame(animate);

            // Rotate particles
            particlesMesh.rotation.x += 0.0005;
            particlesMesh.rotation.y += 0.0005;

            // Animate shapes
            shapes.forEach(shape => {
                shape.rotation.x += shape.userData.rotationSpeed.x;
                shape.rotation.y += shape.userData.rotationSpeed.y;
                shape.rotation.z += shape.userData.rotationSpeed.z;
            });

            renderer.render(scene, camera);
        }
        animate();

        // Handle window resize
        window.addEventListener('resize', () => {
            camera.aspect = window.innerWidth / window.innerHeight;
            camera.updateProjectionMatrix();
            renderer.setSize(window.innerWidth, window.innerHeight);
        });

        // Navbar scroll effect
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 100) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Mobile navigation toggle
        const navToggle = document.getElementById('nav-toggle');
        const navMenu = document.getElementById('nav-menu');

        navToggle.addEventListener('click', () => {
            navMenu.classList.toggle('active');
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
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
        document.querySelectorAll('.stat-item, .hotel-card, .amenity-item').forEach(el => {
            observer.observe(el);
        });

        // Counter animation for stats
        const counters = document.querySelectorAll('.stat-number');
        const countObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const counter = entry.target;
                    const target = parseInt(counter.textContent.replace(/\D/g, ''));
                    let current = 0;
                    const increment = target / 50;
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= target) {
                            current = target;
                            clearInterval(timer);
                        }
                        counter.textContent = current.toFixed(target > 100 ? 0 : 1) + (counter.textContent.includes('+') ? '+' : '');
                    }, 50);
                }
            });
        }, observerOptions);

        counters.forEach(counter => countObserver.observe(counter));

        // Parallax effect
        window.addEventListener('scroll', () => {
            const scrolled = window.pageYOffset;
            const parallax = document.querySelector('.hero-background img');
            if (parallax) {
                parallax.style.transform = `translateY(${scrolled * 0.5}px)`;
            }
        });

        // Add CSS animation keyframes
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

        // Luxury page transitions
        document.addEventListener('DOMContentLoaded', () => {
            document.body.style.opacity = '0';
            setTimeout(() => {
                document.body.style.transition = 'opacity 0.5s ease-in-out';
                document.body.style.opacity = '1';
            }, 100);
        });
    </script>
</body>
</html>