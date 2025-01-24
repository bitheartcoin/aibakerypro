<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Jogosultság ellenőrzése (csak pék és admin láthatja)
$stmt = $pdo->prepare("SELECT role, user_type FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user['role'] !== 'admin' && $user['user_type'] !== 'baker') {
    header('Location: ../index.php');
    exit;
}

// Mai napi gyártás lekérdezése (14:00-ig beérkezett rendelések)
$today = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT 
        p.id as product_id,
        p.name as product_name,
        SUM(oi.quantity) as total_ordered,
        COALESCE(pb.quantity, 0) as in_production,
        COALESCE(pb.status, 'pending') as production_status
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN production_batches pb ON p.id = pb.product_id 
        AND DATE(pb.created_at) = ?
    WHERE DATE(o.created_at) = ?
        AND TIME(o.created_at) <= '14:00:00'
    GROUP BY p.id, p.name
    ORDER BY p.name
");
$stmt->execute([$today, $today]);
$daily_production = $stmt->fetchAll();

// Receptek lekérése
$recipes = $pdo->query("
    SELECT r.*, p.name as product_name
    FROM recipes r
    JOIN products p ON r.product_id = p.id
    ORDER BY p.name
")->fetchAll();

// Alapanyagok lekérése
$ingredients = $pdo->query("
    SELECT * FROM ingredients 
    ORDER BY name
")->fetchAll();

// Aktív gyártási batch-ek lekérése
$stmt = $pdo->prepare("
    SELECT 
        pb.*,
        p.name as product_name,
        r.name as recipe_name,
        ppl.phase_id,
        pp.name as current_phase,
        ppl.actual_temperature,
        ppl.actual_humidity
    FROM production_batches pb
    JOIN products p ON pb.product_id = p.id
    JOIN recipes r ON pb.recipe_id = r.id
    LEFT JOIN production_phase_logs ppl ON pb.id = ppl.batch_id
    LEFT JOIN production_phases pp ON ppl.phase_id = pp.id
    WHERE pb.status = 'in_progress'
    AND DATE(pb.created_at) = ?
    ORDER BY pb.started_at DESC
");
$stmt->execute([$today]);
$active_batches = $stmt->fetchAll();

// Napi gyártás indítása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_production'])) {
    try {
        $pdo->beginTransaction();
        
        foreach ($_POST['quantities'] as $product_id => $quantity) {
            if ($quantity > 0) {
                // Recipe lekérése
                $stmt = $pdo->prepare("
                    SELECT id FROM recipes 
                    WHERE product_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ");
                $stmt->execute([$product_id]);
                $recipe = $stmt->fetch();
                
                if ($recipe) {
                    // Batch létrehozása
                    $stmt = $pdo->prepare("
                        INSERT INTO production_batches (
                            product_id, recipe_id, quantity, status, started_at
                        ) VALUES (?, ?, ?, 'in_progress', NOW())
                    ");
                    $stmt->execute([
                        $product_id,
                        $recipe['id'],
                        $quantity
                    ]);
                    
                    $batch_id = $pdo->lastInsertId();
                    
                    // Első fázis létrehozása
                    $stmt = $pdo->prepare("
                        INSERT INTO production_phase_logs (
                            batch_id, phase_id, status, started_at
                        ) SELECT ?, id, 'pending', NOW()
                        FROM production_phases 
                        WHERE recipe_id = ? 
                        ORDER BY phase_order 
                        LIMIT 1
                    ");
                    $stmt->execute([$batch_id, $recipe['id']]);
                }
            }
        }
        
        $pdo->commit();
        $_SESSION['success'] = 'Gyártás sikeresen elindítva!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Hiba történt: ' . $e->getMessage();
    }
    header('Location: production.php');
    exit;
}

// Fázis befejezése és következő indítása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_phase'])) {
    try {
        $pdo->beginTransaction();
        
        $batch_id = $_POST['batch_id'];
        $phase_id = $_POST['phase_id'];
        
        // Jelenlegi fázis lezárása
        $stmt = $pdo->prepare("
            UPDATE production_phase_logs 
            SET status = 'completed',
                completed_at = NOW(),
                actual_temperature = ?,
                actual_humidity = ?
            WHERE batch_id = ? AND phase_id = ?
        ");
        $stmt->execute([
            $_POST['temperature'] ?? null,
            $_POST['humidity'] ?? null,
            $batch_id,
            $phase_id
        ]);
        
        // Következő fázis lekérése
        $stmt = $pdo->prepare("
            SELECT pp2.* 
            FROM production_phases pp1
            JOIN production_phases pp2 ON pp1.recipe_id = pp2.recipe_id 
                AND pp2.phase_order = pp1.phase_order + 1
            WHERE pp1.id = ?
        ");
        $stmt->execute([$phase_id]);
        $next_phase = $stmt->fetch();
        
        if ($next_phase) {
            // Következő fázis létrehozása
            $stmt = $pdo->prepare("
                INSERT INTO production_phase_logs (
                    batch_id, phase_id, status, started_at
                ) VALUES (?, ?, 'in_progress', NOW())
            ");
            $stmt->execute([$batch_id, $next_phase['id']]);
        } else {
            // Ha nincs több fázis, a batch lezárása
            $stmt = $pdo->prepare("
                UPDATE production_batches 
                SET status = 'completed',
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$batch_id]);
        }
        
        $pdo->commit();
        $_SESSION['success'] = 'Fázis sikeresen befejezve!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Hiba történt: ' . $e->getMessage();
    }
    header('Location: production.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gyártás Kezelése - Pékség</title>
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

        .phase-card {
            border-left: 4px solid var(--accent-color);
            transition: all 0.3s ease;
        }

        .phase-card:hover {
            transform: translateX(5px);
        }

        .phase-card.active {
            border-left-color: var(--success-color);
            background: #f8fff9;
        }

        .temperature-badge {
            background: linear-gradient(135deg, #ff9966, #ff5e62);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }

        .humidity-badge {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }

        .recipe-list {
            max-height: 500px;
            overflow-y: auto;
        }

        .ingredient-card {
            border-left: 4px solid var(--warning-color);
        }

        .batch-progress {
            height: 8px;
            border-radius: 4px;
            background: var(--light-color);
            overflow: hidden;
        }

        .batch-progress-bar {
            height: 100%;
            background: linear-gradient(135deg, var(--accent-color), #d35400);
            transition: width 0.3s ease;
        }

        .alert-temperature {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Gyártás Kezelése</h1>
                    <p class="mb-0 opacity-75">Mai termelés irányítása</p>
                </div>
                <div class="d-flex gap-3 align-items-center">
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

        <!-- Mai gyártási terv -->
        <div class="content-card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-calendar-day me-2"></i>
                    Mai gyártási terv (<?php echo date('Y.m.d'); ?>)
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" class="mb-4">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Termék</th>
                                    <th>Rendelt mennyiség</th>
                                    <th>Gyártásban</th>
                                    <th>Státusz</th>
                                    <th>Gyártandó mennyiség</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($daily_production as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                    <td><?php echo $item['total_ordered']; ?> db</td>
                                    <td><?php echo $item['in_production']; ?> db</td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($item['production_status']) {
                                                'completed' => 'success',
                                                'in_progress' => 'warning',
                                                'cancelled' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo match($item['production_status']) {
                                                'completed' => 'Elkészült',
                                                'in_progress' => 'Folyamatban',
                                                'cancelled' => 'Törölve',
                                                default => 'Függőben'
                                            }; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (date('H') < 18): // Csak 18:00 előtt lehet módosítani ?>
                                        <input type="number" name="quantities[<?php echo $item['product_id']; ?>]" 
                                               class="form-control form-control-sm" style="max-width: 100px;"
                                               value="<?php echo max(0, $item['total_ordered'] - $item['in_production']); ?>">
                                        <?php else: ?>
                                        <span class="text-muted">Lezárva</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (date('H') < 18): ?>
                    <button type="submit" name="start_production" class="btn btn-primary">
                        <i class="fas fa-play me-2"></i>Gyártás indítása
                    </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Aktív gyártási folyamatok -->
        <div class="row">
            <div class="col-lg-8">
                <div class="content-card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="fas fa-industry me-2"></i>
                            Aktív gyártási folyamatok
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($active_batches)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Jelenleg nincs aktív gyártási folyamat.
                            </div>
                        <?php else: ?>
                            <?php foreach ($active_batches as $batch): ?>
                                <div class="phase-card p-3 mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($batch['product_name']); ?></h6>
                                        <span class="badge bg-primary"><?php echo $batch['quantity']; ?> db</span>
                                    </div>
                                    
                                    <div class="d-flex gap-3 mb-2">
                                        <?php if ($batch['actual_temperature']): ?>
                                            <span class="temperature-badge">
                                                <i class="fas fa-thermometer-half me-1"></i>
                                                <?php echo $batch['actual_temperature']; ?>°C
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($batch['actual_humidity']): ?>
                                            <span class="humidity-badge">
                                                <i class="fas fa-tint me-1"></i>
                                                <?php echo $batch['actual_humidity']; ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <p class="mb-2">
                                        <strong>Aktuális fázis:</strong> 
                                        <?php echo htmlspecialchars($batch['current_phase']); ?>
                                    </p>

                                    <form method="POST" class="d-flex gap-2">
                                        <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                        <input type="hidden" name="phase_id" value="<?php echo $batch['phase_id']; ?>">
                                        
                                        <input type="number" name="temperature" class="form-control form-control-sm" 
                                               placeholder="Hőmérséklet" step="0.1">
                                        <input type="number" name="humidity" class="form-control form-control-sm" 
                                               placeholder="Páratartalom" step="0.1">
                                        
                                        <button type="submit" name="complete_phase" class="btn btn-success btn-sm">
                                            <i class="fas fa-check me-1"></i>Fázis befejezése
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Receptúrák és összetevők -->
            <div class="col-lg-4">
                <div class="content-card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-book me-2"></i>
                            Receptúrák és összetevők
                        </h5>
                    </div>
                    <div class="card-body recipe-list">
                        <?php foreach ($recipes as $recipe): ?>
                            <div class="mb-4">
                                <h6><?php echo htmlspecialchars($recipe['product_name']); ?></h6>
                                <p class="small text-muted mb-2">
                                    <?php echo $recipe['base_quantity']; ?> db-os alaprecept
                                </p>
                                
                                <?php
                                // Recept összetevők lekérése
                                $stmt = $pdo->prepare("
                                    SELECT 
                                        i.name as ingredient_name,
                                        ri.quantity,
                                        i.unit
                                    FROM recipe_ingredients ri
                                    JOIN ingredients i ON ri.ingredient_id = i.id
                                    WHERE ri.recipe_id = ?
                                ");
                                $stmt->execute([$recipe['id']]);
                                $ingredients = $stmt->fetchAll();
                                ?>
                                
                                <div class="ingredient-card p-2">
                                    <?php foreach ($ingredients as $ingredient): ?>
                                        <div class="d-flex justify-content-between small">
                                            <span><?php echo htmlspecialchars($ingredient['ingredient_name']); ?></span>
                                            <span>
                                                <?php echo $ingredient['quantity'] . ' ' . $ingredient['unit']; ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Szenzor adatok modal -->
    <div class="modal fade" id="sensorDataModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Szenzor adatok</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Hőmérséklet</label>
                        <div class="temperature-badge w-100 text-center">
                            <span id="currentTemp">-- °C</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Páratartalom</label>
                        <div class="humidity-badge w-100 text-center">
                            <span id="currentHumidity">-- %</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script>
// Szenzor adatok szimulációja és kezelése
class SensorMonitor {
        constructor() {
            this.temp = 25;
            this.humidity = 50;
            this.alerts = new Set();
        }

        // Szimulált hőmérséklet változás
        simulateTemperature() {
            // Random változás -0.5 és +0.5 között
            this.temp += (Math.random() - 0.5);
            return this.temp.toFixed(1);
        }

        // Szimulált páratartalom változás
        simulateHumidity() {
            // Random változás -1 és +1 között
            this.humidity += (Math.random() - 0.5) * 2;
            // Határok között tartás
            this.humidity = Math.max(30, Math.min(90, this.humidity));
            return this.humidity.toFixed(1);
        }

        // Figyelmeztetések kezelése
        checkAlerts(targetTemp, targetHumidity) {
            const alerts = new Set();
            
            // Hőmérséklet ellenőrzése
            if (targetTemp) {
                const tempDiff = Math.abs(this.temp - targetTemp);
                if (tempDiff > 5) {
                    alerts.add(`Kritikus hőmérséklet eltérés: ${this.temp.toFixed(1)}°C vs. ${targetTemp}°C`);
                } else if (tempDiff > 2) {
                    alerts.add(`Hőmérséklet figyelmeztetés: ${this.temp.toFixed(1)}°C vs. ${targetTemp}°C`);
                }
            }

            // Páratartalom ellenőrzése
            if (targetHumidity) {
                const humidityDiff = Math.abs(this.humidity - targetHumidity);
                if (humidityDiff > 10) {
                    alerts.add(`Kritikus páratartalom eltérés: ${this.humidity.toFixed(1)}% vs. ${targetHumidity}%`);
                } else if (humidityDiff > 5) {
                    alerts.add(`Páratartalom figyelmeztetés: ${this.humidity.toFixed(1)}% vs. ${targetHumidity}%`);
                }
            }

            return alerts;
        }
    }

    // Gyártási folyamat követő
    class ProductionTracker {
        constructor() {
            this.activePhases = new Map();
            this.sensorMonitor = new SensorMonitor();
        }

        // Fázis indítása
        startPhase(batchId, phaseData) {
            this.activePhases.set(batchId, {
                ...phaseData,
                startTime: new Date(),
                elapsedSeconds: 0
            });
        }

        // Fázis befejezése
        completePhase(batchId) {
            this.activePhases.delete(batchId);
        }

        // Idő frissítése
        updateTimes() {
            const now = new Date();
            this.activePhases.forEach((phase, batchId) => {
                const elapsed = (now - phase.startTime) / 1000;
                phase.elapsedSeconds = elapsed;

                // Automatikus figyelmeztetés ha túl hosszú ideje tart
                if (phase.duration_minutes && elapsed > phase.duration_minutes * 60) {
                    this.showAlert(`Figyelem! A "${phase.name}" fázis túllépte a tervezett időt!`, 'warning');
                }
            });
        }

        // Figyelmeztetés megjelenítése
        showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="fas fa-info-circle me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.querySelector('.container-fluid').prepend(alertDiv);

            // Automatikus eltüntetés 5 másodperc után
            setTimeout(() => {
                alertDiv.remove();
            }, 5000);
        }
    }

    // Alkalmazás inicializálása
    document.addEventListener('DOMContentLoaded', function() {
        const tracker = new ProductionTracker();

        // Aktív fázisok inicializálása
        document.querySelectorAll('.phase-card').forEach(card => {
            const batchId = card.querySelector('input[name="batch_id"]').value;
            const phaseName = card.querySelector('strong').nextSibling.textContent.trim();
            
            tracker.startPhase(batchId, {
                name: phaseName,
                // További adatok a kártyáról...
            });
        });

        // Szenzorok szimulációja és frissítése
        setInterval(() => {
            // Hőmérséklet és páratartalom szimulálása
            const temp = tracker.sensorMonitor.simulateTemperature();
            const humidity = tracker.sensorMonitor.simulateHumidity();

            // Kijelzők frissítése
            document.querySelectorAll('.temperature-badge').forEach(badge => {
                badge.querySelector('span').textContent = `${temp}°C`;
            });
            document.querySelectorAll('.humidity-badge').forEach(badge => {
                badge.querySelector('span').textContent = `${humidity}%`;
            });

            // Figyelmeztetések ellenőrzése
            tracker.activePhases.forEach((phase, batchId) => {
                const alerts = tracker.sensorMonitor.checkAlerts(
                    phase.target_temperature,
                    phase.target_humidity
                );
                
                alerts.forEach(alert => {
                    tracker.showAlert(alert, 'warning');
                });
            });

            // Idők frissítése
            tracker.updateTimes();
        }, 1000);

        // Form validáció
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const temperature = form.querySelector('input[name="temperature"]');
                const humidity = form.querySelector('input[name="humidity"]');

                if (temperature && temperature.value) {
                    const temp = parseFloat(temperature.value);
                    if (temp < 0 || temp > 300) {
                        e.preventDefault();
                        tracker.showAlert('Érvénytelen hőmérséklet érték!', 'danger');
                    }
                }

                if (humidity && humidity.value) {
                    const hum = parseFloat(humidity.value);
                    if (hum < 0 || hum > 100) {
                        e.preventDefault();
                        tracker.showAlert('Érvénytelen páratartalom érték!', 'danger');
                    }
                }
            });
        });

        // Automatikus mentés beállítása
        let autoSaveInterval;
        document.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('change', () => {
                clearTimeout(autoSaveInterval);
                autoSaveInterval = setTimeout(() => {
                    const form = input.closest('form');
                    const formData = new FormData(form);
                    fetch(form.action, {
                        method: 'POST',
                        body: formData
                    });
                }, 1000);
            });
        });
    });
    </script>
</body>
</html>