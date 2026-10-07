<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("პროდუქტების მოდული");

\Bitrix\Main\Loader::includeModule("iblock");
\Bitrix\Main\Loader::includeModule("catalog");

/* =====================================================================
 *  CONFIG
 *  Lines marked // SYNC must match status-change.php / price-change.php
 *  Open the page with ?debug_props=1 (admin only) to see the real codes
 * ===================================================================== */
function pmCfg() {
    static $cfg = array(
        "IBLOCK_ID" => 14, // SYNC

        // logical key => property CODE in the iblock // SYNC
        "PROPS" => array(
            "PROJECT"     => "__VO9RG4",
            "TYPE"        => "__X1GCRZ",
            "BLOCK"       => "_L24CUB",
            "SECTOR"      => "_3BU0JH",
            "ENTRANCE"    => "_D599QA",
            "FLOOR"       => "_FTRIDL",
            "NUMBER"      => "__6KWOWZ",
            "STATUS"      => "_P64GYD",
            "PROMOTION"   => "_UQIM2I",
            "TOTAL_AREA"  => "__173JA5",
            "INNER_AREA"  => "__US58ND",
            "SUMMER_AREA" => "__BL1XXK",
            "KVM_PRICE"   => "__6ZWTER",
            "PRICE_TOTAL" => "__9YCWGZ",
            "SALE_PRICE"  => "__YOIUM1",
            "NFS_COMMENT" => "NFScomment",
        ),

        // ONLY products with these statuses are loaded (empty array = all) // SYNC
        "VISIBLE_STATUSES" => array("თავისუფალი", "NFS"),

        // setting this status requires a reason, saved to NFS_COMMENT // SYNC
        "COMMENT_STATUS"     => "NFS",
        "COMMENT_MAX_LENGTH" => 1000, // SYNC

        // Sort order (natural compare, in PHP)
        "SORT_KEYS" => array("PROJECT", "BLOCK", "FLOOR", "NUMBER"),

        // Dropdown filters (in display order): POST name, logical key, label, required star
        "SELECT_FILTERS" => array(
            array("name" => "f_project",  "key" => "PROJECT",  "label" => "პროექტი",             "req" => true),
            array("name" => "f_type",     "key" => "TYPE",     "label" => "უძრავი ქონების ტიპი", "req" => true),
            array("name" => "f_sector",   "key" => "SECTOR",   "label" => "სექტორი",             "req" => false),
            array("name" => "f_block",    "key" => "BLOCK",    "label" => "ბლოკი",               "req" => false),
            array("name" => "f_entrance", "key" => "ENTRANCE", "label" => "სადარბაზო",           "req" => false),
            array("name" => "f_status",   "key" => "STATUS",   "label" => "სტატუსი",             "req" => true),
        ),

        // Table columns: logical key => header
        // Special keys: ID, PROMOTION (Yes/No), CATALOG_PRICE (base catalog price)
        "COLUMNS" => array(
            "ID"            => "ID",
            "PROJECT"       => "პროექტი",
            "BLOCK"         => "ბლოკი",
            "SECTOR"        => "სექტორი",
            "ENTRANCE"      => "სადარბაზო",
            "FLOOR"         => "სართ.",
            "NUMBER"        => "უძ.ქ. №",
            "TYPE"          => "ტიპი",
            "STATUS"        => "სტატუსი",
            "PROMOTION"     => "აქცია",
            "TOTAL_AREA"    => "სრული<br>ფართი",
            "INNER_AREA"    => "შიდა<br>ფართი",
            "SUMMER_AREA"   => "საზაფხულო<br>ფართი",
            "KVM_PRICE"     => "კვ.მ.<br>ფასი $",
            "PRICE_TOTAL"   => "ჯამური<br>ღირებულება $",
            "SALE_PRICE"    => "გაყიდვის<br>ღირებულება",
            "CATALOG_PRICE" => "კატალოგის<br>ფასი",
            "NFS_COMMENT"   => "NFS<br>კომენტარი",
        ),

        // Statuses that can be SET: value written => label // SYNC
        "STATUS_OPTIONS" => array(
            "თავისუფალი" => "თავისუფალი",
            "NFS"        => "NFS",
        ),

        // Promotion is a string property — which stored values mean Yes/No
        "PROMOTION_YES"             => array("Y", "Yes", "YES", "კი", "1"),
        "PROMOTION_NO"              => array("N", "No", "NO", "არა", "0"),
        "PROMOTION_ANY_ENUM_IS_YES" => false,

        // Price change types and the price they work on: key sent to the API => label // SYNC (keys)
        "PRICE_CHANGE_TYPES" => array(
            "percent" => "პროცენტი (%)",
            "fixed"   => "ფიქსირებული თანხა ($)",
            "set"     => "ახალი ფასი ($)",
        ),
        // percent gives the same result on both, so it has no target
        "PRICE_TARGETS" => array(
            "sqm"  => "1 კვ.მ. ფასი",
            "full" => "სრული ფასი",
        ),
        // value field label: "type" or "type.target"
        "PRICE_VALUE_LABELS" => array(
            "percent"    => "პროცენტი (%)",
            "fixed.sqm"  => "თანხა 1 კვ.მ.-ზე ($)",
            "fixed.full" => "თანხა სრულ ფასზე ($)",
            "set.sqm"    => "ახალი 1 კვ.მ. ფასი ($)",
            "set.full"   => "ახალი სრული ფასი ($)",
        ),
        // list where price-change.php logs every change (created by /custom/setup/priceChangeLog.php)
        "PRICE_LOG_CODE" => "PRICE_CHANGE_LOG",

        "API_STATUS" => "/rest/local/api/product/status-change.php",
        "API_PRICE"  => "/rest/local/api/product/price-change.php",
    );
    return $cfg;
}
/* ===================================================================== */

