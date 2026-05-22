<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
ob_end_clean();
$APPLICATION->SetTitle("Title");
CModule::IncludeModule("main");

// --------------------- functions --------------------
function printArr ($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

function getNBG_inventory($date){

    $url="https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=USD&date={$date}";
    
    $seb = file_get_contents($url);
    
    $seb = json_decode($seb);
    
    $seb_currency=$seb[0]->currencies[0]->rate;
    
    return $seb_currency;
}


function getUserName ($id) {
    $res = CUser::GetByID($id)->Fetch();

    return $res["NAME"]." ".$res["LAST_NAME"];
}

function getContactInfo($contactId) {
    $arContact = array();
    $res = CCrmContact::GetList(array("ID" => "ASC"), array("ID" => $contactId), array());
    if($arContact = $res->Fetch()){
        $PHONE=\CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT','TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'MOBILE|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $MAIL=\CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT','TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'HOME|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $arContact["PHONE"] = $PHONE["VALUE"];
        $arContact["EMAIL"] = $MAIL["VALUE"];
        return $arContact;
    }
    return $arContact;
}

function getProducts($projId = null, $blockId = null) {
    $arFilter = array(
            "IBLOCK_ID" => 14
    );

    if (!is_null($projId) && $projId !== '') {
        $arFilter["IBLOCK_SECTION_ID"] = $projId;
    }

    $arSelect = array("ID", "IBLOCK_ID","IBLOCK_SECTION_ID","DETAIL_PICTURE", "PROPERTY_*","STAGE_ID");
    $sort= array();
    $count = 99999;
    $nbg = getNBG_inventory(date("Y-m-d"));
    $arElements = array();
    $baseUrl = "https://" . preg_replace('/:\d+$/', '', $_SERVER["HTTP_HOST"]);
    $res = CIBlockElement::GetList($sort, $arFilter, false, array("nPageSize" => $count), $arSelect);
    while ($ob = $res->GetNextElement()) {
        $arFilds = $ob->GetFields();
        $arProps = $ob->GetProperties();
        $arPushs = array();
        foreach ($arFilds as $key => $arFild) $arPushs[$key] = $arFild;
        foreach ($arProps as $key => $arProp){
            $fieldId = $arProp["CODE"];
            $arPushs[$fieldId] = $arProp["VALUE"];
        }

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

        // ── existing images ──
        $image = CFile::GetPath($arPushs['erteulis_gegma']);
        if ($image) {
            $image = $baseUrl . $image;
        } else {
            $image = $baseUrl . "/catalog/projects/resources/noimage.jpg";
        }
        $arPushs['image'] = $image;

        $image2 = CFile::GetPath($arPushs['binis_gegmareba']);
        if ($image2) {
            $image2 = $baseUrl . $image2;
        } else {
            $image2 = $baseUrl . "/catalog/projects/resources/noimage.jpg";
        }
        $arPushs['image2'] = $image2;

        $image3 = CFile::GetPath($arPushs['render_3D']);
        if ($image3) {
            $image3 = $baseUrl . $image3;
        } else {
            $image3 = $baseUrl . "/catalog/projects/resources/noimage.jpg";
        }
        $arPushs['image3'] = $image3;

        $image4 = CFile::GetPath($arPushs['sartulis2D']);
        if ($image4) {
            $image4 = $baseUrl . $image4;
        } else {
            $image4 = $baseUrl . "/catalog/projects/resources/noimage.jpg";
        }
        $arPushs['image4'] = $image4;

        $image5 = CFile::GetPath($arPushs['binisNaxazi2D']);
        if ($image5) {
            $image5 = $baseUrl . $image5;
        } else {
            $image5 = $baseUrl . "/catalog/projects/resources/noimage.jpg";
        }
        $arPushs['image5'] = $image5;

        // ── სურათები block — resolve file IDs to full URLs ──
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
            if ($resolvedPath) {
                $arPushs[$fieldCode] = $baseUrl . $resolvedPath;
            } else {
                $arPushs[$fieldCode] = ""; // empty → JS gallery tile will auto-hide
            }
        }

        $price = CPrice::GetBasePrice($arPushs["ID"]);
        $arPushs["PRICE"] = isset($price["PRICE"]) ? round($price["PRICE"], 2) : 0;
        $arPushs['PRICE_GEL'] = round($arPushs["PRICE"] * $nbg,2);

        array_push($arElements, $arPushs);
    }
    return $arElements;
}


// --------------------- main code --------------------

$projId = isset($_GET['projId']) ? $_GET['projId'] : null;

$resArray["products"] = getProducts($projId);

$phases = [];
$blocks = [];
$apartmentTypes = [];
$statuses = [];
$conditions = [];
if (!empty($resArray["products"])) {
    foreach ($resArray["products"] as $product) {
        if (isset($product["_L24CUB"]) && $product["_L24CUB"] !== null && $product["_L24CUB"] !== '') {
            $apartmentTypes[] = $product["__X1GCRZ"];
            $statuses[] = $product["_P64GYD"];
            $conditions[] = $product["_H8WF0T"];
            $blocks[] = $product["_L24CUB"];
        }
    }
    $apartmentTypes = array_values(array_unique($apartmentTypes)); 
    $statuses       = array_values(array_unique($statuses)); 
    $conditions     = array_values(array_unique($conditions)); 
    $blocks         = array_values(array_unique($blocks)); 
    
    natsort($blocks);
    $blocks = array_values($blocks);
}
$resArray["apartmentTypes"] = $apartmentTypes;
$resArray["statuses"]       = $statuses;
$resArray["conditions"]     = $conditions;
$resArray["blocks"]         = $blocks;

header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArray, JSON_UNESCAPED_UNICODE);
?>