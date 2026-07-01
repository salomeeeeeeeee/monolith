<?php

// ═══════════════════════════════════════════════════════════════════
//  TOKEN — replace the string below with your secret token
// ═══════════════════════════════════════════════════════════════════
define("API_TOKEN", "MONOLIGHTFMG2026");

// ─── Bearer token check — before loading Bitrix ───────────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');

if (!preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $tokenMatch) || $tokenMatch[1] !== API_TOKEN) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(["status" => 401, "error" => "Unauthorized — Bearer token is required"], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Reduce Bitrix overhead ───────────────────────────────────────
define("STOP_STATISTICS",       true);
define("NO_KEEP_STATISTIC",     "Y");
define("NO_AGENT_STATISTIC",    "Y");
define("NO_AGENT_CHECK",        true);
define("DisableEventsCheck",    true);
define("NOT_CHECK_PERMISSIONS", true);

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

CModule::IncludeModule('iblock');
CModule::IncludeModule('catalog');
CModule::IncludeModule('crm');

global $USER, $DB;

$authorizedUser = false;
if (!$USER->IsAuthorized()) {
    $USER->Authorize(1);
    $authorizedUser = true;
}

function getNbgRate(): float
{
    $date = date("Y-m-d");
    $url  = "https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=USD&date={$date}";
    $data = @json_decode(@file_get_contents($url));

    return isset($data[0]->currencies[0]->rate) ? (float)$data[0]->currencies[0]->rate : 0;
}

// ─── Query parameters ────────────────────────────────────────────
$projectId = isset($_GET["projectId"]) && is_numeric($_GET["projectId"]) ? (int)$_GET["projectId"] : null;
$productId = isset($_GET["productId"]) && is_numeric($_GET["productId"]) ? (int)$_GET["productId"] : null;

// ─── Filter ───────────────────────────────────────────────────────
$arFilter = ["IBLOCK_ID" => 14, "ACTIVE" => "Y"];

if ($productId) {
    $arFilter["ID"] = $productId;
} elseif ($projectId) {
    $arFilter["IBLOCK_SECTION_ID"] = $projectId;
} else {
    if ($authorizedUser) {
        $USER->Logout();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => 400, "error" => "projectId or productId is required"], JSON_UNESCAPED_UNICODE);
    exit;
}

$nbg     = getNbgRate();
$baseUrl = ($_SERVER["REQUEST_SCHEME"] ?? "https") . "://" . preg_replace('/:\d+$/', '', $_SERVER["HTTP_HOST"]);

// ─── Fetch — Pass 1: raw data ────────────────────────────────────
$arSelect   = ["ID", "NAME", "IBLOCK_ID", "IBLOCK_SECTION_ID", "DETAIL_PICTURE", "PROPERTY_*"];
$rawItems   = [];
$allProdIds = [];
$allFileIds = [];
$seenIds    = [];

function collectFileIds($value, array &$allFileIds): void
{
    if (is_array($value)) {
        foreach ($value as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $allFileIds[$id] = true;
            }
        }
        return;
    }

    $id = (int)$value;
    if ($id > 0) {
        $allFileIds[$id] = true;
    }
}

$res = CIBlockElement::GetList(
    ["SORT" => "ASC"],
    $arFilter,
    false,
    ["nPageSize" => 99999],
    $arSelect
);

while ($ob = $res->GetNextElement()) {
    $arFields = $ob->GetFields();
    $arProps  = $ob->GetProperties();

    $pid = (int)$arFields["ID"];
    if (in_array($pid, $seenIds, true)) {
        continue;
    }
    $seenIds[]    = $pid;
    $allProdIds[] = $pid;

    $props = [];
    foreach ($arProps as $code => $arProp) {
        $props[$code] = $arProp["VALUE"];
    }

    $fileIds = [
        "image"           => (int)($arFields["DETAIL_PICTURE"] ?? 0),
        "threedrender"    => $props["threedrender"]    ?? 0,
        "floorplan"       => $props["floorplan"]       ?? 0,
        "mtavari_foto"    => $props["mtavari_foto"]    ?? 0,
        "MORE_PHOTO"      => $props["MORE_PHOTO"]      ?? [],
        "erteulis_gegma"  => (int)($props["erteulis_gegma"]     ?? 0),
        "erteuli_render"  => (int)($props["erteuli_render"]     ?? 0),
        "sartulis_gegma"  => (int)($props["sartulis_gegma"]     ?? 0),
        "sartulis_render" => (int)($props["sartulis_render"]    ?? 0),
        "project_pics"    => (int)($props["project_pics"]       ?? 0),
        "company_logo"    => (int)($props["company_logo"]       ?? 0),
        "binis_gegmareba" => (int)($props["binis_gegmareba"]    ?? 0),
        "render_3D"       => (int)($props["render_3D"]          ?? 0),
        "sartulis2D"      => (int)($props["sartulis2D"]         ?? 0),
        "binisNaxazi2D"   => (int)($props["binisNaxazi2D"]      ?? 0),
    ];

    foreach ($fileIds as $fid) {
        collectFileIds($fid, $allFileIds);
    }

    $rawItems[] = ["fields" => $arFields, "props" => $props, "fileIds" => $fileIds];
}

