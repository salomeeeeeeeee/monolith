<?php
/**
 * სალაროს ექსელის ატვირთვა (cash_import.php)
 * ჩანაწერები იწერება ამონაწერების სიაში (ACCOUNT_NUMBER = სალარო) და ებმება bog_merge.php-ზე.
 */
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/crm/deal/bank_integration/helpers.php';

$APPLICATION->SetTitle('სალაროს ექსელის ატვირთვა');
bankBogEnsureModules();

$statementIblockId = bankBogStatementIblockId();
$projects = array_values(array_unique(array_column(bankBogAccounts(), 'project')));
$selectedProject = (string)($_POST['PROJECT'] ?? 'New Depot');

$flash = null;
$stats = null;
if ($statementIblockId <= 0) {
    $flash = ['type' => 'error', 'text' => 'ამონაწერების სია ჯერ არ არსებობს - გაუშვი setup.php'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(0);
    $file = $_FILES['XLSX'] ?? null;
    if (!check_bitrix_sessid()) {
        $flash = ['type' => 'error', 'text' => 'სესია ამოიწურა - განაახლე გვერდი და სცადე თავიდან'];
    } elseif (!in_array($selectedProject, $projects, true)) {
        $flash = ['type' => 'error', 'text' => 'აირჩიე პროექტი'];
    } elseif (!$file || (int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $flash = ['type' => 'error', 'text' => 'ფაილი ვერ აიტვირთა'];
    } elseif (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        $flash = ['type' => 'error', 'text' => 'საჭიროა .xlsx ფაილი'];
    } else {
        $rows = bankBogCashReadExcel($file['tmp_name'], $readError);
        if ($rows === null) {
            $flash = ['type' => 'error', 'text' => $readError];
        } else {
            $stats = bankBogImportCashRows($rows, $selectedProject);
            $flash = [
                'type' => $stats['errors'] ? 'warn' : 'ok',
                'text' => sprintf(
                    'სტრიქონი %d · ახალი %d · უკვე ატვირთული %d · გამოტოვებული %d%s',
                    count($rows),
                    $stats['created'],
                    $stats['skipped_dup'],
                    count($stats['skipped']),
                    $stats['errors'] ? ' · შეცდომა ' . count($stats['errors']) . ' (იხ. ცხრილი)' : ''
                ),
            ];
        }
    }
}
$problemRows = $stats ? array_merge($stats['errors'], $stats['skipped']) : [];

ob_end_clean();
?><!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>სალაროს ექსელის ატვირთვა</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #00335b;
            --muted: #6b7a8a;
            --line: #dde2e8;
            --primary: #00335b;
            --primary-deep: #002445;
            --accent: #72c4b1;
            --surface: #ffffff;
            --shadow: 0 10px 28px rgba(0, 51, 91, 0.08);
            --ok: #1a8f3c;
            --warn: #9a6700;
            --err: #c0392b;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            min-height: 100%;
            font-family: "Montserrat", "Segoe UI", sans-serif;
            color: var(--ink);
            background: linear-gradient(180deg, #ffffff 0%, #f4f5f7 100%);
        }
        .shell { width: min(820px, calc(100% - 32px)); margin: 40px auto 64px; }
        .hero {
            display: flex; align-items: center; gap: 16px;
            padding: 18px 20px; margin-bottom: 14px;
            border-radius: 4px; color: #fff;
            background: linear-gradient(135deg, #002445 0%, #00335b 55%, #0a4a75 100%);
            box-shadow: var(--shadow);
            position: relative; overflow: hidden;
        }
        .hero::after {
            content: ""; position: absolute; inset: auto -40px -80px auto;
            width: 200px; height: 200px; border-radius: 50%;
            background: rgba(114, 196, 177, 0.18);
        }
        .hero-mark {
            width: 60px; height: 60px; border-radius: 4px; flex-shrink: 0;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.25);
            display: grid; place-items: center;
            position: relative; z-index: 1;
        }
        .hero-mark svg { width: 34px; height: 34px; display: block; }
        .hero h1 {
            margin: 0; font-size: 22px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.02em;
        }
        .hero p { margin: 4px 0 0; color: rgba(255, 255, 255, 0.82); font-size: 13px; }
        .hero div { position: relative; z-index: 1; }
        .hero-home {
            margin-left: auto; position: relative; z-index: 1;
            display: inline-flex; align-items: center; gap: 6px;
            height: 36px; padding: 0 14px; border-radius: 2px;
            background: rgba(255, 255, 255, 0.1); color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.3);
            font-size: 12px; font-weight: 700; letter-spacing: 0.06em;
            text-transform: uppercase; text-decoration: none; white-space: nowrap;
            transition: background .15s ease;
        }
        .hero-home:hover { background: rgba(255, 255, 255, 0.2); }
        .hero-home svg { width: 14px; height: 14px; flex-shrink: 0; }
        .card {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: 4px; box-shadow: var(--shadow); padding: 22px;
        }
        label {
            display: block; margin-bottom: 7px;
            font-size: 11px; font-weight: 700;
            letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted);
        }
        .field { margin-bottom: 16px; }
        select, input[type="file"] {
            width: 100%; height: 46px; border-radius: 4px;
            border: 1px solid var(--line); background-color: #f0f2f5;
            padding: 0 14px; font: inherit; color: var(--ink); outline: none;
        }
        input[type="file"] { padding: 10px 14px; height: auto; }
        select:focus { border-color: var(--accent); background-color: #fff; box-shadow: 0 0 0 4px rgba(114, 196, 177, 0.2); }
        .hint { font-size: 12px; color: var(--muted); line-height: 1.5; margin: 0 0 16px; }
        .actions { display: flex; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
        .btn {
            appearance: none; border: 0; border-radius: 2px; height: 46px;
            padding: 0 20px; font: inherit; font-weight: 700; cursor: pointer;
            letter-spacing: 0.06em; text-transform: uppercase; font-size: 12px;
            text-decoration: none; display: inline-flex; align-items: center;
            justify-content: center; transition: transform .15s ease, background .15s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary {
            background: var(--primary); color: #fff;
            box-shadow: 0 8px 20px rgba(0, 51, 91, 0.22); min-width: 180px;
        }
        .btn-primary:hover { background: var(--primary-deep); }
        .btn-ghost { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        .flash {
            margin-bottom: 14px; border-radius: 4px; padding: 13px 16px;
            font-size: 13px; font-weight: 600;
        }
        .flash.ok { background: #e8f7ef; color: var(--ok); border: 1px solid #b7e4c7; }
        .flash.warn { background: #fff7e0; color: var(--warn); border: 1px solid #f5d78e; }
        .flash.error { background: #fef3f2; color: var(--err); border: 1px solid #f5c2c0; }
        .results { margin-top: 14px; padding: 0; overflow-x: auto; }
        .results h2 { margin: 0; padding: 14px 12px; font-size: 13px; text-transform: uppercase; letter-spacing: .06em; }
        .results table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .results th {
            text-align: left; padding: 10px 12px; background: #e8eef4;
            font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
        }
        .results td { padding: 10px 12px; border-top: 1px solid var(--line); vertical-align: top; }
        .results .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .results .muted { color: var(--muted); font-size: 12px; margin-top: 2px; }
        .results .reason { color: var(--err); font-weight: 600; }
        @media (max-width: 640px) {
            .shell { margin-top: 20px; }
            .hero { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="hero">
        <div class="hero-mark">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="6" width="20" height="12" rx="2"></rect>
                <circle cx="12" cy="12" r="2.5"></circle>
                <path d="M6 12h.01M18 12h.01"></path>
            </svg>
        </div>
        <div>
            <h1>სალაროს ექსელი</h1>
            <p>ნაღდი გადახდები · ატვირთვის შემდეგ ებმება დილებს მიბმის გვერდზე</p>
        </div>
        <a class="hero-home" href="/crm/deal/mainpage.php">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 10.5 12 3l9 7.5"></path>
                <path d="M5 9.5V21h14V9.5"></path>
            </svg>
            მთავარი გვერდი
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['text']) ?></div>
    <?php endif; ?>

    <div class="card">
        <?php if ($statementIblockId <= 0): ?>
            <p>ამონაწერების სია ჯერ არ არის შექმნილი.</p>
            <div class="actions">
                <a class="btn btn-primary" href="/crm/deal/bank_integration/setup.php">სიების მომზადება →</a>
            </div>
        <?php else: ?>
        <p class="hint">
            ფაილში საჭიროა სვეტები: თარიღი (დღე/თვე/წელი), N, გადამხდელი (სახელი და პირადი ნომერი), მიმღები, თანხა, ვალუტა, საფუძველი.
            იგივე ფაილის თავიდან ატვირთვისას უკვე ჩაწერილი სტრიქონები აღარ დაემატება. 0 თანხიანი სტრიქონი არ იწერება.
        </p>
        <form method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" id="uploadForm">
            <?= bitrix_sessid_post() ?>
            <div class="field">
                <label for="PROJECT">პროექტი</label>
                <select id="PROJECT" name="PROJECT">
                    <?php foreach ($projects as $project): ?>
                        <option value="<?= htmlspecialchars($project) ?>" <?= $selectedProject === $project ? 'selected' : '' ?>><?= htmlspecialchars($project) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="XLSX">ექსელის ფაილი (.xlsx)</label>
                <input required type="file" id="XLSX" name="XLSX" accept=".xlsx">
            </div>
            <div class="actions">
                <button class="btn btn-primary" type="submit">ატვირთვა</button>
                <a class="btn btn-ghost" href="/crm/deal/bog_merge.php">გადახდებთან მიბმა →</a>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($problemRows): ?>
        <div class="card results">
            <h2>არ ჩაიწერა (<?= count($problemRows) ?>)</h2>
            <table>
                <thead>
                <tr>
                    <th>სტრიქონი</th>
                    <th>თარიღი · N</th>
                    <th>გადამხდელი</th>
                    <th class="num">თანხა</th>
                    <th>მიზეზი</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($problemRows as $row): ?>
                    <tr>
                        <td><?= (int)$row['ROW'] ?></td>
                        <td><?= htmlspecialchars($row['DATE_RAW']) ?><div class="muted">N <?= htmlspecialchars($row['N']) ?></div></td>
                        <td>
                            <?= htmlspecialchars($row['NAME']) ?>
                            <div class="muted"><?= htmlspecialchars($row['PERSONAL_ID']) ?></div>
                        </td>
                        <td class="num"><?= htmlspecialchars(number_format($row['AMOUNT'], 2, '.', ' ') . ' ' . $row['CURRENCY']) ?></td>
                        <td class="reason"><?= htmlspecialchars($row['REASON']) ?><div class="muted"><?= htmlspecialchars($row['BASIS']) ?></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script>
var uploadForm = document.getElementById('uploadForm');
if (uploadForm) {
    uploadForm.addEventListener('submit', function () {
        var btn = uploadForm.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'იტვირთება…';
    });
}
</script>
</body>
</html>
<?php
exit;
