<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Admin jogosultság ellenőrzése
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

// Chat előzmények mentése
function saveChatHistory($question, $response, $context) {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO ai_chat_history (user_id, question, response, context_data)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $question,
        $response,
        json_encode($context)
    ]);
}

// Dokumentum feltöltés kezelése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document'])) {
    $upload_dir = '../uploads/documents/';
    $document_type = $_POST['document_type'] ?? 'other';
    $allowed_types = ['txt', 'pdf', 'docx', 'csv'];

    $file_extension = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
    
    if (in_array($file_extension, $allowed_types)) {
        $new_filename = uniqid() . '_' . date('YmdHis') . '.' . $file_extension;
        $upload_path = $upload_dir . $document_type . '/' . $new_filename;

        if (!file_exists($upload_dir . $document_type)) {
            mkdir($upload_dir . $document_type, 0755, true);
        }

        if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_path)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO ai_documents (
                        user_id, 
                        document_type,
                        filename,
                        original_filename,
                        file_path,
                        created_at,
                        status
                    ) VALUES (?, ?, ?, ?, ?, NOW(), 'pending')
                ");
                
                $stmt->execute([
                    $_SESSION['user_id'],
                    $document_type,
                    $new_filename,
                    $_FILES['document']['name'],
                    $upload_path
                ]);

                logAIEvent('document_upload', [
                    'document_type' => $document_type,
                    'original_filename' => $_FILES['document']['name']
                ]);

                $_SESSION['success'] = 'Dokumentum sikeresen feltöltve!';
            } catch (Exception $e) {
                unlink($upload_path);
                $_SESSION['error'] = 'Hiba történt a dokumentum mentése során: ' . $e->getMessage();
                logAIEvent('error', ['message' => $e->getMessage()]);
            }
        } else {
            $_SESSION['error'] = 'Hiba történt a fájl feltöltése közben!';
        }
    } else {
        $_SESSION['error'] = 'Nem támogatott fájltípus! Csak txt, pdf, docx és csv fájlok engedélyezettek.';
    }
}

