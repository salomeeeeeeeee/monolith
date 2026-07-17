<?php


// from 85 bp deal-allocation-helpers.php
 

if (!function_exists('allocation_getProductDataByID')) {
    function allocation_getProductDataByID($ID)
    {
        if (!is_numeric($ID)) {
            return array();
        }

        $res = CIBlockElement::GetList(
            array(),
            array("ID" => $ID),
            false,
            array("nPageSize" => 1),
            array("ID", "IBLOCK_ID", "NAME", "DATE_ACTIVE_FROM", "PROPERTY_*")
        );

        if ($ob = $res->GetNextElement()) {
            $arFields = $ob->GetFields();
            $arProps  = $ob->GetProperties();
            $arPushs  = array();
            foreach ($arFields as $key => $val) {
                $arPushs[$key] = $val;
            }
            foreach ($arProps as $key => $prop) {
                $arPushs[$key] = $prop["VALUE"];
            }
            $price = CPrice::GetBasePrice($arPushs["ID"]);
            $arPushs["PRICE"] = $price ? $price["PRICE"] : 0;
            return $arPushs;
        }

        return array();
    }
}

if (!function_exists('allocation_dealUpdateFields')) {
    /**
     * ერთი პროდუქტის მონაცემები დილის UF ველებში (monolith catalog mapping).
     */
    function allocation_dealUpdateFields($dealId, $productData)
    {
        $totalPrice = str_replace(array(" ", ",", "$"), "", (string)($productData["__9YCWGZ"] ?? ""));
        $totalPrice = is_numeric($totalPrice) ? floatval($totalPrice) : floatval($productData["PRICE"] ?? 0);

        $arrForAdd = array(
            "UF_CRM_1779277671391" => $productData["__6ZWTER"] ?? "",
            "UF_CRM_1779277729207" => $productData["__VO9RG4"] ?? "",
            "UF_CRM_1779277644355" => $productData["_L24CUB"] ?? "",
            "UF_CRM_1779277898205" => $productData["__X1GCRZ"] ?? "",
            "UF_CRM_1779277754252" => $productData["_D599QA"] ?? "",
            "UF_CRM_1779277828822" => $productData["_FTRIDL"] ?? "",
            "UF_CRM_1779277613798" => $productData["__6KWOWZ"] ?? "",
            "UF_CRM_1779277886804" => $productData["__173JA5"] ?? "",
            "UF_CRM_1779277919090" => $productData["__US58ND"] ?? "",
            "UF_CRM_1761658642424" => $totalPrice,
            "UF_CRM_1761658662573" => $productData["__6ZWTER"] ?? "",
            "UF_CRM_1779277786379" => $productData["__BL1XXK"] ?? "",
            "UF_CRM_1779277838333" => $productData["__KYRP1L"] ?? "",
            "UF_CRM_1779277860291" => $productData["__9H8XS9"] ?? "",
            "UF_CRM_1779277690404" => $productData["__WX6YWZ"] ?? "",
            "UF_CRM_1782206163787" => $productData["__51MODL"] ?? "",
            "PRODUCT_ID"           => $productData["ID"] ?? "",
            "OPPORTUNITY"          => $totalPrice,
        );

        $apartmentNo = $productData["__6KWOWZ"] ?? "";
        if ($apartmentNo !== "") {
            $deal = CCrmDeal::GetByID($dealId, false);
            if ($deal && !empty($deal["TITLE"])) {
                $baseTitle = preg_replace('/\s*\/\s*N\d+\s*$/u', '', $deal["TITLE"]);
                $arrForAdd["TITLE"] = trim($baseTitle) . " / N" . $apartmentNo;
            }
        }

        $Deal = new CCrmDeal(false);
        $Deal->Update($dealId, $arrForAdd, true, true, array(
            "DISABLE_USER_FIELD_CHECK" => true,
            "CURRENT_USER" => 1,
        ));
    }
}

