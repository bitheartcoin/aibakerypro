<?php 
// admin/pos.php
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

$shop_id = $_GET['shop_id'] ?? null;
$date = $_GET['date'] ?? date('Y-m-d');

// Napi forgalom lekérése
$stmt = $pdo->prepare("
    SELECT 
        payment_type,
        COUNT(*) as receipt_count,
        SUM(total_amount) as total_amount
    FROM receipts
    WHERE shop_id = ?
    AND DATE(created_at) = ?
    GROUP BY payment_type
");
$stmt->execute([$shop_id, $date]);
$daily_totals = $stmt->fetchAll();

// ÁFA jelentés
$stmt = $pdo->prepare("
    SELECT 
        ri.vat_rate,
        SUM(ri.total_price / (1 + ri.vat_rate/100)) as net_amount,
        SUM(ri.total_price) as gross_amount,
        SUM(ri.total_price - (ri.total_price / (1 + ri.vat_rate/100))) as vat_amount
    FROM receipt_items ri
    JOIN receipts r ON ri.receipt_id = r.id
    WHERE r.shop_id = ?
    AND DATE(r.created_at) = ?
    GROUP BY ri.vat_rate
");
$stmt->execute([$shop_id, $date]);
$vat_report = $stmt->fetchAll();

// Termékenkénti forgalom
$stmt = $pdo->prepare("
    SELECT 
        p.name,
        p.price,
        p.vat_rate,
        SUM(ri.quantity) as sold_quantity,
        SUM(ri.total_price) as total_income
    FROM receipt_items ri
    JOIN receipts r ON ri.receipt_id = r.id
    JOIN products p ON ri.product_id = p.id
    WHERE r.shop_id = ?
    AND DATE(r.created_at) = ?
    GROUP BY p.id, p.name, p.price, p.vat_rate
    ORDER BY total_income DESC
");
$stmt->execute([$shop_id, $date]);
$product_sales = $stmt->fetchAll();

// Nyugták listája
$stmt = $pdo->prepare("
    SELECT 
        r.*,
        u.username as cashier_name,
        COUNT(ri.id) as item_count
    FROM receipts r
    JOIN users u ON r.created_by = u.id
    LEFT JOIN receipt_items ri ON r.id = ri.receipt_id
    WHERE r.shop_id = ?
    AND DATE(r.created_at) = ?
    GROUP BY r.id
    ORDER BY r.created_at DESC
");
$stmt->execute([$shop_id, $date]);
$receipts = $stmt->fetchAll();

// Üzletek lekérése a szűrőhöz
$shops = $pdo->query("SELECT * FROM shops ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nyugták Kezelése - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <!-- Szűrő -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Üzlet</label>
                        <select name="shop_id" class="form-select" required>
                            <option value="">Válasszon üzletet...</option>
                            <?php foreach ($shops as $shop): ?>
                                <option value="<?php echo $shop['id']; ?>" 
                                        <?php echo $shop_id == $shop['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($shop['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Dátum</label>
                        <input type="date" name="date" class="form-control" 
                               value="<?php echo $date; ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Szűrés
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($shop_id): ?>
        <div class="row">
            <!-- Napi összesítő -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">Napi forgalom</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Fizetési mód</th>
                                        <th>Nyugták száma</th>
                                        <th>Összeg</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total = 0;
                                    foreach ($daily_totals as $row): 
                                        $total += $row['total_amount'];
                                    ?>
                                    <tr>
                                        <td>
                                            <?php
                                            $labels = [
                                                'kassza1' => 'Kassza 1',
                                                'kassza2' => 'Kassza 2',
                                                'kartyasFizetes' => 'Bankkártya'
                                            ];
                                            echo $labels[$row['payment_type']];
                                            ?>
                                        </td>
                                        <td><?php echo $row['receipt_count']; ?> db</td>
                                        <td><?php echo number_format($row['total_amount'], 0, ',', ' '); ?> Ft</td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-info">
                                        <td colspan="2"><strong>Összesen</strong></td>
                                        <td><strong><?php echo number_format($total, 0, ',', ' '); ?> Ft</strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ÁFA jelentés -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">ÁFA jelentés</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ÁFA kulcs</th>
                                        <th>Nettó</th>
                                        <th>ÁFA</th>
                                        <th>Bruttó</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vat_report as $row): ?>
                                    <tr>
                                        <td><?php echo $row['vat_rate']; ?>%</td>
                                        <td><?php echo number_format($row['net_amount'], 0, ',', ' '); ?> Ft</td>
                                        <td><?php echo number_format($row['vat_amount'], 0, ',', ' '); ?> Ft</td>
                                        <td><?php echo number_format($row['gross_amount'], 0, ',', ' '); ?> Ft</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Termékenkénti forgalom -->
            <div class="col-md-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Termékenkénti forgalom</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table"><thead>
                                    <tr>
                                        <th>Termék</th>
                                        <th>Egységár</th>
                                        <th>ÁFA</th>
                                        <th>Eladott mennyiség</th>
                                        <th>Forgalom</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($product_sales as $product): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td><?php echo number_format($product['price'], 0, ',', ' '); ?> Ft</td>
                                        <td><?php echo $product['vat_rate']; ?>%</td>
                                        <td><?php echo $product['sold_quantity']; ?> db</td>
                                        <td><?php echo number_format($product['total_income'], 0, ',', ' '); ?> Ft</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nyugták listája -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Nyugták listája</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Nyugtaszám</th>
                                        <th>Időpont</th>
                                        <th>Pénztáros</th>
                                        <th>Fizetési mód</th>
                                        <th>Tételek</th>
                                        <th>Végösszeg</th>
                                        <th>Műveletek</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($receipts as $receipt): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($receipt['receipt_number']); ?></td>
                                        <td><?php echo date('H:i:s', strtotime($receipt['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($receipt['cashier_name']); ?></td>
                                        <td>
                                            <?php
                                            $labels = [
                                                'kassza1' => 'Kassza 1',
                                                'kassza2' => 'Kassza 2',
                                                'kartyasFizetes' => 'Bankkártya'
                                            ];
                                            echo $labels[$receipt['payment_type']];
                                            ?>
                                        </td>
                                        <td><?php echo $receipt['item_count']; ?> db</td>
                                        <td><?php echo number_format($receipt['total_amount'], 0, ',', ' '); ?> Ft</td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-info" 
                                                    onclick="showReceiptDetails(<?php echo $receipt['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-warning"
                                                    onclick="printReceipt(<?php echo $receipt['id']; ?>)">
                                                <i class="fas fa-print"></i>
                                            </button>
                                            <?php if ($user['role'] == 'admin'): ?>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                    onclick="deleteReceipt(<?php echo $receipt['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Nyugta részletek modal -->
    <div class="modal fade" id="receiptModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Nyugta részletei</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="receiptDetails">
                    <!-- AJAX által betöltve -->
                </div>
            </div>
        </div>
    </div>

    <script>
    // Nyugta részletek betöltése
    function showReceiptDetails(receiptId) {
        fetch('get_receipt.php?id=' + receiptId)
            .then(response => response.text())
            .then(html => {
                document.getElementById('receiptDetails').innerHTML = html;
                new bootstrap.Modal(document.getElementById('receiptModal')).show();
            });
    }

    // Nyugta nyomtatása
    function printReceipt(receiptId) {
        window.open('print_receipt.php?id=' + receiptId, '_blank');
    }

    // Nyugta törlése
    function deleteReceipt(receiptId) {
        if (confirm('Biztosan törli ezt a nyugtát?')) {
            fetch('delete_receipt.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'id=' + receiptId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Hiba történt a törlés során!');
                }
            });
        }
    }
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>