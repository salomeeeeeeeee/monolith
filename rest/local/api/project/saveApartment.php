<?php
ob_start();
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/header.php");
ob_end_clean();
$APPLICATION->SetTitle("Title");

CModule::IncludeModule("crm");

// =========================== FUNCTIONS ===========================
function getCIBlockElementsByID($ID) {
    $res = CIBlockElement::GetList([], ["ID" => $ID], false, ["nPageSize" => 50], ["ID", "NAME", "IBLOCK_ID", "PROPERTY_*"]);
    if ($ob = $res->GetNextElement()) {
        $arFields = $ob->GetFields();
        $arProps  = $ob->GetProperties();
        $arPushs  = [];
        foreach ($arFields as $k => $v) $arPushs[$k] = $v;
        foreach ($arProps as $k => $p) {
            $arPushs[$k] = is_array($p["VALUE"]) ? implode(", ", $p["VALUE"]) : $p["VALUE"];
        }
                $price = CPrice::GetBasePrice($arPushs["ID"]);
        $arPushs["PRICE"] = $price["PRICE"];
        return $arPushs;
    }
}

// ============================ MAIN CODE ============================

$deal_id    = $_GET["deal_id"] ?? "";
$productIds = explode(",", $_GET["productIds"] ?? "");
$productIds = array_filter($productIds, fn($x) => is_numeric($x));
file_put_contents($_SERVER["DOCUMENT_ROOT"]."/debug_product.txt", print_r([
    "deal_id"    => $deal_id,
    "productIds" => $productIds,
], true));

$resArray  = [];
$arrForAdd = [];

