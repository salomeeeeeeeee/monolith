<?php
/**
 * ყველა WON დილი (პროდუქტით): ownerDeal / ownerContact / ownerCompany
 * Batch: ?after_id=0&limit=500
 * გაშვება: https://crm.monolith.ge/crm/deal/test.php
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');
if (function_exists('session_write_close')) {
    session_write_close();
}

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');

define('PRODUCT_IBLOCK_ID', 14);
define('PROP_OWNER_DEAL', 'ownerDeal');
define('PROP_OWNER_CONTACT', 'ownerContact');
define('PROP_OWNER_COMPANY', 'ownerCompany');
define('OWNER_PROP_CODES', [PROP_OWNER_DEAL, PROP_OWNER_CONTACT, PROP_OWNER_COMPANY]);
define('DEFAULT_BATCH_LIMIT', 500);
define('MAX_BATCH_LIMIT', 1000);

$afterId = max(0, (int)($_GET['after_id'] ?? 0));
$limit = (int)($_GET['limit'] ?? DEFAULT_BATCH_LIMIT);
if ($limit <= 0) {
    $limit = DEFAULT_BATCH_LIMIT;
} elseif ($limit > MAX_BATCH_LIMIT) {
    $limit = MAX_BATCH_LIMIT;
}

function syncNormalizePropValue($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    return trim((string)$value);
}

function syncFormatCrmBindValue($entityType, $id)
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

function syncResolveDealContactId(array $deal)
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

function syncExtractCrmEntityId($value)
{
    $value = syncNormalizePropValue($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(?:D|C|CO)_(\d+)$/i', $value, $m)) {
        return $m[1];
    }
    return ctype_digit($value) ? $value : $value;
}

function syncCrmBindValuesMatch($current, $target)
{
    if ($target === '') {
        return syncExtractCrmEntityId($current) === '';
    }
    return syncExtractCrmEntityId($current) === syncExtractCrmEntityId($target);
}

function syncGetProductOwnerProps($productId, $iblockId)
{
    $props = [];
    $res = CIBlockElement::GetList(
        [],
        ['ID' => (int)$productId, 'IBLOCK_ID' => (int)$iblockId],
        false,
        false,
        ['ID', 'IBLOCK_ID']
    );

    if (!$ob = $res->GetNextElement()) {
        return $props;
    }

    $arProps = $ob->GetProperties();
    foreach (OWNER_PROP_CODES as $code) {
        if (!isset($arProps[$code])) {
            continue;
        }
        $props[$code] = syncNormalizePropValue($arProps[$code]['VALUE'] ?? '');
    }

    return $props;
}

function syncGetDealProducts($dealId)
{
    $products = [];
    $res = CCrmProductRow::GetList(
        ['ID' => 'ASC'],
        ['OWNER_TYPE' => 'D', 'OWNER_ID' => (int)$dealId]
    );

    while ($row = $res->Fetch()) {
        $productId = (int)($row['PRODUCT_ID'] ?? 0);
        if ($productId > 0) {
            $products[$productId] = true;
        }
    }

    return array_keys($products);
}

function syncBuildTargetProps($dealId, $contactId, $companyId)
{
    $props = [
        PROP_OWNER_DEAL => syncFormatCrmBindValue('deal', $dealId),
    ];

    if ($contactId > 0) {
        $props[PROP_OWNER_CONTACT] = syncFormatCrmBindValue('contact', $contactId);
    }
    if ($companyId > 0) {
        $props[PROP_OWNER_COMPANY] = syncFormatCrmBindValue('company', $companyId);
    }

    return $props;
}

function syncNeedsUpdate(array $currentProps, array $targetProps)
{
    foreach ($targetProps as $code => $value) {
        if (!syncCrmBindValuesMatch($currentProps[$code] ?? '', $value)) {
            return true;
        }
    }
    return false;
}

$counts = [
    'deals_processed' => 0,
    'deals_with_products' => 0,
    'deals_no_products' => 0,
    'products_updated' => 0,
    'products_skipped' => 0,
    'products_failed' => 0,
];

$arFilter = [
    'CHECK_PERMISSIONS' => 'N',
    'STAGE_SEMANTIC_ID' => 'S',
];
if ($afterId > 0) {
    $arFilter['>ID'] = $afterId;
}

$lastDealId = $afterId;
$processedInBatch = 0;

$res = CCrmDeal::GetListEx(
    ['ID' => 'ASC'],
    $arFilter,
    false,
    ['nTopCount' => $limit],
    ['ID', 'CONTACT_ID', 'COMPANY_ID']
);

while ($deal = $res->Fetch()) {
    $dealId = (int)$deal['ID'];
    $lastDealId = $dealId;
    $processedInBatch++;
    $counts['deals_processed']++;

    $productIds = syncGetDealProducts($dealId);
    if (empty($productIds)) {
        $counts['deals_no_products']++;
        continue;
    }

    $counts['deals_with_products']++;
    $contactId = syncResolveDealContactId($deal);
    $companyId = (int)($deal['COMPANY_ID'] ?? 0);
    $targetProps = syncBuildTargetProps($dealId, $contactId, $companyId);

    foreach ($productIds as $productId) {
        $iblockId = PRODUCT_IBLOCK_ID;
        $elRes = CIBlockElement::GetList([], ['ID' => $productId], false, false, ['ID', 'IBLOCK_ID']);
        if ($elRow = $elRes->Fetch()) {
            $iblockId = (int)$elRow['IBLOCK_ID'];
        }

        $currentProps = syncGetProductOwnerProps($productId, $iblockId);
        if (!syncNeedsUpdate($currentProps, $targetProps)) {
            $counts['products_skipped']++;
            continue;
        }

        try {
            CIBlockElement::SetPropertyValuesEx($productId, $iblockId, $targetProps);
            $counts['products_updated']++;
        } catch (Throwable $e) {
            $counts['products_failed']++;
        }
    }
}

$hasMore = $processedInBatch === $limit;

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'batch' => [
        'after_id' => $afterId,
        'last_deal_id' => $lastDealId,
        'limit' => $limit,
        'has_more' => $hasMore,
        'next_after_id' => $hasMore ? $lastDealId : null,
    ],
    'counts' => $counts,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
