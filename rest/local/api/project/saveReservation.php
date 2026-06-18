<?php
ob_start();
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('crm');

function getCorrectDateRA($date) {
    if (!$date) return ["", ""];
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
        return [$date, $date];
    }
    $parts   = explode("T", $date);
    $dateArr = explode("-", $parts[0]);
    $fixedDate = $dateArr[2] . "/" . $dateArr[1] . "/" . $dateArr[0];
    return [$fixedDate, $fixedDate];
}

function getContactInfo($contactId) {
    $arContact = array();
    $res = CCrmContact::GetList(array("ID" => "ASC"), array("ID" => $contactId), array("ID", "UF_CRM_1779873020955"));
    if ($arContact = $res->Fetch()) {
        $PHONE = \CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'MOBILE|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $MAIL  = \CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'HOME|WORK',  "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $arContact["PHONE"] = $PHONE["VALUE"];
        $arContact["EMAIL"] = $MAIL["VALUE"];
        return $arContact;
    }
    return $arContact;
}

$userSelect      = $_POST['userSelect']  ?? '';
$dealId          = $_POST['deal_id']     ?? '';
$comment         = $_POST['comment']     ?? '';
$prodNum         = $_POST['prodNum']     ?? '';
$passportFileId  = $_POST['passport'] ?? null;
$filePath        = $passportFileId ? $_SERVER["DOCUMENT_ROOT"] . CFile::GetPath($passportFileId) : null;

$firstName  = trim($_POST['firstName']   ?? '');
$lastName   = trim($_POST['lastName']    ?? '');
$idNumber   = trim($_POST['idNumber']    ?? '');
$phone      = trim($_POST['phone']       ?? '');
$contactId  = intval($_POST['contact_id'] ?? 0);

// fallback: resolve contact from deal if not passed
if (!$contactId && $dealId) {
    $contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($dealId);
    $contactId  = intval($contactIds[0] ?? 0);
}

list($reserveDate, $onlyDate) = getCorrectDateRA($_POST['reserveDate'] ?? '');

$params = array(
    "type"        => $userSelect,
    "numOfAps"    => $prodNum,
    "reserveDate" => $reserveDate,
    "onlyDate"    => $onlyDate,
    "comment"     => $comment,
    "firstName"   => $firstName,
    "lastName"    => $lastName,
    "idNumber"    => $idNumber,
    "passportFile"     => $filePath ? CFile::MakeFileArray($filePath) : '',
    "passportFileLink" => $passportFileId ? "https://" . $_SERVER["HTTP_HOST"] . CFile::GetPath($passportFileId) : '',
);

// ── Update contact: NAME, LAST_NAME, ID number, PHONE, passport file ──
$contactUpdateLog = "no contact update\n";
if ($contactId > 0) {
    $contactFields = [];

    if ($firstName !== '') $contactFields['NAME']      = $firstName;
    if ($lastName  !== '') $contactFields['LAST_NAME'] = $lastName;
    if ($idNumber  !== '') $contactFields['UF_CRM_1781244744534'] = $idNumber;

    // Passport file
    if ($passportFileId && $filePath) {
        $contactFields['UF_CRM_1779873020955'] = CFile::MakeFileArray($filePath);
    }

    // Phone — only update if a value was provided
    if ($phone !== '') {
        // fetch existing phone row to get its ID so we update rather than duplicate
        $existingPhone = \CCrmFieldMulti::GetList(
            [],
            ['ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'PHONE', 'ELEMENT_ID' => $contactId]
        )->Fetch();

        if ($existingPhone) {
            $contactFields['FM'] = [
                'PHONE' => [
                    $existingPhone['ID'] => [
                        'ID'         => $existingPhone['ID'],
                        'VALUE'      => $phone,
                        'VALUE_TYPE' => $existingPhone['VALUE_TYPE'] ?: 'WORK',
                    ]
                ]
            ];
        } else {
            $contactFields['FM'] = [
                'PHONE' => [
                    'n0' => ['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']
                ]
            ];
        }
    }

    if (!empty($contactFields)) {
        $contactObj = new CCrmContact(false);
        $updateResult = $contactObj->Update($contactId, $contactFields);
        $contactUpdateLog = "contact update result: " . var_export($updateResult, true) . "\n"
            . "fields: " . print_r($contactFields, true) . "\n";
    }
}

// ── Date formatting ──
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

// ── Deal update ──
$arrForAdd = [
    'UF_CRM_1779278640735' => $userSelect,
    'UF_CRM_1779278590201' => $todayForBitrix,
    'UF_CRM_1779278567041' => $reserveDateForBitrix,
    'UF_CRM_1781181993925' => $comment,
];

$Deal = new CCrmDeal();
$Deal->Update($dealId, $arrForAdd);

// ── Start workflow ──
$arErrorsTmp = array();
$wfId = CBPDocument::StartWorkflow(
    6,
    array("crm", "CCrmDocumentDeal", "DEAL_$dealId"),
    $params,
    $arErrorsTmp
);

file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/saveres_errors.txt",
    "dealId: " . $dealId . "\n" .
    "contactId: " . $contactId . "\n" .
    "wfId: " . var_export($wfId, true) . "\n" .
    $contactUpdateLog .
    "params: " . print_r($params, true) . "\n" .
    "errors: " . print_r($arErrorsTmp, true) . "\n" .
    "POST: " . print_r($_POST, true) . "\n" .
    "passportFileId: " . var_export($passportFileId, true) . "\n"
);

// ── Response ──
$resArr = array();
if (!empty($dealId) && is_numeric($dealId)) {
    $resArr["status"]  = 200;
    $resArr["message"] = "Sent successfully";
    if (!$wfId) {
        $resArr["wf_warning"] = "Workflow may not have started";
        $resArr["wf_errors"]  = $arErrorsTmp;
    }
} else {
    $resArr["status"]  = 405;
    $resArr["message"] = "Method not Found";
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArr, JSON_UNESCAPED_UNICODE);
?>