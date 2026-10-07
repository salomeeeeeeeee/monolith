<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
ob_end_clean();

\Bitrix\Main\Loader::includeModule("iblock");
\Bitrix\Main\Loader::includeModule("catalog");

/* =====================================================================
 *  CONFIG - lines marked // SYNC must match the dashboard page
 *
 *  Request (JSON): ids, change_type (percent | fixed | set), target (sqm | full, not used for
 *  percent), direction (increase | decrease, not used for set), value, filter_info,
 *  dry_run (true = only calculate, nothing is written).
 *
 *  percent       - sqm price and full (catalog) price change by value % (same result for both)
 *  fixed + sqm   - sqm price changes by value $, full price by value x total area
 *  set   + sqm   - sqm price = value, full price = value x total area
 *  fixed + full  - full price changes by value $, sqm price by the same factor
 *  set   + full  - full price = value, sqm price by the same factor
 *  Payment plan prices (New Depo) move by the same factor as the changed price.
 *  Sale price (__YOIUM1) is not touched.
 *
 *  Every change is logged to the PRICE_CHANGE_LOG list (1 element = 1 request);
 *  the list is created by /custom/setup/priceChangeLog.php and prices are not changed without it.
 * ===================================================================== */
function pmCfg() {
    static $cfg = array(
        "IBLOCK_ID" => 14, // SYNC

        // SYNC
        "PROPS" => array(
            "PROJECT"     => "__VO9RG4",
            "TYPE"        => "__X1GCRZ",
            "BLOCK"       => "_L24CUB",
            "FLOOR"       => "_FTRIDL",
            "NUMBER"      => "__6KWOWZ",
            "STATUS"      => "_P64GYD",
            "TOTAL_AREA"  => "__173JA5",
            "KVM_PRICE"   => "__6ZWTER",
            // written only when already filled, so it never goes out of sync with the catalog price
            "PRICE_TOTAL" => "__9YCWGZ",
        ),

        // only products that currently have these statuses can be changed // SYNC
        "VISIBLE_STATUSES" => array("თავისუფალი", "NFS"),

        // change types and the price they work on: key => label for the log // SYNC (keys)
        "CHANGE_TYPES" => array(
            "percent" => "პროცენტი",
            "fixed"   => "ფიქსირებული თანხა",
            "set"     => "ახალი ფასი",
        ),
        "TARGETS" => array(
            "sqm"  => "კვ.მ. ფასი",
            "full" => "სრული ფასი",
        ),

        // payment plan prices kept on the product (New Depo; read by the calculator):
        // label => full price / sqm price property codes
        "PLAN_PRICES" => array(
            "ერთიანი"   => array("FULL" => "__3OT6VA",       "SQM" => "__TTJCKI"),
            "0/20/80"   => array("FULL" => "_02080__AEC240", "SQM" => "_02080__1D1HZL"),
            "60 თვიანი" => array("FULL" => "_60__WZXWF3",    "SQM" => "_60__Q44IB7"),
        ),

        "PRICE_ROUND"    => 2,
        "PRICE_CURRENCY" => "USD", // only for products that have no catalog price yet

        // user group IDs allowed to change data; empty = any authorized user (admins always allowed)
        "ALLOWED_GROUPS" => array(),

        "LOG_CODE"            => "PRICE_CHANGE_LOG",
        "FILTER_INFO_MAX_LEN" => 1000,
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

/** Price as stored in the string properties: up to 2 decimals, no trailing zeros (1186.5, 1200) */
function pmaNum($v) {
    $s = number_format((float)$v, pmCfg()["PRICE_ROUND"], ".", "");
    return strpos($s, ".") === false ? $s : rtrim(rtrim($s, "0"), ".");
}

function pmaCheckAccess() {
    global $USER;
    if (!is_object($USER) || !$USER->IsAuthorized()) return false;
    if ($USER->IsAdmin()) return true;
    $allowed = pmCfg()["ALLOWED_GROUPS"];
    if (empty($allowed)) return true;
    return count(array_intersect(array_map("intval", $allowed), array_map("intval", $USER->GetUserGroupArray()))) > 0;
}

function pmaLogIblockId() {
    $row = CIBlock::GetList(array(), array("CODE" => pmCfg()["LOG_CODE"], "CHECK_PERMISSIONS" => "N"))->Fetch();
    return $row ? (int)$row["ID"] : 0;
}

/**
 * Elements of the configured iblock with VISIBLE_STATUSES only; raw (~VALUE) values keyed by CODE,
 * plus CATALOG_PRICE / CATALOG_CURRENCY (null when the product has no base price).
 */
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
        $row = array("ID" => (int)$f["ID"], "NAME" => $f["~NAME"], "CATALOG_PRICE" => null, "CATALOG_CURRENCY" => "");
        foreach ($ob->GetProperties() as $key => $prop) {
            $code       = ($prop["CODE"] !== "" ? $prop["CODE"] : $key);
            $row[$code] = $prop["~VALUE"];
        }
        $out[$row["ID"]] = $row;
    }

    if (!empty($out)) {
        $res = CPrice::GetList(array(), array("PRODUCT_ID" => array_keys($out), "BASE" => "Y"));
        while ($price = $res->Fetch()) {
            $id = (int)$price["PRODUCT_ID"];
            if (isset($out[$id]) && $out[$id]["CATALOG_PRICE"] === null) {
                $out[$id]["CATALOG_PRICE"]    = (float)$price["PRICE"];
                $out[$id]["CATALOG_CURRENCY"] = $price["CURRENCY"];
            }
        }
    }
    return $out;
}

