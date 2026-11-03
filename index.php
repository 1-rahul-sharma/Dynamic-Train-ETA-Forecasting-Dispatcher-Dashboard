<?php
// Show a friendly setup message if MySQL is unavailable or the database is missing.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // Connect to the local MySQL database used by XAMPP.
    $connection = new mysqli('localhost', 'root', '', 'rail_simple');
    $connection->set_charset('utf8mb4');
} catch (mysqli_sql_exception $error) {
    http_response_code(500);
    exit('Could not connect to MySQL. Start MySQL in XAMPP and import setup.sql.');
}

// Escape database values before placing them in the HTML page.
function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Give every delay a consistent operational status and color class.
function getDelayStatus(int $delay): array
{
    if ($delay === 0) {
        return ['On time', 'status-good'];
    }
    if ($delay <= 15) {
        return ['Delayed', 'status-watch'];
    }
    return ['Major delay', 'status-critical'];
}

// Calculate the displayed arrival time from the timetable and forecast delay.
function predictArrivalTime(string $scheduledTime, int $delay): string
{
    $scheduledDate = DateTimeImmutable::createFromFormat('!H:i:s', $scheduledTime);
    if ($scheduledDate === false) {
        return substr($scheduledTime, 0, 5);
    }
    return $scheduledDate->modify('+' . $delay . ' minutes')->format('H:i');
}

// Read the current delay so the event log records the actual change made.
function getTrainDelay(mysqli $connection, int $trainId): ?int
{
    $query = $connection->prepare('SELECT delay_minutes FROM trains WHERE id = ?');
    $query->bind_param('i', $trainId);
    $query->execute();
    $row = $query->get_result()->fetch_assoc();
    $query->close();

    return $row === null ? null : intval($row['delay_minutes']);
}

// Record an immutable event with the train's station after the update.
function recordTrainEvent(
    mysqli $connection,
    int $trainId,
    string $eventType,
    string $title,
    string $details,
    int $delayChange
): void {
    $insert = $connection->prepare(
        'INSERT INTO train_events (train_id, event_type, title, details, delay_change, station_name) '
        . 'SELECT id, ?, ?, ?, ?, current_station FROM trains WHERE id = ?'
    );
    $insert->bind_param('sssii', $eventType, $title, $details, $delayChange, $trainId);
    $insert->execute();
    $insert->close();
}

// Read the selected train ID before it is used in a SQL statement.
$selectedTrainId = 0;
$noticeCode = '';
$incidentReasons = [
    'signal_failure' => 'Signal failure',
    'track_work' => 'Track maintenance',
    'weather' => 'Severe weather',
    'congestion' => 'Route congestion',
    'other' => 'Other disruption'
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedTrainId = intval($_POST['train_id'] ?? 0);
    $stationId = intval($_POST['station_id'] ?? 0);
    $incidentDelay = intval($_POST['incident_delay'] ?? 0);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $reasonKey = is_string($_POST['incident_reason'] ?? null) ? $_POST['incident_reason'] : '';
    $incidentReason = $incidentReasons[$reasonKey] ?? '';
    $incidentNotes = $_POST['incident_details'] ?? '';
    $incidentNotes = is_string($incidentNotes) ? substr(trim($incidentNotes), 0, 180) : '';

    // Keep each accepted train change and its matching audit event together.
    $connection->begin_transaction();
    try {
        if ($selectedTrainId > 0 && $action === 'add_delay') {
            $beforeDelay = getTrainDelay($connection, $selectedTrainId);
            if ($beforeDelay !== null) {
                $update = $connection->prepare('UPDATE trains SET delay_minutes = delay_minutes + 5 WHERE id = ?');
                $update->bind_param('i', $selectedTrainId);
                $update->execute();
                $update->close();
                $afterDelay = getTrainDelay($connection, $selectedTrainId) ?? $beforeDelay;
                recordTrainEvent($connection, $selectedTrainId, 'delay_adjustment', 'Delay adjusted', 'Dispatcher simulation added 5 minutes.', $afterDelay - $beforeDelay);
                $noticeCode = 'add_delay';
            }
        } elseif ($selectedTrainId > 0 && $action === 'recover') {
            $beforeDelay = getTrainDelay($connection, $selectedTrainId);
            if ($beforeDelay !== null) {
                $update = $connection->prepare('UPDATE trains SET delay_minutes = GREATEST(0, delay_minutes - 3) WHERE id = ?');
                $update->bind_param('i', $selectedTrainId);
                $update->execute();
                $update->close();
                $afterDelay = getTrainDelay($connection, $selectedTrainId) ?? $beforeDelay;
                recordTrainEvent($connection, $selectedTrainId, 'delay_adjustment', 'Recovery update', 'Dispatcher simulation applied up to 3 minutes of recovery.', $afterDelay - $beforeDelay);
                $noticeCode = 'recover';
            }
        } elseif ($selectedTrainId > 0 && $stationId > 0 && $action === 'update_station') {
            // Change position only when the submitted stop belongs to this train.
            $update = $connection->prepare('UPDATE trains AS train JOIN stops AS stop ON stop.train_id = train.id SET train.current_station = stop.station_name WHERE train.id = ? AND stop.id = ?');
            $update->bind_param('ii', $selectedTrainId, $stationId);
            $update->execute();
            $positionChanged = $update->affected_rows > 0;
            $update->close();
            if ($positionChanged) {
                recordTrainEvent($connection, $selectedTrainId, 'position_update', 'Position updated', 'Dispatcher updated the current route position.', 0);
                $noticeCode = 'update_station';
            } else {
                $noticeCode = 'station_unchanged';
            }
        } elseif ($selectedTrainId > 0 && $action === 'record_incident') {
            $allowedImpacts = [5, 10, 15, 20];
            if ($incidentReason !== '' && in_array($incidentDelay, $allowedImpacts, true)) {
                $beforeDelay = getTrainDelay($connection, $selectedTrainId);
                if ($beforeDelay !== null) {
                    $update = $connection->prepare('UPDATE trains SET delay_minutes = delay_minutes + ? WHERE id = ?');
                    $update->bind_param('ii', $incidentDelay, $selectedTrainId);
                    $update->execute();
                    $update->close();
                    $afterDelay = getTrainDelay($connection, $selectedTrainId) ?? $beforeDelay;
                    $eventDetails = $incidentNotes === '' ? 'No additional notes provided.' : $incidentNotes;
                    recordTrainEvent($connection, $selectedTrainId, 'incident', $incidentReason, $eventDetails, $afterDelay - $beforeDelay);
                    $noticeCode = 'incident';
                }
            } else {
                $noticeCode = 'invalid_incident';
            }
        }
        $connection->commit();
    } catch (Throwable $error) {
        $connection->rollback();
        throw $error;
    }

    // Redirect after POST so refreshing the page never repeats an update.
    header('Location: index.php?train_id=' . $selectedTrainId . '&updated=' . $noticeCode);
    exit;
}

