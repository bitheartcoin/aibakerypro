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

// Fizetés kifizetésének kezelése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_salary'])) {
    try {
        $pdo->beginTransaction();
        
        $username = $_POST['username'];
        $amount = $_POST['amount'];
        $shop_id = $_POST['shop_id'] ?? 1; // Alapértelmezett üzlet ID
        
        // Fizetés kifizetés rögzítése
        $stmt = $pdo->prepare("
            INSERT INTO transactions 
            (shop_id, type, category, amount, description, created_at) 
            VALUES 
            (?, 'expense', 'fizetes_kifizetes', ?, ?, NOW())
        ");
        
        $description = $username . " fizetés kifizetése - " . date('Y.m');
        $stmt->execute([$shop_id, $amount, $description]);
        
        // Előleg nullázása (opcionális, mivel az új előleg-kezelés már figyeli a kifizetéseket)
        $stmt = $pdo->prepare("
            INSERT INTO transactions 
            (shop_id, type, category, amount, description, created_at) 
            VALUES 
            (?, 'expense', 'eloleg_nullazas', 0, ?, NOW())
        ");
        $description = $username . " előleg nullázása - " . date('Y.m');
        $stmt->execute([$shop_id, $description]);
        
        $pdo->commit();
        $_SESSION['success'] = 'Fizetés sikeresen kifizetve és előleg nullázva!';
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Hiba történt a kifizetés során: ' . $e->getMessage();
    }
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
    exit;
}

// Szűrési paraméterek
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$selected_user = $_GET['user_id'] ?? null;

// Felhasználók lekérése
$users = $pdo->query("SELECT id, username FROM users WHERE active = 1 ORDER BY username")->fetchAll();

// Munkaidő és fizetés számítás
$user_stats = [];
$total_hours = 0;
$total_earned = 0;
$total_advances = 0;

// Felhasználónkénti számítások
foreach ($users as $u) {
    // Munkaidő számítása
    $stmt = $pdo->prepare("
        SELECT 
            SUM(total_hours) as total_hours,
            hourly_rate
        FROM work_hours wh
        JOIN users u ON wh.user_id = u.id
        WHERE wh.user_id = ? 
        AND DATE(wh.check_in) BETWEEN ? AND ?
        AND wh.check_out IS NOT NULL
        GROUP BY u.id, u.hourly_rate
    ");
    $stmt->execute([$u['id'], $start_date, $end_date]);
    $work_data = $stmt->fetch(PDO::FETCH_ASSOC);

    // Előlegek és kifizetések számítása
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(
                CASE 
                    WHEN category = 'fizeteseloleg' THEN amount
                    WHEN category = 'fizetes_kifizetes' THEN -amount
                    WHEN category = 'eloleg_nullazas' THEN 0
                END
            ), 0) as total_advances
        FROM transactions 
        WHERE type = 'expense'
        AND category IN ('fizeteseloleg', 'fizetes_kifizetes', 'eloleg_nullazas')
        AND description LIKE ?
        AND DATE(created_at) <= ?
    ");
    $stmt->execute(['%' . $u['username'] . '%', $end_date]);
    $advances = $stmt->fetch(PDO::FETCH_ASSOC);

    $hours = $work_data['total_hours'] ?? 0;
    $hourly_rate = $work_data['hourly_rate'] ?? 0;
    $earned = $hours * $hourly_rate;
    $advance = $advances['total_advances'];

    // Statisztika tárolása
    $user_stats[$u['username']] = [
        'hours' => $hours,
        'earned' => $earned,
        'advance' => $advance,
        'hourly_rate' => $hourly_rate
    ];

    // Összesítés
    $total_hours += $hours;
    $total_earned += $earned;
    $total_advances += $advance;
}

// Munkaidők lekérdezése
$work_hours_query = "
    SELECT 
        wh.*,
        u.username,
        u.hourly_rate
    FROM work_hours wh
    JOIN users u ON wh.user_id = u.id
    WHERE DATE(wh.check_in) BETWEEN ? AND ?
";

$params = [$start_date, $end_date];

if ($selected_user) {
    $work_hours_query .= " AND wh.user_id = ?";
    $params[] = $selected_user;
}

$work_hours_query .= " ORDER BY wh.check_in DESC";
$stmt = $pdo->prepare($work_hours_query);
$stmt->execute($params);
$work_hours = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fizetések Kezelése - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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

        .stat-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            height: 100%;
            transition: all 0.3s ease;
        }
    </style>
