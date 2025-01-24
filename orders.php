<?php
// Hiba megjelenítés és logolás bekapcsolása
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config.php';
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

session_start();

// Jogosultság ellenőrzés
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
$shop_id = $_GET['shop_id'] ?? '';
$partner_id = $_GET['partner_id'] ?? '';
$driver_id = $_GET['driver_id'] ?? '';

// Üzletek lekérése
$shops = $pdo->query("SELECT * FROM shops ORDER BY name")->fetchAll();

// Partnerek lekérése
$partners = $pdo->query("SELECT * FROM partners ORDER BY company_name")->fetchAll();

// Sofőrök lekérése
$drivers = $pdo->query("SELECT * FROM drivers ORDER BY name")->fetchAll();

// Excel import feldolgozása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $transaction_started = false;
    try {
        // Debug információk
        error_log("Import started");
        error_log("File details: " . print_r($_FILES['import_file'], true));
        
        $file = $_FILES['import_file'];
        $shop_id = $_POST['shop_id'];
        $driver_id = $_POST['driver_id']; // Sofőr kiválasztása
        
        // Dátum kinyerése a fájl nevéből
        $fileName = pathinfo($file['name'], PATHINFO_FILENAME);
        error_log("Filename: " . $fileName);
        
        $dateParts = explode('.', $fileName);
        error_log("Date parts: " . print_r($dateParts, true));
        
        if (count($dateParts) !== 4) {
            throw new Exception('Hibás fájlnév formátum! A helyes formátum: ÉÉÉÉ.HH.NN.NAPNÉV.xlsx');
        }
        
        $delivery_date = $dateParts[0] . '-' . $dateParts[1] . '-' . $dateParts[2];
        error_log("Delivery date: " . $delivery_date);
        
        // Excel beolvasása
        $inputFileName = $file['tmp_name'];
        error_log("Reading Excel file from: " . $inputFileName);
        
        $spreadsheet = IOFactory::load($inputFileName);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Tranzakció kezdése
        $pdo->beginTransaction();
        $transaction_started = true;
        error_log("Transaction started");
        
        // Új rendelés létrehozása
        $order_number = 'ORD-' . date('Ymd') . '-' . uniqid();
        $stmt = $pdo->prepare("
            INSERT INTO orders (
                shop_id, 
                order_number, 
                delivery_date, 
                status, 
                created_by, 
                driver_id
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $shop_id, 
            $order_number, 
            $delivery_date, 
            'pending', 
            $_SESSION['user_id'], 
            $driver_id
        ]);
        $order_id = $pdo->lastInsertId();
        
        // Terméknevek beolvasása a B1-től
        $col = 'B';
        $products = [];
        while ($worksheet->getCell($col . "1")->getValue() != "") {
            $productName = $worksheet->getCell($col . "1")->getValue();
            error_log("Found product: " . $productName . " in column " . $col);
            $products[$col] = $productName;
            $col++;
        }
        
        error_log("Products found: " . print_r($products, true));
        
        // Mennyiségek beolvasása B2-től és order_items létrehozása
        foreach ($products as $column => $productName) {
            $quantity = (int)$worksheet->getCell($column . "2")->getValue();
            error_log("Quantity for $productName: $quantity");
            
            if ($quantity > 0) {
                $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? AND active = 1");
                $stmt->execute([$productName]);
                $product = $stmt->fetch();
                
                if ($product) {
                    // Order item létrehozása
                    $stmt = $pdo->prepare("
                        INSERT INTO order_items (
                            order_id, 
                            product_id, 
                            quantity
                        ) VALUES (?, ?, ?)
                    ");
                    $stmt->execute([
                        $order_id, 
                        $product['id'], 
                        $quantity
                    ]);
                    
                    // Meglévő deliveries rögzítése
                    $stmt = $pdo->prepare("
                        INSERT INTO deliveries (
                            shop_id, 
                            product_id, 
                            quantity, 
                            delivery_date,
                            driver_id
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $shop_id, 
                        $product['id'], 
                        $quantity, 
                        $delivery_date,
                        $driver_id
                    ]);
                } else {
                    error_log("Product not found: $productName");
                }
            }
        }
        
        $pdo->commit();
        $transaction_started = false;
        error_log("Transaction committed successfully");
        $_SESSION['success'] = 'Rendelés sikeresen importálva!';
        
    } catch (Exception $e) {
        if ($transaction_started) {
            $pdo->rollBack();
        }
        error_log("Error during import: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $_SESSION['error'] = 'Hiba történt az importálás során: ' . $e->getMessage();
    }
    
    header('Location: orders.php');
    exit;
}

// Rendelések lekérése (bolt és partner)
$query = "
    SELECT 
        'shop' AS order_type,
        o.id,
        o.order_number,
        o.delivery_date,
        o.status,
        s.name AS partner_name,
        d.name AS driver_name,
        SUM(oi.quantity) AS total_items
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    LEFT JOIN drivers d ON o.driver_id = d.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE o.delivery_date BETWEEN ? AND ?";

$params = [$start_date, $end_date];

if ($shop_id) {
    $query .= " AND o.shop_id = ?";
    $params[] = $shop_id;
}

if ($driver_id) {
    $query .= " AND o.driver_id = ?";
    $params[] = $driver_id;
}

$query .= " 
    GROUP BY o.id, o.order_number, o.delivery_date, o.status, s.name, d.name

    UNION ALL

    SELECT
        'partner' AS order_type,  
        po.id,
        po.id AS order_number,
        po.delivery_date,
        po.status,
        p.company_name AS partner_name,
        NULL AS driver_name,
        (SELECT SUM(poi.quantity) 
         FROM partner_order_items poi
         WHERE poi.partner_order_id = po.id) AS total_items
    FROM partner_orders po
    JOIN partners p ON po.partner_id = p.id
    WHERE po.delivery_date BETWEEN ? AND ?";

// Paraméterek hozzáadása a partner rendelésekhez is
$params[] = $start_date;
$params[] = $end_date;

if ($partner_id) {
    $query .= " AND po.partner_id = ?";
    $params[] = $partner_id;
}

$query .= " ORDER BY delivery_date DESC, partner_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Külön lekérdezés a rendelési tételekhez (bolt és partner)
function getOrderItems($pdo, $order_id, $order_type) {
    if ($order_type == 'shop') {
        $stmt = $pdo->prepare("
            SELECT 
                p.name AS product_name, 
                oi.quantity 
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                p.name AS product_name,
                poi.quantity
            FROM partner_order_items poi  
            JOIN products p ON poi.product_id = p.id
            WHERE poi.partner_order_id = ?
        ");
    }
    
    $stmt->execute([$order_id]);
    return $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rendelések Kezelése - Admin</title>
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

        .card-header {
            background: var(--primary-color);
            color: white;
            padding: 1.5rem;
        }

        .order-details {
            display: none;
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            margin-top: 1rem;
        }
        
        .order-details table {
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
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
                            <li><a class="dropdown-item" href="partners.php"><i class="fas fa-handshake me-2"></i>Partnerek</a></li>
                            <li><a class="dropdown-item active" href="orders.php"><i class="fas fa-shopping-cart me-2"></i>Rendelések</a></li>
                            <li><a class="dropdown-item" href="ai_forecast.php"><i class="fas fa-robot me-2"></i>AI Előrejelzés</a></li>
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
        <!-- Import form -->
        <div class="content-card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-file-import me-2"></i>Excel Importálás</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Üzlet kiválasztása</label>
                        <select name="shop_id" class="form-select" required>
                            <option value="">Válasszon üzletet...</option>
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?php echo $shop['id']; ?>">
                                    <?php echo htmlspecialchars($shop['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Sofőr kiválasztása</label>
                        <select name="driver_id" class="form-select" required>
                            <option value="">Válasszon sofőrt...</option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?php echo $driver['id']; ?>">
                                    <?php echo htmlspecialchars($driver['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Excel fájl</label>
                        <input type="file" name="import_file" class="form-control" accept=".xlsx,.xls" required>
                        <div class="form-text">
                            Fájlnév formátum: ÉÉÉÉ.HH.NN.NAPNÉV.xlsx<br>
Például: 2023.12.20.Szerda.xlsx
</div>
</div>
<div class="col-12">
<button type="submit" class="btn btn-primary">
<i class="fas fa-upload me-2"></i>Importálás
</button>
</div>
</form>
</div>
</div>
<!-- Szűrés -->
<div class="content-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Szűrés</h5>
        </div>
        <div class="card-body p-4">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Kezdő dátum</label>
                    <input type="date" name="start_date" class="form-control" 
                           value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Végső dátum</label>
                    <input type="date" name="end_date" class="form-control" 
                           value="<?php echo $end_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Üzlet</label>
                    <select name="shop_id" class="form-select">
                        <option value="">Összes üzlet</option>
                        <?php foreach ($shops as $shop): ?>
                            <option value="<?php echo $shop['id']; ?>"
                                    <?php echo $shop_id == $shop['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($shop['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Partner</label>
                    <select name="partner_id" class="form-select">
                        <option value="">Összes partner</option>
                        <?php foreach ($partners as $partner): ?>
                            <option value="<?php echo $partner['id']; ?>"
                                    <?php echo $partner_id == $partner['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($partner['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sofőr</label>
                    <select name="driver_id" class="form-select">
                        <option value="">Összes sofőr</option>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?php echo $driver['id']; ?>"
                                    <?php echo $driver_id == $driver['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($driver['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>Szűrés
                    </button>
                    <?php if (!empty($_GET)): ?>
                    <a href="orders.php" class="btn btn-secondary">
                        <i class="fas fa-undo me-2"></i>Szűrés törlése
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Rendelések listája -->
    <div class="content-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Rendelések listája</h5>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Rendelésszám</th>
                            <th>Dátum</th>
                            <th>Partner/Üzlet</th>
                            <th>Státusz</th>
                            <th>Sofőr</th>
                            <th>Részletek</th>
                            <th>Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($orders)): ?>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                <td><?php echo date('Y.m.d', strtotime($order['delivery_date'])); ?></td>
                                <td><?php echo htmlspecialchars($order['partner_name']); ?></td>
                                <td>
                                    <?php 
                                    $statuszok = [
                                        'pending' => '<span class="badge bg-warning">Függőben</span>',
                                        'confirmed' => '<span class="badge bg-primary">Visszaigazolva</span>',
                                        'processing' => '<span class="badge bg-info">Feldolgozás alatt</span>',
                                        'delivered' => '<span class="badge bg-success">Kiszállítva</span>',
                                        'completed' => '<span class="badge bg-success">Teljesítve</span>',
                                        'cancelled' => '<span class="badge bg-danger">Törölve</span>'
                                    ];
                                    echo $statuszok[$order['status']];
                                    ?>
                                </td>
                                <td>
                                    <?php if ($order['driver_name']): ?>
                                        <?php echo htmlspecialchars($order['driver_name']); ?>
                                    <?php else: ?>
                                        <form method="POST">
                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                            <input type="hidden" name="order_type" value="<?php echo $order['order_type']; ?>">
                                            <select name="driver_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <option value="">Válasszon sofőrt</option>
                                                <?php foreach ($drivers as $driver): ?>
                                                    <option value="<?php echo $driver['id']; ?>">
                                                        <?php echo htmlspecialchars($driver['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="toggleDetails(<?php echo $order['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="#" class="btn btn-sm btn-primary">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <tr class="order-details" id="details-<?php echo $order['id']; ?>">
                                <td colspan="8">
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Termék</th>
                                                <th>Mennyiség</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $orderItems = getOrderItems($pdo, $order['id'], $order['order_type']);
                                            foreach ($orderItems as $item): 
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                <td><?php echo $item['quantity']; ?> db</td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fas fa-info-circle me-2"></i>
                                    A szűrési feltételeknek megfelelő rendelés nem található.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function toggleDetails(orderId) {
    const detailsRow = document.getElementById('details-' + orderId);
    detailsRow.style.display = detailsRow.style.display === 'none' ? 'table-row' : 'none';
}
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>