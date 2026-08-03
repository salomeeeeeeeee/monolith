<?php
ob_start();
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('crm');

date_default_timezone_set('Asia/Tbilisi');

/** Absolute public URL with encoded filename (spaces, (), etc.) */
function buildPassportFileLink($fileId) {
    $path = CFile::GetPath($fileId);
    if (!$path) {
        return '';
    }
    $parts = explode('/', $path);
    $encoded = array_map(function ($part) {
        return $part === '' ? '' : rawurlencode($part);
    }, $parts);
    $host = preg_replace('/:\d+$/', '', $_SERVER["HTTP_HOST"]);
    return "https://" . $host . implode('/', $encoded);
}


// ── Helper: reuse the same IBlock element fetch logic as docs_generation ──
function getCIBlockElementsByFilter($arFilter) {
    $arElements = array();
    $res = CIBlockElement::GetList(array("ID" => "ASC"), $arFilter, false, array("nPageSize" => 99999), array());
    while ($ob = $res->GetNextElement()) {
        $arFields = $ob->GetFields();
        $arProps  = $ob->GetProperties();
        $row = array();
        foreach ($arFields as $k => $v) $row[$k] = $v;
        foreach ($arProps  as $k => $p) $row[$k] = $p["VALUE"];
        $arElements[] = $row;
    }
    return $arElements;
}

// ── First payment lookup for this deal (IBlock 22) ──
CModule::IncludeModule('iblock');

$scheduleRows = getCIBlockElementsByFilter(array("IBLOCK_ID" => 22, "PROPERTY_DEAL" => $dealId));
usort($scheduleRows, function($a, $b) {
    $dateA = DateTime::createFromFormat('d/m/Y', $a['TARIGI'] ?? '');
    $dateB = DateTime::createFromFormat('d/m/Y', $b['TARIGI'] ?? '');
    if (!$dateA && !$dateB) return 0;
    if (!$dateA) return 1;
    if (!$dateB) return -1;
    return $dateA <=> $dateB;
});

$firstPaymentRaw  = !empty($scheduleRows) ? (float)explode("|", $scheduleRows[0]["TANXA"] ?? "")[0] : 0;
$firstPayment     = !empty($scheduleRows) ? number_format($firstPaymentRaw, 2, '.', ',') : '';
$firstPaymentDate = !empty($scheduleRows) ? ($scheduleRows[0]["TARIGI"] ?? '') : '';


// ── Inputs ──────────────────────────────────────────────────────────
$dealId    = intval($_POST['deal_id']    ?? 0);
$contactId = intval($_POST['contact_id'] ?? 0);
$contrDate = trim($_POST['contr_date']   ?? '');   // YYYY-MM-DD from <input type="date">
$firstName = trim($_POST['firstName']    ?? '');
$lastName  = trim($_POST['lastName']     ?? '');
$idNumber  = trim($_POST['idNumber']     ?? '');

// fallback: resolve contact from deal
if (!$contactId && $dealId) {
    $contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($dealId);
    $contactId  = intval($contactIds[0] ?? 0);
}

// ── Passport file upload ─────────────────────────────────────────────
$passportFileId   = null;
$passportFilePath = null;
$passportFileLink = '';

if (!empty($_FILES['passport']['tmp_name'])) {
    $file     = $_FILES['passport'];
    $origName = $file['name'];
    $tmpPath  = $file['tmp_name'];

    $arFile = CFile::MakeFileArray($tmpPath, $file['type']);
    $arFile['name'] = $origName;
    $arFile['MODULE_ID'] = 'crm';

    $savedId = CFile::SaveFile($arFile, 'crm');

    if ($savedId) {
        $passportFileId   = $savedId;
        $passportFilePath = $_SERVER["DOCUMENT_ROOT"] . CFile::GetPath($savedId);
        $passportFileLink = buildPassportFileLink($savedId);
    }
}

// ── Date formatting ──────────────────────────────────────────────────
$fullFormat  = CSite::GetDateFormat('FULL');
$shortFormat = CSite::GetDateFormat('SHORT');

