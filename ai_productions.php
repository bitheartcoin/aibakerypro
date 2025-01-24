<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Admin vagy gyártási jogosultság ellenőrzése
$stmt = $pdo->prepare("
    SELECT u.role, up.permission_name
    FROM users u
    LEFT JOIN user_permission_assignments upa ON u.id = upa.user_id
    LEFT JOIN user_permissions up ON upa.permission_id = up.id
    WHERE u.id = ? AND (u.role = 'admin' OR up.permission_name = 'ai_access')
");
$stmt->execute([$_SESSION['user_id']]);
$user_data = $stmt->fetch();

if (!$user_data || ($user_data['role'] !== 'admin' && $user_data['permission_name'] !== 'ai_access')) {
    header('Location: ../index.php');
    exit;
}

// API kulcs beállítása
define('GEMINI_API_KEY', 'AIzaSyCk6t1C0YeqwBzkAlf4ctGleTCZZ7CbtmI');

// Audit log készítése
function logAIEvent($event_type, $event_data) {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO ai_audit_log (event_type, event_data, user_id, ip_address) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $event_type,
        json_encode($event_data),
        $_SESSION['user_id'],
        $_SERVER['REMOTE_ADDR']
    ]);
}

// Aktív gyártások lekérdezése
$active_batches = $pdo->query("
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
    AND DATE(pb.created_at) = CURDATE()
    ORDER BY pb.started_at DESC
")->fetchAll();

// AI Vivien prompt összeállítása
function constructProductionPrompt($action, $data = []) {
    $prompt = "Te vagy AI Vivien, a Szemes Pékség intelligens gyártási asszisztense. ";
    $prompt .= "Segíts a gyártási folyamatok optimalizálásában. Magyar nyelven válaszolj.\n\n";

    switch ($action) {
        case 'phase_check':
            $prompt .= "Kérlek ellenőrizd a következő gyártási fázis paramétereit:\n";
            $prompt .= "- Termék: " . $data['product_name'] . "\n";
            $prompt .= "- Aktuális fázis: " . $data['phase_name'] . "\n";
            $prompt .= "- Mért hőmérséklet: " . $data['temperature'] . "°C\n";
            $prompt .= "- Mért páratartalom: " . $data['humidity'] . "%\n";
            $prompt .= "- Elvárt hőmérséklet: " . $data['target_temp'] . "°C\n";
            $prompt .= "- Elvárt páratartalom: " . $data['target_humidity'] . "%\n\n";
            $prompt .= "Mit javasolsz a paraméterek optimalizálásához?";
            break;

        case 'quality_check':
            $prompt .= "Kérlek értékeld a következő termék minőségi paramétereit:\n";
            $prompt .= "- Termék: " . $data['product_name'] . "\n";
            $prompt .= "- Szín: " . $data['color'] . "\n";
            $prompt .= "- Állag: " . $data['texture'] . "\n";
            $prompt .= "- Belső hőmérséklet: " . $data['core_temp'] . "°C\n\n";
            $prompt .= "Megfelel a minőségi követelményeknek?";
            break;
    }

    return $prompt;
}

// AI válasz lekérése
function getAIResponse($prompt) {
    $url = "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-pro:generateContent";
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'safetySettings' => [
            [
                'category' => 'HARM_CATEGORY_HARASSMENT',
                'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
            ]
        ]
    ];

    try {
        $ch = curl_init($url . '?key=' . GEMINI_API_KEY);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $result = json_decode($response, true);
        
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return $result['candidates'][0]['content']['parts'][0]['text'];
        }
        
        return "Sajnos nem tudtam elemezni a paramétereket.";

    } catch (Exception $e) {
        logAIEvent('error', ['message' => 'AI API hiba: ' . $e->getMessage()]);
        return "Hiba történt az AI elemzés során.";
    }
}

