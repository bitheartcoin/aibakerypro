<?php
require_once 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $document_type = $_POST['document_type'];
    $upload_dir = 'uploads/documents/';
    
    // Könyvtár létrehozása, ha nem létezik
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // A fájl kezelése
    $file = $_FILES['document'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];

    if (in_array($file_extension, $allowed_extensions)) {
        // Biztonságos, egyedi fájlnév generálása
        $new_filename = uniqid() . '_' . date('Ymd_His') . '.' . $file_extension;
        $upload_path = $upload_dir . $new_filename;

        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            try {
                // Dokumentum mentése az adatbázisba
                $stmt = $pdo->prepare("
                    INSERT INTO documents (
                        user_id, 
                        filename, 
                        file_path, 
                        document_type, 
                        original_filename
                    ) VALUES (?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $_SESSION['user_id'],
                    $new_filename,
                    $upload_path,
                    $document_type,
                    $file['name']
                ]);

                $_SESSION['success'] = 'Dokumentum sikeresen feltöltve!';
            } catch (Exception $e) {
                // Ha hiba történt az adatbázis művelet során, töröljük a feltöltött fájlt
                unlink($upload_path);
                $_SESSION['error'] = 'Hiba történt a dokumentum mentése közben!';
            }
        } else {
            $_SESSION['error'] = 'Hiba történt a fájl feltöltése közben!';
        }
    } else {
        $_SESSION['error'] = 'Nem megengedett fájltípus! Csak JPG, PNG és PDF fájlokat tölthet fel.';
    }
}

// Visszairányítás az előző oldalra
header('Location: ' . $_SERVER['HTTP_REFERER']);
exit;