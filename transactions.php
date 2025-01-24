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
$shop_id = $_GET['shop_id'] ?? null;
$type = $_GET['type'] ?? null;
$payment_type = $_GET['payment_type'] ?? null;
$category = $_GET['category'] ?? null;

// Alap lekérdezés
$query = "SELECT t.*, s.name as shop_name 
          FROM transactions t 
          JOIN shops s ON t.shop_id = s.id 
          WHERE DATE(t.created_at) BETWEEN ? AND ?";
$params = [$start_date, $end_date];

// Szűrések hozzáadása
if ($shop_id) {
    $query .= " AND t.shop_id = ?";
    $params[] = $shop_id;
}

if ($type) {
    $query .= " AND t.type = ?";
    $params[] = $type;
}

if ($category) {
    $query .= " AND t.category = ?";
    $params[] = $category;
}

if ($payment_type) {
    $query .= " AND t.payment_type = ?";
    $params[] = $payment_type;
}

$query .= " ORDER BY t.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Összesítés számítása
$total_income = 0;
$total_expense = 0;
$total_by_payment = [
    'kassza1' => 0,
    'kassza2' => 0,
    'kartyasFizetes' => 0
];

foreach ($transactions as $transaction) {
    if ($transaction['type'] == 'income') {
        $total_income += $transaction['amount'];
        if ($transaction['payment_type']) {
            $total_by_payment[$transaction['payment_type']] += $transaction['amount'];
        }
    } else {
        $total_expense += $transaction['amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Felület - Pékség Adminisztráció</title>
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
            display: flex;
            flex-direction: column;
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
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }

        .stat-header {
            padding: 1.5rem;
            border-radius: 15px 15px 0 0;
            position: relative;
            overflow: hidden;
        }

        .stat-header.income {
            background: linear-gradient(135deg, var(--success-color), #219a52);
            color: white;
        }

        .stat-header.expense {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            color: white;
        }

        .stat-header.balance {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .stat-header i {
            position: absolute;
            right: -10px;
            top: -10px;
            font-size: 5rem;
            opacity: 0.1;
            transform: rotate(15deg);
        }

        .stat-body {
            padding: 1.5rem;
        }

        .content-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .filter-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            padding: 1.5rem;
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

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-fade-in {
            animation: fadeIn 0.5s ease-out forwards;
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
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">Tranzakciók kezelése</p>
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
        <li><a class="dropdown-item" href="/admin/schedules.php"><i class="fas fa-calendar-alt me-2"></i>Munkabeosztás</a></li>
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
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show animate-fade-in" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php 
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statisztika kártyák -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="stat-card animate-fade-in">
                    <div class="stat-header income">
                        <i class="fas fa-arrow-up"></i>
                        <h4 class="mb-0">Összes bevétel</h4>
                    </div>
                    <div class="stat-body">
                        <h3 class="mb-4"><?php echo number_format($total_income, 0, ',', ' '); ?> Ft</h3>
                        <div class="row g-2">
                            <div class="col-4">
                                <div class="text-muted">Kassza 1</div>
                                <div class="fw-bold"><?php echo number_format($total_by_payment['kassza1'], 0, ',', ' '); ?> Ft</div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted">Kassza 2</div>
                                <div class="fw-bold"><?php echo number_format($total_by_payment['kassza2'], 0, ',', ' '); ?> Ft</div>
                            </div>
                            <div class="col-4">
                                <div class="text-muted">Kártyás</div>
                                <div class="fw-bold"><?php echo number_format($total_by_payment['kartyasFizetes'], 0, ',', ' '); ?> Ft</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card animate-fade-in">
                    <div class="stat-header expense">
                        <i class="fas fa-arrow-down"></i>
                        <h4 class="mb-0">Összes kiadás</h4>
                    </div>
                    <div class="stat-body">
                        <h3 class="mb-4"><?php echo number_format($total_expense, 0, ',', ' '); ?> Ft</h3>
                        <div class="progress" style="height: 10px;">
                            <?php
                            $expense_ratio = $total_income > 0 ? ($total_expense / $total_income) * 100 : 0;
                            ?>
                            <div class="progress-bar bg-danger" style="width: <?php echo $expense_ratio; ?>%"></div>
                        </div>
                        <div class="text-muted mt-2">
                            A bevétel <?php echo number_format($expense_ratio, 1); ?>%-a
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card animate-fade-in">
                    <div class="stat-header balance">
                        <i class="fas fa-balance-scale"></i>
                        <h4 class="mb-0">Egyenleg</h4>
                    </div>
                    <div class="stat-body">
                        <h3 class="mb-4"><?php echo number_format($total_income - $total_expense, 0, ',', ' '); ?> Ft</h3>
                        <div class="d-flex justify-content-between text-muted">
                            <div>Bevétel aránya:</div>
                            <div class="fw-bold"><?php echo $total_expense > 0 ? number_format($total_income / $total_expense, 1) : '∞'; ?>x</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Szűrő form -->
        <div class="filter-card animate-fade-in">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Kezdő dátum</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Végző dátum</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Üzlet</label>
                    <select name="shop_id" class="form-select">
                        <option value="">Összes</option>
                        <?php
                        $shops = $pdo->query("SELECT * FROM shops ORDER BY name")->fetchAll();
                        foreach ($shops as $shop) {
                            $selected = $shop['id'] == $shop_id ? 'selected' : '';
                            echo "<option value='{$shop['id']}' {$selected}>{$shop['name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Típus</label>
                    <select name="type" class="form-select">
                        <option value="">Összes típus</option>
                        <option value="income" <?php echo $type == 'income' ? 'selected' : ''; ?>>Bevétel</option>
                        <option value="expense" <?php echo $type == 'expense' ? 'selected' : ''; ?>>Kiadás</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Fizetési mód</label>
                    <select name="payment_type" class="form-select">
                        <option value="">Összes fizetési mód</option>
                        <option value="kassza1" <?php echo $payment_type == 'kassza1' ? 'selected' : ''; ?>>Kassza 1</option>
                        <option value="kassza2" <?php echo $payment_type == 'kassza2' ? 'selected' : ''; ?>>Kassza 2</option>
                        <option value="kartyasFizetes" <?php echo $payment_type == 'kartyasFizetes' ? 'selected' : ''; ?>>Kártyás fizetés</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Kategória</label>
                    <select name="category" class="form-select">
                        <option value="">Összes kategória</option>
                        <option value="tankolas" <?php echo $category == 'tankolas' ? 'selected' : ''; ?>>Tankolás</option>
                        <option value="csomagoloanyag" <?php echo $category == 'csomagoloanyag' ? 'selected' : ''; ?>>Csomagolóanyag</option>
                        <option value="fizeteseloleg" <?php echo $category == 'fizeteseloleg' ? 'selected' : ''; ?>>Fizetéselőleg</option>
                        <option value="tisztitoszerek" <?php echo $category == 'tisztitoszerek' ? 'selected' : ''; ?>>Tisztítószerek</option>
                        <option value="egyeb" <?php echo $category == 'egyeb' ? 'selected' : ''; ?>>Egyéb</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary mt-4">
                        <i class="fas fa-filter me-2"></i>Szűrés
                    </button>
                </div>
            </form>
        </div>

        <!-- Tranzakciók lista -->
        <div class="content-card animate-fade-in">
            <div class="table-responsive">
                <table class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Dátum</th>
                            <th>Üzlet</th>
                            <th>Típus</th>
                            <th>Fizetési mód</th>
                            <th>Összeg</th>
                            <th>Kategória</th>
                            <th>Leírás</th>
                            <th>Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?php echo date('Y-m-d H:i', strtotime($transaction['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($transaction['shop_name']); ?></td>
                            <td>
                                <?php if ($transaction['type'] == 'income'): ?>
                                    <span class="badge bg-success">Bevétel</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Kiadás</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                if ($transaction['type'] == 'income') {
                                    $payment_labels = [
                                        'kassza1' => 'Kassza 1',
                                        'kassza2' => 'Kassza 2',
                                        'kartyasFizetes' => 'Kártyás fizetés'
                                    ];
                                    echo htmlspecialchars($payment_labels[$transaction['payment_type']] ?? '-');
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td><?php echo number_format($transaction['amount'], 0, ',', ' '); ?> Ft</td>
                            <td><?php echo htmlspecialchars($transaction['category'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($transaction['description'] ?? '-'); ?></td>
                            <td>
                                <a href="edit.php?id=<?php echo $transaction['id']; ?>" 
                                   class="btn btn-sm btn-warning btn-action">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="delete.php?id=<?php echo $transaction['id']; ?>" 
                                   class="btn btn-sm btn-danger btn-action" 
                                   onclick="return confirm('Biztosan törli ezt a tranzakciót?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>