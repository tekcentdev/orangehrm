<?php
$_ENV = parse_ini_file(__DIR__ . '/../shared/.env') ?: [];

function requireEnv(string $key): string {
    if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
        http_response_code(500);
        exit("Missing required environment variable: $key");
    }
    return $_ENV[$key];
}

$token = requireEnv('CALENDAR_ACCESS_TOKEN');
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

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

$legendItems = '';
foreach ($leaveTypeColors as $name => $color) {
    $legendItems .= '<li><span style="background:' . htmlspecialchars($color, ENT_QUOTES) . '"></span>'
        . htmlspecialchars($name) . '</li>';
}
$legendItems .= '<li><span style="background:' . htmlspecialchars($unapprovedColor, ENT_QUOTES) . '"></span>Not Approved</li>';

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
  .legend { max-width: 900px; margin: 20px auto; }
  .legend ul { list-style: none; padding-left: 0; display: flex; flex-wrap: wrap; }
  .legend li { margin-right: 15px; display: flex; align-items: center; }
  .legend span { display: inline-block; width: 12px; height: 12px; margin-right: 5px; }
</style>
</head>
<body>
<div class="header">
  <h2>Leave Calendar</h2>
  <button id="copyLink">Copy Subscription Link</button>
</div>
<div id="calendar"></div>
<div class="legend">
  <h3>Legend</h3>
  <ul>
    <?= $legendItems ?>
  </ul>
</div>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.js"></script>
<script>
const calendarEl = document.getElementById('calendar');
const calendar = new FullCalendar.Calendar(calendarEl, {
  initialView: 'dayGridMonth',
  headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
  height: 'auto',
  events: 'leave-calendar-subscription.php?access_token=<?= urlencode($token) ?>&format=json',
  eventOverlap: false,
  eventContent: function(arg) {
    const [name, type] = arg.event.title.split(' - ');
    if (arg.view.type === 'dayGridMonth' || arg.view.type === 'timeGridWeek') {
      const div = document.createElement('div');
      div.textContent = name;
      return { domNodes: [div] };
    }
    const div = document.createElement('div');
    const timeText = arg.timeText ? arg.timeText + ' ' : '';
    div.textContent = timeText + name + ' - ' + type;
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
