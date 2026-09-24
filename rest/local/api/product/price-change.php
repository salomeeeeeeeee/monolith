<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
ob_end_clean();

\Bitrix\Main\Loader::includeModule("iblock");
\Bitrix\Main\Loader::includeModule("catalog");

/* =====================================================================
 *  CONFIG — lines marked // SYNC must match the dashboard page
 * ===================================================================== */
function pmCfg() {
    static $cfg = array(
        "IBLOCK_ID" => 14, // SYNC

        // SYNC
        "PROPS" => array(
            "STATUS"           => "_P64GYD",
            "TOTAL_AREA"       => "__173JA5",
            "KVM_PRICE"        => "__6ZWTER",
            "PRICE_TOTAL"      => "__9YCWGZ",
            // per-element text log of price changes; "" = the iblock has no such property
            "PRICE_CHANGE_LOG" => "",
        ),

        // only products that currently have these statuses can be changed // SYNC
        "VISIBLE_STATUSES" => array("თავისუფალი", "NFS"),

        // keys must match PRICE_AREAS on the dashboard // SYNC
        // for each key: which props hold sqm price, area, total
        "PRICE_AREA_MAP" => array(
            "total" => array("KVM" => "KVM_PRICE", "AREA" => "TOTAL_AREA", "TOTAL" => "PRICE_TOTAL"),
        ),
        "PRICE_ROUND_SQM"      => 2,      // decimals for new sqm price (data has e.g. 545.45)
        "PRICE_ROUND_TOTAL"    => 2,
        "PRICE_CURRENCY"       => "USD",
        "UPDATE_CATALOG_PRICE" => true,   // also update the catalog base price

        // user group IDs allowed to change data; empty = any authorized user (admins always allowed)
        "ALLOWED_GROUPS" => array(),

        // change log iblock (skipped automatically if it doesn't exist)
        "LOG" => array(
            "ENABLED"                => true,
            "IBLOCK_ID"              => 37,
            "PROD_PROP"              => "prodId",   // multiple "element link" property code
            // list enum IDs on THIS portal; null = don't write that property
            "ENUM_CHANGE_TYPE_PRICE" => null,       // old portal: 182
            "ENUM_PERCENT"           => null,       // old portal: 185
            "ENUM_FIXED"             => null,       // old portal: 186
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

function pmaScalar($v) {
    if ($v === null || $v === false) return "";
    if (is_array($v)) {
        $first = reset($v);
        return trim((string)(is_array($first) ? ($first["VALUE"] ?? "") : $first));
    }
    return trim((string)$v);
}

function pmaFloat($v) {
    return (float)str_replace(array(",", " "), array(".", ""), pmaScalar($v));
}

function pmaCheckAccess() {
    global $USER;
    if (!is_object($USER) || !$USER->IsAuthorized()) return false;
    if ($USER->IsAdmin()) return true;
    $allowed = pmCfg()["ALLOWED_GROUPS"];
    if (empty($allowed)) return true;
    return count(array_intersect(array_map("intval", $allowed), array_map("intval", $USER->GetUserGroupArray()))) > 0;
}

/** Elements of the configured iblock with VISIBLE_STATUSES only; raw (~VALUE) values keyed by CODE */
function pmaLoadProducts($ids) {
    $cfg = pmCfg();
    $out = array();
    if (empty($ids)) return $out;

    $filter = array("IBLOCK_ID" => $cfg["IBLOCK_ID"], "ID" => $ids);
    if (!empty($cfg["VISIBLE_STATUSES"])) {
        $filter["PROPERTY_" . pmaCode("STATUS")] = $cfg["VISIBLE_STATUSES"];
    }

    $res = CIBlockElement::GetList(array(), $filter, false, false, array("ID", "IBLOCK_ID", "NAME"));
    while ($ob = $res->GetNextElement()) {
        $f   = $ob->GetFields();
        $row = array("ID" => (int)$f["ID"], "NAME" => $f["~NAME"]);
        foreach ($ob->GetProperties() as $key => $prop) {
            $code       = ($prop["CODE"] !== "" ? $prop["CODE"] : $key);
            $row[$code] = $prop["~VALUE"];
        }
        $out[$row["ID"]] = $row;
    }
    return $out;
}

function pmaUpdateIndex($iblockId, $id) {
    if (class_exists("\\Bitrix\\Iblock\\PropertyIndex\\Manager")) {
        \Bitrix\Iblock\PropertyIndex\Manager::updateElementIndex($iblockId, $id);
    }
}

/** Writes to the log iblock if it exists; null values are skipped */
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

/* ---------------- price update ---------------- */

function updatePrices($products, $ids, $value, $type, $direction, $areaMap) {
    $cfg      = pmCfg();
    $iblockId = $cfg["IBLOCK_ID"];
    $kvmCode  = pmaCode($areaMap["KVM"]);
    $areaCode = pmaCode($areaMap["AREA"]);
    $totCode  = !empty($areaMap["TOTAL"]) ? pmaCode($areaMap["TOTAL"]) : "";
    $logCode  = $cfg["PROPS"]["PRICE_CHANGE_LOG"] ?? "";
    $result   = array();

    foreach ($ids as $id) {
        if (!isset($products[$id])) {
            $result[] = array("id" => $id, "status" => false, "error" => "ელემენტი ვერ მოიძებნა ან სტატუსი არ არის დაშვებული");
            continue;
        }
        $product = $products[$id];

        $oldSqm  = pmaFloat($product[$kvmCode] ?? "");
        $areaVal = pmaFloat($product[$areaCode] ?? "");

        if ($oldSqm <= 0) {
            $result[] = array("id" => $id, "status" => false, "error" => "კვ.მ. ფასი ცარიელია");
            continue;
        }
        if ($areaVal <= 0) {
            $result[] = array("id" => $id, "status" => false, "error" => "ფართი ცარიელია");
            continue;
        }

        $diff = ($type === "fixed") ? (float)$value : $oldSqm * (float)$value / 100;
        if ($direction === "decrease") $diff = -$diff;

        $newSqm   = round(max(0, $oldSqm + $diff), $cfg["PRICE_ROUND_SQM"]);
        $newTotal = round($newSqm * $areaVal, $cfg["PRICE_ROUND_TOTAL"]);

        $propValues = array($kvmCode => $newSqm);
        if ($totCode !== "") {
            $propValues[$totCode] = $newTotal;
        }

        if ($logCode !== "") {
            $existingLog = $product[$logCode] ?? "";
            if (is_array($existingLog)) $existingLog = implode("\n", $existingLog);
            $existingLog = trim((string)$existingLog);

            $dirWord  = ($direction === "increase") ? "მოემატა" : "დააკლდა";
            $unit     = ($type === "percent") ? "%" : "$";
            $logEntry = "ფასი {$dirWord} {$value}{$unit}-ით - ძველი თანხა: {$oldSqm}$ - " . date("d.m.Y H:i");
            $propValues[$logCode] = $existingLog !== "" ? $existingLog . "\n" . $logEntry : $logEntry;
        }

        CIBlockElement::SetPropertyValuesEx($id, $iblockId, $propValues);
        pmaUpdateIndex($iblockId, $id);

        $priceUpdate = null;
        if ($cfg["UPDATE_CATALOG_PRICE"]) {
            $priceUpdate = (bool)CPrice::SetBasePrice($id, $newTotal, $cfg["PRICE_CURRENCY"]);
        }

        $result[] = array(
            "id"          => $id,
            "status"      => true,
            "error"       => "",
            "oldSqmPrice" => $oldSqm,
            "newSqmPrice" => $newSqm,
            "totalPrice"  => $newTotal,
            "priceUpdate" => $priceUpdate,
        );
    }

    return $result;
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

$cfg       = pmCfg();
$ids       = array_values(array_unique(array_filter(array_map("intval", (array)($postJson["ids"] ?? array())))));
$type      = $postJson["change_type"] ?? "";
$area      = $postJson["area"] ?? "";
$direction = $postJson["direction"] ?? "";
$value     = isset($postJson["value"]) ? (float)$postJson["value"] : 0;

if (
    empty($ids) ||
    !in_array($type, array("fixed", "percent"), true) ||
    !in_array($direction, array("increase", "decrease"), true) ||
    !isset($cfg["PRICE_AREA_MAP"][$area]) ||
    $value <= 0
) {
    pmaJsonOut(array("success" => false, "error" => "invalid_params"), 400);
}

global $USER;
$currentUserId