<?php
/**
 * პროდუქტის ფასი → დილის OPPORTUNITY,
 * კვ.მ ფასი (__6ZWTER) → UF_CRM_1779277671391
 * (ownerDeal-დან), IS_MANUAL_OPPORTUNITY = Y
 *
 * UI:     https://crm.monolith.ge/crm/deal/migration/syncProdPricesToDeals.php
 * Dry:    ?ajax=1
 * Apply:  ?ajax=1&apply=1
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');
if (function_exists('session_write_close')) {
    session_write_close();
}

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');
CModule::IncludeModule('catalog');

define('PRODUCT_IBLOCK_ID', 14);
define('PROP_PROJECT', '__VO9RG4');
define('PROP_TYPE', '__X1GCRZ');
define('PROP_STATUS', '_P64GYD');
define('PROP_OWNER_DEAL', 'ownerDeal');
define('PROP_KVM_PRICE', '__6ZWTER');
define('D_KVM_PRICE', 'UF_CRM_1779277671391');

$DEFAULT_PROJECT = 'Dighomi';
$DEFAULT_TYPE = 'ბინა';
$DEFAULT_STATUS = 'გაყიდული';

$TYPE_OPTIONS = [
    'ბინა',
    'ბინა (1 საძ.)',
    'ბინა (2 საძ.)',
    'ბინა (3 საძ.)',
    'სტუდიო',
    'დუპლექსი',
    'შიდა ავტოსადგომი',
    'გარე ავტოსადგომი',
    'დამხმარე',
    'საოფისე',
    'კომერციული',
];
$STATUS_OPTIONS = ['თავისუფალი', 'დაჯავშნილი', 'გაყიდული', 'NFS'];
$PROJECT_OPTIONS = ['Dighomi'];

function upd5NormalizePropValue($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    return trim((string)$value);
}

function upd5ParseNumber($value)
{
    $text = upd5NormalizePropValue($value);
    if ($text === '') {
        return 0.0;
    }
    if (strpos($text, '|') !== false) {
        $parts = explode('|', $text);
        foreach ($parts as $part) {
            $part = trim(str_replace([' ', ','], ['', '.'], $part));
            if ($part !== '' && is_numeric($part)) {
                return round((float)$part, 2);
            }
        }
    }
    $text = str_replace([' ', ','], ['', '.'], $text);
    return is_numeric($text) ? round((float)$text, 2) : 0.0;
}

function upd5ExtractDealId($value)
{
    $text = upd5NormalizePropValue($value);
    if ($text === '') {
        return 0;
    }
    if (ctype_digit($text)) {
        return (int)$text;
    }
    if (preg_match('/^(?:D_|DEAL_)?(\d+)$/i', $text, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/(\d+)/', $text, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function upd5GetBasePrice($productId)
{
    $priceRow = CPrice::GetBasePrice((int)$productId);
    return isset($priceRow['PRICE']) ? round((float)$priceRow['PRICE'], 2) : 0.0;
}

function upd5LoadEnumOptions($propCode, array $fallback)
{
    $options = [];
    $propRes = CIBlockProperty::GetList([], [
        'IBLOCK_ID' => PRODUCT_IBLOCK_ID,
        'CODE' => $propCode,
    ]);
    $prop = $propRes ? $propRes->Fetch() : false;
    if ($prop && !empty($prop['ID'])) {
        $enumRes = CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC', 'VALUE' => 'ASC'],
            ['PROPERTY_ID' => (int)$prop['ID']]
        );
        while ($enum = $enumRes->Fetch()) {
            $val = trim((string)($enum['VALUE'] ?? ''));
            if ($val !== '') {
                $options[] = $val;
            }
        }
    }
    return $options ?: $fallback;
}

/** პროდუქტებიდან: პროექტი → ტიპები / სტატუსები */
function upd5LoadFilterMap()
{
    $map = [];
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => PRODUCT_IBLOCK_ID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'IBLOCK_ID']
    );
    while ($ob = $res->GetNextElement()) {
        $props = $ob->GetProperties();
        $project = upd5NormalizePropValue($props[PROP_PROJECT]['VALUE'] ?? '');
        $type = upd5NormalizePropValue($props[PROP_TYPE]['VALUE'] ?? '');
        $status = upd5NormalizePropValue($props[PROP_STATUS]['VALUE'] ?? '');
        if ($project === '') {
            continue;
        }
        if (!isset($map[$project])) {
            $map[$project] = [
                'types' => [],
                'statuses' => [],
                'statusesByType' => [],
            ];
        }
        if ($type !== '') {
            $map[$project]['types'][$type] = true;
            if (!isset($map[$project]['statusesByType'][$type])) {
                $map[$project]['statusesByType'][$type] = [];
            }
            if ($status !== '') {
                $map[$project]['statusesByType'][$type][$status] = true;
            }
        }
        if ($status !== '') {
            $map[$project]['statuses'][$status] = true;
        }
    }

    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($map as $project => &$entry) {
        $types = array_keys($entry['types']);
        natcasesort($types);
        $entry['types'] = array_values($types);

        $statuses = array_keys($entry['statuses']);
        natcasesort($statuses);
        $entry['statuses'] = array_values($statuses);

        $byType = [];
        foreach ($entry['statusesByType'] as $type => $statusSet) {
            $list = array_keys($statusSet);
            natcasesort($list);
            $byType[$type] = array_values($list);
        }
        $entry['statusesByType'] = $byType;
    }
    unset($entry);

    return $map;
}

