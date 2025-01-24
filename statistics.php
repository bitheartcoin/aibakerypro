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

// Szűrési paraméterek
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$selected_user = $_GET['user_id'] ?? null;
$document_type = $_GET['document_type'] ?? null;

// Szűrési paraméterek
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$shop_id = $_GET['shop_id'] ?? null;
$product_id = $_GET['product_id'] ?? null;

// Kiszállítási és visszáru adatok lekérése
$query = "
    SELECT 
        DATE(d.delivery_date) as date,
        s.name as shop_name,
        p.name as product_name,
        d.quantity as delivered_quantity,
        COALESCE(r.quantity, 0) as returned_quantity,
        d.quantity - COALESCE(r.quantity, 0) as sold_quantity,
        CASE 
            WHEN WEEKDAY(d.delivery_date) < 5 THEN 'Hétköznap'
            ELSE 'Hétvége'
        END as day_type,
        CASE 
            WHEN MONTH(d.delivery_date) IN (6,7,8) THEN 'Nyári szünet'
            WHEN MONTH(d.delivery_date) IN (12) AND DAY(d.delivery_date) >= 20 THEN 'Téli szünet'
            ELSE 'Iskolaidő'
        END as season
    FROM deliveries d
    JOIN shops s ON d.shop_id = s.id
    JOIN products p ON d.product_id = p.id
    LEFT JOIN returns r ON d.shop_id = r.shop_id 
        AND d.product_id = r.product_id 
        AND d.delivery_date = r.return_date
    WHERE d.delivery_date BETWEEN ? AND ?";

$params = [$start_date, $end_date];

if ($shop_id) {
    $query .= " AND d.shop_id = ?";
    $params[] = $shop_id;
}

