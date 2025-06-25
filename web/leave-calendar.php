<?php
// Load environment variables
$_ENV = parse_ini_file(__DIR__ . '/../shared/.env') ?: [];

function requireEnv(string $key): string {
    if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
        http_response_code(500);
        exit("Missing required environment variable: $key");
    }
    return $_ENV[$key];
}

$token = $_GET['access_token'] ?? '';
if ($token !== requireEnv('CALENDAR_ACCESS_TOKEN')) {
    http_response_code(403);
    exit('Invalid access token');
}

$format = $_GET['format'] ?? 'html';

$mysqli = new mysqli(
    requireEnv('OHRM_DB_HOST'),
    requireEnv('OHRM_DB_USER'),
    requireEnv('OHRM_DB_PASS'),
    requireEnv('OHRM_DB_NAME')
);
if ($mysqli->connect_errno) {
    http_response_code(500);
    exit('MySQL connection failed: ' . $mysqli->connect_error);
}

$fromDate = (new DateTime('now -30 days'))->format('Y-m-d');
$toDate = (new DateTime('now +1 year'))->format('Y-m-d');

$query = "
    SELECT
        l.id,
        l.date,
        l.start_time,
        l.end_time,
        l.duration_type,
        l.leave_request_id,
        l.leave_type_id,
        l.length_days,
        lt.name AS leave_type,
        e.emp_firstname,
        e.emp_lastname
    FROM ohrm_leave l
    JOIN ohrm_leave_type lt ON l.leave_type_id = lt.id
    JOIN hs_hr_employee e ON l.emp_number = e.emp_number
    WHERE l.date BETWEEN ? AND ?
    ORDER BY l.emp_number, l.leave_request_id, l.date
";

$stmt = $mysqli->prepare($query);
$stmt->bind_param('ss', $fromDate, $toDate);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$mysqli->close();

// build color map for leave types
$colorFile = __DIR__ . '/../shared/leave-type-colors.json';
$colorMap = [];
if (file_exists($colorFile)) {
    $colorMap = json_decode(file_get_contents($colorFile), true) ?: [];
}
$palette = [
    '#1abc9c', '#3498db', '#9b59b6', '#f39c12',
    '#e74c3c', '#7f8c8d', '#8e44ad', '#2ecc71', '#e67e22', '#16a085'
];
$paletteIndex = 0;
foreach ($rows as $row) {
    $typeId = $row['leave_type_id'];
    if (!isset($colorMap[$typeId])) {
        $colorMap[$typeId] = $palette[$paletteIndex] ?? sprintf('#%06x', mt_rand(0, 0xFFFFFF));
        $paletteIndex++;
    }
}
file_put_contents($colorFile, json_encode($colorMap, JSON_PRETTY_PRINT));

// build events, grouping full day consecutive leaves
$events = [];
$current = null;
foreach ($rows as $row) {
    $date = new DateTime($row['date']);
    if ($row['duration_type'] == 0) {
        if ($current &&
            $current['request'] == $row['leave_request_id'] &&
            $current['end']->format('Y-m-d') == $date->modify('-1 day')->format('Y-m-d')) {
            // extend current full day event
            $date->modify('+1 day');
            $current['end'] = $date;
            $events[$current['index']]['end'] = $date->format('Y-m-d');
            continue;
        }
        $end = (clone $date)->modify('+1 day');
        $event = [
            'index' => count($events),
            'request' => $row['leave_request_id'],
            'title' => $row['emp_firstname'] . ' ' . $row['emp_lastname'] . ' - ' . $row['leave_type'],
            'start' => $date,
            'end' => $end,
            'allDay' => true,
            'color' => $colorMap[$row['leave_type_id']],
            'leaveType' => $row['leave_type']
        ];
        $events[] = $event;
        $current = &$events[array_key_last($events)];
    } else {
        $start = DateTime::createFromFormat('Y-m-d H:i:s', $row['date'] . ' ' . $row['start_time']);
        $end = DateTime::createFromFormat('Y-m-d H:i:s', $row['date'] . ' ' . $row['end_time']);
        $events[] = [
            'title' => $row['emp_firstname'] . ' ' . $row['emp_lastname'] . ' - ' . $row['leave_type'],
            'start' => $start,
            'end' => $end,
            'allDay' => false,
            'color' => $colorMap[$row['leave_type_id']],
            'leaveType' => $row['leave_type']
        ];
        $current = null;
    }
}

function eventsToIcs(array $events): string {
    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//OrangeHRM//Leave Calendar//EN\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    foreach ($events as $idx => $event) {
        $uid = 'leave-' . $idx . '@orangehrm';
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= 'UID:' . $uid . "\r\n";
        $ics .= 'SUMMARY:' . str_replace('\n', ' ', $event['title']) . "\r\n";
        $ics .= 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        if ($event['allDay']) {
            $ics .= 'DTSTART;VALUE=DATE:' . $event['start']->format('Ymd') . "\r\n";
            $ics .= 'DTEND;VALUE=DATE:' . $event['end']->format('Ymd') . "\r\n";
        } else {
            $ics .= 'DTSTART:' . $event['start']->format('Ymd\THis') . "\r\n";
            $ics .= 'DTEND:' . $event['end']->format('Ymd\THis') . "\r\n";
        }
        $ics .= "END:VEVENT\r\n";
    }
    $ics .= "END:VCALENDAR\r\n";
    return $ics;
}

if ($format === 'ics') {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="leave-calendar.ics"');
    echo eventsToIcs($events);
    exit;
}

if ($format === 'json') {
    header('Content-Type: application/json');
    $data = array_map(function ($e) {
        return [
            'title' => $e['title'],
            'start' => $e['start']->format(DateTime::ATOM),
            'end' => $e['end']->format(DateTime::ATOM),
            'allDay' => $e['allDay'],
            'color' => $e['color'],
            'leaveType' => $e['leaveType']
        ];
    }, $events);
    echo json_encode($data);
    exit;
}

// HTML view
$legendItems = '';
foreach ($colorMap as $typeId => $color) {
    $typeName = null;
    foreach ($rows as $r) {
        if ($r['leave_type_id'] == $typeId) { $typeName = $r['leave_type']; break; }
    }
    if ($typeName) {
        $legendItems .= '<li><span style="display:inline-block;width:12px;height:12px;background:' .
            htmlspecialchars($color, ENT_QUOTES) . ';margin-right:5px;"></span>' .
            htmlspecialchars($typeName) . '</li>';
    }
}
$webcal = 'webcal://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?access_token=' . urlencode($token) . '&format=ics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.css">
<style>
  body { font-family: Arial, sans-serif; padding: 20px; }
  #calendar { max-width: 900px; margin: 0 auto; }
  ul.legend { list-style: none; padding-left: 0; }
  ul.legend li { margin-bottom: 4px; }
</style>
</head>
<body>
<h2>Leave Calendar</h2>
<button id="copyLink">Copy Subscription Link</button>
<div id="calendar"></div>
<h3>Legend</h3>
<ul class="legend">
<?= $legendItems ?>
</ul>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.js"></script>
<script>
const calendarEl = document.getElementById('calendar');
const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    height: 'auto',
    events: '<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?format=json&access_token=<?= urlencode($token) ?>'
});
calendar.render();

document.getElementById('copyLink').addEventListener('click', () => {
    navigator.clipboard.writeText('<?= $webcal ?>');
    alert('Subscription link copied to clipboard');
});
</script>
</body>
</html>
