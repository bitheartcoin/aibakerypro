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

// Új beosztás hozzáadása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_schedule'])) {
    try {
        $pdo->beginTransaction();
        
        // Ismétlődő beosztás létrehozása
        $start_date = new DateTime($_POST['start_date']);
        $end_date = new DateTime($_POST['end_date']);
        $repeat_until = isset($_POST['repeat_until']) ? new DateTime($_POST['repeat_until']) : null;
        
        do {
            $stmt = $pdo->prepare("
                INSERT INTO schedules (
                    user_id, 
                    start_date, 
                    end_date, 
                    shift_type,
                    location,
                    details,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $_POST['user_id'],
                $start_date->format('Y-m-d H:i:s'),
                $end_date->format('Y-m-d H:i:s'),
                $_POST['shift_type'],
                $_POST['location'],
                $_POST['details'],
                $_SESSION['user_id']
            ]);
            
            // Ha van ismétlődés
            if ($repeat_until && isset($_POST['repeat_weekly'])) {
                $start_date->modify('+1 week');
                $end_date->modify('+1 week');
            }
        } while ($repeat_until && $start_date <= $repeat_until && isset($_POST['repeat_weekly']));
        
        $pdo->commit();
        $_SESSION['success'] = 'Beosztás sikeresen létrehozva!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Hiba történt: ' . $e->getMessage();
    }
    header('Location: schedules.php');
    exit;
}

// Beosztás módosítása
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    try {
        $stmt = $pdo->prepare("
            UPDATE schedules SET 
                user_id = ?,
                start_date = ?,
                end_date = ?,
                shift_type = ?,
                location = ?,
                details = ?,
                modified_at = NOW(),
                modified_by = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $_POST['user_id'],
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['shift_type'],
            $_POST['location'],
            $_POST['details'],
            $_SESSION['user_id'],
            $_POST['schedule_id']
        ]);
        
        $_SESSION['success'] = 'Beosztás sikeresen módosítva!';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Hiba történt: ' . $e->getMessage();
    }
    header('Location: schedules.php');
    exit;
}

// Beosztás törlése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM schedules WHERE id = ?");
        $stmt->execute([$_POST['schedule_id']]);
        $_SESSION['success'] = 'Beosztás sikeresen törölve!';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Hiba történt: ' . $e->getMessage();
    }
    header('Location: schedules.php');
    exit;
}

