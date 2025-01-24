<?php
require_once 'config.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Felhasználó adatainak lekérése
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Felhasználó beosztásainak lekérése
$stmt = $pdo->prepare("
    SELECT * 
    FROM schedules 
    WHERE user_id = ?
    AND start_date >= CURDATE()
    ORDER BY start_date ASC
");
$stmt->execute([$_SESSION['user_id']]);
$schedules = $stmt->fetchAll();

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
    <title>Munkabeosztásom - <?php echo htmlspecialchars($user['username']); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        .header {
            background: linear-gradient(135deg, #0d6efd, #0a58ca);
            padding: 2rem 0;
            margin-bottom: 2rem;
            color: white;
        }

        .schedule-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            margin-bottom: 1rem;
            overflow: hidden;
            transition: transform 0.2s;
        }

        .schedule-card:hover {
            transform: translateY(-0.25rem);
        }

        .schedule-header {
            padding: 1rem;
            border-bottom: 1px solid #dee2e6;
        }

        .schedule-body {
            padding: 1rem;
        }

        .shift-type {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .shift-type.morning { background: #fff3cd; color: #856404; }
        .shift-type.afternoon { background: #d1ecf1; color: #0c5460; }
        .shift-type.night { background: #d6d8d9; color: #383d41; }
        .shift-type.full_day { background: #d4edda; color: #155724; }
        .shift-type.custom { background: #f8d7da; color: #721c24; }

        .fc-event {
            cursor: pointer;
            border: none;
            padding: 0.25rem;
        }

        .fc-event-title {
            font-weight: 500;
            padding: 0.25rem;
        }

        .location-icon {
            width: 40px;
            height: 40px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
        }

        .next-shift {
            background: linear-gradient(135deg, #28a745, #218838);
            color: white;
            padding: 1.5rem;
            border-radius: 1rem;
            margin-bottom: 2rem;
        }

        .next-shift h4 {
            margin: 0;
            font-weight: 500;
        }

        .next-shift p {
            margin: 0;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0">Munkabeosztásom</h1>
                    <p class="mb-0 opacity-75">Üdvözöljük, <?php echo htmlspecialchars($user['username']); ?>!</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="index.php" class="btn btn-light">
                        <i class="fas fa-home me-2"></i>Főoldal
                    </a>
                    <a href="logout.php" class="btn btn-danger">
                        <i class="fas fa-sign-out-alt me-2"></i>Kijelentkezés
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <?php
        // Következő műszak megjelenítése
        $next_shift = null;
        foreach ($schedules as $schedule) {
            if (strtotime($schedule['start_date']) > time()) {
                $next_shift = $schedule;
                break;
            }
        }
        if ($next_shift):
        ?>
        <div class="next-shift">
            <div class="d-flex align-items-center">
                <div class="location-icon">
                    <i class="fas fa-map-marker-alt fa-lg"></i>
                </div>
                <div>
                    <h4>Következő műszak</h4>
                    <p>
                        <?php
                        echo date('Y. m. d. H:i', strtotime($next_shift['start_date'])) . ' - ' . 
                             date('H:i', strtotime($next_shift['end_date'])) . ' | ' .
                             $locations[$next_shift['location']];
                        ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <!-- Naptár nézet -->
                <div class="schedule-card mb-4">
                    <div id="calendar"></div>
                </div>
            </div>
            <div class="col-lg-4">
                <!-- Lista nézet -->
                <h5 class="mb-3">Közelgő műszakok</h5>
                <?php foreach (array_slice($schedules, 0, 5) as $schedule): ?>
                <div class="schedule-card">
                    <div class="schedule-header">
                        <span class="shift-type <?php echo $schedule['shift_type']; ?>">
                            <?php echo $shift_types[$schedule['shift_type']]; ?>
                        </span>
                    </div>
                    <div class="schedule-body">
                        <div class="d-flex align-items-center mb-2">
                            <div class="location-icon">
                                <i class="fas fa-building"></i>
                            </div>
                            <div>
                                <h6 class="mb-0"><?php echo $locations[$schedule['location']]; ?></h6>
                                <small class="text-muted">
                                    <?php echo date('Y. m. d. H:i', strtotime($schedule['start_date'])); ?> - 
                                    <?php echo date('H:i', strtotime($schedule['end_date'])); ?>
                                </small>
                            </div>
                        </div>
                        <?php if ($schedule['details']): ?>
                        <div class="mt-2 p-2 bg-light rounded">
                            <small><?php echo htmlspecialchars($schedule['details']); ?></small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var calendarEl = document.getElementById('calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'timeGridWeek',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'timeGridWeek,timeGridDay'
                },
                locale: 'hu',
                slotMinTime: '06:00:00',
                slotMaxTime: '22:00:00',
                height: 'auto',
                events: <?php 
                    $events = array_map(function($schedule) use ($shift_types, $locations) {
                        return [
                            'title' => $locations[$schedule['location']],
                            'start' => $schedule['start_date'],
                            'end' => $schedule['end_date'],
                            'backgroundColor' => getColorForShiftType($schedule['shift_type']),
                            'description' => $schedule['details']
                        ];
                    }, $schedules);
                    echo json_encode($events);
                ?>,

eventDidMount: function(info) {
                    // Tooltip hozzáadása az eseményekhez
                    if (info.event.extendedProps.description) {
                        info.el.title = info.event.extendedProps.description;
                    }
                }
            });
            calendar.render();
        });

        // Műszaktípusok színkódjai
        <?php 
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