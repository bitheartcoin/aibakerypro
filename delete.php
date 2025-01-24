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

if ($transaction_id) {
    $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
    if ($stmt->execute([$transaction_id])) {
        $_SESSION['success'] = 'Tranzakció sikeresen törölve!';
    } else {
        $_SESSION['error'] = 'Hiba történt a törlés során!';
    }
}

header('Location: index.php?tab=transactions');
exit;