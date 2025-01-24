<?php
// /admin/ai_vivien.php
require_once 'config.php';
require_once 'includes/class.ai-vivien.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Admin jogosultság ellenőrzése
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$ai = new AIVivien($pdo);
$response = '';
$error = '';

// Kérdés feldolgozása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['question'])) {
    try {
        $response = $ai->generateResponse($_POST['question']);
        $_SESSION['chat_history'][] = [
            'question' => $_POST['question'],
            'response' => $response
        ];
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Chat előzmények betöltése
$chat_history = $_SESSION['chat_history'] ?? [];
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Vivien - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #e67e22;
        }

        .chat-container {
            height: calc(100vh - 200px);
            display: flex;
            flex-direction: column;
        }

        .chat-messages {
            flex-grow: 1;
            overflow-y: auto;
            padding: 1.5rem;
            background-color: #f8f9fa;
        }

        .message {
            margin-bottom: 1.5rem;
            max-width: 80%;
            clear: both;
        }

        .message.user {
            float: right;
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
            background: white;
            border: 1px solid #dee2e6;
        }

        .suggestion-chip {
            display: inline-block;
            padding: 0.5rem 1rem;
            margin: 0.25rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .suggestion-chip:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }
    </style>
</head>
<body class="bg-light">
    <header class="bg-dark text-white py-4 mb-4">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">AI Vivien</h1>
                    <p class="mb-0 opacity-75">Intelligens Üzleti Asszisztens</p>
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
        <!-- Chat konténer -->
        <div class="chat-container bg-white shadow rounded">
            <!-- Üzenetek megjelenítése -->
            <div class="chat-messages" id="chatMessages">
                <!-- Üdvözlő üzenet -->
                <?php if (empty($chat_history)): ?>
                <div class="message assistant">
                    <div class="message-content">
                        <strong>AI Vivien:</strong><br>
                        Üdvözlöm! Én vagyok AI Vivien, a Szemes Pékség intelligens asszisztense. 
                        Kérdezzen bármit az üzlettel kapcsolatban, és igyekszem segíteni a rendelkezésre 
                        álló adatok alapján.
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

                <!-- Új válasz megjelenítése -->
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

                <!-- Hibaüzenet megjelenítése -->
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Javasolt kérdések -->
            <div class="p-3 border-top bg-light">
                <div class="suggestions mb-3">
                    <?php
                    $suggestions = [
                        'Mi a mai forgalom?',
                        'Melyek a legkelendőbb termékek?',
                        'Van-e alacsony készletű termék?',
                        'Mennyi a mai visszáru?',
                        'Hány rendelés van ma?'
                    ];
                    foreach ($suggestions as $suggestion): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="question" value="<?php echo htmlspecialchars($suggestion); ?>">
                            <button type="submit" class="suggestion-chip">
                                <?php echo htmlspecialchars($suggestion); ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>

                <!-- Kérdés beviteli mező -->
                <form method="POST" class="d-flex gap-3">
                    <input type="text" name="question" class="form-control" 
                           placeholder="Írja be kérdését..." required
                           autocomplete="off">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fas fa-paper-plane me-2"></i>Küldés
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script>
        // Üzenetek görgetése az aljára
        function scrollToBottom() {
            const chatMessages = document.getElementById('chatMessages');
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // Oldal betöltésekor és új üzenet érkezésekor görgessünk az aljára
        document.addEventListener('DOMContentLoaded', scrollToBottom);
        window.onload = scrollToBottom;
    </script>
</body>
</html>