</head>
<body>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fizetések Kezelése - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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

        .stat-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            height: 100%;
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
                    <p class="mb-0 opacity-75">Fizetések kezelése</p>
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
                    <a href="partners.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Vissza
                    </a>
                    <a href="../logout.php" class="btn btn-danger">
                        <i class="fas fa-sign-out-alt me-2"></i>Kijelentkezés
                    </a>
                </div>
            </div>
        </div>
    </div>

    </nav>

    <div class="container py-4">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4">
                <i class="fas fa-check-circle me-2"></i>
                <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Szűrő form -->
        <div class="card mb-4">
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
                    <div class="col-md-4">
                        <label class="form-label">Felhasználó</label>
                        <select name="user_id" class="form-select">
                            <option value="">Összes felhasználó</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>" 
                                        <?php echo $selected_user == $u['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>Szűrés
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statisztikai kártyák -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card bg-primary text-white">
                    <h6 class="mb-2"><i class="fas fa-clock me-2"></i>Összes munkaóra</h6>
                    <h3><?php echo number_format($total_hours, 1, ',', ' '); ?> óra</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-success text-white">
                    <h6 class="mb-2"><i class="fas fa-money-bill me-2"></i>Összes fizetés</h6>
                    <h3><?php echo number_format($total_earned, 0, ',', ' '); ?> Ft</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-warning text-white">
                    <h6 class="mb-2"><i class="fas fa-hand-holding-usd me-2"></i>Aktív előlegek</h6>
                    <h3><?php echo number_format($total_advances, 0, ',', ' '); ?> Ft</h3>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-info text-white">
                    <h6 class="mb-2"><i class="fas fa-calculator me-2"></i>Kifizetendő összeg</h6>
                    <h3><?php echo number_format(max(0, $total_earned - $total_advances), 0, ',', ' '); ?> Ft</h3>
                </div>
            </div>
        </div>

        <!-- Felhasználónkénti összesítés -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Felhasználónkénti összesítés</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Felhasználó</th>
                                <th>Munkaórák</th>
                                <th>Órabér</th>
                                <th>Fizetés</th>
                                <th>Előleg</th>
                                <th>Kifizetendő</th>
                                <th>Műveletek</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_stats as $username => $stats): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($username); ?></td>
                                <td><?php echo number_format($stats['hours'], 1, ',', ' '); ?> óra</td>
                                <td><?php echo number_format($stats['hourly_rate'], 0, ',', ' '); ?> Ft/óra</td>
                                <td><?php echo number_format($stats['earned'], 0, ',', ' '); ?> Ft</td>
                                <td>
                                    <?php if ($stats['advance'] > 0): ?>
                                        <span class="badge bg-warning">
                                            <?php echo number_format($stats['advance'], 0, ',', ' '); ?> Ft
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Nincs előleg</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $to_pay = max(0, $stats['earned'] - $stats['advance']);
                                    echo number_format($to_pay, 0, ',', ' '); ?> Ft
                                </td>
                                <td>
                                    <?php if ($to_pay > 0): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                                        <input type="hidden" name="amount" value="<?php echo $to_pay; ?>">
                                        <button type="submit" name="pay_salary" class="btn btn-success btn-sm"
                                                onclick="return confirm('Biztosan kifizeted <?php echo htmlspecialchars($username); ?> fizetését (<?php echo number_format($to_pay, 0, ',', ' '); ?> Ft)?')">
                                            <i class="fas fa-money-bill-wave me-1"></i>Kifizetés
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>