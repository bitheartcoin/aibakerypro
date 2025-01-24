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

// Szűrési paraméterek
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$selected_user = $_GET['user_id'] ?? null;
$document_type = $_GET['document_type'] ?? null;

// Dokumentumok lekérdezése
$query = "
    SELECT 
        d.*,
        u.username
    FROM documents d
    JOIN users u ON d.user_id = u.id
    WHERE DATE(d.upload_date) BETWEEN ? AND ?";

$params = [$start_date, $end_date];

if ($selected_user) {
    $query .= " AND d.user_id = ?";
    $params[] = $selected_user;
}

if ($document_type) {
    $query .= " AND d.document_type = ?";
    $params[] = $document_type;
}

$query .= " ORDER BY d.upload_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Dokumentum törlése
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_document'])) {
    $document_id = $_POST['document_id'];
    
    // Dokumentum adatainak lekérése
    $stmt = $pdo->prepare("SELECT file_path FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();

    if ($document) {
        // Fájl törlése
        if (file_exists($document['file_path'])) {
            unlink($document['file_path']);
        }

        // Adatbázis rekord törlése
        $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
        $stmt->execute([$document_id]);

        $_SESSION['success'] = 'Dokumentum sikeresen törölve!';
        header('Location: documents.php');
        exit;
    }
}

// Felhasználók lekérése a szűrőhöz
$users = $pdo->query("SELECT id, username FROM users WHERE active = 1 ORDER BY username")->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dokumentumok Kezelése - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .preview-image {
            max-width: 100px;
            max-height: 100px;
            border-radius: 5px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        .preview-image:hover {
            transform: scale(1.1);
        }
        .document-icon {
            font-size: 2.5rem;
            color: #e74c3c;
        }
        .modal-preview-image {
            max-width: 100%;
            max-height: 80vh;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php 
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Szűrő form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="tab" value="documents">
                    <div class="col-md-3">
                        <label class="form-label">Kezdő dátum</label>
                        <input type="date" name="start_date" class="form-control" 
                               value="<?php echo $start_date; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Végző dátum</label>
                        <input type="date" name="end_date" class="form-control" 
                               value="<?php echo $end_date; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Felhasználó</label>
                        <select name="user_id" class="form-select">
                            <option value="">Összes</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>" 
                                        <?php echo $selected_user == $u['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($u['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Dokumentum típus</label>
                        <select name="document_type" class="form-select">
                            <option value="">Összes</option>
                            <option value="szamla" <?php echo $document_type == 'szamla' ? 'selected' : ''; ?>>Számla</option>
                            <option value="igazolas" <?php echo $document_type == 'igazolas' ? 'selected' : ''; ?>>Igazolás</option>
                            <option value="egyeb" <?php echo $document_type == 'egyeb' ? 'selected' : ''; ?>>Egyéb</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>
                            Szűrés
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Dokumentumok listája -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-file me-2"></i>
                    Feltöltött dokumentumok
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Előnézet</th>
                                <th>Feltöltő</th>
                                <th>Eredeti fájlnév</th>
                                <th>Típus</th>
                                <th>Dátum</th>
                                <th>Műveletek</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td>
                                    <?php
                                    $ext = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
                                    if (in_array($ext, ['jpg', 'jpeg', 'png'])): ?>
                                        <img src="<?php echo $doc['file_path']; ?>" 
                                             class="preview-image"
                                             onclick="showImagePreview(this.src)"
                                             alt="Előnézet">
                                    <?php else: ?>
                                        <i class="fas fa-file-pdf document-icon"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($doc['username']); ?></td>
                                <td><?php echo htmlspecialchars($doc['original_filename']); ?></td>
                                <td>
                                    <?php
                                    $type_labels = [
                                        'szamla' => 'Számla',
                                        'igazolas' => 'Igazolás',
                                        'egyeb' => 'Egyéb'
                                    ];
                                    echo $type_labels[$doc['document_type']] ?? $doc['document_type'];
                                    ?>
                                </td>
                                <td><?php echo date('Y-m-d H:i', strtotime($doc['upload_date'])); ?></td>
                                <td>
                                    <a href="<?php echo $doc['file_path']; ?>" 
                                       class="btn btn-sm btn-primary" 
                                       target="_blank">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <button type="button" 
                                            class="btn btn-sm btn-danger"
                                            onclick="confirmDelete(<?php echo $doc['id']; ?>)">
                                        <i class="fas fa-trash"></i>
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

    <!-- Kép előnézet modal -->
    <div class="modal fade" id="imagePreviewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Dokumentum előnézet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImage" class="modal-preview-image" src="" alt="Preview">
                </div>
            </div>
        </div>
    </div>

    <!-- Törlés form -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="document_id" id="deleteDocumentId">
        <input type="hidden" name="delete_document" value="1">
    </form>

    <script>
    function showImagePreview(src) {
        document.getElementById('modalImage').src = src;
        new bootstrap.Modal(document.getElementById('imagePreviewModal')).show();
    }

    function confirmDelete(id) {
        if (confirm('Biztosan törli ezt a dokumentumot?')) {
            document.getElementById('deleteDocumentId').value = id;
            document.getElementById('deleteForm').submit();
        }
    }
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>