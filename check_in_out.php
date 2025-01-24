<?php
require_once 'config.php';
session_start();

// RFID azonosítás kezelése
if (isset($_POST['rfid_number'])) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE rfid_number = ? AND active = 1");
    $stmt->execute([$_POST['rfid_number']]);
    $rfid_user = $stmt->fetch();
    
    if ($rfid_user) {
        $_SESSION['user_id'] = $rfid_user['id'];
    } else {
        $_SESSION['error'] = 'Ismeretlen RFID azonosító!';
        header('Location: index.php');
        exit;
    }
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Ellenőrizzük a jelenlegi munkaidő státuszt
$stmt = $pdo->prepare("
    SELECT * FROM work_hours 
    WHERE user_id = ? AND check_out IS NULL
    ORDER BY check_in DESC LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$active_work = $stmt->fetch();

// AJAX kérés kezelése RFID ki/bejelentkezéshez
if (isset($_POST['rfid_action'])) {
    header('Content-Type: application/json');
    
    try {
        if (!$active_work) {
            // Bejelentkezés
            $stmt = $pdo->prepare("INSERT INTO work_hours (user_id, check_in) VALUES (?, NOW())");
            $stmt->execute([$_SESSION['user_id']]);
            echo json_encode(['success' => true, 'message' => 'Sikeres bejelentkezés!', 'action' => 'check_in']);
        } else {
            // Kijelentkezés
            $stmt = $pdo->prepare("
                SELECT wh.*, u.hourly_rate
                FROM work_hours wh
                JOIN users u ON wh.user_id = u.id
                WHERE wh.id = ?
            ");
            $stmt->execute([$active_work['id']]);
            $work_data = $stmt->fetch();

            $pdo->beginTransaction();

            $end_time = date('Y-m-d H:i:s');
            $total_hours = (strtotime($end_time) - strtotime($work_data['check_in'])) / 3600;
            $amount_earned = $total_hours * $work_data['hourly_rate'];

            $stmt = $pdo->prepare("
                UPDATE work_hours 
                SET check_out = ?,
                    total_hours = ?,
                    amount_earned = ?
                WHERE id = ?
            ");
            $stmt->execute([$end_time, $total_hours, $amount_earned, $active_work['id']]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Sikeres kijelentkezés!', 'action' => 'check_out']);
        }
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Hiba történt: ' . $e->getMessage()]);
        exit;
    }
}

// Normál form submit kezelése
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['check_in'])) {
            // Ellenőrizzük, nincs-e már aktív munkaidő
            if (!$active_work) {
                $stmt = $pdo->prepare("INSERT INTO work_hours (user_id, check_in) VALUES (?, NOW())");
                $stmt->execute([$_SESSION['user_id']]);
                $_SESSION['success'] = 'Sikeres bejelentkezés a munkaidőbe!';
            } else {
                $_SESSION['error'] = 'Már van aktív munkaidő!';
            }
        } elseif (isset($_POST['check_out'])) {
            if ($active_work) {
                // Kijelentkezés folyamata
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT wh.*, u.hourly_rate
                    FROM work_hours wh
                    JOIN users u ON wh.user_id = u.id
                    WHERE wh.id = ?
                ");
                $stmt->execute([$active_work['id']]);
                $work_data = $stmt->fetch();

                $end_time = date('Y-m-d H:i:s');
                $total_hours = (strtotime($end_time) - strtotime($work_data['check_in'])) / 3600;
                $amount_earned = $total_hours * $work_data['hourly_rate'];

                $stmt = $pdo->prepare("
                    UPDATE work_hours 
                    SET check_out = ?,
                        total_hours = ?,
                        amount_earned = ?
                    WHERE id = ?
                ");
                $stmt->execute([$end_time, $total_hours, $amount_earned, $active_work['id']]);

                $pdo->commit();
                $_SESSION['success'] = 'Sikeres kijelentkezés a munkaidőből!';
            } else {
                $_SESSION['error'] = 'Nincs aktív munkaidő!';
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($e->getMessage());
        $_SESSION['error'] = 'Hiba történt a művelet során!';
    }

    // Visszairányítás
    header('Location: ' . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php'));
    exit;
}

// Ha idáig eljutottunk, akkor valami hiba történt
$_SESSION['error'] = 'Érvénytelen kérés!';
header('Location: index.php');
exit;