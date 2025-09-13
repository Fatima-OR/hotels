<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Get featured hotels
$featured_hotels = getFeaturedHotels($pdo);
// Database configuration
$host = 'localhost';
$dbname = 'atlass_hotels';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Get all cities for dropdown
$citiesQuery = "SELECT DISTINCT city FROM hotels WHERE status = 'active' ORDER BY city";
$citiesStmt = $pdo->query($citiesQuery);
$cities = $citiesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get room types for dropdown
$roomTypesQuery = "SELECT DISTINCT name FROM room_types ORDER BY name";
$roomTypesStmt = $pdo->query($roomTypesQuery);
$roomTypes = $roomTypesStmt->fetchAll(PDO::FETCH_COLUMN);

// Get services for checkboxes
$services = [
    'Piscine' => 'Piscine',
    'Spa' => 'Spa',
    'WiFi gratuit' => 'WiFi gratuit',
    'Parking' => 'Parking',
    'Restaurant' => 'Restaurant',
    'Salle de sport' => 'Salle de sport',
    'Bar' => 'Bar'
];

// Initialize search variables
$city = $_GET['city'] ?? '';
$roomType = $_GET['room_type'] ?? '';
$checkIn = $_GET['check_in'] ?? '';
$checkOut = $_GET['check_out'] ?? '';
$guests = (int)($_GET['guests'] ?? 1);
$maxPrice = $_GET['max_price'] ?? '';
$selectedServices = $_GET['services'] ?? [];

