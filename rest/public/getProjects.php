<?php
define("STOP_STATISTICS",      true);
define("NO_KEEP_STATISTIC",    "Y");
define("NO_AGENT_STATISTIC",   "Y");
define("NO_AGENT_CHECK",       true);
define("DisableEventsCheck",   true);
define("NOT_CHECK_PERMISSIONS", true);

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

CModule::IncludeModule('iblock');

global $USER;

$authorizedUser = false;
if (!$USER->IsAuthorized()) {
    $USER->Authorize(1);
    $authorizedUser = true;
}

function getProjects(): array
{
    $projects = [];

    $res = CIBlockSection::GetList(
        ["SORT" => "ASC"],
        [
            "IBLOCK_ID"          => 14,
            "IBLOCK_SECTION_ID"  => 10,
            "DEPTH_LEVEL"        => 2,
            "ACTIVE"             => "Y",
        ],
        false,
        ["ID", "NAME"]
    );

    while ($section = $res->GetNext()) {
        $projects[] = [
            "ID"   => $section["ID"],
            "NAME" => $section["NAME"],
        ];
    }

    usort($projects, fn($a, $b) => strnatcasecmp($a["NAME"], $b["NAME"]));

    return $projects;
}

$resProject = getProjects();

if (count($resProject) > 0) {
    $resArray = [
        "status"   => 200,
        "message"  => "OK",
        "projects" => $resProject,
    ];
} else {
    $resArray = [
        "status"  => 404,
        "message" => "Project Not Found!",
        "result"  => $resProject,
    ];
}

if ($authorizedUser) {
    $USER->Logout();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArray, JSON_UNESCAPED_UNICODE);
