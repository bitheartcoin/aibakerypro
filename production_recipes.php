<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
   header('Location: ../login.php');
   exit;
}

$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user['role'] !== 'admin') {
   header('Location: ../index.php');
   exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   try {
       $pdo->beginTransaction();
       
       $stmt = $pdo->prepare("
           INSERT INTO production_recipes (
               product_id, name, description, 
               total_time, difficulty_level, created_by
           ) VALUES (?, ?, ?, ?, ?, ?)
       ");
       
       $stmt->execute([
           $_POST['product_id'],
           $_POST['recipe_name'], 
           $_POST['description'],
           $_POST['total_time'],
           $_POST['difficulty_level'],
           $_SESSION['user_id']
       ]);
       
       $recipe_id = $pdo->lastInsertId();
       
       if (!empty($_POST['phases'])) {
           $stmt = $pdo->prepare("
               INSERT INTO production_phases (
                   recipe_id, name, duration,
                   min_temp, max_temp,
                   min_humidity, max_humidity,
                   instructions, phase_order
               ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
           ");
           
           foreach ($_POST['phases'] as $index => $phase) {
               $stmt->execute([
                   $recipe_id,
                   $phase['name'],
                   $phase['duration'],
                   $phase['min_temp'],
                   $phase['max_temp'],
                   $phase['min_humidity'],
                   $phase['max_humidity'],
                   $phase['instructions'],
                   $index + 1
               ]);
           }
       }
       
       $pdo->commit();
       $_SESSION['success'] = 'Recept sikeresen létrehozva!';
       
   } catch (Exception $e) {
       $pdo->rollBack();
       error_log($e->getMessage());
       $_SESSION['error'] = 'Hiba történt: ' . $e->getMessage();
   }
   
   header('Location: production_recipes.php');
   exit;
}

$products = $pdo->query("SELECT * FROM products WHERE active = 1 ORDER BY name")->fetchAll();

$stmt = $pdo->prepare("
   SELECT 
       pr.*,
       p.name as product_name,
       u.username as creator_name,
       COUNT(pp.id) as phase_count
   FROM production_recipes pr
   JOIN products p ON pr.product_id = p.id
   JOIN users u ON pr.created_by = u.id
   LEFT JOIN production_phases pp ON pr.id = pp.recipe_id
   GROUP BY pr.id
   ORDER BY pr.created_at DESC
");
$stmt->execute();
$recipes = $stmt->fetchAll();

$difficulty_levels = [
   'easy' => 'Könnyű',
   'medium' => 'Közepes',
   'hard' => 'Nehéz'
];
?>
<!DOCTYPE html>
<html lang="hu">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Gyártási Receptek - Admin</title>
   <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
   <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
   <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
   <style>
       .recipe-card {
           border-left: 4px solid var(--bs-primary);
           margin-bottom: 1rem;
           padding: 1rem;
           background: #f8f9fa;
           border-radius: 0.5rem;
       }
       .phase-card {
           border-left: 4px solid #6c757d;
           margin: 1rem 0;
           padding: 1rem;
           background: white;
           border-radius: 0.5rem;
       }
   </style>
</head>
<body class="bg-light">
   <div class="container-fluid">
       <div class="row mb-4">
           <div class="col-12">
               <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
                   <div class="container-fluid">
                       <a class="navbar-brand" href="../admin">
                           <i class="fas fa-cog me-2"></i>Admin Panel
                       </a>
                       <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                           <span class="navbar-toggler-icon"></span>
                       </button>
                       <div class="collapse navbar-collapse" id="navbarNav">
                           <ul class="navbar-nav">
                               <li class="nav-item">
                                   <a class="nav-link" href="../admin/index.php">
                                       <i class="fas fa-cash-register me-2"></i>Tranzakciók
                                   </a>
                               </li>
                               <li class="nav-item">
                                   <a class="nav-link" href="../admin/users.php">
                                       <i class="fas fa-users me-2"></i>Felhasználók
                                   </a>
                               </li>
                               <li class="nav-item">
                                   <a class="nav-link" href="../admin/products.php">
                                       <i class="fas fa-box me-2"></i>Termékek
                                   </a>
                               </li>
                               <li class="nav-item">
                                   <a class="nav-link" href="../admin/drivers.php">
                                       <i class="fas fa-truck me-2"></i>Sofőrök
                                   </a>
                               </li>
                               <!-- További menüpontok -->
                           </ul>
                       </div>
                       <div class="d-flex">
                           <a href="../index.php" class="btn btn-outline-light me-2">
                               <i class="fas fa-home me-2"></i>Főoldal
                           </a>
                           <a href="../logout.php" class="btn btn-danger">
                               <i class="fas fa-sign-out-alt me-2"></i>Kijelentkezés
                           </a>
                       </div>
                   </div>
               </nav>
           </div>
       </div>
   </div>

   <div class="container py-4">
       <?php if (isset($_SESSION['success'])): ?>
           <div class="alert alert-success alert-dismissible fade show">
               <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
               <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
           </div>
       <?php endif; ?>

       <?php if (isset($_SESSION['error'])): ?>
           <div class="alert alert-danger alert-dismissible fade show">
               <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
               <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
           </div>
       <?php endif; ?>

       <!-- Új recept form -->
       <div class="card mb-4">
           <div class="card-header">
               <h5 class="mb-0">Új gyártási recept létrehozása</h5>
           </div>
           <div class="card-body">
               <form method="POST" id="recipeForm">
                   <div class="row mb-4">
                       <div class="col-md-6">
                           <label class="form-label">Termék</label>
                           <select name="product_id" class="form-select select2" required>
                               <option value="">Válasszon terméket...</option>
                               <?php foreach ($products as $product): ?>
                                   <option value="<?php echo $product['id']; ?>">
                                       <?php echo htmlspecialchars($product['name']); ?>
                                   </option>
                               <?php endforeach; ?>
                           </select>
                       </div>
                       <div class="col-md-6">
                           <label class="form-label">Recept neve</label>
                           <input type="text" name="recipe_name" class="form-control" required>
                       </div>
                   </div>

                   <div class="mb-4">
                       <label class="form-label">Leírás</label>
                       <textarea name="description" class="form-control" rows="3"></textarea>
                   </div>

                   <div class="row mb-4">
                       <div class="col-md-6">
                           <label class="form-label">Teljes idő (perc)</label>
                           <input type="number" name="total_time" class="form-control" required>
                       </div>
                       <div class="col-md-6">
                           <label class="form-label">Nehézségi szint</label>
                           <select name="difficulty_level" class="form-select" required>
                               <?php foreach ($difficulty_levels as $value => $label): ?>
                                   <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                               <?php endforeach; ?>
                           </select>
                       </div>
                   </div>

                   <div id="phasesContainer">
                       <!-- Fázisok ide kerülnek -->
                   </div>

                   <button type="button" class="btn btn-secondary mb-4" onclick="addPhase()">
                       <i class="fas fa-plus me-2"></i>Új fázis
                   </button>

                   <div>
                       <button type="submit" class="btn btn-primary">
                           <i class="fas fa-save me-2"></i>Recept mentése
                       </button>
                   </div>
               </form>
           </div>
       </div>

       <!-- Receptek listája -->
       <div class="card">
           <div class="card-header d-flex justify-content-between align-items-center">
               <h5 class="mb-0">Receptek listája</h5>
               <span class="badge bg-primary"><?php echo count($recipes); ?> recept</span>
           </div>
           <div class="card-body">
               <?php foreach ($recipes as $recipe): ?>
                   <div class="recipe-card">
                       <div class="d-flex justify-content-between align-items-start mb-3">
                           <div>
                               <h5 class="mb-1"><?php echo htmlspecialchars($recipe['name']); ?></h5>
                               <p class="text-muted mb-0">
                                   <?php echo htmlspecialchars($recipe['product_name']); ?> |
                                   <?php echo $difficulty_levels[$recipe['difficulty_level']]; ?> |
                                   <?php echo $recipe['total_time']; ?> perc |
                                   <?php echo $recipe['phase_count']; ?> fázis
                               </p>
                           </div>
                           <div>
                               <button type="button" class="btn btn-sm btn-primary" 
                                       onclick="showRecipeDetails(<?php echo $recipe['id']; ?>)">
                                   <i class="fas fa-eye"></i>
                               </button>
                           </div>
                       </div>
                       <div class="recipe-details" id="recipe-<?php echo $recipe['id']; ?>" style="display: none;">
                           <?php if ($recipe['description']): ?>
                               <div class="alert alert-info mb-3">
                                   <?php echo nl2br(htmlspecialchars($recipe['description'])); ?>
                               </div>
                           <?php endif; ?>
                           <small class="text-muted">
                               Létrehozta: <?php echo htmlspecialchars($recipe['creator_name']); ?> |
                               <?php echo date('Y.m.d H:i', strtotime($recipe['created_at'])); ?>
                           </small>
                       </div>
                   </div>
               <?php endforeach; ?>
           </div>
       </div>
   </div>

   <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
   <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
   <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
   <script>
   $(document).ready(function() {
       $('.select2').select2();
   });

   function addPhase() {
       const container = document.getElementById('phasesContainer');
       const phaseIndex = container.children.length;
       
       const phaseHtml = `
           <div class="phase-card">
               <h6 class="mb-3">${phaseIndex + 1}. fázis</h6>
               <div class="row g-3">
                   <div class="col-md-6">
                       <label class="form-label">Fázis neve</label>
                       <input type="text" name="phases[${phaseIndex}][name]" class="form-control" required>
                   </div>
                   <div class="col-md-6">
                       <label class="form-label">Időtartam (perc)</label>
                       <input type="number" name="phases[${phaseIndex}][duration]" class="form-control" required>
                   </div>
                   <div class="col-md-3">
                       <label class="form-label">Min. hőmérséklet (°C)</label>
                       <input type="number" step="0.1" name="phases[${phaseIndex}][min_temp]" class="form-control">
                   </div>
                   <div class="col-md-3">
                       <label class="form-label">Max. hőmérséklet (°C)</label>
                       <input type="number" step="0.1" name="phases[${phaseIndex}][max_temp]" class="form-control">
                   </div>
                   <div class="col-md-3">
                       <label class="form-label">Min. páratartalom (%)</label>
                       <input type="number" step="0.1" name="phases[${phaseIndex}][min_humidity]" class="form-control">
                   </div>
                   <div class="col-md-3">
                       <label class="form-label">Max. páratartalom (%)</label>
                       <input type="number" step="0.1" name="phases[${phaseIndex}][max_humidity]" class="form-control">
                   </div>
                   <div class="col-12">
                       <label class="form-label">Utasítások</label>
                       <textarea name="phases[${phaseIndex}][instructions]" class="form-control" rows="3"></textarea>
                   </div>
               </div>
           </div>
       `;container.insertAdjacentHTML('beforeend', phaseHtml);
   }

   function showRecipeDetails(recipeId) {
       const detailsDiv = document.getElementById(`recipe-${recipeId}`);
       if (detailsDiv) {
           detailsDiv.style.display = detailsDiv.style.display === 'none' ? 'block' : 'none';
       }
   }
   </script>
</body>
</html>