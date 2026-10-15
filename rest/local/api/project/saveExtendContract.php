<?php
// ხელშეკრულების ვადის გაგრძელების მოთხოვნის გაგზავნა: ვალიდაცია + BP "ხელშეკრულების ვადის გაგრძელება" (ნუკის დასტური).
// იხ. local/php_interface/contract_deadline.php და bitrix/bp/contract-extension-template.php
define("NO_KEEP_STATISTIC", true);
require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;

function extendContractRespond($status, $message)
{
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => $status, "message" => $message], JSON_UNESCAPED_UNICODE);
    die();
}

ob_start();

if (!Loader::includeModule("crm") || !Loader::includeModule("bizproc")) {
    extendContractRespond(500, "მოდული არ ჩაიტვირთა");
}
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/contract_deadline.php");

global $USER;
if (!$USER->IsAuthorized()) {
    extendContractRespond(401, "ავტორიზაცია საჭიროა");
}
if (CONTRACT_EXTEND_BP_ID <= 0) {
    extendContractRespond(500, "გაგრძელების ბიზნეს-პროცესი არ არის დაყენებული");
}

$input   = json_decode(file_get_contents("php://input"), true) ?: [];
$deal_id = (int)($input["deal_id"] ?? 0);
$comment = trim((string)($input["comment"] ?? ""));
$date    = trim((string)($input["contr_date"] ?? ""));

if ($deal_id <= 0) {
    extendContractRespond(400, "დილი არ არის მითითებული");
}
if ($comment === "") {
    extendContractRespond(400, "კომენტარი სავალდებულოა");
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !($newTs = strtotime($date . " 23:59:59"))) {
    extendContractRespond(400, "თარიღი არასწორია");
}

$info = contractDeadlineForDeal($deal_id);
if (!$info) {
    extendContractRespond(409, "დილი ხელშეკრულების ეტაპზე არ არის");
}
if ($newTs <= $info["DEADLINE"]) {
    extendContractRespond(400, "ახალი ვადა მიმდინარე ვადაზე (" . contractDeadlineFormat($info["DEADLINE"]) . ") გვიან უნდა იყოს");
}
if (contractDeadlineRequestPending($deal_id)) {
    extendContractRespond(409, "გაგრძელების მოთხოვნა უკვე გაგზავნილია და დასტურს ელოდება");
}

$params = [
    "axaliTarigi"   => $date,
    "komentari"     => $comment,
    "dzivelitarigi" => contractDeadlineFormat($info["DEADLINE"]),
];

$errors = [];
$wfId = CBPDocument::StartWorkflow(
    CONTRACT_EXTEND_BP_ID,
    ["crm", "CCrmDocumentDeal", "DEAL_" . $deal_id],
    $params,
    $errors
);

if (!$wfId) {
    extendContractRespond(400, "მოთხოვნა ვერ გაიგზავნა: " . implode("; ", array_map(fn($e) => $e["message"] ?? "", $errors)));
}
extendContractRespond(200, "მოთხოვნა გაიგზავნა");
