<?php
/**
 * წაშლილი პროდუქტების ჩანაცვლება ყველა სტადიის დილაზე (WON-ის გარდა, default).
 * ეძებს დილებს, რომელთა პროდუქტის რიგიც მკვდარია (ელემენტი წაშლილია /
 * დეაქტივირებულია / სხვა კატალოგშია / PRODUCT_ID ცარიელია) და რიგს ცვლის
 * რეალურად არსებული პროდუქტით — მატჩი პროექტი + ფართის ტიპი + სექტორი /
 * ბლოკი / სართული / ნომერი.
 * დილის თანხა არ იცვლება.
 *
 * იხ. mergeProdsWithDeals.php — იგივე ლოგიკა მხოლოდ WON-ზე და პროდუქტის
 * მიბმაც იმ დილებზე, რომლებსაც პროდუქტი საერთოდ არ აქვთ.
 *
 * UI: https://crm.monolith.ge/crm/deal/migration/replaceDeletedProdsAllStages.php
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
define('PROP_OWNER_DEAL', 'ownerDeal');
define('PROP_OWNER_CONTACT', 'ownerContact');
define('PROP_OWNER_COMPANY', 'ownerCompany');
define('D_PROJECT', 'UF_CRM_1779277729207');
define('D_TYPE', 'UF_CRM_1779277898205');
define('D_SECTOR', 'UF_CRM_1781768590754');
define('D_BLOCK', 'UF_CRM_1779277644355');
define('D_FLOOR', 'UF_CRM_1779277828822');
define('D_NUMBER', 'UF_CRM_1779277613798');

// პროდუქტის property კოდები
define('P_PROJECT', '__VO9RG4');
define('P_TYPE', '__X1GCRZ');
define('P_SECTOR', '_3BU0JH');
define('P_BLOCK', '_L24CUB');
define('P_FLOOR', '_FTRIDL');
define('P_NUMBER', '__6KWOWZ');

define('SCAN_CAP', 30000); // მაქს. რამდენი დილა დასკანირდეს ერთ გაშვებაზე

$run = isset($_REQUEST['run']) && $_REQUEST['run'] === '1';
$apply = isset($_REQUEST['apply']) && $_REQUEST['apply'] === '1';
$keepAmount = !$run || (isset($_REQUEST['keep_amount']) && $_REQUEST['keep_amount'] === '1');
/**
 * სტადიის მზა ნაკრებები. semantics: null = ყველა,
 * 'P' — მიმდინარე, 'S' — გაყიდული (WON), 'F' — წარუმატებელი/გაუქმებული.
 */
$stagePresets = [
    'open' => [
        'label' => 'მიმდინარე სტადიები (გაყიდულისა და წარუმატებლის გარდა)',
        'semantics' => ['P'],
    ],
    'not_won' => [
        'label' => 'ყველა სტადია WON-ის გარდა (წარუმატებლების ჩათვლით)',
        'semantics' => ['P', 'F'],
    ],
    'lost' => [
        'label' => 'მხოლოდ წარუმატებელი / გაუქმებული',
        'semantics' => ['F'],
    ],
    'all' => [
        'label' => 'ყველა სტადია (WON-ის ჩათვლით)',
        'semantics' => null,
    ],
];

$filterStage = trim((string)($_REQUEST['stage'] ?? 'open'));
$filterProject = trim((string)($_REQUEST['project'] ?? ''));
$filterType = trim((string)($_REQUEST['type'] ?? ''));
$limit = (int)($_REQUEST['limit'] ?? 500);
if ($limit <= 0 || $limit > 5000) {
    $limit = 500;
}

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

/** ერთი კომპონენტი მატჩის გასაღებისთვის — რიცხვი და ტექსტი ერთნაირად ნორმალდება */
function keyPart($value)
{
    $num = numVal($value);
    return $num !== null ? rtrim(rtrim(sprintf('%.4f', $num), '0'), '.') : normVal($value);
}

function matchKey($project, $type, $sector, $block, $floor, $number)
{
    return implode('|', [
        keyPart($project),
        keyPart($type),
        keyPart($sector),
        keyPart($block),
        keyPart($floor),
        keyPart($number),
    ]);
}

