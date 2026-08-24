<?php
/**
 * Dighomi + ბინა + გაყიდული: პროდუქტის ფასი → დილის OPPORTUNITY,
 * კვ.მ ფასი (__6ZWTER) → UF_CRM_1779277671391
 * (ownerDeal-დან), IS_MANUAL_OPPORTUNITY = Y
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
define('FILTER_PROJECT', 'Dighomi');
define('FILTER_TYPE', 'ოფისი');
define('FILTER_STATUS', 'გაყიდული');
define('PROP_PROJECT', '__VO9RG4');
define('PROP_TYPE', '__X1GCRZ');
define('PROP_STATUS', '_P64GYD');
define('PROP_OWNER_DEAL', 'ownerDeal');
define('PROP_KVM_PRICE', '__6ZWTER');
define('D_KVM_PRICE', 'UF_CRM_1779277671391');

$apply = isset($_REQUEST['apply']) && $_REQUEST['apply'] === '1';

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

$counts = [
    'products' => 0,
    'no_owner_deal' => 0,
    'deal_not_found' => 0,
    'price_zero' => 0,
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
    'PROPERTY_' . PROP_PROJECT => FILTER_PROJECT,
    'PROPERTY_' . PROP_TYPE => FILTER_TYPE,
    'PROPERTY_' . PROP_STATUS => FILTER_STATUS,
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

    if ($price <= 0) {
        $row['result'] = 'price_zero';
        $counts['price_zero']++;
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
    $kvmSame = abs($kvmBefore - $kvmPrice) < 0.01;

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
        D_KVM_PRICE => $kvmPrice,
    ];
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
        'project' => FILTER_PROJECT,
        'type' => FILTER_TYPE,
        'status' => FILTER_STATUS,
    ],
    'counts' => $counts,
    'results' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
