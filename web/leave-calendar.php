<?php
// --- Load .env securely ---
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
        l.leave_type_id,
        lt.name AS leave_type,
        e.emp_firstname,
        e.emp_lastname
    FROM ohrm_leave l
    JOIN ohrm_leave_type lt ON l.leave_type_id = lt.id
    JOIN hs_hr_employee e ON l.emp_number = e.emp_number
    WHERE l.date BETWEEN ? AND ?
    ORDER BY l.date
";

$stmt = $mysqli->prepare($query);
$stmt->bind_param('ss', $fromDate, $toDate);
$stmt->execute();
$result = $stmt->get_result();

$events = [];
while ($row = $result->fetch_assoc()) {
    $start = new DateTime($row['date']);
    $end = (clone $start)->modify('+1 day');
    $events[] = [
        'uid' => 'leave-' . $row['id'] . '@' . requireEnv('CALENDAR_DOMAIN'),
        'summary' => $row['emp_firstname'] . ' ' . $row['emp_lastname'] . ' - ' . $row['leave_type'],
        'start' => $start->format('Ymd'),
        'end' => $end->format('Ymd')
    ];
}

$stmt->close();
$mysqli->close();

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="leave-calendar.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//OrangeHRM//Leave Calendar//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";

foreach ($events as $event) {
    echo "BEGIN:VEVENT\r\n";
    echo 'UID:' . $event['uid'] . "\r\n";
    echo 'SUMMARY:' . $event['summary'] . "\r\n";
    echo 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
    echo 'DTSTART;VALUE=DATE:' . $event['start'] . "\r\n";
    echo 'DTEND;VALUE=DATE:' . $event['end'] . "\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";

