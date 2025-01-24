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

// Weather API konfiguráció
define('WEATHER_API_KEY', '80243abd27585439d6fbe4b131be510b');

// Üzletek és koordinátáik
$stores = [
    'szemes_lenti' => [
        'name' => 'Szemes Lenti Bolt',
        'address' => 'Balatonszemes Ady Endre utca 4',
        'coords' => ['lat' => 46.8062, 'lon' => 17.7845],
        'zip' => '8636'
    ],
    'szemes_fenti' => [
        'name' => 'Szemes Fenti Bolt',
        'address' => 'Balatonszemes Szabadság Utca 46',
        'coords' => ['lat' => 46.8062, 'lon' => 17.7845],
        'zip' => '8636'
    ],
    'szarszo' => [
        'name' => 'Balatonszárszó',
        'address' => 'Balatonszárszó, Fő u. 5',
        'coords' => ['lat' => 46.8283, 'lon' => 17.8378],
        'zip' => '8624'
    ],
    'mariafurdo1' => [
        'name' => 'Balatonmáriafürdő 1',
        'address' => 'Balatonmáriafürdő, Gr. Széchényi Imre tér 5',
        'coords' => ['lat' => 46.7022, 'lon' => 17.3889],
        'zip' => '8647'
    ],
    'mariafurdo2' => [
        'name' => 'Balatonmáriafürdő 2',
        'address' => 'Gróf Széchényi Imre tér 3',
        'coords' => ['lat' => 46.7022, 'lon' => 17.3889],
        'zip' => '8647'
    ]
];

