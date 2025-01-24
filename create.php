<?php
require_once 'config.php';

try {
    // Új jelszó beállítása
    $username = 'admin';
    $password = '12345678Aa!';
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Ellenőrizzük, hogy létezik-e már admin felhasználó
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $existing_user = $stmt->fetch();

    if ($existing_user) {
        // Ha létezik, akkor frissítjük a jelszavát
        $stmt = $pdo->prepare("UPDATE users SET 
            password = ?,
            role = 'admin',
            user_type = 'admin',
            active = 1
        WHERE username = ?");
        $stmt->execute([$hashed_password, $username]);
        echo "Admin felhasználó jelszava frissítve!<br>";
    } else {
        // Ha nem létezik, létrehozzuk az admin felhasználót
        $stmt = $pdo->prepare("INSERT INTO users (
            username, 
            password, 
            role, 
            user_type, 
            active, 
            hourly_rate
        ) VALUES (?, ?, 'admin', 'admin', 1, 0)");
        $stmt->execute([$username, $hashed_password]);
        echo "Új admin felhasználó létrehozva!<br>";
    }

    echo "Felhasználónév: " . $username . "<br>";
    echo "Új jelszó: " . $password . "<br>";
    
    // Ellenőrizzük a jelszó hash-t
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    echo "<br>Ellenőrzés:<br>";
    echo "Tárolt felhasználónév: " . $user['username'] . "<br>";
    echo "Tárolt role: " . $user['role'] . "<br>";
    echo "Tárolt user_type: " . $user['user_type'] . "<br>";
    echo "Tárolt hash: " . $user['password'] . "<br>";
    echo "Jelszó ellenőrzés: " . (password_verify($password, $user['password']) ? 'Helyes' : 'Hibás');
} catch (PDOException $e) {
    echo "Hiba történt: " . $e->getMessage();
}
?>