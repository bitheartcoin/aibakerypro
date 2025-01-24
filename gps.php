<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Admin jogosultság ellenőrzése
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

// API Token kezelés
$api_token = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_token'])) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.trackgps.ro/api/authentication/login?api-version=2.0');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'username' => 'pal.konecsny@outlook.hu',
        'password' => $_POST['password']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    if (isset($result['token'])) {
        $api_token = $result['token'];
        $_SESSION['gps_token'] = $api_token;
        $_SESSION['success'] = 'API token sikeresen lekérve!';
    } else {
        $_SESSION['error'] = 'Hiba történt a token lekérése során: ' . $response;
        error_log('API Error Response: ' . $response);
    }
}
// Járművek lekérése
$vehicles = [];
if (isset($_SESSION['gps_token'])) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.trackgps.ro/api/carriers/company-vehicles?api-version=2.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_SESSION['gps_token'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $vehicles = json_decode($response, true) ?? [];
}

// Koordináták lekérése
$coordinates = [];
if (isset($_POST['get_coordinates']) && isset($_POST['vehicle_id'])) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.trackgps.ro/api/carriers/way?api-version=2.0');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'VehiclesList' => [(int)$_POST['vehicle_id']],
        'StartDate' => $_POST['start_date'],
        'EndDate' => $_POST['end_date']
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_SESSION['gps_token'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $coordinates = json_decode($response, true) ?? [];
}

// Fogyasztás lekérése
$consumption = [];
if (isset($_POST['get_consumption']) && isset($_POST['vehicle_id'])) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.trackgps.ro/api/carriers/consumption?api-version=2.0');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'VehiclesList' => [(int)$_POST['vehicle_id']],
        'StartDate' => $_POST['start_date'],
        'EndDate' => $_POST['end_date']
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_SESSION['gps_token'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $consumption = json_decode($response, true) ?? [];
}

// Megállók lekérése
$stops = [];
if (isset($_POST['get_stops']) && isset($_POST['vehicle_id'])) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.trackgps.ro/api/carriers/stops?api-version=2.0');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'VehiclesList' => [(int)$_POST['vehicle_id']],
        'StartDate' => $_POST['start_date'],
        'EndDate' => $_POST['end_date']
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_SESSION['gps_token'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $stops = json_decode($response, true) ?? [];
}

// Tankolások lekérése
$refills = [];
if (isset($_POST['get_refills']) && isset($_POST['vehicle_id'])) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.trackgps.ro/api/carriers/refills?api-version=2.0');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'CarrierId' => (int)$_POST['vehicle_id'],
        'StartDate' => $_POST['start_date'],
        'EndDate' => $_POST['end_date']
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_SESSION['gps_token'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $refills = json_decode($response, true) ?? [];
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Követés - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <style>
        #map { height: 500px; }
        .chart-container { height: 300px; }
        .stat-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        .nav-tabs .nav-link {
            color: #495057;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
        }
        .tab-content {
            padding: 20px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 5px 5px;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php">Admin Felület</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <div class="d-flex gap-3 align-items-center ms-auto">
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-cog me-2"></i>Kezelés
                        </button>
                        <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="index.php"><i class="fas fa-cash-register me-2"></i>Tranzakciók</a></li>
                        <li><a class="dropdown-item" href="users.php"><i class="fas fa-users me-2"></i>Felhasználók</a></li>
                        <li><a class="dropdown-item" href="products.php"><i class="fas fa-box me-2"></i>Termékek</a></li>
                        <li><a class="dropdown-item" href="drivers.php"><i class="fas fa-truck me-2"></i>Sofőrök</a></li>
                        <li><a class="dropdown-item" href="payments.php"><i class="fas fa-money-bill-wave me-2"></i>Fizetések</a></li>
                        <li><a class="dropdown-item" href="schedules.php"><i class="fas fa-calendar-alt me-2"></i>Munkabeosztás</a></li>
                        <li><a class="dropdown-item" href="orders.php"><i class="fas fa-shopping-cart me-2"></i>Rendelések</a></li>
                        <li><a class="dropdown-item" href="partners.php"><i class="fas fa-handshake me-2"></i>Partnerek</a></li>
                        <li><a class="dropdown-item" href="ai_forecast.php"><i class="fas fa-chart-line me-2"></i>AI Előrejelzés</a></li>
                        <li><a class="dropdown-item" href="statistics.php"><i class="fas fa-chart-pie me-2"></i>Statisztikák</a></li>
                        </ul>
                    </div>
                    <a href="../index.php" class="btn btn-light">
                        <i class="fas fa-home me-2"></i>Főoldal
                    </a>
                    <a href="../logout.php" class="btn btn-danger">
                        <i class="fas fa-sign-out-alt me-2"></i>Kijelentkezés
                    </a>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="container py-4">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php 
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php 
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <!-- API Token form -->
        <?php if (!isset($_SESSION['gps_token'])): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">API Bejelentkezés</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Jelszó</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" name="get_token" class="btn btn-primary w-100">
                                <i class="fas fa-key me-2"></i>Bejelentkezés
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['gps_token'])): ?>
            <!-- GPS térkép -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">GPS Térkép</h5>
                </div>
                <div class="card-body">
                    <div id="map"></div>
                </div>
            </div>

            <!-- Keresési form és adatok -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Járművek és Adatok</h5>
                </div>
                <div class="card-body">
                    <!-- Keresési form -->
                    <form method="POST" class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Jármű</label>
                            <select name="vehicle_id" class="form-select" required>
                                <option value="">Válasszon járművet...</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?php echo $vehicle['id']; ?>"
                                        <?php echo isset($_POST['vehicle_id']) && $_POST['vehicle_id'] == $vehicle['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($vehicle['plate_number']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Kezdő dátum</label>
                            <input type="date" name="start_date" class="form-control" 
                                   value="<?php echo $_POST['start_date'] ?? date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Végző dátum</label>
                            <input type="date" name="end_date" class="form-control" 
                                   value="<?php echo $_POST['end_date'] ?? date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Műveletek</label>
                            <div class="d-flex gap-2">
                                <button type="submit" name="get_coordinates" class="btn btn-primary" title="Útvonal">
                                    <i class="fas fa-route"></i>
                                </button>
                                <button type="submit" name="get_consumption" class="btn btn-success" title="Fogyasztás">
                                    <i class="fas fa-gas-pump"></i>
                                </button>
                                <button type="submit" name="get_stops" class="btn btn-warning" title="Megállók">
                                    <i class="fas fa-parking"></i>
                                </button>
                                <button type="submit" name="get_refills" class="btn btn-danger" title="Tankolások">
                                    <i class="fas fa-fill-drip"></i>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Tabpanel tartalom -->
                    <div class="tab-content">
                        <!-- Tab panelek tartalma... -->
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
    // Térkép inicializálása
    var map = L.map('map').setView([46.253, 20.148], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    <?php if (!empty($coordinates)): ?>
    // Útvonal megjelenítése a térképen
    var points = [
        <?php foreach ($coordinates as $coord): ?>
        [<?php echo $coord['latitude']; ?>, <?php echo $coord['longitude']; ?>],
        <?php endforeach; ?>
    ];

    // Útvonal rajzolása
    L.polyline(points, {color: 'red'}).addTo(map);

    // Kezdő és végpont markerek
    if (points.length > 0) {
        L.marker(points[0]).addTo(map).bindPopup('Kezdőpont');
        L.marker(points[points.length - 1]).addTo(map).bindPopup('Végpont');
        
        // Térkép igazítása az útvonalhoz
        map.fitBounds(points);
    }
    <?php endif; ?>
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>