function formatCrmBind($entityType, $id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return '';
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

function stageEntityId($categoryId)
{
    $categoryId = (int)$categoryId;
    return $categoryId > 0 ? 'DEAL_STAGE_' . $categoryId : 'DEAL_STAGE';
}

/** [categoryId => [stageId => name]] */
function loadStageMap(array $categories)
{
    $map = [];
    foreach ($categories as $catId => $catName) {
        $stages = CCrmStatus::GetStatusList(stageEntityId($catId));
        if (!is_array($stages) || !$stages) {
            continue;
        }
        $map[(int)$catId] = $stages;
    }
    return $map;
}

/**
 * სტადიის სემანტიკა: 'S' — მოგებული (გაყიდული), 'F' — წარუმატებელი/გაუქმებული,
 * 'P' — მიმდინარე.
 */
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

/* ------------------------------------------------------------ პროდუქტების ინდექსი */

/**
 * კატალოგის ერთჯერადი წაკითხვა:
 *  - $index[matchKey] = [product, ...]
 *  - $projectTypeMap[project] = [type, ...]
 */
function loadProductIndex()
{
    $index = [];
    $projectTypeMap = [];
    $seen = [];

    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => PRODUCT_IBLOCK_ID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        [
            'ID', 'NAME', 'IBLOCK_ID',
            'PROPERTY_' . P_PROJECT,
            'PROPERTY_' . P_TYPE,
            'PROPERTY_' . P_SECTOR,
            'PROPERTY_' . P_BLOCK,
            'PROPERTY_' . P_FLOOR,
            'PROPERTY_' . P_NUMBER,
        ]
    );

    while ($el = $res->Fetch()) {
        $id = (int)$el['ID'];
        if ($id <= 0 || isset($seen[$id])) {
            continue; // multi-value property-ს გამო დუბლი
        }
        $seen[$id] = true;

        $product = [
            'ID'      => $id,
            'NAME'    => (string)$el['NAME'],
            'project' => trim((string)($el['PROPERTY_' . P_PROJECT . '_VALUE'] ?? '')),
            'type'    => trim((string)($el['PROPERTY_' . P_TYPE . '_VALUE'] ?? '')),
            'sector'  => trim((string)($el['PROPERTY_' . P_SECTOR . '_VALUE'] ?? '')),
            'block'   => trim((string)($el['PROPERTY_' . P_BLOCK . '_VALUE'] ?? '')),
            'floor'   => trim((string)($el['PROPERTY_' . P_FLOOR . '_VALUE'] ?? '')),
            'number'  => trim((string)($el['PROPERTY_' . P_NUMBER . '_VALUE'] ?? '')),
        ];

        if ($product['project'] !== '' && $product['type'] !== '') {
            if (!isset($projectTypeMap[$product['project']])) {
                $projectTypeMap[$product['project']] = [];
            }
            $projectTypeMap[$product['project']][$product['type']] = true;
        }

        $key = matchKey(
            $product['project'],
            $product['type'],
            $product['sector'],
            $product['block'],
            $product['floor'],
            $product['number']
        );
        if (!isset($index[$key])) {
            $index[$key] = [];
        }
        $index[$key][] = $product;
    }

    ksort($projectTypeMap, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($projectTypeMap as $project => $types) {
        $keys = array_keys($types);
        natcasesort($keys);
        $projectTypeMap[$project] = array_values($keys);
    }

    return [$index, $projectTypeMap];
}

/** კატალოგში არსებული ელემენტების მდგომარეობა (წაშლილი აქ არ დაბრუნდება) */
function loadProductElementsState(array $productIds)
{
    $ids = [];
    foreach ($productIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return [];
    }

    $state = [];
    foreach (array_chunk(array_values($ids), 500) as $chunk) {
        $res = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['ID' => $chunk, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'ACTIVE', 'NAME']
        );
        while ($el = $res->Fetch()) {
            $state[(int)$el['ID']] = [
                'IBLOCK_ID' => (int)$el['IBLOCK_ID'],
                'ACTIVE'    => (string)$el['ACTIVE'],
                'NAME'      => (string)$el['NAME'],
            ];
        }
    }
    return $state;
}

