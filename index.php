<?php
require_once 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Felhasználói adatok lekérése
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Aktív munkaidő ellenőrzése
$stmt = $pdo->prepare("SELECT * FROM work_hours WHERE user_id = ? AND check_out IS NULL ORDER BY check_in DESC LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$active_work = $stmt->fetch();

// Ha van aktív munkaidő, számoljuk az eltelt időt
$elapsed_time = '';
if ($active_work) {
    $start_time = new DateTime($active_work['check_in']);
    $current_time = new DateTime();
    $interval = $current_time->diff($start_time);
    $elapsed_time = $interval->format('%H:%I:%S');
}

// Fizetéselőleg ellenőrzése
$stmt = $pdo->prepare("
    SELECT SUM(amount) as total_advance 
    FROM transactions 
    WHERE category = 'fizeteseloleg' 
    AND type = 'expense'
    AND description LIKE ?
");
$stmt->execute(['%' . $user['username'] . '%']);
$advance = $stmt->fetch();
$has_advance = ($advance['total_advance'] ?? 0) > 0;

// Saját üzletek lekérése
$stmt = $pdo->query("SELECT * FROM shops ORDER BY name");
$shops = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Bakery Professional Management System</title>
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
            --pos-color: #8e44ad;
            --doc-color: #16a085;
            --factory-color: #2980b9;
            --baker-color: #c0392b;
            --shop-color: #d35400;
            --schedule-color: #16a085;
            --profile-color: #3498db;
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
            color: white;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .shop-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }

        .shop-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }

        .shop-header {
            background: linear-gradient(135deg, var(--shop-color), #d35400);
            color: white;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .shop-header i {
            position: absolute;
            right: -10px;
            top: -10px;
            font-size: 8rem;
            opacity: 0.1;
            transform: rotate(15deg);
        }

        .shop-title {
            font-size: 1.5rem;
            font-weight: 500;
            margin: 0;
            position: relative;
            z-index: 1;
        }

        .btn-action {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .factory-card .shop-header {
            background: linear-gradient(135deg, var(--factory-color), #2573a7);
        }

        .pos-card .shop-header {
            background: linear-gradient(135deg, var(--pos-color), #6c3483);
        }

        .baker-card .shop-header {
            background: linear-gradient(135deg, var(--baker-color), #962e22);
        }

        .profile-card .shop-header {
            background: linear-gradient(135deg, var(--profile-color), #2980b9);
        }

        .schedule-card .shop-header {
            background: linear-gradient(135deg, var(--schedule-color), #0e8c73);
        }

        .doc-card .shop-header {
            background: linear-gradient(135deg, var(--doc-color), #0e8c73);
        }
    </style>
</head>
<body>
    <header class="main-header mb-4">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">AI Bakery Professional Management System</h1>
                    <p class="mb-0 opacity-75">Üdvözöljük, <?php echo htmlspecialchars($user['username']); ?>!</p>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <?php if ($active_work): ?>
                    <div class="d-flex align-items-center text-warning">
                        <i class="fas fa-clock me-2"></i>
                        <span id="elapsed_time"><?php echo $elapsed_time; ?></span>
                    </div>
                    <form method="POST" action="check_in_out.php" class="mb-0">
                        <button type="submit" name="check_out" class="btn btn-danger btn-action">
                            <i class="fas fa-sign-out-alt me-2"></i>Kilépés
                        </button>
                    </form>
                    <?php else: ?>
                    <form method="POST" action="check_in_out.php" class="mb-0">
                        <button type="submit" name="check_in" class="btn btn-success btn-action">
                            <i class="fas fa-sign-in-alt me-2"></i>Belépés
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($user['role'] == 'admin'): ?>
                    <a href="admin/" class="btn btn-warning btn-action">
                        <i class="fas fa-cogs me-2"></i>Admin Panel
                    </a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn btn-danger btn-action">
                        <i class="fas fa-power-off me-2"></i>Kijelentkezés
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container flex-grow-1">
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

        <?php if ($active_work): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-business-time me-2"></i>
                        <strong>Aktív munkaidő</strong>
                    </div>
                    <div>
                        Kezdés: <?php echo date('H:i', strtotime($active_work['check_in'])); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <?php if ($user['role'] == 'admin' || $user['user_type'] == 'driver'): ?>
            <!-- Üzem kártya -->
            <div class="col-lg-4">
                <div class="shop-card factory-card">
                    <div class="shop-header">
                        <i class="fas fa-industry"></i>
                        <h3 class="shop-title">Üzem</h3>
                        <p class="mb-0">Kiszállítás kezelése</p>
                    </div>
                    <div class="shop-body p-4">
                        <a href="factory.php" class="btn btn-light btn-action w-100">
                            <i class="fas fa-boxes me-2"></i>
                            Kezelés
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($user['role'] == 'admin' || $user['user_type'] == 'seller'): ?>
            <!-- POS Rendszer kártya -->
            <div class="col-lg-4">
                <div class="shop-card pos-card">
                    <div class="shop-header">
                        <i class="fas fa-cash-register"></i>
                        <h3 class="shop-title">POS Rendszer</h3>
                        <p class="mb-0">Vonalkódolvasós kasszarendszer</p>
                    </div>
                    <div class="shop-body p-4 bg-white">
                        <form id="posForm" method="GET" action="pos.php">
                            <div class="mb-3">
                                <label class="form-label">Válasszon üzletet</label>
                                <select name="shop_id" class="form-select mb-3" required>
                                    <option value="">Válasszon üzletet...</option>
                                    <?php foreach ($shops as $shop): ?>
                                    <option value="<?php echo $shop['id']; ?>">
                                        <?php echo htmlspecialchars($shop['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-shopping-cart me-2"></i>
                                Kassza megnyitása
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($user['user_type'] == 'baker'): ?>
            <!-- Gyártás kártya -->
            <div class="col-lg-4">
                <div class="shop-card baker-card">
                    <div class="shop-header">
                        <i class="fas fa-bread-slice"></i>
                        <h3 class="shop-title">Gyártás</h3>
                        <p class="mb-0">Termelés kezelése</p>
                    </div>
                    <div class="shop-body p-4">
                        <a href="production.php" class="btn btn-light btn-action w-100">
                            <i class="fas fa-industry me-2"></i>
                            Gyártás kezelése
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Saját adatok kártya -->
            <div class="col-lg-4">
                <div class="shop-card profile-card">
                    <div class="shop-header">
                        <i class="fas fa-user"></i>
                        <h3 class="shop-title">Saját adatok</h3>
                        <p class="mb-0">Profil kezelése</p>
                    </div>
                    <div class="shop-body p-4">
                        <a href="profile.php" class="btn btn-light btn-action w-100">
                            <i class="fas fa-id-card me-2"></i>
                            Adatok kezelése
                        </a>
                    </div>
                </div>
            </div>

            <!-- Beosztás és fizetések kártya -->
            <div class="col-lg-4">
                <div class="shop-card schedule-card">
                    <div class="shop-header">
                        <i class="fas fa-calendar-alt"></i>
                        <h3 class="shop-title">Beosztás és fizetések</h3>
                        <p class="mb-0">Munkaidő és bérek</p>
                    </div>
                    <div class="shop-body p-4">
                        <a href="schedule.php" class="btn btn-light btn-action w-100">
                            <i class="fas fa-clock me-2"></i>
                            Megtekintés
                        </a>
                    </div>
                </div>
            </div>

            <!-- Dokumentum feltöltés kártya -->
            <div class="col-lg-4">
                <div class="shop-card doc-card">
                    <div class="shop-header">
                        <i class="fas fa-file-upload"></i>
                        <h3 class="shop-title">Dokumentumok</h3>
                        <p class="mb-0">Dokumentumok kezelése</p>
                    </div>
                    <div class="shop-body p-4">
                    <form action="upload_document.php" method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <select name="document_type" class="form-select mb-3" required>
                                    <option value="">Dokumentum típusa...</option>
                                    <option value="szamla">Számla</option>
                                    <option value="igazolas">Igazolás</option>
                                    <option value="egyeb">Egyéb</option>
                                </select>
                                <input type="file" name="document" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-success btn-action w-100">
                                <i class="fas fa-upload me-2"></i>
                                Feltöltés
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($user['role'] == 'admin' || $user['user_type'] == 'seller'): ?>
            <!-- Üzlet kártyák -->
            <?php foreach ($shops as $shop): ?>
            <div class="col-lg-4">
                <div class="shop-card">
                    <div class="shop-header">
                        <i class="fas fa-store"></i>
                        <h3 class="shop-title"><?php echo htmlspecialchars($shop['name']); ?></h3>
                        <p class="mb-0">Üzlet kezelése</p>
                    </div>
                    <div class="shop-body p-4">
                        <a href="form.php?shop_id=<?php echo $shop['id']; ?>" class="btn btn-primary btn-action w-100">
                            <i class="fas fa-cash-register me-2"></i>
                            Kezelés
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Munkaidő számláló
    <?php if ($active_work): ?>
    function updateElapsedTime() {
        const startTime = new Date('<?php echo $active_work['check_in']; ?>').getTime();
        
        setInterval(() => {
            const now = new Date().getTime();
            const elapsed = now - startTime;
            
            const hours = Math.floor(elapsed / (1000 * 60 * 60));
            const minutes = Math.floor((elapsed % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((elapsed % (1000 * 60)) / 1000);
            
            document.getElementById('elapsed_time').textContent = 
                String(hours).padStart(2, '0') + ':' +
                String(minutes).padStart(2, '0') + ':' +
                String(seconds).padStart(2, '0');
        }, 1000);
    }
    updateElapsedTime();
    <?php endif; ?>

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