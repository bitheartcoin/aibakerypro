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

// Form feldolgozása
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();
        
        if (isset($_POST['add_user'])) {
            // Kötelező mezők
            $username = $_POST['username'];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $role = $_POST['role'];
            $user_type = $_POST['user_type'];
            $hourly_rate = $_POST['hourly_rate'];
            $active = 1;
 
            $sql = "INSERT INTO users (username, password, role, user_type, hourly_rate, active) 
                   VALUES (?, ?, ?, ?, ?, ?)";
 
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                $username, $password, $role, $user_type, $hourly_rate, $active
            ]);
 
            if ($success) {
                // Ha van opcionális adat, akkor azokat is mentjük
                if (!empty($_POST['tax_number']) || !empty($_POST['address']) || 
                    !empty($_POST['phone']) || !empty($_POST['email'])) {
                    
                    $sql = "UPDATE users SET 
                            tax_number = ?, 
                            address = ?,
                            phone = ?,
                            email = ?,
                            social_security_number = ?,
                            birth_date = ?,
                            mother_name = ?,
                            bank_account = ?
                           WHERE username = ?";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $_POST['tax_number'] ?? null,
                        $_POST['address'] ?? null,
                        $_POST['phone'] ?? null,
                        $_POST['email'] ?? null,
                        $_POST['social_security_number'] ?? null,
                        $_POST['birth_date'] ?? null,
                        $_POST['mother_name'] ?? null,
                        $_POST['bank_account'] ?? null,
                        $username
                    ]);
                }
                
                $pdo->commit();
                $_SESSION['success'] = 'Felhasználó sikeresen létrehozva!';
            } else {
                throw new Exception('Hiba történt a felhasználó létrehozása során.');
            }
 
        } elseif (isset($_POST['update_user'])) {
            $user_id = $_POST['user_id'];
            
            // Alap adatok frissítése
            $sql = "UPDATE users SET 
                    role = ?,
                    user_type = ?,
                    hourly_rate = ?,
                    active = ?
                   WHERE id = ?";
                   
            $stmt = $pdo->prepare($sql);
            $success = $stmt->execute([
                $_POST['role'],
                $_POST['user_type'],
                $_POST['hourly_rate'],
                isset($_POST['active']) ? 1 : 0,
                $user_id
            ]);
 
            // Opcionális adatok frissítése ha vannak
            $sql = "UPDATE users SET 
                    tax_number = ?,
                    address = ?,
                    phone = ?,
                    email = ?,
                    social_security_number = ?,
                    birth_date = ?,
                    mother_name = ?,
                    bank_account = ?
                   WHERE id = ?";
                   
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $_POST['tax_number'] ?? null,
                $_POST['address'] ?? null,
                $_POST['phone'] ?? null,
                $_POST['email'] ?? null,
                $_POST['social_security_number'] ?? null,
                $_POST['birth_date'] ?? null,
                $_POST['mother_name'] ?? null,
                $_POST['bank_account'] ?? null,
                $user_id
            ]);
 
            // Jelszó módosítása, ha van új jelszó
            if (!empty($_POST['password'])) {
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$password, $user_id]);
            }
 
            $pdo->commit();
            $_SESSION['success'] = 'Felhasználó sikeresen frissítve!';
        }
 
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Hiba történt: ' . $e->getMessage();
    }
    
    header('Location: users.php');
    exit;
 }
