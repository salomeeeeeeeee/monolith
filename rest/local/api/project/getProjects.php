<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
ob_end_clean();
$APPLICATION->SetTitle("Title");
CModule::IncludeModule("main");

// ------------------------------FUNCTIONS---------------------------------
function printArr($arr) {
    echo "<pre>"; print_r($arr); echo "</pre>";
}

function getCompanyProjects($compId) {

    $arFilter = [
        "IBLOCK_ID" => 14,
        "SECTION_ID" => $compId,   // ✅ correct filter key
        "ACTIVE" => "Y",
    ];

    $arSelect = [
        "ID",
        "NAME",
        "DEPTH_LEVEL",
        "IBLOCK_SECTION_ID"
    ];

    $res = CIBlockSection::GetList(
        ["SORT" => "ASC"],
        $arFilter,
        false,
        $arSelect
    );

    $projects = [];
    while ($section = $res->GetNext()) {
        $arPushs = [];
        $arPushs["NAME"] = $section["NAME"];
        $arPushs["ID"] = $section["ID"];
        $projects[] = $arPushs;
    }

    return $projects;
}

// ===================================== MAIN CODE =====================================

$compId = isset($_GET['compId']) ? $_GET['compId'] : null;

$projects = getCompanyProjects($compId);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($projects, JSON_UNESCAPED_UNICODE);
?>