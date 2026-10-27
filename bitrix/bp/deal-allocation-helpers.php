<?php


// from 85 bp deal-allocation-helpers.php

if (!defined('ALLOCATION_EXPIRY_TEMPLATE_ID')) {
    // BP 99 "რეზერვაციის ვადა (დაშლილი დილი)" - BP 6-ის ვადის შემოწმება ასლისთვის
    define('ALLOCATION_EXPIRY_TEMPLATE_ID', 99);
}

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
            "UF_CRM_1781768590754" => $productData["_3BU0JH"] ?? "",
            "UF_CRM_1791205458297" => $productData["xelshNum"] ?? "",
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

        // ორივე property კოდი — statusChange იყენებს ownerDeal/ownerContact-ს,
        // კატალოგი/რეპორტები — OWNER_DEAL / OWNER_PERSONAL_CONTACT-ს.
        $element["_P64GYD"]                 = "დაჯავშნილი";
        $element["OWNER_DEAL"]             = $dealID;
        $element["ownerDeal"]              = $dealID;
        $element["DEAL_RESPONSIBLE"]       = $deal["ASSIGNED_BY_ID"];
        $element["OWNER_PERSONAL_CONTACT"] = $deal["CONTACT_ID"];
        $element["ownerContact"]           = $deal["CONTACT_ID"];
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

if (!function_exists('allocation_reservationFields')) {
    /**
     * რეზერვაციის UF ველები, რომლებიც დაშლისას ასლზე გადადის.
     */
    function allocation_reservationFields()
    {
        return array(
            "UF_CRM_1779278640735", // რეზერვაციის ტიპი (სტანდარტული/არასტანდარტული)
            "UF_CRM_1775116922461", // რეზერვაციის ტიპი (უფასო/ფასიანი)
            "UF_CRM_1779278590201", // დარეზერვების თარიღი
            "UF_CRM_1779278567041", // დარეზერვებულია თარიღამდე
            "UF_CRM_1658259058",    // ჯავშნის მოქმედების ვადა
            "UF_CRM_1781181993925", // რეზერვაციის კომენტარი
            "UF_CRM_1775116974516", // რეზერვაციის თარიღი
            "UF_CRM_1775214559",    // რეზერვაციის ვადა
            "UF_CRM_1775214575",    // რეზერვაციის თანხა
            "UF_CRM_1775315263480", // სასურველი გადახდის ტიპი
        );
    }
}

if (!function_exists('allocation_productLabel')) {
    /** "New Depo, ავტოსადგომი N7, ბლოკი ა, სართული 1" */
    function allocation_productLabel($element)
    {
        $number = trim((string)($element["__6KWOWZ"] ?? ""));
        $block  = trim((string)($element["_L24CUB"] ?? ""));
        $floor  = trim((string)($element["_FTRIDL"] ?? ""));
        $parts = array_filter(array(
            trim((string)($element["__VO9RG4"] ?? "")),
            trim(($element["__X1GCRZ"] ?? "") . ($number !== "" ? " N" . $number : "")),
            $block !== "" ? "ბლოკი " . $block : "",
            $floor !== "" ? "სართული " . $floor : "",
        ), 'strlen');
        return count($parts) ? implode(", ", $parts) : "ProdID " . ($element["ID"] ?? "");
    }
}