if ($deal_id) {

    // ── EMPTY: clear all products and wipe deal fields ──
   // ── EMPTY: clear all products and wipe deal fields ──
   if (empty($productIds)) {
    // Load BEFORE clearing
    $prevProds = CCrmDeal::LoadProductRows($deal_id);
    $cleared = CCrmDeal::SaveProductRows($deal_id, []);

    if ($cleared) {
        // ── Clear product iBlock fields for previously attached products ──
            $el = new CIBlockElement;
            foreach ($prevProds as $prod) {
                $productData = getCIBlockElementsByID($prod["PRODUCT_ID"]);
                if (!$productData) continue;
                $arUpdateProps = [
                    "PROPERTY_VALUES" => array_merge($productData, [
                        "OWNER_DEAL"             => "",
                        "OWNER_PERSONAL_CONTACT" => "",
                        "DEAL_RESPONSIBLE"       => "",
                    ]),
                    "NAME"   => $productData["NAME"],
                    "ACTIVE" => "Y",
                ];
                $el->Update($productData["ID"], $arUpdateProps);
            }
            $arrClear = [
                "UF_CRM_1779277671391" => "",
                "UF_CRM_1779277729207" => "",
                "UF_CRM_1779277644355" => "",
                "UF_CRM_1779277898205" => "",
                "UF_CRM_1779277754252" => "",
                "UF_CRM_1779277828822" => "",
                "UF_CRM_1779277613798" => "",
                "UF_CRM_1779277886804" => "",
                "UF_CRM_1779277919090" => "",
                "UF_CRM_1761658642424" => "",
                "UF_CRM_1761658662573" => "",
                "UF_CRM_1764317005"    => "",
                "UF_CRM_1779277786379" => "",
                "UF_CRM_1779277838333" => "",
                "UF_CRM_1779277860291" => "",
                "UF_CRM_1779277690404" => "",
                "PRODUCT_ID"           => "",
            ];
            $Deal   = new CCrmDeal(false);
            $Deal->Update($deal_id, $arrClear);

            $resArray["status"]  = 200;
            $resArray["message"] = "პროდუქტები წაიშალა";
        } else {
            $resArray["status"] = 400;
            $resArray["error"]  = "დაფიქსირდა შეცდომა";
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resArray, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── NON-EMPTY: save products and update deal fields ──
    $KVM_PRICE = $project = $block = $PRODUCT_TYPE = $sadarbazo = "";
    $prodFLOOR = $prodNumber = $prodTOTAL_AREA = $LIVING_SPACE = "";
    $sawyisiGirebuleba = $phase = $productIdsForAdd = "";
    $summerspace = $bedrooms = $bathrooms = $rooms = "";

    $rows = [];
    foreach ($productIds as $pid) {
        $productData = getCIBlockElementsByID($pid);

        if (!$productData) {
            $resArray["status"] = 400;
            $resArray["error"]  = "ბინა ვერ მოიძებნა";
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($resArray, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $rows[] = ["PRODUCT_ID" => $pid, "PRICE" => floatval($productData["PRICE"]), "QUANTITY" => 1];

        $KVM_PRICE         = $KVM_PRICE         ? $KVM_PRICE         . " /" . $productData["__6ZWTER"]  : $productData["__6ZWTER"];
        $project           = $project           ? $project           . " /" . $productData["__VO9RG4"]  : $productData["__VO9RG4"];
        $block             = $block             ? $block             . " /" . $productData["_L24CUB"]   : $productData["_L24CUB"];
        $summerspace       = $summerspace       ? $summerspace       . " /" . $productData["__BL1XXK"]  : $productData["__BL1XXK"];
        $bedrooms          = $bedrooms          ? $bedrooms          . " /" . $productData["__KYRP1L"]  : $productData["__KYRP1L"];
        $bathrooms         = $bathrooms         ? $bathrooms         . " /" . $productData["__9H8XS9"]  : $productData["__9H8XS9"];
        $rooms             = $rooms             ? $rooms             . " /" . $productData["__WX6YWZ"]  : $productData["__WX6YWZ"];
        $PRODUCT_TYPE      = $PRODUCT_TYPE      ? $PRODUCT_TYPE      . " /" . $productData["__X1GCRZ"]  : $productData["__X1GCRZ"];
        $sadarbazo         = $sadarbazo         ? $sadarbazo         . " /" . $productData["_D599QA"]   : $productData["_D599QA"];
        $prodFLOOR         = $prodFLOOR         ? $prodFLOOR         . " /" . $productData["_FTRIDL"]   : $productData["_FTRIDL"];
        $prodNumber        = $prodNumber        ? $prodNumber        . " /" . $productData["__6KWOWZ"]  : $productData["__6KWOWZ"];
        $prodTOTAL_AREA    = $prodTOTAL_AREA    ? $prodTOTAL_AREA    . " /" . $productData["__173JA5"]  : $productData["__173JA5"];
        $LIVING_SPACE      = $LIVING_SPACE      ? $LIVING_SPACE      . " /" . $productData["__US58ND"]  : $productData["__US58ND"];
        $sawyisiGirebuleba = $sawyisiGirebuleba ? $sawyisiGirebuleba . " /" . $productData["PRICE"]     : $productData["PRICE"];
        $phase             = $phase             ? $phase             . " /" . $productData["phase"]     : $productData["phase"];
        $productIdsForAdd  = $productIdsForAdd  ? $productIdsForAdd  . " /" . $productData["ID"]        : $productData["ID"];
    }

    $arrForAdd = [
        "UF_CRM_1779277671391" => $KVM_PRICE,
        "UF_CRM_1779277729207" => $project,
        "UF_CRM_1779277644355" => $block,
        "UF_CRM_1779277898205" => $PRODUCT_TYPE,
        "UF_CRM_1779277754252" => $sadarbazo,
        "UF_CRM_1779277828822" => $prodFLOOR,
        "UF_CRM_1779277613798" => $prodNumber,
        "UF_CRM_1779277886804" => $prodTOTAL_AREA,
        "UF_CRM_1779277919090" => $LIVING_SPACE,
        "UF_CRM_1761658642424" => $sawyisiGirebuleba,
        "UF_CRM_1761658662573" => $KVM_PRICE,
        "UF_CRM_1764317005"    => $phase,
        "UF_CRM_1779277786379" => $summerspace,
        "UF_CRM_1779277838333" => $bedrooms,
        "UF_CRM_1779277860291" => $bathrooms,
        "UF_CRM_1779277690404" => $rooms,
        "PRODUCT_ID"           => $productIdsForAdd,
    ];

    $added = CCrmDeal::SaveProductRows($deal_id, $rows);
    if ($added) {
        $Deal = new CCrmDeal(false);
        $Deal->Update($deal_id, $arrForAdd);

        // ── Update product iBlock fields ──
        try {
            $dealRes  = CCrmDeal::GetList(["ID" => "ASC"], ["ID" => $deal_id], []);
            $dealData = $dealRes->Fetch();

            foreach ($productIds as $pid) {
                $pid = intval($pid);
                if (!$pid) continue;
                $productData = getCIBlockElementsByID($pid);
                if (!$productData || empty($productData["IBLOCK_ID"])) continue;

                CIBlockElement::SetPropertyValuesEx($pid, $productData["IBLOCK_ID"], [
                    "OWNER_DEAL"             => $deal_id,
                    "OWNER_PERSONAL_CONTACT" => $dealData["CONTACT_ID"]    ?? "",
                    "DEAL_RESPONSIBLE"       => $dealData["ASSIGNED_BY_ID"] ?? "",
                ]);
            }
        } catch (Exception $e) {
            // log but don't fail the whole request
            error_log("iBlock property update failed: " . $e->getMessage());
        }
        $resArray["status"]  = 200;
        $resArray["message"] = "მონაცემები შენახულია";
        
        CModule::IncludeModule('bizproc');
        $errors = array();
        CBPDocument::StartWorkflow(
            25,
            array('crm', 'CCrmDocumentDeal', 'DEAL_' . $deal_id),
            array(),
            $errors
        );
        if (!empty($errors)) {
            error_log("Workflow 25 start error: " . implode(", ", array_map(fn($e) => $e['message'], $errors)));
        }
    } else {
        $resArray["status"] = 400;
        $resArray["error"]  = "დაფიქსირდა შეცდომა";
    }

} else {
    $resArray["status"] = 400;
    $resArray["error"]  = "დილი ვერ მოიძებნა";
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArray, JSON_UNESCAPED_UNICODE);