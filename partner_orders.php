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

// Partner adatainak lekérése
$partner_id = $_GET['partner_id'] ?? null;
if (!$partner_id) {
    header('Location: partners.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM partners WHERE id = ?");
$stmt->execute([$partner_id]);
$partner = $stmt->fetch();

if (!$partner) {
    header('Location: partners.php');
    exit;
}

// Új rendelés felvétele
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_order'])) {
    try {
        $pdo->beginTransaction();

        // Rendelés fejléc mentése
        $stmt = $pdo->prepare("
            INSERT INTO partner_orders (partner_id, delivery_date, status)
            VALUES (?, ?, 'pending')
        ");
        $stmt->execute([$partner_id, $_POST['delivery_date']]);
        $order_id = $pdo->lastInsertId();

        // Rendelés tételek mentése
        $stmt = $pdo->prepare("
            INSERT INTO partner_order_items (partner_order_id, product_id, quantity)
            VALUES (?, ?, ?)
        ");

        foreach ($_POST['quantities'] as $product_id => $quantity) {
            if ($quantity > 0) {
                $stmt->execute([$order_id, $product_id, $quantity]);
            }
        }

        $pdo->commit();
        $_SESSION['success'] = 'Rendelés sikeresen rögzítve!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Hiba történt a rendelés rögzítésekor!';
    }
    header('Location: partner_orders.php?partner_id=' . $partner_id);
    exit;
}

// Rendelés státuszának módosítása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    try {
        $stmt = $pdo->prepare("
            UPDATE partner_orders 
            SET status = ? 
            WHERE id = ? AND partner_id = ?
        ");
        $stmt->execute([
            $_POST['status'],
            $_POST['order_id'],
            $partner_id
        ]);
        $_SESSION['success'] = 'Rendelés státusza frissítve!';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Hiba történt a státusz módosításakor!';
    }
    header('Location: partner_orders.php?partner_id=' . $partner_id);
    exit;
}

// Termékek lekérése
$products = $pdo->query("SELECT * FROM products WHERE active = 1 ORDER BY name")->fetchAll();

// Partner rendeléseinek lekérése
$stmt = $pdo->prepare("
    SELECT 
        po.*,
        GROUP_CONCAT(
            CONCAT(p.name, ': ', poi.quantity, ' db')
            SEPARATOR '\n'
        ) as items
    FROM partner_orders po
    LEFT JOIN partner_order_items poi ON po.id = poi.partner_order_id
    LEFT JOIN products p ON poi.product_id = p.id
    WHERE po.partner_id = ?
    GROUP BY po.id
    ORDER BY po.delivery_date DESC
");
$stmt->execute([$partner_id]);
$orders = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Rendelések - <?php echo htmlspecialchars($partner['company_name']); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/admin.css" rel="stylesheet">
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0"><?php echo htmlspecialchars($partner['company_name']); ?></h1>
                    <p class="mb-0 opacity-75">Rendelések kezelése</p>
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
    </header>

    <div class="container-fluid">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Partner adatok -->
            <div class="col-md-4 mb-4">
                <div class="content-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Partner adatok</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-1">
                            <strong>Adószám:</strong><br>
                            <?php echo htmlspecialchars($partner['tax_number']); ?>
                        </p>
                        <p class="mb-1">
                            <strong>Cím:</strong><br>
                            <?php echo htmlspecialchars($partner['zip_code'] . ' ' . $partner['city']); ?><br>
                            <?php echo htmlspecialchars($partner['address']); ?>
                        </p>
                        <p class="mb-1">
                            <strong>Kapcsolattartó:</strong><br>
                            <?php echo htmlspecialchars($partner['contact_name']); ?>
                        </p>
                        <p class="mb-1">
                            <strong>Telefonszám:</strong><br>
                            <?php echo htmlspecialchars($partner['phone']); ?>
                        </p>
                        <p class="mb-1">
                            <strong>Email:</strong><br>
                            <?php echo htmlspecialchars($partner['email']); ?>
                        </p>
                    </div>
                </div>

                <!-- Új rendelés form -->
                <div class="content-card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Új rendelés</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Kiszállítás dátuma</label>
                                <input type="date" name="delivery_date" class="form-control" required
                                       min="<?php echo date('Y-m-d'); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Termékek</label>
                                <?php foreach ($products as $product): ?>
                                <div class="input-group mb-2">
                                    <span class="input-group-text"><?php echo htmlspecialchars($product['name']); ?></span>
                                    <input type="number" name="quantities[<?php echo $product['id']; ?>]" 
                                           class="form-control" value="0" min="0">
                                    <span class="input-group-text">db</span>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="submit" name="add_order" class="btn btn-primary w-100">
                                <i class="fas fa-save me-2"></i>Rendelés mentése
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Rendelések listája -->
            <div class="col-md-8 mb-4">
                <div class="content-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Rendelések</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-custom table-hover">
                                <thead>
                                    <tr>
                                        <th>Dátum</th>
                                        <th>Termékek</th>
                                        <th>Státusz</th>
                                        <th>Műveletek</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d', strtotime($order['delivery_date'])); ?></td>
                                        <td style="white-space: pre-line;">
                                            <?php echo htmlspecialchars($order['items']); ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status_badges = [
                                                'pending' => 'bg-warning',
                                                'confirmed' => 'bg-info',
                                                'delivered' => 'bg-success',
                                                'cancelled' => 'bg-danger'
                                            ];
                                            $status_labels = [
                                                'pending' => 'Függőben',
                                                'confirmed' => 'Visszaigazolva',
                                                'delivered' => 'Kiszállítva',
                                                'cancelled' => 'Törölve'
                                            ];
                                            ?>
                                            <span class="badge <?php echo $status_badges[$order['status']]; ?>">
                                                <?php echo $status_labels[$order['status']]; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-primary dropdown-toggle" 
                                                        data-bs-toggle="dropdown">
                                                    Státusz módosítása
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <?php foreach ($status_labels as $status => $label): ?>
                                                    <li>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                            <input type="hidden" name="status" value="<?php echo $status; ?>">
                                                            <button type="submit" name="update_status" class="dropdown-item">
                                                                <?php echo $label; ?>
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>