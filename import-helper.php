<?php
// Excel fájl feldolgozása
if (isset($_FILES['import_file'])) {
    require 'vendor/autoload.php';
    
    try {
        $inputFileName = $_FILES['import_file']['tmp_name'];
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($inputFileName);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Terméknevek beolvasása a B oszloptól
        $products = [];
        $col = 'B';
        while ($worksheet->getCell($col . "1")->getValue() != "") {
            $products[] = $worksheet->getCell($col . "1")->getValue();
            $col++;
        }
        
        // Mennyiségek beolvasása a 2. sorból
        $quantities = [];
        $col = 'B';
        foreach ($products as $product) {
            $quantities[] = (int)$worksheet->getCell($col . "2")->getValue();
            $col++;
        }

        // Dátum a fájl nevéből (2023.12.06.Szerda.xlsx formátum)
        $fileName = $_FILES['import_file']['name'];
        $dateParts = explode('.', $fileName);
        $deliveryDate = $dateParts[0] . '-' . $dateParts[1] . '-' . $dateParts[2];
        
        // Adatok mentése az adatbázisba
        $pdo->beginTransaction();
        
        try {
            // Termékek ID-jének lekérése név alapján
            $stmt = $pdo->prepare("SELECT id FROM products WHERE name = ? AND active = 1");
            
            foreach ($products as $index => $productName) {
                $stmt->execute([$productName]);
                $product = $stmt->fetch();
                
                if ($product && $quantities[$index] > 0) {
                    // Delivery rekord létrehozása
                    $deliveryStmt = $pdo->prepare("
                        INSERT INTO deliveries (shop_id, product_id, quantity, delivery_date) 
                        VALUES (?, ?, ?, ?)
                    ");
                    
                    $deliveryStmt->execute([
                        $_POST['shop_id'], // Az űrlapról jön a bolt ID
                        $product['id'],
                        $quantities[$index],
                        $deliveryDate
                    ]);
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = 'Szállítólevél sikeresen importálva!';
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = 'Hiba történt az importálás során: ' . $e->getMessage();
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>

<!-- Import form a HTML részben -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-file-import me-2"></i>Szállítólevél importálása</h5>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Üzlet kiválasztása</label>
                <select name="shop_id" class="form-select" required>
                    <option value="">Válasszon üzletet...</option>
                    <?php foreach ($shops as $shop): ?>
                        <option value="<?php echo $shop['id']; ?>">
                            <?php echo htmlspecialchars($shop['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Excel fájl</label>
                <input type="file" name="import_file" class="form-control" accept=".xlsx" required>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload me-2"></i>Importálás
                </button>
            </div>
        </form>
    </div>
</div>