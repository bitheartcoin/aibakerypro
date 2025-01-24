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

// Form feldolgozása
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_driver'])) {
        $name = $_POST['name'];
        $code = $_POST['code'];
        $status = $_POST['status'];

        $stmt = $pdo->prepare("INSERT INTO drivers (name, code, status) VALUES (?, ?, ?)");
        if ($stmt->execute([$name, $code, $status])) {
            $_SESSION['success'] = 'Sofőr sikeresen hozzáadva!';
        } else {
            $_SESSION['error'] = 'Hiba történt a sofőr hozzáadásakor!';
        }
        header('Location: drivers.php');
        exit;
    }
    
    if (isset($_POST['update_driver'])) {
        $driver_id = $_POST['driver_id'];
        $name = $_POST['name'];
        $status = $_POST['status'];
        $active = isset($_POST['active']) ? 1 : 0;

        $stmt = $pdo->prepare("UPDATE drivers SET name = ?, status = ?, active = ? WHERE id = ?");
        if ($stmt->execute([$name, $status, $active, $driver_id])) {
            $_SESSION['success'] = 'Sofőr sikeresen frissítve!';
        } else {
            $_SESSION['error'] = 'Hiba történt a sofőr frissítésekor!';
        }
        header('Location: drivers.php');
        exit;
    }

    // Sofőr törlés kezelése
    if (isset($_POST['delete_driver'])) {
        $driver_id = $_POST['driver_id'];
        
        $pdo->beginTransaction();
        try {
            // Először ellenőrizzük, van-e aktív rendelése a sofőrnek
            $stmt = $pdo->prepare("SELECT COUNT(*) as active_orders FROM orders WHERE driver_id = ? AND status != 'completed'");
            $stmt->execute([$driver_id]);
            $active_orders = $stmt->fetch()['active_orders'];
            
            if ($active_orders > 0) {
                $_SESSION['error'] = 'A sofőrnek van aktív, le nem zárt rendelése!';
                $pdo->rollBack();
            } else {
                // Töröljük a sofőrhöz kapcsolódó összes hivatkozást
                $stmt = $pdo->prepare("DELETE FROM drivers WHERE id = ?");
                $stmt->execute([$driver_id]);
                
                $pdo->commit();
                $_SESSION['success'] = 'Sofőr sikeresen törölve!';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = 'Hiba történt a sofőr törlésekor: ' . $e->getMessage();
        }
        
        header('Location: drivers.php');
        exit;
    }
}

// Sofőrök listázása
$stmt = $pdo->query("SELECT * FROM drivers ORDER BY status DESC, name");
$drivers = $stmt->fetchAll();