if ($authorizedUser) {
    $USER->Logout();
}

// ─── Pass 2: batch — prices (1 SQL) ─────────────────────────────
$priceMap = [];
if (!empty($allProdIds)) {
    $idsStr   = implode(',', $allProdIds);
    $priceRes = $DB->Query(
        "SELECT PRODUCT_ID, PRICE FROM b_catalog_price
         WHERE PRODUCT_ID IN ($idsStr) AND CATALOG_GROUP_ID = 1"
    );
    while ($row = $priceRes->Fetch()) {
        $priceMap[(int)$row['PRODUCT_ID']] = (float)$row['PRICE'];
    }
}

// ─── Pass 2: batch — file URLs (1 SQL) ───────────────────────────
$fileMap = [];
if (!empty($allFileIds)) {
    $idsStr  = implode(',', array_keys($allFileIds));
    $fileRes = $DB->Query(
        "SELECT ID, SUBDIR, FILE_NAME FROM b_file WHERE ID IN ($idsStr)"
    );
    while ($row = $fileRes->Fetch()) {
        $fileMap[(int)$row['ID']] = $baseUrl . "/upload/" . $row['SUBDIR'] . "/" . $row['FILE_NAME'];
    }
}

$fUrl = function (int $id) use ($fileMap): ?string {
    return ($id > 0 && isset($fileMap[$id])) ? $fileMap[$id] : null;
};

$fUrls = function ($ids) use ($fileMap): array {
    if (!is_array($ids)) {
        $ids = [(int)$ids];
    }

    $urls = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0 && isset($fileMap[$id])) {
            $urls[] = $fileMap[$id];
        }
    }

    return $urls;
};

// ─── Pass 3: build response ───────────────────────────────────────
$arElements = [];

