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
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Felület - AI Bakery Professional Management System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
        }

        body {
            background: linear-gradient(135deg, #f6f8fa 0%, #e9ecef 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            padding: 2rem 0;
            margin-bottom: 2rem;
            color: white;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .module-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }

        .module-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }

        .module-header {
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            color: white;
        }

        .module-header i {
            position: absolute;
            right: -10px;
            top: -10px;
            font-size: 8rem;
            opacity: 0.1;
            transform: rotate(15deg);
        }

        .module-body {
            padding: 1.5rem;
        }

        .btn-module {
            width: 100%;
            text-align: left;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            border: none;
            color: white;
        }

        .btn-module:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .btn-module i {
            margin-right: 0.5rem;
            width: 20px;
            text-align: center;
        }

        /* Színek a moduloknak */
        .transactions { background: linear-gradient(135deg, #2980b9, #3498db); }
        .users { background: linear-gradient(135deg, #27ae60, #2ecc71); }
        .products { background: linear-gradient(135deg, #e67e22, #f39c12); }
        .production { background: linear-gradient(135deg, #8e44ad, #9b59b6); }
        .drivers { background: linear-gradient(135deg, #00b4db, #0083b0); }
        .payments { background: linear-gradient(135deg, #9b59b6, #8e44ad); }
        .schedules { background: linear-gradient(135deg, #e74c3c, #c0392b); }
        .orders { background: linear-gradient(135deg, #f1c40f, #f39c12); }
        .partners { background: linear-gradient(135deg, #1abc9c, #16a085); }
        .ai-forecast { background: linear-gradient(135deg, #fd79a8, #e84393); }
        .ai-vivien { background: linear-gradient(135deg, #DA4453, #89216B); }
        .statistics { background: linear-gradient(135deg, #636e72, #2d3436); }
        .documents { background: linear-gradient(135deg, #795548, #5d4037); }
        .rfid { background: linear-gradient(135deg, #20bf6b, #0b8793); }
        .cameras { background: linear-gradient(135deg, #192a56, #273c75); }
        .gps { background: linear-gradient(135deg, #1A2980, #26D0CE); }
        .settings { background: linear-gradient(135deg, #757F9A, #D7DDE8); }

        .section-title {
            font-size: 1.2rem;
            color: #2c3e50;
            margin: 2rem 0 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid rgba(44, 62, 80, 0.1);
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">AI Bakery Professional Management System</p>
                </div>
                <div class="d-flex gap-3 align-items-center">
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

    <div class="container">
        <!-- 1. Alap műveletek -->
        <h2 class="section-title"><i class="fas fa-th me-2"></i>Alap műveletek</h2>
        <div class="row g-4 mb-4">
            <!-- Tranzakciók -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header transactions">
                        <i class="fas fa-cash-register"></i>
                        <h3 class="h5 mb-1">Tranzakciók</h3>
                        <p class="mb-0 small">Bevételek és kiadások kezelése</p>
                    </div>
                    <div class="module-body">
                        <a href="transactions.php" class="btn btn-module transactions">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>

            <!-- Felhasználók -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header users">
                        <i class="fas fa-users"></i>
                        <h3 class="h5 mb-1">Felhasználók</h3>
                        <p class="mb-0 small">Alkalmazottak és jogosultságok</p>
                    </div>
                    <div class="module-body">
                        <a href="users.php" class="btn btn-module users">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>

            <!-- Termékek -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header products">
                        <i class="fas fa-box"></i>
                        <h3 class="h5 mb-1">Termékek</h3>
                        <p class="mb-0 small">Termékek és árak kezelése</p>
                    </div>
                    <div class="module-body">
                        <a href="products.php" class="btn btn-module products">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Személyzet -->
        <h2 class="section-title"><i class="fas fa-user-tie me-2"></i>Személyzet</h2>
        <div class="row g-4 mb-4">
            <!-- Sofőrök -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header drivers">
                        <i class="fas fa-truck"></i>
                        <h3 class="h5 mb-1">Sofőrök</h3>
                        <p class="mb-0 small">Gépjárművezetők kezelése</p>
                    </div>
                    <div class="module-body">
                        <a href="drivers.php" class="btn btn-module drivers">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>

            <!-- Fizetések -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header payments">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3 class="h5 mb-1">Fizetések</h3>
                        <p class="mb-0 small">Bérek és juttatások</p>
                    </div>
                    <div class="module-body">
                        <a href="payments.php" class="btn btn-module payments">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>

            <!-- Munkabeosztás -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header schedules">
                        <i class="fas fa-calendar-alt"></i>
                        <h3 class="h5 mb-1">Munkabeosztás</h3>
                        <p class="mb-0 small">Műszakok kezelése</p>
                    </div>
                    <div class="module-body">
                        <a href="schedules.php" class="btn btn-module schedules">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Üzleti folyamatok -->
        <h2 class="section-title"><i class="fas fa-chart-line me-2"></i>Üzleti folyamatok</h2>
        <div class="row g-4 mb-4">
            <!-- Rendelések -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header orders">
                        <i class="fas fa-shopping-cart"></i>
                        <h3 class="h5 mb-1">Rendelések</h3>
                        <p class="mb-0 small">Rendelések kezelése</p>
                    </div>
                    <div class="module-body">
                        <a href="orders.php" class="btn btn-module orders">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>

            <!-- Gyártás -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header production">
                        <i class="fas fa-bread-slice"></i>
                        <h3 class="h5 mb-1">Gyártás</h3>
                        <p class="mb-0 small">Termelés irányítása</p>
                    </div>
                    <div class="module-body">
                        <a href="production.php" class="btn btn-module production">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>

            <!-- Partnerek -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header partners">
                        <i class="fas fa-handshake"></i>
                        <h3 class="h5 mb-1">Partnerek</h3>
                        <p class="mb-0 small">Partnerek kezelése</p>
                    </div>
                    <div class="module-body">
                        <a href="partners.php" class="btn btn-module partners">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. AI és Analitika -->
        <h2 class="section-title"><i class="fas fa-robot me-2"></i>AI és Analitika</h2>
        <div class="row g-4 mb-4">
            <!-- AI Előrejelzés -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header ai-forecast">
                        <i class="fas fa-brain"></i>
                        <h3 class="h5 mb-1">AI Előrejelzés</h3>
                        <p class="mb-0 small">Mesterséges intelligencia</p>
                    </div>
                    <div class="module-body">
                        <a href="ai_forecast.php" class="btn btn-module ai-forecast">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>

            <!-- AI Vivien -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header ai-vivien">
                        <i class="fas fa-comments"></i>
                        <h3 class="h5 mb-1">AI Vivien</h3>
                        <p class="mb-0 small">AI Asszisztens</p>
                    </div>
                    <div class="module-body">
                    <a href="ai_assistant.php" class="btn btn-module ai-vivien">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>

            <!-- Statisztikák -->
            <div class="col-lg-4">
                <div class="module-card">
                    <div class="module-header statistics">
                        <i class="fas fa-chart-pie"></i>
                        <h3 class="h5 mb-1">Statisztikák</h3>
                        <p class="mb-0 small">Elemzések és riportok</p>
                    </div>
                    <div class="module-body">
                        <a href="statistics.php" class="btn btn-module statistics">
                            <i class="fas fa-arrow-right"></i>Kezelés
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. Dokumentumkezelés -->
        <h2 class="section-title"><i class="fas fa-file-alt me-2"></i>Dokumentumkezelés</h2>
        <div class="row g-4 mb-4">
           <!-- Dokumentumok -->
           <div class="col-lg-4">
               <div class="module-card">
                   <div class="module-header documents">
                       <i class="fas fa-file-alt"></i>
                       <h3 class="h5 mb-1">Dokumentumok</h3>
                       <p class="mb-0 small">Dokumentumok kezelése</p>
                   </div>
                   <div class="module-body">
                       <a href="documents.php" class="btn btn-module documents">
                           <i class="fas fa-arrow-right"></i>Kezelés
                       </a>
                   </div>
               </div>
           </div>
        </div>

        <!-- 6. Biztonság és Ellenőrzés -->
        <h2 class="section-title"><i class="fas fa-shield-alt me-2"></i>Biztonság és Ellenőrzés</h2>
        <div class="row g-4 mb-4">
           <!-- RFID Kezelő -->
           <div class="col-lg-4">
               <div class="module-card">
                   <div class="module-header rfid">
                       <i class="fas fa-wifi"></i>
                       <h3 class="h5 mb-1">RFID Kezelő</h3>
                       <p class="mb-0 small">Beléptetés kezelése</p>
                   </div>
                   <div class="module-body">
                       <a href="rfid_manager.php" class="btn btn-module rfid">
                           <i class="fas fa-arrow-right"></i>Kezelés
                       </a>
                   </div>
               </div>
           </div>

           <!-- Kamera rendszer -->
           <div class="col-lg-4">
               <div class="module-card">
                   <div class="module-header cameras">
                       <i class="fas fa-video"></i>
                       <h3 class="h5 mb-1">Kamera rendszer</h3>
                       <p class="mb-0 small">Megfigyelő rendszer</p>
                   </div>
                   <div class="module-body">
                       <a href="cameras.php" class="btn btn-module cameras">
                           <i class="fas fa-arrow-right"></i>Kezelés
                       </a>
                   </div>
               </div>
           </div>

           <!-- GPS nyomkövetés -->
           <div class="col-lg-4">
               <div class="module-card">
                   <div class="module-header gps">
                       <i class="fas fa-map-marker-alt"></i>
                       <h3 class="h5 mb-1">GPS nyomkövetés</h3>
                       <p class="mb-0 small">Járművek követése</p>
                   </div>
                   <div class="module-body">
                       <a href="gps.php" class="btn btn-module gps">
                           <i class="fas fa-arrow-right"></i>Kezelés
                       </a>
                   </div>
               </div>
           </div>
        </div>

        <!-- 7. Rendszerbeállítások -->
        <h2 class="section-title"><i class="fas fa-cogs me-2"></i>Rendszerbeállítások</h2>
        <div class="row g-4 mb-4">
           <!-- Beállítások -->
           <div class="col-12">
               <div class="module-card">
                   <div class="module-header settings">
                       <i class="fas fa-cog"></i>
                       <h3 class="h5 mb-1">Beállítások</h3>
                       <p class="mb-0 small">Rendszerbeállítások</p>
                   </div>
                   <div class="module-body">
                       <a href="settings.php" class="btn btn-module settings">
                           <i class="fas fa-arrow-right"></i>Kezelés
                       </a>
                   </div>
               </div>
           </div>
        </div>

    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script>
       // Kártya hover effektek
       document.querySelectorAll('.module-card').forEach(card => {
           card.addEventListener('mouseenter', function() {
               this.querySelector('.btn-module').style.transform = 'translateX(10px)';
           });
           
           card.addEventListener('mouseleave', function() {
               this.querySelector('.btn-module').style.transform = 'translateX(0)';
           });
       });
    </script>
</body>
</html>