<?php
/**
 * WON (გაყიდული) დილების პროდუქტებზე:
 *   - სტატუსი (_P64GYD) → "გაყიდული"
 *   - მფლობელი დილის / კონტაქტის (და კომპანიის) ველების შევსება
 *
 * პროდუქტის property-ების რეალური კოდები iblock-იდან იკითხება, ამიტომ
 * ownerDeal / OWNER_DEAL / OWNER_PERSONAL_CONTACT — რომელიც არსებობს, ის ივსება.
 *
 * იხ. mergeProdsWithDeals.php (პროდუქტის მიბმა WON დილაზე) და
 * replaceDeletedProdsAllStages.php (წაშლილი პროდუქტის ჩანაცვლება).
 *
 * UI: https://crm.monolith.ge/crm/deal/migration/setSoldStatusOnWonProducts.php
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
define('PROP_STATUS', '_P64GYD');
define('PROP_PROJECT', '__VO9RG4');
define('PROP_TYPE', '__X1GCRZ');
define('D_PROJECT', 'UF_CRM_1779277729207');
define('D_TYPE', 'UF_CRM_1779277898205');
define('TARGET_STATUS', 'გაყიდული');
define('SCAN_CAP', 30000);

/** owner ველების კანდიდატები — რომელიც iblock-ში არსებობს, ის ჩაიწერება */
$ownerCandidates = [
    'deal'    => ['OWNER_DEAL', 'ownerDeal'],
    'contact' => ['OWNER_PERSONAL_CONTACT', 'OWNER_CONTACT', 'ownerContact'],
    'company' => ['OWNER_COMPANY', 'ownerCompany'],
];

$run = isset($_REQUEST['run']) && $_REQUEST['run'] === '1';
$apply = isset($_REQUEST['apply']) && $_REQUEST['apply'] === '1';
$doStatus = !$run || (isset($_REQUEST['do_status']) && $_REQUEST['do_status'] === '1');
$doOwners = !$run || (isset($_REQUEST['do_owners']) && $_REQUEST['do_owners'] === '1');
$overwriteOwners = isset($_REQUEST['overwrite_owners']) && $_REQUEST['overwrite_owners'] === '1';
$filterStage = trim((string)($_REQUEST['stage'] ?? 'won'));
$filterProject = trim((string)($_REQUEST['project'] ?? ''));
$filterType = trim((string)($_REQUEST['type'] ?? ''));
$limit = (int)($_REQUEST['limit'] ?? 500);
if ($limit <= 0 || $limit > 5000) {
    $limit = 500;
}

$stagePresets = [
    'won' => ['label' => 'მხოლოდ გაყიდული (WON)', 'semantics' => ['S']],
    'all' => ['label' => 'ყველა სტადია', 'semantics' => null],
];

function normVal($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    $value = trim((string)$value);
    $value = str_replace(["\xc2\xa0", ' '], '', $value);
    $value = str_replace(',', '.', $value);
    return mb_strtolower($value);
}

function numVal($value)
{
    $value = normVal($value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return round((float)$value, 4);
}

function valsEqual($a, $b)
{
    $na = numVal($a);
    $nb = numVal($b);
    if ($na !== null && $nb !== null) {
        return abs($na - $nb) < 0.0001;
    }
    return normVal($a) === normVal($b);
}

function scalarProp($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
        if (is_array($value)) {
            $value = reset($value);
        }
    }
    return trim((string)$value);
}

/* ---------------------------------------------------------------- სტადიები */

function loadDealCategories()
{
    $categories = [0 => 'ძირითადი'];
    if (class_exists('\Bitrix\Crm\Category\DealCategory')) {
        try {
            $all = \Bitrix\Crm\Category\DealCategory::getAll(true);
            foreach ((array)$all as $cat) {
                $id = (int)($cat['ID'] ?? 0);
                if ($id > 0) {
                    $categories[$id] = (string)($cat['NAME'] ?? ('ვორონკა ' . $id));
                }
            }
        } catch (Throwable $e) {
            // მხოლოდ ძირითადი ვორონკა დარჩება
        }
    }
    return $categories;
}

