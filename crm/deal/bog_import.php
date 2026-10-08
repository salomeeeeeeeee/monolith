<?php
/**
 * საქართველოს ბანკი — ამონაწერების იმპორტი (bog_import.php)
 */
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/crm/deal/bank_integration/helpers.php';

$APPLICATION->SetTitle('BOG — ამონაწერის გენერაცია');
bankBogEnsureModules();

$flash = null;
$results = null;
$errorMsg = null;
$accounts = bankBogAccounts();
$selectedAccount = trim((string)($_POST['ACCOUNT'] ?? ''));
$statementIblockId = bankBogStatementIblockId();

$accountsByProject = [];
foreach ($accounts as $acc) {
    $accountsByProject[$acc['project']][] = $acc;
}

$missingCredentials = [];
foreach (bankBogCompanies() as $code => $company) {
    list($clientId, $clientSecret) = bankBogCredentials($code);
    if ($clientId === '' || $clientSecret === '') {
        $missingCredentials[] = $company['name'];
    }
}

if ($statementIblockId <= 0) {
    $flash = ['type' => 'error', 'text' => 'ამონაწერების სია ჯერ არ არსებობს — გაუშვი setup.php'];
} elseif (!empty($_POST['from_date']) && !empty($_POST['to_date'])) {
    @set_time_limit(0);
    ignore_user_abort(true);

    $from = preg_replace('/[^0-9\-]/', '', $_POST['from_date']);
    $to = preg_replace('/[^0-9\-]/', '', $_POST['to_date']);
    $keys = ($_POST['MODE'] ?? '') === 'all' ? array_keys($accounts) : [$selectedAccount];

    $results = bankBogImportStatements($from, $to, $keys, $errorMsg);

    $totals = ['created' => 0, 'skipped_dup' => 0, 'skipped_filter' => 0, 'errors' => 0];
    foreach ($results as $stats) {
        $totals['created'] += $stats['created'];
        $totals['skipped_dup'] += $stats['skipped_dup'];
        $totals['skipped_filter'] += $stats['skipped_filter'];
        $totals['errors'] += count($stats['errors']);
    }

    if ($errorMsg) {
        $flash = ['type' => 'error', 'text' => $errorMsg];
    } elseif (empty($results)) {
        $flash = ['type' => 'error', 'text' => 'აირჩიე ანგარიში'];
    } else {
        $flash = [
            'type' => $totals['errors'] ? 'warn' : 'ok',
            'text' => sprintf(
                'ახალი %d · დუბლიკატი %d · გამოტოვებული %d%s',
                $totals['created'],
                $totals['skipped_dup'],
                $totals['skipped_filter'],
                $totals['errors'] ? ' · შეცდომა ' . $totals['errors'] . ' (იხ. ცხრილი)' : ''
            ),
        ];
    }
}

