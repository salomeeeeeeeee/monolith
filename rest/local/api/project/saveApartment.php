<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
ob_end_clean();
$APPLICATION->SetTitle("Title");

CModule::IncludeModule("crm");

// =========================== FUNCTIONS ===========================
function printArr ($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

function getCIBlockElementsByID($ID) {
    $arSelect = Array();
    $res = CIBlockElement::GetList(Array(), array("ID"=>$ID), false, Array("nPageSize"=>50), $arSelect);
    if($ob = $res->GetNextElement()) {
        $arFilds = $ob->GetFields();
        $arProps = $ob->GetProperties();
        $arPushs = array();
        foreach($arFilds as $key => $arFild) $arPushs[$key] = $arFild;
        foreach($arProps as $key => $arProp) $arPushs[$key] = $arProp["VALUE"];
        $price = CPrice::GetBasePrice($arPushs["ID"]);
        $arPushs["PRICE"] = $price["PRICE"];

        return $arPushs;
    }
}

function getDealInfoByID ($dealID) {
    $res = CCrmDeal::GetList(array("ID" => "ASC"), array("ID" => $dealID), array());

    $resArr = array();
    if($arDeal = $res->Fetch()){
        return $arDeal;
    }
    return false;
}


function getDealFields($fieldName,$fieldValue){
    $option=array();
    $rsUField = CUserFieldEnum::GetList(array(), array("USER_FIELD_NAME" => $fieldName));
    while($arUField = $rsUField->GetNext())   {
        if($arUField["VALUE"] == $fieldValue){
            return $arUField["ID"];
        }else{
            return 0;
        }
    }

}

function sendNotificationToQueue ($dealID,$element,$notification) {
    $queue      =   str_replace("|$dealID!","",$element["BOOKING_VGNO4X"]);
    $queue      =   str_replace("!","",$queue);
    $arrQueueDealIDs   =   explode("|",$queue);

    foreach ($arrQueueDealIDs as $QueuedealID) {
        if($QueuedealID>0 && is_numeric($QueuedealID)) {
            $dealData = getDealInfoByID($QueuedealID);
            $responsible = $dealData["ASSIGNED_BY_ID"];
            $arFields = array(
                "MESSAGE_TYPE" => "S", # P - private chat, G - group chat, S - notification
                "TO_USER_ID" => 1,
                "FROM_USER_ID" => 1,
                "MESSAGE" => $notification."$QueuedealID/",
                "AUTHOR_ID" => 1,
                "EMAIL_TEMPLATE" => "some",

                "NOTIFY_TYPE" => 4,  # 1 - confirm, 2 - notify single from, 4 - notify single
                "NOTIFY_MODULE" => "main", # module id sender (ex: xmpp, main, etc)
                "NOTIFY_EVENT" => "IM_GROUP_INVITE", # module event id for search (ex, IM_GROUP_INVITE)
                "NOTIFY_TITLE" => "title to send email", # notify title to send email
            );
            CModule::IncludeModule('im');
            CIMMessenger::Add($arFields);
        }
    }
}

function DATE_Sityvierad($date){
    $date=explode("/",$date);
    switch ($date[1]){
        case "01" : $date[1] = "იანვარს"; break;
        case "02" : $date[1] = "თებერვალს"; break;
        case "03" : $date[1] = "მარტს";break;
        case "04" : $date[1] = "აპრილს";break;
        case "05" : $date[1] = "მაისს";break;
        case "06" : $date[1] = "ივნისს";break;
        case "07" : $date[1] = "ივლისს";break;
        case "08" : $date[1] = "აგვისტოს";break;
        case "09" : $date[1] = "სექტემბერს";break;
        case "10" : $date[1] = "ოქტომბერს";break;
        case "11" : $date[1] = "ნოემბერს";break;
        case "12" : $date[1] = "დეკემბერს";break;
    }
    $date= $date[2]."  წლის  ". $date[0]."  ". $date[1];
    return $date;

}

function DATE_SityvieradNewFormatEng($date){
    $date = explode("/", $date);
    switch ($date[1]){
        case "01" : $date[1] = "January"; break;
        case "02" : $date[1] = "February"; break;
        case "03" : $date[1] = "March"; break;
        case "04" : $date[1] = "April"; break;
        case "05" : $date[1] = "May"; break;
        case "06" : $date[1] = "June"; break;
        case "07" : $date[1] = "July"; break;
        case "08" : $date[1] = "August"; break;
        case "09" : $date[1] = "September"; break;
        case "10" : $date[1] = "October"; break;
        case "11" : $date[1] = "November"; break;
        case "12" : $date[1] = "December"; break;
    }
    $date = $date[0] . " " . $date[1] . ", " . $date[2];
    return $date;
}

function DATE_SityvieradNewFormat($date){
    $date=explode("/",$date);
    switch ($date[1]){
        case "01": $date[1] = "Январь"; break;
        case "02": $date[1] = "Февраль"; break;
        case "03": $date[1] = "Март"; break;
        case "04": $date[1] = "Апрель"; break;
        case "05": $date[1] = "Май"; break;
        case "06": $date[1] = "Июнь"; break;
        case "07": $date[1] = "Июль"; break;
        case "08": $date[1] = "Август"; break;
        case "09": $date[1] = "Сентябрь"; break;
        case "10": $date[1] = "Октябрь"; break;
        case "11": $date[1] = "Ноябрь"; break;
        case "12": $date[1] = "Декабрь"; break;
    }
    $date= $date[0]." ". $date[1].", ". $date[2];
    return $date;

}




// ============================ MAIN CODE ============================

$deal_id = $_GET["deal_id"];
$productIds = explode(",", $_GET["productIds"]);
$productIds = array_filter($productIds, fn($x) => is_numeric($x));

$resArray = [];
$arrForAdd = [];

if ($deal_id) {

    // initialize values for adding info to the deal
    $KVM_PRICE = "";
    $project = "";
    $block = "";
    $PRODUCT_TYPE = "";
    $sadarbazo = "";
    $prodFLOOR = "";
    $prodNumber = "";
    $prodTOTAL_AREA = "";
    $LIVING_SPACE = "";
    $sawyisiGirebuleba = "";
    $phase = "";
    $productIdsForAdd = "";
    $summerspace = "";
    $bedrooms = "";
    $rooms = "";


    // end initialization

    $rows = [];
    foreach ($productIds as $pid) {

        $productData = getCIBlockElementsByID($pid);

        if (!$productData) {
            $resArray["status"] = 400;
            $resArray["error"] = "ბინა ვერ მოიძებნა";
            echo json_encode($resArray, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $price = floatval($productData["PRICE"]);

        // prepare product infos to add
        $rows[] = [
            "PRODUCT_ID" => $pid,
            "PRICE" => $price,
            "QUANTITY" => 1,
        ];

        // update deal info
        $KVM_PRICE ? $KVM_PRICE .= " /" . $productData["__6ZWTER"] : $KVM_PRICE = $productData["__6ZWTER"];
        $project ? $project .= " /" . $productData["__VO9RG4"] : $project = $productData["__VO9RG4"];
        $block ? $block .= " /" . $productData["_L24CUB"] : $block = $productData["_L24CUB"];
        $summerspace ? $summerspace .= " /" . $productData["__BL1XXK"] : $summerspace = $productData["__BL1XXK"];
        $bedrooms ? $bedrooms .= " /" . $productData["__KYRP1L"] : $bedrooms = $productData["__KYRP1L"];
        $bathrooms ? $bathrooms .= " /" . $productData["__9H8XS9"] : $bathrooms = $productData["__9H8XS9"];
        $rooms ? $rooms .= " /" . $productData["__WX6YWZ"] : $rooms = $productData["__WX6YWZ"];

        $PRODUCT_TYPE ? $PRODUCT_TYPE .= " /" . $productData["__X1GCRZ"] : $PRODUCT_TYPE = $productData["__X1GCRZ"];
        $sadarbazo ? $sadarbazo .= " /" . $productData["_D599QA"] : $sadarbazo = $productData["_D599QA"];
        $prodFLOOR ? $prodFLOOR .= " /" . $productData["_FTRIDL"] : $prodFLOOR = $productData["_FTRIDL"];
        $prodNumber ? $prodNumber .= " /" . $productData["__6KWOWZ"] : $prodNumber = $productData["__6KWOWZ"];
        $prodTOTAL_AREA ? $prodTOTAL_AREA .= " /" . $productData["__173JA5"] : $prodTOTAL_AREA = $productData["__173JA5"];
        $LIVING_SPACE ? $LIVING_SPACE .= " /" . $productData["__US58ND"] : $LIVING_SPACE = $productData["__US58ND"];
        $sawyisiGirebuleba ? $sawyisiGirebuleba .= " /" . $productData["PRICE"] : $sawyisiGirebuleba = $productData["PRICE"];
        $phase ? $phase .= " /" . $productData["phase"] : $phase = $productData["phase"];
        $productIdsForAdd ? $productIdsForAdd .= " /" . $productData["ID"] : $productIdsForAdd = $productData["ID"];

    }
    
    $arrForAdd ["UF_CRM_1779277671391"] = $KVM_PRICE;         //კვ.მ ღირებულება
    $arrForAdd ["UF_CRM_1779277729207"] = $project;      //პროექტი
    $arrForAdd ["UF_CRM_1779277644355"] = $block;      //ბლოკი
    $arrForAdd ["UF_CRM_1779277898205"] = $PRODUCT_TYPE;         //ფართის ტიპი
    $arrForAdd ["UF_CRM_1779277754252"] = $sadarbazo;         // სადარბაზო
    $arrForAdd ["UF_CRM_1779277828822"]    = $prodFLOOR;         //სართული
    $arrForAdd ["UF_CRM_1779277613798"] = $prodNumber;      //ბინის №
    $arrForAdd ["UF_CRM_1779277886804"] = $prodTOTAL_AREA;      //საერთო ფართი მ²
    $arrForAdd ["UF_CRM_1779277919090"] = $LIVING_SPACE;      //საცხოვრებელი ფართი მ²
    $arrForAdd ["UF_CRM_1761658642424"] = $sawyisiGirebuleba; // საწყისი ფასი (იგივე რაც უბრალოდ ფასი)
    $arrForAdd ["UF_CRM_1761658662573"] = $KVM_PRICE;         //საწყისი კვ.მ ღირებულება (იგივე რაც კვ.მ ღირებულება)
    $arrForAdd ["UF_CRM_1764317005"] = $phase;                //  ფაზა
    $arrForAdd ["UF_CRM_1779277786379"] = $summerspace;                //  საზაფხულო ფართი
    $arrForAdd ["UF_CRM_1779277838333"] = $bedrooms;                //  საძინებლების რაოდ.

    $arrForAdd ["UF_CRM_1779277860291"] = $bathrooms;                //  სველი წერტ. რაოდ.
    $arrForAdd ["UF_CRM_1779277690404"] = $rooms;                //  otaxebis რაოდ.

    $arrForAdd ["PRODUCT_ID"] = $productIdsForAdd;           // პროდუქტების აიდიები

    $added = CCrmDeal::SaveProductRows($deal_id, $rows);
    if($added){
        $Deal = new CCrmDeal(false);
        $result = $Deal->Update($deal_id, $arrForAdd);
    } else {
        $resArray["status"] = 400;
        $resArray["error"] = "დაფიქსირდა შეცდომა";
    }

    $resArray["status"] = 200;
    $resArray["message"] = "მონაცემები შენახულია";

} else {
    $resArray["status"] = 400;
    $resArray["error"] = "დილი ვერ მოიძებნა";
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArray, JSON_UNESCAPED_UNICODE);