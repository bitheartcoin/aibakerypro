<?php
session_start();

// Session törlése
session_unset();
session_destroy();

// Átirányítás a bejelentkező oldalra
header('Location: login.php');
exit;