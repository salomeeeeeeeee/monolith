<?php

define('API_TOKEN', 'MonolithFMGWonDeal2026');

function wonDealsGetRequestToken()
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['HTTP_X_API_TOKEN'] ?? '',
        $_GET['token'] ?? '',
        $_POST['token'] ?? '',
    ];

    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            $key = strtolower((string)$name);
            if ($key === 'authorization' || $key === 'x-api-token') {
                $candidates[] = $value;
            }
        }
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            $key = strtolower((string)$name);
            if ($key === 'authorization' || $key === 'x-api-token') {
                $candidates[] = $value;
            }
        }
    }

    foreach ($candidates as $raw) {
        $raw = trim((string)$raw);
        if ($raw === '') {
            continue;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $raw, $m)) {
            return $m[1];
        }
        return $raw;
    }

    return '';
}

if (wonDealsGetRequestToken() !== API_TOKEN) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['status' => 401, 'error' => 'Unauthorized — Bearer token is required'], JSON_UNESCAPED_UNICODE);
    exit;
}

ob_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/rest/local/api/calculator/helpers.php');

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');
CModule::IncludeModule('catalog');
session_write_close();

function wonDealsResolvePropText($value)
{
    $text = trim(calcGetIblockPropText($value));
    if ($text === '') {
        return '';
    }
    if (is_numeric($text)) {
        $enum = CIBlockPropertyEnum::GetByID((int)$text);
        if (is_array($enum) && !empty($enum['VALUE'])) {
            return (string)$enum['VALUE'];
        }
    }
    return $text;
}

function wonDealsExtractDealId($value)
{
    $text = trim(wonDealsResolvePropText($value));
    if ($text === '') {
        return 0;
    }
    if (ctype_digit($text)) {
        return (int)$text;
    }
    if (preg_match('/(\d+)/', $text, $matches)) {
        return (int)$matches[1];
    }
    return 0;
}

function wonDealsIsConfirmedPlan(array $plan)
{
    return wonDealsResolvePropText($plan['DASTURI'] ?? '') === 'დადასტურებული';
}

function wonDealsGetApprovalDateValue(array $plan)
{
    foreach (['dadasturebisDro', 'DADASTUREBIS_DRO', 'DADASTUREBIS_DRO'] as $code) {
        if (!empty($plan[$code])) {
            return $plan[$code];
        }
    }
    return '';
}

function wonDealsLoadIblockElementsForDeals($iblockId, array $dealIdSet, array $sort = ['ID' => 'ASC'])
{
    $elements = [];
    $page = 1;
    $pageSize = 500;

    do {
        $pageCount = 0;
        $res = CIBlockElement::GetList(
            $sort,
            ['IBLOCK_ID' => $iblockId],
            false,
            ['nPageSize' => $pageSize, 'iNumPage' => $page],
            ['ID', 'IBLOCK_ID', 'PROPERTY_*']
        );

        while ($ob = $res->GetNextElement()) {
            $pageCount++;
            $arFields = $ob->GetFields();
            $arProps = $ob->GetProperties();
            $row = [];
            foreach ($arFields as $key => $val) {
                $row[$key] = $val;
            }
            foreach ($arProps as $key => $prop) {
                $code = !empty($prop['CODE']) ? $prop['CODE'] : $key;
                $row[$code] = $prop['VALUE'];
            }

            $dealId = wonDealsExtractDealId($row['DEAL'] ?? '');
            if ($dealId <= 0 || !isset($dealIdSet[$dealId])) {
                continue;
            }
            $elements[] = $row;
        }

        $page++;
    } while ($pageCount === $pageSize);

    return $elements;
}

function wonDealsParseAmount($tanxa)
{
    $text = wonDealsResolvePropText($tanxa);
    if ($text === '') {
        return null;
    }
    return round((float)explode('|', $text)[0], 2);
}

function wonDealsCompareTarigi($dateA, $dateB)
{
    $a = DateTime::createFromFormat('d/m/Y', $dateA);
    $b = DateTime::createFromFormat('d/m/Y', $dateB);
    if (!$a && !$b) {
        return 0;
    }
    if (!$a) {
        return 1;
    }
    if (!$b) {
        return -1;
    }
    return $a <=> $b;
}

