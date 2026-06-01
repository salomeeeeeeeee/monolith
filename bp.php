<?
//=== functions

if (!function_exists('getCIBlockElementByID')) {
    /**
     * Returns a single flat property array for the given element ID,
     * or null if not found.
     */
    function getCIBlockElementByID($ID)
    {
        $arSelect = array("ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_*");
        $res = CIBlockElement::GetList(array(), array("ID" => $ID), false, array("nPageSize" => 2), $arSelect);
        if ($ob = $res->GetNextElement()) {
            $arFields = $ob->GetFields();
            $arProps  = $ob->GetProperties();
            $arResult = array();
            foreach ($arFields as $key => $val) $arResult[$key] = $val;
            foreach ($arProps  as $key => $prop) $arResult[$key] = $prop["VALUE"];
            return $arResult;   // single element, not a nested array
        }
        return null;
    }
}

if (!function_exists('getCIBlockElementsByFilter')) {
    function getCIBlockElementsByFilter($arrFilter)
    {
        $arElements = array();
        $res = CIBlockElement::GetList(array("ID"=>"DESC"), $arrFilter, false, Array("nPageSize" => 1), Array("ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_*"));
        while ($ob = $res->GetNextElement()) {
            $arFilds = $ob->GetFields();
            $arProps = $ob->GetProperties();
            $arPushs = array();
            foreach ($arFilds as $key => $arFild) $arPushs[$key] = $arFild;
            foreach ($arProps as $key => $arProp) $arPushs[$key] = $arProp["VALUE"];
            array_push($arElements, $arPushs);
        }
        return $arElements;
    }
}
 
if (!function_exists('getStageType')) {
    function getStageType($stage_id)
    {
        if ($stage_id) {
            $stageGroupElement = getCIBlockElementsByFilter(array("NAME" => $stage_id, "IBLOCK_ID" => 16));
            if (count($stageGroupElement)) {
                return $stageGroupElement[0]["STAGE_GROUP"];
            }
        }
        return 0;
    }
}
 
if (!function_exists('alreadyInQueue')) {
    function alreadyInQueue($queueString, $dealID)
    {
        $queue = explode("|", "$queueString");
        return in_array($dealID, $queue);
    }
}
 
if (!function_exists('firstInQueue')) {
    function firstInQueue($queueString, $dealID)
    {
        $queue = explode("|", "$queueString");
        // Filter out empty strings left by leading "|" separator
        $queue = array_values(array_filter($queue, function($v) { return $v !== ""; }));
        return isset($queue[0]) && $queue[0] == $dealID;
    }
}

if (!function_exists('sendNotificationToQueue')) {
    function sendNotificationToQueue ($queue,$notification) {
        $queueAr = explode("|",$queue);

        $count = 1;
        foreach ($queueAr as $QueuedealID) {
            if($QueuedealID>0 && is_numeric($QueuedealID)) {
                $dealData = getDealInfoByID($QueuedealID);
                $responsible = $dealData["ASSIGNED_BY_ID"];

                $arFields = array(
                    "MESSAGE_TYPE" => "S", # P - private chat, G - group chat, S - notification
                    "TO_USER_ID" => $responsible,
                    "FROM_USER_ID" => 1,
                    "MESSAGE" => $notification." თქვენ ხართ რიგში N$count \n <a href='" . $_SERVER['HTTP_HOST'] ."/crm/deal/details/$QueuedealID/'>".$dealData["TITLE"] ."</a>",
                    "AUTHOR_ID" => 1,
                    "EMAIL_TEMPLATE" => "some",

                    "NOTIFY_TYPE" => 4,  # 1 - confirm, 2 - notify single from, 4 - notify single
                    "NOTIFY_MODULE" => "main", # module id sender (ex: xmpp, main, etc)
                    "NOTIFY_EVENT" => "IM_GROUP_INVITE", # module event id for search (ex, IM_GROUP_INVITE)
                    "NOTIFY_TITLE" => "title to send email", # notify title to send email
                );
                CModule::IncludeModule('im');
                CIMMessenger::Add($arFields);

                $count++;
            }
        }
    }
}

