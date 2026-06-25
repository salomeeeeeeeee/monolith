<?php
ob_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once(__DIR__ . '/helpers.php');
session_write_close();

$json = [];
try {
    $json = \Bitrix\Main\Web\Json::decode(\Bitrix\Main\HttpRequest::getInput());
} catch (Exception $e) {
    $json = [];
}

$result = ['status' => 400, 'errorTXT' => 'Unknown error'];

$dealID = $json['dealId'] ?? null;
$typeSelected = $json['type_selected'] ?? '';
$paymentMode = $json['payment_mode'] ?? '';
$startDate = $json['startDate'] ?? '';
$period = intval($json['period'] ?? 1) ?: 1;
$endDate = $json['endDate'] ?? '';
$price = round(floatval($json['price'] ?? 0), 2);
$advancePayment = round(floatval($json['advancePayment'] ?? 0), 2);
$advancePayDate = $json['advancePayDate'] ?? '';
$lastPayment = round(floatval($json['lastPayment'] ?? 0), 2);
$lastPayDate = $json['lastPayDate'] ?? '';
$bookPayment = round(floatval($json['bookPayment'] ?? 0), 2);
$bookPayDate = $json['bookPayDate'] ?? '';

if ($dealID !== null && $dealID !== '' && !is_numeric($dealID)) {
    $result['errorTXT'] = 'deal ID is not valid';
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($price <= 0) {
    $result['errorTXT'] = 'price is not correct';
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// ერთიანი გადახდა — ერთი რიგი
if ($paymentMode === 'allCash' || $typeSelected === 'allCash') {
    $payDate = $advancePayDate ?: $startDate ?: date('d/m/Y');
    $arrDATA = [[
        'payment' => 1,
        'date' => $payDate,
        'amount' => $price,
        'leftToPay' => 0,
    ]];
    $result = ['status' => 200, 'result' => $arrDATA];
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!calcValidateDate($startDate) || !calcValidateDate($endDate)) {
    $result['errorTXT'] = 'დაწყების ან დასრულების თარიღი არ არის ვალიდური';
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!calcDateCompare($startDate, $endDate)) {
    $result['errorTXT'] = 'დაწყების და დასრულების თარიღები არასწორადაა შევსებული';
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($period == 1) {
    $paymentsCount = intval((calcMonthsBetweenDates($startDate, $endDate) + 1) / $period);
} else {
    $paymentsCount = intval((calcMonthsBetweenDates($startDate, $endDate) + 1) / $period + 1);
}

if ($paymentsCount <= 0) {
    $result['errorTXT'] = 'დაწყების და დასრულების თარიღები არასწორადაა შევსებული';
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($advancePayment && (!calcValidateDate($advancePayDate) || !calcDateCompare($advancePayDate, $startDate))) {
    $result['errorTXT'] = 'პირველადი შეტანის თარიღი უნდა იყოს გრაფიკის დაწყების თარიღზე ნაკლები';
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($lastPayment && (!calcValidateDate($lastPayDate) || !calcDateCompare($endDate, $lastPayDate))) {
    $result['errorTXT'] = 'ბოლო გადახდის თარიღი არ არის სწორად შევსებული';
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$arrDATA = [];
$remaining = $price;

if ($bookPayment > 0) {
    if (!calcValidateDate($bookPayDate)) {
        $result['errorTXT'] = 'ჯავშნის თარიღი არ არის შევსებული';
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $arrDATA[] = [
        'payment' => count($arrDATA) + 1,
        'date' => $bookPayDate,
        'amount' => $bookPayment,
        'leftToPay' => round($remaining - $bookPayment, 2),
    ];
    $remaining = round($remaining - $bookPayment, 2);
}

if ($advancePayment > 0) {
    $arrDATA[] = [
        'payment' => count($arrDATA) + 1,
        'date' => $advancePayDate,
        'amount' => $advancePayment,
        'leftToPay' => round($remaining - $advancePayment, 2),
    ];
    $remaining = round($remaining - $advancePayment, 2);
}

$rangePay = $paymentsCount > 0 ? round(($remaining - $lastPayment) / $paymentsCount, 2) : 0;
$dateWithFirstDay = calcStartDatesMonthsFirstDate($startDate);
$paymentDay = calcGetPaymentDay($startDate);
$currentDate = $startDate;
$leftToPay = $remaining;

for ($i = 0; $i < $paymentsCount; $i++) {
    if ($i != 0) {
        $dateWithFirstDay = calcDateAddMonths($dateWithFirstDay, $period);
        $currentDate = calcGetPaymentDate($dateWithFirstDay, $paymentDay);
    }
    if ($i == $paymentsCount - 1) {
        $amount = round($leftToPay - $lastPayment, 2);
    } else {
        $amount = $rangePay;
    }
    $leftToPay = round($leftToPay - $amount, 2);
    $arrDATA[] = [
        'payment' => count($arrDATA) + 1,
        'date' => $currentDate,
        'amount' => $amount,
        'leftToPay' => $leftToPay,
    ];
}

if ($lastPayment > 0) {
    $arrDATA[] = [
        'payment' => count($arrDATA) + 1,
        'date' => $lastPayDate,
        'amount' => $lastPayment,
        'leftToPay' => round($leftToPay - $lastPayment, 2),
    ];
}

$result = ['status' => 200, 'result' => $arrDATA];

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
