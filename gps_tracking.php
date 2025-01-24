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
    $email = "admin@szemesipekseg.hu"; // Fix email cím használata
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.trackgps.ro/api/authentication/login?api-version=2.0');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'username' => $email
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    if (isset($result['token'])) {
        $api_token = $result['token'];
        $_SESSION['gps_token'] = $api_token;
        $_SESSION['success'] = 'API token sikeresen lekérve!';
    } else {
        $_SESSION['error'] = 'Hiba történt a token lekérése során!';
    }
}

// Járművek lekérése
$vehicles = [];
if (isset($_SESSION['gps_token'])) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.trackgps.ro/api/carriers/company-vehicles?api-version=2.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $_SESSION['gps_token']
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
    </style>
</head>
<body>
    <div class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">GPS Követés</p>
                </div>
                <div class="d-flex gap-3 align-items-center">
                    <a href="../index.php" class="btn btn-light">
                        <i class="fas fa-home me-2"></i>Főoldal
                    </a>
                    <a href="../logout.php" class="btn btn-danger">
                        <i class="fas fa-sign-out-alt me-2"></i>Kijelentkezés
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container py-4">
        <?php if (!isset($_SESSION['gps_token'])): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">GPS Követés bejelentkezés</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <p class="mb-3">A GPS követés használatához kattintson a bejelentkezés gombra.</p>
                    <button type="submit" name="get_token" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt me-2"></i>Bejelentkezés
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['gps_token'])): ?>
        <!-- Térkép -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">GPS Térkép</h5>
            </div>
            <div class="card-body">
                <div id="map"></div>
            </div>
        </div>

        <!-- Keresési form -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Járművek és Adatok</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
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
                            <button type="submit" name="get_coordinates" class="btn btn-primary" 
                                    title="Útvonal lekérése">
                                <i class="fas fa-route"></i>
                            </button>
                            <button type="submit" name="get_consumption" class="btn btn-success" 
                                    title="Fogyasztás lekérése">
                                <i class="fas fa-gas-pump"></i>
                            </button>
                            <button type="submit" name="get_stops" class="btn btn-warning" 
                                    title="Megállók lekérése">
                                <i class="fas fa-parking"></i>
                            </button>
                            <button type="submit" name="get_refills" class="btn btn-danger" 
                                    title="Tankolások lekérése">
                                <i class="fas fa-fill-drip"></i>
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Tabs -->
                <ul class="nav nav-tabs mt-4" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#coordinates">
                            <i class="fas fa-route me-2"></i>Útvonal
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#consumption">
                            <i class="fas fa-gas-pump me-2"></i>Fogyasztás
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#stops">
                            <i class="fas fa-parking me-2"></i>Megállók
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#refills">
                            <i class="fas fa-fill-drip me-2"></i>Tankolások
                        </a>
                    </li>
                </ul>

                <!-- Tab tartalom -->
                <div class="tab-content mt-3">
                    <!-- Útvonal tab -->
                    <div class="tab-pane fade show active" id="coordinates">
                        <?php if (!empty($coordinates)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Időpont</th>
                                            <th>Szélesség</th>
                                            <th>Hosszúság</th>
                                            <th>Sebesség</th>
                                            <th>Cím</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($coordinates as $coord): ?>
                                        <tr>
                                            <td><?php echo date('Y-m-d H:i:s', strtotime($coord['timestamp'])); ?></td>
                                            <td><?php echo $coord['latitude']; ?></td>
                                            <td><?php echo $coord['longitude']; ?></td>
                                            <td><?php echo $coord['speed']; ?> km/h</td>
                                            <td><?php echo htmlspecialchars($coord['address'] ?? ''); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Válasszon járművet és időszakot az útvonal adatok megtekintéséhez!
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Fogyasztás tab -->
                    <div class="tab-pane fade" id="consumption">
                        <?php if (!empty($consumption)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Dátum</th>
                                            <th>Megtett táv</th>
                                            <th>Fogyasztás</th>
                                            <th>Átlagfogyasztás</th>
                                            <th>Üzemanyagszint</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($consumption as $cons): ?>
                                        <tr>
                                            <td><?php echo date('Y-m-d', strtotime($cons['date'])); ?></td>
                                            <td><?php echo number_format($cons['distance'], 1); ?> km</td>
                                            <td><?php echo number_format($cons['consumption'], 1); ?> L</td>
                                            <td><?php echo number_format($cons['average_consumption'], 1); ?> L/100km</td>
                                            <td><?php echo $cons['fuel_level']; ?>%</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Válasszon járművet és időszakot a fogyasztási adatok megtekintéséhez!
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Megállók tab -->
                    <div class="tab-pane fade" id="stops">
                        <?php if (!empty($stops)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Kezdés</th>
                                            <th>Befejezés</th>
                                            <th>Időtartam</th>
                                            <th>Cím</th>
                                            <th>Megjegyzés</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stops as $stop): ?>
                                        <tr>
                                            <td><?php echo date('Y-m-d H:i:s', strtotime($stop['start_time'])); ?></td>
                                            <td><?php echo date('Y-m-d H:i:s', strtotime($stop['end_time'])); ?></td>
                                            <td><?php echo $stop['duration']; ?> perc</td>
                                            <td><?php echo htmlspecialchars($stop['address']); ?></td>
                                            <td><?php echo htmlspecialchars($stop['notes'] ?? ''); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Válasszon járművet és időszakot a megállási adatok megtekintéséhez!
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Tankolások tab -->
                    <div class="tab-pane fade" id="refills">
                        <?php if (!empty($refills)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Dátum</th>
                                            <th>Mennyiség</th>
                                            <th>Összeg</th>
                                            <th>Benzinkút</th>
                                            <th>Fizetési mód</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($refills as $refill): ?>
                                        <tr>
                                            <td><?php echo date('Y-m-d H:i:s', strtotime($refill['timestamp'])); ?></td>
                                            <td><?php echo number_format($refill['amount'], 1); ?> L</td>
                                            <td><?php echo number_format($refill['cost'], 0, ',', ' '); ?> Ft</td>
                                            <td><?php echo htmlspecialchars($refill['station_name']); ?></td>
                                            <td><?php echo htmlspecialchars($refill['payment_method'] ?? '-'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Válasszon járművet és időszakot a tankolási adatok megtekintéséhez!
                            </div>
                        <?php endif; ?>
                    </div>
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

    // Megállók megjelenítése a térképen
    <?php if (!empty($stops)): ?>
        <?php foreach ($stops as $stop): ?>
        L.marker([<?php echo $stop['latitude']; ?>, <?php echo $stop['longitude']; ?>])
            .addTo(map)
            .bindPopup('Megálló: <?php echo date('Y-m-d H:i', strtotime($stop['start_time'])); ?>');
        <?php endforeach; ?>
    <?php endif; ?>
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>