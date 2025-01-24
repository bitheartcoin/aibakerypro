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

$transaction_id = $_GET['id'] ?? null;

if (!$transaction_id) {
    header('Location: index.php');
    exit;
}

// Tranzakció adatainak lekérése
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ?");
$stmt->execute([$transaction_id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    header('Location: index.php');
    exit;
}

// Form feldolgozása
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $amount = $_POST['amount'];
    $type = $_POST['type'];
    $payment_type = $_POST['payment_type'] ?? null;
    $category = $_POST['category'] ?? null;
    $description = $_POST['description'] ?? null;
    $created_at = $_POST['created_at'];

    $stmt = $pdo->prepare("
        UPDATE transactions 
        SET amount = ?, 
            type = ?, 
            payment_type = ?, 
            category = ?,
            description = ?,
            created_at = ?
        WHERE id = ?
    ");

    if ($stmt->execute([$amount, $type, $payment_type, $category, $description, $created_at, $transaction_id])) {
        $_SESSION['success'] = 'Tranzakció sikeresen módosítva!';
        header('Location: index.php?tab=transactions');
        exit;
    } else {
        $_SESSION['error'] = 'Hiba történt a módosítás során!';
    }
}

// Üzletek lekérése
$shops = $pdo->query("SELECT * FROM shops ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tranzakció szerkesztése - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Tranzakció szerkesztése</h5>
                <a href="index.php?tab=transactions" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Vissza
                </a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Dátum és idő</label>
                            <input type="datetime-local" name="created_at" class="form-control" 
                                   value="<?php echo date('Y-m-d\TH:i', strtotime($transaction['created_at'])); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Összeg</label>
                            <input type="number" name="amount" class="form-control" 
                                   value="<?php echo $transaction['amount']; ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Típus</label>
                            <select name="type" class="form-select" required>
                                <option value="income" <?php echo $transaction['type'] == 'income' ? 'selected' : ''; ?>>Bevétel</option>
                                <option value="expense" <?php echo $transaction['type'] == 'expense' ? 'selected' : ''; ?>>Kiadás</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="paymentTypeDiv" <?php echo $transaction['type'] == 'expense' ? 'style="display:none;"' : ''; ?>>
                            <label class="form-label">Fizetési mód</label>
                            <select name="payment_type" class="form-select">
                                <option value="kassza1" <?php echo $transaction['payment_type'] == 'kassza1' ? 'selected' : ''; ?>>Kassza 1</option>
                                <option value="kassza2" <?php echo $transaction['payment_type'] == 'kassza2' ? 'selected' : ''; ?>>Kassza 2</option>
                                <option value="kartyasFizetes" <?php echo $transaction['payment_type'] == 'kartyasFizetes' ? 'selected' : ''; ?>>Kártyás fizetés</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="categoryDiv" <?php echo $transaction['type'] == 'income' ? 'style="display:none;"' : ''; ?>>
                            <label class="form-label">Kategória</label>
                            <select name="category" class="form-select">
                                <option value="tankolas" <?php echo $transaction['category'] == 'tankolas' ? 'selected' : ''; ?>>Tankolás</option>
                                <option value="csomagoloanyag" <?php echo $transaction['category'] == 'csomagoloanyag' ? 'selected' : ''; ?>>Csomagolóanyag</option>
                                <option value="fizeteseloleg" <?php echo $transaction['category'] == 'fizeteseloleg' ? 'selected' : ''; ?>>Fizetéselőleg</option>
                                <option value="tisztitoszerek" <?php echo $transaction['category'] == 'tisztitoszerek' ? 'selected' : ''; ?>>Tisztítószerek</option>
                                <option value="egyeb" <?php echo $transaction['category'] == 'egyeb' ? 'selected' : ''; ?>>Egyéb</option>
                            </select>
                        </div>
                        <div class="col-12" id="descriptionDiv" <?php echo $transaction['type'] == 'income' ? 'style="display:none;"' : ''; ?>>
                            <label class="form-label">Leírás</label>
                            <input type="text" name="description" class="form-control" 
                                   value="<?php echo htmlspecialchars($transaction['description'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Mentés
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.querySelector('select[name="type"]').addEventListener('change', function(e) {
        const isExpense = e.target.value === 'expense';
        document.getElementById('paymentTypeDiv').style.display = isExpense ? 'none' : 'block';
        document.getElementById('categoryDiv').style.display = isExpense ? 'block' : 'none';
        document.getElementById('descriptionDiv').style.display = isExpense ? 'block' : 'none';
    });
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>