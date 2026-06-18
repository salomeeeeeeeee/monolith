<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;

if (!Loader::includeModule("crm")) {
    die(json_encode(["status" => 400, "message" => "CRM module missing"]));
}

CModule::IncludeModule('webservice');

$input   = json_decode(file_get_contents("php://input"), true);
$deal_id = (int)($input['deal_id'] ?? 0);
$comment = $input['comment']    ?? '';
$date    = $input['contr_date'] ?? '';

if (!$deal_id) {
    echo json_encode(["status" => 405, "message" => "No Deal ID provided"]);
    die();
}

if (!$date) {
    echo json_encode(["status" => 400, "message" => "No date provided"]);
    die();
}

$res  = CCrmDeal::GetList([], ["ID" => $deal_id], ["UF_CRM_1779278567041"]);
$deal = $res->Fetch();
$oldDate = $deal["UF_CRM_1779278567041"] ?? '';

$params = [
    "axaliTarigi" => $date,  
    "komentari"   => $comment,
    "dzivelitarigi" => $oldDate,
];

$arErrorsTmp = [];
$wfId = CBPDocument::StartWorkflow(
    21,
    ["crm", "CCrmDocumentDeal", "DEAL_$deal_id"],
    $params,
    $arErrorsTmp
);

$resArr = $wfId
    ? ["status" => 200, "message" => "Sent successfully"]
    : ["status" => 400, "message" => "Invalid Parameters"];

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArr, JSON_UNESCAPED_UNICODE);
?>