// Felhasználók listázása
$users = $pdo->query("SELECT * FROM users ORDER BY username")->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Felhasználók Kezelése - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #e67e22;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f1c40f;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
        }
        body {
            background: linear-gradient(135deg, #f6f8fa 0%, #e9ecef 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .main-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            padding: 2rem 0;
            margin-bottom: 2rem;
            color: white;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        .content-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        .table-custom th {
            background: var(--primary-color);
            color: white;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">Felhasználók kezelése</p>
                </div>
                <div class="d-flex gap-3 align-items-center">
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-cog me-2"></i>Kezelés
                        </button>
                        <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="index.php"><i class="fas fa-cash-register me-2"></i>Tranzakciók</a></li>
                        <li><a class="dropdown-item" href="users.php"><i class="fas fa-users me-2"></i>Felhasználók</a></li>
                        <li><a class="dropdown-item" href="products.php"><i class="fas fa-box me-2"></i>Termékek</a></li>
                        <li><a class="dropdown-item" href="drivers.php"><i class="fas fa-truck me-2"></i>Sofőrök</a></li>
                        <li><a class="dropdown-item" href="payments.php"><i class="fas fa-money-bill-wave me-2"></i>Fizetések</a></li>
                        <li><a class="dropdown-item" href="schedules.php"><i class="fas fa-calendar-alt me-2"></i>Munkabeosztás</a></li>
                        <li><a class="dropdown-item" href="orders.php"><i class="fas fa-shopping-cart me-2"></i>Rendelések</a></li>
                        <li><a class="dropdown-item" href="partners.php"><i class="fas fa-handshake me-2"></i>Partnerek</a></li>
                        <li><a class="dropdown-item" href="ai_forecast.php"><i class="fas fa-chart-line me-2"></i>AI Előrejelzés</a></li>
                        <li><a class="dropdown-item" href="statistics.php"><i class="fas fa-chart-pie me-2"></i>Statisztikák</a></li>
                        </ul>
                    </div>
                    <a href="../index.php" class="btn btn-light">
                        <i class="fas fa-home me-2"></i>Főoldal
                    </a>
                    <a href="../logout.php" class="btn btn-danger">
                        <i class="fas fa-sign-out-alt me-2"></i>Kijelentkezés
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container-fluid">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php 
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="content-card">
            <div class="card-header d-flex justify-content-between align-items-center p-4">
                <h5 class="mb-0">Felhasználók listája</h5>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-plus me-2"></i>Új felhasználó
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Felhasználónév</th>
                                <th>Szerepkör</th>
                                <th>Felhasználó típus</th>
                                <th>Órabér</th>
                                <th>Státusz</th>
                                <th>Műveletek</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $u['role'] == 'admin' ? 'danger' : 'primary'; ?>">
                                        <?php echo $u['role'] == 'admin' ? 'Admin' : 'Felhasználó'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $type_badges = [
                                        'admin' => 'danger',
                                        'driver' => 'info',
                                        'seller' => 'success',
                                        'baker' => 'warning'
                                    ];
                                    $type_names = [
                                        'admin' => 'Admin',
                                        'driver' => 'Sofőr',
                                        'seller' => 'Eladó',
                                        'baker' => 'Pék'
                                    ];
                                    ?>
                                    <span class="badge bg-<?php echo $type_badges[$u['user_type']] ?? 'secondary'; ?>">
                                        <?php echo $type_names[$u['user_type']] ?? 'Ismeretlen'; ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($u['hourly_rate'], 0, ',', ' '); ?> Ft/óra</td>
                                <td>
                                    <span class="badge bg-<?php echo $u['active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $u['active'] ? 'Aktív' : 'Inaktív'; ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-warning" 
                                            onclick="editUser(<?php echo htmlspecialchars(json_encode($u)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Új felhasználó modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Új felhasználó hozzáadása</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <h6 class="mb-3">Alapadatok</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Felhasználónév</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Jelszó</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <div class="col-md-6">
    <label class="form-label">Szerepkör</label>
    <select name="role" class="form-select" required>
        <option value="user">Felhasználó</option>
        <option value="admin">Admin</option>
        <option value="partner">Partner</option>
    </select>
</div>
<div class="col-md-6">
    <label class="form-label">Felhasználó típusa</label>
    <select name="user_type" class="form-select" required>
        <option value="admin">Admin</option>
        <option value="driver">Sofőr</option>
        <option value="seller">Eladó</option>
        <option value="baker">Pék</option>
        <option value="partner">Partner</option>
    </select>
</div>
                        </div>

                        <h6 class="mb-3">Személyes adatok</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Adószám</label>
                                <input type="text" name="tax_number" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">TAJ szám</label>
                                <input type="text" name="social_security_number" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Lakcím</label>
                                <input type="text" name="address" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Telefonszám</label>
                                <input type="tel" name="phone" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">E-mail cím</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Születési dátum</label>
                                <input type="date" name="birth_date" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Anyja neve</label>
                                <input type="text" name="mother_name" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Bankszámlaszám</label>
                                <input type="text" name="bank_account" class="form-control">
                            </div>
                        </div>

                        <h6 class="mb-3">Fizetési adatok</h6>
                        <div class="mb-3">
                            <label class="form-label">Órabér (Ft)</label>
                            <input type="number" name="hourly_rate" class="form-control" value="0" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
                        <button type="submit" name="add_user" class="btn btn-primary">Hozzáadás</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Felhasználó szerkesztése modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Felhasználó szerkesztése</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="modal-body">
                        <h6 class="mb-3">Alapadatok</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Felhasználónév</label>
                                <input type="text" id="edit_username" class="form-control" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Új jelszó (üresen hagyható)</label>
                                <input type="password" name="password" class="form-control">
                            </div>
                            <div class="col-md-6">
    <label class="form-label">Szerepkör</label>
    <select name="role" id="edit_role" class="form-select" required>
        <option value="user">Felhasználó</option>
        <option value="admin">Admin</option>
        <option value="partner">Partner</option>
    </select>
</div>
<div class="col-md-6">
    <label class="form-label">Felhasználó típusa</label>
    <select name="user_type" id="edit_user_type" class="form-select" required>
        <option value="admin">Admin</option>
        <option value="driver">Sofőr</option>
        <option value="seller">Eladó</option>
        <option value="baker">Pék</option>
        <option value="partner">Partner</option>
    </select>
</div>
                        </div>

                        <h6 class="mb-3">Személyes adatok</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Adószám</label>
                                <input type="text" name="tax_number" id="edit_tax_number" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">TAJ szám</label>
                                <input type="text" name="social_security_number" id="edit_social_security_number" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Lakcím</label>
                                <input type="text" name="address" id="edit_address" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Telefonszám</label>
                                <input type="tel" name="phone" id="edit_phone" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">E-mail cím</label>
                                <input type="email" name="email" id="edit_email" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Születési dátum</label>
                                <input type="date" name="birth_date" id="edit_birth_date" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Anyja neve</label>
                                <input type="text" name="mother_name" id="edit_mother_name" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Bankszámlaszám</label>
                                <input type="text" name="bank_account" id="edit_bank_account" class="form-control">
                            </div>
                        </div>

                        <h6 class="mb-3">Fizetési adatok</h6>
                        <div class="mb-3">
                            <label class="form-label">Órabér (Ft)</label>
                            <input type="number" name="hourly_rate" id="edit_hourly_rate" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="active" id="edit_active" class="form-check-input">
                                <label class="form-check-label">Aktív felhasználó</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
                        <button type="submit" name="update_user" class="btn btn-warning">Mentés</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function editUser(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_username').value = user.username;
        document.getElementById('edit_role').value = user.role;
        document.getElementById('edit_user_type').value = user.user_type;
        document.getElementById('edit_hourly_rate').value = user.hourly_rate;
        document.getElementById('edit_tax_number').value = user.tax_number;
        document.getElementById('edit_address').value = user.address;
        document.getElementById('edit_phone').value = user.phone;
        document.getElementById('edit_email').value = user.email;
        document.getElementById('edit_social_security_number').value = user.social_security_number;
        document.getElementById('edit_birth_date').value = user.birth_date;
        document.getElementById('edit_mother_name').value = user.mother_name;
        document.getElementById('edit_bank_account').value = user.bank_account;
        document.getElementById('edit_active').checked = user.active == 1;
        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }

    // Sikeres üzenet automatikus eltüntetése
    document.addEventListener('DOMContentLoaded', function() {
        const alertSuccess = document.querySelector('.alert-success');
        if (alertSuccess) {
            setTimeout(function() {
                const closeButton = alertSuccess.querySelector('.btn-close');
                if (closeButton) {
                    closeButton.click();
                }
            }, 3000);
        }
    });
    </script>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>