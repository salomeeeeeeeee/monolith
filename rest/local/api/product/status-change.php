<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
ob_end_clean();

\Bitrix\Main\Loader::includeModule("iblock");

/* =====================================================================
 *  CONFIG — lines marked // SYNC must match the dashboard page
 * ===================================================================== */
function pmCfg() {
    static $cfg = array(
        "IBLOCK_ID" => 14, // SYNC

        // SYNC
        "PROPS" => array(
            "STATUS"    => "_P64GYD",
            "PROMOTION" => "_UQIM2I",
        ),

        // only products that currently have these statuses can be changed // SYNC
        "VISIBLE_STATUSES" => array("თავისუფალი", "NFS"),

        // statuses that can be SET: value written => label // SYNC
        "STATUS_OPTIONS" => array(
            "თავისუფალი" => "თავისუფალი",
            "NFS"        => "NFS",
        ),
        // legacy API keys => value written
        "STATUS_API_MAP" => array(
            "free" => "თავისუფალი",
            "nfs"  => "NFS",
        ),

        // promotion: values used when the property is a list, and values written for a string property
        "PROMOTION_YES"       => array("Y", "Yes", "YES", "კი", "1"),
        "PROMOTION_NO"        => array("N", "No", "NO", "არა", "0"),
        "PROMOTION_WRITE_YES" => "Y",
        "PROMOTION_WRITE_NO"  => "N",

        // user group IDs allowed to change data; empty = any authorized user (admins always allowed)
        "ALLOWED_GROUPS" => array(),

        // change log iblock (skipped automatically if it doesn't exist)
        "LOG" => array(
            "ENABLED"     => true,
            "IBLOCK_ID"   => 37,
            "PROD_PROP"   => "prodId",   // multiple "element link" property code
            "ENUM_STATUS" => null,       // list enum ID on THIS portal (old portal: 187); null = don't write
        ),
    );
    return $cfg;
}
/* ===================================================================== */

/* ---------------- helpers ---------------- */

function pmaJsonOut($data, $httpCode = 200) {
    http_response_code($httpCode);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    die();
}

function pmaCode($key) {
    $c = pmCfg();
    return isset($c["PROPS"][$key]) ? $c["PROPS"][$key] : $key;
}

function pmaCheckAccess() {
    global $USER;
    if (!is_object($USER) || !$USER->IsAuthorized()) return false;
    if ($USER->IsAdmin()) return true;
    $allowed = pmCfg()["ALLOWED_GROUPS"];
    if (empty($allowed)) return true;
    return count(array_intersect(array_map("intval", $allowed), array_map("intval", $USER->GetUserGroupArray()))) > 0;
}

function pmaUpdateIndex($iblockId, $id) {
    if (class_exists("\\Bitrix\\Iblock\\PropertyIndex\\Manager")) {
        \Bitrix\Iblock\PropertyIndex\Manager::updateElementIndex($iblockId, $id);
    }
}

function pmaSaveLog($name, $props) {
    $log = pmCfg()["LOG"];
    if (empty($log["ENABLED"]) || empty($log["IBLOCK_ID"])) return false;
    if (!CIBlock::GetByID($log["IBLOCK_ID"])->Fetch()) return false;

    $el = new CIBlockElement;
    return $el->Add(array(
        "IBLOCK_ID"       => $log["IBLOCK_ID"],
        "NAME"            => $name,
        "ACTIVE"          => "Y",
        "PROPERTY_VALUES" => array_filter($props, function ($v) { return $v !== null; }),
    ));
}

function pmaPropertyRowByCode($iblockId, $propertyCode) {
    static $cache = array();
    $cacheKey = (int)$iblockId . "|" . $propertyCode;
    if (!array_key_exists($cacheKey, $cache)) {
        $row = CIBlockProperty::GetList(array(), array("IBLOCK_ID" => $iblockId, "CODE" => $propertyCode))->Fetch();
        $cache[$cacheKey] = $row ?: null;
    }
    return $cache[$cacheKey];
}

/**
 * Value to write for promotion.
 * String property → PROMOTION_WRITE_YES/NO; list property → matching enum ID.
 * @return array [writeKey, writeValue]
 */
function pmaPromotionWriteKeyAndValue($iblockId, $propertyCode, $wantYes) {
    $cfg      = pmCfg();
    $strValue = $wantYes ? $cfg["PROMOTION_WRITE_YES"] : $cfg["PROMOTION_WRITE_NO"];

    $prop = pmaPropertyRowByCode($iblockId, $propertyCode);
    if (!$prop) {
        return array($propertyCode, $strValue);
    }
    $propId = (int)$prop["ID"];

    if ($prop["PROPERTY_TYPE"] !== "L") {
        return array($propId, $strValue);
    }

    $rows    = array();
    $enumRes = CIBlockProperty::GetPropertyEnum($propId, array("SORT" => "ASC", "ID" => "ASC"));
    while ($e = $enumRes->Fetch()) $rows[] = $e;

    $yesId = null;
    $noId  = null;
    foreach ($rows as $e) {
        $xml = trim((string)$e["XML_ID"]);
        $val = trim((string)$e["VALUE"]);
        if (in_array($xml, $cfg["PROMOTION_YES"], true) || in_array($val, $cfg["PROMOTION_YES"], true)) $yesId = $e["ID"];
        if (in_array($xml, $cfg["PROMOTION_NO"], true)  || in_array($val, $cfg["PROMOTION_NO"], true))  $noId  = $e["ID"];
    }

    if ($yesId !== null && $noId !== null) {
        return array($propId, $wantYes ? $yesId : $noId);
    }
    if ($yesId !== null && $noId === null) {
        return array($propId, $wantYes ? $yesId : false);
    }
    if (count($rows) === 1) {
        return array($propId, $wantYes ? $rows[0]["ID"] : false);
    }
    if (count($rows) === 2) {
        list($a, $b) = $rows;
        if ($a["DEF"] === "Y" && $b["DEF"] !== "Y") return array($propId, $wantYes ? $b["ID"] : $a["ID"]);
        if ($b["DEF"] === "Y" && $a["DEF"] !== "Y") return array($propId, $wantYes ? $a["ID"] : $b["ID"]);
        return array($propId, $wantYes ? $b["ID"] : $a["ID"]);
    }

    return array($propId, $strValue);
}

