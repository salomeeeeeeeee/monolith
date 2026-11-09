<?php
// 24 სთ-ში რეზერვაციის ვადა ეწურება: რეზერვაციის ეტაპზე მყოფი დილები, რომელთა ვადა დღეს ან ხვალ იწურება
// (ლიდების რეპორტის Liveboard-ის "ვადა დღეს იწურება" + "ვადა ხვალ იწურება").
ob_start();
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('crm');

// მოთხოვნილი რეზერვაცია, დადასტურებული რეზერვაცია
const EXPIRING_RESERVATION_STAGES = ['PREPAYMENT_INVOICE', 'FINAL_INVOICE'];
// დარეზერვებულია თარიღამდე
const EXPIRING_RESERVATION_DATE_FIELD = 'UF_CRM_1779278567041';

function normalizeReservationDate($value)
{
    if (empty($value)) {
        return "";
    }

    if ($value instanceof \Bitrix\Main\Type\Date) {
        return $value->format("d/m/Y");
    }

    if (!is_string($value)) {
        return "";
    }

    $value = trim($value);
    foreach (["!d/m/Y", "!d.m.Y", "!Y-m-d", "d/m/Y H:i:s", "d.m.Y H:i:s"] as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            return $dt->format("d/m/Y");
        }
    }

    return "";
}

$today = (new DateTime("today"))->format("d/m/Y");
$tomorrow = (new DateTime("tomorrow"))->format("d/m/Y");
$deals = [];

if ($USER->IsAuthorized()) {
    $currentUserId = (int)$USER->GetID();
    // 9 - ნუკი პაიჭაძე (სეილს ჰედი): ყველა დილს ხედავს, როგორც ადმინი
    $showAllDeals = in_array($currentUserId, [1, 9], true) || $USER->IsAdmin();

    $arFilter = [
        "CHECK_PERMISSIONS" => "N",
        "CATEGORY_ID" => 0,
        "STAGE_ID" => EXPIRING_RESERVATION_STAGES,
    ];
    if (!$showAllDeals) {
        $arFilter["ASSIGNED_BY_ID"] = $currentUserId;
    }

    $res = CCrmDeal::GetListEx(
        ["ID" => "ASC"],
        $arFilter,
        false,
        false,
        ["ID", "STAGE_ID", "CONTACT_ID", "COMPANY_ID", "CONTACT_FULL_NAME", "COMPANY_TITLE", "ASSIGNED_BY_ID", EXPIRING_RESERVATION_DATE_FIELD]
    );
    while ($deal = $res->Fetch()) {
        $deadline = normalizeReservationDate($deal[EXPIRING_RESERVATION_DATE_FIELD] ?? "");
        if ($deadline !== $today && $deadline !== $tomorrow) {
            continue;
        }

        $clientName = "";
        $clientId = 0;
        $clientType = "";

        if (!empty($deal["CONTACT_ID"])) {
            $clientId = (int)$deal["CONTACT_ID"];
            $clientName = (string)$deal["CONTACT_FULL_NAME"];
            $clientType = "contact";
        } elseif (!empty($deal["COMPANY_ID"])) {
            $clientId = (int)$deal["COMPANY_ID"];
            $clientName = (string)$deal["COMPANY_TITLE"];
            $clientType = "company";
        }

        $deals[] = [
            "ID" => (int)$deal["ID"],
            "CLIENT_ID" => $clientId,
            "CLIENT_NAME" => $clientName,
            "CLIENT_TYPE" => $clientType,
            "DEADLINE" => $deadline,
        ];
    }

    // ჯერ დღეს ვადაგასულები, მერე ხვალინდელები
    usort($deals, function ($a, $b) use ($today) {
        $aToday = $a["DEADLINE"] === $today ? 0 : 1;
        $bToday = $b["DEADLINE"] === $today ? 0 : 1;
        return $aToday <=> $bToday ?: $a["ID"] <=> $b["ID"];
    });
}

$response = [
    "deals" => $deals,
    "today" => $today,
    "tomorrow" => $tomorrow,
    "count" => count($deals),
];

ob_end_clean();
header("Content-Type: application/json; charset=utf-8");
echo json_encode($response, JSON_UNESCAPED_UNICODE);