ob_end_clean();
?><!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BOG — ამონაწერის გენერაცია</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
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
        .shell { width: min(720px, calc(100% - 32px)); margin: 40px auto 64px; }
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
        .hero-mark svg { width: 48px; height: auto; display: block; }
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
        select, .date-field {
            width: 100%; height: 46px; border-radius: 4px;
            border: 1px solid var(--line); background-color: #f0f2f5;
            padding: 0 14px; font: inherit; color: var(--ink); outline: none;
            transition: border-color .2s, box-shadow .2s, background-color .2s;
        }
        select:focus, .date-field:focus, .date-field.active {
            border-color: var(--accent); background-color: #fff;
            box-shadow: 0 0 0 4px rgba(114, 196, 177, 0.2);
        }
        .date-field {
            cursor: pointer; padding-right: 44px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2300335b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='5' width='18' height='16' rx='2'/%3E%3Cpath d='M3 10h18M8 3v4M16 3v4'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 14px center; background-size: 18px;
        }
        .flatpickr-calendar { font-family: inherit; border-radius: 4px; box-shadow: 0 10px 28px rgba(0, 51, 91, 0.16); }
        .flatpickr-day.selected, .flatpickr-day.selected:hover, .flatpickr-day.selected:focus {
            background: var(--primary); border-color: var(--primary);
        }
        .flatpickr-day.today { border-color: var(--accent); }
        .flatpickr-day.today:hover, .flatpickr-day.today:focus { background: var(--accent); border-color: var(--accent); color: #fff; }
        .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
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
        .results table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .results th {
            text-align: left; padding: 10px 12px; background: #e8eef4;
            font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
        }
        .results td { padding: 10px 12px; border-top: 1px solid var(--line); vertical-align: top; }
        .results .num { text-align: right; font-variant-numeric: tabular-nums; }
        .results .muted { color: var(--muted); font-size: 12px; margin-top: 2px; }
        .results .row-error { color: var(--err); font-size: 12px; font-weight: 600; margin-top: 4px; }
        @media (max-width: 640px) {
            .row-2 { grid-template-columns: 1fr; }
            .shell { margin-top: 20px; }
            .hero { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="hero">
        <div class="hero-mark">
            <svg viewBox="0 0 114 97" role="img" aria-label="საქართველოს ბანკი" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M111.018 30.89L99.6178 44.25V69.09C99.6178 82.47 88.7378 93.36 75.3578 93.36H34.1778C26.4078 93.36 19.4878 89.69 15.0478 84C14.2378 84.12 13.3778 84.19 12.4678 84.19C9.51779 84.19 2.10779 82.19 2.10779 77.46C2.10779 75.25 3.90779 73.46 6.11779 73.46C6.79779 73.46 7.33779 73.62 7.79779 73.8C7.79779 73.8 9.92779 74.62 9.92779 73.25V27.91C9.90779 14.53 20.7978 3.64001 34.1678 3.64001H75.3478C98.5478 3.64001 106.528 20.04 111.498 27.32C112.238 28.4 111.858 29.9 111.008 30.89" fill="white"/>
            <path d="M107.218 27.65C105.528 25.31 101.078 18.89 98.1178 16.49C95.6378 14.51 92.7378 13.1 89.0878 13.33C82.7878 13.73 77.5578 19.03 74.2478 22.99C71.6778 26.05 63.7878 30.95 55.3678 33.18C49.0078 34.86 41.8378 34.01 36.1478 33.59C33.0078 33.36 30.1178 33.13 27.6378 33.26C18.6978 33.71 13.3678 40.27 13.5878 47.86C13.8078 55.57 19.1078 62.59 19.1078 69.82C19.1078 75.49 14.4978 78 11.3178 78C7.51778 78 7.25778 76.45 6.10778 76.45C5.63778 76.45 5.09778 76.84 5.09778 77.46C5.09778 79.38 9.79778 81.2 12.4678 81.2C19.5978 81.2 22.5678 76.11 22.5678 76.11C22.5678 76.11 24.3778 81.38 31.4078 81.38C37.0078 81.38 39.4378 78.7 39.4378 76.46C39.4378 74.69 38.5978 74.04 37.8578 73.19C31.9478 67.68 29.9078 63.83 30.9078 60.05C31.9278 56.19 35.9878 53.63 39.0778 53.68C39.0778 53.68 34.8078 56.06 33.9178 60.39C32.9978 64.83 37.7878 69.15 39.0378 69.62C39.0378 69.62 40.3078 69.62 40.6578 69.59C43.8178 69.3 44.8378 67.82 44.8378 66.36C44.8378 64.27 42.3978 64.41 42.3978 60.98C42.3978 58.38 43.8378 57.53 45.1078 57.53C46.4778 57.53 51.6778 58.03 57.9678 58.67C60.0678 58.88 65.3078 60.91 65.3078 69.05V71.98C65.3078 76.94 67.3078 83.08 76.0778 83.08C82.0378 83.08 85.3778 79.76 85.3778 77.36C85.3778 75.79 84.0078 75.1 83.3278 74.16C81.3378 71.38 82.3178 67.51 82.3178 67.51H82.3578C83.1878 67.78 84.1578 67.94 85.2678 67.94C89.6578 67.94 91.7178 66.2 91.7178 64.12C91.7178 61.69 88.4278 61.7 88.0878 59.56C87.6578 56.89 88.3778 45.07 92.9578 38.54L96.5878 40.17C97.1278 40.42 97.7678 40.27 98.1478 39.81L107.168 29.25C107.548 28.79 107.578 28.13 107.228 27.64" fill="#FF6022"/>
            <path d="M22.4578 53.74C20.6978 51.98 19.6078 49.55 19.6078 46.87C19.6078 41.5 24.3978 36.38 29.7678 36.35C35.2178 36.31 42.9378 37.73 49.8878 37.14C51.5978 45.29 57.4878 50.79 62.4378 54.4C59.6278 53.41 50.6278 46.82 45.5578 44.01C37.4878 39.53 29.1778 38.65 24.9278 42.56C21.2678 45.93 21.7778 50.91 22.4578 53.74Z" fill="white"/>
            <path d="M79.8278 64.38V64.35C79.8278 64.35 75.2378 68.72 77.3378 76.65C76.0578 77.89 73.1278 77.42 71.8678 77.23C72.6878 78.27 78.9878 80.57 81.2178 76.78C76.9178 73.45 79.7478 64.95 79.8378 64.39" fill="white"/>
            <path d="M98.1578 23.81C97.8578 25.49 95.9778 26.55 93.9478 26.18C91.9278 25.81 90.5378 24.15 90.8478 22.48C91.1578 20.8 93.0278 19.74 95.0578 20.11C97.0678 20.47 98.4578 22.14 98.1478 23.81" fill="white"/>
            <path d="M89.7478 37.85C81.7478 37.38 75.9378 32.62 74.3078 28.47C73.9378 27.52 74.5178 26.87 75.0778 26.61C75.6478 26.35 76.5978 26.51 76.9578 27.43C79.2078 33.19 83.7778 36.15 89.7478 37.85Z" fill="white"/>
            <path d="M86.9378 44.33C76.0478 44.03 70.1778 39.7 67.1578 33.35C66.8378 32.68 67.0178 31.82 67.8578 31.45C68.6978 31.07 69.4478 31.45 69.7278 32.17C72.5178 39.26 79.9078 43.42 86.9378 44.34" fill="white"/>
            <path d="M59.0178 36.92C63.3078 47.14 72.9078 51.84 84.9078 51.2C73.8278 50.35 65.2978 45.24 61.6478 35.89C61.2978 35.01 60.5778 34.79 59.8978 35C59.2178 35.21 58.6078 35.96 59.0178 36.92Z" fill="white"/>
            <path d="M101.188 24.1901C101.188 24.1901 101.318 28.1301 96.8078 30.1201C92.3378 32.1001 88.4878 29.1701 88.2978 29.0301C88.0178 28.8201 87.8378 28.4801 87.8378 28.1101C87.8378 27.4701 88.3578 26.9601 88.9878 26.9601C89.2778 26.9601 89.5578 27.0701 89.7578 27.2501C89.9678 27.4401 92.4478 29.9601 96.5278 28.9601C100.608 27.9601 101.188 24.1901 101.188 24.1901Z" fill="white"/>
            <path d="M87.7978 21.89C87.7178 22.44 87.2478 22.86 86.6678 22.86C86.0378 22.86 85.5278 22.35 85.5278 21.72C85.5278 21.61 85.5478 21.5 85.5778 21.39C85.6678 21.08 86.7978 17.42 90.9178 16.46C94.9578 15.52 97.6278 17.54 97.6278 17.54C97.6278 17.54 94.6678 15.88 91.2678 17.42C88.4978 18.62 87.8478 21.53 87.7978 21.89Z" fill="white"/>
            </svg>
        </div>
        <div>
            <h1>ამონაწერის გენერაცია</h1>
            <p>საქართველოს ბანკი · <?= count($accounts) ?> ანგარიში / ვალუტა</p>
        </div>
        <a class="hero-home" href="/crm/deal/mainpage.php">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 10.5 12 3l9 7.5"></path>
                <path d="M5 9.5V21h14V9.5"></path>
            </svg>
            მთავარი გვერდი
        </a>
    </div>

    <?php if ($missingCredentials): ?>
        <div class="flash error">client ID / secret არ არის მითითებული: <?= htmlspecialchars(implode(', ', $missingCredentials)) ?> (bank_integration/credentials.php)</div>
    <?php endif; ?>

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
        <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" id="loadForm">
            <input type="hidden" name="MODE" id="MODE" value="one">
            <div class="field">
                <label for="ACCOUNT">ანგარიში</label>
                <select id="ACCOUNT" name="ACCOUNT">
                    <?php foreach ($accountsByProject as $project => $projectAccounts): ?>
                        <optgroup label="<?= htmlspecialchars($project) ?>">
                            <?php foreach ($projectAccounts as $acc): ?>
                                <option value="<?= htmlspecialchars($acc['key']) ?>" <?= ($selectedAccount === $acc['key']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($acc['iban'] . ' ' . $acc['currency'] . ' · ' . $acc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row-2">
                <div class="field">
                    <label for="from_date">დან</label>
                    <input required type="text" class="date-field" id="from_date" name="from_date" value="<?= htmlspecialchars($_POST['from_date'] ?? date('Y-m-d', strtotime('-7 days'))) ?>">
                </div>
                <div class="field">
                    <label for="to_date">მდე</label>
                    <input required type="text" class="date-field" id="to_date" name="to_date" value="<?= htmlspecialchars($_POST['to_date'] ?? date('Y-m-d')) ?>">
                </div>
            </div>
            <div class="actions">
                <button class="btn btn-primary" type="submit" value="one">ჩატვირთვა</button>
                <button class="btn btn-primary" type="submit" value="all">ყველა ანგარიში</button>
                <a class="btn btn-ghost" href="/crm/deal/bog_merge.php">გადახდებთან მიბმა →</a>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($results): ?>
        <div class="card results">
            <table>
                <thead>
                <tr>
                    <th>ანგარიში</th>
                    <th class="num">ახალი</th>
                    <th class="num">დუბლიკატი</th>
                    <th class="num" title="შიდა გადარიცხვა, კონვერტაცია, ხაზინა... - ინახება, მიბმის გვერდზე ჩანს გამოტოვებულებში">გამოტოვებული</th>
                    <th class="num" title="გასავალი თანხები არ ინახება">გასავალი</th>
                    <th class="num" title="ბანკში ჯერ არ არის გატარებული - შემდეგ იმპორტზე ჩაიტვირთება">დაუსრულებელი</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $key => $stats): ?>
                    <?php $acc = $accounts[$key]; ?>
                    <tr>
                        <td>
                            <b><?= htmlspecialchars($acc['project']) ?></b> · <?= htmlspecialchars($acc['name']) ?>
                            <div class="muted"><?= htmlspecialchars($acc['iban'] . ' ' . $acc['currency']) ?></div>
                            <?php foreach ($stats['errors'] as $error): ?>
                                <div class="row-error"><?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td class="num"><b><?= (int)$stats['created'] ?></b></td>
                        <td class="num"><?= (int)$stats['skipped_dup'] ?></td>
                        <td class="num"><?= (int)$stats['skipped_filter'] ?></td>
                        <td class="num"><?= (int)$stats['debit'] ?></td>
                        <td class="num"><?= (int)$stats['pending'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/ka.js"></script>
<script>
// ეკრანზე დღე/თვე/წელი, სერვერზე კი ისევ Y-m-d მიდის
if (window.flatpickr) {
    flatpickr('#from_date, #to_date', {
        locale: 'ka',
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd/m/Y',
        altInputClass: 'date-field',
        disableMobile: true,
    });
}

var loadForm = document.getElementById('loadForm');
if (loadForm) {
    loadForm.addEventListener('submit', function (e) {
        var submitter = e.submitter;
        document.getElementById('MODE').value = submitter && submitter.value === 'all' ? 'all' : 'one';
        loadForm.querySelectorAll('button[type="submit"]').forEach(function (btn) {
            btn.disabled = true;
        });
        if (submitter) {
            submitter.textContent = 'იტვირთება…';
        }
    });
}
</script>
</body>
</html>
<?php
exit;