function pmaUpdateIndex($iblockId, $id) {
    if (class_exists("\\Bitrix\\Iblock\\PropertyIndex\\Manager")) {
        \Bitrix\Iblock\PropertyIndex\Manager::updateElementIndex($iblockId, $id);
    }
}

/** "ფასი +5% (კვ.მ. და სრული)" / "კვ.მ. ფასი -50$" / "სრული ფასი = 15000$" */
function pmaOperationText($type, $target, $direction, $value) {
    $sign = ($direction === "decrease") ? "-" : "+";
    if ($type === "percent") return "ფასი " . $sign . pmaNum($value) . "% (კვ.მ. და სრული)";

    $label = pmCfg()["TARGETS"][$target];
    if ($type === "set") return $label . " = " . pmaNum($value) . "$";
    return $label . " " . $sign . pmaNum($value) . "$";
}

/** "ფიქსირებული თანხა (სრული ფასი)" */
function pmaChangeTypeText($type, $target) {
    $cfg = pmCfg();
    return $cfg["CHANGE_TYPES"][$type] . " (" . ($target !== "" ? $cfg["TARGETS"][$target] : "კვ.მ. და სრული ფასი") . ")";
}

/** "New Depo, ბინა, ბლოკი A, სართ. 5, № 28" */
function pmaPlace($product) {
    $parts = array();
    foreach (array("PROJECT" => "", "TYPE" => "", "BLOCK" => "ბლოკი ", "FLOOR" => "სართ. ", "NUMBER" => "№ ") as $key => $prefix) {
        $v = pmaScalar($product[pmaCode($key)] ?? "");
        if ($v !== "") $parts[] = $prefix . $v;
    }
    return implode(", ", $parts);
}

/* ---------------- price calculation ---------------- */

/**
 * New prices of one product; nothing is written here.
 * @return array ["error" => "..."] or old/new sqm, catalog, total property and plan prices
 */