/* ---------------- main ---------------- */

if (!pmaCheckAccess()) {
    pmaJsonOut(array("success" => false, "error" => "access_denied"), 403);
}

try {
    $postJson = \Bitrix\Main\Web\Json::decode(\Bitrix\Main\HttpRequest::getInput());
} catch (Exception $e) {
    pmaJsonOut(array("success" => false, "error" => $e->getMessage()), 400);
}

$cfg      = pmCfg();
$iblockId = $cfg["IBLOCK_ID"];

$ids       = array_values(array_unique(array_filter(array_map("intval", (array)($postJson["ids"] ?? array())))));
$hasStatus = isset($postJson["status"]) && is_string($postJson["status"]) && trim($postJson["status"]) !== "";
$hasPromo  = isset($postJson["promotion"]) && in_array($postJson["promotion"], array("Y", "N"), true);

if (empty($ids) || (!$hasStatus && !$hasPromo)) {
    pmaJsonOut(array("success" => false, "error" => "invalid_params"), 400);
}

global $USER;
$currentUserId = (int)$USER->GetID();
$filterInfo    = (string)($postJson["filter_info"] ?? "");

// status: legacy keys (free/nfs) are mapped; only STATUS_OPTIONS values are accepted
$statusValue = "";
if ($hasStatus) {
    $status      = trim($postJson["status"]);
    $statusValue = $cfg["STATUS_API_MAP"][$status] ?? $status;
    if (!array_key_exists($statusValue, $cfg["STATUS_OPTIONS"])) {
        pmaJsonOut(array("success" => false, "error" => "status_not_allowed"), 400);
    }
}

$promoWriteKey   = null;
$promoWriteValue = null;
$promoLabel      = "";
if ($hasPromo) {
    list($promoWriteKey, $promoWriteValue) = pmaPromotionWriteKeyAndValue($iblockId, pmaCode("PROMOTION"), $postJson["promotion"] === "Y");
    $promoLabel = ($postJson["promotion"] === "Y") ? "Yes" : "No";
}

// which of the requested IDs belong to this iblock AND currently have an allowed status
$existFilter = array("IBLOCK_ID" => $iblockId, "ID" => $ids);
if (!empty($cfg["VISIBLE_STATUSES"])) {
    $existFilter["PROPERTY_" . pmaCode("STATUS")] = $cfg["VISIBLE_STATUSES"];
}
$existing = array();
$res = CIBlockElement::GetList(array(), $existFilter, false, false, array("ID"));
while ($row = $res->Fetch()) $existing[(int)$row["ID"]] = true;

$successCount = 0;
$errors       = array();
$prodIds      = array();

foreach ($ids as $id) {
    if (!isset($existing[$id])) {
        $errors[] = array("id" => $id, "error" => "ელემენტი ვერ მოიძებნა ან სტატუსი არ არის დაშვებული");
        continue;
    }

    $propertyValues = array();
    if ($hasStatus) $propertyValues[pmaCode("STATUS")] = $statusValue;
    if ($hasPromo)  $propertyValues[$promoWriteKey]    = $promoWriteValue;

    CIBlockElement::SetPropertyValuesEx($id, $iblockId, $propertyValues);
    pmaUpdateIndex($iblockId, $id);

    $successCount++;
    $prodIds[] = array("VALUE" => $id);
}

if ($successCount > 0) {
    $parts = array();
    if ($hasStatus) $parts[] = "სტატუსი: {$statusValue}";
    if ($hasPromo)  $parts[] = "აქცია: {$promoLabel}";

    $log = $cfg["LOG"];
    pmaSaveLog("პროდუქტების მოდული - სტატუსი/აქცია " . date("d.m.Y H:i"), array(
        "changedBy"       => $currentUserId,
        "changeType2"     => $log["ENUM_STATUS"],
        "change"          => implode("; ", $parts),
        $log["PROD_PROP"] => $prodIds,
        "filterInfo"      => $filterInfo,
        "changeDate"      => date("Y-m-d"),
    ));

    if (method_exists("CIBlock", "clearIblockTagCache")) {
        CIBlock::clearIblockTagCache($iblockId);
    }
}

$resArray = array(
    "success" => empty($errors),
    "updated" => $successCount,
);

if (!empty($errors)) {
    $resArray["error"]      = "განახლდა {$successCount}, ვერ განახლდა " . count($errors) . ": "
        . implode(", ", array_map(function ($e) { return "ID:" . $e["id"]; }, $errors));
    $resArray["failed_ids"] = $errors;
    pmaJsonOut($resArray, 422);
}

pmaJsonOut($resArray);