function wonDealsApprovalTimestamp($value)
{
    $text = trim(wonDealsResolvePropText($value));
    if ($text === '') {
        return 0;
    }

    $formats = ['d.m.Y H:i:s', 'd/m/Y H:i:s', 'd.m.Y', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $text);
        if ($dt instanceof DateTime) {
            return $dt->getTimestamp();
        }
    }

    $ts = strtotime(str_replace('/', '.', $text));
    return $ts ?: 0;
}

function wonDealsSortPlansByLatest(array $plans)
{
    usort($plans, function ($a, $b) {
        $tsA = wonDealsApprovalTimestamp(wonDealsGetApprovalDateValue($a));
        $tsB = wonDealsApprovalTimestamp(wonDealsGetApprovalDateValue($b));
        if ($tsA !== $tsB) {
            return $tsB <=> $tsA;
        }
        return intval($b['ID'] ?? 0) <=> intval($a['ID'] ?? 0);
    });
    return $plans;
}

function wonDealsPickLatestConfirmedPlan(array $plans)
{
    $confirmed = array_values(array_filter($plans, 'wonDealsIsConfirmedPlan'));
    if (empty($confirmed)) {
        return null;
    }

    return wonDealsSortPlansByLatest($confirmed)[0];
}

function wonDealsGetScheduleBounds(array $rows)
{
    if (empty($rows)) {
        return [
            'scheduleStartDate' => null,
            'firstTransferAmount' => null,
            'scheduleEndDate' => null,
            'lastTransferAmount' => null,
        ];
    }

    usort($rows, function ($a, $b) {
        return wonDealsCompareTarigi(
            wonDealsResolvePropText($a['TARIGI'] ?? ''),
            wonDealsResolvePropText($b['TARIGI'] ?? '')
        );
    });

    $first = $rows[0];
    $last = $rows[count($rows) - 1];

    return [
        'scheduleStartDate' => wonDealsResolvePropText($first['TARIGI'] ?? '') ?: null,
        'firstTransferAmount' => wonDealsParseAmount($first['TANXA'] ?? ''),
        'scheduleEndDate' => wonDealsResolvePropText($last['TARIGI'] ?? '') ?: null,
        'lastTransferAmount' => wonDealsParseAmount($last['TANXA'] ?? ''),
    ];
}

function wonDealsExtractBrokerId($value)
{
    if ($value === null || $value === '') {
        return 0;
    }

    if (is_array($value)) {
        $value = reset($value);
    }

    $text = trim((string)$value);
    if ($text === '') {
        return 0;
    }

    if (preg_match('/^[A-Z0-9]+_(\d+)$/i', $text, $matches)) {
        return intval($matches[1]);
    }

    if (ctype_digit($text)) {
        return intval($text);
    }

    if (preg_match('/(\d+)/', $text, $matches)) {
        return intval($matches[1]);
    }

    return 0;
}

function wonDealsLoadBrokerNames(array $brokerIds)
{
    $names = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $brokerIds))));
    if (empty($ids)) {
        return $names;
    }

    $elRes = CIBlockElement::GetList(
        [],
        ['ID' => $ids],
        false,
        false,
        ['ID', 'NAME']
    );
    while ($el = $elRes->Fetch()) {
        $id = intval($el['ID'] ?? 0);
        $name = trim((string)($el['NAME'] ?? ''));
        if ($id > 0 && $name !== '') {
            $names[$id] = $name;
        }
    }

    $missing = array_values(array_diff($ids, array_keys($names)));
    if (!empty($missing)) {
        $companyRes = CCrmCompany::GetList([], ['ID' => $missing, 'CHECK_PERMISSIONS' => 'N'], ['ID', 'TITLE']);
        while ($company = $companyRes->Fetch()) {
            $id = intval($company['ID'] ?? 0);
            $name = trim((string)($company['TITLE'] ?? ''));
            if ($id > 0 && $name !== '') {
                $names[$id] = $name;
            }
        }
    }

    $missing = array_values(array_diff($ids, array_keys($names)));
    if (!empty($missing)) {
        $contactRes = CCrmContact::GetList([], ['ID' => $missing, 'CHECK_PERMISSIONS' => 'N'], ['ID', 'NAME', 'LAST_NAME']);
        while ($contact = $contactRes->Fetch()) {
            $id = intval($contact['ID'] ?? 0);
            $name = trim(($contact['NAME'] ?? '') . ' ' . ($contact['LAST_NAME'] ?? ''));
            if ($id > 0 && $name !== '') {
                $names[$id] = $name;
            }
        }
    }

    return $names;
}