function pmaCalc($product, $type, $target, $direction, $value) {
    $cfg   = pmCfg();
    $round = $cfg["PRICE_ROUND"];
    $sign  = ($direction === "decrease") ? -1 : 1;

    $oldSqm   = pmaFloat($product[pmaCode("KVM_PRICE")] ?? "");
    $area     = pmaFloat($product[pmaCode("TOTAL_AREA")] ?? "");
    $oldCat   = $product["CATALOG_PRICE"];
    // the catalog price is the real price (site, deals, calculator); sqm x area only when it is missing
    $oldTotal = ($oldCat !== null && $oldCat > 0) ? $oldCat : round($oldSqm * $area, $round);

    if ($type === "percent" || $target === "full") {
        if ($type === "percent") {
            if ($oldTotal <= 0) return array("error" => "ფასი ცარიელია");
            $newCat = round($oldTotal * (1 + $sign * $value / 100), $round);
        } elseif ($type === "fixed") {
            if ($oldTotal <= 0) return array("error" => "ფასი ცარიელია");
            $newCat = round($oldTotal + $sign * $value, $round);
        } else {
            $newCat = round($value, $round);
        }
        $factor = $oldTotal > 0 ? $newCat / $oldTotal : null;
        // the sqm price follows the full price; a product without sqm price (e.g. parking sold as a whole) keeps it empty
        $newSqm = ($oldSqm > 0 && $factor !== null) ? round($oldSqm * $factor, $round) : null;
    } elseif ($type === "fixed") {
        if ($oldSqm <= 0) return array("error" => "კვ.მ. ფასი ცარიელია");
        if ($area <= 0)   return array("error" => "ფართი ცარიელია");
        $newSqm = round($oldSqm + $sign * $value, $round);
        $newCat = round($oldTotal + $sign * $value * $area, $round);
        $factor = $newSqm / $oldSqm;
    } else {
        if ($area <= 0) return array("error" => "ფართი ცარიელია");
        $newSqm = round($value, $round);
        $newCat = round($newSqm * $area, $round);
        $factor = $oldSqm > 0 ? $newSqm / $oldSqm : null;
    }

    if (($newSqm !== null && $newSqm <= 0) || $newCat <= 0) {
        return array("error" => "ახალი ფასი 0 ან ნაკლები გამოდის");
    }

    $plans = array();
    foreach ($cfg["PLAN_PRICES"] as $label => $codes) {
        $plan = array();
        foreach ($codes as $part => $code) {
            $old = pmaFloat($product[$code] ?? "");
            if ($old > 0) $plan[$part] = array($old, null);
        }
        if (empty($plan)) continue;
        if ($factor === null) return array("error" => "ძველი ფასი ცარიელია - გეგმების ფასებს ვერ დავთვლი");
        foreach ($plan as $part => $pair) {
            $plan[$part][1] = round($pair[0] * $factor, $round);
        }
        $plans[$label] = $plan;
    }

    $oldTotalProp = pmaFloat($product[pmaCode("PRICE_TOTAL")] ?? "");

    return array(
        "error"        => "",
        "oldSqm"       => $oldSqm,
        "newSqm"       => $newSqm,
        "oldCat"       => $oldCat,
        "newCat"       => $newCat,
        "oldTotalProp" => $oldTotalProp,
        "newTotalProp" => $oldTotalProp > 0 ? $newCat : null,
        "plans"        => $plans,
    );
}

/** Writes the calculated prices; returns an error text or "" */
function pmaApply($id, $product, $calc) {
    $cfg = pmCfg();

    $currency = $product["CATALOG_CURRENCY"] !== "" ? $product["CATALOG_CURRENCY"] : $cfg["PRICE_CURRENCY"];
    if (!CPrice::SetBasePrice($id, $calc["newCat"], $currency)) {
        return "კატალოგის ფასი ვერ ჩაიწერა";
    }

    $values = array();
    if ($calc["newSqm"] !== null)       $values[pmaCode("KVM_PRICE")]   = pmaNum($calc["newSqm"]);
    if ($calc["newTotalProp"] !== null) $values[pmaCode("PRICE_TOTAL")] = pmaNum($calc["newTotalProp"]);
    foreach ($calc["plans"] as $label => $plan) {
        foreach ($plan as $part => $pair) {
            $values[$cfg["PLAN_PRICES"][$label][$part]] = pmaNum($pair[1]);
        }
    }

    if (!empty($values)) {
        CIBlockElement::SetPropertyValuesEx($id, $cfg["IBLOCK_ID"], $values);
        pmaUpdateIndex($cfg["IBLOCK_ID"], $id);
    }
    return "";
}

