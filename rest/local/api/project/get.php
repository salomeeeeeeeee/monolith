<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
ob_end_clean();
$APPLICATION->SetTitle("Title");
CModule::IncludeModule("main");

// --------------------- functions --------------------
function printArr($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

function getNBG_inventory($date) {
    $url  = "https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=USD&date={$date}";
    $seb  = json_decode(file_get_contents($url));
    return $seb[0]->currencies[0]->rate;
}

function getUserName($id) {
    $res = CUser::GetByID($id)->Fetch();
    return $res["NAME"] . " " . $res["LAST_NAME"];
}

function getContactInfo($contactId) {
    $arContact = array();
    $res = CCrmContact::GetList(array("ID" => "ASC"), array("ID" => $contactId), array());
    if ($arContact = $res->Fetch()) {
        $PHONE = \CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'MOBILE|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $MAIL  = \CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'HOME|WORK',   "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $arContact["PHONE"] = $PHONE["VALUE"];
        $arContact["EMAIL"] = $MAIL["VALUE"];
        return $arContact;
    }
    return $arContact;
}

/**
 * Returns all iblock property definitions for iblock 14 as an array of
 * { CODE, NAME, TYPE } — used by catalog.php to build the propertyMap.
 */
function getIblockProperties($iblockId = 14) {
    $properties = [];
    $res = CIBlockProperty::GetList(
        ["SORT" => "ASC"],
        ["IBLOCK_ID" => $iblockId, "ACTIVE" => "Y"]
    );
    while ($prop = $res->Fetch()) {
        if (empty($prop["CODE"])) continue;
        $properties[] = [
            "CODE" => $prop["CODE"],
            "NAME" => $prop["NAME"],
            "TYPE" => $prop["PROPERTY_TYPE"],
        ];
    }
    return $properties;
}

function getProducts($projId = null, $blockId = null) {
    $arFilter = ["IBLOCK_ID" => 14];

    if (!is_null($projId) && $projId !== '') {
        $arFilter["IBLOCK_SECTION_ID"] = $projId;
    }

    $arSelect   = ["ID", "IBLOCK_ID", "IBLOCK_SECTION_ID", "DETAIL_PICTURE", "PROPERTY_*", "STAGE_ID"];
    $sort       = [];
    $count      = 99999;
    $nbg        = getNBG_inventory(date("Y-m-d"));
    $arElements = [];
    $seenIds    = [];   // ← dedup tracker
    $baseUrl    = "https://" . preg_replace('/:\d+$/', '', $_SERVER["HTTP_HOST"]);

    $res = CIBlockElement::GetList($sort, $arFilter, false, ["nPageSize" => $count], $arSelect);
    while ($ob = $res->GetNextElement()) {
        $arFilds = $ob->GetFields();
        $arProps = $ob->GetProperties();
        $arPushs = [];

        foreach ($arFilds as $key => $arFild) $arPushs[$key] = $arFild;
        foreach ($arProps as $key => $arProp) {
            $fieldId           = $arProp["CODE"];
            $arPushs[$fieldId] = $arProp["VALUE"];
        }

        // Skip duplicates (can happen when element belongs to multiple sub-sections)
        if (in_array($arPushs["ID"], $seenIds)) continue;
        $seenIds[] = $arPushs["ID"];

        if ($blockId !== null) {
            if (is_array($blockId)) {
                if (!in_array($arPushs["_L24CUB"], $blockId)) continue;
            }
        }

        if ($arPushs["OWNER_CONTACT"]) {
            $arPushs["OWNER_CONTACT_NAME"] = getContactInfo($arPushs["OWNER_CONTACT"])["FULL_NAME"];
        }

        if ($arPushs["DEAL_RESPONSIBLE"]) {
            $arPushs["DEAL_RESPONSIBLE_NAME"] = getUserName($arPushs["DEAL_RESPONSIBLE"]);
        }

        // ── Legacy image aliases (kept for backward compatibility) ──
        $legacyMap = [
            'image'  => 'erteulis_gegma',
            'image2' => 'binis_gegmareba',
            'image3' => 'render_3D',
            'image4' => 'sartulis2D',
            'image5' => 'binisNaxazi2D',
        ];
        foreach ($legacyMap as $alias => $source) {
            $path = CFile::GetPath($arPushs[$source]);
            $arPushs[$alias] = $path
                ? $baseUrl . $path
                : $baseUrl . "/catalog/projects/resources/noimage.jpg";
        }

        // ── Gallery fields — resolve file IDs to full URLs ──
        $galleryFields = [
            'erteulis_gegma',
            'erteuli_render',
            'sartulis_gegma',
            'sartulis_render',
            'project_pics',
            'company_logo',
        ];
        foreach ($galleryFields as $fieldCode) {
            $resolvedPath = CFile::GetPath($arPushs[$fieldCode]);
            $arPushs[$fieldCode] = $resolvedPath ? $baseUrl . $resolvedPath : "";
        }

        // ── Price ──
        $price = CPrice::GetBasePrice($arPushs["ID"]);
        $arPushs["PRICE"]     = isset($price["PRICE"]) ? round($price["PRICE"], 2) : 0;
        $arPushs["PRICE_GEL"] = round($arPushs["PRICE"] * $nbg, 2);

        // ── Normalised aliases expected by catalog.php ──
        $arPushs["Number"]     = $arPushs["__6KWOWZ"] ?? "";
        $arPushs["FLOOR"]      = $arPushs["_FTRIDL"]  ?? "";
        $arPushs["TOTAL_AREA"] = $arPushs["__173JA5"] ?? "";

        array_push($arElements, $arPushs);
    }
    return $arElements;
}


// --------------------- main code --------------------

$projId = isset($_GET['projId']) ? $_GET['projId'] : null;

$nbg = getNBG_inventory(date("Y-m-d"));

$resArray["products"] = getProducts($projId);
$resArray["nbg"]      = $nbg;

// ── Aggregate unique filter values ──
$phases         = [];
$blocks         = [];
$sectors        = [];
$apartmentTypes = [];
$statuses       = [];
$conditions     = [];

if (!empty($resArray["products"])) {
    foreach ($resArray["products"] as $product) {
        // blocks — original logic kept (only add when block is set)
        if (isset($product["_L24CUB"]) && $product["_L24CUB"] !== null && $product["_L24CUB"] !== '') {
            $blocks[] = $product["_L24CUB"];
        }

        // sectors — collect even when block may be empty
        if (isset($product["_3BU0JH"]) && $product["_3BU0JH"] !== null && $product["_3BU0JH"] !== '') {
            $sectors[] = $product["_3BU0JH"];
        }

        $apartmentTypes[] = $product["__X1GCRZ"];
        $statuses[]       = $product["_P64GYD"];
        $conditions[]     = $product["_H8WF0T"];
    }

    $apartmentTypes = array_values(array_unique($apartmentTypes));
    $statuses       = array_values(array_unique($statuses));
    $conditions     = array_values(array_unique($conditions));
    $sectors        = array_values(array_unique($sectors));

    // Natural sort blocks (handles "A1", "B2", "10", "2" etc.)
    $blocks = array_values(array_unique($blocks));
    natsort($blocks);
    $blocks = array_values($blocks);

    natsort($sectors);
    $sectors = array_values($sectors);
}

$resArray["apartmentTypes"] = $apartmentTypes;
$resArray["statuses"]       = $statuses;
$resArray["conditions"]     = $conditions;
$resArray["blocks"]         = $blocks;
$resArray["sectors"]        = $sectors;

// ── Property definitions (used by catalog.php to build propertyMap) ──
$resArray["properties"] = getIblockProperties(14);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArray, JSON_UNESCAPED_UNICODE);
?>