<?php
require_once '../config.php';
session_start();

// Bejelentkezés ellenőrzése
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Jogosultság ellenőrzése (csak admin és pék)
$stmt = $pdo->prepare("SELECT role, user_type FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user['role'] !== 'admin' && $user['user_type'] !== 'baker') {
    header('Location: ../index.php');
    exit;
}

// Dokumentum feldolgozás
function processProductionDocument($file_path) {
    $file_extension = pathinfo($file_path, PATHINFO_EXTENSION);
    
    try {
        switch ($file_extension) {
            case 'json':
                $content = file_get_contents($file_path);
                return json_decode($content, true);
            
            case 'csv':
                $csv_data = array_map('str_getcsv', file($file_path));
                $headers = array_shift($csv_data);
                $processed_data = [];
                
                foreach ($csv_data as $row) {
                    $row_data = array_combine($headers, $row);
                    $processed_data['phases'][] = $row_data;
                }
                $processed_data['recipe_name'] = $processed_data['phases'][0]['recipe_name'];
                return $processed_data;
            
            case 'txt':
                $content = file_get_contents($file_path);
                return parseTextDocument($content);
            
            default:
                throw new Exception('Nem támogatott fájltípus');
        }
    } catch (Exception $e) {
        throw new Exception('Dokumentum feldolgozási hiba: ' . $e->getMessage());
    }
}

// Szöveges dokumentum feldolgozása
function parseTextDocument($content) {
    $lines = explode("\n", $content);
    $recipe_data = [
        'recipe_name' => '',
        'phases' => []
    ];
    
    $current_phase = null;
    foreach ($lines as $line) {
        $line = trim($line);
        
        if (strpos($line, 'Recept neve:') !== false) {
            $recipe_data['recipe_name'] = trim(str_replace('Recept neve:', '', $line));
        }
        
        if (preg_match('/(\d+)\.\s*(.+)/u', $line, $matches)) {
            if ($current_phase) {
                $recipe_data['phases'][] = $current_phase;
            }
            
            $current_phase = [
                'name' => trim($matches[2]),
                'duration_minutes' => null,
                'target_temperature' => null,
                'target_humidity' => null,
                'instructions' => ''
            ];
        }
        
        if (strpos($line, 'Időtartam:') !== false) {
            $current_phase['duration_minutes'] = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
        }
        
        if (strpos($line, 'Célhőmérséklet:') !== false) {
            $current_phase['target_temperature'] = (float)filter_var($line, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        }
        
        if (strpos($line, 'Páratartalom:') !== false) {
            $current_phase['target_humidity'] = (float)filter_var($line, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        }
        
        if (strpos($line, 'Utasítások:') !== false) {
            $current_phase['instructions'] = trim(str_replace('Utasítások:', '', $line));
        }
    }
    
    if ($current_phase) {
        $recipe_data['phases'][] = $current_phase;
    }
    
    return $recipe_data;
}

// Dokumentum feltöltés kezelése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['production_process'])) {
    $upload_dir = '../uploads/production_processes/';
    $allowed_types = ['txt', 'csv', 'json'];

    $file_extension = strtolower(pathinfo($_FILES['production_process']['name'], PATHINFO_EXTENSION));
    
    if (in_array($file_extension, $allowed_types)) {
        $new_filename = 'production_process_' . uniqid() . '.' . $file_extension;
        $upload_path = $upload_dir . $new_filename;

        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        if (move_uploaded_file($_FILES['production_process']['tmp_name'], $upload_path)) {
            try {
                // Dokumentum feldolgozása
                $process_data = processProductionDocument($upload_path);

                // Recept elmentése, ha még nem létezik
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO recipes (product_id, name, base_quantity)
                    SELECT id, ?, 100 
                    FROM products 
                    WHERE name = ?
                ");
                $stmt->execute([$process_data['recipe_name'], $process_data['recipe_name']]);

                // Recept ID lekérése
                $stmt = $pdo->prepare("
                    SELECT id FROM recipes 
                    WHERE name = ?
                ");
                $stmt->execute([$process_data['recipe_name']]);
                $recipe = $stmt->fetch();

                // Gyártási fázisok mentése
                $phase_stmt = $pdo->prepare("
                    INSERT INTO production_phases (
                        recipe_id, 
                        phase_order, 
                        name, 
                        description, 
                        duration_minutes, 
                        target_temperature, 
                        target_humidity
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($process_data['phases'] as $index => $phase) {
                    $phase_stmt->execute([
                        $recipe['id'],
                        $index + 1,
                        $phase['name'],
                        $phase['instructions'] ?? null,
                        $phase['duration_minutes'] ?? null,
                        $phase['target_temperature'] ?? null,
                        $phase['target_humidity'] ?? null
                    ]);
                }

                // Dokumentum mentése az adatbázisba
                $stmt = $pdo->prepare("
                    INSERT INTO ai_documents (
                        user_id, 
                        document_type,
                        filename,
                        original_filename,
                        file_path,
                        created_at,
                        status
                    ) VALUES (?, ?, ?, ?, ?, NOW(), 'processed')
                ");
                
                $stmt->execute([
                    $_SESSION['user_id'],
                    'production_process',
                    $new_filename,
                    $_FILES['production_process']['name'],
                    $upload_path
                ]);

                $_SESSION['success'] = 'Gyártási folyamat sikeresen feldolgozva!';
            } catch (Exception $e) {
                unlink($upload_path);
                $_SESSION['error'] = 'Hiba történt a dokumentum feldolgozása során: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Hiba történt a fájl feltöltése közben!';
        }
    } else {
        $_SESSION['error'] = 'Nem támogatott fájltípus! Csak txt, csv és json fájlok engedélyezettek.';
    }
    
    header('Location: production.php');
    exit;
}

// Redirect direktben történő hivatkozás esetén
header('Location: production.php');
exit;