// Adatbázis kontextus gyűjtése
function getAIContext() {
    global $pdo;
    
    $context = [
        'basic_stats' => [],
        'sales_data' => [],
        'product_insights' => [],
        'user_insights' => []
    ];
    
    // Alapvető statisztikák
    $context['basic_stats'] = [
        'active_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE active = 1")->fetchColumn(),
        'today_income' => $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE DATE(created_at) = CURDATE() AND type = 'income'")->fetchColumn(),
        'total_products' => $pdo->query("SELECT COUNT(*) FROM products WHERE active = 1")->fetchColumn(),
    ];

    // Legkelendőbb termékek részletesen
    $stmt = $pdo->query("
        SELECT 
            p.name AS product_name, 
            SUM(d.quantity) AS total_sold,
            ROUND(SUM(d.quantity * p.price), 2) AS total_revenue,
            p.price AS unit_price
        FROM deliveries d
        JOIN products p ON d.product_id = p.id
        WHERE DATE(d.delivery_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY p.id, p.name, p.price
        ORDER BY total_sold DESC
        LIMIT 10
    ");
    $context['sales_data']['top_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Üzletek teljesítménye
    $context['sales_data']['shop_performance'] = $pdo->query("
        SELECT 
            s.name AS shop_name, 
            ROUND(SUM(d.quantity), 2) AS total_items_sold,
            ROUND(SUM(d.quantity * p.price), 2) AS total_revenue,
            COUNT(DISTINCT d.product_id) AS unique_products
        FROM deliveries d
        JOIN shops s ON d.shop_id = s.id
        JOIN products p ON d.product_id = p.id
        WHERE DATE(d.delivery_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY s.id, s.name
        ORDER BY total_revenue DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Mai visszáruk
    $context['returns'] = $pdo->query("
        SELECT p.name, SUM(r.quantity) as return_quantity
        FROM returns r
        JOIN products p ON r.product_id = p.id
        WHERE DATE(r.return_date) = CURDATE()
        GROUP BY p.id, p.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    return $context;
}

// Dokumentum tartalom olvasása
function readDocumentContent($file_path) {
    $file_extension = pathinfo($file_path, PATHINFO_EXTENSION);
    
    try {
        switch ($file_extension) {
            case 'txt':
                return file_get_contents($file_path);
            
            case 'csv':
                $csv_data = array_map('str_getcsv', file($file_path));
                return json_encode($csv_data);
            
            default:
                return 'Nem támogatott fájltípus olvasása.';
        }
    } catch (Exception $e) {
        logAIEvent('error', ['message' => 'Dokumentum olvasási hiba: ' . $e->getMessage()]);
        return 'Hiba a dokumentum olvasása során: ' . $e->getMessage();
    }
}

// Gemini AI hívás
function askGemini($question, $context = []) {
    $prompt = constructPrompt($question, $context);
    
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('Curl error: ' . curl_error($ch));
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            throw new Exception('API hívás sikertelen. HTTP kód: ' . $http_code . ' Response: ' . $response);
        }

        $result = json_decode($response, true);
        
        if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception('Érvénytelen API válasz formátum');
        }
        
        return $result['candidates'][0]['content']['parts'][0]['text'];

    } catch (Exception $e) {
        logAIEvent('error', ['message' => 'API hívási hiba: ' . $e->getMessage()]);
        throw $e;
    }
}

// Prompt összeállítása
function constructPrompt($question, $context) {
    $prompt = "Te vagy AI Vivien, a Szemes Pékség intelligens üzleti asszisztense. ";
    $prompt .= "Segítesz az üzlet működésével kapcsolatos kérdésekben. Magyar nyelven válaszolj.\n\n";
    
    // Alapvető statisztikák
    if (isset($context['basic_stats'])) {
        $prompt .= "Alapvető adatok:\n";
        $prompt .= "- Aktív felhasználók: " . $context['basic_stats']['active_users'] . "\n";
        $prompt .= "- Mai forgalom: " . number_format($context['basic_stats']['today_income'], 0, ',', ' ') . " Ft\n";
        $prompt .= "- Aktív termékek: " . $context['basic_stats']['total_products'] . " db\n\n";
    }

    // Eladási adatok
    if (isset($context['sales_data']['top_products'])) {
        $prompt .= "Legkelendőbb termékek:\n";
        foreach ($context['sales_data']['top_products'] as $product) {
            $prompt .= "- " . $product['product_name'] . ": " 
                    . $product['total_sold'] . " db (Bevétel: " 
                    . number_format($product['total_revenue'], 0, ',', ' ') . " Ft)\n";
        }
        $prompt .= "\n";
    }

    // Készletinformációk
    if (!empty($context['inventory'])) {
        $prompt .= "Alacsony készletű termékek:\n";
        foreach ($context['inventory'] as $product) {
            $prompt .= "- " . $product['name'] . ": " . $product['stock_quantity'] . " db\n";
        }
        $prompt .= "\n";
    }

    // Mai visszáruk
    if (!empty($context['returns'])) {
        $prompt .= "Mai visszáruk:\n";
        foreach ($context['returns'] as $return) {
            $prompt .= "- " . $return['name'] . ": " . $return['return_quantity'] . " db\n";
        }
        $prompt .= "\n";
    }

    // Dokumentum tartalom, ha van
    if (isset($context['document_content'])) {
        $prompt .= "Dokumentum tartalma:\n" . $context['document_content'] . "\n\n";
    }

    $prompt .= "Kérdés: " . $question . "\n\n";
    $prompt .= "Kérlek adj részletes, érthető választ a rendelkezésre álló adatok alapján!";

    return $prompt;
}

// Chat üzenet feldolgozása
$response = '';
$context = [];
$document_content = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['document_context'])) {
        $document_path = $_POST['document_context'];
        $document_content = readDocumentContent($document_path);
        $context['document_content'] = $document_content;
    }

    if (isset($_POST['question'])) {
        try {
            $question = $_POST['question'];
            $context = array_merge($context, getAIContext());
            
            $response = askGemini($question, $context);
            
            saveChatHistory($question, $response, $context);
            
            logAIEvent('chat_message', [
                'question' => $question,
                'context_type' => isset($_POST['document_context']) ? 'document' : 'general'
            ]);
            
        } catch (Exception $e) {
            $_SESSION['error'] = 'Hiba történt a válasz generálása során: ' . $e->getMessage();
            logAIEvent('error', ['message' => $e->getMessage()]);
        }
    }
}

