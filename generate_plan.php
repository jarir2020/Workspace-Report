<?php
declare(strict_types=1);

$dataDir = __DIR__ . '/plans';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

function clean(string $value): string
{
    return trim($value);
}

function planFile(string $id): string
{
    global $dataDir;
    return $dataDir . '/' . basename($id) . '.json';
}

/*
|--------------------------------------------------------------------------
| SAVE PLAN
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'save') {

        $id = clean($_POST['id'] ?? '');

        if ($id === '') {
            $id = date('Y-m-d_H-i-s');
        }

        $tasks = [];

        foreach ($_POST['tasks'] ?? [] as $task) {

            $task = clean((string)$task);

            if ($task !== '') {
                $tasks[] = [
                    'title'  => $task,
                    'status' => 'pending'
                ];
            }
        }

        $plan = [
            'id'           => $id,
            'date'         => clean($_POST['date'] ?? date('Y-m-d')),
            'prepared_by'  => clean($_POST['prepared_by'] ?? ''),
            'approved_by'  => clean($_POST['approved_by'] ?? ''),
            'tasks'        => $tasks,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s')
        ];

        file_put_contents(
            planFile($id),
            json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        header("Location: ?plan=" . urlencode($id));
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE TASK STATUS
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        $id = clean($_POST['id'] ?? '');
        $file = planFile($id);

        if (is_file($file)) {

            $plan = json_decode(
                file_get_contents($file),
                true
            );

            foreach ($plan['tasks'] as $index => $task) {

                $status = $_POST['status'][$index] ?? 'pending';

                $plan['tasks'][$index]['status'] =
                    $status === 'done' ? 'done' : 'pending';
            }

            $plan['updated_at'] = date('Y-m-d H:i:s');

            file_put_contents(
                $file,
                json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }

        header("Location: ?plan=" . urlencode($id));
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE PLAN
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        $id = clean($_POST['id'] ?? '');
        $file = planFile($id);

        if ($id !== '' && is_file($file)) {
            @unlink($file);
            @unlink($dataDir . '/' . $id . '_report.pdf');
        }

        header("Location: generate_plan.php");
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| LOAD PLAN
|--------------------------------------------------------------------------
*/

$plan = null;

if (!empty($_GET['plan'])) {

    $id = basename($_GET['plan']);
    $file = planFile($id);

    if (is_file($file)) {
        $plan = json_decode(
            file_get_contents($file),
            true
        );
    }
}

/*
|--------------------------------------------------------------------------
| LOAD ALL PLANS (for the Reports list on the home page)
|--------------------------------------------------------------------------
*/

$allPlans = [];

if (is_dir($dataDir)) {
    foreach (scandir($dataDir) as $entry) {
        if (substr($entry, -5) !== '.json') {
            continue;
        }

        $path = $dataDir . '/' . $entry;
        $data = json_decode((string)file_get_contents($path), true);

        if (!is_array($data) || empty($data['id'])) {
            continue;
        }

        $allPlans[] = $data;
    }

    usort($allPlans, function (array $a, array $b): int {
        return strcmp($b['date'] ?? '', $a['date'] ?? '')
             ?: strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
    });
}

/*
|--------------------------------------------------------------------------
| PDF GENERATOR (renders styled HTML and prints to PDF via headless Chrome)
|--------------------------------------------------------------------------
*/