if ($product_id) {
    $query .= " AND d.product_id = ?";
    $params[] = $product_id;
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll();

// Napi trend számítása
$daily_stats = [];
$product_stats = [];
$shop_stats = [];
$season_stats = [];

foreach ($data as $row) {
    // Napi statisztika
    if (!isset($daily_stats[$row['date']])) {
        $daily_stats[$row['date']] = [
            'delivered' => 0,
            'returned' => 0,
            'sold' => 0
        ];
    }
    $daily_stats[$row['date']]['delivered'] += $row['delivered_quantity'];
    $daily_stats[$row['date']]['returned'] += $row['returned_quantity'];
    $daily_stats[$row['date']]['sold'] += $row['sold_quantity'];

    // Termék statisztika
    if (!isset($product_stats[$row['product_name']])) {
        $product_stats[$row['product_name']] = [
            'delivered' => 0,
            'returned' => 0,
            'sold' => 0
        ];
    }
    $product_stats[$row['product_name']]['delivered'] += $row['delivered_quantity'];
    $product_stats[$row['product_name']]['returned'] += $row['returned_quantity'];
    $product_stats[$row['product_name']]['sold'] += $row['sold_quantity'];

    // Üzlet statisztika
    if (!isset($shop_stats[$row['shop_name']])) {
        $shop_stats[$row['shop_name']] = [
            'delivered' => 0,
            'returned' => 0,
            'sold' => 0
        ];
    }
    $shop_stats[$row['shop_name']]['delivered'] += $row['delivered_quantity'];
    $shop_stats[$row['shop_name']]['returned'] += $row['returned_quantity'];
    $shop_stats[$row['shop_name']]['sold'] += $row['sold_quantity'];

    // Szezon statisztika
    if (!isset($season_stats[$row['season']])) {
        $season_stats[$row['season']] = [
            'delivered' => 0,
            'returned' => 0,
            'sold' => 0
        ];
    }
    $season_stats[$row['season']]['delivered'] += $row['delivered_quantity'];
    $season_stats[$row['season']]['returned'] += $row['returned_quantity'];
    $season_stats[$row['season']]['sold'] += $row['sold_quantity'];
}

// Összes mennyiség számítása
$total_delivered = array_sum(array_column($data, 'delivered_quantity'));
$total_returned = array_sum(array_column($data, 'returned_quantity'));
$total_sold = $total_delivered - $total_returned;
$return_rate = $total_delivered > 0 ? ($total_returned / $total_delivered) * 100 : 0;

// Chart.js adatok előkészítése
$chart_data = [
    'daily' => [
        'labels' => array_keys($daily_stats),
        'delivered' => array_column($daily_stats, 'delivered'),
        'returned' => array_column($daily_stats, 'returned'),
        'sold' => array_column($daily_stats, 'sold')
    ],
    'products' => [
        'labels' => array_keys($product_stats),
        'return_rates' => array_map(function($stats) {
            return $stats['delivered'] > 0 ? 
                ($stats['returned'] / $stats['delivered']) * 100 : 0;
        }, $product_stats)
    ],
    'shops' => [
        'labels' => array_keys($shop_stats),
        'return_rates' => array_map(function($stats) {
            return $stats['delivered'] > 0 ? 
                ($stats['returned'] / $stats['delivered']) * 100 : 0;
        }, $shop_stats)
    ],
    'seasons' => [
        'labels' => array_keys($season_stats),
        'return_rates' => array_map(function($stats) {
            return $stats['delivered'] > 0 ? 
                ($stats['returned'] / $stats['delivered']) * 100 : 0;
        }, $season_stats)
    ]
];

// Termékek és üzletek lekérése a szűrőhöz
$shops = $pdo->query("SELECT * FROM shops ORDER BY name")->fetchAll();
$products = $pdo->query("SELECT * FROM products WHERE active = 1 ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statisztikák - Admin</title>
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

        .btn-action {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .nav-link {
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .dropdown-menu {
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .dropdown-item {
            padding: 0.75rem 1.5rem;
            transition: all 0.3s ease;
        }

        .dropdown-item:hover {
            background: var(--light-color);
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            height: 100%;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }

        .chart-container {
            position: relative;
            margin: auto;
            height: 300px;
            width: 100%;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">Statisztikák</p>
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
    </header>

    <div class="container-fluid">
<!-- Szűrő form -->
<div class="content-card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Kezdő dátum</label>
                        <input type="date" name="start_date" class="form-control" 
                               value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Végző dátum</label>
                        <input type="date" name="end_date" class="form-control" 
                               value="<?php echo $end_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Üzlet</label>
                        <select name="shop_id" class="form-select">
                            <option value="">Összes</option>
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?php echo $shop['id']; ?>"
                                        <?php echo $shop_id == $shop['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($shop['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Termék</label>
                        <select name="product_id" class="form-select">
                            <option value="">Összes</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>"
                                        <?php echo $product_id == $product['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($product['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-2"></i>Szűrés
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Összesítő kártyák -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="card-body">
                        <h5 class="card-title text-primary">
                            <i class="fas fa-box me-2"></i>Összes kiszállítás
                        </h5>
                        <h3><?php echo number_format($total_delivered, 0, ',', ' '); ?> db</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="card-body">
                        <h5 class="card-title text-danger">
                            <i class="fas fa-undo me-2"></i>Összes visszáru
                        </h5>
                        <h3><?php echo number_format($total_returned, 0, ',', ' '); ?> db</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="card-body">
                        <h5 class="card-title text-success">
                            <i class="fas fa-shopping-cart me-2"></i>Összes eladás
                        </h5>
                        <h3><?php echo number_format($total_sold, 0, ',', ' '); ?> db</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="card-body">
                        <h5 class="card-title text-warning">
                            <i class="fas fa-chart-pie me-2"></i>Visszáru arány
                        </h5>
                        <h3><?php echo number_format($return_rate, 1, ',', ' '); ?>%</h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grafikonok -->
        <div class="row">
            <!-- Napi trend -->
            <div class="col-12 mb-4">
                <div class="content-card">
                    <div class="card-header">
                        <h5 class="mb-0">Napi trend</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="dailyTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Termékenkénti visszáru arány -->
            <div class="col-md-6 mb-4">
                <div class="content-card">
                    <div class="card-header">
                        <h5 class="mb-0">Termékenkénti visszáru arány</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="productReturnRateChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Üzletenkénti visszáru arány -->
            <div class="col-md-6 mb-4">
                <div class="content-card">
                    <div class="card-header">
                        <h5 class="mb-0">Üzletenkénti visszáru arány</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="shopReturnRateChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Szezon szerinti visszáru arány -->
            <div class="col-12 mb-4">
                <div class="content-card">
                    <div class="card-header">
                        <h5 class="mb-0">Szezon szerinti visszáru arány</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="seasonReturnRateChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    const chartData = <?php echo json_encode($chart_data); ?>;

    // Napi trend grafikon
    new Chart(document.getElementById('dailyTrendChart'), {
        type: 'line',
        data: {
            labels: chartData.daily.labels,
            datasets: [
                {
                    label: 'Kiszállítás',
                    data: chartData.daily.delivered,
                    borderColor: '#4e73df',
                    fill: false
                },
                {
                    label: 'Visszáru',
                    data: chartData.daily.returned,
                    borderColor: '#e74a3b',
                    fill: false
                },
                {
                    label: 'Eladás',
                    data: chartData.daily.sold,
                    borderColor: '#1cc88a',
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    // Termékenkénti visszáru arány grafikon
    new Chart(document.getElementById('productReturnRateChart'), {
        type: 'bar',
        data: {
            labels: chartData.products.labels,
            datasets: [{
                label: 'Visszáru %',
                data: chartData.products.return_rates,
                backgroundColor: '#e74a3b'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Visszáru %'
                    }
                }
            }
        }
    });

    // Üzletenkénti visszáru arány grafikon
    new Chart(document.getElementById('shopReturnRateChart'), {
        type: 'bar',
        data: {
            labels: chartData.shops.labels,
            datasets: [{
                label: 'Visszáru %',
                data: chartData.shops.return_rates,
                backgroundColor: '#f6c23e'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Visszáru %'
                    }
                }
            }
        }
    });

    // Szezon szerinti visszáru arány grafikon
    new Chart(document.getElementById('seasonReturnRateChart'), {
        type: 'bar',
        data: {
            labels: chartData.seasons.labels,
            datasets: [{
                label: 'Visszáru %',
                data: chartData.seasons.return_rates,
                backgroundColor: '#36b9cc'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Visszáru %'
                    }
                }
            }
        }
    });
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>