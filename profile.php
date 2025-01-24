<?php
require_once 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Felhasználó adatainak lekérése
$stmt = $pdo->prepare("
    SELECT * FROM users 
    WHERE id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Munkaidő és fizetés statisztikák lekérése
$start_date = date('Y-m-01'); // Hónap első napja
$end_date = date('Y-m-d'); // Mai nap

// Ledolgozott órák
$stmt = $pdo->prepare("
    SELECT 
        SUM(total_hours) as total_hours,
        COUNT(*) as total_days
    FROM work_hours 
    WHERE user_id = ? 
    AND DATE(check_in) BETWEEN ? AND ?
    AND check_out IS NOT NULL
");
$stmt->execute([$_SESSION['user_id'], $start_date, $end_date]);
$work_stats = $stmt->fetch();

// Aktív munkaidő ellenőrzése
$stmt = $pdo->prepare("
    SELECT * FROM work_hours 
    WHERE user_id = ? 
    AND check_out IS NULL 
    ORDER BY check_in DESC 
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$active_work = $stmt->fetch();

// Fizetési előlegek lekérése
$stmt = $pdo->prepare("
    SELECT SUM(amount) as total_advances
    FROM transactions 
    WHERE type = 'expense'
    AND category = 'fizeteseloleg'
    AND description LIKE ?
    AND DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute(['%' . $user['username'] . '%', $start_date, $end_date]);
$advances = $stmt->fetch();

// Várható fizetés számítása
$total_hours = $work_stats['total_hours'] ?? 0;
$hourly_rate = $user['hourly_rate'] ?? 0;
$expected_salary = $total_hours * $hourly_rate;
$total_advances = $advances['total_advances'] ?? 0;
$remaining_salary = $expected_salary - $total_advances;

// Elszámolt munkaidők lekérése
$stmt = $pdo->prepare("
    SELECT * 
    FROM work_hours 
    WHERE user_id = ? 
    AND DATE(check_in) BETWEEN ? AND ?
    ORDER BY check_in DESC
");
$stmt->execute([$_SESSION['user_id'], $start_date, $end_date]);
$work_hours = $stmt->fetchAll();

// Ha van aktív munkaidő, számoljuk az eltelt időt
$elapsed_time = '';
if ($active_work) {
    $start_time = new DateTime($active_work['check_in']);
    $current_time = new DateTime();
    $interval = $current_time->diff($start_time);
    $elapsed_time = $interval->format('%H:%I:%S');
}

// Beosztások lekérése
$stmt = $pdo->prepare("
    SELECT * 
    FROM schedules 
    WHERE user_id = ?
    AND end_date >= CURDATE()
    ORDER BY start_date ASC
");
$stmt->execute([$_SESSION['user_id']]);
$schedules = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profilom</title>
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

        .profile-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .profile-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .stat-card {
            height: 100%;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }
    </style>
</head>
<body>
    <header class="profile-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0"><?php echo htmlspecialchars($user['username']); ?></h1>
                    <p class="mb-0 opacity-75">
                        <?php
                        $type_labels = [
                            'admin' => 'Adminisztrátor',
                            'driver' => 'Sofőr',
                            'seller' => 'Eladó',
                            'baker' => 'Pék'
                        ];
                        echo $type_labels[$user['user_type']] ?? 'Felhasználó';
                        ?>
                    </p>
                </div>
                <div class="d-flex gap-3">
                    <?php if ($user['role'] == 'admin'): ?>
                    <a href="admin/" class="btn btn-warning">
                        <i class="fas fa-cogs me-2"></i>Admin Panel
                    </a>
                    <?php endif; ?>
                    <a href="index.php" class="btn btn-light">
                        <i class="fas fa-home me-2"></i>Főoldal
                    </a>
                    <a href="logout.php" class="btn btn-danger">
                        <i class="fas fa-sign-out-alt me-2"></i>Kijelentkezés
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
        <!-- Állapot kártyák -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="profile-card stat-card">
                    <div class="card-body">
                        <h5 class="card-title text-primary">
                            <i class="fas fa-clock me-2"></i>Munkaórák
                        </h5>
                        <h3><?php echo number_format($total_hours, 1, ',', ' '); ?> óra</h3>
                        <p class="text-muted mb-0">Ebben a hónapban</p>
                        <?php if ($active_work): ?>
                        <div class="alert alert-success mt-3 mb-0">
                            <i class="fas fa-spinner fa-spin me-2"></i>
                            Aktív munkaidő: <strong id="elapsed_time"><?php echo $elapsed_time; ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="profile-card stat-card">
                    <div class="card-body">
                        <h5 class="card-title text-success">
                            <i class="fas fa-money-bill-wave me-2"></i>Várható fizetés
                        </h5>
                        <h3><?php echo number_format($expected_salary, 0, ',', ' '); ?> Ft</h3>
                        <p class="text-muted">Órabér: <?php echo number_format($hourly_rate, 0, ',', ' '); ?> Ft/óra</p>
                        <?php if ($total_advances > 0): ?>
                        <div class="alert alert-warning mt-3 mb-0">
                            <strong>Előleg:</strong> <?php echo number_format($total_advances, 0, ',', ' '); ?> Ft
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="profile-card stat-card">
                    <div class="card-body">
                        <h5 class="card-title text-info">
                            <i class="fas fa-calendar-alt me-2"></i>Következő műszak
                        </h5>
                        <?php if (!empty($schedules)): ?>
                        <h3><?php echo date('Y.m.d', strtotime($schedules[0]['start_date'])); ?></h3>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($schedules[0]['details']); ?></p>
                        <?php else: ?>
                        <p class="text-muted mb-0">Nincs előre tervezett műszak</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Személyes adatok -->
            <div class="col-md-6 mb-4">
                <div class="profile-card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-user me-2"></i>Személyes adatok</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="text-muted">Adószám</label>
                            <div class="h6"><?php echo htmlspecialchars($user['tax_number'] ?? '-'); ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="text-muted">TAJ szám</label>
                            <div class="h6"><?php echo htmlspecialchars($user['social_security_number'] ?? '-'); ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="text-muted">Lakcím</label>
                            <div class="h6"><?php echo htmlspecialchars($user['address'] ?? '-'); ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="text-muted">Születési dátum</label>
                            <div class="h6"><?php echo $user['birth_date'] ? date('Y.m.d', strtotime($user['birth_date'])) : '-'; ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="text-muted">Anyja neve</label>
                            <div class="h6"><?php echo htmlspecialchars($user['mother_name'] ?? '-'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Elérhetőségek -->
            <div class="col-md-6 mb-4">
                <div class="profile-card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-address-card me-2"></i>Elérhetőségek</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="text-muted">Email cím</label>
                            <div class="h6"><?php echo htmlspecialchars($user['email'] ?? '-'); ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="text-muted">Telefonszám</label>
                            <div class="h6"><?php echo htmlspecialchars($user['phone'] ?? '-'); ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="text-muted">Bankszámlaszám</label>
                            <div class="h6"><?php echo htmlspecialchars($user['bank_account'] ?? '-'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Adatmódosítási kérelem -->
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    Az adatok módosítását kérjük jelezze a vezetőség felé!
                </div>
            </div>
        </div>
    </div>

    <script>
    <?php if ($active_work): ?>
    // Munkaidő számláló
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
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                alert.classList.remove('show');
            }, 5000);
        });
    });
    </script>

    <!-- Bootstrap és egyéb scriptek -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>

    <!-- Órabér input validáció -->
    <script>
    document.querySelectorAll('input[type="number"]').forEach(function(input) {
        input.addEventListener('change', function(e) {
            if (this.value < 0) {
                this.value = 0;
            }
        });
    });

    // Dátum mezők mai napra korlátozása ahol releváns
    document.querySelectorAll('input[type="date"]').forEach(function(input) {
        if (input.classList.contains('future-only')) {
            const today = new Date().toISOString().split('T')[0];
            input.setAttribute('min', today);
        }
    });
    </script>

</body>
</html>