if ($plan && isset($_GET['markdown'])) {

    $completed = 0;
    $pending   = 0;

    foreach ($plan['tasks'] as $task) {
        if ($task['status'] === 'done') {
            $completed++;
        } else {
            $pending++;
        }
    }

    // Build the styled HTML for the report
    $tasksHtml = '';

    foreach ($plan['tasks'] as $i => $task) {

        $status = $task['status'] === 'done'
            ? 'Done'
            : 'Pending';

        $tasksHtml .=
            '<tr>' .
            '<td class="num">' . ($i + 1) . '</td>' .
            '<td>' . htmlspecialchars($task['title']) . '</td>' .
            '<td class="status status-' . htmlspecialchars($task['status']) . '">' .
                $status .
            '</td>' .
            '</tr>';
    }

    $dateEsc      = htmlspecialchars($plan['date']);
    $preparedEsc  = htmlspecialchars($plan['prepared_by']);
    $approvedEsc  = htmlspecialchars($plan['approved_by']);
    $totalTasks   = count($plan['tasks']);

    // Pick a single CSS scale factor that keeps the whole report on
    // one A4 page regardless of how many tasks the user has.
    //
    // Curve tuned empirically with the current stylesheet:
    //   - 1-6 tasks   -> 1.00 (full size)
    //   - 7-12 tasks  -> 0.85
    //   - 13-20 tasks -> 0.72
    //   - 21-30 tasks -> 0.62
    //   - 31-50 tasks -> 0.52
    //   - 51+ tasks   -> 0.45 (tight but still legible)
    if ($totalTasks <= 6) {
        $fontScale = 1.0;
    } elseif ($totalTasks <= 12) {
        $fontScale = 0.85;
    } elseif ($totalTasks <= 20) {
        $fontScale = 0.72;
    } elseif ($totalTasks <= 30) {
        $fontScale = 0.62;
    } elseif ($totalTasks <= 50) {
        $fontScale = 0.52;
    } else {
        $fontScale = 0.45;
    }

    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Work Plan — {$dateEsc}</title>
<style>
    @page {
        size: A4;
        margin: 10mm 12mm 12mm 12mm;
    }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
    }

    body {
        font-family: "Helvetica", "Arial", sans-serif;
        color: #1f2937;
        font-size: calc(10pt * var(--scale, 1));
        line-height: 1.3;
    }

    .page {
        width: 186mm;          /* A4 210mm - 24mm side margins */
    }

    h1 {
        font-size: calc(16pt * var(--scale, 1));
        margin: 0 0 calc(4pt * var(--scale, 1)) 0;
        color: #111827;
        border-bottom: calc(1.5pt * var(--scale, 1)) solid #2563eb;
        padding-bottom: calc(3pt * var(--scale, 1));
    }

    h2 {
        font-size: calc(11pt * var(--scale, 1));
        margin: calc(10pt * var(--scale, 1)) 0 calc(4pt * var(--scale, 1)) 0;
        color: #1e3a8a;
        border-bottom: calc(0.5pt * var(--scale, 1)) solid #e5e7eb;
        padding-bottom: calc(2pt * var(--scale, 1));
    }

    .meta {
        margin: 0 0 calc(6pt * var(--scale, 1)) 0;
        font-size: calc(9.5pt * var(--scale, 1));
        color: #374151;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: calc(3pt * var(--scale, 1));
    }

    th, td {
        border: calc(0.5pt * var(--scale, 1)) solid #d1d5db;
        padding: calc(4pt * var(--scale, 1)) calc(6pt * var(--scale, 1));
        text-align: left;
        vertical-align: top;
        font-size: calc(9.5pt * var(--scale, 1));
        line-height: 1.25;
    }

    th {
        background: #f3f4f6;
        color: #111827;
        font-weight: 600;
        font-size: calc(9pt * var(--scale, 1));
        text-transform: uppercase;
        letter-spacing: 0.3pt;
    }

    td.num {
        width: calc(22pt * var(--scale, 1));
        text-align: center;
        color: #6b7280;
    }

    td.status {
        width: calc(56pt * var(--scale, 1));
        text-align: center;
        font-weight: 600;
        font-size: calc(9pt * var(--scale, 1));
    }

    .status-done {
        color: #047857;
        background: #d1fae5;
    }

    .status-pending {
        color: #92400e;
        background: #fef3c7;
    }

    .summary-grid {
        display: table;
        width: 100%;
        border-collapse: collapse;
        margin-top: calc(4pt * var(--scale, 1));
    }

    .summary-cell {
        display: table-cell;
        border: calc(0.5pt * var(--scale, 1)) solid #d1d5db;
        padding: calc(6pt * var(--scale, 1));
        text-align: center;
        width: 33.33%;
    }

    .summary-cell .label {
        font-size: calc(8pt * var(--scale, 1));
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.4pt;
    }

    .summary-cell .value {
        font-size: calc(14pt * var(--scale, 1));
        font-weight: 700;
        margin-top: calc(2pt * var(--scale, 1));
    }

    .value-total    { color: #1f2937; }
    .value-done     { color: #047857; }
    .value-pending  { color: #b45309; }

    .signatures {
        margin-top: calc(14pt * var(--scale, 1));
    }

    .signature-row {
        display: table;
        width: 100%;
    }

    .signature-cell {
        display: table-cell;
        width: 50%;
        padding: 0 calc(6pt * var(--scale, 1));
    }

    .signature-box {
        border-top: calc(0.5pt * var(--scale, 1)) solid #374151;
        margin-top: calc(22pt * var(--scale, 1));
        padding-top: calc(2pt * var(--scale, 1));
        font-size: calc(8.5pt * var(--scale, 1));
        color: #6b7280;
    }
</style>
</head>
<body style="--scale: {$fontScale};">

<div class="page" id="page">

<h1>Work Plan — {$dateEsc}</h1>

<p class="meta">
    <strong>Prepared by:</strong> {$preparedEsc} &nbsp;&nbsp;
    <strong>Approved by:</strong> {$approvedEsc}
</p>

<h2>Work Plan</h2>

<table>
    <thead>
        <tr>
            <th style="width:22pt; text-align:center;">#</th>
            <th>Task</th>
            <th style="width:56pt; text-align:center;">Status</th>
        </tr>
    </thead>
    <tbody>
        {$tasksHtml}
    </tbody>
</table>

<h2>Completion Summary</h2>

<div class="summary-grid">
    <div class="summary-cell">
        <div class="label">Total Tasks</div>
        <div class="value value-total">{$totalTasks}</div>
    </div>
    <div class="summary-cell">
        <div class="label">Completed</div>
        <div class="value value-done">{$completed}</div>
    </div>
    <div class="summary-cell">
        <div class="label">Pending</div>
        <div class="value value-pending">{$pending}</div>
    </div>
</div>

<div class="signatures">
    <h2>Approval / Submission</h2>
    <div class="signature-row">
        <div class="signature-cell">
            <div class="signature-box">Prepared By</div>
        </div>
        <div class="signature-cell">
            <div class="signature-box">Approved By</div>
        </div>
    </div>
</div>

</div><!-- /.page -->

<script>
    // No runtime scaling needed — the PHP-side font-scale (set as
    // --scale on the body element below) is already chosen to keep
    // the whole report on one A4 page. Kept here as a no-op for
    // future per-page tuning hooks.
</script>

</body>
</html>
HTML;

    // Render the HTML to PDF using headless Chrome
    $pdfFile = $dataDir . '/' . $plan['id'] . '_report.pdf';

    $chrome = null;
    foreach (['/usr/bin/google-chrome', '/snap/bin/chromium', 'google-chrome', 'chromium', 'chromium-browser'] as $candidate) {
        if (is_executable($candidate) || (trim($candidate) !== '' && shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'))) {
            $chrome = $candidate;
            break;
        }
    }

    if ($chrome === null) {
        // Fallback: serve the HTML if no browser is available
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    $tmpHtml = tempnam(sys_get_temp_dir(), 'plan_') . '.html';
    file_put_contents($tmpHtml, $html);

    $cmd = escapeshellcmd($chrome)
         . ' --headless --disable-gpu --no-sandbox'
         . ' --print-to-pdf=' . escapeshellarg($pdfFile)
         . ' --no-pdf-header-footer'
         . ' ' . escapeshellarg('file://' . $tmpHtml)
         . ' > /dev/null 2>&1';

    shell_exec($cmd);

    @unlink($tmpHtml);

    if (is_file($pdfFile) && filesize($pdfFile) > 0) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="workplan_' . $plan['id'] . '.pdf"');
        header('Content-Length: ' . filesize($pdfFile));
        readfile($pdfFile);
        exit;
    }

    // If Chrome failed, fall back to delivering the styled HTML
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>
    <?= $plan ? 'Work Plan' : 'Create Work Plan' ?>
</title>

<style>

body {
    font-family: Arial, sans-serif;
    max-width: 900px;
    margin: 40px auto;
    padding: 20px;
}

input, button, select {
    padding: 8px;
    margin: 5px 0;
}

.btn-row {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-top: 10px;
    flex-wrap: wrap;
}

.btn {
    display: inline-block;
    padding: 8px 16px;
    margin: 0;
    border: 1px solid #2563eb;
    background: #2563eb;
    color: #ffffff;
    font: inherit;
    font-size: 14px;
    line-height: 1.2;
    text-decoration: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn:hover {
    background: #1e40af;
    border-color: #1e40af;
}

.btn-secondary {
    background: #ffffff;
    color: #2563eb;
    border-color: #2563eb;
}

.btn-secondary:hover {
    background: #eff6ff;
    color: #1e40af;
    border-color: #1e40af;
}

.btn-danger {
    background: #ffffff;
    color: #b91c1c;
    border-color: #b91c1c;
    padding: 4px 10px;
    font-size: 13px;
}

.btn-danger:hover {
    background: #fef2f2;
    color: #991b1b;
    border-color: #991b1b;
}

.btn-small {
    padding: 4px 10px;
    font-size: 13px;
}

.home-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    align-items: start;
}

@media (max-width: 760px) {
    .home-layout {
        grid-template-columns: 1fr;
    }
}

.report-id {
    font-family: "Courier New", monospace;
    font-size: 12px;
    color: #6b7280;
}

.empty-row {
    color: #6b7280;
    font-style: italic;
    text-align: center;
}
}

input[type="text"] {
    width: 90%;
}

.task {
    margin-bottom: 8px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

th, td {
    border: 1px solid #ccc;
    padding: 10px;
}

th {
    background: #eee;
}

.done {
    color: green;
    font-weight: bold;
}

.pending {
    color: #b56b00;
    font-weight: bold;
}

</style>

</head>

<body>

<?php if (!$plan): ?>

<div class="home-layout">

<div>

<h1>Create Work Plan</h1>

<form method="POST">

<input type="hidden" name="action" value="save">

<label>Date</label><br>

<input
    type="date"
    name="date"
    value="<?= date('Y-m-d') ?>"
    required
>

<br><br>

<label>Prepared By</label><br>

<input
    type="text"
    name="prepared_by"
    placeholder="Your name"
    required
>

<br><br>

<label>Approved By</label><br>

<input
    type="text"
    name="approved_by"
    placeholder="Approver name"
>

<h3>Tasks</h3>

<div id="tasks">

<div class="task">
    <input
        type="text"
        name="tasks[]"
        placeholder="Enter task"
        required
    >
</div>

</div>

<button type="button" onclick="addTask()">
    + Add Task
</button>

<br><br>

<button type="submit">
    Save Work Plan
</button>

</form>

</div><!-- /.form-column -->

<div>

<h1>Read Existing Reports</h1>

<?php if (empty($allPlans)): ?>

<p class="empty-row">No reports yet. Create your first work plan on the left.</p>

<?php else: ?>

<table>

<thead>

<tr>
    <th>Report</th>
    <th>File Date</th>
    <th style="width: 170px;">Action</th>
</tr>

</thead>

<tbody>

<?php foreach ($allPlans as $row): ?>

<?php
    $rid   = htmlspecialchars($row['id']);
    $rdate = htmlspecialchars($row['date'] ?? '');
    $title = htmlspecialchars($row['prepared_by'] ?: $row['id']);
?>

<tr>

<td>
    <a
        href="?plan=<?= urlencode($row['id']) ?>"
        title="Open this work plan"
    >
        <?= $title ?>
    </a>
    <div class="report-id"><?= $rid ?></div>
</td>

<td><?= $rdate ?></td>

<td>

<a
    class="btn btn-secondary btn-small"
    href="?plan=<?= urlencode($row['id']) ?>"
>
    Edit
</a>

<form
    method="POST"
    style="display:inline; margin:0;"
    onsubmit="return confirm('Delete report <?= $rid ?>?');"
>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= $rid ?>">
    <button
        type="submit"
        class="btn btn-danger btn-small"
    >
        Delete
    </button>
</form>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php endif; ?>

</div><!-- /.reports-column -->

</div><!-- /.home-layout -->

<script>

function addTask() {

    const container = document.getElementById('tasks');

    const div = document.createElement('div');

    div.className = 'task';

    div.innerHTML = `
        <input
            type="text"
            name="tasks[]"
            placeholder="Enter task"
            required
        >
        <button
            type="button"
            onclick="this.parentElement.remove()"
        >
            Remove
        </button>
    `;

    container.appendChild(div);
}

</script>

<?php else: ?>

<h1>Work Plan — <?= htmlspecialchars($plan['date']) ?></h1>

<p>
<strong>Prepared By:</strong>
<?= htmlspecialchars($plan['prepared_by']) ?>
</p>

<p>
<strong>Approved By:</strong>
<?= htmlspecialchars($plan['approved_by']) ?>
</p>

<form method="POST">

<input type="hidden" name="action" value="update">

<input
    type="hidden"
    name="id"
    value="<?= htmlspecialchars($plan['id']) ?>"
>

<table>

<tr>
    <th>#</th>
    <th>Task</th>
    <th>Status</th>
</tr>

<?php foreach ($plan['tasks'] as $i => $task): ?>

<tr>

<td><?= $i + 1 ?></td>

<td>
<?= htmlspecialchars($task['title']) ?>
</td>

<td>

<select name="status[<?= $i ?>]">

<option
    value="pending"
    <?= $task['status'] === 'pending' ? 'selected' : '' ?>
>
    ⏳ Pending
</option>

<option
    value="done"
    <?= $task['status'] === 'done' ? 'selected' : '' ?>
>
    ✅ Done
</option>

</select>

</td>

</tr>

<?php endforeach; ?>

</table>

<br>

<button type="submit">
    Save Status
</button>

</form>

<br>

<div class="btn-row">

<a
    class="btn"
    href="?plan=<?= urlencode($plan['id']) ?>&markdown=1"
    target="_blank"
>
    Generate Final PDF Report
</a>

<a
    class="btn btn-secondary"
    href="generate_plan.php"
>
    Generate New Report
</a>

</div>

<?php endif; ?>

</body>
</html>