/* --------------------------------------------------------- დილის პროდუქტის რიგები */

/** [dealId => [row, ...]] — bulk, რომ ყოველ დილაზე ცალკე query არ წავიდეს */
function loadProductRowsForDeals(array $dealIds)
{
    $byDeal = [];
    foreach ($dealIds as $id) {
        $byDeal[(int)$id] = [];
    }
    if (!$byDeal) {
        return [];
    }

    $chunks = array_chunk(array_keys($byDeal), 500);

    if (class_exists('\Bitrix\Crm\ProductRowTable')) {
        foreach ($chunks as $chunk) {
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
            $row['OWNER_ID'] = $dealId;
            $byDeal[(int)$dealId][] = $row;
        }
    }
    return $byDeal;
}

/** რიგები ცოცხალ / მკვდარ ჯგუფებად */
function splitRows(array $rows, array $state)
{
    $live = [];
    $dead = [];
    foreach ($rows as $row) {
        $pid = (int)($row['PRODUCT_ID'] ?? 0);
        $info = $state[$pid] ?? null;

        if ($pid <= 0) {
            $row['dead_reason'] = 'პროდუქტის გარეშე რიგი';
        } elseif ($info === null) {
            $row['dead_reason'] = 'პროდუქტი წაშლილია';
        } elseif ($info['ACTIVE'] !== 'Y') {
            $row['dead_reason'] = 'პროდუქტი დეაქტივირებულია';
        } elseif ($info['IBLOCK_ID'] !== (int)PRODUCT_IBLOCK_ID) {
            $row['dead_reason'] = 'პროდუქტი სხვა კატალოგშია (iblock ' . $info['IBLOCK_ID'] . ')';
        } else {
            $row['live_info'] = 'iblock ' . $info['IBLOCK_ID'] . ' / ' . $info['NAME'];
            $live[] = $row;
            continue;
        }
        $dead[] = $row;
    }
    return [$live, $dead];
}

function dealCriteria(array $deal)
{
    return [
        'project' => trim((string)($deal[D_PROJECT] ?? '')),
        'type'    => trim((string)($deal[D_TYPE] ?? '')),
        'sector'  => trim((string)($deal[D_SECTOR] ?? '')),
        'block'   => trim((string)($deal[D_BLOCK] ?? '')),
        'floor'   => trim((string)($deal[D_FLOOR] ?? '')),
        'number'  => trim((string)($deal[D_NUMBER] ?? '')),
    ];
}

function productBasePrice($productId)
{
    $priceRow = CPrice::GetBasePrice((int)$productId);
    return (float)($priceRow['PRICE'] ?? 0);
}

function restoreDealOpportunity($dealId, $opportunity, $currencyId, $manual)
{
    $crmDeal = new CCrmDeal(false);
    $fields = [
        'OPPORTUNITY' => $opportunity,
    ];
    if ($manual) {
        $fields['IS_MANUAL_OPPORTUNITY'] = 'Y';
    }
    if ($currencyId !== '') {
        $fields['CURRENCY_ID'] = $currencyId;
    }
    return (bool)$crmDeal->Update($dealId, $fields);
}

/* ------------------------------------------------------------------ გაშვება */

$categories = loadDealCategories();
$stageMap = loadStageMap($categories);
[$productIndex, $projectTypeMap] = loadProductIndex();

$results = [];
$counts = [
    'scanned' => 0,
    'with_dead' => 0,
    'processed' => 0,
    'missing_fields' => 0,
    'not_found' => 0,
    'ambiguous' => 0,
    'replaced' => 0,
    'failed' => 0,
    'skipped_over_limit' => 0,
];
$scanCapped = false;

$statusLabels = [
    'missing_fields'  => 'აკლია ველი',
    'not_found'       => 'პროდუქტი ვერ მოიძებნა',
    'ambiguous'       => 'რამდენიმე მატჩი',
    'would_replace'   => 'ჩანაცვლდება (dry run)',
    'replaced'        => 'ჩანაცვლდა',
    'save_failed'     => 'შეცდომა',
];

