<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
$APPLICATION->SetTitle("Title");

function getPipelineById($categoryId) {
    if($categoryId == "0") return "გაყიდვები";
    else if($categoryId == "4") return "AFTER SALE";
    else if($categoryId == "5") return "Contracts";
    return "";
}

function getDealsByFilter($arFilter) {
    $arDeals = array();
    $res = CCrmDeal::GetListEx(
        array("ID" => "DESC"),
        $arFilter,
        false,
        array("nPageSize" => 10),
        array("ID", "TITLE", "CATEGORY_ID", "ASSIGNED_BY_ID", "ASSIGNED_BY_NAME", "ASSIGNED_BY_LAST_NAME", "DATE_CREATE")
    );
    while ($arDeal = $res->Fetch()) {
        $arDeal["CATEGORY_NAME"]    = getPipelineById($arDeal["CATEGORY_ID"]);
        $arDeal["RESPONSIBLE_NAME"] = $arDeal["ASSIGNED_BY_NAME"] . " " . $arDeal["ASSIGNED_BY_LAST_NAME"];
        $arDeal["DATE_CREATE"] = date("d/m/Y", MakeTimeStamp($arDeal["DATE_CREATE"]));
                array_push($arDeals, $arDeal);
    }
    return $arDeals;
}

function getLeadByFilter($arFilter) {
    $res = CCrmLead::GetListEx(
        array("ID" => "DESC"),
        $arFilter,
        false,
        array("nPageSize" => 1),
        array("DATE_CREATE")
    );
    if ($arLead = $res->Fetch()) {
        $arLead["RESPONSIBLE_NAME"] = $arLead["ASSIGNED_BY_NAME"] . " " . $arLead["ASSIGNED_BY_LAST_NAME"];
        $arLead["DATE_CREATE"] = date("d/m/Y", MakeTimeStamp($arLead["DATE_CREATE"]));
                return $arLead;
    }
    return false;
}

function checkDeals($mobileNumber, $dealId) {
    $mobileNumber = substr($mobileNumber, -9);

    $dbFieldMulti = \CCrmFieldMulti::GetList(array(), array(
        'ENTITY_ID' => 'CONTACT',
        'TYPE_ID'   => 'PHONE',
        "%VALUE"    => $mobileNumber
    ));

    $dealsId  = array($dealId);
    $dealsArr = array();

    while ($info = $dbFieldMulti->Fetch()) {
        if (!empty($info["ELEMENT_ID"])) {
            $arFilter = array(
                "!ID"              => $dealsId,
                "CONTACT_ID"       => $info["ELEMENT_ID"],
                "CATEGORY_ID"      => 0,
                "CHECK_PERMISSIONS" => "N",
            );

            $resDeals = getDealsByFilter($arFilter);

            foreach ($resDeals as $resDeal) {
                array_push($dealsId, $resDeal["ID"]);

                $thisArr = array(
                    "ID"               => $resDeal["ID"],
                    "TITLE"            => $resDeal["TITLE"],
                    "CATEGORY_NAME"    => $resDeal["CATEGORY_NAME"],
                    "RESPONSIBLE_NAME" => $resDeal["RESPONSIBLE_NAME"],
                    "PHONE"            => $info["VALUE"],
                    "DATE_CREATE"      => $resDeal["DATE_CREATE"],
                );

                array_push($dealsArr, $thisArr);
            }
        }
    }

    return $dealsArr;
}

function checkLeads($mobileNumber, $leadId) {
    $mobileNumber = substr($mobileNumber, -9);

    $dbFieldMulti = \CCrmFieldMulti::GetList(array(), array(
        'ENTITY_ID' => 'LEAD',
        'TYPE_ID'   => 'PHONE',
        "%VALUE"    => $mobileNumber
    ));

    $leadsArr = array();

    while ($info = $dbFieldMulti->Fetch()) {
        if (!empty($info["ELEMENT_ID"]) && $leadId != $info["ELEMENT_ID"]) {
            $arFilter = array(
                "ID"               => $info["ELEMENT_ID"],
                "CHECK_PERMISSIONS" => "N",
            );

            $resLead = getLeadByFilter($arFilter);

            if ($resLead["ID"]) {
                $thisArr = array(
                    "ID"               => $resLead["ID"],
                    "TITLE"            => $resLead["TITLE"],
                    "CATEGORY_NAME"    => "LEAD",
                    "RESPONSIBLE_NAME" => $resLead["RESPONSIBLE_NAME"],
                    "PHONE"            => $info["VALUE"],
                    "DATE_CREATE"      => $resLead["DATE_CREATE"],
                );

                array_push($leadsArr, $thisArr);
            }
        }
    }

    return $leadsArr;
}

$dealId = $_GET["dealId"];
$phone  = $_GET["phone"];
$type   = $_GET["type"];

$resArray = array();

if (strlen($phone) == 9) {
    $dealId = "";
    $leadId = "";

    if ($type == "deal") $dealId = $id;
    if ($type == "lead") $leadId = $id;

    $resDeals = checkDeals($phone, $dealId);
    $resLeads = checkLeads($phone, $leadId);

    $res = array(
        "DEALS" => $resDeals,
        "LEADS" => $resLeads,
    );

    $resArray["status"]  = 200;
    $resArray["message"] = "OK";
    $resArray["res"]     = $res;
} else {
    $resArray["status"]  = 500;
    $resArray["message"] = "Bad Request!";
}

ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArray, JSON_UNESCAPED_UNICODE);