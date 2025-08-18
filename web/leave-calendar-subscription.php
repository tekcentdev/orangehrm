<?php
// Load environment variables from `shared/.env` or fallback to `.env`
$_ENV = parse_ini_file(__DIR__ . '/../shared/.env')
    ?: parse_ini_file(__DIR__ . '/../.env')
    ?: [];
if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', 'On');
}

// Autoload dependencies so we can access entity constants
require __DIR__ . '/../src/vendor/autoload.php';

use OrangeHRM\Entity\Leave;

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
        l.length_hours,
        l.status,
        lt.name AS leave_type,
        e.emp_firstname,
        e.emp_lastname,
        (
            SELECT ol.name
            FROM hs_hr_emp_locations el
            LEFT JOIN ohrm_location ol ON el.location_id = ol.id
            WHERE el.emp_number = e.emp_number
            ORDER BY el.location_id
            LIMIT 1
        ) AS location_name
    FROM ohrm_leave l
    JOIN ohrm_leave_type lt ON l.leave_type_id = lt.id
    JOIN hs_hr_employee e ON l.emp_number = e.emp_number
    WHERE l.date BETWEEN ? AND ?
        AND l.status IN (?, ?, ?)
    ORDER BY l.emp_number, l.leave_request_id, l.date
";

$stmt = $mysqli->prepare($query);
$pendingStatus = Leave::LEAVE_STATUS_LEAVE_PENDING_APPROVAL;
$approvedStatus = Leave::LEAVE_STATUS_LEAVE_APPROVED;
$takenStatus = Leave::LEAVE_STATUS_LEAVE_TAKEN;
$stmt->bind_param(
    'ssiii',
    $fromDate,
    $toDate,
    $pendingStatus,
    $approvedStatus,
    $takenStatus
);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$mysqli->close();

function locationToTimezone(?string $location): string {
    $map = [
        'Hong Kong' => 'Asia/Hong_Kong',
        'Hong Kong Office' => 'Asia/Hong_Kong',
        'Vietnam' => 'Asia/Ho_Chi_Minh',
        'Ho Chi Minh' => 'Asia/Ho_Chi_Minh',
        'London' => 'Europe/London',
    ];
    return $location !== null && isset($map[$location])
        ? $map[$location]
        : 'Asia/Hong_Kong';
}

function normalizeLeaveType(string $type): string {

    $lower = strtolower($type);
    if (strpos($lower, 'annual') !== false) {
        return 'Annual leave';
    }
    if (strpos($lower, 'sick') !== false) {
        return 'Sick Leave';
    }
    if (strpos($lower, 'work from home') !== false) {
        return 'Work from home';
    }
    if (strpos($lower, 'travel') !== false) {
        return 'Travel';
    }
    return $type;
}

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
    'Sick Leave' => '#34495e',
    'Time-off in Lieu' => '#27ae60',
    'Work from home' => '#95a5a6'
];
$leaveTypeEmoji = [
    'Annual leave' => '🏖️',
    'Sick Leave' => '🤒',
    'Work from home' => '🏡',
    'Travel' => '✈️',
    'Birthday Leave' => '🎂'
];
$unapprovedColor = '#bdc3c7';

$events = [];
$current = null;
foreach ($rows as $row) {
    $timezone = locationToTimezone($row['location_name'] ?? null);
    $date = new DateTime($row['date'], new DateTimeZone($timezone));
    $leaveType = normalizeLeaveType($row['leave_type']);
    $color = $leaveTypeColors[$leaveType] ?? '#cccccc';
    if (!in_array($row['status'], [2,3])) {
        $color = $unapprovedColor;
    }

    $name = $row['emp_firstname'] . ' ' . $row['emp_lastname'];
    $emoji = $leaveTypeEmoji[$leaveType] ?? '';

    $status = in_array($row['status'], [Leave::LEAVE_STATUS_LEAVE_APPROVED, Leave::LEAVE_STATUS_LEAVE_TAKEN])
        ? 'CONFIRMED'
        : 'TENTATIVE';
    // Map to valid ICS status values
    $icsStatus = $status === 'CONFIRMED' ? 'CONFIRMED' : 'TENTATIVE';

    $fullDay = $row['duration_type'] == 0 || (isset($row['length_hours']) && (float)$row['length_hours'] >= 8);
    if ($fullDay) {
        if ($current &&
            $current['request'] == $row['leave_request_id'] &&
            $current['end']->format('Y-m-d') == $date->format('Y-m-d') &&
            $current['color'] === $color) {
            $current['end']->modify('+1 day');
            continue;
        }
        $end = (clone $date)->modify('+1 day');
        $event = [
            'id' => $row['id'],
            'request' => $row['leave_request_id'],
            'title' => $name,
            'emoji' => $emoji,
            'summary' => trim($name . ' ' . $emoji),
            'start' => $date,
            'end' => $end,
            'allDay' => true,
            'color' => $color,
            'leaveType' => $leaveType,
            'timezone' => $timezone,
            'status' => $status

        ];
        $events[] = $event;
        $current = &$events[array_key_last($events)];
    } else {
        $tzObj = new DateTimeZone($timezone);
        $start = DateTime::createFromFormat('Y-m-d H:i:s', $row['date'] . ' ' . $row['start_time'], $tzObj);
        $end = DateTime::createFromFormat('Y-m-d H:i:s', $row['date'] . ' ' . $row['end_time'], $tzObj);
        $events[] = [
            'id' => $row['id'],
            'title' => $name,
            'emoji' => $emoji,
            'summary' => trim($name . ' ' . $emoji),
            'start' => $start,
            'end' => $end,
            'allDay' => false,
            'color' => $color,
            'leaveType' => $leaveType,
            'timezone' => $timezone,
            'status' => $status
        ];
        unset($current);
        $current = null;
    }
}