function pmCode($key) {
    $c = pmCfg();
    return isset($c["PROPS"][$key]) ? $c["PROPS"][$key] : $key;
}

function scalarPropertyValue($v) {
    if ($v === null || $v === false) {
        return "";
    }
    if (is_array($v)) {
        $first = reset($v);
        if (is_array($first) && array_key_exists("VALUE", $first)) {
            return trim((string)$first["VALUE"]);
        }
        return trim((string)$first);
    }
    return trim((string)$v);
}

/** Scalar value of a property by logical key */
function pv($p, $key) {
    $code = pmCode($key);
    return scalarPropertyValue(isset($p[$code]) ? $p[$code] : "");
}

/** Is the product's status one of VISIBLE_STATUSES (case/space-insensitive) */
function isVisibleStatus($p) {
    $allowed = pmCfg()["VISIBLE_STATUSES"];
    if (empty($allowed)) return true;
    $st = mb_strtolower(pv($p, "STATUS"));
    foreach ($allowed as $a) {
        if ($st === mb_strtolower(trim($a))) return true;
    }
    return false;
}

function isPromoYes($p) {
    $c    = pmCfg();
    $code = pmCode("PROMOTION");
    $val  = scalarPropertyValue($p[$code] ?? "");
    $xml  = scalarPropertyValue($p[$code . "_XML_ID"] ?? "");

    if (in_array($val, $c["PROMOTION_YES"], true) || in_array($xml, $c["PROMOTION_YES"], true)) return true;
    if (in_array($val, $c["PROMOTION_NO"], true)  || in_array($xml, $c["PROMOTION_NO"], true))  return false;

    return $c["PROMOTION_ANY_ENUM_IS_YES"]
        && (int)scalarPropertyValue($p[$code . "_ENUM_ID"] ?? "") > 0;
}

function getCIBlockElementsByFilter($arFilter = array()) {
    $arElements = array();
    $arSelect = array("ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_*");

    $res = CIBlockElement::GetList(array("ID" => "ASC"), $arFilter, false, false, $arSelect);
    while ($ob = $res->GetNextElement()) {
        $arPushs = $ob->GetFields();
        foreach ($ob->GetProperties() as $key => $arProp) {
            $propCode = (!empty($arProp["CODE"]) ? $arProp["CODE"] : $key);
            // raw value (~VALUE): the page escapes on output, VALUE is already escaped by Bitrix
            $arPushs[$propCode] = array_key_exists("~VALUE", $arProp) ? $arProp["~VALUE"] : $arProp["VALUE"];
            if ($arProp["PROPERTY_TYPE"] === "L") {
                $arPushs[$propCode . "_ENUM_ID"] = $arProp["VALUE_ENUM_ID"] ?? "";
                $arPushs[$propCode . "_XML_ID"]  = $arProp["VALUE_XML_ID"] ?? "";
            }
        }
        $basePrice = CPrice::GetBasePrice($arPushs["ID"]);
        $arPushs["CATALOG_PRICE"] = ($basePrice && isset($basePrice["PRICE"])) ? pmNum($basePrice["PRICE"]) : "";
        $arElements[] = $arPushs;
    }

    // Natural sort by configured keys (values are strings: "10" must come after "2")
    $sortKeys = pmCfg()["SORT_KEYS"];
    usort($arElements, function ($a, $b) use ($sortKeys) {
        foreach ($sortKeys as $k) {
            $cmp = strnatcasecmp(pv($a, $k), pv($b, $k));
            if ($cmp !== 0) return $cmp;
        }
        return (int)$a["ID"] - (int)$b["ID"];
    });

    return $arElements;
}

function getUniqueValues($products, $key) {
    $values = array();
    foreach ($products as $p) {
        $val = pv($p, $key);
        if ($val !== "" && !in_array($val, $values, true)) {
            $values[] = $val;
        }
    }
    natcasesort($values);
    return array_values($values);
}