/** One log line: "#37369 New Depo, ბინა, ... | კვ.მ.: 1422 -> 1493.1 | კატალოგი: 92856.6 -> 97499.43 | ..." */
function pmaDetailLine($id, $product, $calc) {
    $parts = array("#" . $id . " " . pmaPlace($product));
    if ($calc["newSqm"] !== null) {
        $parts[] = "კვ.მ.: " . pmaNum($calc["oldSqm"]) . " -> " . pmaNum($calc["newSqm"]);
    } else {
        $parts[] = "კვ.მ.: " . ($calc["oldSqm"] > 0 ? pmaNum($calc["oldSqm"]) : "ცარიელია") . ", არ შეცვლილა";
    }
    $parts[] = "კატალოგი: " . ($calc["oldCat"] !== null ? pmaNum($calc["oldCat"]) : "არ იყო") . " -> " . pmaNum($calc["newCat"]);
    if ($calc["newTotalProp"] !== null) {
        $parts[] = "ჯამური ღირ.: " . pmaNum($calc["oldTotalProp"]) . " -> " . pmaNum($calc["newTotalProp"]);
    }
    foreach ($calc["plans"] as $label => $plan) {
        $planParts = array();
        if (isset($plan["SQM"]))  $planParts[] = "კვ.მ. " . pmaNum($plan["SQM"][0]) . " -> " . pmaNum($plan["SQM"][1]);
        if (isset($plan["FULL"])) $planParts[] = "სრული " . pmaNum($plan["FULL"][0]) . " -> " . pmaNum($plan["FULL"][1]);
        $parts[] = $label . ": " . implode(", ", $planParts);
    }
    return implode(" | ", $parts);
}

