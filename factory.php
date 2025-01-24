<?php
require_once 'config.php';
session_start();

// Bejelentkezés ellenőrzése
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Felhasználó lekérdezése
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND (user_type = 'driver' OR role = 'admin')");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    // Ha nem sofőr és nem admin
    $_SESSION['error'] = 'Nincs jogosultsága az oldal megtekintéséhez.';
    header('Location: index.php');
    exit;
}

// Hibakezelés és naplózás hozzáadása
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Mai napi rendelések lekérése
$today = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT 
        o.id, 
        o.order_number, 
        o.delivery_date, 
        o.status, 
        s.name as shop_name,
        COUNT(oi.id) as total_products,
        SUM(oi.quantity) as total_quantity
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    JOIN order_items oi ON o.id = oi.order_id
    WHERE o.delivery_date = ? 
    AND o.driver_id = ?
    GROUP BY o.id, o.order_number, o.delivery_date, o.status, s.name
    ORDER BY o.delivery_date
");
$stmt->execute([$today, $user['id']]);
$orders = $stmt->fetchAll();

// Hibakezelés hozzáadása a lekérdezéshez
if ($stmt->errorCode() !== '00000') {
    $errorInfo = $stmt->errorInfo();
    error_log("Adatbázis hiba: " . print_r($errorInfo, true));
    $_SESSION['error'] = 'Hiba történt a rendelések lekérdezése során.';
}

// Rendelés részleteinek lekérése
function getOrderDetails($pdo, $order_id) {
    $stmt = $pdo->prepare("
        SELECT 
            p.name as product_name, 
            oi.quantity 
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    return $stmt->fetchAll();
}

// Státusz frissítés
if (isset($_POST['update_status'])) {
    $order_id = $_POST['order_id'];
    $new_status = $_POST['status'];
    
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $order_id]);
    
    $_SESSION['success'] = 'Rendelés státusza frissítve!';
    header('Location: factory.php');
    exit;
}

// Rendelés törlése
if (isset($_POST['delete_order'])) {
    $order_id = $_POST['order_id'];
    
    $pdo->beginTransaction();
    try {
        // Először töröljük a rendelés tételeket
        $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmt->execute([$order_id]);
        
        // Majd magát a rendelést
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        
        $pdo->commit();
        
        $_SESSION['success'] = 'Rendelés sikeresen törölve!';
        header('Location: factory.php');
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Hiba történt a rendelés törlésekor!';
        header('Location: factory.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiszállítás Kezelése - Pékség Adminisztráció</title>
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
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        .main-header {
            background: linear-gradient(135deg, var(--accent-color), #d35400);
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
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #dee2e6;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 0.2rem rgba(230, 126, 34, 0.25);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-color), #d35400);
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #d35400, var(--accent-color));
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .product-row {
            transition: all 0.3s ease;
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }

        .product-row:hover {
            background-color: #f8f9fa;
        }

        .quantity-input {
            max-width: 100px;
            margin: 0 auto;
        }

        .alert {
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }

        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }

        .table th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.85rem;
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 5px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #d35400;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">
                    <i class="fas fa-truck me-3"></i>
                    Kiszállítás Kezelése
                </h1>
                <a href="index.php" class="btn btn-light">
                    <i class="fas fa-home me-2"></i>
                    Vissza a főoldalra
                </a>
            </div>
        </div>
    </header>

    <div class="container flex-grow-1">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php 
                echo htmlspecialchars($_SESSION['success']);
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php 
                echo htmlspecialchars($_SESSION['error']);
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="content-card">
            <div class="card-body p-4">
                <h3 class="mb-4">
                    <i class="fas fa-calendar-day me-2"></i>
                    Mai kiszállítások (<?php echo date('Y.m.d'); ?>)
                </h3>

                <?php if (empty($orders)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-inbox me-2"></i>
                        Ma nincs kiszállítandó rendelés.
                    </div>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <div class="card mb-3 shadow-sm">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>Rendelésszám:</strong> 
                                    <?php echo htmlspecialchars($order['order_number']); ?>
                                    <span class="ms-2 badge 
                                        <?php 
                                        switch($order['status']) {
                                            case 'pending': echo 'bg-warning'; break;
                                            case 'processing': echo 'bg-info'; break;
                                            case 'completed': echo 'bg-success'; break;
                                            case 'cancelled': echo 'bg-danger'; break;
                                            default: echo 'bg-secondary';
                                        }
                                        ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'Függőben',
                                            'processing' => 'Folyamatban',
                                            'completed' => 'Teljesítve',
                                            'cancelled' => 'Törölve'
                                        ];
                                        echo $statuses[$order['status']]; 
                                        ?>
                                    </span>
                                </div>
                                <div>
                                    <strong>Üzlet:</strong> 
                                    <?php echo htmlspecialchars($order['shop_name']); ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Termékek:</h6>
                                        <ul class="list-group">
                                            <?php 
                                            $orderDetails = getOrderDetails($pdo, $order['id']);
                                            foreach($orderDetails as $detail): 
                                            ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                                    <?php echo htmlspecialchars($detail['product_name']); ?>
                                                    <span class="badge bg-primary rounded-pill">
                                                        <?php echo $detail['quantity']; ?> db
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Rendelés összesítő:</h6>
                                        <p>
                                            <strong>Termékfajták:</strong> <?php echo $order['total_products']; ?> típus<br>
                                            <strong>Teljes mennyiség:</strong> <?php echo $order['total_quantity']; ?> db
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer d-flex justify-content-between">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <input type="hidden" name="status" value="<?php 
                                        echo $order['status'] == 'pending' ? 'processing' : 
                                            ($order['status'] == 'processing' ? 'completed' : 'pending');
                                    ?>">
                                    <button type="submit" name="update_status" class="btn btn-sm 
                                        <?php 
                                        echo $order['status'] == 'pending' ? 'btn-warning' : 
                                            ($order['status'] == 'processing' ? 'btn-success' : 'btn-secondary');
                                        ?>">
                                        <i class="fas fa-sync me-1"></i>
                                        <?php 
                                        echo $order['status'] == 'pending' ? 'Folyamatba vétel' : 
                                            ($order['status'] == 'processing' ? 'Teljesítve' : 'Visszaállítás');
                                        ?>
                                    </button>
                                </form>

                                <form method="POST" onsubmit="return confirm('Biztosan törli a rendelést?');">
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <button type="submit" name="delete_order" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash me-1"></i>Rendelés törlése
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validáció
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()

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

            // Törlés megerősítés
            const deleteOrderForms = document.querySelectorAll('form[onsubmit]');
            deleteOrderForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Biztosan törli a rendelést? Ez a művelet nem vonható vissza.')) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>