/** Cell value for the table */
function cellValue($p, $key) {
    if ($key === "ID")            return (string)$p["ID"];
    if ($key === "PROMOTION")     return isPromoYes($p) ? "Yes" : "No";
    if ($key === "CATALOG_PRICE") return (string)$p["CATALOG_PRICE"];
    // the comment explains the current NFS status only
    if ($key === "NFS_COMMENT")   return pv($p, "STATUS") === pmCfg()["COMMENT_STATUS"] ? pv($p, $key) : "";
    return pv($p, $key);
}

function toFloat($v) {
    return (float)str_replace(array(",", " "), array(".", ""), $v);
}

/** Price without trailing zeros, the way price-change.php writes it (69043.00000000 -> 69043) */
function pmNum($v) {
    $s = number_format((float)$v, 2, ".", "");
    return strpos($s, ".") === false ? $s : rtrim(rtrim($s, "0"), ".");
}

function printArr($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

$CFG = pmCfg();

/* ---------- Debug flag (admin only) ---------- */
global $USER;
$pmDebug = (is_object($USER) && $USER->IsAdmin() && ($_GET["debug_props"] ?? "") === "1");

/* ---------- Load products: only VISIBLE_STATUSES ---------- */
$arBaseFilter = array("IBLOCK_ID" => $CFG["IBLOCK_ID"]);
if (!empty($CFG["VISIBLE_STATUSES"])) {
    $arBaseFilter["PROPERTY_" . pmCode("STATUS")] = $CFG["VISIBLE_STATUSES"];
}
$products = array_values(array_filter(getCIBlockElementsByFilter($arBaseFilter), "isVisibleStatus"));

// Debug view shows the whole iblock (all statuses) — only with ?debug_props=1
$debugProducts = $pmDebug ? getCIBlockElementsByFilter(array("IBLOCK_ID" => $CFG["IBLOCK_ID"])) : array();

// Unique values for each dropdown filter (built from loaded products only)
$selectOptions = array();
foreach ($CFG["SELECT_FILTERS"] as $sf) {
    $selectOptions[$sf["name"]] = getUniqueValues($products, $sf["key"]);
}

// Status options for the status panel: only the configured ones
$statusOptions = $CFG["STATUS_OPTIONS"];

// Action tabs above the table; the open one is posted with the filter form so it stays open after filtering
$actionTabs = array("price" => "ფასის ცვლილება", "status" => "სტატუსი / აქცია");
$activeTab  = isset($actionTabs[$_POST["active_tab"] ?? ""]) ? $_POST["active_tab"] : "price";

// Price change log list (linked under the price panel); 0 = not created yet
$priceLog   = CIBlock::GetList(array(), array("CODE" => $CFG["PRICE_LOG_CODE"], "CHECK_PERMISSIONS" => "N"))->Fetch();
$priceLogId = $priceLog ? (int)$priceLog["ID"] : 0;

$filtered   = $products;
$isFiltered = false;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["filter_submit"])) {
    $isFiltered = true;

    $selVals = array();
    foreach ($CFG["SELECT_FILTERS"] as $sf) {
        $selVals[$sf["key"]] = trim($_POST[$sf["name"]] ?? "");
    }
    $fNumber    = trim($_POST["f_number"]     ?? "");
    $fFloorFrom = trim($_POST["f_floor_from"] ?? "");
    $fFloorTo   = trim($_POST["f_floor_to"]   ?? "");
    $fAreaFrom  = trim($_POST["f_area_from"]  ?? "");
    $fAreaTo    = trim($_POST["f_area_to"]    ?? "");

    $filtered = array_values(array_filter($products, function ($p) use ($selVals, $fNumber, $fFloorFrom, $fFloorTo, $fAreaFrom, $fAreaTo) {
        foreach ($selVals as $key => $val) {
            if ($val !== "" && pv($p, $key) !== $val) return false;
        }
        if ($fNumber !== "" && pv($p, "NUMBER") !== $fNumber) return false;

        $floor = (int)pv($p, "FLOOR");
        if ($fFloorFrom !== "" && $floor < (int)$fFloorFrom) return false;
        if ($fFloorTo   !== "" && $floor > (int)$fFloorTo)   return false;

        $area = toFloat(pv($p, "TOTAL_AREA"));
        if ($fAreaFrom !== "" && $area < (float)$fAreaFrom) return false;
        if ($fAreaTo   !== "" && $area > (float)$fAreaTo)   return false;
        return true;
    }));
}

