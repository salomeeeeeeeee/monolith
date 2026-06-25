<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");

CModule::IncludeModule("crm");
CModule::IncludeModule("iblock");

define('IBLOCK_ID',       14);
define('DEAL_BLOCK',      'UF_CRM_1661249856017');
define('DEAL_PROJECT',    'UF_CRM_1781705760942');
define('DEAL_FLOOR',      'UF_CRM_1781705783644');
define('DEAL_APT_NUMBER', 'UF_CRM_1781705822863');

define('PROP_BLOCK',      '_L24CUB');
define('PROP_PROJECT',    '__VO9RG4');
define('PROP_FLOOR',      '_FTRIDL');
define('PROP_NUMBER',     '__6KWOWZ');


$mode = "run";


function getCIBlockElementsByFilter(array $filter): ?array {
    $res = CIBlockElement::GetList(
        [],
        $filter,
        false,
        ["nPageSize" => 1],
        ["ID", "NAME", "IBLOCK_ID", "IBLOCK_SECTION_ID", "PROPERTY_*"]
    );
    if (!($ob = $res->GetNextElement())) return null;
    $fields = $ob->GetFields();
    $props  = $ob->GetProperties();
    $out    = $fields;
    foreach ($props as $code => $prop) {
        $out[$code] = is_array($prop["VALUE"]) ? implode(", ", $prop["VALUE"]) : $prop["VALUE"];
    }
    $price = CPrice::GetBasePrice($out["ID"]);
    $out["PRICE"] = $price["PRICE"] ?? 0;
    return $out;
}

function linkProductToDeal(int $dealId, array $productData): array {
    $rows = [[
        "PRODUCT_ID" => $productData["ID"],
        "PRICE"      => floatval($productData["PRICE"]),
        "QUANTITY"   => 1,
    ]];

    $saved = CCrmDeal::SaveProductRows($dealId, $rows);
    if (!$saved) {
        return ["status" => 400, "error" => "SaveProductRows failed for deal {$dealId}"];
    }

    $dealFields = [
        "UF_CRM_1779277671391" => $productData["__6ZWTER"]  ?? "",
        "UF_CRM_1779277729207" => $productData["__VO9RG4"]  ?? "",
        "UF_CRM_1779277644355" => $productData["_L24CUB"]   ?? "",
        "UF_CRM_1779277898205" => $productData["__X1GCRZ"]  ?? "",
        "UF_CRM_1779277754252" => $productData["_D599QA"]   ?? "",
        "UF_CRM_1779277828822" => $productData["_FTRIDL"]   ?? "",
        "UF_CRM_1779277613798" => $productData["__6KWOWZ"]  ?? "",
        "UF_CRM_1779277886804" => $productData["__173JA5"]  ?? "",
        "UF_CRM_1779277919090" => $productData["__US58ND"]  ?? "",
        "UF_CRM_1761658642424" => $productData["PRICE"]     ?? "",
        "UF_CRM_1761658662573" => $productData["__6ZWTER"]  ?? "",
        "UF_CRM_1779277786379" => $productData["__BL1XXK"]  ?? "",
        "UF_CRM_1779277838333" => $productData["__KYRP1L"]  ?? "",
        "UF_CRM_1779277860291" => $productData["__9H8XS9"]  ?? "",
        "UF_CRM_1779277690404" => $productData["__WX6YWZ"]  ?? "",
        "PRODUCT_ID"           => $productData["ID"],
    ];

    $Deal = new CCrmDeal(false);
    $Deal->Update($dealId, $dealFields);

    $dealRes  = CCrmDeal::GetList(["ID" => "ASC"], ["ID" => $dealId], []);
    $dealData = $dealRes->Fetch();

    CIBlockElement::SetPropertyValuesEx($productData["ID"], IBLOCK_ID, [
        "OWNER_DEAL"             => $dealId,
        "OWNER_PERSONAL_CONTACT" => $dealData["CONTACT_ID"]     ?? "",
        "DEAL_RESPONSIBLE"       => $dealData["ASSIGNED_BY_ID"] ?? "",
    ]);

    return ["status" => 200, "product_id" => $productData["ID"], "deal_id" => $dealId];
}