// Felhasználók lekérése
$users = $pdo->query("
    SELECT id, username, user_type 
    FROM users 
    WHERE active = 1 
    ORDER BY username
")->fetchAll();

// Beosztások lekérése
$schedules_query = "
    SELECT 
        s.*,
        u.username,
        u.user_type,
        cu.username as created_by_name,
        mu.username as modified_by_name
    FROM schedules s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN users cu ON s.created_by = cu.id
    LEFT JOIN users mu ON s.modified_by = mu.id
    WHERE s.start_date >= CURDATE()
    ORDER BY s.start_date ASC, s.created_at DESC
";

$schedules = $pdo->query($schedules_query)->fetchAll();

// Műszaktípusok
$shift_types = [
    'morning' => 'Reggeli műszak (6:00-14:00)',
    'afternoon' => 'Délutáni műszak (14:00-22:00)',
    'night' => 'Éjszakai műszak (22:00-6:00)',
    'full_day' => 'Egész napos (8:00-16:00)',
    'custom' => 'Egyedi időbeosztás'
];

// Helyszínek
$locations = [
    'bakery' => 'Pékség',
    'store1' => '1. számú üzlet',
    'store2' => '2. számú üzlet',
    'store3' => '3. számú üzlet',
    'delivery' => 'Kiszállítás'
];
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Munkabeosztások Kezelése - Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
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

        .schedule-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        .nav-tabs .nav-link {
            color: var(--dark-color);
            padding: 1rem 2rem;
            border: none;
            border-radius: 0;
            position: relative;
        }

        .nav-tabs .nav-link.active {
            color: var(--accent-color);
            background: none;
            border: none;
        }

        .nav-tabs .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent-color);
        }

        .schedule-header {
            background: var(--light-color);
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }

        .shift-type {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .shift-type.morning { background: #fff3cd; color: #856404; }
        .shift-type.afternoon { background: #d1ecf1; color: #0c5460; }
        .shift-type.night { background: #d6d8d9; color: #383d41; }
        .shift-type.full_day { background: #d4edda; color: #155724; }
        .shift-type.custom { background: #f8d7da; color: #721c24; }

        .fc-event {
            cursor: pointer;
            padding: 0.5rem;
        }

        .fc-event-title {
            font-weight: 500;
        }

        .quick-actions {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 1000;
        }

        .quick-actions .btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 1rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Admin Felület</h1>
                    <p class="mb-0 opacity-75">Munkabeosztások kezelése</p>
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
                            <li><a class="dropdown-item active" href="schedules.php"><i class="fas fa-calendar-alt me-2"></i>Munkabeosztás</a></li>
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
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="schedule-card">
            <div class="card-body">
                <ul class="nav nav-tabs mb-4" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="tab" href="#calendar">
                            <i class="fas fa-calendar-alt me-2"></i>Naptár nézet
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#list">
                            <i class="fas fa-list me-2"></i>Lista nézet
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="tab" href="#stats">
                            <i class="fas fa-chart-bar me-2"></i>Statisztikák
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Naptár nézet -->
                    <div class="tab-pane fade show active" id="calendar">
                        <div id="calendar"></div>
                    </div>

                    <!-- Lista nézet -->
                    <div class="tab-pane fade" id="list">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Dolgozó</th>
                                        <th>Kezdés</th>
                                        <th>Befejezés</th>
                                        <th>Műszak típus</th>
                                        <th>Helyszín</th>
                                        <th>Részletek</th>
                                        <th>Létrehozva</th>
                                        <th>Műveletek</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($schedules as $schedule): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($schedule['username']); ?>
                                            <br>
                                            <small class="text-muted"><?php echo $schedule['user_type']; ?></small>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($schedule['start_date'])); ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($schedule['end_date'])); ?></td>
                                        <td>
                                            <span class="shift-type <?php echo $schedule['shift_type']; ?>">
                                                <?php echo $shift_types[$schedule['shift_type']]; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $locations[$schedule['location']]; ?></td>
                                        <td><?php echo htmlspecialchars($schedule['details']); ?></td>
                                        <td>
                                            <?php echo date('Y-m-d H:i', strtotime($schedule['created_at'])); ?>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($schedule['created_by_name']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-warning" 
                                                        onclick="editSchedule(<?php echo htmlspecialchars(json_encode($schedule)); ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger"
                                                        onclick="deleteSchedule(<?php echo $schedule['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Statisztikák -->
                    <div class="tab-pane fade" id="stats">
                        <div class="row">
                            <div class="col-md-6">
                                <canvas id="shiftsChart"></canvas>
                            </div>
                            <div class="col-md-6">
                                <canvas id="locationsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Új beosztás modal -->
    <div class="modal fade" id="addScheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Új beosztás létrehozása</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Dolgozó</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">Válasszon dolgozót...</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>">
                                        <?php echo htmlspecialchars($u['username']); ?> 
                                        (<?php echo $u['user_type']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Műszak típusa</label>
                            <select name="shift_type" class="form-select" required>
                                <?php foreach ($shift_types as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Kezdés</label>
                                <input type="datetime-local" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Befejezés</label>
                                <input type="datetime-local" name="end_date" class="form-control" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Helyszín</label>
                            <select name="location" class="form-select" required>
                                <?php foreach ($locations as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Részletek</label>
                            <textarea name="details" class="form-control" rows="3"></textarea>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="repeat_weekly" name="repeat_weekly">
                                <label class="form-check-label" for="repeat_weekly">Heti ismétlődés</label>
                            </div>
                        </div>

                        <div class="mb-3" id="repeatUntilContainer" style="display: none;">
                            <label class="form-label">Ismétlődés vége</label>
                            <input type="date" name="repeat_until" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
                        <button type="submit" name="add_schedule" class="btn btn-primary">Létrehozás</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Beosztás szerkesztése modal -->
    <div class="modal fade" id="editScheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Beosztás szerkesztése</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="schedule_id" id="edit_schedule_id">
                    <div class="modal-body">
                        <!-- Ugyanazok a mezők, mint az új beosztásnál, csak id-kkal ellátva -->
                        <div class="mb-3">
                            <label class="form-label">Dolgozó</label>
                            <select name="user_id" id="edit_user_id" class="form-select" required>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>">
                                        <?php echo htmlspecialchars($u['username']); ?> 
                                        (<?php echo $u['user_type']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Műszak típusa</label>
                            <select name="shift_type" id="edit_shift_type" class="form-select" required>
                                <?php foreach ($shift_types as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Kezdés</label>
                                <input type="datetime-local" name="start_date" id="edit_start_date" 
                                       class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Befejezés</label>
                                <input type="datetime-local" name="end_date" id="edit_end_date" 
                                       class="form-control" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Helyszín</label>
                            <select name="location" id="edit_location" class="form-select" required>
                                <?php foreach ($locations as $value => $label): ?>
                                    <option value="<?php echo $value; ?>"><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Részletek</label>
                            <textarea name="details" id="edit_details" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
                        <button type="submit" name="update_schedule" class="btn btn-warning">Mentés</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Gyorsműveletek -->
    <div class="quick-actions">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal">
            <i class="fas fa-plus"></i>
        </button>
        <button type="button" class="btn btn-success" onclick="window.print()">
            <i class="fas fa-print"></i>
        </button>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // FullCalendar inicializálása
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'timeGridWeek',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                locale: 'hu',
                slotMinTime: '06:00:00',
                slotMaxTime: '22:00:00',
                events: <?php 
                    $events = array_map(function($schedule) use ($shift_types, $locations) {
                        return [
                            'id' => $schedule['id'],
                            'title' => $schedule['username'] . ' - ' . $locations[$schedule['location']],
                            'start' => $schedule['start_date'],
                            'end' => $schedule['end_date'],
                            'backgroundColor' => getColorForShiftType($schedule['shift_type']),
                            'extendedProps' => [
                                'user_id' => $schedule['user_id'],
                                'shift_type' => $schedule['shift_type'],
                                'location' => $schedule['location'],
                                'details' => $schedule['details']
                            ]
                        ];
                    }, $schedules);
                    echo json_encode($events);
                ?>,
                eventClick: function(info) {
                    editSchedule({
                        id: info.event.id,
                        user_id: info.event.extendedProps.user_id,
                        shift_type: info.event.extendedProps.shift_type,
                        location: info.event.extendedProps.location,
                        start_date: info.event.start,
                        end_date: info.event.end,
                        details: info.event.extendedProps.details
                    });
                }
            });
            calendar.render();
        });

        // Ismétlődés kezelése
        document.getElementById('repeat_weekly').addEventListener('change', function(e) {
            document.getElementById('repeatUntilContainer').style.display = 
                e.target.checked ? 'block' : 'none';
        });

        // Beosztás szerkesztése
        function editSchedule(schedule) {
            document.getElementById('edit_schedule_id').value = schedule.id;
            document.getElementById('edit_user_id').value = schedule.user_id;
            document.getElementById('edit_shift_type').value = schedule.shift_type;
            document.getElementById('edit_location').value = schedule.location;
            document.getElementById('edit_details').value = schedule.details;

            // Dátumok formázása
            const start_date = new Date(schedule.start_date);
            const end_date = new Date(schedule.end_date);
            
            document.getElementById('edit_start_date').value = 
                start_date.toISOString().slice(0, 16);
            document.getElementById('edit_end_date').value = 
                end_date.toISOString().slice(0, 16);
                new bootstrap.Modal(document.getElementById('editScheduleModal')).show();
        }

        // Beosztás törlése
        function deleteSchedule(scheduleId) {
            if (confirm('Biztosan törli ezt a beosztást?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'schedule_id';
                input.value = scheduleId;

                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'delete_schedule';
                submitInput.value = '1';

                form.appendChild(input);
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Statisztikai grafikonok
        const shiftsChart = new Chart(document.getElementById('shiftsChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_values($shift_types)); ?>,
                datasets: [{
                    label: 'Műszakok száma',
                    data: <?php
                        $shift_stats = array_count_values(array_column($schedules, 'shift_type'));
                        $shift_data = array_map(function($type) use ($shift_stats) {
                            return $shift_stats[$type] ?? 0;
                        }, array_keys($shift_types));
                        echo json_encode($shift_data);
                    ?>,
                    backgroundColor: [
                        '#ffc107',
                        '#17a2b8',
                        '#6c757d',
                        '#28a745',
                        '#dc3545'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Műszakok megoszlása'
                    }
                }
            }
        });

        const locationsChart = new Chart(document.getElementById('locationsChart'), {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_values($locations)); ?>,
                datasets: [{
                    data: <?php
                        $location_stats = array_count_values(array_column($schedules, 'location'));
                        $location_data = array_map(function($loc) use ($location_stats) {
                            return $location_stats[$loc] ?? 0;
                        }, array_keys($locations));
                        echo json_encode($location_data);
                    ?>,
                    backgroundColor: [
                        '#e67e22',
                        '#27ae60',
                        '#2980b9',
                        '#8e44ad',
                        '#c0392b'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Helyszínek megoszlása'
                    }
                }
            }
        });

        // Sikeres üzenet automatikus eltüntetése
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                }, 3000);
            });
        });

        <?php 
        // Műszaktípusok színkódjai
        function getColorForShiftType($type) {
            $colors = [
                'morning' => '#ffc107',
                'afternoon' => '#17a2b8',
                'night' => '#6c757d',
                'full_day' => '#28a745',
                'custom' => '#dc3545'
            ];
            return $colors[$type] ?? '#6c757d';
        }
        ?>
    </script>
</body>
</html>