function eventsToIcs(array $events): string {
    $ics = "BEGIN:VCALENDAR\r\n";
    $ics .= "VERSION:2.0\r\n";
    $ics .= "PRODID:-//OrangeHRM//Leave Calendar//EN\r\n";
    $ics .= "CALSCALE:GREGORIAN\r\n";
    $ics .= "METHOD:PUBLISH\r\n";
    // Simplified timezone definitions for better compatibility
    $timezones = [
        'Asia/Hong_Kong' => '+0800',
        'Asia/Ho_Chi_Minh' => '+0700',
        'Europe/London' => '+0000',
    ];
    foreach ($timezones as $tzId => $offset) {
        $ics .= "BEGIN:VTIMEZONE\r\n";
        $ics .= "TZID:" . $tzId . "\r\n";
        $ics .= "BEGIN:STANDARD\r\n";
        $ics .= "TZOFFSETFROM:" . $offset . "\r\n";
        $ics .= "TZOFFSETTO:" . $offset . "\r\n";
        $ics .= "DTSTART:19700101T000000\r\n";
        $ics .= "END:STANDARD\r\n";
        $ics .= "END:VTIMEZONE\r\n";
    }
    foreach ($events as $idx => $event) {
        $uid = 'leave-' . ($event['id'] ?? $idx) . '@orangehrm';
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= 'UID:' . $uid . "\r\n";
        $summary = $event['summary'] ?? $event['title'];
        $summary = preg_replace('/\s+/', ' ', str_replace(["\n", "\t"], ' ', $summary));
        // Escape special characters and ensure line folding for long summaries
        $summary = str_replace(['\\', ';', ',', "\r", "\n"], ['\\\\', '\\;', '\\,', '', ''], $summary);
        // Line fold if longer than 75 characters
        if (strlen($summary) > 75) {
            $ics .= 'SUMMARY:' . substr($summary, 0, 75) . "\r\n";
            $remaining = substr($summary, 75);
            while (strlen($remaining) > 0) {
                $ics .= ' ' . substr($remaining, 0, 74) . "\r\n";
                $remaining = substr($remaining, 74);
            }
        } else {
            $ics .= 'SUMMARY:' . $summary . "\r\n";
        }
        // Add description for better compatibility
        $description = 'Leave request for ' . $event['leaveType'];
        $description = str_replace(['\\', ';', ',', "\r", "\n"], ['\\\\', '\\;', '\\,', '', ''], $description);
        $ics .= 'DESCRIPTION:' . $description . "\r\n";
        $ics .= 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n";
        if ($event['allDay']) {
            $ics .= 'DTSTART;VALUE=DATE:' . $event['start']->format('Ymd') . "\r\n";
            // For all-day events, DTEND should be the day AFTER the last day
            $ics .= 'DTEND;VALUE=DATE:' . $event['end']->format('Ymd') . "\r\n";
            $ics .= 'X-MICROSOFT-CDO-ALLDAYEVENT:TRUE' . "\r\n";
        } else {
            $tz = $event['timezone'] ?? 'Asia/Hong_Kong';
            $ics .= 'DTSTART;TZID=' . $tz . ':' . $event['start']->format('Ymd\THis') . "\r\n";
            $ics .= 'DTEND;TZID=' . $tz . ':' . $event['end']->format('Ymd\THis') . "\r\n";
        }
        // Use valid ICS values - STATUS, CLASS, and TRANSP are optional in ICS, so we'll omit them for better compatibility
        // $ics .= 'STATUS:' . $icsStatus . "\r\n";
        // $ics .= "CLASS:PUBLIC\r\n";
        // $ics .= "TRANSP:OPAQUE\r\n";
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
            'emoji' => $e['emoji'],
            'start' => $e['start']->format(DateTime::ATOM),
            'end' => $e['end']->format(DateTime::ATOM),
            'allDay' => $e['allDay'],
            'color' => $e['color'],
            'leaveType' => $e['leaveType'],
            'timezone' => $e['timezone'],
            'status' => $e['status']
        ];
    }, $events);
    echo json_encode($data);
    exit;
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="leave-calendar.ics"');
// Add BOM for better compatibility with some applications
echo "\xEF\xBB\xBF";
echo eventsToIcs($events);