$isAjax = isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === '1';
$apply = isset($_REQUEST['apply']) && $_REQUEST['apply'] === '1';

$filterProject = upd5NormalizePropValue($_REQUEST['project'] ?? $DEFAULT_PROJECT);
$filterType = upd5NormalizePropValue($_REQUEST['type'] ?? $DEFAULT_TYPE);
$filterStatus = upd5NormalizePropValue($_REQUEST['status'] ?? $DEFAULT_STATUS);

if ($filterProject === '') {
    $filterProject = $DEFAULT_PROJECT;
}
if ($filterType === '') {
    $filterType = $DEFAULT_TYPE;
}
if ($filterStatus === '') {
    $filterStatus = $DEFAULT_STATUS;
}

$FILTER_MAP = [];
$PROJECT_OPTIONS = [];
$TYPE_OPTIONS = [];
$STATUS_OPTIONS = [];

if (!$isAjax) {
    $FILTER_MAP = upd5LoadFilterMap();
    $PROJECT_OPTIONS = array_keys($FILTER_MAP);
    if (!$PROJECT_OPTIONS) {
        $PROJECT_OPTIONS = upd5LoadEnumOptions(PROP_PROJECT, ['Dighomi']);
    }

    if ($filterProject === '' || !isset($FILTER_MAP[$filterProject])) {
        $filterProject = isset($FILTER_MAP[$DEFAULT_PROJECT])
            ? $DEFAULT_PROJECT
            : ($PROJECT_OPTIONS[0] ?? $DEFAULT_PROJECT);
    }

    $TYPE_OPTIONS = $FILTER_MAP[$filterProject]['types'] ?? [];
    if (!$TYPE_OPTIONS) {
        $TYPE_OPTIONS = upd5LoadEnumOptions(PROP_TYPE, [
            'ბინა', 'სტუდიო', 'დუპლექსი', 'შიდა ავტოსადგომი', 'გარე ავტოსადგომი', 'დამხმარე', 'საოფისე', 'კომერციული',
        ]);
    }
    if ($filterType === '' || !in_array($filterType, $TYPE_OPTIONS, true)) {
        $filterType = in_array($DEFAULT_TYPE, $TYPE_OPTIONS, true)
            ? $DEFAULT_TYPE
            : ($TYPE_OPTIONS[0] ?? $DEFAULT_TYPE);
    }

    $STATUS_OPTIONS = $FILTER_MAP[$filterProject]['statusesByType'][$filterType]
        ?? ($FILTER_MAP[$filterProject]['statuses'] ?? []);
    if (!$STATUS_OPTIONS) {
        $STATUS_OPTIONS = upd5LoadEnumOptions(PROP_STATUS, ['თავისუფალი', 'დაჯავშნილი', 'გაყიდული', 'NFS']);
    }
    if ($filterStatus === '' || !in_array($filterStatus, $STATUS_OPTIONS, true)) {
        $filterStatus = in_array($DEFAULT_STATUS, $STATUS_OPTIONS, true)
            ? $DEFAULT_STATUS
            : ($STATUS_OPTIONS[0] ?? $DEFAULT_STATUS);
    }
}

