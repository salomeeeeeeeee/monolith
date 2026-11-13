<?php
// 24 სთ-ში რეზერვაციის ვადა ეწურება: რეზერვაციის ეტაპზე მყოფი დილები, რომელთა ვადა დღეს ან ხვალ იწურება
// (ლიდების რეპორტის Liveboard-ის "ვადა დღეს იწურება" + "ვადა ხვალ იწურება"), და ისინიც, რომლებიც
// 24 სთ-ში თავისუფლდება - მაგ. პარასკევის ვადიანი ჯავშანი ორშაბათს 13:00-ზე (release_time.php).
// DEADLINE: ჯავშნის თარიღი, დადასტურებულზე + გათავისუფლების დრო ("13/11/2026 → 16/11 13:00").
ob_start();
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('crm');
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/reservation_extension.php");

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

/**
 * დადასტურებული ჯავშნის გათავისუფლების დრო (ts) ან false:
 * გაგრძელებული - reservation_extension agent-ი (მომდევნო სამუშაო დღე, ორშაბათს 13:00),
 * სხვა - BP 6 / 99 / 100-ის პაუზა (ორშაბათის წესით).
 */
function expiringReservationReleaseTime($deal, $dateTs, $releaseByDeal)
{
    if ($deal["STAGE_ID"] !== RESERVATION_EXT_STAGE || !$dateTs) {
        return false;
    }
    if (reservationExtApprovedInCurrentReservation($deal["ID"])) {
        return reservationExtReleaseTime($dateTs);
    }
    return $releaseByDeal[(int)$deal["ID"]] ?? false;
}

$now = time();
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

    $releaseByDeal = null;

    $res = CCrmDeal::GetListEx(
        ["ID" => "ASC"],
        $arFilter,
        false,
        false,
        ["ID", "STAGE_ID", "CONTACT_ID", "COMPANY_ID", "CONTACT_FULL_NAME", "COMPANY_TITLE", "ASSIGNED_BY_ID", EXPIRING_RESERVATION_DATE_FIELD]
    );
    while ($deal = $res->Fetch()) {
        $deadline = normalizeReservationDate($deal[EXPIRING_RESERVATION_DATE_FIELD] ?? "");
        $dateTs = reservationExtParseDate($deadline);
        if (!$dateTs) {
            continue;
        }

        // ვადაგასული/ხვალამდელი თარიღი - გათავისუფლების დრო შეიძლება 24 სთ-შია (მაგ. პარასკევის ჯავშანი ორშაბათს)
        $release = false;
        if ($dateTs <= strtotime("tomorrow")) {
            if ($releaseByDeal === null) {
                $releaseByDeal = monolithReservationReleaseByDeal();
            }
            $release = expiringReservationReleaseTime($deal, $dateTs, $releaseByDeal);
        }

        $releaseSoon = $release && $release > $now && $release <= $now + 86400;
        if ($deadline !== $today && $deadline !== $tomorrow && !$releaseSoon) {
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
            "DEADLINE" => $deadline . ($release && $release > $now ? " → " . date("d/m H:i", $release) : ""),
            "SORT" => $release && $release > $now ? $release : $dateTs + 86400,
        ];
    }

    // ჯერ ის, რაც ადრე თავისუფლდება
    usort($deals, function ($a, $b) {
        return $a["SORT"] <=> $b["SORT"] ?: $a["ID"] <=> $b["ID"];
    });
    foreach ($deals as &$row) {
        unset($row["SORT"]);
    }
    unset($row);
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