if (!function_exists('allocation_assignProductToDeal')) {
    /**
     * ერთი პროდუქტის მინიჭება დილზე + ინვენტორის OWNER/STATUS განახლება.
     * ანაგის reservation_163 ეკვივალენტი (monolith ველებით).
     */
    function allocation_assignProductToDeal($deal, $element)
    {
        if (!$deal || empty($deal["ID"]) || empty($element["ID"])) {
            return false;
        }

        $dealID = intval($deal["ID"]);

        $element["_P64GYD"]                 = "დაჯავშნილი";
        $element["OWNER_DEAL"]             = $dealID;
        $element["DEAL_RESPONSIBLE"]       = $deal["ASSIGNED_BY_ID"];
        $element["OWNER_PERSONAL_CONTACT"] = $deal["CONTACT_ID"];
        $element["QUEUE"]                  = str_replace("|" . $dealID, "", (string)($element["QUEUE"] ?? ""));

        $el = new CIBlockElement;
        $el->Update($element["ID"], array(
            "PROPERTY_VALUES" => $element,
            "NAME"            => $element["NAME"],
            "ACTIVE"          => "Y",
        ));

        $price = isset($element["PRICE"]) ? floatval($element["PRICE"]) : 0;
        if ($price <= 0) {
            $basePrice = CPrice::GetBasePrice($element["ID"]);
            $price = $basePrice ? floatval($basePrice["PRICE"]) : 0;
            $element["PRICE"] = $price;
        }

        CCrmDeal::SaveProductRows($dealID, array(
            array(
                "PRODUCT_ID" => $element["ID"],
                "PRICE"      => $price,
                "QUANTITY"   => 1,
            ),
        ));

        allocation_dealUpdateFields($dealID, $element);
        return true;
    }
}

if (!function_exists('allocation_copyDeal')) {
    /**
     * წყარო დილის ასლი — იგივე სტეიჯი/კონტაქტი/UF/SOURCE_ID, პროდუქტების გარეშე.
     */
    function allocation_copyDeal($sourceDeal)
    {
        if (!$sourceDeal || empty($sourceDeal["ID"])) {
            return 0;
        }

        $sourceId = intval($sourceDeal["ID"]);

        // GetByID იძლევა სრულ ველებს (SOURCE_ID, UF_...), GetList ხშირად აკლებს
        $fullDeal = CCrmDeal::GetByID($sourceId, false);
        if (!$fullDeal || empty($fullDeal["ID"])) {
            $fullDeal = $sourceDeal;
        }

        $fields = $fullDeal;
        $readonly = array(
            "ID",
            "DATE_CREATE",
            "DATE_MODIFY",
            "CREATED_BY_ID",
            "MODIFY_BY_ID",
            "ACCOUNT_CURRENCY_ID",
            "OPPORTUNITY_ACCOUNT",
            "TAX_VALUE_ACCOUNT",
            "PRODUCT_ID",
            "CLOSED",
            "~STAGE_ID",
            "~DATE_CREATE",
            "~DATE_MODIFY",
            "~CLOSEDATE",
            "~BEGINDATE",
        );
        foreach ($readonly as $key) {
            unset($fields[$key]);
        }

        foreach (array_keys($fields) as $key) {
            if (strpos($key, "~") === 0) {
                unset($fields[$key]);
            }
        }

        $fields["CLOSED"] = "N";

        // წყარო აშკარად გადავიტანოთ (Add ზოგჯერ ტოვებს ცარიელს)
        if (!empty($fullDeal["SOURCE_ID"])) {
            $fields["SOURCE_ID"] = $fullDeal["SOURCE_ID"];
        }
        if (isset($fullDeal["SOURCE_DESCRIPTION"])) {
            $fields["SOURCE_DESCRIPTION"] = $fullDeal["SOURCE_DESCRIPTION"];
        }

        $Deal = new CCrmDeal(false);
        $newId = $Deal->Add($fields, true, array(
            "DISABLE_USER_FIELD_CHECK" => true,
            "CURRENT_USER" => isset($fullDeal["ASSIGNED_BY_ID"]) ? intval($fullDeal["ASSIGNED_BY_ID"]) : 1,
        ));

        if (!$newId) {
            @file_put_contents(
                $_SERVER["DOCUMENT_ROOT"] . "/debug_allocation.txt",
                date("Y-m-d H:i:s") . " copyDeal FAILED source=" . $sourceId
                    . " err=" . $Deal->LAST_ERROR . "\n",
                FILE_APPEND
            );
            return 0;
        }

        // SOURCE_ID უსაფრთხოდ ხელახლა ჩაწერა Update-ით
        if (!empty($fullDeal["SOURCE_ID"])) {
            $upd = array(
                "SOURCE_ID" => $fullDeal["SOURCE_ID"],
            );
            if (isset($fullDeal["SOURCE_DESCRIPTION"])) {
                $upd["SOURCE_DESCRIPTION"] = $fullDeal["SOURCE_DESCRIPTION"];
            }
            $Deal->Update($newId, $upd, true, true, array(
                "DISABLE_USER_FIELD_CHECK" => true,
                "CURRENT_USER" => 1,
            ));
        }

        if (class_exists('\Bitrix\Crm\Binding\DealContactTable')) {
            $contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($sourceId);
            if (!empty($contactIds)) {
                \Bitrix\Crm\Binding\DealContactTable::bindContactIDs($newId, $contactIds);
            }
        }

        @file_put_contents(
            $_SERVER["DOCUMENT_ROOT"] . "/debug_allocation.txt",
            date("Y-m-d H:i:s") . " copyDeal OK source={$sourceId} new={$newId}"
                . " SOURCE_ID=" . ($fullDeal["SOURCE_ID"] ?? "") . "\n",
            FILE_APPEND
        );

        return intval($newId);
    }
}