if (!function_exists('sendNotificationToResponsible')) {
    function sendNotificationToResponsible ($dealID,$notification) {

        if($dealID>0 && is_numeric($dealID)) {
            $dealData = getDealInfoByID($dealID);
            $responsible = $dealData["ASSIGNED_BY_ID"];

            $arFields = array(
                "MESSAGE_TYPE" => "S", # P - private chat, G - group chat, S - notification
                "TO_USER_ID" => 1,
                "FROM_USER_ID" => 1,
                "MESSAGE" => $notification." \n <a href='" . $_SERVER['HTTP_HOST'] ."/crm/deal/details/$dealID/'>".$dealData["TITLE"] ."</a>",
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
 
 
if (!function_exists('new_stage')) {
    function new_stage($dealID, $arProducts, $deal)
    {
        if (!count($arProducts)) return "";
 
        $elementsForUpdate = array();
 
        foreach ($arProducts as $product) {
            $element = getCIBlockElementByID($product["PRODUCT_ID"]);
            if (!$element) continue;
 
            // Add to queue if not already present
            if (!alreadyInQueue($element["QUEUE"], $dealID)) {
                $element["QUEUE"] .= "|$dealID";
            }
 
            if ($element["OWNER_DEAL"] == $dealID) {
                $notification = $element["PRODUCT_TYPE"] . " N" . $element["Number"] . " გათავისუფლდა ";
                sendNotificationToQueue($element["QUEUE"], $notification);
                sendNotificationToResponsible($dealID, $notification);
 
                // Remove this deal from the queue since it's being released
                $element["QUEUE"] = str_replace("|$dealID", "", $element["QUEUE"]);
 
                $element["_P64GYD"]                  = "თავისუფალი";
                $element["DEAL_RESPONSIBLE"]        = "";
                $element["OWNER_DEAL"]              = "";
                $element["OWNER_PERSONAL_CONTACT"]  = "";
            } 
            // else {
            //     if ($element["_P64GYD"] == "თავისუფალი" && $element["QUEUE"]) {
            //         $element["_P64GYD"]           = "ჯავშნის რიგში";
            //         $element["DEAL_RESPONSIBLE"] = $deal["ASSIGNED_BY_ID"];
            //     }
            // }
 
            $elementsForUpdate[$product["PRODUCT_ID"]] = $element;
        }
 
        return updateProdElement($elementsForUpdate);
    }
}
 
if (!function_exists('reservation')) {
    function reservation($dealID, $arProducts, $deal)
    {
        if (!count($arProducts)) {
            $logText = changeDealStageToNew($deal, array());
            $logText = "დილზე პროდუქტი არ არის მიბმული";
            sendNotificationToResponsible($dealID, $logText);
            return $logText;
        }
 
        $sendNotification  = false;
        $errors            = array();
        $elementsForUpdate = array();
 
        foreach ($arProducts as $product) {
            $element = getCIBlockElementByID($product["PRODUCT_ID"]);
            if (!$element) continue;
 
            if ($element) {
                if ($element["_P64GYD"] != "დაჯავშნილი") $sendNotification = true;
                $element = preparationProductForReservation($element, $deal);
            }
 
            $elementsForUpdate[$product["PRODUCT_ID"]] = $element;
        }
 
        if (empty($errors)) {
            $logText = updateProdElement($elementsForUpdate) . "დაიჯავშნა";
            if ($sendNotification) {
                sendNotificationToResponsible($dealID, $logText);
            } else {
                $logText = "";
            }
        } else {
            $logText = changeDealStageToNew($deal, $errors);
            updateQueue($arProducts, $dealID);
            sendNotificationToResponsible($dealID, $logText);
        }
 
        return $logText;
    }
}
 
if (!function_exists('sold')) {
    function sold($dealID, $arProducts, $deal)
    {
        if (!count($arProducts)) {
            $logText = changeDealStageToNew($deal, array());
            $logText = "დილზე პროდუქტი არ არის მიბმული";
            sendNotificationToResponsible($dealID, $logText);
            return $logText;
        }
 
        $sendNotification  = false;
        $errors            = array();
        $elementsForUpdate = array();
 
        foreach ($arProducts as $product) {
            $element = getCIBlockElementByID($product["PRODUCT_ID"]);
            if (!$element) continue;
 
            if ($element["OWNER_DEAL"] == $dealID) {
                if ($element["_P64GYD"] != "გაყიდული") $sendNotification = true;
                $element = preparationProductForSale($element, $deal);
            } elseif ($element["_P64GYD"] == "თავისუფალი" || ($element["_P64GYD"] == "ჯავშნის რიგში" && firstInQueue($element["QUEUE"], $dealID))) {
                $element = preparationProductForSale($element, $deal);
                $sendNotification = true;
            } else {
                $errors[] = $element["PRODUCT_TYPE"] . " N" . $element["Number"] . " ProdID " . $element["ID"] . " არ არის თავისუფალი";
            }
 
            $elementsForUpdate[$product["PRODUCT_ID"]] = $element;
        }
 
        if (empty($errors)) {
            $logText = updateProdElement($elementsForUpdate) . "გაიყიდა";
            if ($sendNotification) sendNotificationToResponsible($dealID, $logText);
        } else {
            $logText = changeDealStageToNew($deal, $errors);
            updateQueue($arProducts, $dealID);
            sendNotificationToResponsible($dealID, $logText);
        }
 
        return $logText;
    }
}
 
if (!function_exists('junk')) {
    function junk($dealID, $arProducts)
    {
        if (!count($arProducts)) return "";
 
        $elementsForUpdate = array();
        $needNotification  = false;
 
        foreach ($arProducts as $product) {
            $element = getCIBlockElementByID($product["PRODUCT_ID"]);
            if (!$element) continue;
 
            $element["QUEUE"] = str_replace("|$dealID", "", $element["QUEUE"]);
 
            if ($element["OWNER_DEAL"] == $dealID) {
                $notification = $element["PRODUCT_TYPE"] . " N" . $element["Number"] . " გათავისუფლდა ";
                sendNotificationToQueue($element["QUEUE"], $notification);
                sendNotificationToResponsible($dealID, $notification);
 
                $element["_P64GYD"]                  = $element["QUEUE"] ? "ჯავშნის რიგში" : "თავისუფალი";
                $element["DEAL_RESPONSIBLE"]        = "";
                $element["OWNER_DEAL"]              = "";
                $element["OWNER_PERSONAL_CONTACT"]  = "";
                $needNotification = true;
 
                deleteProdFromDeal($dealID);
            } else {
                if ($element["_P64GYD"] == "ჯავშნის რიგში" && !$element["QUEUE"]) {
                    $element["_P64GYD"] = "თავისუფალი";
                    $needNotification  = true;
                }
            }
 
            $elementsForUpdate[$product["PRODUCT_ID"]] = $element;
        }
 
        $logText = updateProdElement($elementsForUpdate) . "გათავისუფლდა";
        return $needNotification ? $logText : false;
    }
}
 
if (!function_exists('deleteProdFromDeal')) {
    function deleteProdFromDeal($dealID)
    {
        // Clear product rows
        CCrmDeal::SaveProductRows($dealID, array());
 
        // Clear all product-related custom fields on the deal
        $arrForAdd = array(
            "UF_CRM_1761658503260" => '',   // კვ.მ ღირებულება
            "UF_CRM_1761658516561" => '',   // პროექტი
            "UF_CRM_1762948106980" => '',   // ბლოკი
            "UF_CRM_1761658532158" => '',   // ფართის ტიპი
            "UF_CRM_1762867479699" => '',   // სადარბაზო
            "UF_CRM_1761658577987" => '',   // სართული
            "UF_CRM_1761658559005" => '',   // ბინის №
            "UF_CRM_1761658608306" => '',   // საერთო ფართი მ²
            "UF_CRM_1761658765237" => '',   // საცხოვრებელი ფართი მ²
            "UF_CRM_1761658642424" => '',   // საწყისი ფასი
            "UF_CRM_1761658662573" => '',   // საწყისი კვ.მ ღირებულება
            "UF_CRM_1764317005"    => '',   // ფაზა
            "UF_CRM_1769416547"    => '',
        );
 
        $Deal = new CCrmDeal();
        $Deal->Update($dealID, $arrForAdd);
    }
}
 
if (!function_exists('preparationProductForSale')) {
    function preparationProductForSale($element, $deal)
    {
        $dealID = $deal["ID"];
        $element["_P64GYD"]                  = "გაყიდული";
        $element["OWNER_DEAL"]              = $dealID;
        $element["DEAL_RESPONSIBLE"]        = $deal["ASSIGNED_BY_ID"];
        $element["OWNER_PERSONAL_CONTACT"]  = $deal["CONTACT_ID"];
        $element["QUEUE"]                   = str_replace("|$dealID", "", $element["QUEUE"]);
        return $element;
    }
}
 
if (!function_exists('preparationProductForReservation')) {
    function preparationProductForReservation($element, $deal)
    {
        $dealID = $deal["ID"];
        $element["_P64GYD"]                  = "დაჯავშნილი";
        $element["OWNER_DEAL"]              = $dealID;
        $element["DEAL_RESPONSIBLE"]        = $deal["ASSIGNED_BY_ID"];
        $element["OWNER_PERSONAL_CONTACT"]  = $deal["CONTACT_ID"];
        return $element;
    }
}
 
if (!function_exists('updateProdElement')) {
    function updateProdElement($elements)
    {
        $count   = 1;
        $logText = "";
        foreach ($elements as $element) {
            if (!$element) continue;
            $el = new CIBlockElement;
            $arLoadProductArray = array(
                "PROPERTY_VALUES" => $element,
                "NAME"            => $element["NAME"],
                "ACTIVE"          => "Y",
            );
            $res = $el->Update($element["ID"], $arLoadProductArray);
            if ($res) {
                $logText .= "$count)" . $element["PRODUCT_TYPE"] . " N" . $element["Number"] . " ProdID " . $element["ID"] . "\n";
                $count++;
            }
        }
        return $logText;
    }
}
 
if (!function_exists('changeDealStageToNew')) {
    function changeDealStageToNew($deal, $errors)
    {
        $logText   = "";
        $arrForAdd = array(
            "STAGE_ID" => $deal["UF_CRM_1695034234043"] ?: "FINAL_INVOICE",
        );
        $Deal = new CCrmDeal();
        $Deal->Update($deal["ID"], $arrForAdd);
        foreach ($errors as $i => $error) {
            $logText .= ($i + 1) . ") " . $error . "\n";
        }
        return $logText;
    }
}
 
if (!function_exists('updateQueue')) {
    function updateQueue($arProducts, $dealID)
    {
        $elementsForUpdate = array();
        foreach ($arProducts as $product) {
            $element = getCIBlockElementByID($product["PRODUCT_ID"]);
            if (!$element) continue;
            if (!alreadyInQueue($element["QUEUE"], $dealID)) {
                $element["QUEUE"] .= "|$dealID";
            }
            $elementsForUpdate[$product["PRODUCT_ID"]] = $element;
        }
        updateProdElement($elementsForUpdate);
    }
}

if (!function_exists('getDealInfoByID')) {
    function getDealInfoByID ($dealID) {
        $res = CCrmDeal::GetList(array("ID" => "ASC"), array("ID" => $dealID), array());

        $resArr = array();
        if($arDeal = $res->Fetch()){
            return $arDeal;
        }
        return false;
    }
}
 
//=== Main execution
 
// Read deal ID from workflow context (not hardcoded)
$root   = $this->GetRootActivity();
$dealID = intval($root->GetVariable("DEAL_ID"));

// $dealID = 13;
 
$deal       = getDealInfoByID($dealID);
// printArr($deal);
$logText    = "";
$allocation = 0;


$arProducts = CCrmDeal::LoadProductRows($dealID);
// printArr($arProducts);

if ($deal["CLOSED"] == "Y") {
    if ($deal["STAGE_ID"] == "WON") {
        $logText = sold($dealID, $arProducts, $deal);
    } else {
        $logText = junk($dealID, $arProducts);
    }
} else {
    $stage_group = getStageType($deal["STAGE_ID"]);
    // $this->SetVariable("stageGroup", $stage_group);
    if ($stage_group == "new") {
        $logText = new_stage($dealID, $arProducts, $deal);
        // if ($deal["STAGE_ID"] == "FINAL_INVOICE") {
        // } else {
        //     $logText = junk($dealID, $arProducts);
        // }
    } elseif ($stage_group == "Reservation") {
        $logText = reservation($dealID, $arProducts, $deal);
    } elseif ($stage_group == "Sold") {
        $logText = sold($dealID, $arProducts, $deal);
    } elseif ($stage_group == "junk") {
        $logText = junk($dealID, $arProducts);
    } else {
        $logText = new_stage($dealID, $arProducts, $deal);
    }
}
 
$this->SetVariable("log", $logText, JSON_UNESCAPED_UNICODE);
$this->SetVariable("allocation", $allocation);