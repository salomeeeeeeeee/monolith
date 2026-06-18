<?php
ob_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once(__DIR__ . '/helpers.php');
CModule::IncludeModule('bizproc');
session_write_close();

$json = [];
try {
    $json = \Bitrix\Main\Web\Json::decode(\Bitrix\Main\HttpRequest::getInput());
} catch (Exception $e) {
    $json = [];
}

$result = ['status' => 400, 'TEXT' => 'გრაფიკი ვერ მოიძებნა'];

if (empty($json['data']) || !is_array($json['data'])) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($json['dealId']) || $json['dealId'] == 1) {
    $result['TEXT'] = 'გთხოვთ გრაფიკი დააგენერიროთ დილიდან';
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$dealData = calcGetDealInfoByID($json['dealId']);
if (!$dealData) {
    $result['TEXT'] = 'დილი ვერ მოიძებნა';
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$dealProds = CCrmDeal::LoadProductRows($json['dealId']);
$productData = [];
if (!empty($dealProds[0]['PRODUCT_ID'])) {
    $productData = calcGetProductDataByID($dealProds[0]['PRODUCT_ID']);
}

$planTypeMap = [
    'customType' => 'არასტანდარტული',
    'allCash' => 'ერთიანი',
    'internal' => 'შიდა განვადება',
];

$graphName = $json['graph'] ?? 'არასტანდარტული';
$documentHtml = calcBuildScheduleHtml($json['data']);

$arForAdd = [
    'IBLOCK_ID' => 21,
    'NAME' => 'განვადება ' . $json['dealId'] . ' — ' . date('d/m/Y H:i'),
    'ACTIVE' => 'Y',
];

$arProps = [
    'JSON' => json_encode($json, JSON_UNESCAPED_UNICODE),
    'document' => $documentHtml,
    'SELECTID_GRAPH' => $json['graph'] ?? '',
    'planType' => $planTypeMap[$json['payment_mode'] ?? ''] ?? ($json['planType'] ?? ''),
    'AUTHOR' => $json['author'] ?? 1,
    'DASTURI' => 'მოლოდინში',
    'DEAL' => $json['dealId'],
    'project' => $productData[0]['PROJECT'] ?? ($dealData['UF_CRM_1779277729207'] ?? ''),
    'prodType' => $productData[0]['PRODUCT_TYPE'] ?? ($dealData['UF_CRM_1779277898205'] ?? ''),
    'FLOOR' => $productData[0]['FLOOR'] ?? ($dealData['UF_CRM_1779277828822'] ?? ''),
    'number' => $productData[0]['Number'] ?? ($dealData['UF_CRM_1779277613798'] ?? ''),
    'advancePayment' => $json['advancePayment'] ?? '',
    'lastPayment' => $json['lastPayment'] ?? '',
    'DistributedPayment' => $json['DistributedPayment'] ?? '',
    'PERIOD' => $json['period'] ?? 1,
    'DISCOUNT_AMOUNT' => $json['discountAmount'] ?? '',
    'LAST_AMOUNT' => $json['lastAmount'] ?? '',
    'APPROVED_BY' => '',
];

$res = calcAddCIBlockElement($arForAdd, $arProps);

if (!is_numeric($res) || $res <= 0) {
    $result['TEXT'] = 'გრაფიკი ვერ გაიგზავნა: ' . (is_string($res) ? $res : '');
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$arErrorsTmp = [];
try {
    CBPDocument::StartWorkflow(
        22,
        ['lists', 'BizprocDocument', $res],
        ['TargetUser' => 'user_' . ($json['author'] ?? 1)],
        $arErrorsTmp
    );
} catch (Exception $e) {
    $arErrorsTmp[] = $e->getMessage();
}

if (!empty($arErrorsTmp)) {
    $result = [
        'status' => 200,
        'TEXT' => 'გრაფიკი შეინახა (ID: ' . $res . '), მაგრამ პროცესი ვერ გაეშვა: ' . implode('; ', $arErrorsTmp),
        'elementId' => $res,
    ];
} else {
    $result = [
        'status' => 200,
        'TEXT' => 'გრაფიკი წარმატებით გაიგზავნა დასტურისთვის',
        'elementId' => $res,
    ];
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
