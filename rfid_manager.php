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

// RFID hozzárendelés/módosítás
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_rfid'])) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET rfid_number = ? WHERE id = ?");
            $stmt->execute([
                $_POST['rfid_number'],
                $_POST['user_id']
            ]);
            $_SESSION['success'] = 'RFID kártya sikeresen hozzárendelve!';
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Duplicate entry error
                $_SESSION['error'] = 'Ez az RFID kártya már használatban van!';
            } else {
                $_SESSION['error'] = 'Hiba történt: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['remove_rfid'])) {
        $stmt = $pdo->prepare("UPDATE users SET rfid_number = NULL WHERE id = ?");
        $stmt->execute([$_POST['user_id']]);
        $_SESSION['success'] = 'RFID kártya sikeresen eltávolítva!';
    }
    
    header('Location: rfid_manager.php');
    exit;
}

// Felhasználók listázása RFID információkkal
$users = $pdo->query("
    SELECT id, username, rfid_number, user_type, active 
    FROM users 
    ORDER BY username
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RFID Kártyák Kezelése - Admin</title>
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

        .user-row {
            transition: all 0.3s ease;
        }

        .user-row:hover {
            background-color: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .rfid-badge {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-family: monospace;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .rfid-badge i {
            color: #43a047;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .modal-content {
            border-radius: 15px;
            border: none;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
        }

        .modal-footer {
            border-top: none;
        }

        .table th {
            background: var(--primary-color);
            color: white;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .type-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .type-badge.admin { background: #ffebee; color: #c62828; }
        .type-badge.driver { background: #e3f2fd; color: #1565c0; }
        .type-badge.seller { background: #e8f5e9; color: #2e7d32; }
        .type-badge.baker { background: #fff3e0; color: #ef6c00; }

        .rfid-scan-area {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            margin: 1rem 0;
            transition: all 0.3s ease;
        }

        .rfid-scan-area.scanning {
            border-color: var(--accent-color);
            background: #fff8f3;
        }

        .pulse-icon {
            font-size: 2rem;
            color: var(--accent-color);
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">RFID Kártyák Kezelése</p>
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

        <div class="content-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Felhasználó</th>
                                <th>Típus</th>
                                <th>RFID Azonosító</th>
                                <th>Státusz</th>
                                <th>Műveletek</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr class="user-row">
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td>
                                    <?php 
                                    $type_classes = [
                                        'admin' => 'admin',
                                        'driver' => 'driver',
                                        'seller' => 'seller',
                                        'baker' => 'baker'
                                    ];
                                    $type_names = [
                                        'admin' => 'Admin',
                                        'driver' => 'Sofőr',
                                        'seller' => 'Eladó',
                                        'baker' => 'Pék'
                                    ];
                                    ?>
                                    <span class="type-badge <?php echo $type_classes[$user['user_type']] ?? ''; ?>">
                                        <?php echo $type_names[$user['user_type']] ?? 'Ismeretlen'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['rfid_number']): ?>
                                        <span class="rfid-badge">
                                            <i class="fas fa-wifi"></i>
                                            <?php echo htmlspecialchars($user['rfid_number']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">
                                            <i class="fas fa-times-circle"></i> Nincs RFID kártya
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $user['active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $user['active'] ? 'Aktív' : 'Inaktív'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-primary btn-action" 
                                                onclick="assignRFID('<?php echo $user['id']; ?>', '<?php echo htmlspecialchars($user['username']); ?>')">
                                            <i class="fas fa-wifi me-1"></i>RFID Hozzárendelés
                                        </button>
                                        <?php if ($user['rfid_number']): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Biztosan törli az RFID kártyát?');">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" name="remove_rfid" class="btn btn-sm btn-danger btn-action">
                                                <i class="fas fa-unlink"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
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

    <!-- RFID Hozzárendelés Modal -->
    <div class="modal fade" id="rfidModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">RFID Kártya Hozzárendelése</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="rfidForm">
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="userId">
                        
                        <p class="mb-3">RFID kártya hozzárendelése ehhez a felhasználóhoz: 
                            <strong id="userName"></strong>
                        </p>

                        <div class="rfid-scan-area" id="scanArea">
                            <i class="fas fa-wifi pulse-icon mb-3"></i>
                            <h5>Érintse a kártyát az olvasóhoz</h5>
                            <p class="text-muted mb-0">A rendszer automatikusan felismeri az RFID kártyát</p>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">RFID Azonosító</label>
                            <input type="text" name="rfid_number" id="rfidNumber" class="form-control" required>
                            <div class="form-text">H10301 formátumú azonosító</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
                        <button type="submit" name="assign_rfid" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Mentés
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    let rfidBuffer = '';
    const rfidInput = document.getElementById('rfidNumber');
    const scanArea = document.getElementById('scanArea');

    function assignRFID(userId, userName) {
        document.getElementById('userId').value = userId;
        document.getElementById('userName').textContent = userName;
        document.getElementById('rfidNumber').value = '';
        scanArea.classList.remove('scanning');
        new bootstrap.Modal(document.getElementById('rfidModal')).show();
    }

    // RFID olvasó figyelése
    document.addEventListener('keypress', function(e) {
        if (document.getElementById('rfidModal').classList.contains('show')) {
            scanArea.classList.add('scanning');
            
            if (e.key === 'Enter') {
                if (rfidBuffer.length > 0) {
                    rfidInput.value = rfidBuffer;
                    scanArea.classList.remove('scanning');
                    rfidBuffer = '';
                }
            } else {
                rfidBuffer += e.key;
            }
        }
    });

    // Sikeres üzenet automatikus eltüntetése
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            }, 3000);
        });
    });
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>