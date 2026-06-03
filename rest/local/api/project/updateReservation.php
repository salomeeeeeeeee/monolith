<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
CModule::IncludeModule('crm');

function printArr ($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

function getCorrectDateRA($date) {
    if (!$date) return ["", ""];
    // Already DD/MM/YYYY (from JS reservation popup)
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
        return [$date, $date];
    }
    // ISO format YYYY-MM-DDTHH:MM:SS
    $parts   = explode("T", $date);
    $dateArr = explode("-", $parts[0]);
    $fixedDate = $dateArr[2] . "/" . $dateArr[1] . "/" . $dateArr[0];
    return [$fixedDate, $fixedDate];
}

function getContactInfo($contactId) {
    $arContact = array();
    $res = CCrmContact::GetList(array("ID" => "ASC"), array("ID" => $contactId), array("ID", "UF_CRM_1779873020955"));
    if($arContact = $res->Fetch()){
        $PHONE=\CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT','TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'MOBILE|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $MAIL=\CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT','TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'HOME|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $arContact["PHONE"] = $PHONE["VALUE"];
        $arContact["EMAIL"] = $MAIL["VALUE"];
        return $arContact;
    }
    return $arContact;
}

$postdata= $_POST;
$xelshekrulebaID = $_FILES['passport'] ?? null;
$userSelect = $postdata['userSelect'];
$dealId = $postdata['deal_id']; 
list($reserveDate, $onlyDate) = getCorrectDateRA($postdata['reserveDate']);
$prodNum = $postdata['prodNum'];
$reservationPrice = $postdata['reservationPrice'];
$paymentType = $postdata['paymentType'];
$dgeebi = $postdata['dgeebi'];
$currency = $_POST['currency'] ?? 'GEL';
$params["currency"] = $currency;

$params = array("type" => $userSelect,
                "numOfAps" => $prodNum,
                "reserveDate" => $reserveDate,
                "onlyDate" => $onlyDate,
                "reservationPrice" => $reservationPrice,
                "paymentType" => $paymentType,
);


if ($xelshekrulebaID && $xelshekrulebaID['error'] === UPLOAD_ERR_OK) {
    $contactId = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($dealId)[0];
    $contact = getContactInfo($contactId);

    $serverPath = $xelshekrulebaID['tmp_name'];

    if (!$contact["UF_CRM_1779873020955"]) {
        $CCrmContact = new CCrmContact();
        $upd = array(
            "UF_CRM_1779873020955" => CFile::MakeFileArray($serverPath),
        );
        $updateReserve = $CCrmContact->Update($contactId, $upd);
    }
}

$fullFormat  = CSite::GetDateFormat('FULL');
$shortFormat = CSite::GetDateFormat('SHORT');

$todayForBitrix = CDatabase::FormatDate(
    date('Y-m-d H:i:s'),
    'YYYY-MM-DD HH:MI:SS',
    $fullFormat
);

$reserveDateForBitrix = '';
if ($onlyDate) {
    $parts = explode("/", $onlyDate);
    if (count($parts) === 3) {
        list($day, $month, $year) = $parts;
        $isoDate = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
        $reserveDateForBitrix = CDatabase::FormatDate($isoDate, 'YYYY-MM-DD', $shortFormat);
    }
}

$arrForAdd = [
    'UF_CRM_1779278640735' => $userSelect,
    'UF_CRM_1779278590201' => $todayForBitrix,
    'UF_CRM_1779278567041' => $reserveDateForBitrix,
];
$Deal = new CCrmDeal();
$Deal->Update($dealId, $arrForAdd);

$arErrorsTmp = array();
$wfId = CBPDocument::StartWorkflow(
    8,                                                               //პროცესის ID
    array("crm", "CCrmDocumentDeal", "DEAL_$dealId"),        // deal || contact || lead || company
    $params,
    $arErrorsTmp
);


$resArr = array();
if(!empty($dealId) && is_numeric($dealId)) {
    if($wfId) {
        $resArr["status"] = 200;
        $resArr["message"] = "Sent successfully";

    } else {
        $resArr["status"] = 400;
        $resArr["message"] = "Invalid Parameters";
    }
} else {
    $resArr["status"] = 405;
    $resArr["message"] = "Method not Found";
}



ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArr, JSON_UNESCAPED_UNICODE);
?>