function loadStageMap(array $categories)
{
    $map = [];
    foreach ($categories as $catId => $catName) {
        $stages = CCrmStatus::GetStatusList($catId > 0 ? 'DEAL_STAGE_' . $catId : 'DEAL_STAGE');
        if (is_array($stages) && $stages) {
            $map[(int)$catId] = $stages;
        }
    }
    return $map;
}

/** 'S' — გაყიდული, 'F' — წარუმატებელი, 'P' — მიმდინარე */
function stageSemantic($stageId, $categoryId)
{
    $stageId = (string)$stageId;
    try {
        $semantic = CCrmDeal::GetSemanticID($stageId, (int)$categoryId);
        if ($semantic === 'S' || $semantic === 'F' || $semantic === 'P') {
            return $semantic;
        }
    } catch (Throwable $e) {
        // fallback ქვემოთ
    }
    $short = strtoupper(strpos($stageId, ':') !== false ? substr($stageId, strpos($stageId, ':') + 1) : $stageId);
    if ($short === 'WON') {
        return 'S';
    }
    if ($short === 'LOSE' || $short === 'APOLOGY') {
        return 'F';
    }
    return 'P';
}

/* -------------------------------------------------------- პროდუქტის property-ები */

/** [CODE => ['ID','PROPERTY_TYPE','USER_TYPE','MULTIPLE','NAME']] */
function loadProductPropertyMeta()
{
    $meta = [];
    $res = CIBlockProperty::GetList([], ['IBLOCK_ID' => PRODUCT_IBLOCK_ID]);
    while ($prop = $res->Fetch()) {
        $code = trim((string)($prop['CODE'] ?? ''));
        if ($code === '') {
            continue;
        }
        $meta[$code] = [
            'ID'            => (int)$prop['ID'],
            'PROPERTY_TYPE' => (string)($prop['PROPERTY_TYPE'] ?? ''),
            'USER_TYPE'     => (string)($prop['USER_TYPE'] ?? ''),
            'MULTIPLE'      => (string)($prop['MULTIPLE'] ?? 'N'),
            'NAME'          => (string)($prop['NAME'] ?? $code),
        ];
    }
    return $meta;
}

/** სტატუსის სიის მნიშვნელობები: [VALUE => ENUM_ID] */
function loadStatusEnum(array $meta)
{
    $options = [];
    $propId = (int)($meta[PROP_STATUS]['ID'] ?? 0);
    if ($propId <= 0) {
        return $options;
    }
    $res = CIBlockPropertyEnum::GetList(['SORT' => 'ASC', 'VALUE' => 'ASC'], ['PROPERTY_ID' => $propId]);
    while ($enum = $res->Fetch()) {
        $value = trim((string)($enum['VALUE'] ?? ''));
        if ($value !== '') {
            $options[$value] = (int)$enum['ID'];
        }
    }
    return $options;
}

/** iblock-ში რეალურად არსებული owner კოდები */
function resolveOwnerCodes(array $candidates, array $meta)
{
    $found = [];
    foreach ($candidates as $entityType => $codes) {
        $found[$entityType] = [];
        foreach ($codes as $code) {
            if (isset($meta[$code])) {
                $found[$entityType][] = $code;
            }
        }
    }
    return $found;
}

/** CRM-bind property-სთვის D_/C_/CO_ პრეფიქსი, სხვა შემთხვევაში სუფთა ID */
function ownerPropValue(array $propMeta, $entityType, $id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return '';
    }
    $userType = strtolower((string)($propMeta['USER_TYPE'] ?? ''));
    $isCrmBind = strpos($userType, 'crm') !== false;
    if (!$isCrmBind) {
        return (string)$id;
    }
    switch ($entityType) {
        case 'deal':
            return 'D_' . $id;
        case 'contact':
            return 'C_' . $id;
        case 'company':
            return 'CO_' . $id;
        default:
            return (string)$id;
    }
}

/** ერთი და იმავე ობიექტზე მიუთითებს თუ არა (D_123 ≡ 123) */
function ownerValueMatches($current, $expected)
{
    $current = scalarProp($current);
    $expected = (string)$expected;
    if ($current === '' ) {
        return false;
    }
    if ($current === $expected) {
        return true;
    }
    if (preg_match('/(\d+)/', $current, $a) && preg_match('/(\d+)/', $expected, $b)) {
        return (int)$a[1] === (int)$b[1];
    }
    return false;
}

