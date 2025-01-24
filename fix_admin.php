<?php
require_once 'config.php';

try {
    // Új jelszó beállítása az admin felhasználónak
    $password = '12345678Aa!';  // Ez lesz az új jelszó
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Admin felhasználó jelszavának frissítése
    $stmt = $pdo->prepare("UPDATE users SET password = ?, active = 1, role = 'admin' WHERE username = 'admin'");
    if ($stmt->execute([$hashed_password])) {
        echo "Admin jelszó sikeresen frissítve!<br>";
        echo "Felhasználónév: admin<br>";
        echo "Új jelszó: " . $password . "<br>";
        
        // Ellenőrizzük a jelszó hash-t
        $stmt = $pdo->prepare("SELECT password FROM users WHERE username = 'admin'");
        $stmt->execute();
        $user = $stmt->fetch();
        echo "<br>Ellenőrzés:<br>";
        echo "Tárolt hash: " . $user['password'] . "<br>";
        echo "Jelszó ellenőrzés: " . (password_verify($password, $user['password']) ? 'Helyes' : 'Hibás');
    }
} catch (PDOException $e) {
    echo "Hiba történt: " . $e->getMessage();
}
?>