// Read the train selection and a safe message code from the GET request.
$selectedTrainId = intval($_GET['train_id'] ?? 0);
$noticeCode = $_GET['updated'] ?? '';
$notices = [
    'add_delay' => 'Scenario updated: 5 minutes added to the delay.',
    'recover' => 'Scenario updated: up to 3 minutes recovered.',
    'update_station' => 'Train position updated on the route.',
    'station_unchanged' => 'Position is unchanged or the stop is not on this train route.',
    'incident' => 'Disruption recorded and forecast delay updated.',
    'invalid_incident' => 'Choose a valid disruption reason and delay impact.'
];
$notice = $notices[$noticeCode] ?? '';

// Load the fleet once and calculate the dashboard totals from database values.
$trainResult = $connection->query('SELECT id, train_number, name, current_station, delay_minutes FROM trains ORDER BY train_number');
$allTrains = $trainResult->fetch_all(MYSQLI_ASSOC);
$fleetStats = ['total' => count($allTrains), 'delayed' => 0, 'critical' => 0, 'average' => 0];
$delayTotal = 0;
foreach ($allTrains as $trainRecord) {
    $trainDelay = intval($trainRecord['delay_minutes']);
    $delayTotal += $trainDelay;
    if ($trainDelay > 0) {
        $fleetStats['delayed']++;
    }
    if ($trainDelay > 15) {
        $fleetStats['critical']++;
    }
}
if ($fleetStats['total'] > 0) {
    $fleetStats['average'] = round($delayTotal / $fleetStats['total']);
}

// Load the latest operational changes for the network activity log.
$eventResult = $connection->query(
    'SELECT event.event_type, event.title, event.details, event.delay_change, event.station_name, event.created_at, '
    . 'train.train_number, train.name '
    . 'FROM train_events AS event JOIN trains AS train ON train.id = event.train_id '
    . 'ORDER BY event.created_at DESC, event.id DESC LIMIT 12'
);
$recentEvents = $eventResult->fetch_all(MYSQLI_ASSOC);

// Group route stops once so every train can contribute its next forecasted arrival.
$networkStopResult = $connection->query(
    'SELECT train_id, station_name, `sequence`, scheduled_time FROM stops ORDER BY train_id, `sequence`'
);
$networkStopsByTrain = [];
foreach ($networkStopResult->fetch_all(MYSQLI_ASSOC) as $networkStop) {
    $networkStopsByTrain[intval($networkStop['train_id'])][] = $networkStop;
}
$upcomingArrivals = [];
foreach ($allTrains as $trainRecord) {
    $trainId = intval($trainRecord['id']);
    $trainStops = $networkStopsByTrain[$trainId] ?? [];
    $currentSequence = 0;
    foreach ($trainStops as $trainStop) {
        if ($trainStop['station_name'] === $trainRecord['current_station']) {
            $currentSequence = intval($trainStop['sequence']);
            break;
        }
    }

    foreach ($trainStops as $trainStop) {
        $stopSequence = intval($trainStop['sequence']);
        if ($stopSequence > $currentSequence) {
            $forecastDelay = max(0, intval($trainRecord['delay_minutes']) - 3 * $stopSequence);
            $upcomingArrivals[] = [
                'train_number' => $trainRecord['train_number'],
                'name' => $trainRecord['name'],
                'station_name' => $trainStop['station_name'],
                'scheduled_time' => $trainStop['scheduled_time'],
                'predicted_time' => predictArrivalTime($trainStop['scheduled_time'], $forecastDelay),
                'forecast_delay' => $forecastDelay,
                'status' => getDelayStatus($forecastDelay)
            ];
            break;
        }
    }
}
usort($upcomingArrivals, static function (array $first, array $second): int {
    return strcmp($first['predicted_time'], $second['predicted_time']);
});

