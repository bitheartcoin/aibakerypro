<?php
require_once 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Sofőr jogosultság ellenőrzése
$stmt = $pdo->prepare("SELECT role, username FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user['role'] !== 'driver') {
    header('Location: index.php');
    exit;
}

// Dátum szűrés
$date = $_GET['date'] ?? date('Y-m-d');

// Sofőr azonosítása a username alapján
$stmt = $pdo->prepare("SELECT code FROM drivers WHERE name LIKE ?");
$stmt->execute(['%' . $user['username'] . '%']);
$driver = $stmt->fetch();

if (!$driver) {
    $_SESSION['error'] = 'Sofőr kód nem található!';
    header('Location: index.php');
    exit;
}

// Ha XLS exportot kértek
if (isset($_GET['format']) && $_GET['format'] == 'xls') {
    require 'vendor/autoload.php'; // PhpSpreadsheet használata

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Fejléc
    $sheet->setCellValue('A1', 'Szállítólevél');
    $sheet->setCellValue('A2', 'Dátum: ' . $date);
    $sheet->setCellValue('A3', 'Sofőr: ' . $user['username']);

    // Fejléc formázása
    $sheet->mergeCells('A1:E1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    // Oszlop fejlécek
    $sheet->setCellValue('A5', 'Üzlet');
    $sheet->setCellValue('B5', 'Termék');
    $sheet->setCellValue('C5', 'Mennyiség');
    $sheet->setCellValue('D5', 'Visszáru');
    $sheet->setCellValue('E5', 'Eladva');

    // Adatok lekérése
    $query = "
        SELECT 
            s.name as shop_name,
            p.name as product_name,
            d.quantity as delivered_quantity,
            COALESCE(r.quantity, 0) as returned_quantity,
            d.quantity - COALESCE(r.quantity, 0) as sold_quantity
        FROM deliveries d
        JOIN shops s ON d.shop_id = s.id
        JOIN products p ON d.product_id = p.id
        LEFT JOIN returns r ON d.shop_id = r.shop_id 
            AND d.product_id = r.product_id 
            AND d.delivery_date = r.return_date
        WHERE d.driver_code = ? 
        AND DATE(d.delivery_date) = ?
        ORDER BY s.name, p.name";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$driver['code'], $date]);
    $deliveries = $stmt->fetchAll();

    // Adatok beírása
    $row = 6;
    foreach ($deliveries as $delivery) {
        $sheet->setCellValue('A'.$row, $delivery['shop_name']);
        $sheet->setCellValue('B'.$row, $delivery['product_name']);
        $sheet->setCellValue('C'.$row, $delivery['delivered_quantity']);
        $sheet->setCellValue('D'.$row, $delivery['returned_quantity']);
        $sheet->setCellValue('E'.$row, $delivery['sold_quantity']);
        $row++;
    }

    // Excel fájl létrehozása és letöltése
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="szallitolevel_'.$date.'.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

// Ha MDB exportot kértek
if (isset($_GET['format']) && $_GET['format'] == 'mdb') {
    // MDB export logika ide jönne
    // Ez külön könyvtárat igényel az MDB fájlok kezeléséhez
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Szállítólevelek - <?php echo htmlspecialchars($user['username']); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Szállítólevelek</h5>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Vissza
                </a>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">Dátum</label>
                        <input type="date" name="date" class="form-control" value="<?php echo $date; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Formátum</label>
                        <select name="format" class="form-select">
                            <option value="xls">Excel (.xlsx)</option>
                            <option value="mdb">Access (.mdb)</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-download me-2"></i>Letöltés
                        </button>
                    </div>
                </form>

                <!-- Előnézet táblázat -->
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Üzlet</th>
                                <th>Termék</th>
                                <th>Mennyiség</th>
                                <th>Visszáru</th>
                                <th>Eladva</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->prepare($query);
                            $stmt->execute([$driver['code'], $date]);
                            while ($row = $stmt->fetch()): 
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['shop_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                <td><?php echo $row['delivered_quantity']; ?></td>
                                <td><?php echo $row['returned_quantity']; ?></td>
                                <td><?php echo $row['sold_quantity']; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>