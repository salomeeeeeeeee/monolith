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
$skipResult = null;
if ($statementIblockId > 0 && !empty($_POST)) {
    // "არ საჭიროებს დამუშავებას" / "დაბრუნება" - ცალკე ფორმიდან, შემდეგ გვერდი თავიდან იტვირთება
    $skipAction = (string)($_POST['skip_action'] ?? '');
    if ($skipAction !== '') {
        if (!check_bitrix_sessid()) {
            $skipResult = ['ok' => false, 'error' => 'session_expired'];
        } elseif ($skipAction === 'skip') {
            $skipResult = bankBogManualSkip($_POST['statement_id'] ?? 0, $_POST['comment'] ?? '', trim($USER->GetFullName()) ?: $USER->GetLogin());
        } elseif ($skipAction === 'restore') {
            $skipResult = bankBogRestoreSkipped($_POST['statement_id'] ?? 0);
        }
        if (!empty($skipResult['ok'])) {
            LocalRedirect($APPLICATION->GetCurPage() . '?done=' . ($skipAction === 'skip' ? 'skip' : 'restore'));
        }
    } else {
        $saveResult = bankBogProcessMergePost($_POST);
    }
}
$skipErrors = [
    'session_expired' => 'სესია ამოიწურა - განაახლე გვერდი და სცადე თავიდან',
    'statement_not_found' => 'ამონაწერი ვერ მოიძებნა',
    'already_skipped' => 'ამონაწერი უკვე გამოტოვებულია',
    'already_merged' => 'ამონაწერი უკვე მიბმულია გადახდაზე',
    'not_manual' => 'ავტომატურად გამოტოვებული ამონაწერი ვერ დაბრუნდება',
];
$done = (string)($_GET['done'] ?? '');

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
            --blue: #d3e3fa;
            --blue-strong: #2563c9;
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
        /* მთელ სიგანეზე, გვერდებზე მხოლოდ მცირე დაშორება */
        .wrap { margin: 18px 12px 60px; }
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
        /* ცხრილი ეკრანში ეტევა: გრძელი ტექსტი (IBAN, SWIFT, მისამართი) გადადის ახალ ხაზზე, სიგანის სქროლი არ ჩნდება */
        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
        thead th {
            text-align: left; padding: 12px 8px;
            background: #e8eef4; color: var(--primary);
            border-bottom: 1px solid var(--line);
            font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
            position: sticky; top: 0; z-index: 6;
        }
        td { padding: 10px 8px; border-bottom: 1px solid #eef1f4; vertical-align: top; overflow-wrap: anywhere; }
        .nowrap { white-space: nowrap; }
        th.col-client { width: 150px; }
        th.col-alloc { width: 290px; }
        tr.filtertr.tone-green { background: var(--green); }
        tr.filtertr.tone-yellow { background: var(--yellow); }
        tr.filtertr.tone-red { background: var(--red); }
        /* ცისფერი მწვანეს არ უნდა ჰგავდეს: მუქი ზოლი მარცხნივ + ბეჯი კლიენტთან */
        tr.filtertr.tone-blue { background: var(--blue); }
        tr.filtertr.tone-blue > td:first-child { box-shadow: inset 5px 0 0 var(--blue-strong); }
        .recognized-badge {
            display: inline-block; margin-top: 5px; padding: 2px 8px; border-radius: 999px;
            background: var(--blue-strong); color: #fff; font-size: 11px; font-weight: 700;
        }
        .history-note { color: var(--blue-strong); font-weight: 700; }
        .cash-badge {
            display: inline-block; margin-top: 5px; padding: 2px 8px; border-radius: 999px;
            background: #7a5a00; color: #fff; font-size: 11px; font-weight: 700;
        }
        .unit-note { color: var(--ok); font-weight: 700; }
        .unit-note.miss { color: #9a6700; }
        .suggest-box { margin-top: 6px; display: flex; gap: 6px; align-items: flex-start; flex-wrap: wrap; }
        tr.hidden-row { display: none; }
        input.form-control {
            width: 100%; min-width: 0; height: 36px; border-radius: 4px;
            border: 1px solid var(--line); padding: 0 8px; font: inherit; background: #fff; color: var(--ink);
        }
        input.form-control.deal-inactive { border-color: #e08b86; background: #fff6f5; }
        .alloc-list { display: flex; flex-direction: column; gap: 8px; }
        .alloc-row { display: grid; grid-template-columns: minmax(100px, 1fr) minmax(80px, 104px) 34px; gap: 6px; align-items: start; }
        .deal-meta { font-size: 11px; color: var(--muted); line-height: 1.35; margin-top: 4px; }
        .amount { font-variant-numeric: tabular-nums; font-weight: 700; white-space: nowrap; }
        .mini-btn {
            height: 28px; padding: 0 8px; border-radius: 4px; white-space: nowrap;
            border: 1px solid var(--line); background: #fff; color: var(--ink);
            font: inherit; font-size: 12px; font-weight: 600; cursor: pointer;
        }
        /* დილის ინფო ველების ქვეშ, მთელ სიგანეზე */
        .alloc-meta { grid-column: 1 / -1; margin-top: -4px; }
        .alloc-meta .deal-meta { margin-top: 0; }
        .add-deal { margin-top: 8px; }
        /* აბონირების ხაზი: თანხა ₾-ში, ცალკე სიაში ინახება */
        .alloc-row.alloc-sub { padding: 6px; margin: 0 -6px; border-radius: 6px; background: #f1ecfb; }
        .alloc-sub input[name^="DEAL_"] { background: #f7f5fb; }
        .sub-badge {
            display: inline-block; margin-top: 4px; padding: 1px 7px; border-radius: 999px;
            background: #6b4fbb; color: #fff; font-size: 11px; font-weight: 700;
        }
        .sub-note { color: #6b4fbb; }
        .sub-note .mini-btn { height: 22px; margin-left: 4px; color: #6b4fbb; border-color: #cfc3ee; }
        .sub-note .mini-btn:disabled { opacity: .45; cursor: default; }
        .sub-over input[name^="VALUE_"] { border-color: #e08b86; background: #fff6f5; }
        /* ერთეული ველში: ბინა $, აბონირება ₾ */
        .alloc-value { position: relative; }
        .alloc-value::after {
            content: attr(data-unit); position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            font-size: 12px; font-weight: 700; color: var(--muted); pointer-events: none;
        }
        .alloc-value input { padding-right: 20px; -moz-appearance: textfield; }
        .alloc-value input::-webkit-outer-spin-button,
        .alloc-value input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .alloc-sum { margin-top: 6px; font-size: 12px; font-weight: 600; color: var(--err); }
        .alloc-sum:empty { display: none; }
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
        .project-summary { max-height: none; margin-bottom: 4px; }
        .project-summary tbody tr { cursor: pointer; }
        .project-summary tbody tr:hover { background: #f3f7fa; }
        .project-summary tbody tr.active { background: var(--green); box-shadow: inset 5px 0 0 var(--accent); }
        .project-summary tr.total-row td { font-weight: 700; background: #f7f9fb; cursor: default; }
        .skip-btn { margin-top: 8px; color: var(--muted); }
        .skip-btn:hover { color: var(--err); border-color: #e8bcb8; }
        .restore-btn { margin-top: 6px; display: block; }
        .setup-note {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: 4px; padding: 20px; box-shadow: 0 10px 28px rgba(0, 51, 91, 0.06);
        }
        @media (max-width: 1200px) { th.col-alloc { width: 240px; } }
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
            <p>თუ გადამხდელის ჩარიცხვა ადრე სხვა დილზე უკვე აქამდე არის გატარებული, შემდეგ ჯერზე ის დილი იქნება ნაჩვენები/შემოთავაზებული (ცისფერი რიგი) - ამოიცნობა INN-ით, INN-ის გარეშე კი გამგზავნის ანგარიშით.</p>
            <p>სალაროს ექსელიდან ატვირთული ჩანაწერები "სალარო" ნიშნით აქვე ჩანს: დილი საფუძველში ჩაწერილი ფართის ნომრით წინასწარ ირჩევა, შექმნილ გადახდას ტიპი "სალარო" ეწერება.</p>
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
        <a class="btn btn-ghost" href="/crm/deal/cash_import.php">სალაროს ექსელი</a>
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

    <?php if ($done === 'skip'): ?>
        <div class="flash ok">ოპერაცია გადავიდა "გამოტოვებულ ამონაწერებში" (ხელით გამოტოვებული)</div>
    <?php elseif ($done === 'restore'): ?>
        <div class="flash ok">ოპერაცია დაბრუნდა დასამუშავებლებში</div>
    <?php endif; ?>
    <?php if ($skipResult && empty($skipResult['ok'])): ?>
        <div class="flash err"><?= htmlspecialchars($skipErrors[$skipResult['error'] ?? ''] ?? ('შეცდომა: ' . ($skipResult['error'] ?? ''))) ?></div>
    <?php endif; ?>

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
        <span><i style="background:#2563c9"></i>ამოცნობილი წინა გატარებით</span>
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

    <div class="section-title" style="margin-top:4px;">
        დასამუშავებელი ოპერაციები პროექტების მიხედვით
        <span class="sub">ითვლება ზემოთ არჩეული ძებნის, თარიღისა და ვალუტის მიხედვით · პროექტზე დაჭერა ქვემოთ მხოლოდ მას დატოვებს</span>
    </div>
    <div class="table-card project-summary">
        <table>
            <thead>
            <tr>
                <th>პროექტი</th>
                <th>ოპერაციები</th>
                <th>დილი მოიძებნა</th>
                <th>დილი ვერ მოიძებნა</th>
                <th>ჯამი $</th>
                <th>ჯამი ₾</th>
            </tr>
            </thead>
            <tbody id="tbody_projects"></tbody>
        </table>
    </div>

    <form method="post" id="skipForm" style="display:none;">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="skip_action" value="">
        <input type="hidden" name="statement_id" value="">
        <input type="hidden" name="comment" value="">
    </form>

    <div class="section-title">
        ამონაწერის მიხედვით მოიძებნა დილები
        <span class="pill" id="pillMatched">0</span>
        <span class="sub">მწვანე = 1 დილი · ყვითელი = რამდენიმე · ცისფერი = წინა გატარებით</span>
    </div>
    <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="post" id="myForm">
        <div class="table-card">
            <table>
                <thead>
                <tr>
                    <th class="col-client">კლიენტი</th>
                    <th>თარიღი</th>
                    <th>დანიშნულება</th>
                    <th>მიმღები</th>
                    <th>ვალუტა</th>
                    <th>თანხა ₾</th>
                    <th>თანხა $</th>
                    <th title="NBG კურსი ამონაწერის EntryDate-ის მიხედვით">კურსი</th>
                    <th class="col-alloc" title="ბინის გადახდა $-ში, აბონირება ₾-ში">დილი / თანხა $ (აბონირება ₾)</th>
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
                    <th class="col-client">კლიენტი</th>
                    <th>INN</th>
                    <th>თარიღი</th>
                    <th>დანიშნულება</th>
                    <th>მიმღები</th>
                    <th>ვალუტა</th>
                    <th>კურსი</th>
                    <th>თანხა ₾</th>
                    <th>თანხა $</th>
                    <th class="col-alloc" title="ბინის გადახდა $-ში, აბონირება ₾-ში">დილი / თანხა $ (აბონირება ₾)</th>
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
                        <th class="col-client">კლიენტი</th>
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
/** სალაროს ჩანაწერის ბეჯი და საფუძვლიდან ამოცნობილი ფართი; $matched - შეირჩა თუ არა დილი, $missText - რას ნიშნავს, თუ არა */
function cashMeta(row, matched, missText) {
    if (!row.CASH) return '';
    const h = row.UNIT_HINT;
    const label = h ? [h.BLOCK ? `ბლოკი ${h.BLOCK}` : '', `${h.TYPE || 'ფართი'} ${h.UNIT}`].filter(Boolean).join(' · ') : '';
    const note = label
        ? `<div class="deal-meta"><span class="unit-note${matched ? '' : ' miss'}">საფუძვლიდან: ${esc(label)}${matched ? '' : ' - ' + esc(missText)}</span></div>`
        : '';
    return `<div><span class="cash-badge">სალარო</span></div>${note}`;
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
        renderSubscriptionAction(input, null);
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
        renderSubscriptionAction(input, cached);
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
        renderSubscriptionAction(input, json);
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
        const rate = Number(row.dataset.nbg || 0);
        let sum = 0;
        let filled = false;
        let subOver = false;
        row.querySelectorAll('.alloc-row').forEach(alloc => {
            const v = Number(alloc.querySelector('input[name^="VALUE_"]').value || 0);
            if (v > 0) filled = true;
            if (!alloc.classList.contains('alloc-sub')) {
                sum += v;
                return;
            }
            // აბონირება ₾-შია: ამონაწერის $ თანხასთან შესადარებლად NBG კურსით
            sum += rate > 0 ? v / rate : 0;
            const over = v > Number(alloc.dataset.left || 0) + 0.01;
            alloc.classList.toggle('sub-over', over);
            if (over) subOver = true;
        });
        const ok = filled && !subOver && Math.abs(sum - expected) < 0.02;
        // რატომ არ ემთხვევა - ხაზების ქვეშ
        let note = row.querySelector('.alloc-sum');
        if (!note) {
            note = document.createElement('div');
            note.className = 'alloc-sum';
            row.querySelector('.alloc-list').after(note);
        }
        note.textContent = !filled || ok ? '' : (subOver
            ? 'აბონირება დარჩენილზე მეტია'
            : `ჯამი $${money(sum)}, ამონაწერი $${money(expected)} - სხვაობა $${money(expected - sum)}`);
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

/** opts.sub - აბონირების ხაზი (თანხა ₾, opts.left - დარჩენილი აბონირება); opts.subHtml - დილის აბონირების ინფო / ღილაკი */
function buildAllocRow(idx, paymentId, dealId, metaHtml, value, opts = {}) {
    const sub = !!opts.sub;
    return `<div class="alloc-row${sub ? ' alloc-sub' : ''}" data-idx="${idx}"${sub ? ` data-deal="${esc(dealId)}" data-left="${Number(opts.left || 0)}"` : ''}>
        <div class="alloc-deal">
            <input class="form-control" name="DEAL_${idx}" value="${esc(dealId)}" placeholder="Deal ID"${sub ? ' readonly' : ''}>
            <input type="hidden" name="PAYMENT_${idx}" value="${esc(paymentId)}">
            <input type="hidden" name="KIND_${idx}" value="${sub ? 'sub' : 'flat'}">
            ${sub ? '<span class="sub-badge">აბონირება ₾</span>' : ''}
        </div>
        <div class="alloc-value" data-unit="${sub ? '₾' : '$'}">
            <input class="form-control" name="VALUE_${idx}" type="number" step="0.01" value="${value}">
        </div>
        <button type="button" class="alloc-del" title="წაშლა" onclick="removeAllocRow(this)">×</button>
        ${metaHtml ? `<div class="alloc-meta">${metaHtml}</div>` : ''}
        ${opts.subHtml || ''}
    </div>`;
}

function round2(n) {
    return Math.round(Number(n || 0) * 100) / 100;
}
/** 0 ზუსტად "0"-ად: სერვერი ასეთ ხაზს გამოტოვებს */
function valueText(n) {
    return n > 0 ? round2(n).toFixed(2) : '0';
}

/** დილის აბონირება ბინის ხაზის ქვეშ: თანხა, დარჩენილი და "+ აბონირება" */
function subscriptionAction(dealId, sub) {
    if (!sub) return '';
    const left = Number(sub.LEFT || 0);
    const btn = left > 0.009
        ? `<button type="button" class="mini-btn sub-add" data-left="${left}" onclick="addSubscriptionRow(this)" title="ამონაწერის ნაწილი აბონირებაზე (₾)">+ აბონირება</button>`
        : '';
    return `<div class="alloc-meta sub-action" data-deal="${esc(dealId)}"><div class="deal-meta sub-note">აბონირება: ${money(sub.AMOUNT)} ₾ · დარჩენილი: <b>${money(left)} ₾</b>${btn}</div></div>`;
}

/** ვალიდაციის შემდეგ: ხელით ჩაწერილ / შეცვლილ დილსაც უჩნდება აბონირება */
function renderSubscriptionAction(input, info) {
    const allocRow = input.closest('.alloc-row');
    if (!allocRow || allocRow.classList.contains('alloc-sub')) return;
    allocRow.querySelectorAll('.sub-action').forEach(el => el.remove());
    if (info && info.exists && info.active && info.sub) {
        allocRow.insertAdjacentHTML('beforeend', subscriptionAction(input.value.trim(), info.sub));
        syncSubscriptionButtons(allocRow.closest('tr'));
    }
}

/** ერთ ამონაწერზე ერთი დილის აბონირება ერთხელ */
function syncSubscriptionButtons(tr) {
    if (!tr) return;
    tr.querySelectorAll('.sub-action').forEach(action => {
        const btn = action.querySelector('.sub-add');
        if (btn) btn.disabled = !!tr.querySelector(`.alloc-sub[data-deal="${CSS.escape(action.dataset.deal || '')}"]`);
    });
}

/** ბინის ხაზის ქვეშ აბონირების ხაზი: ₾ თანხიდან რამდენიც დარჩენილ აბონირებას ეტევა, ბინას რჩება დანარჩენი */
function addSubscriptionRow(btn) {
    const allocRow = btn.closest('.alloc-row');
    const tr = btn.closest('tr');
    const formId = tr.closest('form').id;
    const dealId = allocRow.querySelector('input[name^="DEAL_"]').value.trim();
    if (!dealId || tr.querySelector(`.alloc-sub[data-deal="${CSS.escape(dealId)}"]`)) return;
    const left = Number(btn.dataset.left || 0);
    const rate = Number(tr.dataset.nbg || 0);
    const subGel = round2(Math.min(left, Number(tr.dataset.gel || 0)));
    const flatInput = allocRow.querySelector('input[name^="VALUE_"]');
    const flatUsd = Number(flatInput.value || 0);
    if (flatUsd > 0 && rate > 0) {
        flatInput.value = valueText(flatUsd - subGel / rate);
    }
    const idx = formId === 'myForm' ? indexDeals++ : indexErrors++;
    const paymentId = allocRow.querySelector('input[name^="PAYMENT_"]').value;
    allocRow.insertAdjacentHTML('afterend', buildAllocRow(idx, paymentId, dealId, '', valueText(subGel), { sub: true, left }));
    allocRow.nextElementSibling.querySelector('input[name^="VALUE_"]').addEventListener('input', () => checkRowTotals(formId));
    syncSubscriptionButtons(tr);
    checkRowTotals(formId);
}

// ხაზში თანხის ჩაწერისას დანარჩენი სხვა ხაზზე გადადის. capture - checkRowTotals-მდე ეშვება
let editSeq = 0;
document.addEventListener('input', e => {
    const row = e.target.matches && e.target.matches('input[name^="VALUE_"]') ? e.target.closest('.alloc-row') : null;
    if (!row) return;
    row.dataset.edited = String(++editSeq);
    rebalanceRemainder(row);
}, true);

/**
 * მხოლოდ აბონირებიან ამონაწერზე: დანარჩენი თანხა იწერება ხაზში, რომელიც ყველაზე დიდი ხნის წინ შეიცვალა
 * (ან საერთოდ არ შეცვლილა); შეუცვლელებიდან ჯერ აბონირებაში, მერე იმავე დილის ბინაში, მერე სხვა დილში.
 * აბონირების გარეშე ამონაწერზე არაფერი ივსება - თანხა სხვა დილზე უნებლიედ არ უნდა გადავიდეს.
 */
function rebalanceRemainder(edited) {
    const tr = edited.closest('tr');
    const rate = Number(tr.dataset.nbg || 0);
    const rows = [...tr.querySelectorAll('.alloc-row')];
    const subDeals = rows.filter(r => r.classList.contains('alloc-sub')).map(r => r.dataset.deal);
    if (!subDeals.length || rows.length < 2 || rate <= 0) return;
    const rank = r => r.classList.contains('alloc-sub') ? 0
        : (subDeals.includes(r.querySelector('input[name^="DEAL_"]').value.trim()) ? 1 : 2);
    const target = rows.filter(r => r !== edited).sort((a, b) =>
        (Number(a.dataset.edited || 0) - Number(b.dataset.edited || 0)) || (rank(a) - rank(b)))[0];
    let othersUsd = 0;
    rows.forEach(r => {
        if (r === target) return;
        const v = Number(r.querySelector('input[name^="VALUE_"]').value || 0);
        othersUsd += r.classList.contains('alloc-sub') ? v / rate : v;
    });
    target.querySelector('input[name^="VALUE_"]').value = target.classList.contains('alloc-sub')
        ? valueText(Number(tr.dataset.gel || 0) - othersUsd * rate)
        : valueText(Number(tr.dataset.expectedUsd || 0) - othersUsd);
}

function removeAllocRow(btn) {
    const row = btn.closest('tr');
    const form = row.closest('form');
    const formId = form.id;
    const allocRow = btn.closest('.alloc-row');
    const list = row.querySelector('.alloc-list');
    if (allocRow.classList.contains('alloc-sub')) {
        allocRow.remove();
        syncSubscriptionButtons(row);
    } else if (list.querySelectorAll('.alloc-row').length <= 1) {
        // ბოლო ხაზი — ცარიელდება, არ იშლება მთლიანად
        const deal = allocRow.querySelector('input[name^="DEAL_"]');
        const val = allocRow.querySelector('input[name^="VALUE_"]');
        deal.value = '';
        val.value = '0';
        clearDealValidationState(deal);
        allocRow.querySelectorAll('.alloc-meta').forEach(meta => meta.remove());
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
        // სალაროს საფუძვლით შერჩეული დილი პირველია და მთელი თანხით ივსება
        const suggested = Number(row.SUGGESTED_DEAL || 0);
        const deals = [...(row.MERGE_DEALS || [])].sort((a, b) => (Number(b.ID) === suggested) - (Number(a.ID) === suggested));
        // საფუძვლის ფართი კლიენტის დილებში არ არის: ერთადერთი დილიც ხელით უნდა დადასტურდეს
        const hintMiss = row.CASH && row.UNIT_HINT && !suggested;
        // ცისფერი: INN-ით ვერნაპოვნი დილი წინა გატარებიდან
        const tone = row.RECOGNIZED ? 'tone-blue' : ((deals.length > 1 && !suggested) || hintMiss ? 'tone-yellow' : 'tone-green');
        const allocParts = [];
        if (deals.length) {
            deals.forEach((d) => {
                const idx = indexDeals++;
                const prefill = Number(d.ID) === suggested || (deals.length === 1 && !hintMiss) ? Number(row.BANK_AMOUNT_USD || 0) : 0;
                // პროექტი · უძრავი ქონების ტიპი · ბლოკი ნომერი
                const info = [d.PROJECT || '-', d.TYPE, [d.BLOCK, d.UNIT].filter(Boolean).join(' ')].filter(Boolean);
                // სხვის დილზე გადახდისას ჩანს, ვისი დილია
                const owner = d.FROM_HISTORY && d.CLIENT_NAME ? `<br>დილის კლიენტი: ${esc(d.CLIENT_NAME)}` : '';
                const history = d.HISTORY_COUNT ? `<br><span class="history-note">წინა გატარებები: ${esc(d.HISTORY_COUNT)}, ბოლო: ${esc(d.HISTORY_LAST)}</span>` : '';
                const meta = `<div class="deal-meta" title="დარჩენილი დავალიანება: გრაფიკი - გადახდები (დღემდე)">#${esc(d.ID)} · ${esc(info.join(' · '))}${owner}<br>დარჩენილი: <b>${money(d.LEFT_TO_PAY)}</b>${history}</div>`;
                // დანიშნულებაში აბონირება წერია: ₾ თანხიდან რამდენიც ეტევა აბონირებაზე, დანარჩენი ბინაზე
                const subLeft = d.SUB ? Number(d.SUB.LEFT || 0) : 0;
                const subGel = prefill > 0 && row.SUB_HINT && subLeft > 0.009 && Number(row.NBG_RATE) > 0
                    ? round2(Math.min(subLeft, Number(row.BANK_AMOUNT_GEL || 0)))
                    : 0;
                const flatValue = subGel > 0 ? valueText(prefill - subGel / Number(row.NBG_RATE)) : prefill;
                allocParts.push(buildAllocRow(idx, row.list_id, d.ID, meta, flatValue, { subHtml: subscriptionAction(d.ID, d.SUB) }));
                if (subGel > 0) {
                    allocParts.push(buildAllocRow(indexDeals++, row.list_id, d.ID, '', valueText(subGel), { sub: true, left: subLeft }));
                }
            });
        } else {
            const idx = indexDeals++;
            allocParts.push(buildAllocRow(idx, row.list_id, '', '', 0));
        }

        const tr = document.createElement('tr');
        tr.className = `filtertr ${tone}`;
        tr.dataset.expectedUsd = String(row.BANK_AMOUNT_USD || 0);
        tr.dataset.nbg = String(row.NBG_RATE || 0);
        tr.dataset.date = String(row.DATE || '');
        tr.dataset.search = [
            row.CLIENT_NAME, row.NAME, row.INN, row.SENDER_ACCOUNT, row.BENEFICIARY, row.NOMINATION, row.CURRENCY, row.PROJECT, row.ACCOUNT,
            ...(deals.map(d => d.ID + ' ' + (d.NAME || '') + ' ' + (d.TYPE || '') + ' ' + (d.CLIENT_NAME || '')))
        ].join(' ').toLowerCase();
        tr.dataset.currency = (row.CURRENCY || '').toUpperCase();
        tr.dataset.project = row.PROJECT || '';
        tr.dataset.kind = 'matched';
        tr.dataset.usd = String(row.BANK_AMOUNT_USD || 0);
        tr.dataset.gel = String(row.BANK_AMOUNT_GEL || 0);
        // INN-ის გარეშე გადამხდელი გამგზავნის ანგარიშით ამოიცნობა - ის ჩანს INN-ის ადგილას
        const clientMeta = [row.STATUS, row.PAYER || row.INN].filter(Boolean);
        const badge = row.RECOGNIZED ? '<div><span class="recognized-badge">წინა გატარებით</span></div>' : '';
        tr.innerHTML = `
            <td><b>${esc(row.CLIENT_NAME || row.NAME)}</b><div class="deal-meta">${esc(clientMeta.join(' · '))}</div>${badge}${cashMeta(row, suggested > 0, 'კლიენტს ამ ფართის დილი არ აქვს. მარჯვნივ მისი სხვა დილი(ები)ა, თანხა ჩაწერეთ მხოლოდ თუ სწორია')}${skipButton(row.list_id)}</td>
            <td class="nowrap">${esc(row.DATE)}</td>
            <td>${esc(row.NOMINATION)}</td>
            <td>${esc(row.BENEFICIARY)}${accountMeta(row)}</td>
            <td class="nowrap">${currencyCell(row)}</td>
            <td class="amount">${money(row.BANK_AMOUNT_GEL)}</td>
            <td class="amount">${money(row.BANK_AMOUNT_USD)}</td>
            <td class="nowrap" title="NBG USD კურსი ამონაწერის თარიღზე (${esc(row.DATE)})">${esc(row.NBG_RATE)}</td>
            <td><div class="alloc-list">${allocParts.join('')}</div><button type="button" class="mini-btn add-deal" onclick="addDealField(this,'myForm')">+ დილი</button></td>
        `;
        tbody.appendChild(tr);
        syncSubscriptionButtons(tr);
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
        tr.dataset.nbg = String(row.NBG_RATE || 0);
        tr.dataset.date = String(row.DATE || '');
        tr.dataset.search = [row.NAME, row.INN, row.BENEFICIARY, row.NOMINATION, row.CURRENCY, row.PROJECT, row.ACCOUNT].join(' ').toLowerCase();
        tr.dataset.currency = (row.CURRENCY || '').toUpperCase();
        tr.dataset.project = row.PROJECT || '';
        tr.dataset.kind = 'errors';
        tr.dataset.usd = String(usd);
        tr.dataset.gel = String(row.AMOUNT_GEL || row.BANK_AMOUNT_GEL || 0);
        // სალარო: გადამხდელი ვერ მოიძებნა, მაგრამ საფუძვლის ფართით დილი ჩანს - ივსება მხოლოდ "ჩასმით"
        const s = row.SUGGESTED;
        const suggestion = s ? `<div class="suggest-box"><div class="deal-meta">შემოთავაზება: <b>#${esc(s.ID)}</b> · ${esc([s.CLIENT_NAME, s.PROJECT, s.TYPE, [s.BLOCK, s.UNIT].filter(Boolean).join(' ')].filter(Boolean).join(' · '))}</div>
            <button type="button" class="mini-btn" onclick="useSuggestedDeal(this, '${esc(s.ID)}')">ჩასმა</button></div>` : '';
        tr.innerHTML = `
            <td><b>${esc(row.NAME)}</b>${cashMeta(row, !!s, 'ამ ფართის დილი ვერ მოიძებნა')}${skipButton(row.PAYMENT || row.list_id)}</td>
            <td>${esc(row.INN)}</td>
            <td class="nowrap">${esc(row.DATE)}</td>
            <td>${esc(row.NOMINATION)}</td>
            <td>${esc(row.BENEFICIARY)}${accountMeta(row)}</td>
            <td class="nowrap">${currencyCell(row)}</td>
            <td class="nowrap" title="NBG USD კურსი ამონაწერის თარიღზე">${esc(row.NBG_RATE)}</td>
            <td class="amount">${money(row.AMOUNT_GEL || row.BANK_AMOUNT_GEL)}</td>
            <td class="amount">${money(usd)}</td>
            <td><div class="alloc-list">${buildAllocRow(idx, row.PAYMENT || row.list_id, '', '', usd)}</div><button type="button" class="mini-btn add-deal" onclick="addDealField(this,'myForm_er')">+ დილი</button>${suggestion}</td>
        `;
        tbody.appendChild(tr);
        tr.querySelectorAll('input[name^="DEAL_"]').forEach(bindDealInput);
        tr.querySelectorAll('input[name^="VALUE_"]').forEach(inp => {
            inp.addEventListener('input', () => checkRowTotals('myForm_er'));
        });
    });
    checkRowTotals('myForm_er');
}

/** შემოთავაზებული დილი რიგის პირველ ველში */
function useSuggestedDeal(btn, dealId) {
    const input = btn.closest('tr').querySelector('input[name^="DEAL_"]');
    input.value = dealId;
    validateDealInput(input);
    checkRowTotals('myForm_er');
}

/** ზედა ციფრები ფილტრის მიხედვით: მხოლოდ ხილული რიგები */
function refreshStats() {
    let matched = 0, errorsCount = 0, usd = 0, gel = 0;
    document.querySelectorAll('tr.filtertr:not(.hidden-row)').forEach(tr => {
        if (tr.dataset.kind === 'matched') matched++;
        else errorsCount++;
        usd += Number(tr.dataset.usd || 0);
        gel += Number(tr.dataset.gel || 0);
    });
    document.getElementById('statMatched').textContent = matched;
    document.getElementById('statErrors').textContent = errorsCount;
    document.getElementById('pillMatched').textContent = matched;
    document.getElementById('pillErrors').textContent = errorsCount;
    document.getElementById('pillSkipped').textContent = (skipped || []).length;
    document.getElementById('statUsd').textContent = money(usd);
    document.getElementById('statGel').textContent = money(gel);
}

/** პროექტების ცხრილი: ოპერაციების რაოდენობა და თანხა, პროექტის ფილტრის გარდა ყველა ფილტრით */
function renderProjectSummary(passesOtherFilters) {
    const selected = document.getElementById('projectFilter').value || '';
    const byProject = new Map();
    const total = { count: 0, matched: 0, errors: 0, usd: 0, gel: 0 };
    document.querySelectorAll('tr.filtertr').forEach(tr => {
        if (!passesOtherFilters(tr)) return;
        const key = tr.dataset.project || '';
        const bucket = byProject.get(key) || { count: 0, matched: 0, errors: 0, usd: 0, gel: 0 };
        [bucket, total].forEach(b => {
            b.count++;
            if (tr.dataset.kind === 'matched') b.matched++;
            else b.errors++;
            b.usd += Number(tr.dataset.usd || 0);
            b.gel += Number(tr.dataset.gel || 0);
        });
        byProject.set(key, bucket);
    });

    const tbody = document.getElementById('tbody_projects');
    tbody.innerHTML = '';
    if (!byProject.size) {
        tbody.innerHTML = '<tr><td colspan="6" style="padding:14px;color:var(--muted);">დასამუშავებელი ოპერაცია არ არის</td></tr>';
        return;
    }
    [...byProject.entries()].sort((a, b) => a[0].localeCompare(b[0], 'ka')).forEach(([project, b]) => {
        const tr = document.createElement('tr');
        tr.className = project === selected && selected !== '' ? 'active' : '';
        tr.innerHTML = `<td><b>${esc(project || 'პროექტის გარეშე')}</b></td><td>${b.count}</td><td>${b.matched}</td><td>${b.errors}</td>
            <td class="amount">${money(b.usd)}</td><td class="amount">${money(b.gel)}</td>`;
        tr.addEventListener('click', () => {
            const select = document.getElementById('projectFilter');
            select.value = select.value === project ? '' : project;
            applyFilters();
        });
        tbody.appendChild(tr);
    });
    const tr = document.createElement('tr');
    tr.className = 'total-row';
    tr.innerHTML = `<td>სულ</td><td>${total.count}</td><td>${total.matched}</td><td>${total.errors}</td>
        <td class="amount">${money(total.usd)}</td><td class="amount">${money(total.gel)}</td>`;
    tbody.appendChild(tr);
}

function skipButton(statementId) {
    return `<div><button type="button" class="mini-btn skip-btn" onclick="skipStatement('${esc(statementId)}')" title="ოპერაცია გადავა გამოტოვებულებში; იქიდან შეიძლება დაბრუნება">არ საჭიროებს დამუშავებას</button></div>`;
}

function submitSkipForm(action, statementId, comment) {
    const form = document.getElementById('skipForm');
    form.elements.skip_action.value = action;
    form.elements.statement_id.value = statementId;
    form.elements.comment.value = comment || '';
    form.submit();
}

function skipStatement(statementId) {
    const comment = prompt('ოპერაცია არ საჭიროებს დამუშავებას და გადავა გამოტოვებულებში.\nმიუთითე მიზეზი (არასავალდებულო):', '');
    if (comment === null) return;
    submitSkipForm('skip', statementId, comment);
}

function restoreStatement(statementId) {
    if (!confirm('ოპერაცია დაბრუნდეს დასამუშავებლებში?')) return;
    submitSkipForm('restore', statementId, '');
}

function renderSkipped() {
    const tbody = document.getElementById('tbody_skipped');
    const reasonSelect = document.getElementById('skippedReasonFilter');
    const reasons = new Map();
    (skipped || []).forEach(r => {
        const code = r.REASON_CODE || 'other';
        const label = code === 'manual' ? 'ხელით გამოტოვებული (არ საჭიროებს დამუშავებას)' : (r.REASON || code);
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
            <td class="nowrap">${esc(row.DATE)}</td>
            <td>${esc(row.NOMINATION)}</td>
            <td>${esc(row.BENEFICIARY)}${accountMeta(row)}</td>
            <td class="nowrap">${currencyCell(row)}</td>
            <td class="amount">${money(row.AMOUNT_GEL)}</td>
            <td class="amount">${money(row.AMOUNT_USD)}</td>
            <td><span class="reason-badge">${esc(row.REASON)}</span>${row.MANUAL ? `<button type="button" class="mini-btn restore-btn" onclick="restoreStatement('${esc(row.list_id)}')">დაბრუნება</button>` : ''}</td>
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

    // ძებნა, ვალუტა და თარიღი; პროექტი ცალკე - პროექტების ცხრილი ყველა პროექტს აჩვენებს
    const passesOtherFilters = tr => {
        const hay = tr.dataset.search || '';
        const rowCur = tr.dataset.currency || '';
        const rowDate = tr.dataset.date || '';
        const okQ = !q || hay.includes(q);
        const okC = !cur || rowCur === cur;
        let okD = true;
        if (from && rowDate && rowDate < from) okD = false;
        if (to && rowDate && rowDate > to) okD = false;
        return okQ && okC && okD;
    };
    document.querySelectorAll('tr.filtertr').forEach(tr => {
        const okP = !project || tr.dataset.project === project;
        tr.classList.toggle('hidden-row', !(passesOtherFilters(tr) && okP));
    });
    checkRowTotals('myForm');
    checkRowTotals('myForm_er');
    refreshStats();
    renderProjectSummary(passesOtherFilters);
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

// ინახება მხოლოდ ფილტრით ხილული რიგები - დამალულის ჯამი არ მოწმდება და ისინი არ იგზავნება
['myForm', 'myForm_er'].forEach(formId => {
    document.getElementById(formId).addEventListener('submit', function () {
        this.querySelectorAll('tr.filtertr.hidden-row input').forEach(inp => { inp.disabled = true; });
    });
});

renderMatched();
renderErrors();
renderSkipped();
fillProjectFilter();
applyFilters();
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