// Filter description sent to the API (for logs)
$filterInfoParts = array();
foreach ($CFG["SELECT_FILTERS"] as $sf) {
    if (!empty($_POST[$sf["name"]])) $filterInfoParts[] = $sf["label"] . ": " . $_POST[$sf["name"]];
}
if (!empty($_POST["f_number"])) $filterInfoParts[] = "ბინის №: " . $_POST["f_number"];
if (!empty($_POST["f_floor_from"]) || !empty($_POST["f_floor_to"])) {
    $filterInfoParts[] = "სართული: " . ($_POST["f_floor_from"] ?? "") . "-" . ($_POST["f_floor_to"] ?? "");
}
if (!empty($_POST["f_area_from"]) || !empty($_POST["f_area_to"])) {
    $filterInfoParts[] = "ფართი მ²: " . ($_POST["f_area_from"] ?? "") . "-" . ($_POST["f_area_to"] ?? "");
}
$filterInfo = implode(" | ", $filterInfoParts);
?>
<!DOCTYPE html>
<html lang="ka">
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Arial, sans-serif; font-size: 13px; }

    .filter-form { background: #f4f4f4; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
    .filter-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .filter-group { display: flex; flex-direction: column; gap: 4px; }
    .filter-group label { font-weight: bold; }
    .filter-group select,
    .filter-group input[type="number"],
    .filter-group input[type="text"] { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; min-width: 160px; }
    .floor-range { display: flex; gap: 5px; align-items: center; }
    .floor-range input { min-width: 70px !important; }
    .btn-filter { background: #e67e22; color: #fff; border: none; padding: 9px 24px; font-size: 14px; border-radius: 4px; cursor: pointer; }
    .btn-filter:hover { background: #ca6f1e; }
    .required-star { color: red; margin-left: 3px; }

    .count-line { font-weight: bold; margin: 20px 0 10px; font-size: 14px; }
    table { border-collapse: collapse; width: 100%; font-size: 12px; }
    th { background: #2c6fad; color: #fff; padding: 7px 5px; text-align: center; border: 1px solid #ccc; white-space: nowrap; }
    td { padding: 6px 5px; border: 1px solid #ddd; text-align: center; }
    tr:nth-child(even) td { background: #f0f5ff; }

    /* ფასის ცვლილება / სტატუსი / აქცია - ტაბები */
    .tabs-container { margin-top: 20px; margin-bottom: 10px; }
    .tab-buttons { display: flex; flex-wrap: wrap; }
    .tab-btn { padding: 10px 28px; cursor: pointer; border: 1px solid #ccc; border-bottom: none; background: #e8e8e8; font: 13px Arial, sans-serif; border-radius: 4px 4px 0 0; transition: background .15s; }
    .tab-btn:hover { background: #dcdcdc; }
    .tab-btn.active { background: #2c6fad; color: #fff; border-color: #2c6fad; }
    .tab-content { display: none; border: 1px solid #ccc; padding: 20px; border-radius: 0 4px 4px 4px; background: #fafafa; }
    .tab-content.active { display: block; }
    .form-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .form-group { display: flex; flex-direction: column; gap: 4px; }
    .form-group label { font-weight: bold; font-size: 13px; }
    .form-group select { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; min-width: 180px; }
    .comment-group { display: none; margin-top: 14px; max-width: 600px; }
    .comment-group textarea { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font: inherit; resize: vertical; }
    td.cell-comment { text-align: left; white-space: pre-wrap; min-width: 200px; max-width: 360px; }

    .form-group input[type="number"] { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; min-width: 160px; }
    .panel-note { margin: 12px 0 0; font-size: 12px; color: #555; }

    .btn-action { color: #fff; border: none; padding: 9px 22px; font-size: 14px; border-radius: 4px; cursor: pointer; }
    .btn-action:disabled { opacity: .6; cursor: wait; }
    .btn-update { background: #2c6fad; }
    .btn-update:hover { background: #1a4f85; }

    .loading-msg { display: none; margin-top: 12px; padding: 10px 16px; background: #fff8e1; border: 1px solid #f0c040; border-radius: 4px; font-size: 13px; color: #7a5800; }
    .success-msg { display: none; margin-top: 12px; padding: 10px 16px; background: #e8f5e9; border: 1px solid #66bb6a; border-radius: 4px; font-size: 13px; color: #2e7d32; }
    .error-msg   { display: none; margin-top: 12px; padding: 10px 16px; background: #ffebee; border: 1px solid #ef9a9a; border-radius: 4px; font-size: 13px; color: #b71c1c; }
    .success-msg, .error-msg { white-space: pre-line; }

    .table-wrap { overflow-x: auto; }
    .debug-box { background: #fffbe6; border: 1px solid #e0c060; padding: 12px; margin-bottom: 20px; border-radius: 6px; }
    .debug-box td, .debug-box th { text-align: left; }
    .debug-missing { color: #b71c1c; font-weight: bold; }
    .debug-ok { color: #2e7d32; }
</style>
</head>
<body>

<?php if ($pmDebug): ?>
<div class="debug-box">
    <h3 style="margin-top:0;">Debug: IBLOCK_ID = <?= (int)$CFG["IBLOCK_ID"] ?> — properties</h3>
    <?php
    $existingCodes = array();
    $rows = array();
    $rsProp = CIBlockProperty::GetList(array("SORT" => "ASC", "ID" => "ASC"), array("IBLOCK_ID" => $CFG["IBLOCK_ID"]));
    while ($prop = $rsProp->Fetch()) {
        $code = $prop["CODE"] !== "" ? $prop["CODE"] : $prop["ID"];
        $existingCodes[] = $code;
        $enumText = "";
        if ($prop["PROPERTY_TYPE"] === "L") {
            $enums = array();
            $rsEnum = CIBlockPropertyEnum::GetList(array("SORT" => "ASC"), array("PROPERTY_ID" => $prop["ID"]));
            while ($e = $rsEnum->Fetch()) {
                $enums[] = $e["ID"] . ": " . $e["VALUE"] . " [" . $e["XML_ID"] . "]";
            }
            $enumText = implode("; ", $enums);
        }
        $sample = isset($debugProducts[0][$code]) ? $debugProducts[0][$code] : "";
        $rows[] = array($prop["ID"], $code, $prop["NAME"], $prop["PROPERTY_TYPE"] . ($prop["USER_TYPE"] ? "/" . $prop["USER_TYPE"] : ""), $prop["MULTIPLE"], $enumText, is_array($sample) ? json_encode($sample, JSON_UNESCAPED_UNICODE) : $sample);
    }
    ?>
    <p><b>Config mapping check:</b></p>
    <ul>
        <?php foreach ($CFG["PROPS"] as $logical => $code): if ($code === "") continue; $ok = in_array($code, $existingCodes, true); ?>
            <li class="<?= $ok ? "debug-ok" : "debug-missing" ?>"><?= htmlspecialchars($logical) ?> → <?= htmlspecialchars($code) ?> <?= $ok ? "✔" : "✘ NOT FOUND" ?></li>
        <?php endforeach; ?>
    </ul>
    <p><b>Distinct values (whole iblock):</b>
        სტატუსი: <?= htmlspecialchars(implode(", ", getUniqueValues($debugProducts, "STATUS"))) ?> —
        აქცია: <?= htmlspecialchars(implode(", ", getUniqueValues($debugProducts, "PROMOTION"))) ?: "(ცარიელი)" ?>
    </p>
    <div class="table-wrap">
    <table>
        <thead><tr><th>ID</th><th>CODE</th><th>NAME</th><th>TYPE</th><th>MULTIPLE</th><th>Enum values (ID: VALUE [XML_ID])</th><th>Sample (1st element)</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr><?php foreach ($r as $cell): ?><td><?= htmlspecialchars((string)$cell) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p>Elements in iblock: <?= count($debugProducts) ?> — shown on dashboard (<?= htmlspecialchars(implode(", ", $CFG["VISIBLE_STATUSES"])) ?>): <?= count($products) ?></p>
</div>
<?php endif; ?>

<!-- ფილტრის ფორმა -->
<form method="POST" class="filter-form">
    <input type="hidden" name="filter_submit" value="1">
    <input type="hidden" name="active_tab" id="active-tab" value="<?= htmlspecialchars($activeTab) ?>">
    <div class="filter-row">

        <?php foreach ($CFG["SELECT_FILTERS"] as $sf): ?>
        <div class="filter-group">
            <label><?= htmlspecialchars($sf["label"]) ?><?= $sf["req"] ? ' <span class="required-star">*</span>' : '' ?></label>
            <select name="<?= htmlspecialchars($sf["name"]) ?>">
                <option value="">-- ყველა --</option>
                <?php foreach ($selectOptions[$sf["name"]] as $v): ?>
                    <option value="<?= htmlspecialchars($v) ?>" <?= (($_POST[$sf["name"]] ?? "") === $v ? "selected" : "") ?>><?= htmlspecialchars($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endforeach; ?>

        <div class="filter-group">
            <label>სართული (დან – მდე)</label>
            <div class="floor-range">
                <input type="number" name="f_floor_from" placeholder="დან" value="<?= htmlspecialchars($_POST["f_floor_from"] ?? "") ?>">
                <span>–</span>
                <input type="number" name="f_floor_to"   placeholder="მდე" value="<?= htmlspecialchars($_POST["f_floor_to"]   ?? "") ?>">
            </div>
        </div>

        <div class="filter-group">
            <label>სრული ფართი მ² (დან – მდე)</label>
            <div class="floor-range">
                <input type="number" name="f_area_from" placeholder="დან" value="<?= htmlspecialchars($_POST["f_area_from"] ?? "") ?>" min="0" step="0.01">
                <span>–</span>
                <input type="number" name="f_area_to"   placeholder="მდე" value="<?= htmlspecialchars($_POST["f_area_to"]   ?? "") ?>" min="0" step="0.01">
            </div>
        </div>

        <div class="filter-group">
            <label>უძრავი ქონების №</label>
            <input type="text" name="f_number" placeholder="მაგ: 28" value="<?= htmlspecialchars($_POST["f_number"] ?? "") ?>" style="min-width: 120px;">
        </div>

        <div class="filter-group" style="justify-content: flex-end;">
            <button type="submit" class="btn-filter">ფილტრაცია</button>
        </div>

    </div>
</form>

<?php if ($isFiltered): ?>

    <div class="tabs-container">
        <div class="tab-buttons">
            <?php foreach ($actionTabs as $tab => $label): ?>
                <button type="button" class="tab-btn<?= $tab === $activeTab ? " active" : "" ?>" data-tab="<?= $tab ?>" onclick="switchTab('<?= $tab ?>')"><?= htmlspecialchars($label) ?></button>
            <?php endforeach; ?>
        </div>

    <!-- ფასის ცვლილება -->
    <div class="tab-content<?= $activeTab === "price" ? " active" : "" ?>" id="tab-price">
        <div class="form-row">
            <div class="form-group">
                <label>ცვლილების ტიპი</label>
                <select id="price-type" onchange="onPriceTypeChange()">
                    <option value="">-- აირჩიეთ --</option>
                    <?php foreach ($CFG["PRICE_CHANGE_TYPES"] as $k => $label): ?>
                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="price-target-group" style="display: none;">
                <label>ფასის ტიპი</label>
                <select id="price-target" onchange="onPriceTypeChange()">
                    <?php foreach ($CFG["PRICE_TARGETS"] as $k => $label): ?>
                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="price-direction-group">
                <label>მიმართულება</label>
                <select id="price-direction">
                    <option value="increase">მომატება</option>
                    <option value="decrease">დაკლება</option>
                </select>
            </div>

            <div class="form-group">
                <label id="price-value-label">მნიშვნელობა</label>
                <input type="number" id="price-value" min="0.01" step="0.01">
            </div>

            <div class="form-group" style="justify-content: flex-end;">
                <button class="btn-action btn-update" id="price-submit" onclick="submitPriceChange()">ფასის შეცვლა</button>
            </div>
        </div>
        <p class="panel-note">
            იცვლება ფილტრის ყველა პროდუქტის კვ.მ. ფასი და სრული (კატალოგის) ფასი. პროცენტი ორივეზე ერთნაირად მოქმედებს; თანხა და ახალი ფასი - არჩეულზე, მეორე გადაითვლება
            (კვ.მ.-ის არჩევისას სრული = კვ.მ. × ფართი, სრულის არჩევისას კვ.მ. იმავე პროპორციით). ნიუ დეპოზე გეგმების ფასებიც (ერთიანი, 0/20/80, 60 თვიანი) იმავე პროპორციით იცვლება. გაყიდვის ღირებულება არ იცვლება.
            შეცვლამდე გამოჩნდება შედეგი დასადასტურებლად. ყველა ცვლილება იწერება
            <?php if ($priceLogId > 0): ?><a href="/services/lists/<?= $priceLogId ?>/view/0/" target="_blank">ფასის ცვლილებების ლოგში</a><?php else: ?>ფასის ცვლილებების ლოგში<?php endif; ?>.
        </p>
        <div class="loading-msg" id="price-loading">⏳ მიმდინარეობს მონაცემების დამუშავება...</div>
        <div class="success-msg" id="price-success"></div>
        <div class="error-msg"   id="price-error"></div>
    </div>

    <!-- სტატუსი / აქცია -->
    <div class="tab-content<?= $activeTab === "status" ? " active" : "" ?>" id="tab-status">
        <div class="form-row">
            <div class="form-group">
                <label>სტატუსი</label>
                <select id="status-value" onchange="toggleStatusComment()">
                    <option value="">— არ შეცვალოთ —</option>
                    <?php foreach ($statusOptions as $k => $label): ?>
                        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>აქცია</label>
                <select id="status-promotion">
                    <option value="">— არ შეცვალოთ —</option>
                    <option value="Y">Yes</option>
                    <option value="N">No</option>
                </select>
            </div>

            <div class="form-group" style="justify-content: flex-end;">
                <button class="btn-action btn-update" onclick="submitStatusChange()">განახლება</button>
            </div>
        </div>
        <div class="form-group comment-group" id="status-comment-group">
            <label for="status-comment"><?= htmlspecialchars($CFG["COMMENT_STATUS"]) ?> კომენტარი <span class="required-star">*</span></label>
            <textarea id="status-comment" rows="3" maxlength="<?= (int)$CFG["COMMENT_MAX_LENGTH"] ?>" placeholder="რა მიზეზით / რის გამო ეცვლება სტატუსი <?= htmlspecialchars($CFG["COMMENT_STATUS"]) ?>-ზე"></textarea>
        </div>
        <p style="margin: 12px 0 0; font-size: 12px; color: #555;">აირჩიეთ მინიმუმ ერთი ველი (სტატუსი ან აქცია); დანარჩენი უცვლელი დარჩება. <?= htmlspecialchars($CFG["COMMENT_STATUS"]) ?>-ზე გადაყვანისას კომენტარი სავალდებულოა.</p>
        <div class="loading-msg" id="status-loading">⏳ მიმდინარეობს მონაცემების დამუშავება...</div>
        <div class="success-msg" id="status-success">✅ დასრულებულია მონაცემების დამუშავება</div>
        <div class="error-msg"   id="status-error">❌ შეცდომა მონაცემების დამუშავებისას</div>
    </div>
    </div>

    <div class="count-line">რაოდენობა: <?= count($filtered) ?></div>

    <?php if (count($filtered) > 0): ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <?php foreach ($CFG["COLUMNS"] as $label): ?>
                    <th><?= $label /* labels come from config, may contain <br> */ ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($filtered as $p): ?>
            <tr data-id="<?= (int)$p["ID"] ?>">
                <?php foreach ($CFG["COLUMNS"] as $key => $label): ?>
                    <td data-col="<?= htmlspecialchars($key) ?>"<?= $key === "NFS_COMMENT" ? ' class="cell-comment"' : '' ?>><?= htmlspecialchars(cellValue($p, $key)) ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
        <p>ფილტრის შედეგად პროდუქტი ვერ მოიძებნა.</p>
    <?php endif; ?>

    <script>
    const filteredIds = <?= json_encode(array_column($filtered, "ID")) ?>;
    const API_STATUS  = <?= json_encode($CFG["API_STATUS"]) ?>;
    const API_PRICE   = <?= json_encode($CFG["API_PRICE"]) ?>;
    const filterInfo  = <?= json_encode($filterInfo, JSON_UNESCAPED_UNICODE) ?>;
    const COMMENT_STATUS = <?= json_encode($CFG["COMMENT_STATUS"], JSON_UNESCAPED_UNICODE) ?>;
    const PRICE_VALUE_LABELS = <?= json_encode($CFG["PRICE_VALUE_LABELS"], JSON_UNESCAPED_UNICODE) ?>;

    function switchTab(tab) {
        document.querySelectorAll(".tab-btn").forEach(b => b.classList.toggle("active", b.dataset.tab === tab));
        document.querySelectorAll(".tab-content").forEach(c => c.classList.toggle("active", c.id === "tab-" + tab));
        document.getElementById("active-tab").value = tab;
    }

    // percent has no target (same result on sqm and full price); "set" writes one price to every product, so it has no direction
    function onPriceTypeChange() {
        const type   = document.getElementById("price-type").value;
        const target = document.getElementById("price-target").value;
        document.getElementById("price-target-group").style.display    = (type && type !== "percent") ? "flex" : "none";
        document.getElementById("price-direction-group").style.display = type === "set" ? "none" : "flex";
        document.getElementById("price-value-label").innerText = type
            ? PRICE_VALUE_LABELS[type === "percent" ? type : type + "." + target]
            : "მნიშვნელობა";
    }

    function fmtMoney(n) {
        return Number(n).toLocaleString("en-US", { maximumFractionDigits: 2 });
    }

    function failedText(failed, limit) {
        const all  = failed || [];
        const list = all.slice(0, limit).map(f => "ID:" + f.id + " (" + f.error + ")");
        if (all.length > limit) list.push("... და კიდევ " + (all.length - limit));
        return list.join("\n");
    }

    // new prices into the table without reloading (the filter stays as it is)
    function applyPriceResults(products) {
        products.forEach(p => {
            const row = document.querySelector('tr[data-id="' + p.id + '"]');
            if (!row) return;
            const cells = { KVM_PRICE: p.kvm, PRICE_TOTAL: p.total, CATALOG_PRICE: p.catalog };
            Object.keys(cells).forEach(col => {
                const td = row.querySelector('td[data-col="' + col + '"]');
                if (td && cells[col] !== null) td.textContent = cells[col];
            });
        });
    }

    async function submitPriceChange() {
        const type      = document.getElementById("price-type").value;
        const target    = document.getElementById("price-target").value;
        const direction = document.getElementById("price-direction").value;
        const value     = parseFloat(document.getElementById("price-value").value);

        if (!type) { alert("აირჩიეთ ცვლილების ტიპი"); return; }
        if (isNaN(value) || value <= 0) { alert("შეიყვანეთ დადებითი მნიშვნელობა"); return; }
        if (type === "percent" && direction === "decrease" && value >= 100) { alert("დაკლება 100%-ზე ნაკლები უნდა იყოს"); return; }
        if (!filteredIds.length) { alert("ფილტრის შედეგი ცარიელია"); return; }

        const payload = { ids: filteredIds, change_type: type, target: target, direction: direction, value: value, filter_info: filterInfo };
        const btn     = document.getElementById("price-submit");
        const errBox  = document.getElementById("price-error");
        const okBox   = document.getElementById("price-success");

        btn.disabled = true;
        setMsg("price", "loading");

        try {
            // 1. the server calculates what would change, nothing is written yet
            let res  = await post_fetch(API_PRICE, Object.assign({ dry_run: true }, payload));
            let data = await res.json();

            if (!res.ok || !data.success) {
                errBox.textContent = "❌ შეცდომა" + (data.error ? ": " + data.error : "");
                setMsg("price", "error");
                return;
            }
            if (!data.ok) {
                errBox.textContent = "❌ ვერცერთ პროდუქტს ფასი ვერ შეეცვლება:\n" + failedText(data.failed_ids, 20);
                setMsg("price", "error");
                return;
            }

            let question = data.operation
                + "\n\nშეიცვლება " + data.ok + " პროდუქტის ფასი."
                + "\nკატალოგის ფასების ჯამი: " + fmtMoney(data.sum_before) + "$ -> " + fmtMoney(data.sum_after) + "$";
            if (data.plans) question += "\nნიუ დეპოს გეგმების ფასებიც შეიცვლება " + data.plans + " პროდუქტზე.";
            if (data.failed) question += "\n\nგამოტოვდება " + data.failed + " პროდუქტი:\n" + failedText(data.failed_ids, 10);
            question += "\n\nგავაგრძელო?";

            if (!confirm(question)) {
                setMsg("price", "");
                return;
            }

            // 2. write
            res  = await post_fetch(API_PRICE, payload);
            data = await res.json();

            applyPriceResults(data.products || []);

            if (!data.updated) {
                errBox.textContent = "❌ შეცდომა" + (data.error ? ": " + data.error : "")
                    + (data.failed ? "\n" + failedText(data.failed_ids, 20) : "");
                setMsg("price", "error");
                return;
            }

            okBox.textContent = "✅ დასრულებულია - " + data.operation + ", განახლდა " + data.updated + " პროდუქტი";
            if (data.failed) okBox.textContent += "\nვერ განახლდა " + data.failed + ":\n" + failedText(data.failed_ids, 20);
            if (data.log_error) okBox.textContent += "\n⚠ " + data.log_error;
            if (data.log_url) {
                const link = document.createElement("a");
                link.href = data.log_url;
                link.target = "_blank";
                link.textContent = "ლოგის ჩანაწერი";
                okBox.append("\n", link);
            }
            setMsg("price", "success");
        } catch (e) {
            errBox.textContent = "❌ შეცდომა: " + e.message;
            setMsg("price", "error");
        } finally {
            btn.disabled = false;
        }
    }

    // the comment field is shown only when the status that needs a reason (NFS) is picked
    function toggleStatusComment() {
        const show = document.getElementById("status-value").value === COMMENT_STATUS;
        document.getElementById("status-comment-group").style.display = show ? "flex" : "none";
    }

    function setMsg(prefix, state) {
        ["loading", "success", "error"].forEach(s =>
            document.getElementById(prefix + "-" + s).style.display = (s === state ? "block" : "none")
        );
    }

    async function post_fetch(url, data = {}) {
        return fetch(url, {
            method: "POST", mode: "cors", cache: "no-cache",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            redirect: "follow", referrerPolicy: "no-referrer",
            body: JSON.stringify(data)
        });
    }

    async function submitStatusChange() {
        const status    = document.getElementById("status-value").value;
        const promotion = document.getElementById("status-promotion").value;

        const hasStatus = status !== "";
        const hasPromo  = promotion === "Y" || promotion === "N";

        if (!hasStatus && !hasPromo) {
            alert("აირჩიეთ მინიმუმ ერთი: სტატუსი ან აქცია (ან ორივე ერთად)");
            return;
        }
        if (!filteredIds.length) { alert("ფილტრის შედეგი ცარიელია"); return; }

        const needsComment = status === COMMENT_STATUS;
        const comment      = document.getElementById("status-comment").value.trim();
        if (needsComment && comment === "") {
            alert(COMMENT_STATUS + "-ზე გადაყვანისას მიუთითეთ კომენტარი: რა მიზეზით / რის გამო");
            document.getElementById("status-comment").focus();
            return;
        }

        const payload = { ids: filteredIds, filter_info: filterInfo };
        if (hasStatus)    payload.status = status;
        if (hasPromo)     payload.promotion = promotion;
        if (needsComment) payload.status_comment = comment;

        setMsg("status", "loading");

        try {
            const res  = await post_fetch(API_STATUS, payload);
            const data = await res.json();

            if (res.ok) {
                document.getElementById("status-success").innerText =
                    "✅ დასრულებულია — განახლდა " + data.updated + " პროდუქტი";
                setMsg("status", "success");
            } else {
                document.getElementById("status-error").innerText =
                    "❌ შეცდომა" + (data.error ? ": " + data.error : "");
                setMsg("status", "error");
            }
        } catch (e) {
            document.getElementById("status-error").innerText = "❌ შეცდომა: " + e.message;
            setMsg("status", "error");
        }
    }
    </script>

<?php endif; ?>

</body>
</html>

<?php require($_SERVER["DOCUMENT_ROOT"]."/bitrix/footer.php"); ?>