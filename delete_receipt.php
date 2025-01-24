<?php
// admin/delete_receipt.php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva']));
}

// Admin jogosultság ellenőrzése
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user['role'] !== 'admin') {
    die(json_encode(['success' => false, 'message' => 'Nincs megfelelő jogosultság']));
}

$receipt_id = $_POST['id'] ?? 0;

try {
    $pdo->beginTransaction();

    // Tranzakció törlése
    $stmt = $pdo->prepare("
        SELECT shop_id, total_amount, payment_type
        FROM receipts 
        WHERE id = ?
    ");
    $stmt->execute([$receipt_id]);
    $receipt = $stmt->fetch();

    if ($receipt) {
        // Tranzakció törlése
        $stmt = $pdo->prepare("
            DELETE FROM transactions 
            WHERE shop_id = ? 
            AND type = 'income' 
            AND payment_type = ? 
            AND amount = ?
            AND DATE(created_at) = DATE(
                (SELECT created_at FROM receipts WHERE id = ?)
            )
        ");
        $stmt->execute([
            $receipt['shop_id'],
            $receipt['payment_type'],
            $receipt['total_amount'],
            $receipt_id
        ]);
    }

    // Nyugta tételek törlése
    $stmt = $pdo->prepare("DELETE FROM receipt_items WHERE receipt_id = ?");
    $stmt->execute([$receipt_id]);

    // Nyugta törlése
    $stmt = $pdo->prepare("DELETE FROM receipts WHERE id = ?");
    $stmt->execute([$receipt_id]);

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Hiba történt a törlés során']);
}