// Summarize the forecast impact at every station along the corridor.
$stationResult = $connection->query(
    'SELECT stops.station_name, COUNT(*) AS service_count, '
    . 'SUM(CASE WHEN GREATEST(0, trains.delay_minutes - 3 * stops.`sequence`) > 0 THEN 1 ELSE 0 END) AS affected_count, '
    . 'ROUND(AVG(GREATEST(0, trains.delay_minutes - 3 * stops.`sequence`))) AS average_delay, '
    . 'MAX(GREATEST(0, trains.delay_minutes - 3 * stops.`sequence`)) AS peak_delay, '
    . 'MIN(stops.scheduled_time) AS first_schedule, MAX(stops.scheduled_time) AS last_schedule '
    . 'FROM stops JOIN trains ON trains.id = stops.train_id '
    . 'GROUP BY stops.station_name ORDER BY MIN(stops.`sequence`)'
);
$stationSummary = $stationResult->fetch_all(MYSQLI_ASSOC);

// Find the selected train and load its route stops in sequence order.
$selectedTrain = null;
$routeStops = [];
foreach ($allTrains as $trainRecord) {
    if (intval($trainRecord['id']) === $selectedTrainId) {
        $selectedTrain = $trainRecord;
        break;
    }
}

if ($selectedTrain !== null) {
    $stopQuery = $connection->prepare('SELECT id, station_name, `sequence`, scheduled_time FROM stops WHERE train_id = ? ORDER BY `sequence`');
    $stopQuery->bind_param('i', $selectedTrainId);
    $stopQuery->execute();
    $routeStops = $stopQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $stopQuery->close();

    // Work out the current route position, next stop, and each predicted arrival.
    $currentSequence = 0;
    foreach ($routeStops as $routeStop) {
        if ($routeStop['station_name'] === $selectedTrain['current_station']) {
            $currentSequence = intval($routeStop['sequence']);
            break;
        }
    }

    $nextStop = null;
    $currentDelay = intval($selectedTrain['delay_minutes']);
    foreach ($routeStops as &$routeStop) {
        $stopSequence = intval($routeStop['sequence']);
        $routeStop['predicted_delay'] = max(0, $currentDelay - 3 * $stopSequence);
        $routeStop['predicted_time'] = predictArrivalTime(
            $routeStop['scheduled_time'],
            $routeStop['predicted_delay']
        );
        $routeStop['route_state'] = $stopSequence < $currentSequence
            ? 'passed'
            : ($stopSequence === $currentSequence ? 'current' : 'upcoming');
        $routeStop['delay_class'] = $routeStop['predicted_delay'] < 5
            ? 'status-good'
            : ($routeStop['predicted_delay'] <= 15 ? 'status-watch' : 'status-critical');

        if ($nextStop === null && $stopSequence > $currentSequence) {
            $nextStop = $routeStop;
        }
    }
    unset($routeStop);

    $routeProgress = count($routeStops) > 0 && $currentSequence > 0
        ? min(100, (int) round($currentSequence / count($routeStops) * 100))
        : 0;
    $firstForecastDelay = count($routeStops) > 0 ? intval($routeStops[0]['predicted_delay']) : 0;
    $finalForecastDelay = count($routeStops) > 0 ? intval($routeStops[count($routeStops) - 1]['predicted_delay']) : 0;
    $projectedRecovery = max(0, $currentDelay - $finalForecastDelay);
    $selectedStatus = getDelayStatus($currentDelay);
} else {
    $currentSequence = 0;
    $nextStop = null;
    $routeProgress = 0;
    $selectedStatus = ['', ''];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rail Operations Dashboard</title>
    <style>
        :root {
            color-scheme: light;
            font-family: "Bahnschrift", "Segoe UI", sans-serif;
            color: #172725;
            background: #edf2ef;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 28px 20px 48px;
            background: #edf2ef;
        }

        .dashboard-shell {
            width: min(1180px, 100%);
            margin: 0 auto;
        }

        h1, h2, h3, p { margin-top: 0; }
        h1, h2 { font-family: Georgia, "Times New Roman", serif; font-weight: 500; }
        h1 { margin-bottom: 5px; font-size: 30px; }
        h2 { margin-bottom: 0; font-size: 23px; }
        h3 { margin-bottom: 8px; font-size: 17px; }
        p { line-height: 1.5; }

        .masthead {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 24px;
            padding: 25px 28px;
            border-bottom: 4px solid #e6a23c;
            border-radius: 8px;
            background: #17312e;
            color: #f7faf7;
            animation: enter 350ms ease-out both;
        }

        .brand-lockup { display: flex; align-items: center; gap: 16px; }
        .brand-mark {
            display: grid;
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            place-content: center;
            gap: 4px;
            border: 1px solid #86aaa1;
            border-radius: 5px;
        }
        .brand-mark span { display: block; width: 23px; height: 3px; background: #f0b451; }
        .brand-mark span:nth-child(2) { width: 17px; margin-left: 6px; background: #a9c5bb; }
        .eyebrow {
            margin-bottom: 6px;
            color: #55736b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .masthead .eyebrow { color: #b6d0c6; }
        .masthead p:last-child { margin-bottom: 0; color: #d0ddd7; font-size: 14px; }
        .feed-state { display: grid; justify-items: end; gap: 7px; color: #d0ddd7; font-size: 12px; }
        .feed-label { display: flex; align-items: center; gap: 8px; color: #f7faf7; font-weight: 800; }
        .signal-dot { width: 8px; height: 8px; border-radius: 50%; background: #80c69c; box-shadow: 0 0 0 4px #31564b; }

        .metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin: 18px 0 26px;
        }
        .metric {
            min-height: 112px;
            padding: 16px 18px;
            border: 1px solid #d6e0da;
            border-top: 3px solid #4d8174;
            border-radius: 5px;
            background: #ffffff;
        }
        .metric:nth-child(2) { border-top-color: #e6a23c; }
        .metric:nth-child(3) { border-top-color: #bd5549; }
        .metric:nth-child(4) { border-top-color: #5b7f9d; }
        .metric-label, .metric-note { display: block; color: #60716c; font-size: 12px; }
        .metric-value { display: block; margin: 7px 0 2px; font-size: 30px; font-weight: 800; }

        .section { padding: 22px 0; border-top: 1px solid #cad6d0; }
        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 16px;
            margin-bottom: 16px;
        }
        .section-heading h2 { margin-bottom: 0; }
        .section-heading .eyebrow { margin-bottom: 4px; }
        .toolbar-form, .actions, .position-form {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 12px;
        }
        label { display: grid; gap: 6px; color: #4b5e58; font-size: 12px; font-weight: 800; }
        select, button, .button-link {
            min-height: 42px;
            padding: 9px 13px;
            border: 1px solid #aebdb6;
            border-radius: 4px;
            background: #ffffff;
            color: #172725;
            font: inherit;
        }
        select { min-width: 260px; }
        button, .button-link { cursor: pointer; font-weight: 800; text-decoration: none; }
        button:hover, .button-link:hover { background: #eef4f0; }
        button.primary, .button-link.primary { border-color: #245c50; background: #245c50; color: #ffffff; }
        button.primary:hover, .button-link.primary:hover { background: #19493f; }
        .notice { margin: 0 0 18px; padding: 12px 15px; border-left: 4px solid #4d8174; background: #e1eee7; color: #214b3f; font-weight: 700; }

        .table-scroll { overflow-x: auto; border: 1px solid #d6e0da; border-radius: 5px; background: #ffffff; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 13px 14px; border-bottom: 1px solid #e0e7e2; white-space: nowrap; }
        th { background: #e8efeb; color: #50635c; font-size: 11px; text-transform: uppercase; }
        tbody tr:last-child td { border-bottom: 0; }
        tbody tr:hover { background: #f6f9f7; }
        .train-name { display: block; font-weight: 800; }
        .muted { display: block; margin-top: 3px; color: #6a7b75; font-size: 12px; }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 3px; font-size: 11px; font-weight: 800; }
        .status-good { color: #206344; background: #e1f1e7; }
        .status-watch { color: #88550b; background: #fff0d2; }
        .status-critical { color: #9e332b; background: #fce4e0; }
        .button-link { display: inline-flex; min-height: 34px; align-items: center; padding: 6px 10px; border-color: #c4d2cb; font-size: 12px; }

        .service-heading { display: flex; justify-content: space-between; align-items: center; gap: 18px; margin-bottom: 15px; }
        .service-heading h2 { margin-bottom: 4px; }
        .service-heading .eyebrow { margin-bottom: 2px; }
        .service-meta { display: flex; flex-wrap: wrap; gap: 8px 28px; padding: 13px 0; border-top: 1px solid #d6e0da; border-bottom: 1px solid #d6e0da; }
        .meta-item { color: #60716c; font-size: 12px; }
        .meta-item strong { display: block; margin-top: 4px; color: #172725; font-size: 14px; }
        .detail-layout { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(250px, 0.8fr); gap: 28px; padding-top: 22px; }
        .progress-summary { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 10px; color: #53665e; font-size: 12px; }
        .progress-track { height: 8px; overflow: hidden; border-radius: 8px; background: #dce5df; }
        .progress-track span { display: block; height: 100%; border-radius: inherit; background: #43816e; transition: width 350ms ease; }
        .route-nodes { display: flex; justify-content: space-between; gap: 5px; margin: 19px 0 25px; padding: 0; list-style: none; }
        .route-node { position: relative; display: grid; justify-items: center; gap: 7px; min-width: 0; flex: 1; color: #71817b; font-size: 11px; text-align: center; }
        .route-node:not(:last-child)::after { position: absolute; z-index: 0; top: 6px; left: calc(50% + 8px); width: calc(100% - 11px); height: 2px; background: #d5dfd9; content: ""; }
        .route-node.passed:not(:last-child)::after { background: #43816e; }
        .route-dot { z-index: 1; width: 14px; height: 14px; border: 3px solid #c7d3cc; border-radius: 50%; background: #ffffff; }
        .route-node.passed .route-dot { border-color: #43816e; background: #43816e; }
        .route-node.current { color: #173d33; font-weight: 800; }
        .route-node.current .route-dot { border-color: #e6a23c; background: #e6a23c; box-shadow: 0 0 0 4px #fff0d2; }
        .route-node.upcoming .route-dot { border-color: #c7d3cc; }
        .schedule-heading { margin: 22px 0 12px; font-family: Georgia, "Times New Roman", serif; font-size: 18px; font-weight: 500; }
        .eta-delay { font-weight: 800; }
        .route-label { display: inline-block; margin-left: 7px; color: #245c50; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .forecast-profile { margin-top: 24px; padding-top: 18px; border-top: 1px solid #d6e0da; }
        .profile-heading { display: flex; justify-content: space-between; align-items: end; gap: 12px; margin-bottom: 14px; }
        .profile-heading h3 { margin-bottom: 0; font-family: Georgia, "Times New Roman", serif; font-size: 19px; font-weight: 500; }
        .profile-rate { color: #64756e; font-size: 11px; white-space: nowrap; }
        .profile-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 14px; }
        .profile-stat { padding: 9px 11px; border: 1px solid #dce5df; border-radius: 4px; background: #f7f9f8; }
        .profile-stat span { display: block; color: #64756e; font-size: 10px; }
        .profile-stat strong { display: block; margin-top: 4px; font-size: 16px; }
        .recovery-chart { display: flex; align-items: flex-end; gap: 12px; height: 184px; padding: 12px 12px 0; border-bottom: 1px solid #bdcbc3; background: repeating-linear-gradient(to top, #ffffff 0, #ffffff 39px, #e8eeea 40px, #ffffff 41px); }
        .recovery-item { display: grid; grid-template-rows: 18px minmax(0, 1fr) 28px; justify-items: center; flex: 1; min-width: 0; height: 100%; }
        .recovery-value { color: #4e625a; font-size: 10px; font-weight: 800; }
        .recovery-bar-track { display: flex; align-items: flex-end; width: min(52px, 75%); height: 100%; background: rgba(224, 233, 227, 0.55); }
        .recovery-bar-fill { display: block; width: 100%; min-height: 2px; border-radius: 3px 3px 0 0; }
        .recovery-bar-fill.status-good { background: #4d9a70; }
        .recovery-bar-fill.status-watch { background: #e6a23c; }
        .recovery-bar-fill.status-critical { background: #c75c50; }
        .recovery-station { align-self: end; max-width: 100%; overflow-wrap: anywhere; color: #53665e; font-size: 10px; text-align: center; }
        .ops-aside { padding-left: 24px; border-left: 1px solid #d6e0da; }
        .next-stop-block { padding: 0 0 20px; border-bottom: 1px solid #d6e0da; }
        .next-stop-name { margin: 3px 0; font-family: Georgia, "Times New Roman", serif; font-size: 25px; }
        .next-stop-time { margin-bottom: 8px; color: #245c50; font-size: 24px; font-weight: 800; }
        .control-block { padding-top: 20px; }
        .control-block > p { margin-bottom: 14px; color: #62736d; font-size: 12px; }
        .actions { align-items: stretch; }
        .actions button { flex: 1; }
        .position-form { display: grid; align-items: stretch; gap: 9px; margin-top: 17px; }
        .position-form select { width: 100%; min-width: 0; }
        .position-form button { width: 100%; }
        .incident-form { display: grid; gap: 10px; margin-top: 20px; padding-top: 18px; border-top: 1px solid #d6e0da; }
        input[type="text"] { width: 100%; min-height: 40px; padding: 9px 11px; border: 1px solid #aebdb6; border-radius: 4px; background: #ffffff; color: #172725; font: inherit; }
        .audit-list { border-top: 1px solid #d6e0da; }
        .audit-entry { display: grid; grid-template-columns: 145px minmax(0, 1fr) 145px 90px; align-items: center; gap: 16px; padding: 14px 10px; border-bottom: 1px solid #d6e0da; }
        .audit-time, .audit-station { color: #64756e; font-size: 12px; }
        .audit-description strong { display: block; margin-bottom: 3px; }
        .audit-description span { color: #64756e; font-size: 12px; }
        .audit-delta { justify-self: end; padding: 5px 8px; border-radius: 3px; font-size: 11px; font-weight: 800; white-space: nowrap; }
        .model-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px; }
        .model-item { padding-left: 13px; border-left: 3px solid #e6a23c; }
        .model-item strong { display: block; margin-bottom: 5px; }
        .model-item span { color: #64756e; font-size: 12px; line-height: 1.5; }
        .empty-state { padding: 20px; color: #697a73; text-align: center; }
        .page-footer { padding-top: 18px; color: #687972; font-size: 11px; text-align: right; }

        @keyframes enter {
            from { opacity: 0; transform: translateY(7px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 560px) {
            body { padding: 14px 12px 30px; }
            .masthead { align-items: flex-start; padding: 19px 17px; }
            .brand-lockup { align-items: flex-start; gap: 11px; }
            .brand-mark { width: 38px; height: 38px; flex-basis: 38px; }
            .brand-mark span { width: 19px; }
            .brand-mark span:nth-child(2) { width: 14px; margin-left: 5px; }
            h1 { font-size: 25px; }
            .feed-state { display: none; }
            .metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin: 12px 0 19px; }
            .metric { min-height: 99px; padding: 12px; }
            .metric-value { font-size: 26px; }
            .section { padding: 18px 0; }
            .section-heading { align-items: flex-start; flex-direction: column; }
            .toolbar-form, .toolbar-form label, .toolbar-form select, .toolbar-form button { width: 100%; }
            .service-heading { align-items: flex-start; flex-direction: column; }
            .detail-layout { grid-template-columns: 1fr; gap: 22px; }
            .ops-aside { padding: 20px 0 0; border-top: 1px solid #d6e0da; border-left: 0; }
            .route-node { font-size: 9px; }
            .route-nodes { margin-bottom: 20px; }
            .profile-heading { align-items: flex-start; flex-direction: column; }
            .profile-stats { gap: 5px; }
            .profile-stat { padding: 8px 6px; }
            .profile-stat span { font-size: 9px; }
            .recovery-chart { gap: 5px; padding-right: 5px; padding-left: 5px; }
            .recovery-station { font-size: 9px; }
            .audit-entry { grid-template-columns: 1fr auto; gap: 8px 12px; }
            .audit-time { grid-column: 1 / -1; }
            .audit-station { grid-column: 1; }
            .audit-delta { grid-column: 2; grid-row: 3; }
            .model-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 12px; }
            th, td { padding: 11px 10px; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body>
<main class="dashboard-shell">
    <header class="masthead">
        <div class="brand-lockup">
            <div class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></div>
            <div>
                <p class="eyebrow">Network control / Western corridor</p>
                <h1>Rail Operations</h1>
                <p>Service health, route milestones, and arrival forecasts</p>
            </div>
        </div>
        <div class="feed-state">
            <span class="feed-label"><span class="signal-dot"></span> DEMONSTRATION FEED</span>
            <span>Refreshed <?= date('D, d M Y - H:i') ?></span>
        </div>
    </header>

    <!-- Summarize delay conditions across the train fleet. -->
    <section class="metrics" aria-label="Fleet summary">
        <div class="metric">
            <span class="metric-label">Tracked services</span>
            <strong class="metric-value"><?= $fleetStats['total'] ?></strong>
            <span class="metric-note">In this corridor</span>
        </div>
        <div class="metric">
            <span class="metric-label">Delayed services</span>
            <strong class="metric-value"><?= $fleetStats['delayed'] ?></strong>
            <span class="metric-note">Current delay above zero</span>
        </div>
        <div class="metric">
            <span class="metric-label">Major delays</span>
            <strong class="metric-value"><?= $fleetStats['critical'] ?></strong>
            <span class="metric-note">More than 15 minutes</span>
        </div>
        <div class="metric">
            <span class="metric-label">Average delay</span>
            <strong class="metric-value"><?= intval($fleetStats['average']) ?><small> min</small></strong>
            <span class="metric-note">Across tracked services</span>
        </div>
    </section>

    <!-- Select a service through a GET request. -->
    <section class="section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Service lookup</p>
                <h2>Train detail</h2>
            </div>
            <form class="toolbar-form" method="get" action="index.php">
                <label for="train_id">Train service
                    <select id="train_id" name="train_id" required>
                        <option value="">Choose a train</option>
                        <?php foreach ($allTrains as $trainOption): ?>
                            <option value="<?= intval($trainOption['id']) ?>" <?= intval($trainOption['id']) === $selectedTrainId ? 'selected' : '' ?>>
                                <?= escapeHtml($trainOption['train_number']) ?> - <?= escapeHtml($trainOption['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="primary" type="submit">Open service</button>
            </form>
        </div>

        <?php if ($notice !== ''): ?>
            <p class="notice" role="status"><?= escapeHtml($notice) ?></p>
        <?php endif; ?>

        <?php if ($selectedTrain !== null): ?>
            <!-- Show current position and calculated progress for the selected route. -->
            <article class="service-detail">
                <div class="service-heading">
                    <div>
                        <p class="eyebrow">Service <?= escapeHtml($selectedTrain['train_number']) ?></p>
                        <h2><?= escapeHtml($selectedTrain['name']) ?></h2>
                    </div>
                    <span class="status-badge <?= escapeHtml($selectedStatus[1]) ?>"><?= escapeHtml($selectedStatus[0]) ?></span>
                </div>

                <div class="service-meta">
                    <div class="meta-item">Current station<strong><?= escapeHtml($selectedTrain['current_station']) ?></strong></div>
                    <div class="meta-item">Current delay<strong><?= intval($selectedTrain['delay_minutes']) ?> min</strong></div>
                    <div class="meta-item">Route progress<strong><?= $currentSequence > 0 ? $currentSequence . ' of ' . count($routeStops) : 'Position not on route' ?></strong></div>
                </div>

                <div class="detail-layout">
                    <div class="route-column">
                        <section aria-label="Route progress">
                            <div class="progress-summary">
                                <span><?= $currentSequence > 0 ? 'Route progress' : 'Route position unavailable' ?></span>
                                <strong><?= $routeProgress ?>%</strong>
                            </div>
                            <div class="progress-track" role="progressbar" aria-label="Route progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $routeProgress ?>">
                                <span style="width: <?= $routeProgress ?>%"></span>
                            </div>
                            <ol class="route-nodes">
                                <?php foreach ($routeStops as $routeStop): ?>
                                    <li class="route-node <?= escapeHtml($routeStop['route_state']) ?>">
                                        <span class="route-dot"></span>
                                        <span><?= escapeHtml($routeStop['station_name']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </section>

                        <h3 class="schedule-heading">Predicted timetable</h3>
                        <div class="table-scroll">
                            <table>
                                <thead>
                                <tr>
                                    <th>Stop</th>
                                    <th>Scheduled</th>
                                    <th>Predicted</th>
                                    <th>Delay at stop</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (count($routeStops) > 0): ?>
                                    <?php foreach ($routeStops as $routeStop): ?>
                                        <tr>
                                            <td>
                                                <?= escapeHtml($routeStop['station_name']) ?>
                                                <?php if ($routeStop['route_state'] === 'current'): ?><span class="route-label">Current</span><?php endif; ?>
                                            </td>
                                            <td><?= escapeHtml(substr($routeStop['scheduled_time'], 0, 5)) ?></td>
                                            <td><strong><?= escapeHtml($routeStop['predicted_time']) ?></strong></td>
                                            <td class="eta-delay <?= escapeHtml($routeStop['delay_class']) ?>"><?= intval($routeStop['predicted_delay']) ?> min</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td class="empty-state" colspan="4">No route stops are configured.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Visualize the forecast delay at each station on this route. -->
                        <section class="forecast-profile" aria-labelledby="forecast-profile-title">
                            <div class="profile-heading">
                                <div>
                                    <p class="eyebrow">Delay projection</p>
                                    <h3 id="forecast-profile-title">Recovery profile</h3>
                                </div>
                                <span class="profile-rate">3 min recovery / stop</span>
                            </div>
                            <div class="profile-stats">
                                <div class="profile-stat">
                                    <span>First stop delay</span>
                                    <strong><?= $firstForecastDelay ?> min</strong>
                                </div>
                                <div class="profile-stat">
                                    <span>Projected recovery</span>
                                    <strong><?= $projectedRecovery ?> min</strong>
                                </div>
                                <div class="profile-stat">
                                    <span>Final stop delay</span>
                                    <strong><?= $finalForecastDelay ?> min</strong>
                                </div>
                            </div>
                            <div class="recovery-chart" role="img" aria-label="Forecast delay at each route stop">
                                <?php foreach ($routeStops as $routeStop): ?>
                                    <?php
                                    $barHeight = $currentDelay > 0
                                        ? (int) round(intval($routeStop['predicted_delay']) / $currentDelay * 100)
                                        : 0;
                                    ?>
                                    <div class="recovery-item">
                                        <span class="recovery-value"><?= intval($routeStop['predicted_delay']) ?> min</span>
                                        <div class="recovery-bar-track" aria-hidden="true">
                                            <span class="recovery-bar-fill <?= escapeHtml($routeStop['delay_class']) ?>" style="height: <?= $barHeight ?>%"></span>
                                        </div>
                                        <span class="recovery-station"><?= escapeHtml($routeStop['station_name']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    </div>

                    <aside class="ops-aside">
                        <section class="next-stop-block">
                            <p class="eyebrow">Next stop</p>
                            <?php if ($nextStop !== null): ?>
                                <h3 class="next-stop-name"><?= escapeHtml($nextStop['station_name']) ?></h3>
                                <p class="next-stop-time"><?= escapeHtml($nextStop['predicted_time']) ?></p>
                                <span class="status-badge <?= escapeHtml($nextStop['delay_class']) ?>">
                                    <?= intval($nextStop['predicted_delay']) ?> min forecast delay
                                </span>
                            <?php else: ?>
                                <h3 class="next-stop-name">Route complete</h3>
                                <p class="muted">No remaining stops are scheduled.</p>
                            <?php endif; ?>
                        </section>

                        <section class="control-block">
                            <p class="eyebrow">Scenario controls</p>
                            <h3>Adjust service</h3>
                            <p>These controls update the demonstration data in MySQL.</p>
                            <form class="actions" method="post" action="index.php">
                                <input type="hidden" name="train_id" value="<?= intval($selectedTrain['id']) ?>">
                                <button type="submit" name="action" value="add_delay">+5 min delay</button>
                                <button type="submit" name="action" value="recover">-3 min recover</button>
                            </form>

                            <form class="position-form" method="post" action="index.php">
                                <input type="hidden" name="train_id" value="<?= intval($selectedTrain['id']) ?>">
                                <label for="station_id">Set current station
                                    <select id="station_id" name="station_id" required>
                                        <?php foreach ($routeStops as $routeStop): ?>
                                            <option value="<?= intval($routeStop['id']) ?>" <?= $routeStop['station_name'] === $selectedTrain['current_station'] ? 'selected' : '' ?>>
                                                <?= escapeHtml($routeStop['station_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="primary" type="submit" name="action" value="update_station">Update position</button>
                            </form>

                            <form class="incident-form" method="post" action="index.php">
                                <input type="hidden" name="train_id" value="<?= intval($selectedTrain['id']) ?>">
                                <label for="incident_reason">Disruption reason
                                    <select id="incident_reason" name="incident_reason" required>
                                        <option value="signal_failure">Signal failure</option>
                                        <option value="track_work">Track maintenance</option>
                                        <option value="weather">Severe weather</option>
                                        <option value="congestion">Route congestion</option>
                                        <option value="other">Other disruption</option>
                                    </select>
                                </label>
                                <label for="incident_delay">Estimated impact
                                    <select id="incident_delay" name="incident_delay" required>
                                        <option value="5">5 minutes</option>
                                        <option value="10">10 minutes</option>
                                        <option value="15">15 minutes</option>
                                        <option value="20">20 minutes</option>
                                    </select>
                                </label>
                                <label for="incident_details">Dispatcher notes
                                    <input id="incident_details" name="incident_details" type="text" maxlength="180" placeholder="Optional details">
                                </label>
                                <button class="primary" type="submit" name="action" value="record_incident">Record disruption</button>
                            </form>
                        </section>
                    </aside>
                </div>
            </article>
        <?php elseif ($selectedTrainId > 0): ?>
            <p class="empty-state">Train service not found.</p>
        <?php endif; ?>
    </section>

    <!-- Keep the complete fleet visible for quick service selection. -->
    <section class="section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Network overview</p>
                <h2>Tracked fleet</h2>
            </div>
            <span class="muted"><?= $fleetStats['total'] ?> services</span>
        </div>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>Train service</th>
                    <th>Current station</th>
                    <th>Service status</th>
                    <th>Current delay</th>
                    <th>Route view</th>
                </tr>
                </thead>
                <tbody>
                <?php if (count($allTrains) > 0): ?>
                    <?php foreach ($allTrains as $trainRecord): ?>
                        <?php $fleetStatus = getDelayStatus(intval($trainRecord['delay_minutes'])); ?>
                        <tr>
                            <td>
                                <span class="train-name"><?= escapeHtml($trainRecord['name']) ?></span>
                                <span class="muted"><?= escapeHtml($trainRecord['train_number']) ?></span>
                            </td>
                            <td><?= escapeHtml($trainRecord['current_station']) ?></td>
                            <td><span class="status-badge <?= escapeHtml($fleetStatus[1]) ?>"><?= escapeHtml($fleetStatus[0]) ?></span></td>
                            <td><strong><?= intval($trainRecord['delay_minutes']) ?> min</strong></td>
                            <td><a class="button-link" href="index.php?train_id=<?= intval($trainRecord['id']) ?>">Open service</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td class="empty-state" colspan="5">No train services are configured.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Show the next forecasted arrival for every service still on its route. -->
    <section class="section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Live-style board</p>
                <h2>Upcoming arrivals</h2>
            </div>
            <span class="muted"><?= count($upcomingArrivals) ?> services en route</span>
        </div>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>Train service</th>
                    <th>Next station</th>
                    <th>Scheduled</th>
                    <th>Forecast ETA</th>
                    <th>Delay at arrival</th>
                </tr>
                </thead>
                <tbody>
                <?php if (count($upcomingArrivals) > 0): ?>
                    <?php foreach ($upcomingArrivals as $arrival): ?>
                        <tr>
                            <td>
                                <span class="train-name"><?= escapeHtml($arrival['name']) ?></span>
                                <span class="muted"><?= escapeHtml($arrival['train_number']) ?></span>
                            </td>
                            <td><?= escapeHtml($arrival['station_name']) ?></td>
                            <td><?= escapeHtml(substr($arrival['scheduled_time'], 0, 5)) ?></td>
                            <td><strong><?= escapeHtml($arrival['predicted_time']) ?></strong></td>
                            <td><span class="status-badge <?= escapeHtml($arrival['status'][1]) ?>"><?= intval($arrival['forecast_delay']) ?> min</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td class="empty-state" colspan="5">No upcoming stops are available.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Compare forecast delay exposure across each station on the corridor. -->
    <section class="section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Corridor analysis</p>
                <h2>Station activity</h2>
            </div>
            <span class="muted"><?= count($stationSummary) ?> route stations</span>
        </div>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>Station</th>
                    <th>Scheduled services</th>
                    <th>Forecast affected</th>
                    <th>Average delay</th>
                    <th>Peak delay</th>
                    <th>Schedule window</th>
                </tr>
                </thead>
                <tbody>
                <?php if (count($stationSummary) > 0): ?>
                    <?php foreach ($stationSummary as $station): ?>
                        <tr>
                            <td><strong><?= escapeHtml($station['station_name']) ?></strong></td>
                            <td><?= intval($station['service_count']) ?></td>
                            <td><?= intval($station['affected_count']) ?> services</td>
                            <td><?= intval($station['average_delay']) ?> min</td>
                            <td><?= intval($station['peak_delay']) ?> min</td>
                            <td><?= escapeHtml(substr($station['first_schedule'], 0, 5)) ?> - <?= escapeHtml(substr($station['last_schedule'], 0, 5)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td class="empty-state" colspan="6">No station schedule data is available.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Explain the assumptions behind the ETA for a clear project demonstration. -->
    <section class="section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Forecast method</p>
                <h2>Model assumptions</h2>
            </div>
        </div>
        <div class="model-grid">
            <div class="model-item">
                <strong>Delay input</strong>
                <span>Uses the dispatcher-reported delay stored for each service.</span>
            </div>
            <div class="model-item">
                <strong>Recovery rate</strong>
                <span>Reduces forecast delay by 3 minutes at every successive stop.</span>
            </div>
            <div class="model-item">
                <strong>Arrival estimate</strong>
                <span>Scheduled time plus the remaining forecast delay, never below zero.</span>
            </div>
            <div class="model-item">
                <strong>Data source</strong>
                <span>Demonstration timetable and dispatcher entries; no live railway feed.</span>
            </div>
        </div>
    </section>

    <!-- Display the newest dispatcher actions and disruption reports. -->
    <section class="section">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Audit trail</p>
                <h2>Recent operations</h2>
            </div>
            <span class="muted">Latest <?= count($recentEvents) ?> events</span>
        </div>
        <?php if (count($recentEvents) > 0): ?>
            <div class="audit-list">
                <?php foreach ($recentEvents as $event): ?>
                    <?php
                    $eventClass = $event['event_type'] === 'incident'
                        ? 'status-critical'
                        : ($event['event_type'] === 'position_update' ? 'status-good' : 'status-watch');
                    $delayChange = intval($event['delay_change']);
                    ?>
                    <article class="audit-entry">
                        <time class="audit-time" datetime="<?= escapeHtml(date('c', strtotime($event['created_at']))) ?>">
                            <?= escapeHtml(date('d M Y, H:i', strtotime($event['created_at']))) ?>
                        </time>
                        <div class="audit-description">
                            <strong><?= escapeHtml($event['title']) ?> / <?= escapeHtml($event['train_number']) ?></strong>
                            <span><?= escapeHtml($event['name']) ?>: <?= escapeHtml($event['details']) ?></span>
                        </div>
                        <span class="audit-station">At <?= escapeHtml($event['station_name']) ?></span>
                        <span class="audit-delta <?= escapeHtml($eventClass) ?>">
                            <?= $delayChange > 0 ? '+' : '' ?><?= $delayChange ?> min
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="empty-state">No operational events recorded yet.</p>
        <?php endif; ?>
    </section>

    <footer class="page-footer">ETA rule: 3 minutes recovered per scheduled stop | Forecast demonstration</footer>
</main>
</body>
</html>
<?php
// Release the fleet result and database connection after rendering the page.
$trainResult->free();
$connection->close();
?>