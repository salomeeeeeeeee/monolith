<?php
// ჯავშნის ვადის ცვლილების მოთხოვნა: ვალიდაცია + BP 21 "update reservation" (დასტური: user 3 + group_g20).
// ერთ რეზერვაციაზე ვადა მხოლოდ ერთხელ გრძელდება, იხ. local/php_interface/reservation_extension.php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;

function saveUpdateReservationRespond($status, $message)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => $status, "message" => $message], JSON_UNESCAPED_UNICODE);
    die();
}

ob_start();

if (!Loader::includeModule("crm") || !Loader::includeModule("bizproc")) {
    saveUpdateReservationRespond(500, "მოდული არ ჩაიტვირთა");
}
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/reservation_extension.php");

global $USER;
if (!$USER->IsAuthorized()) {
    saveUpdateReservationRespond(401, "ავტორიზაცია საჭიროა");
}

$input   = json_decode(file_get_contents("php://input"), true) ?: [];
$deal_id = (int)($input['deal_id'] ?? 0);
$comment = trim((string)($input['comment'] ?? ''));
$date    = trim((string)($input['contr_date'] ?? ''));

if (!$deal_id) {
    saveUpdateReservationRespond(405, "No Deal ID provided");
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !($newTs = reservationExtParseDate($date))) {
    saveUpdateReservationRespond(400, "გთხოვთ აირჩიოთ სწორი თარიღი");
}

$info = reservationExtInfo($deal_id);
if (!$info["ALLOWED"]) {
    saveUpdateReservationRespond(409, $info["REASON"]);
}
if ($info["DATE"] && $newTs <= $info["DATE"]) {
    saveUpdateReservationRespond(400, "ახალი ვადა მიმდინარე ვადაზე (" . reservationExtFormatDate($info["DATE"]) . ") გვიან უნდა იყოს");
}

$params = [
    "axaliTarigi"   => $date,
    "komentari"     => $comment,
    "dzivelitarigi" => (string)($info["DEAL"][RESERVATION_EXT_DATE_FIELD] ?? ''),
];

$arErrorsTmp = [];
$wfId = CBPDocument::StartWorkflow(
    RESERVATION_EXT_BP_ID,
    ["crm", "CCrmDocumentDeal", "DEAL_$deal_id"],
    $params,
    $arErrorsTmp
);

if (!$wfId) {
    saveUpdateReservationRespond(400, "Invalid Parameters");
}
saveUpdateReservationRespond(200, "Sent successfully");
