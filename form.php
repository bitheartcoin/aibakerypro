<?php
require_once 'config.php';
session_start();

if (!isset($_GET['shop_id'])) {
    header('Location: index.php');
    exit;
}

$shop_id = (int)$_GET['shop_id'];
$stmt = $pdo->prepare("SELECT * FROM shops WHERE id = ?");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
    header('Location: index.php');
    exit;
}

// Termékek lekérése a visszáru formhoz
$stmt = $pdo->query("SELECT * FROM products WHERE active = 1 ORDER BY name");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        if (isset($_POST['income'])) {
            // Bevétel feldolgozása
            $amount = $_POST['amount'];
            $payment_type = $_POST['payment_type'];
            $created_at = $_POST['created_at'] ?? date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO transactions (shop_id, type, payment_type, amount, created_at) 
                                  VALUES (?, 'income', ?, ?, ?)");
            $stmt->execute([$shop_id, $payment_type, $amount, $created_at]);

        } elseif (isset($_POST['expense'])) {
            // Kiadás feldolgozása
            foreach ($_POST['expense_description'] as $key => $description) {
                if (!empty($description) && !empty($_POST['expense_amount'][$key])) {
                    $stmt = $pdo->prepare("INSERT INTO transactions (shop_id, type, amount, description, category, created_at) 
                                         VALUES (?, 'expense', ?, ?, ?, ?)");
                    $stmt->execute([
                        $shop_id,
                        $_POST['expense_amount'][$key],
                        $description,
                        $_POST['category'],
                        $_POST['created_at']
                    ]);
                }
            }
        } elseif (isset($_POST['returns'])) {
            // Visszáru feldolgozása
            $return_date = $_POST['return_date'];
            
            foreach ($_POST['return_quantity'] as $product_id => $quantity) {
                if ($quantity > 0) {
                    $stmt = $pdo->prepare("INSERT INTO returns (shop_id, product_id, quantity, return_date) 
                                         VALUES (?, ?, ?, ?)");
                    $stmt->execute([$shop_id, $product_id, $quantity, $return_date]);
                }
            }
        }

        $pdo->commit();
        $_SESSION['success'] = 'Adatok sikeresen rögzítve!';
        header('Location: form.php?shop_id=' . $shop_id);
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Hiba történt a mentés során!';
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tranzakció rögzítése - <?php echo htmlspecialchars($shop['name']); ?></title>
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

        .section-title {
            color: var(--primary-color);
            font-size: 1.5rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid rgba(44, 62, 80, 0.1);
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

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #219a52);
            border: none;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #c0392b);
            border: none;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #f39c12);
            border: none;
            color: white;
        }

        .btn-light {
            background: white;
            color: var(--primary-color);
            border: 2px solid transparent;
        }

        .btn-light:hover {
            background: var(--light-color);
            color: var(--primary-color);
        }

        .form-check {
            margin-bottom: 0.5rem;
        }

        .form-check-input:checked {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }

        .returns-table {
            background: white;
            border-radius: 8px;
        }

        .returns-table th {
            background-color: #f8f9fa;
            padding: 1rem;
            font-weight: 500;
        }

        .returns-table td {
            padding: 1rem;
            vertical-align: middle;
        }

        .quantity-input {
            max-width: 120px;
            margin: 0 auto;
        }

        .alert {
            border-radius: 10px;
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .alert-success {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }

        .alert-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }

        .tab-content {
            background: white;
            border-radius: 0 0 15px 15px;
            padding: 2rem;
        }

        .nav-tabs {
            border: none;
            margin-bottom: 0;
        }

        .nav-tabs .nav-link {
            border: none;
            color: var(--dark-color);
            padding: 1rem 2rem;
            border-radius: 15px 15px 0 0;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-tabs .nav-link.active {
            background: white;
            color: var(--accent-color);
        }

        .nav-tabs .nav-link:not(.active):hover {
            background: rgba(255, 255, 255, 0.1);
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <h1 class="mb-0">
                    <i class="fas fa-store me-3"></i>
                    <?php echo htmlspecialchars($shop['name']); ?>
                </h1>
                <a href="index.php" class="btn btn-light">
                    <i class="fas fa-home me-2"></i>
                    Vissza a főoldalra
                </a>
            </div>
        </div>
    </header>

    <div class="container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show animate-fade-in" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php 
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show animate-fade-in" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php 
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Bevételi form -->
            <div class="col-md-6 mb-4">
                <div class="content-card">
                    <div class="card-body p-4">
                        <h4 class="section-title">
                            <i class="fas fa-plus-circle me-2 text-success"></i>
                            Bevétel rögzítése
                        </h4>
                        <form method="POST" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label class="form-label">Dátum és idő</label>
                                <input type="datetime-local" name="created_at" class="form-control" 
                                       value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Bevétel típusa</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_type" 
                                           id="kassza1" value="kassza1" required>
                                    <label class="form-check-label" for="kassza1">Kassza 1</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_type" 
                                           id="kassza2" value="kassza2">
                                    <label class="form-check-label" for="kassza2">Kassza 2</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="payment_type" 
                                           id="kartyasFizetes" value="kartyasFizetes">
                                    <label class="form-check-label" for="kartyasFizetes">Kártyás fizetés</label>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Összeg</label>
                                <input type="number" name="amount" class="form-control" required step="0.01">
                            </div>

                            <button type="submit" name="income" value="1" class="btn btn-success w-100">
                                <i class="fas fa-save me-2"></i>
                                Bevétel rögzítése
                            </button>
                        </form>
                    </div>
                </div>
            </div>

<!-- Kiadási form -->
<div class="col-md-6 mb-4">
    <div class="content-card">
        <div class="card-body p-4">
            <h4 class="section-title">
                <i class="fas fa-minus-circle me-2 text-danger"></i>
                Kiadás rögzítése
            </h4>
            <form method="POST" class="needs-validation" novalidate>
                <div class="mb-3">
                    <label class="form-label">Dátum és idő</label>
                    <input type="datetime-local" name="created_at" class="form-control"
                           value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Kategória</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="category" 
                               id="tankolas" value="tankolas" required>
                        <label class="form-check-label" for="tankolas">Tankolás</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="category" 
                               id="csomagoloanyag" value="csomagoloanyag">
                        <label class="form-check-label" for="csomagoloanyag">Csomagolóanyag</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="category" 
                               id="fizeteseloleg" value="fizeteseloleg">
                        <label class="form-check-label" for="fizeteseloleg">Fizetéselőleg</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="category" 
                               id="tisztitoszerek" value="tisztitoszerek">
                        <label class="form-check-label" for="tisztitoszerek">Tisztítószerek</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="category" 
                               id="egyeb" value="egyeb">
                        <label class="form-check-label" for="egyeb">Egyéb</label>
                    </div>
                </div>

                <div id="expense-items">
                    <div class="expense-item mb-3">
                        <div class="row">
                            <div class="col-md-8">
                                <label class="form-label">Leírás</label>
                                <input type="text" name="expense_description[]" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Összeg</label>
                                <input type="number" name="expense_amount[]" class="form-control" 
                                       required step="0.01">
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" class="btn btn-secondary mb-3" onclick="addExpenseItem()">
                    <i class="fas fa-plus me-2"></i>
                    Új kiadási tétel
                </button>

                <button type="submit" name="expense" value="1" class="btn btn-danger w-100">
                    <i class="fas fa-save me-2"></i>
                    Kiadás rögzítése
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Visszáru form -->
<div class="col-12">
    <div class="content-card">
        <div class="card-body p-4">
            <h4 class="section-title">
                <i class="fas fa-box me-2 text-warning"></i>
                Napi maradék rögzítése
            </h4>
            <form method="POST" class="needs-validation" novalidate>
                <div class="mb-4">
                    <label class="form-label">Dátum</label>
                    <input type="date" name="return_date" class="form-control"
                           value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="table-responsive">
                    <table class="table returns-table">
                        <thead>
                            <tr>
                                <th style="width: 60%">Termék neve</th>
                                <th style="width: 40%">Maradék mennyiség (db)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                            <tr class="product-row">
                                <td>
                                    <label class="form-label mb-0" for="return_<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </label>
                                </td>
                                <td>
                                    <input type="number" 
                                           class="form-control quantity-input"
                                           id="return_<?php echo $product['id']; ?>"
                                           name="return_quantity[<?php echo $product['id']; ?>]"
                                           value="0"
                                           min="0">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" name="returns" value="1" class="btn btn-warning">
                    <i class="fas fa-save me-2"></i>
                    Maradék rögzítése
                </button>
            </form>
        </div>
    </div>
</div>
</div>
</div>

<script>
function addExpenseItem() {
    const container = document.getElementById('expense-items');
    const newItem = document.createElement('div');
    newItem.className = 'expense-item mb-3';
    newItem.innerHTML = `
        <div class="row">
            <div class="col-md-8">
                <label class="form-label">Leírás</label>
                <input type="text" name="expense_description[]" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Összeg</label>
                <input type="number" name="expense_amount[]" class="form-control" required step="0.01">
            </div>
        </div>
    `;
    container.appendChild(newItem);
}

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
});
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>