function wonDealsResolveBrokerField($value, array $brokerNameMap)
{
    $brokerId = wonDealsExtractBrokerId($value);
    if ($brokerId <= 0) {
        if (is_array($value)) {
            $value = reset($value);
        }
        $text = trim((string)$value);

        return [
            'brokerId' => null,
            'brokerName' => ($text !== '' && !ctype_digit($text)) ? $text : null,
        ];
    }

    return [
        'brokerId' => $brokerId,
        'brokerName' => $brokerNameMap[$brokerId] ?? null,
    ];
}

// ─── Query parameters: filters & pagination ─────────────────────
$paramFrom         = isset($_GET['from'])         ? trim($_GET['from'])         : '';
$paramTo           = isset($_GET['to'])           ? trim($_GET['to'])           : '';
$paramUpdatedSince = isset($_GET['updatedSince']) ? trim($_GET['updatedSince']) : '';
$paramLimit        = isset($_GET['limit'])        ? intval($_GET['limit'])      : 0;
$paramOffset       = isset($_GET['offset'])       ? intval($_GET['offset'])     : 0;

if ($paramLimit <= 0 || $paramLimit > 5000) {
    $paramLimit = 5000;
}
if ($paramOffset < 0) {
    $paramOffset = 0;
}

$dealFilter = ['STAGE_ID' => 'WON', 'CHECK_PERMISSIONS' => 'N'];

if ($paramFrom !== '') {
    $dealFilter['>=DATE_CREATE'] = $paramFrom;
}
if ($paramTo !== '') {
    $dealFilter['<=DATE_CREATE'] = $paramTo;
}
if ($paramUpdatedSince !== '') {
    $dealFilter['>=DATE_MODIFY'] = $paramUpdatedSince;
}

// ─── Fetch deals ─────────────────────────────────────────────────
$wonDeals = [];
$res = CCrmDeal::GetListEx(
    ['ID' => 'ASC'],
    $dealFilter,
    false,
    ['nPageSize' => $paramLimit, 'iNumPage' => intval($paramOffset / $paramLimit) + 1],
    [
        'ID',
        'OPPORTUNITY',
        'UF_CRM_1779277886804',
        'ASSIGNED_BY_ID',
        'ASSIGNED_BY_NAME',
        'ASSIGNED_BY_LAST_NAME',
        'CONTACT_ID',
        'SOURCE_ID',
        'DATE_CREATE',
        'DATE_MODIFY',
        'UF_CRM_1785491867',
    ]
);

while ($deal = $res->Fetch()) {
    $wonDeals[] = $deal;
}

$brokerIds = [];
foreach ($wonDeals as $deal) {
    $brokerId = wonDealsExtractBrokerId($deal['UF_CRM_1785491867'] ?? null);
    if ($brokerId > 0) {
        $brokerIds[] = $brokerId;
    }
}
$brokerNameMap = wonDealsLoadBrokerNames($brokerIds);

$dealIdSet = [];
foreach ($wonDeals as $deal) {
    $dealIdSet[intval($deal['ID'])] = true;
}

// ─── Load manager personal IDs (UF_USR_1786440559828) ────────────
$managerUserIds = [];
foreach ($wonDeals as $deal) {
    $uid = intval($deal['ASSIGNED_BY_ID'] ?? 0);
    if ($uid > 0) {
        $managerUserIds[$uid] = true;
    }
}
$managerPersonalIds = [];
if (!empty($managerUserIds)) {
    $userRes = CUser::GetList(
        'ID', 'ASC',
        ['ID' => implode('|', array_keys($managerUserIds))],
        ['SELECT' => ['UF_USR_1786440559828']]
    );
    while ($u = $userRes->Fetch()) {
        $managerPersonalIds[intval($u['ID'])] = trim($u['UF_USR_1786440559828'] ?? '');
    }
}

