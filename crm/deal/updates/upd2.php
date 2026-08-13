<?php
/**
 * WON + Dighomi + ბინა: პროდუქტებზე ownerDeal / ownerContact / ownerCompany დილიდან
 * გაშვება: https://crm.monolith.ge/crm/deal/test.php
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');

define('FILTER_PROJECT', 'Dighomi');
define('FILTER_TYPE', 'ბინა');
define('PRODUCT_IBLOCK_ID', 14);
define('PROP_OWNER_DEAL', 'ownerDeal');
define('PROP_OWNER_CONTACT', 'ownerContact');
define('PROP_OWNER_COMPANY', 'ownerCompany');
define('OWNER_PROP_CODES', [PROP_OWNER_DEAL, PROP_OWNER_CONTACT, PROP_OWNER_COMPANY]);

function testNormalizePropValue($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    return trim((string)$value);
}

function testGetProductOwnerProps($productId, $iblockId)
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
        $props[$code] = testNormalizePropValue($arProps[$code]['VALUE'] ?? '');
    }

    return $props;
}

function testFormatCrmBindValue($entityType, $id)
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

function testResolveDealContactId(array $deal)
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

function testExtractCrmEntityId($value)
{
    $value = testNormalizePropValue($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(?:D|C|CO)_(\d+)$/i', $value, $m)) {
        return $m[1];
    }
    return ctype_digit($value) ? $value : $value;
}

function testCrmBindValuesMatch($current, $target)
{
    if ($target === '') {
        return testExtractCrmEntityId($current) === '';
    }
    return testExtractCrmEntityId($current) === testExtractCrmEntityId($target);
}

function testGetDealProducts($dealId)
{
    $products = [];
    $res = CCrmProductRow::GetList(
        ['ID' => 'ASC'],
        ['OWNER_TYPE' => 'D', 'OWNER_ID' => (int)$dealId]
    );

    while ($row = $res->Fetch()) {
        $productId = (int)($row['PRODUCT_ID'] ?? 0);
        if ($productId > 0) {
            $products[$productId] = $row;
        }
    }

    return $products;
}

$arFilter = [
    'STAGE_ID' => 'WON',
    'UF_CRM_1779277729207' => FILTER_PROJECT,
    'UF_CRM_1779277898205' => FILTER_TYPE,
    'CHECK_PERMISSIONS' => 'N',
];

$updated = 0;
$skipped = 0;
$failed = 0;
$results = [];

$res = CCrmDeal::GetList(
    ['ID' => 'ASC'],
    $arFilter,
    ['ID', 'TITLE', 'CONTACT_ID', 'COMPANY_ID']
);

while ($deal = $res->Fetch()) {
    $dealId = (int)$deal['ID'];
    $contactId = testResolveDealContactId($deal);
    $companyId = (int)($deal['COMPANY_ID'] ?? 0);

    $targetProps = [
        PROP_OWNER_DEAL => testFormatCrmBindValue('deal', $dealId),
        PROP_OWNER_CONTACT => testFormatCrmBindValue('contact', $contactId),
        PROP_OWNER_COMPANY => testFormatCrmBindValue('company', $companyId),
    ];

    $products = testGetDealProducts($dealId);
    if (empty($products)) {
        $skipped++;
        $results[] = [
            'deal_id' => $dealId,
            'title' => $deal['TITLE'],
            'result' => 'skipped',
            'reason' => 'no_products',
            'target' => $targetProps,
        ];
        continue;
    }

    $dealResult = [
        'deal_id' => $dealId,
        'title' => $deal['TITLE'],
        'target' => $targetProps,
        'products' => [],
    ];

    foreach ($products as $productId => $productRow) {
        $iblockId = PRODUCT_IBLOCK_ID;
        $elRes = CIBlockElement::GetList([], ['ID' => $productId], false, false, ['ID', 'IBLOCK_ID', 'NAME']);
        if ($elRow = $elRes->Fetch()) {
            $iblockId = (int)$elRow['IBLOCK_ID'];
        }

        $currentProps = testGetProductOwnerProps($productId, $iblockId);

        $needsUpdate = false;
        foreach ($targetProps as $code => $value) {
            if (!testCrmBindValuesMatch($currentProps[$code] ?? '', $value)) {
                $needsUpdate = true;
                break;
            }
        }

        if (!$needsUpdate) {
            $skipped++;
            $dealResult['products'][] = [
                'product_id' => $productId,
                'result' => 'skipped',
                'reason' => 'already_same',
            ];
            continue;
        }

        CIBlockElement::SetPropertyValuesEx($productId, $iblockId, $targetProps);

        $verifyProps = testGetProductOwnerProps($productId, $iblockId);

        $ok = true;
        foreach ($targetProps as $code => $value) {
            if ($value === '') {
                continue;
            }
            if (!testCrmBindValuesMatch($verifyProps[$code] ?? '', $value)) {
                $ok = false;
                break;
            }
        }

        if ($ok) {
            $updated++;
            $dealResult['products'][] = [
                'product_id' => $productId,
                'result' => 'updated',
                'before' => $currentProps,
                'after' => $verifyProps,
            ];
        } else {
            $failed++;
            $dealResult['products'][] = [
                'product_id' => $productId,
                'result' => 'failed',
                'before' => $currentProps,
                'after' => $verifyProps,
                'expected' => $targetProps,
            ];
        }
    }

    $results[] = $dealResult;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'filter' => [
        'stage' => 'WON',
        'UF_CRM_1779277729207' => FILTER_PROJECT,
        'UF_CRM_1779277898205' => FILTER_TYPE,
    ],
    'product_fields' => [PROP_OWNER_DEAL, PROP_OWNER_CONTACT, PROP_OWNER_COMPANY],
    'summary' => [
        'deals' => count($results),
        'products_updated' => $updated,
        'products_skipped' => $skipped,
        'products_failed' => $failed,
    ],
    'results' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