/** Writes the log element; on failure the full text goes to the Event Log so nothing is lost */
function pmaSaveLog($logIblockId, $name, $props) {
    $el = new CIBlockElement;
    $id = $el->Add(array(
        "IBLOCK_ID"       => $logIblockId,
        "NAME"            => $name,
        "ACTIVE"          => "Y",
        "PROPERTY_VALUES" => $props,
    ));
    if ($id) return (int)$id;

    CEventLog::Add(array(
        "SEVERITY"      => "WARNING",
        "AUDIT_TYPE_ID" => "PRICE_CHANGE_LOG_ERROR",
        "MODULE_ID"     => "iblock",
        "ITEM_ID"       => "-",
        "DESCRIPTION"   => $el->LAST_ERROR . "\n" . json_encode(array("name" => $name) + $props, JSON_UNESCAPED_UNICODE),
    ));
    return 0;
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
$type      = (string)($postJson["change_type"] ?? "");
// percent gives the same result on the sqm and the full price, so it has no target
$target    = ($type === "percent") ? "" : (string)($postJson["target"] ?? "");
$direction = ($type === "set") ? "" : (string)($postJson["direction"] ?? "");
$value     = isset($postJson["value"]) && is_numeric($postJson["value"]) ? round((float)$postJson["value"], $cfg["PRICE_ROUND"]) : 0;
$dryRun    = !empty($postJson["dry_run"]);

if (
    empty($ids) ||
    !isset($cfg["CHANGE_TYPES"][$type]) ||
    ($type !== "percent" && !isset($cfg["TARGETS"][$target])) ||
    ($type !== "set" && !in_array($direction, array("increase", "decrease"), true)) ||
    $value <= 0
) {
    pmaJsonOut(array("success" => false, "error" => "invalid_params"), 400);
}
if ($type === "percent" && $direction === "decrease" && $value >= 100) {
    pmaJsonOut(array("success" => false, "error" => "დაკლება 100%-ზე ნაკლები უნდა იყოს"), 400);
}

$logIblockId = pmaLogIblockId();
if ($logIblockId <= 0) {
    pmaJsonOut(array("success" => false, "error" => "ლოგის სია " . $cfg["LOG_CODE"] . " არ არსებობს - ადმინმა გაუშვას /custom/setup/priceChangeLog.php"), 500);
}

global $USER;
$operation = pmaOperationText($type, $target, $direction, $value);
$products  = pmaLoadProducts($ids);

$planned = array();
$errors  = array();
foreach ($ids as $id) {
    if (!isset($products[$id])) {
        $errors[] = array("id" => $id, "error" => "ელემენტი ვერ მოიძებნა ან სტატუსი არ არის დაშვებული");
        continue;
    }
    $calc = pmaCalc($products[$id], $type, $target, $direction, $value);
    if ($calc["error"] !== "") {
        $errors[] = array("id" => $id, "error" => $calc["error"]);
        continue;
    }
    $planned[$id] = $calc;
}

/* ---------- dry run: what would change ---------- */
if ($dryRun) {
    $sumBefore = 0;
    $sumAfter  = 0;
    $withPlans = 0;
    foreach ($planned as $calc) {
        $sumBefore += (float)$calc["oldCat"];
        $sumAfter  += $calc["newCat"];
        if (!empty($calc["plans"])) $withPlans++;
    }
    pmaJsonOut(array(
        "success"    => true,
        "dry_run"    => true,
        "operation"  => $operation,
        "ok"         => count($planned),
        "failed"     => count($errors),
        "failed_ids" => $errors,
        "sum_before" => round($sumBefore, $cfg["PRICE_ROUND"]),
        "sum_after"  => round($sumAfter, $cfg["PRICE_ROUND"]),
        "plans"      => $withPlans,
    ));
}

/* ---------- write ---------- */
$updated   = array();
$details   = array();
$projects  = array();
$sumBefore = 0;
$sumAfter  = 0;

foreach ($planned as $id => $calc) {
    $error = pmaApply($id, $products[$id], $calc);
    if ($error !== "") {
        $errors[] = array("id" => $id, "error" => $error);
        continue;
    }
    $updated[] = array(
        "id"      => $id,
        "kvm"     => $calc["newSqm"] !== null ? pmaNum($calc["newSqm"]) : null,
        "catalog" => pmaNum($calc["newCat"]),
        "total"   => $calc["newTotalProp"] !== null ? pmaNum($calc["newTotalProp"]) : null,
    );
    $details[]  = pmaDetailLine($id, $products[$id], $calc);
    $sumBefore += (float)$calc["oldCat"];
    $sumAfter  += $calc["newCat"];

    $project = pmaScalar($products[$id][pmaCode("PROJECT")] ?? "");
    if ($project !== "") $projects[$project] = true;
}

$resArray = array(
    "success"    => empty($errors),
    "operation"  => $operation,
    "updated"    => count($updated),
    "failed"     => count($errors),
    "failed_ids" => $errors,
    "products"   => $updated,
);

if (!empty($updated)) {
    if (method_exists("CIBlock", "clearIblockTagCache")) {
        CIBlock::clearIblockTagCache($cfg["IBLOCK_ID"]);
    }

    $failedLines = array();
    foreach ($errors as $e) {
        $failedLines[] = "#" . $e["id"] . ": " . $e["error"];
    }
    $projectText = implode(", ", array_keys($projects));

    $props = array(
        "OPERATION"     => $operation,
        "CHANGE_TYPE"   => pmaChangeTypeText($type, $target),
        "DIRECTION"     => $type === "set" ? "-" : ($direction === "increase" ? "მომატება" : "დაკლება"),
        "CHANGE_VALUE"  => $value,
        "PROJECTS"      => $projectText,
        "UPDATED_COUNT" => count($updated),
        "FAILED_COUNT"  => count($errors),
        "SUM_BEFORE"    => round($sumBefore, $cfg["PRICE_ROUND"]),
        "SUM_AFTER"     => round($sumAfter, $cfg["PRICE_ROUND"]),
        "PRODUCT_IDS"   => array_column($updated, "id"),
        "DETAILS"       => $details,
        "FILTER_INFO"   => mb_substr((string)($postJson["filter_info"] ?? ""), 0, $cfg["FILTER_INFO_MAX_LEN"]),
        "CHANGED_BY"    => (int)$USER->GetID(),
        "CHANGE_DATE"   => ConvertTimeStamp(time(), "FULL"),
    );
    if (!empty($failedLines)) $props["FAILED"] = $failedLines;

    $name  = mb_substr($operation . " | " . count($updated) . " პროდ. | " . $projectText, 0, 250);
    $logId = pmaSaveLog($logIblockId, $name, $props);
    if ($logId > 0) {
        $resArray["log_url"] = "/services/lists/" . $logIblockId . "/element/0/" . $logId . "/";
    } else {
        $resArray["log_error"] = "ფასები შეიცვალა, მაგრამ ლოგი სიაში ვერ ჩაიწერა (ჩაიწერა Event Log-ში)";
    }
}

if (empty($updated)) {
    $resArray["error"] = "ვერცერთ პროდუქტს ფასი ვერ შეეცვალა";
    pmaJsonOut($resArray, 422);
}

pmaJsonOut($resArray);