// ─── Load contacts for client fields ─────────────────────────────
$contactIds = [];
foreach ($wonDeals as $deal) {
    $cid = intval($deal['CONTACT_ID'] ?? 0);
    if ($cid > 0) {
        $contactIds[$cid] = true;
    }
}
$contactsData = [];
$contactPhones = [];
if (!empty($contactIds)) {
    $cRes = CCrmContact::GetList([], ['ID' => array_keys($contactIds), 'CHECK_PERMISSIONS' => 'N'], [
        'ID', 'NAME', 'LAST_NAME', 'UF_CRM_1781244744534',
    ]);
    while ($c = $cRes->Fetch()) {
        $contactsData[intval($c['ID'])] = $c;
    }

    $multiRes = CCrmFieldMulti::GetList(
        ['ID' => 'ASC'],
        ['ENTITY_ID' => 'CONTACT', 'ELEMENT_ID' => array_keys($contactIds), 'TYPE_ID' => 'PHONE']
    );
    while ($row = $multiRes->Fetch()) {
        $eid = intval($row['ELEMENT_ID']);
        if (!isset($contactPhones[$eid])) {
            $contactPhones[$eid] = $row['VALUE'];
        }
    }
}

// ─── Load deal product rows (projectId, productId, listPrice) ────
$productsByDeal = [];
$allProductIds = [];
if (!empty($dealIdSet)) {
    foreach (array_keys($dealIdSet) as $did) {
        $prodRes = CCrmProductRow::GetList(
            ['ID' => 'ASC'],
            ['OWNER_TYPE' => 'D', 'OWNER_ID' => $did]
        );
        while ($pr = $prodRes->Fetch()) {
            $pid = intval($pr['PRODUCT_ID'] ?? 0);
            if ($pid > 0) {
                $allProductIds[$pid] = true;
            }
            $productsByDeal[$did][] = $pr;
        }
    }
}

// ─── Resolve productId → projectId/projectName (iblock 14) ────────
$productToProjectId = [];
$projectIdToName = [];
$productPriceMap = [];
if (!empty($allProductIds)) {
    $elRes = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => 14, 'ID' => array_keys($allProductIds), 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'IBLOCK_SECTION_ID', 'NAME']
    );
    while ($ob = $elRes->GetNextElement()) {
        $el = $ob->GetFields();
        $pid = intval($el['ID'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $productToProjectId[$pid] = !empty($el['IBLOCK_SECTION_ID']) ? intval($el['IBLOCK_SECTION_ID']) : null;
    }

    $projectIds = [];
    foreach ($productToProjectId as $projId) {
        if (!empty($projId)) {
            $projectIds[intval($projId)] = true;
        }
    }

    if (!empty($projectIds)) {
        $secRes = CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => 14, 'ID' => array_keys($projectIds), 'ACTIVE' => 'Y'],
            false,
            ['ID', 'NAME']
        );
        while ($sec = $secRes->GetNext()) {
            $projectIdToName[intval($sec['ID'])] = $sec['NAME'] ?? null;
        }
    }

    global $DB;
    $productIdSql = implode(',', array_map('intval', array_keys($allProductIds)));
    if ($productIdSql !== '') {
        $priceRes = $DB->Query(
            "SELECT PRODUCT_ID, PRICE
             FROM b_catalog_price
             WHERE PRODUCT_ID IN ($productIdSql) AND CATALOG_GROUP_ID = 1"
        );
        while ($priceRow = $priceRes->Fetch()) {
            $productPriceMap[intval($priceRow['PRODUCT_ID'])] = round(floatval($priceRow['PRICE']), 2);
        }
    }
}

// ─── Resolve deal source (dealType) ─────────────────────────────
function wonDealsResolveSource($sourceId)
{
    if (empty($sourceId)) {
        return null;
    }
    $statuses = CCrmStatus::GetStatusList('SOURCE');
    return $statuses[$sourceId] ?? $sourceId;
}

// ─── Plans & schedules ───────────────────────────────────────────
$plansByDeal = [];
$scheduleByDeal = [];

if (!empty($dealIdSet)) {
    foreach (wonDealsLoadIblockElementsForDeals(21, $dealIdSet, ['ID' => 'DESC']) as $plan) {
        $dealId = wonDealsExtractDealId($plan['DEAL'] ?? '');
        if ($dealId <= 0 || !isset($dealIdSet[$dealId])) {
            continue;
        }
        if (!isset($plansByDeal[$dealId])) {
            $plansByDeal[$dealId] = [];
        }
        $plansByDeal[$dealId][] = $plan;
    }

    foreach (wonDealsLoadIblockElementsForDeals(22, $dealIdSet, ['PROPERTY_TARIGI' => 'ASC']) as $row) {
        $dealId = wonDealsExtractDealId($row['DEAL'] ?? '');
        if ($dealId <= 0 || !isset($dealIdSet[$dealId])) {
            continue;
        }
        if (!isset($scheduleByDeal[$dealId])) {
            $scheduleByDeal[$dealId] = [];
        }
        $scheduleByDeal[$dealId][] = $row;
    }
}

