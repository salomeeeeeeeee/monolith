<?php
// 24 სთ-ში რეზერვაციის ვადა ეწურება: რეზერვაციის ეტაპზე მყოფი დილები, რომელთა ვადა ხვალ იწურება.
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

$tomorrow = (new DateTime("tomorrow"))->format("d/m/Y");
$deals = [];

if ($USER->IsAuthorized()) {
    $currentUserId = (int)$USER->GetID();
    $showAllDeals = $currentUserId === 1 || $USER->IsAdmin();

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
        if ($deadline !== $tomorrow) {
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
}

$response = [
    "deals" => $deals,
    "tomorrow" => $tomorrow,
    "count" => count($deals),
];

ob_end_clean();
header("Content-Type: application/json; charset=utf-8");
echo json_encode($response, JSON_UNESCAPED_UNICODE);
