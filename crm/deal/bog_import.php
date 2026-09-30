<?php
/**
 * საქართველოს ბანკი — ამონაწერების იმპორტი (bog_import.php)
 */
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/crm/deal/bank_integration/helpers.php';

$APPLICATION->SetTitle('BOG — ამონაწერის გენერაცია');
bankBogEnsureModules();

$flash = null;
$stats = null;
$errorMsg = null;
$accounts = bankBogAccounts();
$selectedAccount = $_POST['ACCOUNT'] ?? ($accounts[0]['number'] ?? BANK_BOG_ACCOUNT);
$statementIblockId = bankBogStatementIblockId();

if ($statementIblockId <= 0) {
    $flash = ['type' => 'error', 'text' => 'ამონაწერების სია ჯერ არ არსებობს — გაუშვი setup.php'];
} elseif (!empty($_POST['from_date']) && !empty($_POST['to_date']) && !empty($_POST['CURRENCY']) && !empty($_POST['ACCOUNT'])) {
    $from = preg_replace('/[^0-9\-]/', '', $_POST['from_date']);
    $to = preg_replace('/[^0-9\-]/', '', $_POST['to_date']);
    $currency = strtoupper(trim((string)$_POST['CURRENCY']));
    if (!in_array($currency, ['GEL', 'USD', 'EUR'], true)) {
        $currency = 'GEL';
    }
    $selectedAccount = trim((string)$_POST['ACCOUNT']);

    $stats = bankBogImportStatements($from, $to, $currency, $errorMsg, $selectedAccount);
    if ($errorMsg && empty($stats['created']) && empty($stats['fetched'])) {
        $flash = ['type' => 'error', 'text' => $errorMsg];
    } elseif ($errorMsg) {
        $flash = ['type' => 'warn', 'text' => $errorMsg];
    } else {
        $flash = [
            'type' => 'ok',
            'text' => sprintf(
                'ახალი %d · დუბლიკატი %d · გამოტოვებული %d · API ჩანაწერი %d',
                (int)$stats['created'],
                (int)$stats['skipped_dup'],
                (int)$stats['skipped_filter'],
                (int)$stats['fetched']
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
            width: 52px; height: 52px; border-radius: 4px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.25);
            display: grid; place-items: center;
            font-weight: 700; font-size: 14px; letter-spacing: 0.04em;
            position: relative; z-index: 1;
        }
        .hero h1 {
            margin: 0; font-size: 22px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.02em;
        }
        .hero p { margin: 4px 0 0; color: rgba(255, 255, 255, 0.82); font-size: 13px; }
        .hero div { position: relative; z-index: 1; }
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
        select, input[type="date"] {
            width: 100%; height: 46px; border-radius: 4px;
            border: 1px solid var(--line); background: #f0f2f5;
            padding: 0 14px; font: inherit; color: var(--ink); outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        select:focus, input[type="date"]:focus {
            border-color: var(--accent); background: #fff;
            box-shadow: 0 0 0 4px rgba(114, 196, 177, 0.2);
        }
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
        @media (max-width: 640px) {
            .row-2 { grid-template-columns: 1fr; }
            .shell { margin-top: 20px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="hero">
        <div class="hero-mark">BOG</div>
        <div>
            <h1>ამონაწერის გენერაცია</h1>
            <p>საქართველოს ბანკი · <?= htmlspecialchars(BANK_BOG_COMPANY_LABEL) ?></p>
        </div>
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
        <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" id="loadForm">
            <div class="field">
                <label for="ACCOUNT">ანგარიში</label>
                <select id="ACCOUNT" name="ACCOUNT" required>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= htmlspecialchars($acc['number']) ?>" <?= ($selectedAccount === $acc['number']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars(($acc['label'] ?? '') . ' — ' . $acc['number']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="CURRENCY">ვალუტა</label>
                <select id="CURRENCY" name="CURRENCY" required>
                    <option value="GEL" <?= (($_POST['CURRENCY'] ?? 'GEL') === 'GEL') ? 'selected' : '' ?>>GEL</option>
                    <option value="USD" <?= (($_POST['CURRENCY'] ?? '') === 'USD') ? 'selected' : '' ?>>USD</option>
                    <option value="EUR" <?= (($_POST['CURRENCY'] ?? '') === 'EUR') ? 'selected' : '' ?>>EUR</option>
                </select>
            </div>
            <div class="row-2">
                <div class="field">
                    <label for="from_date">დან</label>
                    <input required type="date" id="from_date" name="from_date" value="<?= htmlspecialchars($_POST['from_date'] ?? date('Y-m-d', strtotime('-7 days'))) ?>">
                </div>
                <div class="field">
                    <label for="to_date">მდე</label>
                    <input required type="date" id="to_date" name="to_date" value="<?= htmlspecialchars($_POST['to_date'] ?? date('Y-m-d')) ?>">
                </div>
            </div>
            <div class="actions">
                <button class="btn btn-primary" type="submit" id="submitBtn">ჩატვირთვა</button>
                <a class="btn btn-ghost" href="/crm/deal/bog_merge.php">გადახდებთან მიბმა →</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
<script>
var loadForm = document.getElementById('loadForm');
if (loadForm) {
    loadForm.addEventListener('submit', function () {
        var btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.textContent = 'იტვირთება…';
    });
}
</script>
</body>
</html>
<?php
exit;