if ($run) {
    $select = [
        'ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'CONTACT_ID', 'COMPANY_ID',
        'OPPORTUNITY', 'CURRENCY_ID', 'IS_MANUAL_OPPORTUNITY',
        D_PROJECT, D_TYPE, D_SECTOR, D_BLOCK, D_FLOOR, D_NUMBER,
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
        $counts['scanned']++;
        if ($counts['scanned'] >= SCAN_CAP) {
            $scanCapped = true;
            break;
        }
    }

    // 2. პროდუქტის რიგები + ელემენტების მდგომარეობა bulk-ად
    $rowsByDeal = loadProductRowsForDeals(array_keys($deals));
    $allProductIds = [];
    foreach ($rowsByDeal as $rows) {
        foreach ($rows as $row) {
            $allProductIds[] = (int)($row['PRODUCT_ID'] ?? 0);
        }
    }
    $state = loadProductElementsState($allProductIds);

    // 3. მხოლოდ ის დილები, რომლებსაც მკვდარი რიგი აქვთ
    foreach ($deals as $dealId => $deal) {
        $rows = $rowsByDeal[$dealId] ?? [];
        if (!$rows) {
            continue;
        }
        [$liveRows, $deadRows] = splitRows($rows, $state);
        if (!$deadRows) {
            continue;
        }

        $counts['with_dead']++;
        if ($counts['processed'] >= $limit) {
            $counts['skipped_over_limit']++;
            continue;
        }
        $counts['processed']++;

        $categoryId = (int)($deal['CATEGORY_ID'] ?? 0);
        $stageId = (string)($deal['STAGE_ID'] ?? '');
        $criteria = dealCriteria($deal);

        $row = [
            'deal_id'    => $dealId,
            'title'      => (string)($deal['TITLE'] ?? ''),
            'stage_id'   => $stageId,
            'stage_name' => (string)($stageMap[$categoryId][$stageId] ?? $stageId),
            'category'   => (string)($categories[$categoryId] ?? ('#' . $categoryId)),
            'currency'   => (string)($deal['CURRENCY_ID'] ?? ''),
            'opportunity_before' => (float)($deal['OPPORTUNITY'] ?? 0),
            'criteria'   => $criteria,
            'live_rows'  => $liveRows,
            'dead_rows'  => $deadRows,
            'status'     => '',
        ];

        foreach (['project', 'type', 'sector', 'block', 'floor', 'number'] as $key) {
            if ($criteria[$key] === '') {
                $row['status'] = 'missing_fields';
                $counts['missing_fields']++;
                $results[] = $row;
                continue 2;
            }
        }

        $key = matchKey(
            $criteria['project'],
            $criteria['type'],
            $criteria['sector'],
            $criteria['block'],
            $criteria['floor'],
            $criteria['number']
        );
        $matches = $productIndex[$key] ?? [];

        if (count($matches) === 0) {
            $row['status'] = 'not_found';
            $counts['not_found']++;
            $results[] = $row;
            continue;
        }
        if (count($matches) > 1) {
            $row['status'] = 'ambiguous';
            $row['matches'] = $matches;
            $counts['ambiguous']++;
            $results[] = $row;
            continue;
        }

        $product = $matches[0];
        $product['PRICE'] = productBasePrice($product['ID']);
        $row['product'] = $product;

        $originalOpportunity = (float)($deal['OPPORTUNITY'] ?? 0);
        $currencyId = (string)($deal['CURRENCY_ID'] ?? '');
        $wasManual = ((string)($deal['IS_MANUAL_OPPORTUNITY'] ?? '')) === 'Y';

        // ფასი: ერთადერთი მკვდარი რიგი — დილის თანხიდან (როგორც mergeProdsWithDeals-ში);
        // თუ დილაზე სხვა ცოცხალი რიგებიც არის — მკვდარი რიგის საკუთარი ფასი რჩება.
        $deadPrice = (float)($deadRows[0]['PRICE'] ?? 0);
        $deadQty = (float)($deadRows[0]['QUANTITY'] ?? 1);
        if ($deadQty <= 0) {
            $deadQty = 1;
        }

        if (!$liveRows && count($deadRows) === 1 && $originalOpportunity > 0) {
            $rowPrice = round($originalOpportunity / $deadQty, 2);
            $row['price_source'] = 'deal';
        } elseif ($deadPrice > 0) {
            $rowPrice = round($deadPrice, 2);
            $row['price_source'] = 'old_row';
        } else {
            $rowPrice = (float)$product['PRICE'];
            $row['price_source'] = 'product';
        }
        $row['row_price'] = $rowPrice;
        $row['row_qty'] = $deadQty;

        // ახალი რიგების ნაკრები: ცოცხლები უცვლელად + ჩანაცვლებული
        $newRows = [];
        $alreadyHasProduct = false;
        foreach ($liveRows as $lr) {
            if ((int)$lr['PRODUCT_ID'] === (int)$product['ID']) {
                $alreadyHasProduct = true;
            }
            $newRows[] = [
                'ID'           => (int)$lr['ID'],
                'PRODUCT_ID'   => (int)$lr['PRODUCT_ID'],
                'PRODUCT_NAME' => (string)$lr['PRODUCT_NAME'],
                'PRICE'        => (float)$lr['PRICE'],
                'QUANTITY'     => (float)$lr['QUANTITY'],
            ];
        }
        if (!$alreadyHasProduct) {
            $newRows[] = [
                'PRODUCT_ID'   => (int)$product['ID'],
                'PRODUCT_NAME' => (string)$product['NAME'],
                'PRICE'        => $rowPrice,
                'QUANTITY'     => $deadQty,
            ];
        }
        $row['dropped_dead_only'] = $alreadyHasProduct;

        if (!$apply) {
            $row['status'] = 'would_replace';
            $results[] = $row;
            continue;
        }

        $saved = CCrmDeal::SaveProductRows($dealId, $newRows);
        if (!$saved) {
            $row['status'] = 'save_failed';
            $counts['failed']++;
            $results[] = $row;
            continue;
        }

        $manual = $wasManual || $keepAmount;
        $row['opportunity_restored'] = restoreDealOpportunity($dealId, $originalOpportunity, $currencyId, $manual);
        $row['manual_opportunity_set'] = $manual;

        $verifyRes = CCrmDeal::GetListEx(
            [],
            ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID', 'OPPORTUNITY', 'IS_MANUAL_OPPORTUNITY']
        );
        $verify = $verifyRes ? $verifyRes->Fetch() : false;
        $row['opportunity_after'] = (float)($verify['OPPORTUNITY'] ?? 0);
        $row['is_manual_opportunity'] = (string)($verify['IS_MANUAL_OPPORTUNITY'] ?? '');

        if (!$alreadyHasProduct) {
            $contactId = resolveDealContactId($deal);
            $companyId = (int)($deal['COMPANY_ID'] ?? 0);
            $ownerProps = [PROP_OWNER_DEAL => formatCrmBind('deal', $dealId)];
            if ($contactId > 0) {
                $ownerProps[PROP_OWNER_CONTACT] = formatCrmBind('contact', $contactId);
            }
            if ($companyId > 0) {
                $ownerProps[PROP_OWNER_COMPANY] = formatCrmBind('company', $companyId);
            }
            try {
                CIBlockElement::SetPropertyValuesEx($product['ID'], PRODUCT_IBLOCK_ID, $ownerProps);
            } catch (Throwable $e) {
                $row['owner_sync_error'] = $e->getMessage();
            }
        }

        $row['status'] = 'replaced';
        $counts['replaced']++;
        $results[] = $row;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>წაშლილი პროდუქტების ჩანაცვლება — ყველა სტადია</title>
    <style>
        :root {
            --bg: #f4f5f7;
            --panel: #fff;
            --text: #00335b;
            --muted: #6b7a8a;
            --accent: #72c4b1;
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
        select, button {
            height: 40px;
            border-radius: 8px;
            font-size: 14px;
        }
        select {
            min-width: 200px;
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
        .chip {
            background: #eef3f7;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 13px;
        }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f4; vertical-align: top; }
        th { font-size: 12px; color: var(--muted); }
        .st-would_replace, .st-replaced { color: #0e7c66; font-weight: 600; }
        .st-not_found, .st-missing_fields, .st-save_failed, .st-ambiguous { color: var(--danger); font-weight: 600; }
        .dead { color: var(--danger); font-size: 12px; }
        .price, .muted { color: var(--muted); font-size: 12px; }
        .warn { color: var(--danger); font-size: 13px; margin-top: 8px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>წაშლილი პროდუქტების ჩანაცვლება — ყველა სტადია</h1>
    <p class="sub">
        ეძებს დილებს, რომელთა პროდუქტის რიგიც მკვდარია (პროდუქტი წაშლილია, დეაქტივირებულია, სხვა
        კატალოგშია ან PRODUCT_ID ცარიელია) და ცვლის რეალურად არსებული პროდუქტით — მატჩი პროექტი +
        ფართის ტიპი + სექტორი / ბლოკი / სართული / ნომერი. დილის თანხა უცვლელი რჩება.
        WON დილებისთვის იხ. <a href="mergeProdsWithDeals.php">mergeProdsWithDeals.php</a>.
    </p>

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
            <label class="check">
                <input type="checkbox" name="keep_amount" value="1" <?= $keepAmount ? 'checked' : '' ?>>
                თანხის დაფიქსირება
            </label>
            <label class="check">
                <input type="checkbox" name="apply" value="1" <?= $apply ? 'checked' : '' ?>>
                რეალურად ჩანაცვლება
            </label>
            <button type="submit" id="run-btn">ნახვა</button>
        </div>
        <p class="warn" id="apply-warn" style="<?= $apply ? '' : 'display:none' ?>">
            Apply ჩართულია: მკვდარი პროდუქტის რიგები ჩანაცვლდება რეალური პროდუქტით.
        </p>
    </form>

    <?php if ($run): ?>
        <div class="card">
            <div class="counts">
                <span class="chip">რეჟიმი: <?= $apply ? 'APPLY' : 'DRY RUN' ?></span>
                <span class="chip">სტადია: <?= htmlspecialchars($stagePresets[$filterStage]['label'] ?? $filterStage) ?></span>
                <span class="chip">პროექტი: <?= htmlspecialchars($filterProject !== '' ? $filterProject : 'ყველა') ?></span>
                <span class="chip">ტიპი: <?= htmlspecialchars($filterType !== '' ? $filterType : 'ყველა') ?></span>
                <span class="chip">დასკანირდა: <?= (int)$counts['scanned'] ?></span>
                <span class="chip">წაშლილი პროდუქტით: <?= (int)$counts['with_dead'] ?></span>
                <span class="chip">დამუშავდა: <?= (int)$counts['processed'] ?></span>
                <span class="chip">აკლია ველი: <?= (int)$counts['missing_fields'] ?></span>
                <span class="chip">ვერ მოიძებნა: <?= (int)$counts['not_found'] ?></span>
                <span class="chip">რამდენიმე მატჩი: <?= (int)$counts['ambiguous'] ?></span>
                <span class="chip">ჩანაცვლდა: <?= (int)$counts['replaced'] ?></span>
                <span class="chip">შეცდომა: <?= (int)$counts['failed'] ?></span>
            </div>
            <?php if ($counts['skipped_over_limit'] > 0): ?>
                <p class="warn">ლიმიტს გარეთ დარჩა <?= (int)$counts['skipped_over_limit'] ?> დილა — გაუშვი თავიდან.</p>
            <?php endif; ?>
            <?php if ($scanCapped): ?>
                <p class="warn">სკანირება შეჩერდა <?= SCAN_CAP ?> დილაზე — დაავიწროვე ფილტრი.</p>
            <?php endif; ?>

            <?php if (!$results): ?>
                <p class="muted">წაშლილპროდუქტიანი დილა ვერ მოიძებნა.</p>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>დილი</th>
                        <th>სტადია</th>
                        <th>სტატუსი</th>
                        <th>პროექტი / ტიპი / სექტორი / ბლოკი / სართული / ნომერი</th>
                        <th>ახალი პროდუქტი</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $r):
                        $c = $r['criteria'] ?? [];
                        $p = $r['product'] ?? null;
                        $st = (string)($r['status'] ?? '');
                    ?>
                        <tr>
                            <td>
                                <a href="/crm/deal/details/<?= (int)$r['deal_id'] ?>/" target="_blank">#<?= (int)$r['deal_id'] ?></a>
                                <div><?= htmlspecialchars((string)($r['title'] ?? '')) ?></div>
                                <div class="price">
                                    თანხა: <?= number_format((float)($r['opportunity_before'] ?? 0), 2, '.', ' ') ?>
                                    <?= htmlspecialchars((string)($r['currency'] ?? '')) ?>
                                    <?php if (isset($r['opportunity_after'])): ?>
                                        → <?= number_format((float)$r['opportunity_after'], 2, '.', ' ') ?>
                                        <?= ($r['is_manual_opportunity'] ?? '') === 'Y' ? '(manual)' : '' ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?= htmlspecialchars((string)($r['stage_name'] ?? '')) ?>
                                <div class="muted"><?= htmlspecialchars((string)($r['category'] ?? '')) ?></div>
                            </td>
                            <td class="st-<?= htmlspecialchars($st) ?>">
                                <?= htmlspecialchars($statusLabels[$st] ?? $st) ?>
                                <?php foreach (($r['dead_rows'] ?? []) as $dr): ?>
                                    <div class="dead">
                                        #<?= (int)($dr['PRODUCT_ID'] ?? 0) ?>
                                        <?= htmlspecialchars((string)($dr['PRODUCT_NAME'] ?? '')) ?>
                                        — <?= htmlspecialchars((string)($dr['dead_reason'] ?? '')) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach (($r['live_rows'] ?? []) as $lr): ?>
                                    <div class="price">
                                        რჩება: #<?= (int)($lr['PRODUCT_ID'] ?? 0) ?>
                                        <?= htmlspecialchars((string)($lr['PRODUCT_NAME'] ?? '')) ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!empty($r['dropped_dead_only'])): ?>
                                    <div class="price">ახალი რიგი არ დაემატა — პროდუქტი უკვე მიბმულია</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars((string)($c['project'] ?? '')) ?> /
                                <?= htmlspecialchars((string)($c['type'] ?? '')) ?> /
                                <?= htmlspecialchars((string)($c['sector'] ?? '')) ?> /
                                <?= htmlspecialchars((string)($c['block'] ?? '')) ?> /
                                <?= htmlspecialchars((string)($c['floor'] ?? '')) ?> /
                                <?= htmlspecialchars((string)($c['number'] ?? '')) ?>
                            </td>
                            <td>
                                <?php if ($p): ?>
                                    #<?= (int)$p['ID'] ?> <?= htmlspecialchars((string)$p['NAME']) ?>
                                    <?php if (isset($r['row_price'])): ?>
                                        <div class="price">
                                            ფასი: <?= number_format((float)$r['row_price'], 2, '.', ' ') ?>
                                            <?= htmlspecialchars((string)($r['currency'] ?? '')) ?>
                                            × <?= rtrim(rtrim(number_format((float)($r['row_qty'] ?? 1), 2, '.', ''), '0'), '.') ?>
                                            (<?php
                                                $src = (string)($r['price_source'] ?? '');
                                                echo $src === 'deal' ? 'დილიდან' : ($src === 'old_row' ? 'ძველი რიგიდან' : 'პროდუქტიდან');
                                            ?>)
                                        </div>
                                    <?php endif; ?>
                                <?php elseif (!empty($r['matches'])): ?>
                                    <?php foreach ($r['matches'] as $m): ?>
                                        <div>#<?= (int)$m['ID'] ?> <?= htmlspecialchars((string)$m['NAME']) ?></div>
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
