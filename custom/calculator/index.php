<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/rest/local/api/calculator/helpers.php');

CModule::IncludeModule('crm');
CJSCore::Init(['jquery', 'date']);

global $USER;

$APPLICATION->SetTitle('განვადების კალკულატორი');

$dealID = null;
$prod_ID = $_GET['ProductID'] ?? null;

$dealIdParam = $_GET['dealid'] ?? $_GET['dealId'] ?? $_GET['DEAL_ID'] ?? null;
if (!empty($dealIdParam) && $dealIdParam !== 'UNDEFINED') {
    $dealID = intval($dealIdParam);
}

if (!$dealID) {
    exit('არასწორი პარამეტრები — საჭიროა dealid');
}

$dealData = calcGetDealInfoByID($dealID);
if (!$dealData) {
    exit('დილი ვერ მოიძებნა');
}

$dealProds = CCrmDeal::LoadProductRows($dealID);
$productInfo = [];

if ($prod_ID) {
    $productInfo = calcGetProductDataByID($prod_ID);
} elseif (!empty($dealProds[0]['PRODUCT_ID'])) {
    $prod_ID = $dealProds[0]['PRODUCT_ID'];
    $productInfo = calcGetProductDataByID($prod_ID);
}

if (empty($productInfo[0])) {
    exit('დილზე პროდუქტი არ არის მიბმული');
}

$prod = $productInfo[0];
$totalKVM = $prod['TOTAL_AREA'] > 0 ? $prod['TOTAL_AREA'] : 1;
$oldPrice = floatval($dealData['UF_CRM_1761658642424'] ?: $prod['PRICE']);
$startSqmPrice = floatval($dealData['UF_CRM_1761658662573'] ?: $prod['KVM_PRICE']);
$projectName = $dealData['UF_CRM_1779277729207'] ?: $prod['PROJECT'];
if (!$projectName && !empty($prod['IBLOCK_SECTION_ID'])) {
    $sectionRes = CIBlockSection::GetByID($prod['IBLOCK_SECTION_ID']);
    if ($section = $sectionRes->GetNext()) {
        $projectName = $section['NAME'];
    }
}
$binisNomeri = $dealData['UF_CRM_1779277613798'] ?: $prod['Number'];

$dateForNBG = date('Y-m-d');
$nbgKursi = calcGetNbgRate($dateForNBG);

// ── განვადების პირობები (ლისტი 20) — პროექტის მიხედვით PHP-ში ფილტრაცია ──
$conditionElements = calcGetInstallmentConditions($projectName, 20);

$instalmentPlanArr = [];
$scheduleTypeArr = [];

$scheduleTypeArr['customType'] = [
    'price' => $oldPrice,
    'kvmPrice' => $startSqmPrice,
    'discountAmount' => 0,
    'discountPerSqm' => 0,
    'oldPrice' => $oldPrice,
    'TOTAL_AREA' => $totalKVM,
    'startSqmPrice' => $startSqmPrice,
];

$scheduleTypeArr['allCash'] = [
    'price' => round($oldPrice - (70 * $totalKVM), 2),
    'kvmPrice' => round($startSqmPrice - 70, 2),
    'discountAmount' => round(70 * $totalKVM, 2),
    'discountPerSqm' => 70,
    'oldPrice' => $oldPrice,
    'TOTAL_AREA' => $totalKVM,
    'startSqmPrice' => $startSqmPrice,
    'advancePaymentPct' => 100,
    'lastPaymentPct' => 0,
];

$instalmentPlanArr['allCash'] = 'ერთიანი გადახდა';