// ── Main ──────────────────────────────────────────────────────────

$results = [];
$matched = 0;
$skipped = 0;
$failed  = 0;

$dealFilter = [
    "STAGE_ID"               => "WON",
    "!".DEAL_BLOCK     => false,
    "!".DEAL_APT_NUMBER => false,
    "!".DEAL_PROJECT   => false,
];

$dealRes = CCrmDeal::GetList(
    ["ID" => "ASC"],
    $dealFilter,
    [],
    false,
    [
        "ID", "TITLE", "STAGE_ID",
        DEAL_BLOCK, DEAL_PROJECT, DEAL_FLOOR, DEAL_APT_NUMBER,
    ]
);

while ($deal = $dealRes->Fetch()) {
    $dealId    = intval($deal["ID"]);
    $block     = trim($deal[DEAL_BLOCK]      ?? "");
    $project   = trim($deal[DEAL_PROJECT]    ?? "");
    $floor     = trim($deal[DEAL_FLOOR]      ?? "");
    $aptNumber = trim($deal[DEAL_APT_NUMBER] ?? "");

    // Block, apt number and project are all required
    if (!$aptNumber || !$block || !$project) {
        $results[] = [
            "deal_id" => $dealId,
            "title"   => $deal["TITLE"],
            "result"  => "skip",
            "reason"  => "missing block, apt number or project",
            "values"  => [
                "block"      => $block,
                "project"    => $project,
                "floor"      => $floor,
                "apt_number" => $aptNumber,
            ],
        ];
        $skipped++;
        continue;
    }

    // Skip if deal already has products linked
    $existingProds = CCrmDeal::LoadProductRows($dealId);
    if (!empty($existingProds)) {
        $results[] = [
            "deal_id" => $dealId,
            "title"   => $deal["TITLE"],
            "result"  => "skip",
            "reason"  => "already has " . count($existingProds) . " product(s)",
        ];
        $skipped++;
        continue;
    }

    // Build iBlock filter — block, project and apt number required, floor optional
    $iblockFilter = [
        "IBLOCK_ID"                 => IBLOCK_ID,
        "PROPERTY_" . PROP_BLOCK    => $block,
        "PROPERTY_" . PROP_PROJECT  => $project,
        "PROPERTY_" . PROP_NUMBER   => $aptNumber,
        "ACTIVE"                    => "Y",
    ];
    if ($floor) $iblockFilter["PROPERTY_" . PROP_FLOOR] = $floor;

    $productData = getCIBlockElementsByFilter($iblockFilter);

    // If floor was set and match failed, retry without floor
    if (!$productData && $floor) {
        unset($iblockFilter["PROPERTY_" . PROP_FLOOR]);
        $productData = getCIBlockElementsByFilter($iblockFilter);
    }

    if (!$productData) {
        $results[] = [
            "deal_id"     => $dealId,
            "title"       => $deal["TITLE"],
            "result"      => "no_match",
            "filter_used" => $iblockFilter,
        ];
        $failed++;
        continue;
    }

    // Preview mode — report what would be linked without changing anything
    if ($mode === "preview") {
        $results[] = [
            "deal_id"    => $dealId,
            "title"      => $deal["TITLE"],
            "result"     => "would_link",
            "product_id" => $productData["ID"],
            "product"    => $productData["NAME"],
            "block"      => $productData[PROP_BLOCK],
            "project"    => $productData[PROP_PROJECT],
            "floor"      => $productData[PROP_FLOOR],
            "number"     => $productData[PROP_NUMBER],
        ];
        $matched++;
        continue;
    }

    // Run mode — actually link
    // $linkResult = linkProductToDeal($dealId, $productData);
    // $results[] = array_merge($linkResult, [
    //     "deal_id" => $dealId,
    //     "title"   => $deal["TITLE"],
    //     "product" => $productData["NAME"],
    // ]);
    // if ($linkResult["status"] === 200) $matched++;
    // else $failed++;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    "mode"    => $mode,
    "summary" => ["matched" => $matched, "skipped" => $skipped, "failed" => $failed, "total" => count($results)],
    "results" => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);