if (!function_exists('allocation_distributeProducts')) {
    /**
     * მრავალპროდუქტიანი დილის დაშლა:
     * - ბოლო პროდუქტი რჩება ორიგინალ დილზე (copy=no)
     * - დანარჩენებზე იქმნება ასლი და ენიჭება თითო პროდუქტი (copy=yes)
     */
    function allocation_distributeProducts($dealID, $arProducts = null, $deal = null)
    {
        $dealID = intval($dealID);
        if ($dealID <= 0) {
            return "";
        }

        if ($arProducts === null) {
            $arProducts = CCrmDeal::LoadProductRows($dealID);
        }
        if ($deal === null) {
            $deal = CCrmDeal::GetByID($dealID, false);
        }
        if (!$deal) {
            return "დილი ვერ მოიძებნა";
        }

        $prodCount = count($arProducts);
        if ($prodCount <= 1) {
            return "";
        }

        $logLines = array();
        $index = 0;

        foreach ($arProducts as $product) {
            $index++;
            $productId = intval($product["PRODUCT_ID"]);
            if ($productId <= 0) {
                continue;
            }

            $element = allocation_getProductDataByID($productId);
            if (empty($element["ID"])) {
                $logLines[] = "ProdID {$productId}: ვერ მოიძებნა";
                continue;
            }

            $isLast = ($index === $prodCount);

            if ($isLast) {
                // ორიგინალი დილი — ერთი პროდუქტი რჩება
                allocation_assignProductToDeal($deal, $element);
                $logLines[] = "Deal {$dealID} ← ProdID {$productId} (ორიგინალი)";
            } else {
                $newDealId = allocation_copyDeal($deal);
                if (!$newDealId) {
                    $logLines[] = "ProdID {$productId}: დილის ასლი ვერ შეიქმნა";
                    continue;
                }
                $newDeal = CCrmDeal::GetByID($newDealId, false);
                if (!$newDeal) {
                    $logLines[] = "ProdID {$productId}: ახალი დილი {$newDealId} ვერ წაიკითხა";
                    continue;
                }
                allocation_assignProductToDeal($newDeal, $element);
                $logLines[] = "Deal {$newDealId} ← ProdID {$productId} (ასლი წყაროდან {$dealID})";
            }
        }

        $logText = "დილის დაშლა:\n" . implode("\n", $logLines);
        @file_put_contents(
            $_SERVER["DOCUMENT_ROOT"] . "/debug_allocation.txt",
            date("Y-m-d H:i:s") . " DEAL={$dealID}\n{$logText}\n----------------------------------------\n",
            FILE_APPEND
        );

        return $logText;
    }
}