if ($isAjax) {
    $counts = [
        'products' => 0,
        'no_owner_deal' => 0,
        'deal_not_found' => 0,
        'already_same' => 0,
        'would_update' => 0,
        'updated' => 0,
        'failed' => 0,
    ];
    $results = [];

    $filter = [
        'IBLOCK_ID' => PRODUCT_IBLOCK_ID,
        'ACTIVE' => 'Y',
        'CHECK_PERMISSIONS' => 'N',
        'PROPERTY_' . PROP_PROJECT => $filterProject,
        'PROPERTY_' . PROP_TYPE => $filterType,
        'PROPERTY_' . PROP_STATUS => $filterStatus,
    ];

    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        $filter,
        false,
        false,
        ['ID', 'NAME', 'IBLOCK_ID']
    );

    while ($ob = $res->GetNextElement()) {
        $fields = $ob->GetFields();
        $props = $ob->GetProperties();
        $counts['products']++;

        $productId = (int)$fields['ID'];
        $ownerDealRaw = $props[PROP_OWNER_DEAL]['VALUE'] ?? ($props['OWNER_DEAL']['VALUE'] ?? '');
        $dealId = upd5ExtractDealId($ownerDealRaw);
        $price = upd5GetBasePrice($productId);
        $kvmPrice = upd5ParseNumber($props[PROP_KVM_PRICE]['VALUE'] ?? '');

        $row = [
            'product_id' => $productId,
            'product_name' => $fields['NAME'] ?? '',
            'owner_deal_raw' => upd5NormalizePropValue($ownerDealRaw),
            'deal_id' => $dealId,
            'product_price' => $price,
            'kvm_price' => $kvmPrice,
            'project' => upd5NormalizePropValue($props[PROP_PROJECT]['VALUE'] ?? ''),
            'type' => upd5NormalizePropValue($props[PROP_TYPE]['VALUE'] ?? ''),
            'status' => upd5NormalizePropValue($props[PROP_STATUS]['VALUE'] ?? ''),
        ];

        if ($dealId <= 0) {
            $row['result'] = 'no_owner_deal';
            $counts['no_owner_deal']++;
            $results[] = $row;
            continue;
        }

        $dealRes = CCrmDeal::GetListEx(
            [],
            ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID', 'TITLE', 'OPPORTUNITY', 'CURRENCY_ID', 'IS_MANUAL_OPPORTUNITY', D_KVM_PRICE]
        );
        $deal = $dealRes ? $dealRes->Fetch() : false;

        if (!$deal) {
            $row['result'] = 'deal_not_found';
            $counts['deal_not_found']++;
            $results[] = $row;
            continue;
        }

        $opportunityBefore = round((float)($deal['OPPORTUNITY'] ?? 0), 2);
        $kvmBefore = upd5ParseNumber($deal[D_KVM_PRICE] ?? '');
        $row['deal_title'] = $deal['TITLE'] ?? '';
        $row['opportunity_before'] = $opportunityBefore;
        $row['kvm_before'] = $kvmBefore;
        $row['currency'] = $deal['CURRENCY_ID'] ?? '';
        $row['is_manual_before'] = $deal['IS_MANUAL_OPPORTUNITY'] ?? '';

        $opportunitySame = abs($opportunityBefore - $price) < 0.01
            && ($deal['IS_MANUAL_OPPORTUNITY'] ?? '') === 'Y';
        // კვ.მ ველში 0 არ იწერება — თუ პროდუქტზე 0ა, ველს არ ვეხებით
        $kvmSame = $kvmPrice <= 0 || abs($kvmBefore - $kvmPrice) < 0.01;

        if ($opportunitySame && $kvmSame) {
            $row['result'] = 'already_same';
            $counts['already_same']++;
            $results[] = $row;
            continue;
        }

        if (!$apply) {
            $row['result'] = 'would_update';
            $counts['would_update']++;
            $results[] = $row;
            continue;
        }

        $crmDeal = new CCrmDeal(false);
        $fieldsUpdate = [
            'IS_MANUAL_OPPORTUNITY' => 'Y',
            'OPPORTUNITY' => $price,
        ];
        if ($kvmPrice > 0) {
            $fieldsUpdate[D_KVM_PRICE] = $kvmPrice;
        }
        if (!empty($deal['CURRENCY_ID'])) {
            $fieldsUpdate['CURRENCY_ID'] = $deal['CURRENCY_ID'];
        }

        $ok = $crmDeal->Update($dealId, $fieldsUpdate);
        if (!$ok) {
            $row['result'] = 'failed';
            $row['error'] = $crmDeal->LAST_ERROR;
            $counts['failed']++;
            $results[] = $row;
            continue;
        }

        $verifyRes = CCrmDeal::GetListEx(
            [],
            ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID', 'OPPORTUNITY', 'IS_MANUAL_OPPORTUNITY', D_KVM_PRICE]
        );
        $verify = $verifyRes ? $verifyRes->Fetch() : false;

        $row['opportunity_after'] = round((float)($verify['OPPORTUNITY'] ?? 0), 2);
        $row['kvm_after'] = upd5ParseNumber($verify[D_KVM_PRICE] ?? '');
        $row['is_manual_after'] = $verify['IS_MANUAL_OPPORTUNITY'] ?? '';
        $row['result'] = 'updated';
        $counts['updated']++;
        $results[] = $row;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'apply' => $apply,
        'filter' => [
            'project' => $filterProject,
            'type' => $filterType,
            'status' => $filterStatus,
        ],
        'counts' => $counts,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
    die();
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>დილის ფასების აფდეითი</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f5f7;
            --panel: #ffffff;
            --text: #00335b;
            --muted: #6b7a8a;
            --primary: #00335b;
            --accent: #72c4b1;
            --warn: #c47a3a;
            --danger: #b94a48;
            --ok: #2f8f6b;
            --line: #dde2e8;
            --shadow: 0 10px 28px rgba(0, 51, 91, 0.08);
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--text);
            font-family: "Montserrat", "Segoe UI", sans-serif;
        }
        .page {
            max-width: none;
            margin: 0 auto;
            padding: 16px 20px 40px;
            width: 100%;
        }
        .hero {
            background: linear-gradient(135deg, #00335b 0%, #0a4a75 55%, #1a6b7a 100%);
            color: #fff;
            border-radius: 10px;
            padding: 22px 24px;
            box-shadow: var(--shadow);
            margin-bottom: 16px;
        }
        .hero h1 {
            margin: 0 0 8px;
            font-size: 22px;
            font-weight: 700;
        }
        .hero p {
            margin: 0;
            opacity: 0.92;
            font-size: 14px;
            line-height: 1.55;
            max-width: 820px;
        }
        .grid {
            display: grid;
            grid-template-columns: 280px minmax(0, 1fr);
            gap: 16px;
            align-items: start;
        }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: var(--shadow);
            padding: 16px;
        }
        .panel h2 {
            margin: 0 0 12px;
            font-size: 15px;
            font-weight: 700;
        }
        .desc-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 8px;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.45;
        }
        .desc-list li {
            padding-left: 14px;
            position: relative;
        }
        .desc-list li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 7px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent);
        }
        .field {
            display: grid;
            gap: 6px;
            margin-bottom: 12px;
        }
        .field label {
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
        }
        .field select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 10px 12px;
            font: inherit;
            color: var(--text);
            background: #fff;
        }
        .actions {
            display: grid;
            gap: 8px;
            margin-top: 4px;
        }
        button {
            border: 0;
            border-radius: 6px;
            padding: 11px 14px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        button:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .btn-diff {
            background: var(--accent);
            color: #08353a;
        }
        .btn-run {
            background: var(--primary);
            color: #fff;
        }
        .btn-ghost {
            background: #eef2f6;
            color: var(--text);
        }
        .stats {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        .stat {
            background: #f7f9fb;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 8px 10px;
            min-width: 110px;
        }
        .stat b {
            display: block;
            font-size: 16px;
            line-height: 1.2;
        }
        .stat span {
            font-size: 11px;
            color: var(--muted);
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .toolbar .left {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            background: #fff;
            color: var(--muted);
            cursor: pointer;
            user-select: none;
        }
        .chip input { margin: 0; }
        .status {
            font-size: 13px;
            color: var(--muted);
            min-height: 20px;
        }
        .status.err { color: var(--danger); }
        .status.ok { color: var(--ok); }
        .table-wrap {
            overflow-x: hidden;
            overflow-y: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
            max-height: 62vh;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            table-layout: fixed;
        }
        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            word-break: break-word;
        }
        th {
            position: sticky;
            top: 0;
            background: #f0f4f8;
            z-index: 1;
            font-weight: 600;
            white-space: nowrap;
        }
        th:nth-child(1), td:nth-child(1) { width: 110px; }
        th:nth-child(2), td:nth-child(2) { width: 22%; }
        th:nth-child(3), td:nth-child(3) { width: 26%; }
        th:nth-child(4), td:nth-child(4) { width: 22%; }
        th:nth-child(5), td:nth-child(5) { width: 14%; }
        tr:hover td { background: #fafcfd; }
        .badge {
            display: inline-block;
            border-radius: 4px;
            padding: 2px 7px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .b-would { background: #fff4e5; color: var(--warn); }
        .b-same { background: #eaf7f1; color: var(--ok); }
        .b-updated { background: #e5f3ff; color: #1a5f9e; }
        .b-fail, .b-miss { background: #fdecea; color: var(--danger); }
        .diff-old { color: var(--danger); text-decoration: line-through; margin-right: 6px; }
        .diff-new { color: var(--ok); font-weight: 600; }
        a.entity-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        a.entity-link:hover { text-decoration: underline; color: #0a4a75; }
        .empty {
            padding: 28px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }
        .spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(0,0,0,.15);
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            vertical-align: -1px;
            margin-right: 6px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <h1>დილის ფასების აფდეითი</h1>
        <p>
            ფილტრის მიხედვით იპოვის პროდუქტებს, ამ პროდუქტის დილზე წერს პროდუქტის ფასს და "კვ/მ ღირებულება $" ველში პროდუქტიდან კვ.მ ფასს.
        </p>
    </div>

    <div class="grid">
        <aside class="panel">
            <h2>ფილტრი</h2>
            <div class="field">
                <label for="project">პროექტი</label>
                <select id="project">
                    <?php foreach ($PROJECT_OPTIONS as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $opt === $filterProject ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="type">ტიპი</label>
                <select id="type">
                    <?php foreach ($TYPE_OPTIONS as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $opt === $filterType ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="status">სტატუსი</label>
                <select id="status">
                    <?php foreach ($STATUS_OPTIONS as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $opt === $filterStatus ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="actions">
                <button type="button" class="btn-diff" id="btnDiff">განსხვავების ნახვა</button>
                <button type="button" class="btn-run" id="btnRun" disabled>გაშვება</button>
                <button type="button" class="btn-ghost" id="btnReset">გასუფთავება</button>
            </div>

            <!-- <h2 style="margin-top:18px">რას აკეთებს</h2>
            <ul class="desc-list">
                <li>ირჩევს აქტიურ პროდუქტებს (iblock 14) პროექტი + ტიპი + სტატუსი ფილტრით</li>
                <li>ownerDeal-დან ამოიღებს დილის ID-ს</li>
                <li>პროდუქტის catalog ფასს ადარებს დილის OPPORTUNITY-ს</li>
                <li>კვ.მ ფასს (__6ZWTER) ადარებს UF_CRM_1779277671391-ს (0-ს არ წერს)</li>
                <li>განსხვავება = მხოლოდ ნახვა; გაშვება = რეალური Update</li>
            </ul> -->
        </aside>

        <section class="panel">
            <div class="toolbar">
                <div class="left">
                    <label class="chip"><input type="checkbox" id="onlyDiff" checked> მხოლოდ განსხვავება</label>
                    <label class="chip"><input type="checkbox" id="hideSame" checked> already_same დამალვა</label>
                </div>
                <div class="status" id="statusMsg">აირჩიე ფილტრი და დააჭირე „განსხვავების ნახვა“.</div>
            </div>
            <div class="stats" id="stats"></div>
            <div class="table-wrap" id="tableWrap">
                <div class="empty">ჯერ შედეგი არაა.</div>
            </div>
        </section>
    </div>
</div>

<script>
(function () {
    const el = (id) => document.getElementById(id);
    const project = el('project');
    const type = el('type');
    const status = el('status');
    const btnDiff = el('btnDiff');
    const btnRun = el('btnRun');
    const btnReset = el('btnReset');
    const statusMsg = el('statusMsg');
    const stats = el('stats');
    const tableWrap = el('tableWrap');
    const onlyDiff = el('onlyDiff');
    const hideSame = el('hideSame');

    const filterMap = <?= json_encode($FILTER_MAP, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const defaults = {
        type: <?= json_encode($DEFAULT_TYPE, JSON_UNESCAPED_UNICODE) ?>,
        status: <?= json_encode($DEFAULT_STATUS, JSON_UNESCAPED_UNICODE) ?>,
    };

    let lastPayload = null;
    let busy = false;
    let syncingFilters = false;

    const RESULT_LABELS = {
        would_update: 'განსხვავება',
        already_same: 'იგივეა',
        updated: 'განახლდა',
        failed: 'შეცდომა',
        no_owner_deal: 'დილი არაა',
        deal_not_found: 'დილი ვერ მოიძებნა',
    };

    const RESULT_CLASS = {
        would_update: 'b-would',
        already_same: 'b-same',
        updated: 'b-updated',
        failed: 'b-fail',
        no_owner_deal: 'b-miss',
        deal_not_found: 'b-miss',
    };

    function fillSelect(select, options, preferred) {
        const prev = preferred != null ? preferred : select.value;
        select.innerHTML = '';
        if (!options.length) {
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = '— არაა —';
            select.appendChild(empty);
            return;
        }
        let chosen = options.indexOf(prev) >= 0 ? prev : null;
        if (chosen == null && preferred && options.indexOf(preferred) >= 0) {
            chosen = preferred;
        }
        if (chosen == null) {
            chosen = options[0];
        }
        options.forEach(function (val) {
            const opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val;
            if (val === chosen) opt.selected = true;
            select.appendChild(opt);
        });
    }

    function projectEntry() {
        return filterMap[project.value] || { types: [], statuses: [], statusesByType: {} };
    }

    function syncTypeAndStatus(keepType, keepStatus) {
        syncingFilters = true;
        const entry = projectEntry();
        fillSelect(type, entry.types || [], keepType != null ? keepType : (defaults.type || type.value));
        const byType = (entry.statusesByType && entry.statusesByType[type.value]) || [];
        const statuses = byType.length ? byType : (entry.statuses || []);
        fillSelect(status, statuses, keepStatus != null ? keepStatus : (defaults.status || status.value));
        syncingFilters = false;
    }

    function onFilterChanged() {
        if (syncingFilters) return;
        btnRun.disabled = true;
        statusMsg.className = 'status';
        statusMsg.textContent = 'ფილტრი შეიცვალა — თავიდან ნახე განსხვავება.';
    }

    function money(n) {
        const x = Number(n || 0);
        return x.toLocaleString('ka-GE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function setBusy(v, text) {
        busy = v;
        btnDiff.disabled = v;
        btnRun.disabled = v || !lastPayload || !(lastPayload.counts && lastPayload.counts.would_update > 0);
        statusMsg.className = 'status';
        if (text) {
            statusMsg.innerHTML = (v ? '<span class="spinner"></span>' : '') + text;
        }
    }

    function filterParams() {
        const p = new URLSearchParams();
        p.set('ajax', '1');
        p.set('project', project.value);
        p.set('type', type.value);
        p.set('status', status.value);
        return p;
    }

    async function run(apply) {
        if (busy) return;
        if (apply) {
            const n = lastPayload && lastPayload.counts ? lastPayload.counts.would_update : 0;
            const ok = confirm(
                'დარწმუნებული ხარ?\n\n' +
                'ფილტრი: ' + project.value + ' / ' + type.value + ' / ' + status.value + '\n' +
                'განახლდება დაახლ. ' + n + ' დილი.\n\n' +
                'ეს რეალურად ჩაწერს CRM-ში.'
            );
            if (!ok) return;
        }

        setBusy(true, apply ? 'მიმდინარეობს ჩაწერა…' : 'მიმდინარეობს შედარება…');
        try {
            const p = filterParams();
            if (apply) p.set('apply', '1');
            const res = await fetch(location.pathname + '?' + p.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            lastPayload = data;
            render(data);
            statusMsg.className = 'status ok';
            statusMsg.textContent = apply
                ? ('დასრულდა. განახლდა: ' + (data.counts.updated || 0) + ', შეცდომა: ' + (data.counts.failed || 0))
                : ('ნაპოვნია პროდუქტი: ' + (data.counts.products || 0) + ', განსხვავება: ' + (data.counts.would_update || 0));
            btnRun.disabled = !(data.counts && data.counts.would_update > 0);
            if (apply) {
                // apply შემდეგ would_update = 0, განახლების შემდეგ თავიდან dry run
                btnRun.disabled = true;
            }
        } catch (e) {
            statusMsg.className = 'status err';
            statusMsg.textContent = 'შეცდომა: ' + (e && e.message ? e.message : e);
            btnRun.disabled = true;
        } finally {
            busy = false;
            btnDiff.disabled = false;
        }
    }

    function visibleRows(data) {
        let rows = data.results || [];
        if (hideSame.checked) {
            rows = rows.filter((r) => r.result !== 'already_same');
        }
        if (onlyDiff.checked) {
            rows = rows.filter((r) => r.result === 'would_update' || r.result === 'updated' || r.result === 'failed');
        }
        return rows;
    }

    function renderStats(counts) {
        const items = [
            ['products', 'პროდუქტი'],
            ['would_update', 'განსხვავება'],
            ['already_same', 'იგივეა'],
            ['updated', 'განახლდა'],
            ['no_owner_deal', 'დილი არაა'],
            ['deal_not_found', 'ვერ მოიძებნა'],
            ['failed', 'შეცდომა'],
        ];
        stats.innerHTML = items.map(([k, label]) => (
            '<div class="stat"><b>' + (counts[k] || 0) + '</b><span>' + label + '</span></div>'
        )).join('');
    }

    function cellDiff(before, after, force) {
        const b = Number(before || 0);
        const a = Number(after || 0);
        if (!force && Math.abs(b - a) < 0.01) {
            return money(b);
        }
        return '<span class="diff-old">' + money(b) + '</span><span class="diff-new">' + money(a) + '</span>';
    }

    function render(data) {
        renderStats(data.counts || {});
        const rows = visibleRows(data);
        if (!rows.length) {
            tableWrap.innerHTML = '<div class="empty">ამ ფილტრებით საჩვენებელი ჩანაწერი არაა.</div>';
            return;
        }

        let html = '<table><thead><tr>' +
            '<th>შედეგი</th><th>პროდუქტი</th><th>დილი</th>' +
            '<th>OPPORTUNITY</th><th>კვ.მ ფასი</th>' +
            // '<th>ვალუტა</th>' +
            '</tr></thead><tbody>';

        for (const r of rows) {
            const badge = '<span class="badge ' + (RESULT_CLASS[r.result] || '') + '">' +
                (RESULT_LABELS[r.result] || r.result) + '</span>';
            const productCell = r.product_id
                ? ('<a class="entity-link" href="/crm/catalog/14/product/' + r.product_id + '/" target="_blank" rel="noopener">#' + r.product_id + '</a>' +
                   '<div style="color:#6b7a8a">' + escapeHtml(r.product_name || '') + '</div>')
                : '—';
            const dealCell = r.deal_id
                ? ('<a class="entity-link" href="/crm/deal/details/' + r.deal_id + '/" target="_blank" rel="noopener">#' + r.deal_id + '</a>' +
                   (r.deal_title ? '<div style="color:#6b7a8a">' + escapeHtml(r.deal_title) + '</div>' : ''))
                : escapeHtml(r.owner_deal_raw || '—');

            const oppAfter = r.result === 'updated' ? r.opportunity_after : r.product_price;
            const kvmAfter = r.result === 'updated' ? r.kvm_after : r.kvm_price;
            const showOppDiff = r.result === 'would_update' || r.result === 'updated' || r.result === 'failed';
            const showKvmDiff = showOppDiff && Number(r.kvm_price || 0) > 0;

            html += '<tr>' +
                '<td>' + badge + (r.error ? '<div style="color:#b94a48;margin-top:4px">' + escapeHtml(r.error) + '</div>' : '') + '</td>' +
                '<td>' + productCell + '</td>' +
                '<td>' + dealCell + '</td>' +
                '<td>' + (showOppDiff ? cellDiff(r.opportunity_before, oppAfter, true) : money(r.opportunity_before || r.product_price)) + '</td>' +
                '<td>' + (showKvmDiff ? cellDiff(r.kvm_before, kvmAfter, true) : (Number(r.kvm_price || 0) > 0 ? money(r.kvm_price) : '—')) + '</td>' +
                // '<td>' + escapeHtml(r.currency || '') + '</td>' +
                '</tr>';
        }
        html += '</tbody></table>';
        tableWrap.innerHTML = html;
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    btnDiff.addEventListener('click', () => run(false));
    btnRun.addEventListener('click', () => run(true));
    btnReset.addEventListener('click', () => {
        lastPayload = null;
        stats.innerHTML = '';
        tableWrap.innerHTML = '<div class="empty">ჯერ შედეგი არაა.</div>';
        statusMsg.className = 'status';
        statusMsg.textContent = 'აირჩიე ფილტრი და დააჭირე „განსხვავების ნახვა“.';
        btnRun.disabled = true;
    });
    onlyDiff.addEventListener('change', () => lastPayload && render(lastPayload));
    hideSame.addEventListener('change', () => lastPayload && render(lastPayload));

    project.addEventListener('change', () => {
        syncTypeAndStatus(null, null);
        onFilterChanged();
    });
    type.addEventListener('change', () => {
        syncingFilters = true;
        const entry = projectEntry();
        const byType = (entry.statusesByType && entry.statusesByType[type.value]) || [];
        const statuses = byType.length ? byType : (entry.statuses || []);
        fillSelect(status, statuses, defaults.status);
        syncingFilters = false;
        onFilterChanged();
    });
    status.addEventListener('change', onFilterChanged);

    syncTypeAndStatus(
        <?= json_encode($filterType, JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode($filterStatus, JSON_UNESCAPED_UNICODE) ?>
    );
})();
</script>
</body>
</html>
<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