// Időjárás lekérése egy adott lokációra
function getWeatherForecast($lat, $lon) {
    $url = sprintf(
        'https://api.openweathermap.org/data/2.5/forecast?lat=%s&lon=%s&appid=%s&units=metric&lang=hu',
        $lat,
        $lon,
        WEATHER_API_KEY
    );
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Szezonalitás meghatározása
function getSeasonType($date) {
    $month = date('n', strtotime($date));
    $day = date('j', strtotime($date));
    
    // Főszezon: június 15 - augusztus 31
    if (($month == 6 && $day >= 15) || $month == 7 || $month == 8) {
        return 'high_season';
    }
    // Előszezon: május 1 - június 14
    elseif ($month == 5 || ($month == 6 && $day < 15)) {
        return 'pre_season';
    }
    // Utószezon: szeptember 1 - szeptember 30
    elseif ($month == 9) {
        return 'post_season';
    }
    // Holtszezon: minden más időszak
    else {
        return 'off_season';
    }
}

// Nap típusának meghatározása
function getDayType($date) {
    $day_of_week = date('N', strtotime($date));
    $month = date('n', strtotime($date));
    $day = date('j', strtotime($date));
    
    // Hétvége
    if ($day_of_week >= 6) {
        return 'weekend';
    }
    
    // Iskolai időszak vagy szünet
    if ($month >= 6 && $month <= 8) {
        return 'summer_break';
    }
    elseif ($month == 12 && $day >= 22) {
        return 'winter_break';
    }
    else {
        return 'school_day';
    }
}

// Előrejelzés kalkulálása
function calculateForecast($shop_id, $date, $weather_data) {
    global $pdo;
    
    // Historikus adatok lekérése
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.name as product_name,
            AVG(CASE WHEN DAYOFWEEK(d.delivery_date) IN (1,7) THEN 
                d.quantity - COALESCE(r.quantity, 0)
            END) as avg_weekend_sales,
            AVG(CASE WHEN DAYOFWEEK(d.delivery_date) NOT IN (1,7) THEN 
                d.quantity - COALESCE(r.quantity, 0)
            END) as avg_weekday_sales,
            COALESCE(AVG(r.quantity), 0) as avg_returns
        FROM products p
        LEFT JOIN deliveries d ON p.id = d.product_id
        LEFT JOIN returns r ON d.delivery_date = r.return_date 
            AND d.product_id = r.product_id
            AND d.shop_id = r.shop_id
        WHERE d.shop_id = ?
        AND d.delivery_date BETWEEN DATE_SUB(?, INTERVAL 3 MONTH) AND ?
        GROUP BY p.id, p.name
    ");
    
    $stmt->execute([$shop_id, $date, $date]);
    $historical_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Időjárás hatása
    $weather_impact = calculateWeatherImpact($weather_data);
    
    // Szezon hatása
    $season_impact = calculateSeasonImpact(getSeasonType($date));
    
    // Nap típusának hatása
    $day_type_impact = calculateDayTypeImpact(getDayType($date));
    
    $forecasts = [];
    foreach ($historical_data as $product) {
        // Alap mennyiség meghatározása
        $base_quantity = (getDayType($date) == 'weekend') 
            ? $product['avg_weekend_sales'] 
            : $product['avg_weekday_sales'];
            
        // Végső mennyiség számítása az összes tényező figyelembevételével
        $forecasted_quantity = $base_quantity * $weather_impact * $season_impact * $day_type_impact;
        
        $forecasts[$product['id']] = [
            'product_name' => $product['product_name'],
            'base_quantity' => $base_quantity,
            'forecasted_quantity' => round($forecasted_quantity),
            'confidence' => calculateConfidence($product, $weather_data),
            'factors' => [
                'weather' => $weather_impact,
                'season' => $season_impact,
                'day_type' => $day_type_impact
            ]
        ];
    }
    
    return $forecasts;
}

// Időjárás hatásának számítása
function calculateWeatherImpact($weather_data) {
    $temp = $weather_data['main']['temp'];
    $weather_condition = $weather_data['weather'][0]['main'];
    
    $impact = 1.0;
    
    // Hőmérséklet hatása
    if ($temp > 25) {
        $impact *= 1.2; // Meleg időben több vásárlás
    } elseif ($temp < 10) {
        $impact *= 0.9; // Hideg időben kevesebb vásárlás
    }
    
    // Időjárási kondíció hatása
    switch ($weather_condition) {
        case 'Rain':
        case 'Thunderstorm':
            $impact *= 0.8; // Esős időben kevesebb vásárlás
            break;
        case 'Clear':
            $impact *= 1.1; // Tiszta időben több vásárlás
            break;
    }
    
    return $impact;
}

// Szezon hatásának számítása
function calculateSeasonImpact($season_type) {
    switch ($season_type) {
        case 'high_season':
            return 2.0; // Főszezonban dupla forgalom
        case 'pre_season':
            return 1.3; // Előszezonban 30% növekedés
        case 'post_season':
            return 1.2; // Utószezonban 20% növekedés
        default:
            return 1.0; // Holtszezonban normál forgalom
    }
}

// Nap típus hatásának számítása
function calculateDayTypeImpact($day_type) {
    switch ($day_type) {
        case 'weekend':
            return 1.5; // Hétvégén 50% növekedés
        case 'summer_break':
            return 1.3; // Nyári szünetben 30% növekedés
        case 'winter_break':
            return 1.2; // Téli szünetben 20% növekedés
        default:
            return 1.0; // Hétköznapokon normál forgalom
    }
}

// Előrejelzés megbízhatóságának számítása
function calculateConfidence($product_data, $weather_data) {
    $confidence = 0.7; // Alap megbízhatóság
    
    // Ha van elegendő historikus adat
    if ($product_data['avg_weekday_sales'] > 0 && $product_data['avg_weekend_sales'] > 0) {
        $confidence += 0.1;
    }
    
    // Ha az időjárás stabil
    if ($weather_data['weather'][0]['main'] == 'Clear') {
        $confidence += 0.1;
    }
    
    return min($confidence, 1.0);
}

// Form feldolgozása és előrejelzés generálása
$selected_shop = $_GET['shop_id'] ?? null;
$forecast_date = $_GET['forecast_date'] ?? date('Y-m-d');

$weather_forecasts = [];
$sales_forecasts = [];

if ($selected_shop) {
    foreach ($stores as $store) {
        $weather = getWeatherForecast($store['coords']['lat'], $store['coords']['lon']);
        if ($weather) {
            $weather_forecasts[$store['name']] = $weather;
            $sales_forecasts[$store['name']] = calculateForecast(
                $selected_shop,
                $forecast_date,
                $weather['list'][0]
            );
        }
    }
}

// Üzletek lekérése a dropdown-hoz
$shops = $pdo->query("SELECT * FROM shops ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Előrejelzés - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    :root {
        --primary-color: #2c3e50;
        --secondary-color: #34495e;
        --accent-color: #e67e22;
        --success-color: #27ae60;
        --danger-color: #e74c3c;
        --warning-color: #f1c40f;
        --light-color: #ecf0f1;
        --dark-color: #2c3e50;
    }

    body {
        background: linear-gradient(135deg, #f6f8fa 0%, #e9ecef 100%);
        min-height: 100vh;
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    }

    .main-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        padding: 2rem 0;
        margin-bottom: 2rem;
        color: white;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    }

    .content-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        margin-bottom: 2rem;
        overflow: hidden;
    }

    .weather-card {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        margin-bottom: 1rem;
    }

    .weather-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
    }

    .forecast-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        margin-bottom: 1rem;
        transition: all 0.3s ease;
    }

    .forecast-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
    }

    .confidence-indicator {
        width: 100%;
        height: 6px;
        background: var(--light-color);
        border-radius: 3px;
        margin-top: 0.5rem;
        overflow: hidden;
    }

    .confidence-level {
        height: 100%;
        background: linear-gradient(to right, var(--warning-color), var(--success-color));
        border-radius: 3px;
        transition: width 0.3s ease;
    }

    .weather-icon {
        font-size: 2.5rem;
        margin-right: 1rem;
        color: var(--light-color);
    }

    .table-custom {
        margin-bottom: 0;
    }

    .table-custom th {
        background: var(--primary-color);
        color: white;
        font-weight: 500;
        text-transform: uppercase;
        font-size: 0.85rem;
    }

    .btn-action {
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-size: 0.9rem;
    }

    .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .stat-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        height: 100%;
        overflow: hidden;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
    }

    .stat-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 1.5rem;
        border-radius: 15px 15px 0 0;
        position: relative;
        overflow: hidden;
    }

    .chart-container {
        position: relative;
        margin: auto;
        height: 300px;
        width: 100%;
    }

    .trend-info {
        background: linear-gradient(135deg, var(--accent-color), #d35400);
        color: white;
        padding: 1.25rem;
        border-radius: 10px;
        margin-top: 1.5rem;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .animate-fade-in {
        animation: fadeIn 0.5s ease-out forwards;
    }
</style>

<header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">AI Előrejelzés</p>
                </div>
                <div class="d-flex gap-3 align-items-center">
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
                            <li><a class="dropdown-item active" href="ai_forecast.php"><i class="fas fa-chart-line me-2"></i>AI Előrejelzés</a></li>
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
    </header>

</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <!-- Szűrő form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Üzlet kiválasztása</label><select name="shop_id" class="form-select" required>
                            <option value="">Válasszon üzletet...</option>
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?php echo $shop['id']; ?>" 
                                        <?php echo $selected_shop == $shop['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($shop['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Előrejelzés dátuma</label>
                        <input type="date" name="forecast_date" class="form-control" 
                               value="<?php echo $forecast_date; ?>" required
                               min="<?php echo date('Y-m-d'); ?>"
                               max="<?php echo date('Y-m-d', strtotime('+5 days')); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-calculator me-2"></i>Előrejelzés készítése
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selected_shop && !empty($weather_forecasts)): ?>
            <div class="row">
                <!-- Időjárás előrejelzés -->
                <div class="col-md-4">
                    <?php foreach ($weather_forecasts as $store_name => $weather): ?>
                        <div class="weather-card p-4">
                            <h5 class="d-flex align-items-center mb-3">
                                <i class="fas <?php 
                                    $condition = strtolower($weather['list'][0]['weather'][0]['main']);
                                    echo match($condition) {
                                        'clear' => 'fa-sun',
                                        'clouds' => 'fa-cloud',
                                        'rain' => 'fa-cloud-rain',
                                        'thunderstorm' => 'fa-bolt',
                                        'snow' => 'fa-snowflake',
                                        default => 'fa-cloud'
                                    };
                                ?> weather-icon"></i>
                                <?php echo $store_name; ?>
                            </h5>
                            <div class="mb-2">
                                <strong>Hőmérséklet:</strong> 
                                <?php echo round($weather['list'][0]['main']['temp']); ?>°C
                            </div>
                            <div class="mb-2">
                                <strong>Páratartalom:</strong> 
                                <?php echo $weather['list'][0]['main']['humidity']; ?>%
                            </div>
                            <div>
                                <strong>Időjárás:</strong> 
                                <?php echo $weather['list'][0]['weather'][0]['description']; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Eladási előrejelzés -->
                <div class="col-md-8">
                    <div class="forecast-card p-4">
                        <h5 class="mb-4">Eladási előrejelzés</h5>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Termék</th>
                                        <th>Javasolt mennyiség</th>
                                        <th>Alap mennyiség</th>
                                        <th>Megbízhatóság</th>
                                        <th>Befolyásoló tényezők</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sales_forecasts as $store_name => $forecasts): ?>
                                        <?php foreach ($forecasts as $product_id => $forecast): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($forecast['product_name']); ?></td>
                                                <td>
                                                    <strong class="text-primary">
                                                        <?php echo round($forecast['forecasted_quantity']); ?> db
                                                    </strong>
                                                </td>
                                                <td><?php echo round($forecast['base_quantity']); ?> db</td>
                                                <td>
                                                    <div class="confidence-indicator">
                                                        <div class="confidence-level" 
                                                             style="width: <?php echo $forecast['confidence'] * 100; ?>%">
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small>
                                                        Időjárás: <?php echo round(($forecast['factors']['weather'] - 1) * 100); ?>%<br>
                                                        Szezon: <?php echo round(($forecast['factors']['season'] - 1) * 100); ?>%<br>
                                                        Nap típusa: <?php echo round(($forecast['factors']['day_type'] - 1) * 100); ?>%
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Grafikonok és elemzések -->
                    <div class="forecast-card p-4">
                        <h5 class="mb-4">Trendelemzés</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <canvas id="salesTrendChart"></canvas>
                            </div>
                            <div class="col-md-6">
                                <canvas id="factorsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            // Trend grafikon
            new Chart(document.getElementById('salesTrendChart'), {
                type: 'line',
                data: {
                    labels: ['Előző hét', 'Múlt hét', 'Jelenlegi', 'Előrejelzés'],
                    datasets: [{
                        label: 'Eladások trendje',
                        data: [65, 59, 80, 81],
                        borderColor: '#3498db',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Eladási trend'
                        }
                    }
                }
            });

            // Tényezők grafikon
            new Chart(document.getElementById('factorsChart'), {
                type: 'radar',
                data: {
                    labels: ['Időjárás', 'Szezon', 'Nap típusa', 'Historikus adat', 'Trend'],
                    datasets: [{
                        label: 'Befolyásoló tényezők súlya',
                        data: [0.8, 0.9, 0.7, 0.85, 0.75],
                        fill: true,
                        backgroundColor: 'rgba(52, 152, 219, 0.2)',
                        borderColor: '#3498db',
                        pointBackgroundColor: '#3498db'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Tényezők elemzése'
                        }
                    }
                }
            });
            </script>

        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Válasszon üzletet és dátumot az előrejelzés elkészítéséhez!
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>