// Chat előzmények lekérése
$chat_history = [];
$stmt = $pdo->prepare("
    SELECT question, response, created_at 
    FROM ai_chat_history 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([$_SESSION['user_id']]);
$chat_history = array_reverse($stmt->fetchAll());

// Legutóbbi dokumentumok lekérése
$recent_docs = $pdo->query("
    SELECT filename, document_type, original_filename, file_path 
    FROM ai_documents 
    WHERE user_id = {$_SESSION['user_id']}
    ORDER BY created_at DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-


    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Vivien - Admin</title>
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

        .chat-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            height: calc(100vh - 250px);
            display: flex;
            flex-direction: column;
        }

        .chat-messages {
            flex-grow: 1;
            overflow-y: auto;
            padding: 1.5rem;
            background-color: #f8f9fa;
        }

        .chat-input {
            border-top: 1px solid #eee;
            padding: 1.5rem;
            background: white;
            border-radius: 0 0 15px 15px;
        }

        .message {
            margin-bottom: 1.5rem;
            max-width: 80%;
            clear: both;
        }

        .message.user {
            float: right;
            text-align: right;
        }

        .message.assistant {
            float: left;
        }

        .message-content {
            padding: 1rem;
            border-radius: 15px;
            display: inline-block;
            max-width: 100%;
            word-wrap: break-word;
        }

        .user .message-content {
            background: #3498db;
            color: white;
        }

        .assistant .message-content {
            background: #f1f3f5;
            border: 1px solid #dee2e6;
            color: #2c3e50;
        }

        .document-upload-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-top: 1rem;
            padding: 1.5rem;
        }

        .file-upload-preview {
            display: flex;
            align-items: center;
            margin-top: 1rem;
            padding: 0.75rem;
            background-color: #f8f9fa;
            border-radius: 10px;
        }

        .file-upload-preview i {
            font-size: 2rem;
            margin-right: 1rem;
            color: var(--accent-color);
        }

        .suggestions {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .suggestion-chip {
            padding: 0.5rem 1rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .suggestion-chip:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">AI Asszisztens</p>
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

    <div class="container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php 
                echo htmlspecialchars($_SESSION['success']);
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php 
                echo htmlspecialchars($_SESSION['error']);
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <div class="chat-container">
                    <div class="chat-messages" id="chatMessages">
                        <!-- Üdvözlő üzenet -->
                        <?php if (empty($chat_history)): ?>
                            <div class="message assistant">
                                <div class="message-content">
                                    <strong>AI Vivien:</strong><br>
                                    Üdvözöllek! Kérdezhetsz tőlem bármit az üzlettel kapcsolatban. 
                                    Használhatod a javasolt kérdéseket, vagy feltölthetsz dokumentumokat 
                                    a jobb oldali sávban a részletesebb elemzéshez.
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Chat előzmények -->
                        <?php foreach ($chat_history as $chat): ?>
                            <div class="message user">
                                <div class="message-content">
                                    <strong>Kérdés:</strong><br>
                                    <?php echo htmlspecialchars($chat['question']); ?>
                                </div>
                            </div>
                            <div class="message assistant">
                                <div class="message-content">
                                    <strong>AI Vivien:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($chat['response'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Javasolt kérdések -->
                        <div class="suggestions">
                            <?php
                            $suggestions = [
                                'Mai forgalom összesítése',
                                'Legkelendőbb termékek listája',
                                'Készletszint ellenőrzése',
                                'Mai rendelések áttekintése',
                                'Kiszállítások státusza'
                            ];
                            foreach ($suggestions as $suggestion): 
                            ?>
                                <div class="suggestion-chip" onclick="askQuestion('<?php echo htmlspecialchars($suggestion); ?>')">
                                    <?php echo htmlspecialchars($suggestion); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Új kérdés és válasz -->
                        <?php if ($response): ?>
                            <div class="message user">
                                <div class="message-content">
                                    <strong>Kérdés:</strong><br>
                                    <?php echo htmlspecialchars($_POST['question']); ?>
                                </div>
                            </div>
                            <div class="message assistant">
                                <div class="message-content">
                                    <strong>AI Vivien:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($response)); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="chat-input">
                        <form method="POST" class="d-flex gap-3">
                            <input type="text" name="question" class="form-control" 
                                   placeholder="Írj be egy kérdést..." required>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-2"></i>Küldés
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <!-- Dokumentum feltöltő kártya -->
                <div class="document-upload-card">
                    <h5 class="mb-3">
                        <i class="fas fa-file-upload me-2"></i>Dokumentum feltöltés
                    </h5>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Dokumentum típusa</label>
                            <select name="document_type" class="form-select" required>
                                <option value="">Válasszon típust...</option>
                                <option value="txt">Szöveges dokumentum</option>
                                <option value="recipes">Recept</option>
                                <option value="reports">Jelentés</option>
                                <option value="other">Egyéb</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Dokumentum kiválasztása</label>
                            <input type="file" name="document" class="form-control" 
                                   accept=".txt,.pdf,.docx,.csv" required>
                        </div>
                        <div id="filePreview" class="file-upload-preview" style="display:none;">
                            <i class="fas fa-file"></i>
                            <div>
                                <strong id="fileName">fájlnév.txt</strong><br>
                                <small id="fileSize">0 KB</small>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 mt-3">
                            <i class="fas fa-cloud-upload-alt me-2"></i>Feltöltés
                        </button>
                    </form>
                </div>

                <!-- Legutóbbi dokumentumok -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>Legutóbbi dokumentumok
                        </h5>
                    </div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($recent_docs as $doc): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo htmlspecialchars($doc['original_filename']); ?></strong>
                                    <small class="d-block text-muted">
                                        <?php echo htmlspecialchars($doc['document_type']); ?>
                                    </small>
                                </div>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="document_context" 
                                    value="<?php echo htmlspecialchars($doc['file_path']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Fájl előnézet
    document.querySelector('input[name="document"]').addEventListener('change', function(event) {
const file = event.target.files[0];
        const filePreview = document.getElementById('filePreview');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');

        if (file) {
            fileName.textContent = file.name;
            fileSize.textContent = (file.size / 1024).toFixed(2) + ' KB';
            filePreview.style.display = 'flex';
        } else {
            filePreview.style.display = 'none';
        }
    });

    // Javasolt kérdés kezelése
    function askQuestion(question) {
        document.querySelector('input[name="question"]').value = question;
        document.querySelector('form').submit();
    }

    // Üzenetek görgetése
    function scrollToBottom() {
        const chatMessages = document.getElementById('chatMessages');
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // Oldal betöltésekor és új üzenet után görgetés
    window.addEventListener('load', scrollToBottom);
    document.addEventListener('DOMContentLoaded', scrollToBottom);
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>