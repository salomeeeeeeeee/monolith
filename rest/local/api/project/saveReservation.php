<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
CModule::IncludeModule('crm');

function printArr ($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

function getCorrectDateRA($date) {
    if ($date) {
        $parts = explode("T", $date);
        $dateArr = explode("-", $parts[0]);
        $fixedDate = $dateArr[1] . "/" . $dateArr[2] . "/" . $dateArr[0];
        $onlyDate = $fixedDate;
        if (!empty($parts[1])) {
            $timeParts = explode(":", $parts[1]);
            // Ensure HH:MM:SS format regardless of input
            $fixedDate .= " " . $timeParts[0] . ":" . $timeParts[1] . ":" . ($timeParts[2] ?? "00");
        }

        return [$fixedDate, $onlyDate];
    } else {
        return "";
    }
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

// Read POST fields
$userSelect       = $_POST['userSelect']       ?? '';
$dealId           = $_POST['deal_id']          ?? '';  
$reservationPrice = $_POST['reservationPrice'] ?? '';
$paymentType      = $_POST['paymentType']      ?? '';

// Handle the datetime
list($reserveDate, $onlyDate) = getCorrectDateRA($_POST['reserveDate'] ?? '');

// Handle uploaded passport file
$xelshekrulebaID = null;
if (!empty($_FILES['passport']['tmp_name'])) {
    // Save to Bitrix file system
    $xelshekrulebaID = CFile::SaveFile(
        CFile::MakeFileArray($_FILES['passport']['tmp_name'], $_FILES['passport']['name']),
        "crm"
    );
}


$params = array("type" => $userSelect,
                "numOfAps" => $prodNum,
                "reserveDate" => $reserveDate,
                "onlyDate" => $onlyDate,
                "reservationPrice" => $reservationPrice,
                "paymentType" => $paymentType,
);


if ($xelshekrulebaID){
    $contactId = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($dealId)[0];
    $contact = getContactInfo($contactId);

    $fileInfo = CFile::GetFileArray($xelshekrulebaID);

    // $originalNameFile = pathinfo($fileInfo["ORIGINAL_NAME"], PATHINFO_FILENAME);
    // $params["originalNameFile"] = $originalNameFile;

    // Build full URL and pass to workflow
    $fileUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
               . "://" . $_SERVER['HTTP_HOST'] . $fileInfo["SRC"];
    $params["originalNameFile"] = $fileUrl;

    $serverPath = $_SERVER["DOCUMENT_ROOT"] . $fileInfo["SRC"];

    if (!$contact["UF_CRM_1779873020955"]) {
        $CCrmContact = new CCrmContact();
        $upd = array(
            "UF_CRM_1779873020955" => CFile::MakeFileArray($serverPath), 
        );
        $updateReserve = $CCrmContact->Update($contactId, $upd);
    }
}

$arErrorsTmp = array();
$wfId = CBPDocument::StartWorkflow(
    6,                                                               //პროცესის ID
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