if (!function_exists('allocation_addComment')) {
    function allocation_addComment($dealId, $text, $authorId)
    {
        if (!class_exists('\Bitrix\Crm\Timeline\CommentEntry')) {
            return 0;
        }
        try {
            return (int)\Bitrix\Crm\Timeline\CommentEntry::create(array(
                "TEXT"      => $text,
                "AUTHOR_ID" => $authorId > 0 ? $authorId : 1,
                "BINDINGS"  => array(array("ENTITY_TYPE_ID" => CCrmOwnerType::Deal, "ENTITY_ID" => intval($dealId))),
            ));
        } catch (\Throwable $e) {
            // კომენტარის შეცდომამ დაშლა არ უნდა შეაჩეროს
            allocation_debug("addComment FAILED deal=" . intval($dealId) . " err=" . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('allocation_debug')) {
    function allocation_debug($text)
    {
        @file_put_contents(
            $_SERVER["DOCUMENT_ROOT"] . "/debug_allocation.txt",
            date("Y-m-d H:i:s") . " " . $text . "\n",
            FILE_APPEND
        );
    }
}

if (!function_exists('allocation_isSplitComment')) {
    /** წინა დაშლის კომენტარი (თუ წყარო თვითონ ასლია) ახალ ასლს არ ეხება */
    function allocation_isSplitComment($text)
    {
        return mb_strpos($text, "დილი შეიქმნა რეზერვაციისას დილის") !== false
            || mb_strpos($text, "რეზერვაციისას დილი დაიშალა") !== false;
    }
}

if (!function_exists('allocation_copyComments')) {
    /**
     * წყარო დილის კომენტარები (სისტემურიც) ასლზე - იგივე ავტორით და დროით,
     * რომ ყოველ დილზე ცალკე ჩანდეს ჯავშნის ისტორია. მიმაგრებული ფაილები არ გადადის.
     */
    function allocation_copyComments($sourceId, $newId)
    {
        $sourceId = intval($sourceId);
        $newId    = intval($newId);
        $copied   = 0;

        $res = \Bitrix\Main\Application::getConnection()->query(
            "SELECT t.ID, t.COMMENT, t.AUTHOR_ID, t.CREATED FROM b_crm_timeline t"
            . " JOIN b_crm_timeline_bind b ON b.OWNER_ID = t.ID"
            . " WHERE b.ENTITY_TYPE_ID = " . CCrmOwnerType::Deal . " AND b.ENTITY_ID = " . $sourceId
            . " AND t.TYPE_ID = " . \Bitrix\Crm\Timeline\TimelineType::COMMENT
            . " ORDER BY t.ID"
        );
        while ($row = $res->fetch()) {
            if (trim((string)$row["COMMENT"]) === "" || allocation_isSplitComment($row["COMMENT"])) {
                continue;
            }
            $id = allocation_addComment($newId, $row["COMMENT"], intval($row["AUTHOR_ID"]));
            if ($id <= 0) {
                continue;
            }
            $copied++;
            if ($row["CREATED"]) {
                try {
                    \Bitrix\Crm\Timeline\Entity\TimelineTable::update($id, array("CREATED" => $row["CREATED"]));
                } catch (\Throwable $e) {
                    allocation_debug("copyComments CREATED not kept comment={$id} err=" . $e->getMessage());
                }
            }
        }
        return $copied;
    }
}

if (!function_exists('allocation_startCopyExpiry')) {
    /**
     * ასლის ჯავშნის ვადა: ორიგინალზე მას BP 6-ის პაუზა ითვლის, ასლზე - BP 99 (იგივე შემოწმება).
     * ეშვება მხოლოდ თუ ორიგინალზე BP 6 მიმდინარეობს, ანუ ჯავშანი saveReservation-ით გაკეთდა.
     */
    function allocation_startCopyExpiry($sourceId, $copyIds)
    {
        $sourceId = intval($sourceId);
        if (!count($copyIds) || !CModule::IncludeModule('bizproc')) {
            return;
        }

        $running = \Bitrix\Main\Application::getConnection()->query(
            "SELECT ID FROM b_bp_workflow_state WHERE WORKFLOW_TEMPLATE_ID = 6 AND DOCUMENT_ID = 'DEAL_" . $sourceId . "'"
            . " AND (STATE IS NULL OR STATE NOT IN ('Completed', 'Terminated')) LIMIT 1"
        )->fetch();
        if (!$running) {
            allocation_debug("expiry skipped source={$sourceId}: BP 6 არ მიმდინარეობს");
            return;
        }

        $row = CCrmDeal::GetListEx(
            array(),
            array("=ID" => $sourceId, "CHECK_PERMISSIONS" => "N"),
            false,
            false,
            array("ID", "UF_CRM_1779278640735", "UF_CRM_1779278567041")
        )->Fetch();
        $onlyDate = $row ? trim((string)$row["UF_CRM_1779278567041"]) : "";
        if ($onlyDate === "") {
            allocation_debug("expiry skipped source={$sourceId}: ჯავშნის თარიღი ცარიელია");
            return;
        }

        foreach ($copyIds as $copyId) {
            $errors = array();
            $wfId = "";
            try {
                $wfId = CBPDocument::StartWorkflow(
                    ALLOCATION_EXPIRY_TEMPLATE_ID,
                    array("crm", "CCrmDocumentDeal", "DEAL_" . intval($copyId)),
                    array(
                        "type"      => (string)$row["UF_CRM_1779278640735"],
                        "onlyDate"  => $onlyDate,
                        "startTime" => ConvertTimeStamp(time(), "FULL"),
                    ),
                    $errors
                );
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
            allocation_debug("expiry copy=" . intval($copyId) . " source={$sourceId} wf=" . $wfId
                . (count($errors) ? " err=" . json_encode($errors, JSON_UNESCAPED_UNICODE) : ""));
        }
    }
}

if (!function_exists('allocation_refreshAmountWords')) {
    /** ჯამური ფასი სიტყვიერად (BP 25) თავიდან - დაშლამდე ის ყველა ბინის ჯამს აჩვენებს */
    function allocation_refreshAmountWords($dealId)
    {
        if (!CModule::IncludeModule('bizproc')) {
            return;
        }
        $errors = array();
        try {
            CBPDocument::StartWorkflow(25, array("crm", "CCrmDocumentDeal", "DEAL_" . intval($dealId)), array(), $errors);
        } catch (\Throwable $e) {
            allocation_debug("amountWords FAILED deal=" . intval($dealId) . " err=" . $e->getMessage());
        }
    }
}

if (!function_exists('allocation_copyDeal')) {
    /**
     * წყარო დილის ასლი — იგივე სტეიჯი/კონტაქტი/SOURCE_ID/რეზერვაციის ველები, პროდუქტების გარეშე.
     */
    function allocation_copyDeal($sourceDeal)
    {
        if (!$sourceDeal || empty($sourceDeal["ID"])) {
            return 0;
        }

        $sourceId = intval($sourceDeal["ID"]);

        // GetByID იძლევა SOURCE_ID-ს, მაგრამ UF ველებს არა - ისინი ქვემოთ ცალკე იკითხება
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

        // რეზერვაციის თარიღები/ტიპი, თორემ ასლზე ჯავშნის მონაცემები ცარიელი რჩება
        $reservationFields = allocation_reservationFields();
        $ufRow = CCrmDeal::GetListEx(
            array(),
            array("=ID" => $sourceId, "CHECK_PERMISSIONS" => "N"),
            false,
            false,
            array_merge(array("ID"), $reservationFields)
        )->Fetch();
        foreach ($reservationFields as $code) {
            if ($ufRow && isset($ufRow[$code]) && $ufRow[$code] !== "" && $ufRow[$code] !== false) {
                $fields[$code] = $ufRow[$code];
            }
        }

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
        $copies = array();      // ასლის ID => ბინა
        $originalLabel = "";

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
                $originalLabel = allocation_productLabel($element);
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
                $copiedComments = allocation_copyComments($dealID, $newDealId);
                $copies[$newDealId] = allocation_productLabel($element);
                $logLines[] = "Deal {$newDealId} ← ProdID {$productId} (ასლი წყაროდან {$dealID}, კომენტარი: {$copiedComments})";
            }
        }

        // სისტემური კომენტარი ყველა დილზე: საიდან შეიქმნა / რად დაიშალა
        $authorId = intval($deal["ASSIGNED_BY_ID"] ?? 0);
        $dealLink = function ($id) {
            return "[url=/crm/deal/details/{$id}/]#{$id}[/url]";
        };
        if (count($copies)) {
            $lines = array();
            foreach ($copies as $copyId => $label) {
                $lines[] = "- " . $dealLink($copyId) . ": " . $label;
            }
            if ($originalLabel !== "") {
                $lines[] = "- " . $dealLink($dealID) . " (ეს დილი): " . $originalLabel;
            }
            allocation_addComment(
                $dealID,
                "სისტემური კომენტარი:\nრეზერვაციისას დილი დაიშალა, თითო უძრავ ქონებაზე ცალკე დილი:\n" . implode("\n", $lines),
                $authorId
            );
            foreach ($copies as $copyId => $label) {
                allocation_addComment(
                    $copyId,
                    "სისტემური კომენტარი:\nდილი შეიქმნა რეზერვაციისას დილის " . $dealLink($dealID) . " დაშლით.\n"
                        . "უძრავი ქონება: " . $label . "\n"
                        . "დაშლამდე დაწერილი კომენტარები გადმოტანილია დილიდან #" . $dealID . ".",
                    $authorId
                );
            }
        }

        allocation_startCopyExpiry($dealID, array_keys($copies));

        // ჯამური ფასი სიტყვიერად - თითო დილის ახალი ფასით
        allocation_refreshAmountWords($dealID);
        foreach (array_keys($copies) as $copyId) {
            allocation_refreshAmountWords($copyId);
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
