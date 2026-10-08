<?php
/**
 * საქართველოს ბანკი — ამონაწერების მიბმა გადახდებთან (bog_merge.php)
 * Statements: BOG_STATEMENTS · Payments: 23 · Stages: bankBogMergeStages()
 */
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/crm/deal/bank_integration/helpers.php';

$APPLICATION->SetTitle('BOG — გადახდებთან მიბმა');
bankBogEnsureModules();

$statementIblockId = bankBogStatementIblockId();

$saveResult = null;
if ($statementIblockId > 0 && !empty($_POST)) {
    $saveResult = bankBogProcessMergePost($_POST);
}

list($listModel, $errorDeals, $skippedStatements) = $statementIblockId > 0
    ? bankBogBuildMergeModels()
    : [[], [], []];
if (!is_array($skippedStatements)) {
    $skippedStatements = [];
}

ob_end_clean();
?><!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BOG — გადახდებთან მიბმა</title>
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
            --surface: #fff;
            --bg: #f4f5f7;
            --ok: #1a8f3c;
            --err: #c0392b;
            --green: #e8f7f3;
            --yellow: #fff7e0;
            --red: #fef3f2;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; min-height: 100%;
            font-family: "Montserrat", "Segoe UI", sans-serif;
            color: var(--ink);
            background: linear-gradient(180deg, #ffffff 0%, var(--bg) 100%);
        }
        .topbar {
            position: sticky; top: 0; z-index: 50;
            background: linear-gradient(135deg, #002445 0%, #00335b 55%, #0a4a75 100%);
            color: #fff;
            padding: 14px 20px;
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
            box-shadow: 0 10px 28px rgba(0, 51, 91, 0.12);
        }
        .brand { display: flex; align-items: flex-start; gap: 12px; }
        .brand-mark {
            width: 46px; height: 46px; border-radius: 4px; flex-shrink: 0;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.25);
            display: grid; place-items: center;
        }
        .brand-mark svg { width: 36px; height: auto; display: block; }
        .brand h1 {
            margin: 0; font-size: 18px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.02em;
        }
        .brand p { margin: 3px 0 0; color: rgba(255, 255, 255, 0.78); font-size: 12px; max-width: 760px; }
        .top-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn {
            appearance: none; border: 0; border-radius: 2px; height: 38px;
            padding: 0 16px; font: inherit; font-weight: 700; cursor: pointer;
            font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary { background: var(--accent); color: #0d3f36; }
        .btn-primary:disabled { opacity: 0.45; cursor: not-allowed; }
        .btn-ghost {
            background: rgba(255, 255, 255, 0.1); color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .btn svg { width: 14px; height: 14px; flex-shrink: 0; }
        .wrap { width: min(1480px, calc(100% - 28px)); margin: 18px auto 60px; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px; }
        .stat {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: 4px; padding: 14px 16px;
            box-shadow: 0 10px 28px rgba(0, 51, 91, 0.05);
        }
        .stat span {
            display: block; font-size: 11px; color: var(--muted); line-height: 1.35;
            text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700;
        }
        .stat strong { font-size: 24px; font-weight: 700; }
        .legend { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 12px; font-size: 12px; color: var(--muted); }
        .legend i { display: inline-block; width: 12px; height: 12px; border-radius: 2px; margin-right: 6px; vertical-align: -1px; }
        .toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; align-items: end; }
        .toolbar .field { display: flex; flex-direction: column; gap: 4px; }
        .toolbar label {
            font-size: 11px; font-weight: 700; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .search, .date-input, .toolbar select {
            height: 40px; border-radius: 4px; border: 1px solid var(--line);
            padding: 0 12px; font: inherit; background: #fff; color: var(--ink);
        }
        .date-input {
            width: 172px; padding-right: 38px; cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2300335b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='5' width='18' height='16' rx='2'/%3E%3Cpath d='M3 10h18M8 3v4M16 3v4'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center; background-size: 16px;
        }
        .flatpickr-calendar { font-family: inherit; border-radius: 4px; box-shadow: 0 10px 28px rgba(0, 51, 91, 0.16); }
        .flatpickr-day.selected, .flatpickr-day.selected:hover, .flatpickr-day.selected:focus {
            background: var(--primary); border-color: var(--primary);
        }
        .flatpickr-day.today { border-color: var(--accent); }
        .flatpickr-day.today:hover, .flatpickr-day.today:focus { background: var(--accent); border-color: var(--accent); color: #fff; }
        .search { flex: 1; min-width: 200px; }
        .section-title {
            font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
            margin: 22px 0 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .section-title .sub { font-size: 12px; font-weight: 500; color: var(--muted); text-transform: none; letter-spacing: 0; }
        .pill {
            display: inline-flex; align-items: center; height: 22px;
            padding: 0 8px; border-radius: 999px; font-size: 11px; font-weight: 700;
            background: var(--primary); color: #fff;
        }
        .table-card {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: 4px; overflow: auto;
            max-height: calc(100vh - 240px);
            box-shadow: 0 10px 28px rgba(0, 51, 91, 0.06);
        }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 1180px; font-size: 13px; }
        thead th {
            text-align: left; padding: 12px 10px;
            background: #e8eef4; color: var(--primary);
            border-bottom: 1px solid var(--line);
            font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
            position: sticky; top: 0; z-index: 6;
        }
        td { padding: 10px; border-bottom: 1px solid #eef1f4; vertical-align: top; }
        tr.filtertr.tone-green { background: var(--green); }
        tr.filtertr.tone-yellow { background: var(--yellow); }
        tr.filtertr.tone-red { background: var(--red); }
        tr.hidden-row { display: none; }
        input.form-control {
            width: 100%; min-width: 72px; height: 36px; border-radius: 4px;
            border: 1px solid var(--line); padding: 0 8px; font: inherit; background: #fff; color: var(--ink);
        }
        input.form-control.deal-inactive { border-color: #e08b86; background: #fff6f5; }
        .alloc-list { display: flex; flex-direction: column; gap: 8px; min-width: 320px; }
        .alloc-row { display: grid; grid-template-columns: minmax(140px, 1.4fr) 110px 34px; gap: 8px; align-items: start; }
        .deal-meta { font-size: 11px; color: var(--muted); line-height: 1.35; margin-top: 4px; }
        .amount { font-variant-numeric: tabular-nums; font-weight: 700; }
        .mini-btn {
            height: 28px; padding: 0 8px; border-radius: 4px;
            border: 1px solid var(--line); background: #fff; color: var(--ink);
            font: inherit; font-size: 12px; font-weight: 600; cursor: pointer;
        }
        .alloc-del {
            height: 36px; width: 34px; border-radius: 4px;
            border: 1px solid #e8bcb8; background: #fef3f2; color: var(--err);
            font-size: 18px; line-height: 1; cursor: pointer; font-weight: 700;
        }
        .flash { margin-bottom: 14px; border-radius: 4px; padding: 12px 14px; font-weight: 600; font-size: 13px; }
        .flash.ok { background: #e8f7ef; color: var(--ok); border: 1px solid #b7e4c7; }
        .flash.err { background: #fef3f2; color: var(--err); border: 1px solid #f5c2c0; }
        .banner {
            display: none; background: #fef3f2; color: var(--err);
            border: 1px solid #f5c2c0; border-left: 5px solid var(--err);
            font-weight: 700; padding: 12px 14px; margin-bottom: 12px;
            border-radius: 4px; position: sticky; top: 84px; z-index: 40;
        }
        .deal-stage-error { color: var(--err); font-size: 12px; font-weight: 600; margin-top: 4px; display: inline-block; }
        .sum-ok { outline: 2px solid rgba(114, 196, 177, 0.6); }
        .sum-bad { outline: 2px solid rgba(192, 57, 43, 0.35); }
        .skipped-panel {
            margin-top: 28px; border: 1px solid var(--line);
            border-radius: 4px; background: var(--surface); overflow: hidden;
        }
        .skipped-toggle {
            width: 100%; appearance: none; border: 0; background: #e8eef4;
            padding: 14px 16px; display: flex; align-items: center;
            justify-content: space-between; gap: 12px; cursor: pointer;
            font: inherit; font-weight: 700; text-align: left; color: var(--primary);
        }
        .skipped-toggle:hover { background: #dde7ef; }
        .skipped-toggle .chev { transition: transform .2s ease; font-size: 18px; color: var(--muted); }
        .skipped-panel.open .skipped-toggle .chev { transform: rotate(180deg); }
        .skipped-body { display: none; border-top: 1px solid var(--line); }
        .skipped-panel.open .skipped-body { display: block; }
        .skipped-filters {
            display: flex; gap: 8px; flex-wrap: wrap; padding: 12px 16px;
            border-bottom: 1px solid var(--line); background: #fafbfc;
        }
        .reason-badge {
            display: inline-block; padding: 4px 8px; border-radius: 4px;
            background: #fff7e0; color: #7a5a00;
            font-size: 12px; font-weight: 700; line-height: 1.35;
        }
        .setup-note {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: 4px; padding: 20px; box-shadow: 0 10px 28px rgba(0, 51, 91, 0.06);
        }
        @media (max-width: 900px) { .stats { grid-template-columns: 1fr 1fr; } }
    </style>
</head>
<body>
<div class="topbar">
    <div class="brand">
        <div class="brand-mark">
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
            <h1>გადახდებთან მიბმა</h1>
            <p>დილები იძებნება სტეიჯებზე: <?= htmlspecialchars(implode(' · ', bankBogMergeStageLabels())) ?> — ამ დილის კონტაქტის პირადი/პასპორტის ნომრის ან კომპანიის საიდ. კოდის მიხედვით.</p>
            <p>კურსი მოდის NBG-დან ამონაწერის თარიღის მიხედვით. "დარჩენილი" ნიშნავს დარჩენილ დავალიანებას $-ში დღემდე (გადახდის გრაფიკის თანხების ჯამს (დღემდე) − უკვე არსებული გადახდების ჯამი).</p>
        </div>
    </div>
    <div class="top-actions">
        <a class="btn btn-ghost" href="/crm/deal/mainpage.php">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 10.5 12 3l9 7.5"></path>
                <path d="M5 9.5V21h14V9.5"></path>
            </svg>
            მთავარი გვერდი
        </a>
        <a class="btn btn-ghost" href="/crm/deal/bog_import.php">← ამონაწერის იმპორტი</a>
        <button class="btn btn-primary" type="submit" form="myForm" id="main_button" disabled>შენახვა</button>
    </div>
</div>

<div class="wrap">
    <?php
    if ($statementIblockId <= 0) {
        echo '<div class="setup-note">'
            . '<p>ამონაწერების სია ჯერ არ არის შექმნილი.</p>'
            . '<p><a href="/crm/deal/bank_integration/setup.php">სიების მომზადება →</a></p>'
            . '</div></div></body></html>';
        exit;
    }
    ?>

    <?php if ($saveResult): ?>
        <?php if (!empty($saveResult['saved'])): ?>
            <div class="flash ok">შენახულია <?= (int)$saveResult['saved'] ?> გადახდა</div>
        <?php endif; ?>
        <?php if (!empty($saveResult['errors'])): ?>
            <div class="flash err">შეცდომები: <?= htmlspecialchars(json_encode($saveResult['errors'], JSON_UNESCAPED_UNICODE)) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <div id="dealValidationBanner" class="banner"></div>

    <div class="stats">
        <div class="stat">
            <span>ამონაწერის მიხედვით მოიძებნა დილები</span>
            <strong id="statMatched">0</strong>
        </div>
        <div class="stat">
            <span>ამონაწერის შესაბამისი დილები ვერ მოიძებნა</span>
            <strong id="statErrors">0</strong>
        </div>
        <div class="stat"><span>ჯამი $</span><strong id="statUsd">0</strong></div>
        <div class="stat"><span>ჯამი ₾</span><strong id="statGel">0</strong></div>
    </div>

    <div class="legend">
        <span><i style="background:#72c4b1"></i>1 დილი</span>
        <span><i style="background:#f5d78e"></i>1-ზე მეტი დილი</span>
        <span><i style="background:#f5c2c0"></i>დილი ვერ მოიძებნა</span>
    </div>

    <div class="toolbar">
        <input class="search" id="searchBox" type="search" placeholder="ძებნა: სახელი, INN, beneficiary, deal ID…">
        <div class="field">
            <label for="dateFrom">თარიღი დან</label>
            <input class="date-input" type="text" id="dateFrom" placeholder="დდ/თთ/წწწწ">
        </div>
        <div class="field">
            <label for="dateTo">თარიღი მდე</label>
            <input class="date-input" type="text" id="dateTo" placeholder="დდ/თთ/წწწწ">
        </div>
        <div class="field">
            <label for="projectFilter">პროექტი</label>
            <select id="projectFilter">
                <option value="">ყველა</option>
            </select>
        </div>
        <div class="field">
            <label for="currencyFilter">ვალუტა</label>
            <select id="currencyFilter">
                <option value="">ყველა</option>
                <option value="GEL">GEL</option>
                <option value="USD">USD</option>
                <option value="EUR">EUR</option>
            </select>
        </div>
    </div>

    <div class="section-title">
        ამონაწერის მიხედვით მოიძებნა დილები
        <span class="pill" id="pillMatched">0</span>
        <span class="sub">მწვანე = 1 დილი · ყვითელი = რამდენიმე</span>
    </div>
    <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="post" id="myForm">
        <div class="table-card">
            <table>
                <thead>
                <tr>
                    <th>კლიენტი</th>
                    <th>თარიღი</th>
                    <th>დანიშნულება</th>
                    <th>მიმღები</th>
                    <th>ვალუტა</th>
                    <th>თანხა ₾</th>
                    <th>თანხა $</th>
                    <th title="NBG კურსი ამონაწერის EntryDate-ის მიხედვით">კურსი</th>
                    <th>დილი / თანხა $</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="tbody_data"></tbody>
            </table>
        </div>
    </form>

    <div class="section-title">
        ამონაწერის შესაბამისი დილები ვერ მოიძებნა
        <span class="pill" id="pillErrors">0</span>
        <span class="sub">ხელით მიბმა</span>
    </div>
    <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="post" id="myForm_er">
        <div class="table-card">
            <table>
                <thead>
                <tr>
                    <th>კლიენტი</th>
                    <th>INN</th>
                    <th>თარიღი</th>
                    <th>დანიშნულება</th>
                    <th>მიმღები</th>
                    <th>ვალუტა</th>
                    <th>კურსი</th>
                    <th>თანხა ₾</th>
                    <th>თანხა $</th>
                    <th>დილი / თანხა $</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="tbody_data_errors"></tbody>
            </table>
        </div>
        <div style="margin-top:12px;">
            <button class="btn btn-primary" type="submit" id="errors_button" disabled>შენახვა (ხელით მიბმა)</button>
        </div>
    </form>

    <div class="skipped-panel" id="skippedPanel">
        <button type="button" class="skipped-toggle" id="skippedToggle" aria-expanded="false">
            <span>
                გამოტოვებული ამონაწერები
                <span class="pill" id="pillSkipped">0</span>
                <span class="sub" style="font-weight:500;color:var(--muted);margin-left:8px;">რატომ არ ჩანს ზემოთ</span>
            </span>
            <span class="chev">▾</span>
        </button>
        <div class="skipped-body">
            <div class="skipped-filters">
                <select id="skippedReasonFilter" class="search" style="flex:0;min-width:260px;height:38px;">
                    <option value="">ყველა მიზეზი</option>
                </select>
                <input class="search" id="skippedSearch" type="search" placeholder="ძებნა გამოტოვებულებში…" style="height:38px;">
            </div>
            <div class="table-card" style="border:0;border-radius:0;box-shadow:none;">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>კლიენტი</th>
                        <th>INN</th>
                        <th>თარიღი</th>
                        <th>დანიშნულება</th>
                        <th>მიმღები</th>
                        <th>ვალუტა</th>
                        <th>თანხა ₾</th>
                        <th>თანხა $</th>
                        <th>მიზეზი</th>
                    </tr>
                    </thead>
                    <tbody id="tbody_skipped"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/ka.js"></script>
<script>
const data = <?= json_encode($listModel, JSON_UNESCAPED_UNICODE) ?>;
const errors = <?= json_encode($errorDeals, JSON_UNESCAPED_UNICODE) ?>;
const skipped = <?= json_encode($skippedStatements, JSON_UNESCAPED_UNICODE) ?>;
const DEAL_VALIDATION_API = '/crm/deal/bank_integration/validate_deal.php';
const ACTIVE_STAGE_LABELS = <?= json_encode(bankBogMergeStageLabels(), JSON_UNESCAPED_UNICODE) ?>;
const dealValidationCache = new Map();
const dealValidationTimers = new Map();
let mainFormTotalsMatch = false;
let errorFormTotalsMatch = false;
let indexDeals = 0;
let indexErrors = 0;

function money(n) {
    return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

/** რომელ ანგარიშზე შემოვიდა: პროექტი · IBAN */
function accountMeta(row) {
    const parts = [row.PROJECT, row.ACCOUNT].filter(Boolean);
    return parts.length ? `<div class="deal-meta">${esc(parts.join(' · '))}</div>` : '';
}
/** EUR-ზე საწყისი თანხაც ჩანს - ცხრილში მხოლოდ ₾ და $ სვეტებია */
function currencyCell(row) {
    const cur = esc(row.CURRENCY);
    return row.CURRENCY === 'EUR' ? `${cur}<div class="deal-meta">${money(row.AMOUNT)} €</div>` : cur;
}

function updateSaveButtonsState() {
    const mainButton = document.getElementById('main_button');
    const errorsButton = document.getElementById('errors_button');
    if (mainButton) {
        mainButton.disabled = !mainFormTotalsMatch || document.querySelectorAll('#myForm input[name^="DEAL_"].deal-inactive').length > 0;
    }
    if (errorsButton) {
        errorsButton.disabled = !errorFormTotalsMatch || document.querySelectorAll('#myForm_er input[name^="DEAL_"].deal-inactive').length > 0;
    }
}

function updateDealValidationBanner() {
    const banner = document.getElementById('dealValidationBanner');
    const invalid = document.querySelectorAll('input[name^="DEAL_"].deal-inactive');
    if (invalid.length) {
        const ids = [...new Set([...invalid].map(i => i.value.trim()).filter(Boolean))];
        banner.style.display = 'block';
        banner.textContent = `${ids.length}: არააქტიური (არ არის სტეიჯებზე: ${ACTIVE_STAGE_LABELS.join(', ')}) ან არასწორი დილის აიდი.`;
    } else {
        banner.style.display = 'none';
        banner.textContent = '';
    }
    updateSaveButtonsState();
}

function clearDealValidationState(input) {
    const wrapper = input.closest('.alloc-deal') || input.parentElement;
    wrapper.querySelectorAll('.deal-stage-error').forEach(el => el.remove());
    input.classList.remove('deal-inactive');
}

function setDealValidationError(wrapper, message) {
    wrapper.querySelectorAll('.deal-stage-error').forEach(el => el.remove());
    const span = document.createElement('span');
    span.className = 'deal-stage-error';
    span.textContent = message;
    wrapper.appendChild(span);
}

async function validateDealInput(input) {
    const dealId = input.value.trim();
    const wrapper = input.closest('.alloc-deal') || input.parentElement;
    if (!dealId) {
        clearDealValidationState(input);
        updateDealValidationBanner();
        return;
    }
    if (dealValidationCache.has(dealId)) {
        const cached = dealValidationCache.get(dealId);
        clearDealValidationState(input);
        if (!cached.exists || !cached.active) {
            input.classList.add('deal-inactive');
            setDealValidationError(wrapper, cached.exists ? `სტეიჯი: ${cached.stage_id || '?'}` : 'დილი ვერ მოიძებნა');
        }
        updateDealValidationBanner();
        return;
    }
    try {
        const res = await fetch(`${DEAL_VALIDATION_API}?deal_id=${encodeURIComponent(dealId)}`);
        const json = await res.json();
        dealValidationCache.set(dealId, json);
        clearDealValidationState(input);
        if (!json.exists || !json.active) {
            input.classList.add('deal-inactive');
            setDealValidationError(wrapper, json.exists ? `სტეიჯი: ${json.stage_id || '?'}` : 'დილი ვერ მოიძებნა');
        }
    } catch (e) {}
    updateDealValidationBanner();
}

function scheduleDealValidation(input) {
    const key = input.name;
    if (dealValidationTimers.has(key)) clearTimeout(dealValidationTimers.get(key));
    dealValidationTimers.set(key, setTimeout(() => validateDealInput(input), 350));
}

function bindDealInput(input) {
    input.addEventListener('input', () => scheduleDealValidation(input));
    input.addEventListener('blur', () => validateDealInput(input));
    if (input.value.trim()) validateDealInput(input);
}

/** მხოლოდ შევსებული რიგები უნდა ემთხვეოდეს; ცარიელი რიგები არ ბლოკავს შენახვას */
function checkRowTotals(formId) {
    const form = document.getElementById(formId);
    const rows = form.querySelectorAll('tr.filtertr:not(.hidden-row)');
    let hasValid = false;
    let hasInvalidFilled = false;

    rows.forEach(row => {
        const expected = Number(row.dataset.expectedUsd || 0);
        const values = [...row.querySelectorAll('input[name^="VALUE_"]')].map(i => Number(i.value || 0));
        const sum = values.reduce((a, b) => a + b, 0);
        const filled = values.some(v => v > 0);
        const ok = filled && Math.abs(sum - expected) < 0.02;
        row.classList.toggle('sum-ok', ok);
        row.classList.toggle('sum-bad', filled && !ok);
        if (ok) hasValid = true;
        if (filled && !ok) hasInvalidFilled = true;
    });

    const result = hasValid && !hasInvalidFilled;
    if (formId === 'myForm') mainFormTotalsMatch = result;
    if (formId === 'myForm_er') errorFormTotalsMatch = result;
    updateSaveButtonsState();
}

function buildAllocRow(idx, paymentId, dealId, metaHtml, value) {
    return `<div class="alloc-row" data-idx="${idx}">
        <div class="alloc-deal">
            <input class="form-control" name="DEAL_${idx}" value="${esc(dealId)}" placeholder="Deal ID">
            <input type="hidden" name="PAYMENT_${idx}" value="${esc(paymentId)}">
            ${metaHtml || ''}
        </div>
        <div class="alloc-value">
            <input class="form-control" name="VALUE_${idx}" type="number" step="0.01" value="${value}">
        </div>
        <button type="button" class="alloc-del" title="წაშლა" onclick="removeAllocRow(this)">×</button>
    </div>`;
}

function removeAllocRow(btn) {
    const row = btn.closest('tr');
    const form = row.closest('form');
    const formId = form.id;
    const allocRow = btn.closest('.alloc-row');
    const list = row.querySelector('.alloc-list');
    if (list.querySelectorAll('.alloc-row').length <= 1) {
        // ბოლო ხაზი — ცარიელდება, არ იშლება მთლიანად
        const deal = allocRow.querySelector('input[name^="DEAL_"]');
        const val = allocRow.querySelector('input[name^="VALUE_"]');
        deal.value = '';
        val.value = '0';
        clearDealValidationState(deal);
        const meta = allocRow.querySelector('.deal-meta');
        if (meta) meta.remove();
    } else {
        allocRow.remove();
    }
    checkRowTotals(formId);
    updateDealValidationBanner();
}

function addDealField(btn, formId) {
    const row = btn.closest('tr');
    const list = row.querySelector('.alloc-list');
    const idx = formId === 'myForm' ? indexDeals++ : indexErrors++;
    const paymentId = row.querySelector('input[name^="PAYMENT_"]').value;
    list.insertAdjacentHTML('beforeend', buildAllocRow(idx, paymentId, '', '', 0));
    const newRow = list.lastElementChild;
    bindDealInput(newRow.querySelector('input[name^="DEAL_"]'));
    newRow.querySelector('input[name^="VALUE_"]').addEventListener('input', () => checkRowTotals(formId));
    checkRowTotals(formId);
}

function renderMatched() {
    const tbody = document.getElementById('tbody_data');
    tbody.innerHTML = '';
    indexDeals = 0;
    (data || []).forEach((row) => {
        const deals = row.MERGE_DEALS || [];
        const tone = deals.length > 1 ? 'tone-yellow' : 'tone-green';
        const allocParts = [];
        if (deals.length) {
            deals.forEach((d) => {
                const idx = indexDeals++;
                const prefill = deals.length === 1 ? Number(row.BANK_AMOUNT_USD || 0) : 0;
                const meta = `<div class="deal-meta" title="დარჩენილი დავალიანება: გრაფიკი − გადახდები (დღემდე)">#${esc(d.ID)} · ${esc(d.PROJECT || '—')} · ${esc(d.BLOCK || '')} ${esc(d.UNIT || '')}<br>დარჩენილი: <b>${money(d.LEFT_TO_PAY)}</b></div>`;
                allocParts.push(buildAllocRow(idx, row.list_id, d.ID, meta, prefill));
            });
        } else {
            const idx = indexDeals++;
            allocParts.push(buildAllocRow(idx, row.list_id, '', '', 0));
        }

        const tr = document.createElement('tr');
        tr.className = `filtertr ${tone}`;
        tr.dataset.expectedUsd = String(row.BANK_AMOUNT_USD || 0);
        tr.dataset.date = String(row.DATE || '');
        tr.dataset.search = [
            row.CLIENT_NAME, row.NAME, row.INN, row.BENEFICIARY, row.NOMINATION, row.CURRENCY, row.PROJECT, row.ACCOUNT,
            ...(deals.map(d => d.ID + ' ' + (d.NAME || '')))
        ].join(' ').toLowerCase();
        tr.dataset.currency = (row.CURRENCY || '').toUpperCase();
        tr.dataset.project = row.PROJECT || '';
        tr.innerHTML = `
            <td><b>${esc(row.CLIENT_NAME || row.NAME)}</b><div class="deal-meta">${esc(row.STATUS || '')} · ${esc(row.INN || '')}</div></td>
            <td>${esc(row.DATE)}</td>
            <td>${esc(row.NOMINATION)}</td>
            <td>${esc(row.BENEFICIARY)}${accountMeta(row)}</td>
            <td>${currencyCell(row)}</td>
            <td class="amount">${money(row.BANK_AMOUNT_GEL)}</td>
            <td class="amount">${money(row.BANK_AMOUNT_USD)}</td>
            <td title="NBG USD კურსი ამონაწერის თარიღზე (${esc(row.DATE)})">${esc(row.NBG_RATE)}</td>
            <td><div class="alloc-list">${allocParts.join('')}</div></td>
            <td><button type="button" class="mini-btn" onclick="addDealField(this,'myForm')">+ დილი</button></td>
        `;
        tbody.appendChild(tr);
        tr.querySelectorAll('input[name^="DEAL_"]').forEach(bindDealInput);
        tr.querySelectorAll('input[name^="VALUE_"]').forEach(inp => {
            inp.addEventListener('input', () => checkRowTotals('myForm'));
        });
    });
    checkRowTotals('myForm');
}

function renderErrors() {
    const tbody = document.getElementById('tbody_data_errors');
    tbody.innerHTML = '';
    indexErrors = 100000;
    (errors || []).forEach((row) => {
        const idx = indexErrors++;
        const usd = Number(row.AMOUNT_USD || row.BANK_AMOUNT_USD || 0);
        const tr = document.createElement('tr');
        tr.className = 'filtertr tone-red';
        tr.dataset.expectedUsd = String(usd);
        tr.dataset.date = String(row.DATE || '');
        tr.dataset.search = [row.NAME, row.INN, row.BENEFICIARY, row.NOMINATION, row.CURRENCY, row.PROJECT, row.ACCOUNT].join(' ').toLowerCase();
        tr.dataset.currency = (row.CURRENCY || '').toUpperCase();
        tr.dataset.project = row.PROJECT || '';
        tr.innerHTML = `
            <td><b>${esc(row.NAME)}</b></td>
            <td>${esc(row.INN)}</td>
            <td>${esc(row.DATE)}</td>
            <td>${esc(row.NOMINATION)}</td>
            <td>${esc(row.BENEFICIARY)}${accountMeta(row)}</td>
            <td>${currencyCell(row)}</td>
            <td title="NBG USD კურსი ამონაწერის თარიღზე">${esc(row.NBG_RATE)}</td>
            <td class="amount">${money(row.AMOUNT_GEL || row.BANK_AMOUNT_GEL)}</td>
            <td class="amount">${money(usd)}</td>
            <td><div class="alloc-list">${buildAllocRow(idx, row.PAYMENT || row.list_id, '', '', usd)}</div></td>
            <td><button type="button" class="mini-btn" onclick="addDealField(this,'myForm_er')">+ დილი</button></td>
        `;
        tbody.appendChild(tr);
        tr.querySelectorAll('input[name^="DEAL_"]').forEach(bindDealInput);
        tr.querySelectorAll('input[name^="VALUE_"]').forEach(inp => {
            inp.addEventListener('input', () => checkRowTotals('myForm_er'));
        });
    });
    checkRowTotals('myForm_er');
}

function refreshStats() {
    document.getElementById('statMatched').textContent = (data || []).length;
    document.getElementById('statErrors').textContent = (errors || []).length;
    document.getElementById('pillMatched').textContent = (data || []).length;
    document.getElementById('pillErrors').textContent = (errors || []).length;
    document.getElementById('pillSkipped').textContent = (skipped || []).length;
    let usd = 0, gel = 0;
    [...(data || []), ...(errors || [])].forEach(r => {
        usd += Number(r.BANK_AMOUNT_USD || r.AMOUNT_USD || 0);
        gel += Number(r.BANK_AMOUNT_GEL || r.AMOUNT_GEL || 0);
    });
    document.getElementById('statUsd').textContent = money(usd);
    document.getElementById('statGel').textContent = money(gel);
}

function renderSkipped() {
    const tbody = document.getElementById('tbody_skipped');
    const reasonSelect = document.getElementById('skippedReasonFilter');
    const reasons = new Map();
    (skipped || []).forEach(r => {
        const code = r.REASON_CODE || 'other';
        const label = r.REASON || code;
        if (!reasons.has(code)) reasons.set(code, label);
    });
    const current = reasonSelect.value;
    reasonSelect.innerHTML = '<option value="">ყველა მიზეზი</option>';
    [...reasons.entries()].sort((a, b) => a[1].localeCompare(b[1], 'ka')).forEach(([code, label]) => {
        const opt = document.createElement('option');
        opt.value = code;
        opt.textContent = label;
        reasonSelect.appendChild(opt);
    });
    reasonSelect.value = current;

    tbody.innerHTML = '';
    if (!(skipped || []).length) {
        tbody.innerHTML = '<tr><td colspan="10" style="padding:18px;color:var(--muted);">გამოტოვებული ამონაწერი არ არის</td></tr>';
        return;
    }
    (skipped || []).forEach(row => {
        const tr = document.createElement('tr');
        tr.className = 'skipped-row';
        tr.dataset.reason = row.REASON_CODE || '';
        tr.dataset.search = [row.NAME, row.INN, row.BENEFICIARY, row.NOMINATION, row.REASON, row.list_id, row.PROJECT, row.ACCOUNT].join(' ').toLowerCase();
        tr.innerHTML = `
            <td>${esc(row.list_id)}</td>
            <td><b>${esc(row.NAME)}</b></td>
            <td>${esc(row.INN)}</td>
            <td>${esc(row.DATE)}</td>
            <td>${esc(row.NOMINATION)}</td>
            <td>${esc(row.BENEFICIARY)}${accountMeta(row)}</td>
            <td>${currencyCell(row)}</td>
            <td class="amount">${money(row.AMOUNT_GEL)}</td>
            <td class="amount">${money(row.AMOUNT_USD)}</td>
            <td><span class="reason-badge">${esc(row.REASON)}</span></td>
        `;
        tbody.appendChild(tr);
    });
}

function applySkippedFilters() {
    const q = (document.getElementById('skippedSearch').value || '').trim().toLowerCase();
    const reason = document.getElementById('skippedReasonFilter').value || '';
    document.querySelectorAll('#tbody_skipped tr.skipped-row').forEach(tr => {
        const okR = !reason || tr.dataset.reason === reason;
        const okQ = !q || (tr.dataset.search || '').includes(q);
        tr.style.display = (okR && okQ) ? '' : 'none';
    });
}

function applyFilters() {
    const q = (document.getElementById('searchBox').value || '').trim().toLowerCase();
    const cur = (document.getElementById('currencyFilter').value || '').toUpperCase();
    const project = document.getElementById('projectFilter').value || '';
    const from = document.getElementById('dateFrom').value || '';
    const to = document.getElementById('dateTo').value || '';

    document.querySelectorAll('tr.filtertr').forEach(tr => {
        const hay = tr.dataset.search || '';
        const rowCur = tr.dataset.currency || '';
        const rowDate = tr.dataset.date || '';
        const okQ = !q || hay.includes(q);
        const okC = !cur || rowCur === cur;
        const okP = !project || tr.dataset.project === project;
        let okD = true;
        if (from && rowDate && rowDate < from) okD = false;
        if (to && rowDate && rowDate > to) okD = false;
        tr.classList.toggle('hidden-row', !(okQ && okC && okP && okD));
    });
    checkRowTotals('myForm');
    checkRowTotals('myForm_er');
}

function fillProjectFilter() {
    const select = document.getElementById('projectFilter');
    const projects = [...new Set([...(data || []), ...(errors || [])].map(r => r.PROJECT).filter(Boolean))].sort();
    projects.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p;
        select.appendChild(opt);
    });
}

renderMatched();
renderErrors();
renderSkipped();
refreshStats();
fillProjectFilter();
document.getElementById('searchBox').addEventListener('input', applyFilters);
document.getElementById('currencyFilter').addEventListener('change', applyFilters);
document.getElementById('projectFilter').addEventListener('change', applyFilters);
// ეკრანზე დღე/თვე/წელი, ფილტრი კი Y-m-d მნიშვნელობას ადარებს; ველის წაშლა ფილტრსაც ხსნის
if (window.flatpickr) {
    flatpickr('#dateFrom, #dateTo', {
        locale: 'ka',
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd/m/Y',
        altInputClass: 'date-input',
        allowInput: true,
        disableMobile: true,
    });
}
document.getElementById('dateFrom').addEventListener('change', applyFilters);
document.getElementById('dateTo').addEventListener('change', applyFilters);

document.getElementById('skippedToggle').addEventListener('click', function () {
    const panel = document.getElementById('skippedPanel');
    const open = panel.classList.toggle('open');
    this.setAttribute('aria-expanded', open ? 'true' : 'false');
});
document.getElementById('skippedReasonFilter').addEventListener('change', applySkippedFilters);
document.getElementById('skippedSearch').addEventListener('input', applySkippedFilters);
</script>
</body>
</html>
<?php
exit;