// Build search query
$hotels = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET)) {
    $query = "
        SELECT DISTINCT h.*, 
               MIN(rt.price_per_night) as min_price,
               MAX(rt.price_per_night) as max_price,
               COUNT(rt.id) as room_types_count
        FROM hotels h
        LEFT JOIN room_types rt ON h.id = rt.hotel_id
        WHERE h.status = 'active'
    ";
    
    $params = [];
    
    // Filter by city
    if (!empty($city) && $city !== 'all') {
        $query .= " AND h.city = :city";
        $params[':city'] = $city;
    }
    
    // Filter by max price
    if (!empty($maxPrice)) {
        $query .= " AND rt.price_per_night <= :max_price";
        $params[':max_price'] = $maxPrice;
    }
    
    // Filter by guest capacity
    if ($guests > 1) {
        $query .= " AND rt.max_occupancy >= :guests";
        $params[':guests'] = $guests;
    }
    
    // Filter by room type
    if (!empty($roomType) && $roomType !== 'all') {
        $query .= " AND rt.name LIKE :room_type";
        $params[':room_type'] = '%' . $roomType . '%';
    }
    
    // Filter by services
    if (!empty($selectedServices)) {
        foreach ($selectedServices as $index => $service) {
            $paramName = ":service_$index";
            $query .= " AND JSON_CONTAINS(h.amenities, $paramName)";
            $params[$paramName] = json_encode($service);
        }
    }
    
    $query .= " GROUP BY h.id ORDER BY h.is_featured DESC, h.rating DESC";
    
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $error = "Erreur lors de la recherche : " . $e->getMessage();
    }
} else {
    // Show all hotels if no search
    $query = "
        SELECT DISTINCT h.*, 
               MIN(rt.price_per_night) as min_price,
               MAX(rt.price_per_night) as max_price,
               COUNT(rt.id) as room_types_count
        FROM hotels h
        LEFT JOIN room_types rt ON h.id = rt.hotel_id
        WHERE h.status = 'active'
        GROUP BY h.id ORDER BY h.is_featured DESC, h.rating DESC
    ";
    $stmt = $pdo->query($query);
    $hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to calculate nights between dates
function calculateNights($checkIn, $checkOut) {
    if (empty($checkIn) || empty($checkOut)) return 1;
    $date1 = new DateTime($checkIn);
    $date2 = new DateTime($checkOut);
    $diff = $date1->diff($date2);
    return max(1, $diff->days);
}

$nights = calculateNights($checkIn, $checkOut);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nos Hôtels - Atlas Hotels</title>
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
            color: var(--primary-gold);
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
            color: var(--primary-gold);
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

        /* Page Header */
        .page-header {
            margin-top: 80px;
            padding: 4rem 0 2rem;
            background: var(--gradient-dark);
            text-align: center;
            position: relative;
        }

        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 3rem;
            color: var(--primary-gold);
            margin-bottom: 1rem;
        }

        .page-subtitle {
            font-size: 1.2rem;
            color: var(--platinum);
            opacity: 0.8;
        }

        /* Search Section */
        .search-section {
            padding: 3rem 0;
            background: var(--gradient-dark);
            position: relative;
        }

        .search-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.1);
        }

        .search-form {
            background: rgba(212, 175, 55, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 16px;
            padding: 2rem;
            position: relative;
            z-index: 1;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            color: var(--primary-gold);
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-select, .form-input {
            padding: 0.75rem 1rem;
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 8px;
            background: rgba(212, 175, 55, 0.2);
            color: var(--primary-gold);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: rgba(212, 175, 55, 0.6);
            background: rgba(212, 175, 55, 0.25);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
        }

        .form-select option {
            background: var(--charcoal);
            color: var(--primary-gold);
        }

        .form-input::placeholder {
            color: rgba(212, 175, 55, 0.7);
        }

        /* Services Section */
        .services-section {
            margin: 2rem 0;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .service-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--primary-gold);
        }

        .service-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-gold);
        }

        /* Button */
        .btn-primary {
            background: var(--gradient-luxury);
            color: var(--midnight);
            border: none;
            padding: 1rem 2rem;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
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

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            position: relative;
        }

        /* Results Section */
        .hotels-listing {
            padding: 4rem 0;
            background: var(--midnight);
        }

        .results-header {
            margin-bottom: 3rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .results-title {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-gold);
        }

        .results-info {
            color: var(--platinum);
            font-size: 0.9rem;
        }

        .active-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .filter-tag {
            background: var(--primary-gold);
            color: var(--midnight);
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
        }

        .filter-tag a {
            color: var(--midnight);
            text-decoration: none;
            font-weight: bold;
            margin-left: 0.5rem;
        }

        /* Hotel Cards */
        .hotels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 3rem;
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

        .featured-badge {
            background: var(--gradient-luxury);
            color: var(--midnight);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .hotel-content {
            padding: 2rem;
        }

        .hotel-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-gold);
            margin-bottom: 1rem;
        }

        .hotel-location-full {
            color: var(--primary-gold);
            font-size: 0.9rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .hotel-description {
            color: var(--platinum);
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .hotel-amenities-preview {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        .amenity-tag {
            background: rgba(212, 175, 55, 0.2);
            color: var(--primary-gold);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .amenity-tag i {
            color: var(--primary-gold);
            font-size: 0.7rem;
        }

        .amenity-more {
            color: var(--primary-gold);
            font-size: 0.8rem;
            font-weight: 500;
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

        /* Empty State */
        .no-results {
            text-align: center;
            padding: 4rem 2rem;
            background: rgba(212, 175, 55, 0.05);
            border-radius: 20px;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .no-results i {
            font-size: 4rem;
            color: var(--primary-gold);
            margin-bottom: 1rem;
        }

        .no-results h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            color: var(--primary-gold);
            margin-bottom: 0.5rem;
        }

        .no-results p {
            color: var(--platinum);
            margin-bottom: 2rem;
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

        /* Responsive Design */
        @media (max-width: 768px) {
            .search-grid {
                grid-template-columns: 1fr;
            }
            
            .hotels-grid {
                grid-template-columns: 1fr;
            }
            
            .hotel-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .page-title {
                font-size: 2rem;
            }
            
            .results-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Animation Keyframes */
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
    </style>
</head>
<body>
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
                <a href="index.php" class="nav-link">Accueil</a>
                <a href="hotels.php" class="nav-link active">Hôtels</a>
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

    <!-- Page Header -->
    <section class="page-header">
        <div class="container">
            <h1 class="page-title">Nos Hôtels de Luxe</h1>
            <p class="page-subtitle">Découvrez notre collection exclusive d'établissements d'exception au Maroc</p>
        </div>
    </section>

    <!-- Search Form -->
    <section class="search-section">
        <div class="container">
            <form class="search-form" method="GET">
                <div class="search-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-map-marker-alt"></i>Ville
                        </label>
                        <select name="city" class="form-select">
                            <option value="">Toutes les villes</option>
                            <?php foreach ($cities as $cityOption): ?>
                                <option value="<?php echo htmlspecialchars($cityOption); ?>" 
                                        <?php echo $city === $cityOption ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cityOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-calendar-alt"></i>Date d'arrivée
                        </label>
                        <input type="date" name="check_in" class="form-input" 
                               value="<?php echo htmlspecialchars($checkIn); ?>"
                                min="<?= date('Y-m-d') ?>" required>
                              
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-calendar-alt"></i>Date de départ
                        </label>
                        <input type="date" name="check_out" class="form-input" 
                               value="<?php echo htmlspecialchars($checkOut); ?>"
                               min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-users"></i>Voyageurs
                        </label>
                        <select name="guests" class="form-select">
                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $guests === $i ? 'selected' : ''; ?>>
                                    <?php echo $i; ?> <?php echo $i === 1 ? 'personne' : 'personnes'; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-bed"></i>Type de chambre
                        </label>
                        <select name="room_type" class="form-select">
                            <option value="">Tous les types</option>
                            <?php foreach ($roomTypes as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" 
                                        <?php echo $roomType === $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-dollar-sign"></i>Prix maximum par nuit
                        </label>
                        <input type="number" name="max_price" class="form-input" 
                               placeholder="Prix en MAD" value="<?php echo htmlspecialchars($maxPrice); ?>">
                    </div>
                </div>

                <div class="services-section">
                    <label class="form-label">Services proposés</label>
                    <div class="services-grid">
                        <?php foreach ($services as $serviceId => $serviceLabel): ?>
                            <label class="service-item">
                                <input type="checkbox" name="services[]" value="<?php echo $serviceId; ?>"
                                       <?php echo in_array($serviceId, $selectedServices) ? 'checked' : ''; ?>>
                                <span><?php echo $serviceLabel; ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="btn-primary">
                    <i class="fas fa-search"></i>
                    Rechercher
                </button>
            </form>
        </div>
    </section>

    <!-- Hotels Listing -->
    <section class="hotels-listing">
        <div class="container">
            <?php if (isset($error)): ?>
                <div style="background: rgba(220, 38, 38, 0.1); border: 1px solid rgba(220, 38, 38, 0.3); color: #dc2626; padding: 1rem; border-radius: 8px; margin-bottom: 2rem;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="results-header">
                <div>
                    <h2 class="results-title">
                        <?php echo count($hotels); ?> hôtel<?php echo count($hotels) !== 1 ? 's' : ''; ?> trouvé<?php echo count($hotels) !== 1 ? 's' : ''; ?>
                    </h2>
                    <?php if (!empty($city) && $city !== 'all'): ?>
                        <p class="results-info">à <?php echo htmlspecialchars($city); ?></p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($checkIn) && !empty($checkOut)): ?>
                    <div class="results-info">
                        <?php echo $nights; ?> nuit<?php echo $nights !== 1 ? 's' : ''; ?> • 
                        <?php echo $guests; ?> personne<?php echo $guests !== 1 ? 's' : ''; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($city || $maxPrice): ?>
                <div class="active-filters">
                    <?php if ($city): ?>
                        <span class="filter-tag">
                            Ville: <?php echo htmlspecialchars($city); ?>
                            <a href="?<?php echo http_build_query(array_filter(['max_price' => $maxPrice])); ?>">×</a>
                        </span>
                    <?php endif; ?>
                    <?php if ($maxPrice): ?>
                        <span class="filter-tag">
                            Max: <?php echo htmlspecialchars($maxPrice); ?>MAD
                            <a href="?<?php echo http_build_query(array_filter(['city' => $city])); ?>">×</a>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($hotels)): ?>
                <div class="no-results">
                    <i class="fas fa-search"></i>
                    <h3>Aucun hôtel trouvé</h3>
                    <p>Essayez de modifier vos critères de recherche pour voir plus de résultats</p>
                    <a href="hotels.php" class="btn-primary">Voir tous les hôtels</a>
                </div>
            <?php else: ?>
                <div class="hotels-grid">
                    <?php foreach ($hotels as $hotel): ?>
                        <div class="hotel-card">
                            <div class="hotel-image">
                                <img src="<?php echo htmlspecialchars($hotel['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($hotel['name']); ?>">
                                <div class="hotel-overlay">
                                    <div class="hotel-rating">
                                        <i class="fas fa-star"></i>
                                        <span><?php echo $hotel['rating']; ?></span>
                                    </div>
                                    <div class="hotel-location">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?php echo htmlspecialchars($hotel['city']); ?></span>
                                    </div>
                                    <?php if ($hotel['is_featured']): ?>
                                        <div class="featured-badge">
                                            <i class="fas fa-crown"></i>
                                            Featured
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="hotel-content">
                                <h3 class="hotel-name"><?php echo htmlspecialchars($hotel['name']); ?></h3>
                                <p class="hotel-location-full">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($hotel['location']); ?>
                                </p>
                                <p class="hotel-description">
                                    <?php echo htmlspecialchars(substr($hotel['description'], 0, 120)) . '...'; ?>
                                </p>
                                
                                <?php 
                                $amenities = json_decode($hotel['amenities'], true);
                                if ($amenities && is_array($amenities)): 
                                ?>
                                    <div class="hotel-amenities-preview">
                                        <?php for ($i = 0; $i < min(3, count($amenities)); $i++): ?>
                                            <span class="amenity-tag">
                                                <i class="fas fa-check"></i>
                                                <?php echo htmlspecialchars($amenities[$i]); ?>
                                            </span>
                                        <?php endfor; ?>
                                        <?php if (count($amenities) > 3): ?>
                                            <span class="amenity-more">+<?php echo count($amenities) - 3; ?> autres</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="hotel-footer">
                                    <div class="hotel-price">
                                        <span class="price-amount"><?php echo number_format($hotel['min_price'] ?: $hotel['price_per_night'], 0, ',', ' '); ?> MAD</span>
                                        <span class="price-period">par nuit</span>
                                        <?php if ($nights > 1): ?>
                                            <span class="price-period">Total: <?php echo number_format(($hotel['min_price'] ?: $hotel['price_per_night']) * $nights, 0, ',', ' '); ?> MAD</span>
                                        <?php endif; ?>
                                    </div>
                                    <a href="hotel-details.php?id=<?php echo $hotel['id']; ?>" class="btn-primary">
                                        Voir Détails
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
                    <p class="footer-description">
                        Collection exclusive des plus beaux hôtels de luxe du Maroc.
                    </p>
                </div>
                
                <div class="footer-section">
                    <h4 class="footer-title">Navigation</h4>
                    <ul class="footer-links">
                        <li><a href="index.php">Accueil</a></li>
                        <li><a href="hotels.php">Hôtels</a></li>
                        <li><a href="about.php">À Propos</a></li>
                        <li><a href="contact.php">Contact</a></li>
                    </ul>
                </div>
                
                <div class="footer-section">
                    <h4 class="footer-title">Services</h4>
                    <ul class="footer-links">
                        <li><a href="#">Réservations</a></li>
                        <li><a href="#">Concierge</a></li>
                        <li><a href="#">Spa & Wellness</a></li>
                        <li><a href="#">Événements</a></li>
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
                <p>&copy; 2025 Atlas Hotels. Collection de Luxe Marocaine. Tous droits réservés.</p>
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
        document.querySelectorAll('.hotel-card').forEach(el => {
            observer.observe(el);
        });

        // Parallax effect
        window.addEventListener('scroll', () => {
            const scrolled = window.pageYOffset;
            const parallax = document.querySelector('.hero-background img');
            if (parallax) {
                parallax.style.transform = `translateY(${scrolled * 0.5}px)`;
            }
        });

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
