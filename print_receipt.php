<?php
// admin/print_receipt.php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id'])) die('Hozzáférés megtagadva');

$receipt_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT r.*, s.name as shop_name, s.address as shop_address,
           u.username as cashier_name
    FROM receipts r
    JOIN shops s ON r.shop_id = s.id
    JOIN users u ON r.created_by = u.id
    WHERE r.id = ?
");
$stmt->execute([$receipt_id]);
$receipt = $stmt->fetch();

if (!$receipt) die('Nyugta nem található');

$stmt = $pdo->prepare("
    SELECT ri.*, p.name as product_name, p.unit
    FROM receipt_items ri
    JOIN products p ON ri.product_id = p.id
    WHERE ri.receipt_id = ?
");
$stmt->execute([$receipt_id]);
$items = $stmt->fetchAll();

// ÁFA összesítés
$vat_totals = [];
foreach ($items as $item) {
    $vat_rate = $item['vat_rate'];
    if (!isset($vat_totals[$vat_rate])) {
        $vat_totals[$vat_rate] = [
            'net' => 0,
            'vat' => 0,
            'gross' => 0
        ];
    }
    $gross = $item['total_price'];
    $net = $gross / (1 + $vat_rate/100);
    $vat = $gross - $net;
    
    $vat_totals[$vat_rate]['net'] += $net;
    $vat_totals[$vat_rate]['vat'] += $vat;
    $vat_totals[$vat_rate]['gross'] += $gross;
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>Nyugta - <?php echo $receipt['receipt_number']; ?></title>
    <style>
        @page { margin: 10mm; }
        @media print {
            .no-print { display: none; }
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.3;
            margin: 0;
            padding: 10mm;
        }
        .header { text-align: center; margin-bottom: 5mm; }
        .shop-name { font-size: 14pt; font-weight: bold; }
        .divider { border-top: 1px dashed #000; margin: 3mm 0; }
        .items { width: 100%; border-collapse: collapse; }
        .items th { border-bottom: 1px solid #000; }
        .items td, .items th { padding: 1mm; text-align: left; }
        .amount-col { text-align: right; }
        .total { font-weight: bold; margin-top: 3mm; }
        .vat-summary { margin-top: 5mm; font-size: 9pt; }
        .footer { margin-top: 10mm; font-size: 9pt; text-align: center; }
        .btn-print {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="btn-print no-print">Nyomtatás</button>

    <div class="header">
        <div class="shop-name"><?php echo htmlspecialchars($receipt['shop_name']); ?></div>
        <div><?php echo htmlspecialchars($receipt['shop_address']); ?></div>
    </div>

    <div class="divider"></div>

    <div>
        Nyugtaszám: <?php echo htmlspecialchars($receipt['receipt_number']); ?><br>
        Dátum: <?php echo date('Y.m.d H:i:s', strtotime($receipt['created_at'])); ?><br>
        Pénztáros: <?php echo htmlspecialchars($receipt['cashier_name']); ?><br>
        Fizetési mód: <?php 
            $modes = ['kassza1' => 'Kassza 1', 'kassza2' => 'Kassza 2', 'kartyasFizetes' => 'Bankkártya'];
            echo $modes[$receipt['payment_type']];
        ?>
    </div>

    <div class="divider"></div>

    <table class="items">
        <tr>
            <th>Megnevezés</th>
            <th>Menny.</th>
            <th class="amount-col">Egys.ár</th>
            <th class="amount-col">Összeg</th>
        </tr>
        <?php foreach ($items as $item): ?>
        <tr>
            <td><?php echo htmlspecialchars($item['product_name']); ?></td>
            <td><?php echo $item['quantity'] . ' ' . $item['unit']; ?></td>
            <td class="amount-col"><?php echo number_format($item['unit_price'], 0, ',', ' '); ?></td>
            <td class="amount-col"><?php echo number_format($item['total_price'], 0, ',', ' '); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <div class="divider"></div>

    <div class="total">
        Összesen fizetendő: <?php echo number_format($receipt['total_amount'], 0, ',', ' '); ?> Ft
    </div>

    <div class="vat-summary">
        ÁFA összesítés:<br>
        <?php foreach ($vat_totals as $rate => $amounts): ?>
        <?php echo $rate; ?>% ÁFA tartalom:<br>
        Nettó: <?php echo number_format($amounts['net'], 0, ',', ' '); ?> Ft<br>
        ÁFA: <?php echo number_format($amounts['vat'], 0, ',', ' '); ?> Ft<br>
        Bruttó: <?php echo number_format($amounts['gross'], 0, ',', ' '); ?> Ft<br>
        <?php endforeach; ?>
    </div>

    <div class="footer">
        Köszönjük a vásárlást!<br>
        A nyugta a rendszerben elektronikusan tárolva.
    </div>

    <script>
    if (location.href.includes('print=true')) {
        window.print();
    }
    </script>
</body>
</html>