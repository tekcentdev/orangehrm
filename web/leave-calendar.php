<?php
$_ENV = parse_ini_file(__DIR__ . '/../shared/.env')
    ?: parse_ini_file(__DIR__ . '/../.env')
    ?: [];
if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
    error_reporting(E_ALL);
    ini_set('display_errors', 'On');
}

function requireEnv(string $key): string
{
    if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
        http_response_code(500);
        exit("Missing required environment variable: $key");
    }
    return $_ENV[$key];
}

function normalizeLeaveType(string $type): string
{
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
    'Travel' => '✈️',
    'Birthday Leave' => '🎂'
];

$subscribeUrl = 'webcal://' . $host . '/web/leave-calendar-subscription.php?access_token=' . urlencode($token);
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
  .subscribe { max-width: 900px; margin: 20px auto; }

  /* Better alignment for FullCalendar header on small screens */
  @media (max-width: 600px) {
    .fc-header-toolbar {
      flex-direction: column;
      align-items: center;
    }
    .fc-header-toolbar .fc-toolbar-chunk {
      width: 100%;
      margin-bottom: 8px;
      text-align: center;
    }
  }

  /* Add breathing space around event content */
  .fc-event {
    padding: 2px 4px !important;
  }
  
  .fc-event-main {
    padding: 1px 2px !important;
  }
  
  /* Ensure text has some space from the colored background */
  .fc-event-title {
    padding-left: 2px !important;
    padding-right: 2px !important;
  }
</style>
</head>
<body>
<div class="header">
  <h2><a href="/web/leave/viewLeaveList">Leave</a> &gt; Leave Calendar</h2>
</div>
<div id="calendar"></div>
<div class="subscribe">
  <h3>Subscribe to Leave Calendar</h3>
  <ol>
    <li>Visit <a href="https://outlook.office.com/calendar">https://outlook.office.com/calendar</a></li>
    <li>Choose <strong>Add Calendar</strong></li>
    <li>Select <strong>Subscribe from web</strong></li>
    <li>Paste <code><?= htmlspecialchars($subscribeUrl, ENT_QUOTES) ?></code></li>
    <li>Optional: Select the calendar in Outlook Desktop</li>
  </ol>
</div>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.9/index.global.min.js"></script>
<script>
const leaveTypeEmoji = <?= json_encode($leaveTypeEmoji) ?>;
function normalizeLeaveType(type) {
  const lower = type.toLowerCase();
  if (lower.includes('annual')) return 'Annual leave';
  if (lower.includes('sick')) return 'Sick Leave';
  if (lower.includes('work from home')) return 'Work from home';
  if (lower.includes('wfh')) return 'Work from home';
  if (lower.includes('travel')) return 'Travel';
  return type;
}
const calendarEl = document.getElementById('calendar');
const calendar = new FullCalendar.Calendar(calendarEl, {
  initialView: 'dayGridMonth',
  headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
  height: 'auto',
  slotMinTime: '08:00:00',
  slotMaxTime: '19:00:00',
  events: 'leave-calendar-subscription.php?access_token=<?= urlencode($token) ?>&format=json',
  eventDataTransform: function(data) {
    const approved = data.status === 'CONFIRMED';
    const color = approved ? '#2ecc71' : '#bdc3c7';
    data.backgroundColor = color;
    data.borderColor = color;
    data.textColor = approved ? '#fff' : '#000';
    // force block display so timed events in month view show background color
    data.display = 'block';
    return data;
  },
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
  },
  eventDidMount: function(info) {
    const name = info.event.title;
    const type = info.event.extendedProps.leaveType || '';
    const status = info.event.extendedProps.status || '';
    const normalized = normalizeLeaveType(type);
    const approvalStatus = status === 'CONFIRMED' ? 'Approved' : 'Pending';
    let tooltip = name + ' - ' + normalized + ' (' + approvalStatus + ')';
    if (!info.event.allDay) {
      const opts = { hour: '2-digit', minute: '2-digit' };
      const startStr = info.event.start.toLocaleTimeString([], opts);
      const endStr = info.event.end.toLocaleTimeString([], opts);
      tooltip += ' ' + startStr + ' - ' + endStr;
    }
    info.el.setAttribute('title', tooltip);
  }
});
calendar.render();
</script>
</body>
</html>