/** [productId => [code => value]] */
function loadProductProps(array $productIds, array $codes)
{
    $out = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if (!$ids || !$codes) {
        return $out;
    }

    foreach (array_chunk($ids, 200) as $chunk) {
        $res = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => PRODUCT_IBLOCK_ID, 'ID' => $chunk, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'ACTIVE']
        );
        while ($ob = $res->GetNextElement()) {
            $fields = $ob->GetFields();
            $props = $ob->GetProperties();
            $row = [
                'ID'     => (int)$fields['ID'],
                'NAME'   => (string)($fields['~NAME'] ?? $fields['NAME'] ?? ''),
                'ACTIVE' => (string)($fields['ACTIVE'] ?? ''),
            ];
            foreach ($codes as $code) {
                $row[$code] = isset($props[$code]) ? scalarProp($props[$code]['VALUE'] ?? '') : '';
            }
            $out[(int)$fields['ID']] = $row;
        }
    }
    return $out;
}

/** [dealId => [row, ...]] */
function loadProductRowsForDeals(array $dealIds)
{
    $byDeal = [];
    foreach ($dealIds as $id) {
        $byDeal[(int)$id] = [];
    }
    if (!$byDeal) {
        return [];
    }

    if (class_exists('\Bitrix\Crm\ProductRowTable')) {
        foreach (array_chunk(array_keys($byDeal), 500) as $chunk) {
            $res = \Bitrix\Crm\ProductRowTable::getList([
                'select' => ['ID', 'OWNER_ID', 'PRODUCT_ID', 'PRODUCT_NAME', 'PRICE', 'QUANTITY'],
                'filter' => ['=OWNER_TYPE' => 'D', '@OWNER_ID' => $chunk],
                'order'  => ['ID' => 'ASC'],
            ]);
            while ($row = $res->fetch()) {
                $byDeal[(int)$row['OWNER_ID']][] = $row;
            }
        }
        return $byDeal;
    }

    foreach (array_keys($byDeal) as $dealId) {
        $res = CCrmProductRow::GetList(
            ['ID' => 'ASC'],
            ['OWNER_TYPE' => 'D', 'OWNER_ID' => (int)$dealId],
            false,
            false,
            ['ID', 'PRODUCT_ID', 'PRODUCT_NAME', 'PRICE', 'QUANTITY']
        );
        while ($row = $res->Fetch()) {
            $byDeal[(int)$dealId][] = $row;
        }
    }
    return $byDeal;
}

function resolveDealContactId(array $deal)
{
    $contactId = (int)($deal['CONTACT_ID'] ?? 0);
    if ($contactId > 0) {
        return $contactId;
    }
    if (class_exists('\Bitrix\Crm\Binding\DealContactTable')) {
        $contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs((int)$deal['ID']);
        if (!empty($contactIds)) {
            return (int)$contactIds[0];
        }
    }
    return 0;
}

/** პროექტი → ტიპები (ფილტრის dropdown-ისთვის) */
function loadProjectTypeMap()
{
    $map = [];
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => PRODUCT_IBLOCK_ID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'PROPERTY_' . PROP_PROJECT, 'PROPERTY_' . PROP_TYPE]
    );
    while ($el = $res->Fetch()) {
        $project = trim((string)($el['PROPERTY_' . PROP_PROJECT . '_VALUE'] ?? ''));
        $type = trim((string)($el['PROPERTY_' . PROP_TYPE . '_VALUE'] ?? ''));
        if ($project === '' || $type === '') {
            continue;
        }
        $map[$project][$type] = true;
    }
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($map as $project => $types) {
        $keys = array_keys($types);
        natcasesort($keys);
        $map[$project] = array_values($keys);
    }
    return $map;
}

/* ------------------------------------------------------------------ გაშვება */

$categories = loadDealCategories();
$stageMap = loadStageMap($categories);
$propMeta = loadProductPropertyMeta();
$statusEnum = loadStatusEnum($propMeta);
$ownerCodes = resolveOwnerCodes($ownerCandidates, $propMeta);
$projectTypeMap = loadProjectTypeMap();

