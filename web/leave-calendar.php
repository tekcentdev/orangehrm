<?php
$_ENV = parse_ini_file(__DIR__ . '/../shared/.env')
    ?: parse_ini_file(__DIR__ . '/../.env')
    ?: [];
if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', 'On');
}

function requireEnv(string $key): string {
    if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
        http_response_code(500);
        exit("Missing required environment variable: $key");
    }
    return $_ENV[$key];
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

$token = requireEnv('CALENDAR_ACCESS_TOKEN');
$host = requireEnv('CALENDAR_DOMAIN');

$leaveTypeEmoji = [
    'Annual leave' => '🏖️',
    'Sick Leave' => '🤒',
    'Work from home' => '🏡',
    'Travel' => '✈️'
];

$webcal = 'webcal://' . $host . '/web/leave-calendar-subscription.php?access_token=' . urlencode($token) . '&format=ics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.css">
<style>
  body { font-family: Arial, sans-serif; padding: 20px; }
  .header { display: flex; align-items: center; justify-content: space-between; max-width: 900px; margin: 0 auto; }
  #calendar { max-width: 900px; margin: 20px auto; }
</style>
</head>
<body>
<div class="header">
  <h2>Leave Calendar</h2>
  <button id="copyLink">Copy Subscription Link</button>
</div>
<div id="calendar"></div>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.js"></script>
<script>
const leaveTypeEmoji = <?= json_encode($leaveTypeEmoji) ?>;
function normalizeLeaveType(type) {
  const lower = type.toLowerCase();
  if (lower.includes('annual')) return 'Annual leave';
  if (lower.includes('sick')) return 'Sick Leave';
  if (lower.includes('work from home')) return 'Work from home';
  if (lower.includes('travel')) return 'Travel';
  return type;
}
const calendarEl = document.getElementById('calendar');
const calendar = new FullCalendar.Calendar(calendarEl, {
  initialView: 'dayGridMonth',
  headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
  height: 'auto',
  events: 'leave-calendar-subscription.php?access_token=<?= urlencode($token) ?>&format=json',
  eventOverlap: false,
  eventContent: function(arg) {
    const name = arg.event.title;
    const type = arg.event.extendedProps.leaveType || '';
    const normalized = normalizeLeaveType(type);
    const emoji = leaveTypeEmoji[normalized] || '';
    const div = document.createElement('div');
    if (arg.view.type === 'dayGridMonth' || arg.view.type === 'timeGridWeek') {
      div.style.whiteSpace = 'normal';
      div.style.overflowWrap = 'anywhere';
    }
    let text = name + (emoji ? ' ' + emoji : '');
    if (arg.view.type !== 'dayGridMonth' && arg.view.type !== 'timeGridWeek') {
      const timeText = arg.timeText ? arg.timeText + ' ' : '';
      text = timeText + text;
    }
    div.textContent = text;
    return { domNodes: [div] };
  }
});
calendar.render();

document.getElementById('copyLink').addEventListener('click', () => {
  navigator.clipboard.writeText('<?= $webcal ?>');
  alert('Subscription link copied to clipboard');
});
</script>
</body>
</html>
