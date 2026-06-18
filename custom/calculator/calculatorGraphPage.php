<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/rest/local/api/calculator/helpers.php');

CModule::IncludeModule('crm');

global $APPLICATION;

$docId = intval($_GET['docID'] ?? $_GET['docId'] ?? 0);
if (!$docId) {
    exit('არასწორი პარამეტრები — საჭიროა docID');
}

$element = calcGetElementByID($docId);
if (!$element || intval($element['IBLOCK_ID']) !== 21) {
    exit('განვადების დასტური ვერ მოიძებნა');
}

$json = calcParseScheduleJson($element['JSON'] ?? '');

$dealId = intval($json['dealId'] ?? 0);
$dealData = $dealId ? calcGetDealInfoByID($dealId) : null;

if (!empty($json['data']) && is_array($json['data'])) {
    $scheduleHtml = calcBuildScheduleHtml($json['data'], floatval($json['PRICE'] ?? 0));
} else {
    $scheduleHtml = calcGetIblockPropText($element['document'] ?? '');
}

$planType = calcGetIblockPropText($element['planType'] ?? ($json['planType'] ?? ''));
$graphName = calcGetIblockPropText($element['SELECTID_GRAPH'] ?? ($json['graph'] ?? ''));
$approvalStatus = calcGetIblockPropText($element['DASTURI'] ?? '');
$advancePayment = calcGetIblockPropText($element['advancePayment'] ?? ($json['advancePayment'] ?? ''));
$lastPayment = calcGetIblockPropText($element['lastPayment'] ?? ($json['lastPayment'] ?? ''));
$discountAmount = calcGetIblockPropText($element['DISCOUNT_AMOUNT'] ?? ($json['discountAmount'] ?? ''));
$lastAmount = calcGetIblockPropText($element['LAST_AMOUNT'] ?? ($json['lastAmount'] ?? ''));
$price = floatval($json['PRICE'] ?? 0);

$APPLICATION->SetTitle('განვადების გრაფიკი #' . $docId);
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <style>
        body {
            font-family: 'Inter', 'Noto Sans Georgian', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            margin: 0;
            padding: 16px;
            font-size: 13px;
        }
        body.in-sidepanel { padding: 12px; }
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06);
        }
        h1 { font-size: 20px; margin: 0 0 8px; }
        .meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-top: 16px; }
        .meta-item label { display: block; font-size: 11px; color: #64748b; text-transform: uppercase; margin-bottom: 4px; }
        .meta-item span { font-weight: 600; }
        .deal-link { color: #4f46e5; font-weight: 700; text-decoration: none; }
        .deal-link:hover { text-decoration: underline; }
        .status { display: inline-block; padding: 4px 10px; border-radius: 20px; background: #eef2ff; color: #3730a3; font-size: 12px; font-weight: 600; }
        .schedule-wrap table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .schedule-wrap th, .schedule-wrap td { border: 1px solid #e2e8f0; padding: 8px 10px; text-align: center; }
        .schedule-wrap th { background: #3730a3; color: #fff; }
        .schedule-wrap tbody tr:hover { background: #f8fafc; }
        .empty { color: #dc2626; font-weight: 600; }
    </style>
</head>
<body>

<div class="card">
    <h1>განვადების გრაფიკი</h1>
    <!-- <p style="margin:0;color:#64748b;"><?= htmlspecialchars($element['NAME'] ?? '') ?></p> -->

    <div class="meta">
        <?php if ($dealId): ?>
        <div class="meta-item">
            <label>დილი</label>
            <span><a class="deal-link" href="/crm/deal/details/<?= $dealId ?>/" target="_blank">#<?= $dealId ?></a></span>
        </div>
        <?php endif; ?>
        <div class="meta-item">
            <label>განვადების ტიპი</label>
            <span><?= htmlspecialchars($planType ?: $graphName) ?></span>
        </div>
        <div class="meta-item">
            <label>დასტური</label>
            <span class="status"><?= htmlspecialchars($approvalStatus ?: '—') ?></span>
        </div>
        <div class="meta-item">
            <label>საბოლოო ფასი ($)</label>
            <span><?= $price > 0 ? number_format($price, 2, '.', ',') : htmlspecialchars($lastAmount) ?></span>
        </div>
        <div class="meta-item" style="display:none;">
            <label>ფასდაკლება</label>
            <span><?= htmlspecialchars($discountAmount ?: '—') ?></span>
        </div>
        <div class="meta-item">
            <label>პირველადი შენატანი</label>
            <span><?= htmlspecialchars($advancePayment ?: '—') ?></span>
        </div>
        <div class="meta-item">
            <label>ბოლო შენატანი</label>
            <span><?= htmlspecialchars($lastPayment ?: '—') ?></span>
        </div>
    </div>
</div>

<div class="card schedule-wrap">
    <h2 style="font-size:14px;margin:0 0 12px;text-transform:uppercase;color:#64748b;">გადახდის გრაფიკი</h2>
    <?php if ($scheduleHtml): ?>
        <?= $scheduleHtml ?>
    <?php else: ?>
        <p class="empty">გრაფიკის მონაცემები ვერ მოიძებნა</p>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('header.app__header, header.page__header').forEach(h => h.style.display = 'none');
    if (window !== window.top || (typeof BX !== 'undefined' && BX.SidePanel && BX.SidePanel.Instance.getTopSlider())) {
        document.body.classList.add('in-sidepanel');
    }
});
</script>
</body>
</html>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