$statusIsList = ($propMeta[PROP_STATUS]['PROPERTY_TYPE'] ?? '') === 'L';
$targetStatus = trim((string)($_REQUEST['target_status'] ?? TARGET_STATUS));
if ($statusEnum && !isset($statusEnum[$targetStatus])) {
    $statusKeys = array_keys($statusEnum);
    $targetStatus = isset($statusEnum[TARGET_STATUS]) ? TARGET_STATUS : (string)($statusKeys[0] ?? TARGET_STATUS);
}
$targetEnumId = (int)($statusEnum[$targetStatus] ?? 0);
$statusPropMissing = !isset($propMeta[PROP_STATUS]);
$statusEnumMissing = $statusIsList && $targetEnumId <= 0;

$results = [];
$counts = [
    'deals' => 0,
    'no_rows' => 0,
    'products' => 0,
    'processed' => 0,
    'dead_products' => 0,
    'duplicates' => 0,
    'already_ok' => 0,
    'status_changed' => 0,
    'owners_filled' => 0,
    'updated' => 0,
    'failed' => 0,
    'skipped_over_limit' => 0,
];
$scanCapped = false;

$statusLabels = [
    'would_update' => 'განახლდება (dry run)',
    'updated'      => 'განახლდა',
    'already_ok'   => 'უკვე სწორია',
    'dead_product' => 'პროდუქტი წაშლილია',
    'duplicate'    => 'პროდუქტი სხვა დილაზეც არის',
    'no_rows'      => 'პროდუქტის გარეშე',
    'update_failed' => 'შეცდომა',
];