foreach ($conditionElements as $element) {
    $discountPerSqm = calcGetNumericProp($element, ['DISCOUNT', 'DISCOUNT_PER_SQM', 'FASDAKLEBA']);
    $advancePct = calcGetConditionPercent(
        $element,
        ['ADVANCE_PAYNMENT', 'ADVANCE_PAYMENT', 'PIRVELADI_SHENETANI', 'FIRST_PAYMENT', 'ADVANCE_PERCENT'],
        'calcParseAdvancePctFromName'
    );
    $lastPct = calcGetConditionPercent(
        $element,
        ['LAST_PAYMENT', 'Bolo_SHENETANI', 'LAST_PAYMENT_PERCENT', 'BOLO_SHENETANI'],
        'calcParseLastPctFromName'
    );
    $discountAmount = round($discountPerSqm * $totalKVM, 2);
    $price = round($oldPrice - $discountAmount, 2);
    $kvmPrice = $totalKVM > 0 ? round($price / $totalKVM, 2) : 0;
    $months = calcParseMonthsFromName($element['NAME']);
    $endDateFixed = calcFormatBitrixDate($element['END_DATE'] ?? '');

    $instalmentPlanArr[$element['ID']] = $element['NAME'];

    $scheduleTypeArr[$element['ID']] = [
        'name' => $element['NAME'],
        'price' => $price,
        'kvmPrice' => $kvmPrice,
        'discountAmount' => $discountAmount,
        'discountPerSqm' => $discountPerSqm,
        'oldPrice' => $oldPrice,
        'TOTAL_AREA' => $totalKVM,
        'startSqmPrice' => $startSqmPrice,
        'advancePaymentPct' => $advancePct,
        'lastPaymentPct' => $lastPct,
        'months' => $months,
        'endDateFixed' => $endDateFixed,
    ];
}
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-dark: #3730a3;
            --accent: #06b6d4;
            --bg: #f1f5f9;
            --card: #ffffff;
            --border: #e2e8f0;
            --text: #0f172a;
            --muted: #64748b;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Noto Sans Georgian', sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 16px;
            font-size: 13px;
        }
        body.in-sidepanel { padding: 12px; }
        body.in-sidepanel .calc-header { padding: 18px 20px; margin-bottom: 16px; }
        body.in-sidepanel .calc-header h1 { font-size: 18px; }
        .calc-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 60%, #1e1b4b 100%);
            color: #fff;
            border-radius: 16px;
            padding: 28px 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(79, 70, 229, 0.25);
        }
        .calc-header h1 {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 6px;
        }
        .calc-header p { margin: 0; opacity: 0.8; font-size: 13px; }
        .card-panel {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.06);
        }
        .card-panel h3 {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin: 0 0 16px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
        }
        @media (max-width: 1100px) { .form-grid { grid-template-columns: repeat(4, 1fr); } }
        @media (max-width: 600px)  { .form-grid { grid-template-columns: 1fr; } }
        .field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .field input, .field select, .field textarea {
            width: 100%;
            height: 38px;
            padding: 0 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            transition: border-color 0.15s;
        }
        .field input:focus, .field select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12);
        }
        .field input:disabled, .field select:disabled {
            background: #f8fafc;
            color: var(--text);
            font-weight: 600;
            cursor: not-allowed;
        }
        .field input.date-field {
            cursor: pointer;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM2 2a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1H2z'/%3E%3Cpath d='M2.5 4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5H3a.5.5 0 0 1-.5-.5V4z'/%3E%3C/svg%3E") no-repeat right 10px center;
            padding-right: 34px;
        }
        .field input.date-field:disabled {
            cursor: not-allowed;
            background-color: #f8fafc;
        }
        .flatpickr-calendar { z-index: 99999 !important; }
        .field textarea { height: auto; padding: 10px 12px; }
        .field.frozen input {
            background: #f0f4ff;
            border-color: #c7d2fe;
            color: var(--primary-dark);
        }
        .btn-calc {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 11px 28px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .btn-calc:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(79,70,229,0.35); }
        .btn-save {
            background: linear-gradient(135deg, #059669, #047857);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 11px 28px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-save:hover { box-shadow: 0 6px 20px rgba(5,150,105,0.35); }
        .table-graph {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border);
            margin-top: 8px;
        }
        .table-graph th {
            background: var(--primary-dark);
            color: #fff;
            padding: 10px 14px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }
        .table-graph td {
            padding: 8px 12px;
            text-align: center;
            border-top: 1px solid var(--border);
            background: #fff;
        }
        .table-graph tbody tr:hover td { background: #f8fafc; }
        .table-graph input {
            border: none;
            width: 100%;
            text-align: center;
            background: transparent;
            font-size: 13px;
        }
        .weekend-red { color: #dc2626 !important; }
        .error-msg { color: #dc2626; font-weight: 600; margin: 8px 0; }
        .confirm-msg { color: #d97706; font-weight: 600; }
        .hidden { display: none !important; }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
        .deal-link { color: var(--primary); font-weight: 700; text-decoration: none; }
        .deal-link:hover { text-decoration: underline; }
        .mode-badge {
            display: inline-block;
            background: rgba(255,255,255,0.15);
            border-radius: 20px;
            padding: 3px 12px;
            font-size: 11px;
            margin-top: 8px;
        }
    </style>
</head>
<body>

<div class="calc-header">
    <h1>განვადების კალკულატორი</h1>
    <!-- <p>Monolith 24 &mdash; გადახდის გრაფიკის დაგეგმვა და დასტურისთვის გაგზავნა</p> -->
    <span class="mode-badge" id="modeBadge">არასტანდარტული</span>
</div>

<div id="errors"></div>
<p id="confirmTXT" class="confirm-msg hidden">* განვადება საჭიროებს დასტურს</p>

<!-- საინფორმაციო ველები -->
<div class="card-panel">
    <!-- <h3>საინფორმაციო ველები</h3> -->
    <div class="form-grid">
        <div class="field frozen">
            <label>დილი</label>
            <div style="height:38px;display:flex;align-items:center;padding:0 12px;background:#f0f4ff;border:1.5px solid #c7d2fe;border-radius:8px;">
                <a class="deal-link" href="/crm/deal/details/<?= $dealID ?>/" target="_blank">#<?= $dealID ?></a>
            </div>
        </div>
        <div class="field frozen">
            <label>უძრავი ქონების № / მ²</label>
            <input value="<?= htmlspecialchars($binisNomeri) ?> / <?= $totalKVM ?> მ²" disabled>
        </div>
        <div class="field frozen">
            <label>საწყისი კვ.მ ღირებულება ($)</label>
            <input id="startSqmPrice" value="<?= number_format($startSqmPrice, 2, '.', ',') ?>" disabled>
        </div>
        <div class="field frozen">
            <label>საწყისი ჯამური ღირებულება ($)</label>
            <input id="startPrice" value="<?= number_format($oldPrice, 2, '.', ',') ?>" disabled>
        </div>

    </div>
</div>

<!-- გადახდის ტიპი -->
<div class="card-panel">
    <!-- <h3>გადახდის ტიპი</h3> -->
    <div class="form-grid">
        <div class="field">
            <label>ტიპი</label>
            <select id="paymentMode" class="green-border" onchange="onPaymentModeChange()">
                <option value="customType">არასტანდარტული</option>
                <option value="allCash">ერთიანი გადახდა</option>
                <option value="internal">შიდა განვადება</option>
            </select>
        </div>
        <div class="field hidden" id="scheduleTypeField">
            <label>გრაფიკის ტიპი</label>
            <select id="type_select" onchange="onScheduleTypeChange()">
                <option value="">აირჩიეთ პირობა</option>
            </select>
        </div>
        <div class="field">
            <label>გადახდის პერიოდულობა</label>
            <select id="period">
                <option value="1" selected>თვეში ერთხელ</option>
                <option value="3">3 თვეში ერთხელ</option>
                <option value="6">6 თვეში ერთხელ</option>
                <option value="12">წელიწადში ერთხელ</option>
            </select>
        </div>
        <div class="field hidden" id="nbgField">
            <label>NBG კურსი</label>
            <input id="nbgKursi" value="<?= $nbgKursi ?>" disabled>
        </div>
    </div>
</div>

<!-- ფასები -->
<div class="card-panel">
    <!-- <h3>ფასები</h3> -->
    <div class="form-grid">
        <div class="field frozen" style="display:none;">
            <label>სულ ($)</label>
            <input id="totalPrice" value="<?= number_format($oldPrice, 2, '.', ',') ?>" disabled>
        </div>

        <div class="field">
            <label>ფასდაკლება კვ.მ ($)</label>
            <input id="discountPerSqm" value="0" oninput="calculateDiscountPerSqm()" onblur="formatDiscountField('discountPerSqm')">
        </div>
        <div class="field">
            <label>ფასდაკლება სრული ($)</label>
            <input id="discountNum" value="0" oninput="calculateDiscount()" onblur="formatDiscountField('discountNum')">
        </div>
        <div class="field">
            <label>საბოლოო კვ.მ ფასი ($)</label>
            <input id="kvmPrice" disabled>
        </div>

        <div class="field">
            <label>საბოლოო ფასი ($)</label>
            <input id="price" disabled>
        </div>
        <div class="field" style="display:none;">
            <label>საბოლოო ფასი (₾)</label>
            <input id="priceGel" disabled>
        </div>
    </div>
</div>

<!-- გადახდები -->
<div class="card-panel">
    <!-- <h3>გადახდის პარამეტრები</h3> -->
    <div class="form-grid">
        <div class="field">
            <label>პირველადი შენატანის თარიღი</label>
            <input id="advancePayDate" type="text" class="date-field" placeholder="dd/mm/YYYY" autocomplete="off" readonly>
        </div>
        <div class="field">
            <label>პირველადი შენატანი ($)</label>
            <input id="advancePayment" oninput="onAdvanceChange('amount')">
        </div>
        <div class="field">
            <label>პირველადი შენატანი (%)</label>
            <input id="advancePaymentPercent" oninput="onAdvanceChange('percent')">
        </div>
        <div class="field" id="fieldStartDate">
            <label>დაწყების თარიღი</label>
            <input id="startDate" type="text" class="date-field" placeholder="dd/mm/YYYY" autocomplete="off" readonly>
        </div>

        <div class="field" id="fieldLastPayDate">
            <label>ბოლო შენატანის თარიღი</label>
            <input id="lastPayDate" type="text" class="date-field" placeholder="dd/mm/YYYY" autocomplete="off" readonly>
        </div>
        <div class="field" id="fieldLastPayment">
            <label>ბოლო შენატანი ($)</label>
            <input id="lastPayment" oninput="onLastChange('amount')">
        </div>
        <div class="field" id="fieldLastPaymentPercent">
            <label>ბოლო შენატანი (%)</label>
            <input id="lastPaymentPercent" oninput="onLastChange('percent')">
        </div>
        <div class="field" id="fieldEndDate">
            <label>დასრულების თარიღი</label>
            <input id="endDate" type="text" class="date-field" placeholder="dd/mm/YYYY" autocomplete="off" readonly>
        </div>
    </div>
    <div class="form-grid" style="margin-top:14px; display:none;">
        <div class="field" style="grid-column: span 4; display:none;">
            <label>კომენტარი</label>
            <textarea id="commentInput" rows="2"></textarea>
        </div>
    </div>
</div>

<div class="actions">
    <button class="btn-calc" onclick="getAndFillGraph()">გამოთვლა</button>
    <button class="btn-save hidden" id="saveBTN" onclick="saveGraph()">შენახვა</button>
</div>

<table id="graphData" class="table-graph"></table>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const DATE_FIELD_IDS = ['advancePayDate', 'startDate', 'endDate', 'lastPayDate'];
const ALL_CASH_HIDDEN_FIELDS = ['fieldStartDate', 'fieldEndDate', 'fieldLastPayDate', 'fieldLastPayment', 'fieldLastPaymentPercent'];
const DATE_PICKER_OPTS = {
    dateFormat: 'd/m/Y',
    allowInput: false,
    disableMobile: true,
    locale: { firstDayOfWeek: 1 },
};
const CONFIG = {
    nbgKursi: <?= json_encode($nbgKursi) ?>,
    instalmentPlanArr: <?= json_encode($instalmentPlanArr, JSON_UNESCAPED_UNICODE) ?>,
    scheduleTypeArr: <?= json_encode($scheduleTypeArr, JSON_UNESCAPED_UNICODE) ?>,
    dealID: <?= json_encode($dealID) ?>,
    prodID: <?= json_encode($prod_ID) ?>,
    oldPrice: <?= json_encode($oldPrice) ?>,
    startSqmPrice: <?= json_encode($startSqmPrice) ?>,
    totalKVM: <?= json_encode($totalKVM) ?>,
    userID: <?= json_encode($USER->GetID()) ?>,
    projectName: <?= json_encode($projectName, JSON_UNESCAPED_UNICODE) ?>,
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('header.app__header, header.page__header').forEach(h => h.style.display = 'none');
    if (window !== window.top || (typeof BX !== 'undefined' && BX.SidePanel && BX.SidePanel.Instance.getTopSlider())) {
        document.body.classList.add('in-sidepanel');
    }
    initDatePickers();
    onPaymentModeChange();
    setDateValue('startDate', dateAddMonth(today(), 1));
    setDateValue('advancePayDate', today());
});

function onPaymentModeChange() {
    const mode = getValue('paymentMode');
    const badge = document.getElementById('modeBadge');
    const scheduleField = document.getElementById('scheduleTypeField');
    const confirmTxt = document.getElementById('confirmTXT');

    const labels = { customType: 'არასტანდარტული', allCash: 'ერთიანი', internal: 'შიდა განვადება' };
    badge.textContent = labels[mode] || mode;

    if (mode === 'internal') {
        scheduleField.classList.remove('hidden');
        ALL_CASH_HIDDEN_FIELDS.forEach(show);
        fillScheduleOptions();
        confirmTxt.classList.add('hidden');
    } else if (mode === 'allCash') {
        scheduleField.classList.add('hidden');
        ALL_CASH_HIDDEN_FIELDS.forEach(hide);
        fillAllCashData();
        confirmTxt.classList.add('hidden');
    } else {
        scheduleField.classList.add('hidden');
        ALL_CASH_HIDDEN_FIELDS.forEach(show);
        fillCustomTypeData();
        confirmTxt.classList.remove('hidden');
    }
    updateSaveButtonText();
    clearGraph();
}

function fillScheduleOptions() {
    const sel = document.getElementById('type_select');
    sel.innerHTML = '<option value="">აირჩიეთ პირობა</option>';
    let count = 0;
    for (const [id, name] of Object.entries(CONFIG.instalmentPlanArr)) {
        if (id === 'allCash') continue;
        sel.innerHTML += `<option value="${id}">${name}</option>`;
        count++;
    }
    if (count === 0) {
        showError('ამ პროექტისთვის (' + CONFIG.projectName + ') განვადების პირობები ვერ მოიძებნა ლისტ 20-ში');
    } else {
        showError('');
    }
}

function onScheduleTypeChange() {
    const id = getValue('type_select');
    if (!id || !CONFIG.scheduleTypeArr[id]) return;
    fillScheduleData(id);
    getAndFillGraph();
}

function fillAllCashData() {
    const d = CONFIG.scheduleTypeArr.allCash;
    setValue('discountPerSqm', formatNumber(d.discountPerSqm));
    setValue('discountNum', formatNumber(d.discountAmount));
    setValue('price', formatNumber(d.price));
    setValue('kvmPrice', formatNumber(d.kvmPrice));
    setValue('priceGel', formatNumber(d.price * CONFIG.nbgKursi));
    setValue('advancePayment', formatNumber(d.price));
    setValue('advancePaymentPercent', '100');
    setValue('advancePayDate', today());
    setValue('lastPayment', '0');
    setValue('lastPaymentPercent', '0');
    setValue('endDate', today());
    setValue('lastPayDate', today());
    disableFields(['discountPerSqm','discountNum','advancePayment','advancePaymentPercent','endDate','lastPayment','lastPaymentPercent']);
}

function fillCustomTypeData() {
    const d = CONFIG.scheduleTypeArr.customType;
    setValue('discountPerSqm', '0');
    setValue('discountNum', '0');
    setValue('price', formatNumber(d.price));
    setValue('kvmPrice', formatNumber(d.kvmPrice));
    setValue('priceGel', formatNumber(d.price * CONFIG.nbgKursi));
    enableFields(['discountPerSqm','discountNum','advancePayment','advancePaymentPercent','endDate','lastPayment','lastPaymentPercent','startDate','advancePayDate','lastPayDate']);
}

function fillScheduleData(id) {
    const d = CONFIG.scheduleTypeArr[id];
    setValue('discountPerSqm', formatNumber(d.discountPerSqm || 0));
    setValue('discountNum', formatNumber(d.discountAmount));
    setValue('price', formatNumber(d.price));
    setValue('kvmPrice', formatNumber(d.kvmPrice));
    setValue('priceGel', formatNumber(d.price * CONFIG.nbgKursi));

    const calcDate = today();
    let endDate = d.endDateFixed;
    if (d.months) {
        endDate = dateAddMonth(calcDate, d.months);
    }
    setValue('endDate', endDate);
    setValue('startDate', dateAddMonth(calcDate, 1));
    setValue('advancePayDate', calcDate);

    const price = d.price;
    const advancePct = parseFloat(d.advancePaymentPct) || 0;
    if (advancePct > 0) {
        const adv = (price / 100 * advancePct).toFixed(2);
        setValue('advancePayment', formatNumber(adv));
        setValue('advancePaymentPercent', formatNumber(advancePct));
    } else {
        setValue('advancePayment', '0');
        setValue('advancePaymentPercent', '0');
    }

    const lastPct = parseFloat(d.lastPaymentPct) || 0;
    if (lastPct > 0) {
        const last = (price / 100 * lastPct).toFixed(2);
        setValue('lastPayment', formatNumber(last));
        setValue('lastPaymentPercent', formatNumber(lastPct));
        setValue('lastPayDate', endDate);
        setValue('endDate', dateAddMonth(endDate, -1));
    } else {
        setValue('lastPayment', '0');
        setValue('lastPaymentPercent', '0');
        setValue('lastPayDate', '');
    }

    disableFields(['discountPerSqm','discountNum','advancePayment','advancePaymentPercent','endDate','lastPayment','lastPaymentPercent']);
}

function applyPriceFromDiscount(discount, skipField) {
    const startPrice = CONFIG.oldPrice;
    const price = Math.max(0, startPrice - discount);
    const kvm = CONFIG.totalKVM > 0 ? price / CONFIG.totalKVM : 0;
    const perSqm = CONFIG.totalKVM > 0 ? discount / CONFIG.totalKVM : 0;
    setValue('price', formatNumber(price));
    setValue('kvmPrice', formatNumber(kvm));
    setValue('priceGel', formatNumber(price * CONFIG.nbgKursi));
    if (skipField !== 'discountPerSqm') {
        setValue('discountPerSqm', formatNumber(perSqm));
    }
    if (skipField !== 'discountNum') {
        setValue('discountNum', formatNumber(discount));
    }
}

function calculateDiscount() {
    const mode = getValue('paymentMode');
    if (mode !== 'customType') return;
    applyPriceFromDiscount(parseFormattedNumber(getValue('discountNum')), 'discountNum');
}

function calculateDiscountPerSqm() {
    const mode = getValue('paymentMode');
    if (mode !== 'customType') return;
    const perSqm = parseFormattedNumber(getValue('discountPerSqm'));
    const discount = perSqm * CONFIG.totalKVM;
    applyPriceFromDiscount(discount, 'discountPerSqm');
}

function formatDiscountField(id) {
    setValue(id, formatNumber(parseFormattedNumber(getValue(id))));
}

function onAdvanceChange(type) {
    const price = parseFormattedNumber(getValue('price'));
    if (!price) return;
    if (type === 'amount') {
        const amt = parseFormattedNumber(getValue('advancePayment'));
        setValue('advancePaymentPercent', ((amt / price) * 100).toFixed(2));
    } else {
        const pct = parseFormattedNumber(getValue('advancePaymentPercent'));
        setValue('advancePayment', formatNumber(price / 100 * pct));
    }
}

function onLastChange(type) {
    const price = parseFormattedNumber(getValue('price'));
    if (!price) return;
    if (type === 'amount') {
        const amt = parseFormattedNumber(getValue('lastPayment'));
        setValue('lastPaymentPercent', ((amt / price) * 100).toFixed(2));
    } else {
        const pct = parseFormattedNumber(getValue('lastPaymentPercent'));
        setValue('lastPayment', formatNumber(price / 100 * pct));
    }
}

async function getAndFillGraph() {
    const mode = getValue('paymentMode');
    const typeSelected = mode === 'internal' ? getValue('type_select') : mode;

    if (mode === 'internal' && !typeSelected) {
        showError('გთხოვთ აირჩიოთ გრაფიკის ტიპი');
        return;
    }

    const price = parseFormattedNumber(getValue('price'));
    if (!price) { showError('ფასი არ არის შევსებული'); return; }

    const requestData = {
        dealId: CONFIG.dealID,
        type_selected: typeSelected,
        payment_mode: mode,
        price: price,
        startDate: getValue('startDate') || today(),
        endDate: getValue('endDate') || today(),
        advancePayment: parseFormattedNumber(getValue('advancePayment')),
        advancePayDate: getValue('advancePayDate'),
        lastPayment: parseFormattedNumber(getValue('lastPayment')),
        lastPayDate: getValue('lastPayDate'),
        period: getValue('period'),
        bookPayment: 0,
        bookPayDate: '',
    };

    if (mode !== 'allCash' && (!requestData.startDate || !requestData.endDate)) {
        showError('გთხოვთ შეავსოთ დაწყება/დასრულების თარიღი');
        return;
    }

    try {
        const res = await fetch('/rest/local/api/calculator/stageCalculateGraph.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestData),
        });
        const data = await res.json();
        if (data.status === 200) {
            showError('');
            fillGraph(data, mode === 'customType');
            updateSaveButtonText();
            show('saveBTN');
        } else {
            showError(data.errorTXT || 'გამოთვლა ვერ მოხერხდა');
            hide('saveBTN');
            clearGraph();
        }
    } catch (e) {
        showError('გრაფიკის გამოთვლა ვერ მოხერხდა');
    }
}

function isAdvanceRow(row) {
    const advance = parseFormattedNumber(getValue('advancePayment'));
    const advanceDate = getValue('advancePayDate');
    return advance > 0 && row.date === advanceDate && Math.abs(row.amount - advance) < 0.05;
}

function isLastRow(row) {
    const last = parseFormattedNumber(getValue('lastPayment'));
    const lastDate = getValue('lastPayDate');
    return last > 0 && lastDate && row.date === lastDate && Math.abs(row.amount - last) < 0.05;
}

function paymentLabel(row, installmentNum) {
    if (isAdvanceRow(row)) return 'პირველადი შენატანი';
    if (isLastRow(row)) return 'ბოლო შენატანი';
    return installmentNum;
}

function fillGraph(data, editable) {
    const rows = data.result.filter(row => row.amount > 0);
    let installmentNum = 0;

    let html = `<thead><tr><th>#</th><th>გადახდის თარიღი</th><th>თანხა ($)</th><th>დარჩენილი თანხა ($)</th></tr></thead><tbody>`;
    rows.forEach(row => {
        if (!isAdvanceRow(row) && !isLastRow(row)) installmentNum++;
        const label = paymentLabel(row, installmentNum);
        html += `<tr data-payment="${label}"><td>${label}</td>`;
        html += `<td><input type="text" class="date-field" value="${row.date}" ${editable ? '' : 'disabled'} readonly></td>`;
        html += `<td><input value="${formatNumber(row.amount)}" ${editable ? '' : 'disabled'}
            oninput="recalculateDebt()"></td>`;
        html += `<td>${formatNumber(row.leftToPay)}</td></tr>`;
    });
    html += '</tbody>';
    document.getElementById('graphData').innerHTML = html;
    if (editable) {
        document.querySelectorAll('#graphData .date-field').forEach(el => initDatePickerOnElement(el));
    }
}

function updateSaveButtonText() {
    const btn = document.getElementById('saveBTN');
    if (!btn) return;
    btn.textContent = getValue('paymentMode') === 'customType' ? 'გაგზავნა' : 'შენახვა';
}

async function saveGraph() {
    const mode = getValue('paymentMode');
    const typeSelected = mode === 'internal' ? getValue('type_select') : mode;
    const price = parseFormattedNumber(getValue('price'));
    const table = document.getElementById('graphData');
    const tbody = table.querySelector('tbody');
    if (!tbody || !tbody.rows.length) { alert('გთხოვთ ჯერ გამოთვალოთ გრაფიკი'); return; }

    const paymentPlan = Array.from(tbody.rows).map(row => ({
        payment: row.dataset.payment || row.cells[0].textContent,
        date: row.cells[1].querySelector('input').value,
        amount: parseFormattedNumber(row.cells[2].querySelector('input').value),
    }));

    const total = paymentPlan.reduce((s, r) => s + r.amount, 0);
    if (Math.abs(total - price) > 0.05) {
        alert('გრაფიკის ჯამი (' + formatNumber(total) + ') არ ემთხვევა საბოლოო ფასს (' + formatNumber(price) + ')');
        return;
    }

    const advancePayment = parseFormattedNumber(getValue('advancePayment'));
    const advancePaymentPercent = parseFormattedNumber(getValue('advancePaymentPercent'));
    const lastPayment = parseFormattedNumber(getValue('lastPayment'));
    const lastPaymentPercent = parseFormattedNumber(getValue('lastPaymentPercent'));
    const distributed = (price - advancePayment - lastPayment).toFixed(2);
    const distributedPct = price > 0 ? ((distributed / price) * 100).toFixed(2) : '0';

    const graphLabel = mode === 'internal'
        ? (CONFIG.instalmentPlanArr[typeSelected] || '')
        : (mode === 'allCash' ? 'ერთიანი გადახდა' : 'არასტანდარტული');

    const savingJson = {
        dealId: CONFIG.dealID,
        data: paymentPlan,
        selected_type: typeSelected,
        payment_mode: mode,
        graph: graphLabel,
        planType: graphLabel,
        PRICE: price,
        kvmPrice: parseFormattedNumber(getValue('kvmPrice')),
        commentInput: getValue('commentInput'),
        period: getValue('period'),
        author: CONFIG.userID,
        PROD_ID: CONFIG.prodID,
        oldPrice: CONFIG.oldPrice,
        advancePayment: `${advancePayment} $ / ${advancePaymentPercent} %`,
        lastPayment: `${lastPayment} $ / ${lastPaymentPercent} %`,
        DistributedPayment: `${distributed} $ / ${distributedPct} %`,
        discountAmount: `${parseFormattedNumber(getValue('discountNum'))} $`,
        lastAmount: `${formatNumber(price)} $`,
    };

    try {
        const res = await fetch('/rest/local/api/calculator/saveGraphEndRunWorkflow.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(savingJson),
        });
        const data = await res.json();
        const swalResult = await Swal.fire({ icon: data.status === 200 ? 'success' : 'warning', title: data.TEXT, confirmButtonColor: '#4f46e5' });
        if (data.status === 200 && swalResult.isConfirmed) {
            refreshDealPage();
            if (typeof BX !== 'undefined' && BX.SidePanel && BX.SidePanel.Instance.getSliderByWindow(window)) {
                BX.SidePanel.Instance.close();
            } else if (window.opener) {
                window.close();
            }
        }
    } catch (e) {
        alert('შენახვა ვერ მოხერხდა');
    }
}

function refreshDealPage() {
    try {
        if (typeof BX !== 'undefined' && BX.SidePanel) {
            const slider = BX.SidePanel.Instance.getSliderByWindow(window);
            if (slider && slider.getParentSlider) {
                const parentSlider = slider.getParentSlider();
                if (parentSlider) {
                    const parentWin = parentSlider.getWindow();
                    if (parentWin && parentWin.location) {
                        parentWin.location.reload();
                        return;
                    }
                }
            }
        }
    } catch (e) {}

    if (window.opener && !window.opener.closed) {
        window.opener.location.reload();
    } else if (window.top && window.top !== window) {
        window.top.location.reload();
    }
}

function recalculateDebt() {
    const price = parseFormattedNumber(getValue('price'));
    const table = document.getElementById('graphData');
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    let sum = 0;
    let paid = 0;
    Array.from(tbody.rows).forEach(row => {
        const amt = parseFormattedNumber(row.cells[2].querySelector('input').value);
        sum += amt;
        paid += amt;
        if (row.cells[3]) row.cells[3].textContent = formatNumber(Math.max(0, price - paid));
    });
    if (Math.abs(sum - price) <= 0.05) show('saveBTN');
    else hide('saveBTN');
}

// ── helpers ──
function formatNumber(num) {
    const n = parseFloat(String(num).replace(/,/g, ''));
    if (isNaN(n)) return '0.00';
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function parseFormattedNumber(str) {
    if (typeof str !== 'string') return parseFloat(str) || 0;
    return parseFloat(str.replace(/,/g, '')) || 0;
}
function today() {
    const d = new Date();
    return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`;
}
function dateAddMonth(date, months) {
    const [day, month, year] = date.split('/').map(Number);
    const d = new Date(year, month - 1, day);
    d.setMonth(d.getMonth() + parseInt(months));
    return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`;
}
function getValue(id) { return document.getElementById(id).value; }
function setValue(id, val) {
    const el = document.getElementById(id);
    if (!el) return;
    if (DATE_FIELD_IDS.includes(id)) {
        setDateValue(id, val);
        return;
    }
    el.value = val;
}
function setDateValue(id, val) {
    const el = document.getElementById(id);
    if (!el) return;
    if (el._flatpickr) {
        if (val) el._flatpickr.setDate(val, false, 'd/m/Y');
        else el._flatpickr.clear();
    } else {
        el.value = val || '';
    }
}
function initDatePickers() {
    DATE_FIELD_IDS.forEach(id => {
        const el = document.getElementById(id);
        if (el) initDatePickerOnElement(el);
    });
}
function initDatePickerOnElement(el) {
    if (!el || el._flatpickr) return;
    flatpickr(el, DATE_PICKER_OPTS);
}
function show(id) { document.getElementById(id).classList.remove('hidden'); }
function hide(id) { document.getElementById(id).classList.add('hidden'); }
function showError(msg) {
    const el = document.getElementById('errors');
    el.innerHTML = msg ? `<p class="error-msg">* ${msg}</p>` : '';
}
function clearGraph() {
    document.getElementById('graphData').innerHTML = '';
    hide('saveBTN');
}
function disableFields(ids) {
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.disabled = true;
        if (el._flatpickr) el._flatpickr.set('clickOpens', false);
    });
}
function enableFields(ids) {
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.disabled = false;
        if (el._flatpickr) el._flatpickr.set('clickOpens', true);
    });
}
</script>
</body>
</html>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
