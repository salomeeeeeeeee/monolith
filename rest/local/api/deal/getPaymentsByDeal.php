<?php

define('API_TOKEN', 'MonolithFMGWonDeal2026');

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('NOT_CHECK_PERMISSIONS', true);

function paymentsGetRequestToken()
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

function paymentsRespond(array $payload, $httpCode = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (paymentsGetRequestToken() !== API_TOKEN) {
    paymentsRespond(['status' => 401, 'error' => 'Unauthorized — Bearer token is required'], 401);
}

ob_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/rest/local/api/calculator/helpers.php');

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');
session_write_close();

$dealId = intval($_GET['dealId'] ?? 0);
if ($dealId <= 0) {
    paymentsRespond(['status' => 400, 'error' => 'dealId is required'], 400);
}

$dealRes = CCrmDeal::GetListEx([], ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID']);
if (!$dealRes->Fetch()) {
    paymentsRespond(['status' => 404, 'error' => 'Deal not found'], 404);
}

function paymentsResolvePropText($value)
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

function paymentsParseAmount($tanxa)
{
    $text = paymentsResolvePropText($tanxa);
    if ($text === '') {
        return null;
    }
    return round((float)explode('|', $text)[0], 2);
}

function paymentsCompareDate($dateA, $dateB)
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

$scheduleRows = calcGetCIBlockElementsByFilter(
    ['IBLOCK_ID' => 22, 'PROPERTY_DEAL' => $dealId],
    ['ID', 'IBLOCK_ID', 'PROPERTY_*'],
    ['PROPERTY_TARIGI' => 'ASC']
);

usort($scheduleRows, function ($a, $b) {
    return paymentsCompareDate(
        paymentsResolvePropText($a['TARIGI'] ?? ''),
        paymentsResolvePropText($b['TARIGI'] ?? '')
    );
});

$payments = [];
foreach ($scheduleRows as $row) {
    $payments[] = [
        'paymentId' => intval($row['ID'] ?? 0) ?: null,
        'amount'    => paymentsParseAmount($row['TANXA'] ?? ''),
        'date'      => paymentsResolvePropText($row['TARIGI'] ?? '') ?: null,
    ];
}

paymentsRespond([
    'status'   => 200,
    'dealId'   => $dealId,
    'count'    => count($payments),
    'payments' => $payments,
]);
