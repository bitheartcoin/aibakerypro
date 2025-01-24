<?php
// admin/get_receipt.php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die('Hozzáférés megtagadva');
}

$receipt_id = $_GET['id'] ?? 0;

// Nyugta részletek lekérése
$stmt = $pdo->prepare("
    SELECT 
        r.*,
        s.name as shop_name,
        u.username as cashier_name
    FROM receipts r
    JOIN shops s ON r.shop_id = s.id
    JOIN users u ON r.created_by = u.id
    WHERE r.id = ?
");
$stmt->execute([$receipt_id]);
$receipt = $stmt->fetch();

if (!$receipt) {
    die('Nyugta nem található');
}

// Tételek lekérése
$stmt = $pdo->prepare("
    SELECT 
        ri.*,
        p.name as product_name,
        p.unit
    FROM receipt_items ri
    JOIN products p ON ri.product_id = p.id
    WHERE ri.receipt_id = ?
    ORDER BY ri.id
");
$stmt->execute([$receipt_id]);
$items = $stmt->fetchAll();
?>

<div class="receipt-details">
    <div class="mb-3">
        <h6>Nyugta adatai</h6>
        <table class="table table-sm">
            <tr>
                <td><strong>Nyugtaszám:</strong></td>
                <td><?php echo htmlspecialchars($receipt['receipt_number']); ?></td>
            </tr>
            <tr>
                <td><strong>Üzlet:</strong></td>
                <td><?php echo htmlspecialchars($receipt['shop_name']); ?></td>
            </tr>
            <tr>
                <td><strong>Dátum:</strong></td>
                <td><?php echo date('Y.m.d H:i:s', strtotime($receipt['created_at'])); ?></td>
            </tr>
            <tr>
                <td><strong>Pénztáros:</strong></td>
                <td><?php echo htmlspecialchars($receipt['cashier_name']); ?></td>
            </tr>
            <tr>
                <td><strong>Fizetési mód:</strong></td>
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
            </tr>
        </table>
    </div>

    <div>
        <h6>Tételek</h6>
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Termék</th>
                    <th>Mennyiség</th>
                    <th>Egységár</th>
                    <th>ÁFA</th>
                    <th>Részösszeg</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <td><?php echo $item['quantity'] . ' ' . $item['unit']; ?></td>
                    <td><?php echo number_format($item['unit_price'], 0, ',', ' '); ?> Ft</td>
                    <td><?php echo $item['vat_rate']; ?>%</td>
                    <td><?php echo number_format($item['total_price'], 0, ',', ' '); ?> Ft</td>
                </tr>
                <?php endforeach; ?>
                <tr class="table-info">
                    <td colspan="4"><strong>Végösszeg</strong></td>
                    <td><strong><?php echo number_format($receipt['total_amount'], 0, ',', ' '); ?> Ft</strong></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>