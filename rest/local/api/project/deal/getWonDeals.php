<?php
ob_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/rest/local/api/calculator/helpers.php');

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');
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

$wonDeals = [];
$res = CCrmDeal::GetListEx(
    ['ID' => 'ASC'],
    ['STAGE_ID' => 'WON', 'CHECK_PERMISSIONS' => 'N'],
    false,
    false,
    [
        'ID',
        'OPPORTUNITY',
        'UF_CRM_1779277886804',
        'ASSIGNED_BY_NAME',
        'ASSIGNED_BY_LAST_NAME',
    ]
);

while ($deal = $res->Fetch()) {
    $wonDeals[] = $deal;
}

$dealIdSet = [];
foreach ($wonDeals as $deal) {
    $dealIdSet[intval($deal['ID'])] = true;
}

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

$result = [];
foreach ($wonDeals as $deal) {
    $dealId = intval($deal['ID']);
    $scheduleRows = $scheduleByDeal[$dealId] ?? [];
    $latestPlan = wonDealsPickLatestConfirmedPlan($plansByDeal[$dealId] ?? []);
    $schedule = wonDealsGetScheduleBounds($scheduleRows);

    $managerName = trim(($deal['ASSIGNED_BY_NAME'] ?? '') . ' ' . ($deal['ASSIGNED_BY_LAST_NAME'] ?? ''));

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

    $result[] = [
        'dealId' => $dealId,
        'totalAmount' => round(floatval($deal['OPPORTUNITY'] ?? 0), 2),
        'totalArea' => $totalArea,
        'installmentType' => $installmentType,
        'scheduleType' => $scheduleType,
        'scheduleStartDate' => $schedule['scheduleStartDate'],
        'firstTransferAmount' => $schedule['firstTransferAmount'],
        'scheduleEndDate' => $schedule['scheduleEndDate'],
        'lastTransferAmount' => $schedule['lastTransferAmount'],
        'manager' => $managerName !== '' ? $managerName : null,
    ];
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 200,
    'count' => count($result),
    'deals' => $result,
], JSON_UNESCAPED_UNICODE);
