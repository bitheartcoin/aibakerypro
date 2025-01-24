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

// Új partner hozzáadása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_partner'])) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO partners (
                company_name, tax_number, address, 
                email, contact_name, contact_phone
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $_POST['company_name'] ?? '',
            $_POST['tax_number'] ?? '',
            $_POST['address'] ?? '',
            $_POST['email'] ?? '',
            $_POST['contact_name'] ?? '',
            $_POST['contact_phone'] ?? ''
        ]);
        
        $_SESSION['success'] = 'Partner sikeresen hozzáadva!';
        header('Location: partners.php');
        exit;
    } catch (Exception $e) {
        error_log('Partner hozzáadási hiba: ' . $e->getMessage());
        $_SESSION['error'] = 'Hiba történt a partner hozzáadásakor: ' . $e->getMessage();
        header('Location: partners.php');
        exit;
    }
}

// Partner módosítása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_partner'])) {
    try {
        $stmt = $pdo->prepare("
            UPDATE partners 
            SET company_name = ?, 
                tax_number = ?, 
                address = ?, 
                email = ?, 
                contact_name = ?, 
                contact_phone = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $_POST['company_name'] ?? '',
            $_POST['tax_number'] ?? '',
            $_POST['address'] ?? '',
            $_POST['email'] ?? '',
            $_POST['contact_name'] ?? '',
            $_POST['contact_phone'] ?? '',
            $_POST['partner_id'] ?? 0
        ]);
        
        $_SESSION['success'] = 'Partner sikeresen módosítva!';
        header('Location: partners.php');
        exit;
    } catch (Exception $e) {
        error_log('Partner módosítási hiba: ' . $e->getMessage());
        $_SESSION['error'] = 'Hiba történt a partner módosításakor!';
        header('Location: partners.php');
        exit;
    }
}

// Partnerek listázása
$partners = $pdo->query("
    SELECT 
        *, 
        0 as total_orders,
        0 as total_order_value
    FROM partners 
    WHERE active = 1 
    ORDER BY company_name
")->fetchAll();

// Saját üzletek lekérése
$shops = $pdo->query("SELECT * FROM shops ORDER BY name")->fetchAll();

?>

<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partnerek Kezelése - Admin</title>
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

        .partner-details-row {
            display: none;
            background-color: #f8f9fa;
        }

        .btn-details {
            cursor: pointer;
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">Partnerek kezelése</p>
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
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Új partner hozzáadása -->
            <div class="col-md-4 mb-4">
                <div class="content-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Új partner</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Cégnév</label>
                                <input type="text" name="company_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Adószám</label>
                                <input type="text" name="tax_number" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Teljes cím</label>
                                <input type="text" name="address" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email cím</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Kapcsolattartó neve</label>
                                <input type="text" name="contact_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Telefonszám</label>
                                <input type="tel" name="contact_phone" class="form-control" required>
                            </div>
                            <button type="submit" name="add_partner" class="btn btn-primary w-100">
                                <i class="fas fa-save me-2"></i>Partner mentése
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Partnerek listája -->
            <div class="col-md-8 mb-4">
                <div class="content-card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Partnerek listája</h5>
                        <div>
                            <button class="btn btn-sm btn-primary me-2" id="show-shops-btn">
                                <i class="fas fa-store me-2"></i>Saját üzletek
                            </button>
                            <button class="btn btn-sm btn-secondary" id="show-partners-btn">
                                <i class="fas fa-handshake me-2"></i>Külső partnerek
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="partners-table">
                                <thead>
                                    <tr>
                                        <th>Cégnév</th>
                                        <th>Adószám</th>
                                        <th>Cím</th>
                                        <th>Kapcsolattartó</th>
                                        <th>Műveletek</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Saját üzletek -->
                                    <?php foreach ($shops as $shop): ?>
                                    <tr class="table-shop">
                                        <td>
                                            <i class="fas fa-store me-2 text-primary"></i>
                                            <?php echo htmlspecialchars($shop['name']); ?>
                                        </td>
                                        <td>Saját üzlet</td>
                                        <td><?php echo htmlspecialchars($shop['location']); ?></td>
                                        <td>-</td>
                                        <td>
                                            <a href="shop_orders.php?shop_id=<?php echo $shop['id']; ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye me-1"></i>Rendelések
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <!-- Külső partnerek -->
                                    <?php foreach ($partners as $partner): ?>
                                    <tr class="table-partner">
                                        <td><?php echo htmlspecialchars($partner['company_name']); ?></td>
                                        <td><?php echo htmlspecialchars($partner['tax_number'] ?: 'Nem megadott'); ?></td>
                                        <td><?php echo htmlspecialchars($partner['address'] ?: 'Nem megadott'); ?></td>
                                        <td><?php echo htmlspecialchars($partner['contact_name'] ?: 'Nem megadott'); ?></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-sm btn-info btn-details" 
                                                        data-bs-toggle="collapse" 
                                                        data-bs-target="#partner-details-<?php echo $partner['id']; ?>">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-warning" 
                                                        onclick="editPartner(<?php echo htmlspecialchars(json_encode($partner)); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="partner_orders.php?partner_id=<?php echo $partner['id']; ?>" 
                                                   class="btn btn-sm btn-primary">
                                                    <i class="fas fa-shopping-cart"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            </div>
    </div>

    <!-- Partner szerkesztése modal -->
    <div class="modal fade" id="editPartnerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Partner szerkesztése</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="partner_id" id="edit_partner_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Cégnév</label>
                            <input type="text" name="company_name" id="edit_company_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Adószám</label>
                            <input type="text" name="tax_number" id="edit_tax_number" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Teljes cím</label>
                            <input type="text" name="address" id="edit_address" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email cím</label>
                            <input type="email" name="email" id="edit_email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kapcsolattartó neve</label>
                            <input type="text" name="contact_name" id="edit_contact_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Telefonszám</label>
                            <input type="tel" name="contact_phone" id="edit_contact_phone" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
                        <button type="submit" name="edit_partner" class="btn btn-warning">
                            <i class="fas fa-save me-2"></i>Mentés
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script>
    function editPartner(partner) {
        document.getElementById('edit_partner_id').value = partner.id;
        document.getElementById('edit_company_name').value = partner.company_name;
        document.getElementById('edit_tax_number').value = partner.tax_number;
        document.getElementById('edit_address').value = partner.address;
        document.getElementById('edit_email').value = partner.email;
        document.getElementById('edit_contact_name').value = partner.contact_name;
        document.getElementById('edit_contact_phone').value = partner.contact_phone;
        
        new bootstrap.Modal(document.getElementById('editPartnerModal')).show();
    }

    // Saját üzletek és külső partnerek közötti váltás
    document.addEventListener('DOMContentLoaded', function() {
        const showShopsBtn = document.getElementById('show-shops-btn');
        const showPartnersBtn = document.getElementById('show-partners-btn');
        const partnersTable = document.getElementById('partners-table');
        const shopRows = document.querySelectorAll('.table-shop');
        const partnerRows = document.querySelectorAll('.table-partner');

        showShopsBtn.addEventListener('click', function() {
            partnerRows.forEach(row => row.style.display = 'none');
            shopRows.forEach(row => row.style.display = '');
        });

        showPartnersBtn.addEventListener('click', function() {
            shopRows.forEach(row => row.style.display = 'none');
            partnerRows.forEach(row => row.style.display = '');
        });
    });
    </script>
</body>
</html>