$todayForBitrix = CDatabase::FormatDate(
    date('Y-m-d H:i:s'),
    'YYYY-MM-DD HH:MI:SS',
    $fullFormat
);

// contr_date: YYYY-MM-DD  →  Bitrix SHORT format (dd/mm/yyyy)
$contrDateForBitrix = '';
if ($contrDate) {
    $contrDateForBitrix = CDatabase::FormatDate($contrDate, 'YYYY-MM-DD', $shortFormat);
}

// ── Update contact ───────────────────────────────────────────────────
$contactUpdateLog = "no contact update\n";
if ($contactId > 0) {
    $contactFields = [];

    if ($firstName !== '') $contactFields['NAME']      = $firstName;
    if ($lastName  !== '') $contactFields['LAST_NAME'] = $lastName;
    if ($idNumber  !== '') $contactFields['UF_CRM_1781244744534'] = $idNumber;

    if ($passportFileId && $passportFilePath) {
        $contactFields['UF_CRM_1779873020955'] = CFile::MakeFileArray($passportFilePath);
    }

    if (!empty($contactFields)) {
        $contactObj   = new CCrmContact(false);
        $updateResult = $contactObj->Update($contactId, $contactFields);
        $contactUpdateLog = "contact update result: " . var_export($updateResult, true) . "\n"
            . "fields: " . print_r($contactFields, true) . "\n";
    }
}

// ── Update deal ──────────────────────────────────────────────────────
$arrForDeal = [
    'UF_CRM_1779278774084' => $contrDateForBitrix,   // ხელშეკრულების გაფორმების თარიღი
    'UF_CRM_1779278590201' => $todayForBitrix,        // today (reuse existing field)
];

$dealObj = new CCrmDeal();
$dealObj->Update($dealId, $arrForDeal);

$dealUrl = "https://" . preg_replace('/:\d+$/', '', $_SERVER["HTTP_HOST"]) . "/crm/deal/details/{$dealId}/";
$dealLinkBBCode = "[URL={$dealUrl}]Deal #{$dealId}[/URL]";

$params = [
    "dealId"           => $dealId,
    "dealUrl"          => $dealUrl,
    "dealLink"         => $dealLinkBBCode,   
    "contrDate"        => $contrDateForBitrix,
    "firstName"        => $firstName,
    "lastName"         => $lastName,
    "idNumber"         => $idNumber,
    "passportFile"     => ($passportFilePath ? CFile::MakeFileArray($passportFilePath) : ''),
    "passportFileLink" => $passportFileLink,
    "firstPayment"      => $firstPayment,      
    "firstPaymentDate"  => $firstPaymentDate,   
];
// ── Start workflow ───────────────────────────────────────────────────
$arErrorsTmp = [];
$wfId = CBPDocument::StartWorkflow(
    26,   // <-- replace with your actual sell workflow ID
    ["crm", "CCrmDocumentDeal", "DEAL_$dealId"],
    $params,
    $arErrorsTmp
);

// ── Debug log ────────────────────────────────────────────────────────
file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/savesell_errors.txt",
    "dealId: $dealId\n" .
    "contactId: $contactId\n" .
    "contrDate: $contrDate → $contrDateForBitrix\n" .
    "wfId: " . var_export($wfId, true) . "\n" .
    $contactUpdateLog .
    "params: " . print_r($params, true) . "\n" .
    "errors: " . print_r($arErrorsTmp, true) . "\n" .
    "POST: " . print_r($_POST, true) . "\n" .
    "FILES: " . print_r($_FILES, true) . "\n"
);

// ── Response ─────────────────────────────────────────────────────────
$resArr = [];
if ($dealId > 0) {
    $resArr["status"]  = 200;
    $resArr["message"] = "Sent successfully";
    if (!$wfId) {
        $resArr["wf_warning"] = "Workflow may not have started";
        $resArr["wf_errors"]  = $arErrorsTmp;
    }
} else {
    $resArr["status"]  = 405;
    $resArr["message"] = "Invalid deal ID";
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArr, JSON_UNESCAPED_UNICODE);
?>