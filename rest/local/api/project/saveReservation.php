<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
CModule::IncludeModule('crm');

function printArr($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

function getContactInfo($contactId) {
    $arContact = array();
    $res = CCrmContact::GetList(array("ID" => "ASC"), array("ID" => $contactId), array("ID", "UF_CRM_1779873020955"));
    if ($arContact = $res->Fetch()) {
        $PHONE = \CCrmFieldMulti::GetList(array(), array(
            'ENTITY_ID'  => 'CONTACT',
            'TYPE_ID'    => 'PHONE',
            'VALUE_TYPE' => 'MOBILE|WORK',
            "ELEMENT_ID" => $arContact["ID"]
        ))->Fetch();
        $MAIL = \CCrmFieldMulti::GetList(array(), array(
            'ENTITY_ID'  => 'CONTACT',
            'TYPE_ID'    => 'EMAIL',
            'VALUE_TYPE' => 'HOME|WORK',
            "ELEMENT_ID" => $arContact["ID"]
        ))->Fetch();
        $arContact["PHONE"] = $PHONE["VALUE"];
        $arContact["EMAIL"] = $MAIL["VALUE"];
        return $arContact;
    }
    return $arContact;
}

// ── Read POST fields ──────────────────────────────────────────────────
$userSelect       = $_POST['userSelect']       ?? '';
$dealId           = $_POST['deal_id']          ?? '';
$reservationPrice = $_POST['reservationPrice'] ?? '';
$paymentType      = $_POST['paymentType']      ?? '';
$reservedate      = $_POST['reserveDate']      ?? ''; // DD/MM/YYYY from JS

// ── Get Bitrix site date formats ──────────────────────────────────────
$fullFormat  = CSite::GetDateFormat('FULL');  // e.g. "DD.MM.YYYY HH:MI:SS"
$shortFormat = CSite::GetDateFormat('SHORT'); // e.g. "DD.MM.YYYY"

// ── Today formatted for Bitrix datetime field ─────────────────────────
$todayForBitrix = CDatabase::FormatDate(
    date('Y-m-d H:i:s'),
    'YYYY-MM-DD HH:MI:SS',
    $fullFormat
);

// ── Reservation deadline — input from JS is DD/MM/YYYY ────────────────
$reserveDateForBitrix = '';
if ($reservedate) {
    $parts = explode("/", $reservedate);
    if (count($parts) === 3) {
        list($day, $month, $year) = $parts;
        $isoDate = $year . '-'
            . str_pad($month, 2, '0', STR_PAD_LEFT) . '-'
            . str_pad($day,   2, '0', STR_PAD_LEFT);
        $reserveDateForBitrix = CDatabase::FormatDate(
            $isoDate,
            'YYYY-MM-DD',
            $shortFormat
        );
    }
}

error_log("FULL format:          $fullFormat");
error_log("SHORT format:         $shortFormat");
error_log("Today formatted:      $todayForBitrix");
error_log("Reserve date input:   $reservedate");
error_log("Reserve date formatted: $reserveDateForBitrix");

// ── Handle uploaded passport file ─────────────────────────────────────
$xelshekrulebaID = null;
if (!empty($_FILES['passport']['tmp_name'])) {
    $xelshekrulebaID = CFile::SaveFile(
        CFile::MakeFileArray(
            $_FILES['passport']['tmp_name'],
            $_FILES['passport']['name']
        ),
        "crm"
    );
}

// ── Always update the deal fields ─────────────────────────────────────
$arrForAdd = [
    'UF_CRM_1779278640735' => $userSelect,
    'UF_CRM_1779278590201' => $todayForBitrix,
    'UF_CRM_1779278567041' => $reserveDateForBitrix,
];

$Deal   = new CCrmDeal();
$result = $Deal->Update($dealId, $arrForAdd);

error_log("Deal update result: " . var_export($result, true));
error_log("Fields sent: "        . print_r($arrForAdd, true));

// ── Workflow params base ──────────────────────────────────────────────
$params = [
    "type"             => $userSelect,
    "reserveDate"      => $reserveDateForBitrix,
    "reservationPrice" => $reservationPrice,
    "paymentType"      => $paymentType,
];

// ── File-dependent logic ──────────────────────────────────────────────
if ($xelshekrulebaID) {
    $contactId = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($dealId)[0];
    $contact   = getContactInfo($contactId);
    $fileInfo  = CFile::GetFileArray($xelshekrulebaID);

    $fileUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
               . "://" . $_SERVER['HTTP_HOST'] . $fileInfo["SRC"];
    $params["originalNameFile"] = $fileUrl;

    $serverPath = $_SERVER["DOCUMENT_ROOT"] . $fileInfo["SRC"];

    if (!$contact["UF_CRM_1779873020955"]) {
        $CCrmContact = new CCrmContact();
        $CCrmContact->Update($contactId, [
            "UF_CRM_1779873020955" => CFile::MakeFileArray($serverPath),
        ]);
    }
}

// ── Start workflow ────────────────────────────────────────────────────
$arErrorsTmp = array();
$wfId = CBPDocument::StartWorkflow(
    6,
    array("crm", "CCrmDocumentDeal", "DEAL_$dealId"),
    $params,
    $arErrorsTmp
);

error_log("Workflow ID: " . var_export($wfId, true));
if (!empty($arErrorsTmp)) {
    error_log("Workflow errors: " . print_r($arErrorsTmp, true));
}

// ── Response ──────────────────────────────────────────────────────────
$resArr = [];
if (!empty($dealId) && is_numeric($dealId)) {
    if ($wfId) {
        $resArr["status"]  = 200;
        $resArr["message"] = "Sent successfully";
    } else {
        $resArr["status"]  = 400;
        $resArr["message"] = "Invalid Parameters";
        $resArr["errors"]  = $arErrorsTmp;
    }
} else {
    $resArr["status"]  = 405;
    $resArr["message"] = "Method not Found";
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArr, JSON_UNESCAPED_UNICODE);
?>