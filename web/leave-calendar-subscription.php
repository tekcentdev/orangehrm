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

$format = $_GET['format'] ?? 'ics';

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
        l.status,
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

$leaveTypeColors = [
    'Annual leave' => '#1abc9c',
    'Birthday Leave' => '#3498db',
    'Breavement leave' => '#e74c3c',
    'Convalescence Leave' => '#9b59b6',
    "Employee’s Children Marriage" => '#f39c12',
    'Marriage Leave' => '#8e44ad',
    'Maternity Leave' => '#2ecc71',
    'Maternity Leave in working hour' => '#16a085',
    'Occupational accidents or Diseases leave' => '#d35400',
    'Paternity Leave' => '#e67e22',
    'Pregnancy check-up Leave' => '#2980b9',
    'Sick Leave (paid by company)' => '#34495e',
    'Sick Leave (paid by Social Ins Dept)' => '#c0392b',
    'Time-off in Lieu' => '#27ae60',
    'Unpaid Leave' => '#7f8c8d',
    'Work from home' => '#95a5a6'
];
$unapprovedColor = '#bdc3c7';

$events = [];
$current = null;
foreach ($rows as $row) {
    $date = new DateTime($row['date']);
    $color = $leaveTypeColors[$row['leave_type']] ?? '#cccccc';
    if (!in_array($row['status'], [2,3])) {
        $color = $unapprovedColor;
    }
    if ($row['duration_type'] == 0) {
        if ($current &&
            $current['request'] == $row['leave_request_id'] &&
            $current['end']->format('Y-m-d') == $date->modify('-1 day')->format('Y-m-d') &&
            $current['color'] === $color) {
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
            'color' => $color,
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
            'color' => $color,
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
        $ics .= 'SUMMARY:' . str_replace("\n", ' ', $event['title']) . "\r\n";
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

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="leave-calendar.ics"');
echo eventsToIcs($events);
