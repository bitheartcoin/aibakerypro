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

// Function to calculate user payments
function calculateUserPayment($pdo, $user_id, $start_date, $end_date) {
    // Get work hours
    $stmt = $pdo->prepare("
        SELECT 
            SUM(total_hours) as total_hours,
            u.hourly_rate
        FROM work_hours wh
        JOIN users u ON wh.user_id = u.id
        WHERE wh.user_id = ? 
        AND DATE(wh.check_in) BETWEEN ? AND ?
        AND wh.check_out IS NOT NULL
        GROUP BY u.hourly_rate
    ");
    $stmt->execute([$user_id, $start_date, $end_date]);
    $work_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate base earnings
    $total_hours = $work_data['total_hours'] ?? 0;
    $hourly_rate = $work_data['hourly_rate'] ?? 0;
    $base_earnings = $total_hours * $hourly_rate;
    
    // Get advances for the period
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_advances
        FROM transactions 
        WHERE type = 'expense'
        AND category = 'fizeteseloleg'
        AND description LIKE ?
        AND DATE(created_at) BETWEEN ? AND ?
    ");
    
    // Get username for advance matching
    $stmt_user = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt_user->execute([$user_id]);
    $user = $stmt_user->fetch();
    
    $stmt->execute(['%' . $user['username'] . '%', $start_date, $end_date]);
    $advances = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $total_advances = $advances['total_advances'] ?? 0;
    
    // Final calculation
    $net_payment = $base_earnings - $total_advances;
    
    return [
        'total_hours' => $total_hours,
        'hourly_rate' => $hourly_rate,
        'base_earnings' => $base_earnings,
        'advances' => $total_advances,
        'net_payment' => $net_payment
    ];
}

// Felhasználók lekérése a szűrőhöz
$users = $pdo->query("SELECT id, username FROM users WHERE active = 1 ORDER BY username")->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Munkaidő Követés - Admin</title>
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

        .content-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .payment-card {
            transition: all 0.3s ease;
        }

        .payment-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
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
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .advance-warning {
            background: var(--warning-color);
            color: white;
            padding: 0.75rem;
            border-radius: 8px;
            margin-top: 1rem;
        }
        /* Ezeket add hozzá az admin.css fájlhoz */
.payment-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    margin-bottom: 2rem;
    overflow: hidden;
    transition: all 0.3s ease;
    height: 100%;
}

.payment-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
}

.payment-card .card-header {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    padding: 1.5rem;
    border-bottom: none;
}

.payment-card .card-body {
    padding: 1.5rem;
}

.payment-info {
    padding: 1rem;
    background: rgba(236, 240, 241, 0.5);
    border-radius: 10px;
    margin-bottom: 1rem;
}

.payment-info .label {
    color: var(--dark-color);
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
}

.payment-info .value {
    font-size: 1.25rem;
    font-weight: 500;
    color: var(--primary-color);
}

.advance-warning {
    background: linear-gradient(135deg, var(--warning-color), #f39c12);
    color: white;
    padding: 1.25rem;
    border-radius: 10px;
    margin-top: 1.5rem;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.advance-warning .btn {
    background: rgba(255, 255, 255, 0.9);
    color: var(--warning-color);
    border: none;
    font-weight: 500;
    margin-top: 1rem;
}

.advance-warning .btn:hover {
    background: white;
    transform: translateY(-2px);
}
    </style>
</head>
<body class="bg-light">
<header class="main-header">
        <div class="container-fluid">
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
                            <li><a class="dropdown-item active" href="payments.php"><i class="fas fa-money-bill-wave me-2"></i>Fizetések</a></li>
                            <li><a class="dropdown-item" href="statistics.php"><i class="fas fa-chart-line me-2"></i>Statisztikák</a></li>
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

    <div class="container-fluid py-4">
        <!-- Szűrő form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="tab" value="work_hours">
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
                            <i class="fas fa-filter me-2"></i>
                            Szűrés
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Összesítés kártyák -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-clock me-2"></i>Összes munkaóra</h5>
                        <h3><?php echo number_format($total_hours, 1, ',', ' '); ?> óra</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-money-bill me-2"></i>Összes fizetés</h5>
                        <h3><?php echo number_format($total_earned, 0, ',', ' '); ?> Ft</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-hand-holding-usd me-2"></i>Aktív előlegek</h5>
                        <h3><?php echo number_format($total_advances, 0, ',', ' '); ?> Ft</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-users me-2"></i>Aktív dolgozók</h5>
                        <h3><?php echo count($user_stats); ?> fő</h3>
                    </div>
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
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Felhasználó</th>
                                <th>Összes óra</th>
                                <th>Fizetés</th>
                                <th>Aktív előleg</th>
                                <th>Egyenleg</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($user_stats as $username => $stats): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($username); ?></td>
                                <td><?php echo number_format($stats['hours'], 1, ',', ' '); ?> óra</td>
                                <td><?php echo number_format($stats['earned'], 0, ',', ' '); ?> Ft</td>
                                <td>
                                    <?php if ($stats['advance'] > 0): ?>
                                        <span class="badge bg-warning">
                                            <?php echo number_format($stats['advance'], 0, ',', ' '); ?> Ft
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format($stats['earned'] - $stats['advance'], 0, ',', ' '); ?> Ft</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Részletes munkaidő lista -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Részletes munkaidő lista</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Felhasználó</th>
                                <th>Kezdés</th>
                                <th>Befejezés</th>
                                <th>Munkaórák</th>
                                <th>Órabér</th>
                                <th>Fizetés</th>
                                <th>Státusz</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($work_hours as $wh): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($wh['username']); ?></td>
                                <td><?php echo date('Y-m-d H:i', strtotime($wh['check_in'])); ?></td>
                                <td>
                                    <?php 
                                    echo $wh['check_out'] 
                                        ? date('Y-m-d H:i', strtotime($wh['check_out']))
                                        : '<span class="badge bg-success">Aktív</span>';
                                    ?>
                                </td>
                                <td><?php echo $wh['total_hours'] ? number_format($wh['total_hours'], 1, ',', ' ') : '-'; ?></td>
                                <td><?php echo number_format($wh['hourly_rate'], 0, ',', ' '); ?> Ft/óra</td>
                                <td>
                                    <?php
                                    if ($wh['total_hours']) {
                                        echo number_format($wh['total_hours'] * $wh['hourly_rate'], 0, ',', ' ') . ' Ft';
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if (!$wh['check_out']): ?>
                                        <span class="badge bg-success">Folyamatban</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary">Lezárva</span>
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