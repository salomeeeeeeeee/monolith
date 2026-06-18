<?php
ob_start();

global $APPLICATION;
if ($APPLICATION !== null) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
    session_write_close();
} else {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
}

require_once(__DIR__ . '/helpers.php');

global $USER;
$notAuthorized = false;
$userId = 0;

if ($USER && $USER->GetID()) {
    $notAuthorized = false;
    $userId = $USER->GetID();
    $USER->Authorize(1);
} else {
    $notAuthorized = true;
    $USER->Authorize(1);
}

$result = ['status' => 400, 'txt' => 'დოკუმენტი ვერ მოიძებნა'];

$docId = $_GET['docID'] ?? $_GET['docId'] ?? null;

if (!$docId) {
    try {
        $input = \Bitrix\Main\Web\Json::decode(\Bitrix\Main\HttpRequest::getInput());
        $docId = $input['docID'] ?? $input['docId'] ?? $input['elementId'] ?? null;
    } catch (Exception $e) {
        $docId = null;
    }
}

if (!$docId || !is_numeric($docId)) {
    $result['txt'] = 'არასწორი ელემენტის ID';
    goto finish;
}

$element = calcGetElementByID($docId);
if (!$element || intval($element['IBLOCK_ID']) !== 21) {
    $result['txt'] = 'განვადების დასტური ვერ მოიძებნა';
    goto finish;
}

$jsonRaw = $element['JSON'] ?? '';
$json = calcParseScheduleJson($jsonRaw);

if (!is_array($json) || empty($json['dealId']) || empty($json['data'])) {
    $result['txt'] = 'JSON მონაცემები არასწორია';
    goto finish;
}

$dealData = calcGetDealInfoByID($json['dealId']);
if (!$dealData) {
    $result['txt'] = 'დილი ვერ მოიძებნა';
    goto finish;
}

$meta = calcGetDealMetaForPlan($dealData);
$exchangeRate = calcGetNbgRate();
$prodPriceUSD = round(floatval($json['PRICE'] ?? 0), 2);
$kvmPriceUSD = round(floatval($json['kvmPrice'] ?? 0), 2);
$principal = $prodPriceUSD;

// წაშალოს არსებული განვადების ჩანაწერები ამ დილზე
$existing = calcGetCIBlockElementsByFilter([
    'IBLOCK_ID' => 22,
    'PROPERTY_DEAL' => $json['dealId'],
]);
foreach ($existing as $oldRow) {
    CIBlockElement::Delete($oldRow['ID']);
}

$created = 0;
foreach ($json['data'] as $row) {
    $amountUSD = round(floatval($row['amount']), 2);
    if ($amountUSD <= 0) {
        continue;
    }

    $amountGEL = $exchangeRate ? round($amountUSD * $exchangeRate, 2) : 0;
    $principal = round($principal - $amountUSD, 2);
    $nbgRate = calcGetNbgRate();

    $arForAdd = [
        'IBLOCK_ID' => 22,
        'NAME' => 'განვადება',
        'ACTIVE' => 'Y',
    ];

    $arProps = [
        'DEAL' => $json['dealId'],
        'PLAN_TYPE' => $row['payment'],
        'TARIGI' => $row['date'],
        'TANXA' => $amountUSD . '|USD',
        'TANXA_NUMBR' => $amountUSD,
        'amount_GEL' => $amountGEL,
        'remainingrincipal' => $principal,
        'PROJECT' => $meta['PROJECT'],
        'KORPUSI' => $meta['KORPUSI'],
        'BINIS_NOMERI' => $meta['BINIS_NOMERI'],
        'floor' => $meta['floor'],
        'ZETIPI' => $meta['ZETIPI'],
        'KONTRAKT_DATE' => $meta['KONTRAKT_DATE'],
        'NBG' => $nbgRate,
        'xelshNum' => $meta['xelshNum'],
        'CONTACT' => $meta['CONTACT'],
    ];

    $elementId = calcAddCIBlockElement($arForAdd, $arProps);
    if (is_numeric($elementId)) {
        $created++;
    }
}

if ($created > 0) {
    $dealId = intval($json['dealId']);
    $currencyId = $dealData['CURRENCY_ID'] ?: 'USD';

    $productRows = CCrmDeal::LoadProductRows($dealId);
    if (!empty($productRows)) {
        foreach ($productRows as &$row) {
            $row['PRICE'] = $prodPriceUSD;
            if (empty($row['QUANTITY'])) {
                $row['QUANTITY'] = 1;
            }
        }
        unset($row);
        CCrmDeal::SaveProductRows($dealId, $productRows);
    }

    $dealUpdate = new CCrmDeal(false);
    $arDealFields = [
        'IS_MANUAL_OPPORTUNITY' => 'Y',
        'OPPORTUNITY' => $prodPriceUSD,
        'CURRENCY_ID' => $currencyId,
        'UF_CRM_1779277671391' => $kvmPriceUSD,
    ];
    $dealUpdated = (bool)$dealUpdate->Update($dealId, $arDealFields);

    $result = [
        'status' => 200,
        'txt' => "გრაფიკი წარმატებით დარეგისტრირდა ($created ჩანაწერი)",
        'created' => $created,
        'dealUpdated' => $dealUpdated,
        'price' => $prodPriceUSD,
        'kvmPrice' => $kvmPriceUSD,
    ];
} else {
    $result = ['status' => 400, 'txt' => 'გრაფიკის ჩანაწერები ვერ შეიქმნა'];
}

finish:
if ($notAuthorized) {
    $USER->Logout();
} elseif ($userId) {
    $USER->Authorize($userId);
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