foreach ($rawItems as $item) {
    $arFields = $item["fields"];
    $props    = $item["props"];
    $fids     = $item["fileIds"];
    $pid      = (int)$arFields["ID"];

    $priceUsd = isset($priceMap[$pid]) ? round($priceMap[$pid], 2) : null;
    if ($priceUsd === null && !empty($props["__9YCWGZ"]) && is_numeric($props["__9YCWGZ"])) {
        $priceUsd = round((float)$props["__9YCWGZ"], 2);
    }

    $priceGel = ($priceUsd !== null && $nbg > 0) ? round($priceUsd * $nbg, 2) : null;

    $ownerContactName = null;
    if (!empty($props["OWNER_PERSONAL_CONTACT"])) {
        $cRes = CCrmContact::GetList([], ["ID" => $props["OWNER_PERSONAL_CONTACT"]], ["ID", "NAME", "LAST_NAME"]);
        if ($cRow = $cRes->Fetch()) {
            $ownerContactName = trim($cRow["NAME"] . " " . $cRow["LAST_NAME"]);
        }
    }

    $responsibleName = null;
    if (!empty($props["DEAL_RESPONSIBLE"])) {
        $uRes = CUser::GetByID($props["DEAL_RESPONSIBLE"]);
        if ($uRow = $uRes->Fetch()) {
            $responsibleName = trim($uRow["NAME"] . " " . $uRow["LAST_NAME"]);
        }
    }

    $reservationStageId = null;
    $reservationDate    = null;
    if (!empty($props["OWNER_DEAL"])) {
        $dRes = CCrmDeal::GetList(["ID" => "ASC"], ["ID" => $props["OWNER_DEAL"]], ["ID", "STAGE_ID", "UF_CRM_1779278567041"]);
        if ($dRow = $dRes->Fetch()) {
            $reservationStageId = $dRow["STAGE_ID"];
            $reservationDate    = $dRow["UF_CRM_1779278567041"];
        }
    }

    $block = $props["_L24CUB"] ?? $props["_MVA3NL"] ?? null;

    $raw = [
        // ─── System fields ───────────────────────────────────────
        "id"                    => $pid,
        "name"                  => $arFields["NAME"] ?: null,

        // ─── Status ──────────────────────────────────────────────
        "status"                => $props["_P64GYD"] ?? "",

        // ─── Price ───────────────────────────────────────────────
        "price"                 => $priceUsd,
        // "priceGel"              => $priceGel,
        "totalPriceUsd"         => !empty($props["__9YCWGZ"]) ? (float)$props["__9YCWGZ"] : null,
        "kvmPrice"              => !empty($props["__6ZWTER"]) ? (float)$props["__6ZWTER"] : null,

        // ─── Location / Identification ───────────────────────────
        "project"               => $props["__VO9RG4"] ?? null,
        "block"                 => $block,
        "sector"                => $props["_3BU0JH"] ?? null,
        "floor"                 => $props["_FTRIDL"] ?? null,
        "number"                => $props["__6KWOWZ"] ?? null,
        "stairwell"             => $props["_D599QA"] ?? null,
        "cadastralCode"         => $props["__51MODL"] ?? null,
        "view"                  => $props["_XGIF25"] ?? null,

        // ─── Classification ──────────────────────────────────────
        "productType"           => $props["__X1GCRZ"] ?? null,
        "condition"             => $props["_H8WF0T"] ?? null,
        "sale"                  => $props["_UQIM2I"] ?? null,
        "salePrice"             => !empty($props["__YOIUM1"]) ? (float)$props["__YOIUM1"] : null,
        "projectEndDate"        => $props["__THLWP9"] ?? null,

        // ─── Areas ───────────────────────────────────────────────
        "totalArea"             => !empty($props["__173JA5"]) ? (float)$props["__173JA5"] : null,
        "livingSpace"           => !empty($props["__US58ND"]) ? (float)$props["__US58ND"] : null,
        "balconyArea"           => !empty($props["__BL1XXK"]) ? (float)$props["__BL1XXK"] : null,
        "balcony1"              => $props["_1_7CGWZ9"] ?? null,
        "balcony2"              => $props["_2_I5WZ38"] ?? null,

        // ─── Rooms ───────────────────────────────────────────────
        "hall"                  => $props["__J9TMOP"] ?? null,
        "rooms"                 => $props["__WX6YWZ"] ?? null,
        "bedrooms"              => $props["__KYRP1L"] ?? null,
        "bedroom1"              => $props["_1_UJ6WRQ"] ?? null,
        "bedroom2"              => $props["_2_UH0KQR"] ?? null,
        "bedroom3"              => $props["_3_4Y50I1"] ?? null,
        "livingRoom"            => $props["__Z60OKH"] ?? null,
        "wardrobe"              => $props["__J33KT8"] ?? null,

        // ─── Bathrooms ───────────────────────────────────────────
        "wcCount"               => $props["__9H8XS9"] ?? null,
        "wc1"                   => $props["_1_8M61S3"] ?? null,
        "wc2"                   => $props["_2_LK1VJB"] ?? null,

        // ─── CRM ─────────────────────────────────────────────────
        "ownerDeal"             => $props["OWNER_DEAL"] ?? null,
        "ownerContact"          => $props["OWNER_PERSONAL_CONTACT"] ?? null,
        "ownerContactName"      => $ownerContactName,
        "responsible"           => $props["DEAL_RESPONSIBLE"] ?? null,
        "responsibleName"       => $responsibleName,
        "queue"                 => $props["QUEUE"] ?? null,
        "reservationStageId"    => $reservationStageId,
        "reservationDate"       => $reservationDate,

        // ─── Images / Files ────────────────────────────────────
        "image"                 => $fUrl($fids["image"]),
        "mainPhoto"             => $fUrl(is_array($fids["mtavari_foto"]) ? (int)reset($fids["mtavari_foto"]) : (int)$fids["mtavari_foto"]),
        "morePhotos"            => $fUrls($fids["MORE_PHOTO"]),
        "threedrender"          => $fUrl(is_array($fids["threedrender"]) ? (int)reset($fids["threedrender"]) : (int)$fids["threedrender"]),
        "floorplan"             => $fUrl(is_array($fids["floorplan"]) ? (int)reset($fids["floorplan"]) : (int)$fids["floorplan"]),
        "apartmentPlan"         => $fUrl($fids["erteulis_gegma"]),
        "apartmentRender"       => $fUrl($fids["erteuli_render"]),
        "floorPlan"             => $fUrl($fids["sartulis_gegma"]),
        "floorRender"           => $fUrl($fids["sartulis_render"]),
        "projectPics"           => $fUrl($fids["project_pics"]),
        "companyLogo"           => $fUrl($fids["company_logo"]),
        "buildingPlan"          => $fUrl($fids["binis_gegmareba"]),
        "render3d"              => $fUrl($fids["render_3D"]),
        "floor2d"               => $fUrl($fids["sartulis2D"]),
        "apartmentView2d"       => $fUrl($fids["binisNaxazi2D"]),
    ];

    $always = ["id", "status"];
    $arElements[] = array_filter($raw, function ($value, $key) use ($always) {
        if (in_array($key, $always, true)) {
            return true;
        }
        if ($value === null || $value === "" || $value === false) {
            return false;
        }
        if (is_array($value) && count($value) === 0) {
            return false;
        }
        return true;
    }, ARRAY_FILTER_USE_BOTH);
}

$resArray = [];

if (!empty($arElements)) {
    $resArray["status"] = 200;
    $resArray["result"] = $productId ? $arElements[0] : $arElements;
} else {
    $resArray["status"] = 404;
    $resArray["error"]  = "No products found";
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArray, JSON_UNESCAPED_UNICODE);