if ($run) {
    $select = [
        'ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'CONTACT_ID', 'COMPANY_ID',
        D_PROJECT, D_TYPE,
    ];

    $arFilter = ['CHECK_PERMISSIONS' => 'N'];
    if (!isset($stagePresets[$filterStage]) && $filterStage !== '') {
        $arFilter['STAGE_ID'] = $filterStage;
    }
    if ($filterProject !== '') {
        $arFilter[D_PROJECT] = $filterProject;
    }

    // 1. დილების შერჩევა
    $deals = [];
    $res = CCrmDeal::GetListEx(['ID' => 'ASC'], $arFilter, false, false, $select);
    while ($deal = $res->Fetch()) {
        $categoryId = (int)($deal['CATEGORY_ID'] ?? 0);
        $stageId = (string)($deal['STAGE_ID'] ?? '');

        if (isset($stagePresets[$filterStage])) {
            $allowed = $stagePresets[$filterStage]['semantics'];
            if ($allowed !== null && !in_array(stageSemantic($stageId, $categoryId), $allowed, true)) {
                continue;
            }
        }
        if ($filterType !== '' && !valsEqual($deal[D_TYPE] ?? '', $filterType)) {
            continue;
        }

        $deals[(int)$deal['ID']] = $deal;
        $counts['deals']++;
        if ($counts['deals'] >= SCAN_CAP) {
            $scanCapped = true;
            break;
        }
    }

    // 2. პროდუქტის რიგები + პროდუქტების მიმდინარე property-ები
    $rowsByDeal = loadProductRowsForDeals(array_keys($deals));
    $productIds = [];
    foreach ($rowsByDeal as $rows) {
        foreach ($rows as $r) {
            $pid = (int)($r['PRODUCT_ID'] ?? 0);
            if ($pid > 0) {
                $productIds[$pid] = $pid;
            }
        }
    }

    $wantedCodes = [PROP_STATUS, PROP_PROJECT, PROP_TYPE];
    foreach ($ownerCodes as $codes) {
        foreach ($codes as $code) {
            $wantedCodes[] = $code;
        }
    }
    $wantedCodes = array_values(array_unique(array_filter($wantedCodes, function ($code) use ($propMeta) {
        return isset($propMeta[$code]);
    })));
    $productProps = loadProductProps(array_keys($productIds), $wantedCodes);

    // 3. დამუშავება
    $seenProducts = [];
    foreach ($deals as $dealId => $deal) {
        $categoryId = (int)($deal['CATEGORY_ID'] ?? 0);
        $stageId = (string)($deal['STAGE_ID'] ?? '');
        $rows = $rowsByDeal[$dealId] ?? [];

        $base = [
            'deal_id'    => $dealId,
            'title'      => (string)($deal['TITLE'] ?? ''),
            'stage_name' => (string)($stageMap[$categoryId][$stageId] ?? $stageId),
            'category'   => (string)($categories[$categoryId] ?? ('#' . $categoryId)),
        ];

        if (!$rows) {
            $counts['no_rows']++;
            continue;
        }

        $contactId = resolveDealContactId($deal);
        $companyId = (int)($deal['COMPANY_ID'] ?? 0);

        foreach ($rows as $productRow) {
            $pid = (int)($productRow['PRODUCT_ID'] ?? 0);
            $product = $pid > 0 ? ($productProps[$pid] ?? null) : null;

            $row = $base;
            $row['product_id'] = $pid;
            $row['product_name'] = (string)($product['NAME'] ?? ($productRow['PRODUCT_NAME'] ?? ''));

            if ($pid <= 0 || $product === null) {
                $row['status'] = 'dead_product';
                $counts['dead_products']++;
                $results[] = $row;
                continue;
            }

            $counts['products']++;

            if (isset($seenProducts[$pid])) {
                $row['status'] = 'duplicate';
                $row['other_deal_id'] = $seenProducts[$pid];
                $counts['duplicates']++;
                $results[] = $row;
                continue;
            }
            $seenProducts[$pid] = $dealId;

            if ($counts['processed'] >= $limit) {
                $counts['skipped_over_limit']++;
                continue;
            }
            $counts['processed']++;

            $row['status_before'] = (string)($product[PROP_STATUS] ?? '');
            $row['status_after'] = $row['status_before'];

            $updateProps = [];
            $changes = [];

            // სტატუსი
            if ($doStatus && !$statusPropMissing && !$statusEnumMissing) {
                if (!valsEqual($row['status_before'], $targetStatus)) {
                    $updateProps[PROP_STATUS] = $statusIsList ? $targetEnumId : $targetStatus;
                    $row['status_after'] = $targetStatus;
                    $changes[] = 'სტატუსი';
                }
            }

            // owner ველები
            $ownerInfo = [];
            if ($doOwners) {
                $entityIds = [
                    'deal'    => $dealId,
                    'contact' => $contactId,
                    'company' => $companyId,
                ];
                foreach ($ownerCodes as $entityType => $codes) {
                    $entityId = (int)($entityIds[$entityType] ?? 0);
                    if ($entityId <= 0) {
                        continue;
                    }
                    foreach ($codes as $code) {
                        $expected = ownerPropValue($propMeta[$code], $entityType, $entityId);
                        if ($expected === '') {
                            continue;
                        }
                        $current = (string)($product[$code] ?? '');
                        if (ownerValueMatches($current, $expected)) {
                            continue;
                        }
                        if ($current !== '' && !$overwriteOwners) {
                            $ownerInfo[] = $code . ': ' . $current . ' (არ გადაიწერა)';
                            continue;
                        }
                        $updateProps[$code] = $expected;
                        $ownerInfo[] = $code . ' → ' . $expected;
                        $changes[] = $code;
                    }
                }
            }
            $row['owner_info'] = $ownerInfo;

            if (!$updateProps) {
                $row['status'] = 'already_ok';
                $counts['already_ok']++;
                $results[] = $row;
                continue;
            }

            $row['changes'] = $changes;

            if (!$apply) {
                $row['status'] = 'would_update';
                $results[] = $row;
                continue;
            }

            try {
                CIBlockElement::SetPropertyValuesEx($pid, PRODUCT_IBLOCK_ID, $updateProps);
                $row['status'] = 'updated';
                $counts['updated']++;
                if (isset($updateProps[PROP_STATUS])) {
                    $counts['status_changed']++;
                }
                if (count($updateProps) > (isset($updateProps[PROP_STATUS]) ? 1 : 0)) {
                    $counts['owners_filled']++;
                }
            } catch (Throwable $e) {
                $row['status'] = 'update_failed';
                $row['error'] = $e->getMessage();
                $counts['failed']++;
            }
            $results[] = $row;
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>პროდუქტის სტატუსი "გაყიდული" WON დილებზე</title>
    <style>
        :root {
            --bg: #f4f5f7;
            --panel: #fff;
            --text: #00335b;
            --muted: #6b7a8a;
            --danger: #c0392b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .wrap { max-width: 1280px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .sub { color: var(--muted); margin: 0 0 20px; font-size: 14px; }
        .card {
            background: var(--panel);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 28px rgba(0, 51, 91, 0.08);
            margin-bottom: 20px;
        }
        .row { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
        label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; }
        select, button { height: 40px; border-radius: 8px; font-size: 14px; }
        select {
            min-width: 190px;
            border: 1px solid #d5dce3;
            padding: 0 10px;
            background: #fff;
            color: var(--text);
        }
        .check { display: flex; align-items: center; gap: 8px; height: 40px; font-size: 14px; }
        button {
            border: 0;
            padding: 0 18px;
            background: var(--text);
            color: #fff;
            cursor: pointer;
            font-weight: 600;
        }
        button.apply { background: #0e7c66; }
        .counts { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        .chip { background: #eef3f7; border-radius: 999px; padding: 6px 12px; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f4; vertical-align: top; }
        th { font-size: 12px; color: var(--muted); }
        .st-would_update, .st-updated { color: #0e7c66; font-weight: 600; }
        .st-already_ok, .st-duplicate { color: var(--muted); }
        .st-dead_product, .st-update_failed { color: var(--danger); font-weight: 600; }
        .muted { color: var(--muted); font-size: 12px; }
        .warn { color: var(--danger); font-size: 13px; margin-top: 8px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>პროდუქტის სტატუსი "<?= htmlspecialchars($targetStatus) ?>" + მფლობელის ველები</h1>
    <p class="sub">
        WON (გაყიდული) სტადიის დილებზე მიბმულ პროდუქტებს უსეტავს სტატუსს "<?= htmlspecialchars($targetStatus) ?>"
        და ავსებს მფლობელი დილის / კონტაქტის / კომპანიის ველებს.
        პროდუქტის მიბმისთვის იხ. <a href="mergeProdsWithDeals.php">mergeProdsWithDeals.php</a>,
        წაშლილი პროდუქტების ჩასანაცვლებლად — <a href="replaceDeletedProdsAllStages.php">replaceDeletedProdsAllStages.php</a>.
    </p>

    <?php if ($statusPropMissing): ?>
        <p class="warn">iblock <?= (int)PRODUCT_IBLOCK_ID ?>-ში property <?= htmlspecialchars(PROP_STATUS) ?> ვერ მოიძებნა — სტატუსი არ ჩაიწერება.</p>
    <?php elseif ($statusEnumMissing): ?>
        <p class="warn">სტატუსის სიაში "<?= htmlspecialchars($targetStatus) ?>" ვერ მოიძებნა — სტატუსი არ ჩაიწერება.</p>
    <?php endif; ?>

    <form class="card" method="get" id="fix-form">
        <input type="hidden" name="run" value="1">
        <div class="row">
            <div>
                <label for="stage">სტადია</label>
                <select name="stage" id="stage">
                    <?php foreach ($stagePresets as $presetKey => $preset): ?>
                        <option value="<?= htmlspecialchars($presetKey) ?>" <?= $filterStage === $presetKey ? 'selected' : '' ?>>
                            <?= htmlspecialchars($preset['label']) ?>
                        </option>
                    <?php endforeach; ?>
                    <?php foreach ($stageMap as $catId => $stages): ?>
                        <optgroup label="<?= htmlspecialchars((string)($categories[$catId] ?? ('#' . $catId))) ?>">
                            <?php foreach ($stages as $stageId => $stageName):
                                $sem = stageSemantic($stageId, $catId);
                                $semMark = $sem === 'S' ? ' — გაყიდული' : ($sem === 'F' ? ' — წარუმატებელი' : '');
                            ?>
                                <option value="<?= htmlspecialchars((string)$stageId) ?>" <?= $filterStage === (string)$stageId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$stageName . $semMark) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="target_status">სტატუსი</label>
                <select name="target_status" id="target_status">
                    <?php if (!$statusEnum): ?>
                        <option value="<?= htmlspecialchars($targetStatus) ?>" selected><?= htmlspecialchars($targetStatus) ?></option>
                    <?php else: ?>
                        <?php foreach (array_keys($statusEnum) as $statusOption): ?>
                            <option value="<?= htmlspecialchars($statusOption) ?>" <?= $targetStatus === $statusOption ? 'selected' : '' ?>>
                                <?= htmlspecialchars($statusOption) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <label for="project">პროექტი</label>
                <select name="project" id="project">
                    <option value="">ყველა</option>
                    <?php foreach (array_keys($projectTypeMap) as $projectName): ?>
                        <option value="<?= htmlspecialchars($projectName) ?>" <?= $filterProject === $projectName ? 'selected' : '' ?>>
                            <?= htmlspecialchars($projectName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="type">ფართის ტიპი</label>
                <select name="type" id="type">
                    <option value="">ყველა</option>
                </select>
            </div>
            <div>
                <label for="limit">ლიმიტი</label>
                <select name="limit" id="limit">
                    <?php foreach ([100, 200, 500, 1000, 2000, 5000] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row" style="margin-top:12px">
            <label class="check">
                <input type="checkbox" name="do_status" value="1" <?= $doStatus ? 'checked' : '' ?>>
                სტატუსის სეტვა
            </label>
            <label class="check">
                <input type="checkbox" name="do_owners" value="1" <?= $doOwners ? 'checked' : '' ?>>
                მფლობელის ველების შევსება
            </label>
            <label class="check">
                <input type="checkbox" name="overwrite_owners" value="1" <?= $overwriteOwners ? 'checked' : '' ?>>
                სხვა მნიშვნელობის გადაწერა
            </label>
            <label class="check">
                <input type="checkbox" name="apply" value="1" <?= $apply ? 'checked' : '' ?>>
                რეალურად ჩაწერა
            </label>
            <button type="submit" id="run-btn">ნახვა</button>
        </div>
        <p class="muted" style="margin-top:10px">
            მფლობელის ველები iblock-იდან:
            <?php
            $foundCodes = [];
            foreach ($ownerCodes as $entityType => $codes) {
                foreach ($codes as $code) {
                    $foundCodes[] = $entityType . ' → ' . $code;
                }
            }
            echo $foundCodes ? htmlspecialchars(implode(' · ', $foundCodes)) : 'ვერ მოიძებნა';
            ?>
        </p>
        <p class="warn" id="apply-warn" style="<?= $apply ? '' : 'display:none' ?>">
            Apply ჩართულია: პროდუქტებზე რეალურად ჩაიწერება სტატუსი და მფლობელის ველები.
        </p>
    </form>

    <?php if ($run): ?>
        <div class="card">
            <div class="counts">
                <span class="chip">რეჟიმი: <?= $apply ? 'APPLY' : 'DRY RUN' ?></span>
                <span class="chip">სტადია: <?= htmlspecialchars($stagePresets[$filterStage]['label'] ?? $filterStage) ?></span>
                <span class="chip">პროექტი: <?= htmlspecialchars($filterProject !== '' ? $filterProject : 'ყველა') ?></span>
                <span class="chip">ტიპი: <?= htmlspecialchars($filterType !== '' ? $filterType : 'ყველა') ?></span>
                <span class="chip">დილები: <?= (int)$counts['deals'] ?></span>
                <span class="chip">პროდუქტის გარეშე: <?= (int)$counts['no_rows'] ?></span>
                <span class="chip">პროდუქტები: <?= (int)$counts['products'] ?></span>
                <span class="chip">წაშლილი პროდუქტი: <?= (int)$counts['dead_products'] ?></span>
                <span class="chip">დუბლი: <?= (int)$counts['duplicates'] ?></span>
                <span class="chip">დამუშავდა: <?= (int)$counts['processed'] ?></span>
                <span class="chip">უკვე სწორია: <?= (int)$counts['already_ok'] ?></span>
                <span class="chip">განახლდა: <?= (int)$counts['updated'] ?></span>
                <span class="chip">სტატუსი შეიცვალა: <?= (int)$counts['status_changed'] ?></span>
                <span class="chip">owner შეივსო: <?= (int)$counts['owners_filled'] ?></span>
                <span class="chip">შეცდომა: <?= (int)$counts['failed'] ?></span>
            </div>
            <?php if ($counts['skipped_over_limit'] > 0): ?>
                <p class="warn">ლიმიტს გარეთ დარჩა <?= (int)$counts['skipped_over_limit'] ?> პროდუქტი — გაუშვი თავიდან.</p>
            <?php endif; ?>
            <?php if ($scanCapped): ?>
                <p class="warn">სკანირება შეჩერდა <?= SCAN_CAP ?> დილაზე — დაავიწროვე ფილტრი.</p>
            <?php endif; ?>

            <?php if (!$results): ?>
                <p class="muted">შედეგი ცარიელია.</p>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>დილი</th>
                        <th>სტადია</th>
                        <th>პროდუქტი</th>
                        <th>სტატუსი</th>
                        <th>მფლობელის ველები</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $r):
                        $st = (string)($r['status'] ?? '');
                    ?>
                        <tr>
                            <td>
                                <a href="/crm/deal/details/<?= (int)$r['deal_id'] ?>/" target="_blank">#<?= (int)$r['deal_id'] ?></a>
                                <div><?= htmlspecialchars((string)($r['title'] ?? '')) ?></div>
                            </td>
                            <td>
                                <?= htmlspecialchars((string)($r['stage_name'] ?? '')) ?>
                                <div class="muted"><?= htmlspecialchars((string)($r['category'] ?? '')) ?></div>
                            </td>
                            <td>
                                <?php if ((int)($r['product_id'] ?? 0) > 0): ?>
                                    #<?= (int)$r['product_id'] ?>
                                <?php endif; ?>
                                <?= htmlspecialchars((string)($r['product_name'] ?? '')) ?>
                                <div class="st-<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($statusLabels[$st] ?? $st) ?></div>
                                <?php if (!empty($r['other_deal_id'])): ?>
                                    <div class="muted">დამუშავდა დილაზე #<?= (int)$r['other_deal_id'] ?></div>
                                <?php endif; ?>
                                <?php if (!empty($r['error'])): ?>
                                    <div class="warn"><?= htmlspecialchars((string)$r['error']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (array_key_exists('status_before', $r)): ?>
                                    <?= htmlspecialchars((string)$r['status_before'] !== '' ? (string)$r['status_before'] : '—') ?>
                                    <?php if ((string)($r['status_after'] ?? '') !== (string)$r['status_before']): ?>
                                        → <strong><?= htmlspecialchars((string)$r['status_after']) ?></strong>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['owner_info'])): ?>
                                    <?php foreach ($r['owner_info'] as $info): ?>
                                        <div class="muted"><?= htmlspecialchars((string)$info) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<script>
    const map = <?= json_encode($projectTypeMap, JSON_UNESCAPED_UNICODE) ?>;
    const projectEl = document.getElementById('project');
    const typeEl = document.getElementById('type');
    const selectedType = <?= json_encode($filterType, JSON_UNESCAPED_UNICODE) ?>;
    const applyBox = document.querySelector('input[name="apply"]');
    const runBtn = document.getElementById('run-btn');
    const applyWarn = document.getElementById('apply-warn');

    function allTypes() {
        const set = new Set();
        Object.keys(map).forEach(function (p) {
            (map[p] || []).forEach(function (t) { set.add(t); });
        });
        return Array.from(set).sort(function (a, b) { return a.localeCompare(b, 'ka'); });
    }

    function fillTypes() {
        const project = projectEl.value;
        const types = project ? (map[project] || []) : allTypes();
        typeEl.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'ყველა';
        typeEl.appendChild(placeholder);
        types.forEach(function (t) {
            const opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            if (t === selectedType) opt.selected = true;
            typeEl.appendChild(opt);
        });
    }

    function syncApplyUi() {
        const on = applyBox.checked;
        runBtn.textContent = on ? 'გაშვება' : 'ნახვა';
        runBtn.classList.toggle('apply', on);
        applyWarn.style.display = on ? '' : 'none';
    }

    projectEl.addEventListener('change', fillTypes);
    applyBox.addEventListener('change', syncApplyUi);
    fillTypes();
    syncApplyUi();
</script>
</body>
</html>
