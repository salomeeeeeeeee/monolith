<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

CModule::IncludeModule("crm");

define('SOURCE_ID_VALUE', 'UC_61RG25');

$results = [];
$updated = 0;
$failed  = 0;

$dealRes = CCrmDeal::GetList(
    ["ID" => "ASC"],
    ["STAGE_ID" => "WON"],
    [],
    false,
    ["ID", "TITLE", "SOURCE_ID"]
);

while ($deal = $dealRes->Fetch()) {
    $dealId = intval($deal["ID"]);

    $Deal = new CCrmDeal(false);
    $ok = $Deal->Update($dealId, ["SOURCE_ID" => SOURCE_ID_VALUE]);

    if ($ok) {
        $updated++;
        $results[] = [
            "deal_id" => $dealId,
            "title"   => $deal["TITLE"],
            "result"  => "updated",
            "old_source_id" => $deal["SOURCE_ID"],
            "new_source_id" => SOURCE_ID_VALUE,
        ];
    } else {
        $failed++;
        $results[] = [
            "deal_id" => $dealId,
            "title"   => $deal["TITLE"],
            "result"  => "failed",
        ];
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    "summary" => ["updated" => $updated, "failed" => $failed, "total" => count($results)],
    "results" => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);