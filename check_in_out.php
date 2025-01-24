<?php
require_once 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Check-in funkció
if (isset($_POST['check_in'])) {
    // Ellenőrizzük, nincs-e már aktív munkaidő
    $stmt = $pdo->prepare("SELECT * FROM work_hours WHERE user_id = ? AND check_out IS NULL");
    $stmt->execute([$_SESSION['user_id']]);
    $active_work = $stmt->fetch();

    if (!$active_work) {
        $stmt = $pdo->prepare("INSERT INTO work_hours (user_id, check_in) VALUES (?, NOW())");
        $stmt->execute([$_SESSION['user_id']]);
        $_SESSION['success'] = 'Sikeres bejelentkezés a munkaidőbe!';
    } else {
        $_SESSION['error'] = 'Már van aktív munkaidő!';
    }
}

// Check-out funkció
if (isset($_POST['check_out'])) {
    try {
        // Aktív munkaidő keresése
        $stmt = $pdo->prepare("
            SELECT wh.*, u.hourly_rate 
            FROM work_hours wh
            JOIN users u ON wh.user_id = u.id
            WHERE wh.user_id = ? AND wh.check_out IS NULL
            ORDER BY wh.check_in DESC LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $active_work = $stmt->fetch();

        if ($active_work) {
            $pdo->beginTransaction();

            // Munkaidő lezárása
            $check_out = date('Y-m-d H:i:s');
            $total_hours = (strtotime($check_out) - strtotime($active_work['check_in'])) / 3600;

            $stmt = $pdo->prepare("
                UPDATE work_hours 
                SET check_out = ?,
                    total_hours = ?,
                    amount_earned = ?
                WHERE id = ?
            ");

            // Fizetés számítása
            $amount_earned = $total_hours * $active_work['hourly_rate'];

            $stmt->execute([
                $check_out,
                $total_hours,
                $amount_earned,
                $active_work['id']
            ]);

            $pdo->commit();
            $_SESSION['success'] = 'Sikeres kijelentkezés a munkaidőből!';
        } else {
            $_SESSION['error'] = 'Nincs aktív munkaidő!';
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        $_SESSION['error'] = 'Hiba történt a munkaidő lezárásakor!';
    }
}

// Visszairányítás
header('Location: ' . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php'));
exit;