// ─── Build result ────────────────────────────────────────────────
$result = [];
foreach ($wonDeals as $deal) {
    $dealId = intval($deal['ID']);
    $scheduleRows = $scheduleByDeal[$dealId] ?? [];
    $latestPlan = wonDealsPickLatestConfirmedPlan($plansByDeal[$dealId] ?? []);
    $schedule = wonDealsGetScheduleBounds($scheduleRows);

    $managerId = intval($deal['ASSIGNED_BY_ID'] ?? 0) ?: null;
    $managerName = trim(($deal['ASSIGNED_BY_NAME'] ?? '') . ' ' . ($deal['ASSIGNED_BY_LAST_NAME'] ?? ''));
    $managerPID = $managerId ? ($managerPersonalIds[$managerId] ?? null) : null;

    $totalArea = $deal['UF_CRM_1779277886804'] ?? null;
    if ($totalArea !== null && $totalArea !== '') {
        $totalArea = is_numeric($totalArea) ? floatval($totalArea) : wonDealsResolvePropText($totalArea);
    } else {
        $totalArea = null;
    }

    $installmentType = $latestPlan
        ? (wonDealsResolvePropText($latestPlan['planType'] ?? '') ?: null)
        : null;
    $scheduleType = $latestPlan
        ? (wonDealsResolvePropText($latestPlan['SELECTID_GRAPH'] ?? '') ?: null)
        : null;

    // Product (single)
    $product = null;
    $dealProducts = $productsByDeal[$dealId] ?? [];
    if (!empty($dealProducts)) {
        $pr = $dealProducts[0];
        $productId = intval($pr['PRODUCT_ID'] ?? 0) ?: null;
        $projectId = $productId !== null ? ($productToProjectId[$productId] ?? null) : null;
        $product = [
            'productId'   => $productId,
            'projectId'   => $projectId,
            'projectName' => $projectId !== null ? ($projectIdToName[$projectId] ?? null) : null,
            'prodPrice'   => $productId !== null ? ($productPriceMap[$productId] ?? null) : null,
        ];
    }

    // Broker
    $broker = wonDealsResolveBrokerField($deal['UF_CRM_1785491867'] ?? null, $brokerNameMap);

    // Contact / client
    $contactId = intval($deal['CONTACT_ID'] ?? 0);
    $contact = $contactId > 0 ? ($contactsData[$contactId] ?? null) : null;
    $clientName = null;
    $clientPersonalId = null;
    $clientPhone = null;
    if ($contact) {
        $clientName = trim(($contact['NAME'] ?? '') . ' ' . ($contact['LAST_NAME'] ?? '')) ?: null;
        $clientPersonalId = $contact['UF_CRM_1781244744534'] ?? null;
        $clientPhone = $contactPhones[$contactId] ?? null;
    }

    $result[] = [
        'dealId'              => $dealId,
        'saleDate'            => $deal['DATE_CREATE'] ?? null,
        'totalAmount'         => round(floatval($deal['OPPORTUNITY'] ?? 0), 2),
        'totalArea'           => $totalArea,
        'installmentType'     => $installmentType,
        'scheduleType'        => $scheduleType,
        'scheduleStartDate'   => $schedule['scheduleStartDate'],
        'firstTransferAmount' => $schedule['firstTransferAmount'],
        'scheduleEndDate'     => $schedule['scheduleEndDate'],
        'lastTransferAmount'  => $schedule['lastTransferAmount'],
        'managerId'           => $managerId,
        'manager'             => $managerName !== '' ? $managerName : null,
        'managerPersonalId'   => $managerPID ?: null,
        'dealType'            => wonDealsResolveSource($deal['SOURCE_ID'] ?? ''),
        'brokerId'            => $broker['brokerId'],
        'brokerName'          => $broker['brokerName'],
        'clientName'          => $clientName,
        'clientPersonalId'    => $clientPersonalId,
        'clientPhone'         => $clientPhone,
        'product'             => $product,
    ];
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 200,
    'count'  => count($result),
    'limit'  => $paramLimit,
    'offset' => $paramOffset,
    'deals'  => $result,
], JSON_UNESCAPED_UNICODE);
