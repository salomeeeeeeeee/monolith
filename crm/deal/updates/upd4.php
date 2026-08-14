<?php
/**
 * დილებზე პროდუქტის მიბმა ველებით:
 * პროექტი, ფართის ტიპი, ბლოკი, სართული, ნომერი, სრული ფართი
 *
 * Dry run:  https://crm.monolith.ge/crm/deal/test.php
 * Apply:    https://crm.monolith.ge/crm/deal/test.php?apply=1
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

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

$dealIds = [
    // 69399, 69398, 69397, 69396, 69395, 69394, 69393, 69392, 69391, 69390,
    // 69389, 69388, 69387, 69386, 69385, 69384, 69383, 69382, 69381, 69380,
    // 69379, 69378, 69377, 69376, 69375, 69374, 69373, 69372, 69371, 69370,
    // 69369, 69368, 69367, 69366, 69365, 69364, 69363, 69362, 69361, 69360,
    // 69359, 69358, 69357, 69356, 69355, 69354, 69353, 69352, 69351, 69350,

    69377, 69367, 69366, 69365, 69351
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

function dealHasProducts($dealId)
{
    $res = CCrmProductRow::GetList(
        ['ID' => 'ASC'],
        ['OWNER_TYPE' => 'D', 'OWNER_ID' => (int)$dealId],
        false,
        ['nTopCount' => 1],
        ['ID']
    );
    return (bool)$res->Fetch();
}

function findMatchingProducts(array $deal)
{
    $project = trim((string)($deal['UF_CRM_1779277729207'] ?? ''));
    $type    = trim((string)($deal['UF_CRM_1779277898205'] ?? ''));
    $block   = trim((string)($deal['UF_CRM_1779277644355'] ?? ''));
    $floor   = trim((string)($deal['UF_CRM_1779277828822'] ?? ''));
    $number  = trim((string)($deal['UF_CRM_1779277613798'] ?? ''));
    $area    = trim((string)($deal['UF_CRM_1779277886804'] ?? ''));

    $criteria = [
        'project' => $project,
        'type'    => $type,
        'block'   => $block,
        'floor'   => $floor,
        'number'  => $number,
        'area'    => $area,
    ];

    $filter = [
        'IBLOCK_ID' => PRODUCT_IBLOCK_ID,
        'ACTIVE'    => 'Y',
        'CHECK_PERMISSIONS' => 'N',
    ];
    if ($project !== '') {
        $filter['PROPERTY___VO9RG4'] = $project;
    }
    if ($block !== '') {
        $filter['PROPERTY__L24CUB'] = $block;
    }
    if ($number !== '') {
        $filter['PROPERTY___6KWOWZ'] = $number;
    }

    $matches = [];
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        $filter,
        false,
        false,
        ['ID', 'NAME', 'IBLOCK_ID']
    );

    while ($ob = $res->GetNextElement()) {
        $fields = $ob->GetFields();
        $props  = $ob->GetProperties();

        $prodProject = $props['__VO9RG4']['VALUE'] ?? '';
        $prodType    = $props['__X1GCRZ']['VALUE'] ?? '';
        $prodBlock   = $props['_L24CUB']['VALUE'] ?? '';
        $prodFloor   = $props['_FTRIDL']['VALUE'] ?? '';
        $prodNumber  = $props['__6KWOWZ']['VALUE'] ?? '';
        $prodArea    = $props['__173JA5']['VALUE'] ?? '';

        if ($project !== '' && !valsEqual($prodProject, $project)) {
            continue;
        }
        if ($type !== '' && !valsEqual($prodType, $type)) {
            continue;
        }
        if ($block !== '' && !valsEqual($prodBlock, $block)) {
            continue;
        }
        if ($floor !== '' && !valsEqual($prodFloor, $floor)) {
            continue;
        }
        if ($number !== '' && !valsEqual($prodNumber, $number)) {
            continue;
        }
        if ($area !== '' && !valsEqual($prodArea, $area)) {
            continue;
        }

        $priceRow = CPrice::GetBasePrice((int)$fields['ID']);
        $matches[] = [
            'ID'      => (int)$fields['ID'],
            'NAME'    => $fields['NAME'],
            'PRICE'   => (float)($priceRow['PRICE'] ?? 0),
            'project' => $prodProject,
            'type'    => $prodType,
            'block'   => $prodBlock,
            'floor'   => $prodFloor,
            'number'  => $prodNumber,
            'area'    => $prodArea,
        ];
    }

    return [$criteria, $matches];
}

$results = [];
$counts = [
    'deals' => 0,
    'already_has_product' => 0,
    'missing_fields' => 0,
    'not_found' => 0,
    'ambiguous' => 0,
    'matched' => 0,
    'attached' => 0,
    'failed' => 0,
];

$select = [
    'ID', 'TITLE', 'CONTACT_ID', 'COMPANY_ID',
    'UF_CRM_1779277729207',
    'UF_CRM_1779277898205',
    'UF_CRM_1779277644355',
    'UF_CRM_1779277828822',
    'UF_CRM_1779277613798',
    'UF_CRM_1779277886804',
];

foreach ($dealIds as $dealId) {
    $counts['deals']++;
    $row = [
        'deal_id' => $dealId,
        'status'  => '',
    ];

    $dealRes = CCrmDeal::GetListEx(
        [],
        ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['nTopCount' => 1],
        $select
    );
    $deal = $dealRes->Fetch();
    if (!$deal) {
        $row['status'] = 'deal_not_found';
        $counts['failed']++;
        $results[] = $row;
        continue;
    }

    $row['title'] = $deal['TITLE'] ?? '';

    if (dealHasProducts($dealId)) {
        $row['status'] = 'already_has_product';
        $counts['already_has_product']++;
        $results[] = $row;
        continue;
    }

    [$criteria, $matches] = findMatchingProducts($deal);
    $row['criteria'] = $criteria;

    $emptyCount = 0;
    foreach ($criteria as $v) {
        if (trim((string)$v) === '') {
            $emptyCount++;
        }
    }
    if ($emptyCount >= 6) {
        $row['status'] = 'missing_fields';
        $counts['missing_fields']++;
        $results[] = $row;
        continue;
    }

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
    $row['product'] = $product;
    $counts['matched']++;

    if (!$apply) {
        $row['status'] = 'would_attach';
        $results[] = $row;
        continue;
    }

    $saved = CCrmDeal::SaveProductRows($dealId, [[
        'PRODUCT_ID' => $product['ID'],
        'PRICE'      => $product['PRICE'],
        'QUANTITY'   => 1,
    ]]);

    if (!$saved) {
        $row['status'] = 'save_failed';
        $counts['failed']++;
        $results[] = $row;
        continue;
    }

    $contactId = resolveDealContactId($deal);
    $companyId = (int)($deal['COMPANY_ID'] ?? 0);
    $ownerProps = [
        PROP_OWNER_DEAL => formatCrmBind('deal', $dealId),
    ];
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

    $row['status'] = 'attached';
    $counts['attached']++;
    $results[] = $row;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'apply'   => $apply,
    'counts'  => $counts,
    'results' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