// Fázis frissítése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_phase'])) {
    try {
        $pdo->beginTransaction();
        
        // AI elemzés kérése
        $phase_data = [
            'product_name' => $_POST['product_name'],
            'phase_name' => $_POST['phase_name'],
            'temperature' => $_POST['temperature'],
            'humidity' => $_POST['humidity'],
            'target_temp' => $_POST['target_temp'],
            'target_humidity' => $_POST['target_humidity']
        ];
        
        $ai_prompt = constructProductionPrompt('phase_check', $phase_data);
        $ai_response = getAIResponse($ai_prompt);
        
        // Fázis adatok mentése
        $stmt = $pdo->prepare("
            UPDATE production_phase_logs 
            SET actual_temperature = ?,
                actual_humidity = ?,
                ai_feedback = ?,
                updated_at = NOW()
            WHERE batch_id = ? AND phase_id = ?
        ");
        
        $stmt->execute([
            $_POST['temperature'],
            $_POST['humidity'],
            $ai_response,
            $_POST['batch_id'],
            $_POST['phase_id']
        ]);
        
        $pdo->commit();
        $_SESSION['success'] = 'Fázis paraméterek sikeresen frissítve!';
        
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
        body {
            background: linear-gradient(135deg, #f6f8fa 0%, #e9ecef 100%);
            min-height: 100vh;
        }
        
        .phase-card {
            border-left: 4px solid #6c757d;
            margin: 1rem 0;
            padding: 1.5rem;
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .phase-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,0.15);
        }
        
        .ai-feedback {
            background: #fff8f3;
            border-left: 4px solid #e67e22;
            padding: 1rem;
            margin-top: 1rem;
            border-radius: 0.25rem;
        }
    </style>
</head>
<body>
    <header class="bg-dark text-white py-4 mb-4">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Gyártás Kezelése</h1>
                    <p class="mb-0">AI Vivien asszisztens</p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-outline-light">
                        <i class="fas fa-arrow-left me-2"></i>Vissza
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container">
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

        <?php if (empty($active_batches)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Jelenleg nincs aktív gyártási folyamat.
            </div>
        <?php else: ?>
            <?php foreach ($active_batches as $batch): ?>
                <div class="phase-card">
                    <div class="d-flex justify-content-between align-items-start mb-4">
                        <div>
                            <h5 class="mb-1"><?php echo htmlspecialchars($batch['product_name']); ?></h5>
                            <p class="text-muted mb-0">
                                <?php echo htmlspecialchars($batch['recipe_name']); ?> |
                                Aktuális fázis: <?php echo htmlspecialchars($batch['current_phase']); ?>
                            </p>
                        </div>
                        <span class="badge bg-primary">Folyamatban</span>
                    </div>

                    <form method="POST" class="row g-3">
                        <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                        <input type="hidden" name="phase_id" value="<?php echo $batch['phase_id']; ?>">
                        <input type="hidden" name="product_name" value="<?php echo htmlspecialchars($batch['product_name']); ?>">
                        <input type="hidden" name="phase_name" value="<?php echo htmlspecialchars($batch['current_phase']); ?>">
                        
                        <div class="col-md-3">
                            <label class="form-label">Hőmérséklet (°C)</label>
                            <input type="number" name="temperature" class="form-control" 
                                   value="<?php echo $batch['actual_temperature']; ?>" step="0.1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Páratartalom (%)</label>
                            <input type="number" name="humidity" class="form-control" 
                                   value="<?php echo $batch['actual_humidity']; ?>" step="0.1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" name="update_phase" class="btn btn-primary w-100">
                                <i class="fas fa-sync-alt me-2"></i>Paraméterek frissítése
                            </button>
                        </div>
                    </form>

                    <?php if (isset($batch['ai_feedback'])): ?>
                        <div class="ai-feedback">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-robot me-2"></i>
                                <strong>AI Vivien javaslata:</strong>
                            </div>
                            <?php echo nl2br(htmlspecialchars($batch['ai_feedback'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>