// Kiszállítások statisztikája sofőrönként
$delivery_stats = [];
$stmt = $pdo->query("
    SELECT 
        d.driver_code,
        dr.name,
        COUNT(DISTINCT DATE(d.delivery_date)) as delivery_days,
        COUNT(*) as total_deliveries,
        SUM(d.quantity) as total_quantity
    FROM deliveries d
    JOIN drivers dr ON d.driver_code = dr.code
    GROUP BY d.driver_code, dr.name
");
$delivery_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Részletes kiszállítási napló
$detailed_delivery_stats = [];
$stmt = $pdo->query("
    SELECT 
        dr.id AS driver_id,
        dr.name AS driver_name,
        o.order_number,
        o.delivery_date,
        s.name AS shop_name,
        SUM(oi.quantity) AS total_quantity,
        COUNT(DISTINCT p.id) AS unique_products
    FROM orders o
    JOIN drivers dr ON o.driver_id = dr.id
    JOIN shops s ON o.shop_id = s.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    GROUP BY dr.id, dr.name, o.order_number, o.delivery_date, s.name
    ORDER BY o.delivery_date DESC
");
$detailed_delivery_stats = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sofőrök Kezelése - Admin</title>
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

        .table-custom th {
            background: var(--primary-color);
            color: white;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">Sofőrök kezelése</p>
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
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                echo htmlspecialchars($_SESSION['success']);
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                echo htmlspecialchars($_SESSION['error']);
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Sofőrök listája -->
        <div class="content-card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center p-4">
                <h5 class="mb-0">Sofőrök kezelése</h5>
                <button type="button" class="btn btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#addDriverModal">
                    <i class="fas fa-plus me-2"></i>Új sofőr
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-custom table-hover">
                        <thead>
                            <tr>
                                <th>Kód</th>
                                <th>Név</th>
                                <th>Státusz</th>
                                <th>Állapot</th>
                                <th>Műveletek</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($drivers as $driver): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($driver['code']); ?></td>
                                <td><?php echo htmlspecialchars($driver['name']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $driver['status'] == 'állandó' ? 'primary' : 'secondary'; ?>">
                                        <?php echo $driver['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $driver['active'] ? 'success' : 'danger'; ?>">
                                        <?php echo $driver['active'] ? 'Aktív' : 'Inaktív'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-warning btn-action" 
                                                onclick="editDriver(<?php echo htmlspecialchars(json_encode($driver)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger btn-action" 
                                                onclick="deleteDriver(<?php echo $driver['id']; ?>, '<?php echo htmlspecialchars($driver['name']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Kiszállítási statisztikák -->
        <div class="content-card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Kiszállítási statisztikák</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-custom table-hover">
                        <thead>
                            <tr>
                                <th>Sofőr</th>
                                <th>Kiszállítási napok
                                <th>Összes kiszállítás</th>
                            <th>Összes mennyiség</th>
                        </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($delivery_stats as $stat): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($stat['name']); ?></td>
                                <td><?php echo $stat['delivery_days']; ?> nap</td>
                                <td><?php echo $stat['total_deliveries']; ?> db</td>
                                <td><?php echo number_format($stat['total_quantity'], 0, ',', ' '); ?> db</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Részletes kiszállítási napló -->
        <div class="content-card">
            <div class="card-header">
                <h5 class="mb-0">Részletes kiszállítási napló</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-custom table-hover">
                        <thead>
                            <tr>
                                <th>Sofőr</th>
                                <th>Rendelésszám</th>
                                <th>Kiszállítás dátuma</th>
                                <th>Üzlet</th>
                                <th>Termékfajták</th>
                                <th>Mennyiség</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detailed_delivery_stats as $stat): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($stat['driver_name']); ?></td>
                                <td><?php echo htmlspecialchars($stat['order_number']); ?></td>
                                <td><?php echo date('Y.m.d', strtotime($stat['delivery_date'])); ?></td>
                                <td><?php echo htmlspecialchars($stat['shop_name']); ?></td>
                                <td><?php echo $stat['unique_products']; ?> típus</td>
                                <td><?php echo number_format($stat['total_quantity'], 0, ',', ' '); ?> db</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Új sofőr modal -->
    <div class="modal fade" id="addDriverModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Új sofőr hozzáadása</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Név</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kód (3 számjegy)</label>
                            <input type="text" name="code" class="form-control" required 
                                   pattern="[0-9]{3}" maxlength="3">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Státusz</label>
                            <select name="status" class="form-select" required>
                                <option value="alkalmi">Alkalmi</option>
                                <option value="állandó">Állandó</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
                        <button type="submit" name="add_driver" class="btn btn-primary">Hozzáadás</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Sofőr szerkesztése modal -->
    <div class="modal fade" id="editDriverModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Sofőr szerkesztése</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="driver_id" id="edit_driver_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Név</label>
                            <input type="text" name="name" id="edit_driver_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kód</label>
                            <input type="text" id="edit_driver_code" class="form-control" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Státusz</label>
                            <select name="status" id="edit_driver_status" class="form-select" required>
                                <option value="alkalmi">Alkalmi</option>
                                <option value="állandó">Állandó</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="active" id="edit_driver_active" class="form-check-input">
                                <label class="form-check-label">Aktív sofőr</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
                        <button type="submit" name="update_driver" class="btn btn-warning">Mentés</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function editDriver(driver) {
        document.getElementById('edit_driver_id').value = driver.id;
        document.getElementById('edit_driver_name').value = driver.name;
        document.getElementById('edit_driver_code').value = driver.code;
        document.getElementById('edit_driver_status').value = driver.status;
        document.getElementById('edit_driver_active').checked = driver.active == 1;
        new bootstrap.Modal(document.getElementById('editDriverModal')).show();
    }

    function deleteDriver(driverId, driverName) {
        if (confirm('Biztosan törölni szeretné a(z) ' + driverName + ' nevű sofőrt? Ez a művelet nem vonható vissza!')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const driverInput = document.createElement('input');
            driverInput.type = 'hidden';
            driverInput.name = 'driver_id';
            driverInput.value = driverId;
            form.appendChild(driverInput);
            
            const deleteInput = document.createElement('input');
            deleteInput.type = 'hidden';
            deleteInput.name = 'delete_driver';
            deleteInput.value = '1';
            form.appendChild(deleteInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Sikeres üzenet automatikus eltüntetése
    document.addEventListener('DOMContentLoaded', function() {
        const alertSuccess = document.querySelector('.alert-success');
        if (alertSuccess) {
            setTimeout(function() {
                const closeButton = alertSuccess.querySelector('.btn-close');
                if (closeButton) {
                    closeButton.click();
                }
            }, 3000);
        }
    });
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>