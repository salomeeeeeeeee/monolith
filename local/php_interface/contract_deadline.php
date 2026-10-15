<?php

// "ხელშეკრულების" ეტაპზე დილს აქვს 5 სამუშაო დღე. ვადის გასვლის შემდეგ agent-ი დილს
// "დამუშავების პროცესში" აბრუნებს და 7 bp statusChange ბინას ათავისუფლებს.
// ვადა ითვლება ეტაპზე ბოლო გადასვლიდან (b_crm_deal_stage_history), ამიტომ ძველ დილებზეც მუშაობს.
//
// გაგრძელება (2026-10-15): სეილი ბარათიდან "ვადის გაგრძელებას" ითხოვს (extendContract.php, კომენტარი
// სავალდებულოა), ნუკი (user 9) ადასტურებს BP-ში და ახალი თარიღი CONTRACT_DEADLINE_FIELD-ში იწერება.
// შევსებული ველი 5 დღეს ცვლის (ვადა = ის დღე 23:59). ეტაპიდან გასვლისას ველი იშლება, რომ ხელახლა
// შესვლისას ისევ 5 დღე დაიწყოს. ლიმიტი არ არის - რამდენჯერაც სჭირდება, იმდენჯერ ითხოვს.
// სანამ მოთხოვნა დასტურს ელოდება, agent-ი დილს არ აბრუნებს, ვადა გასულიც რომ იყოს; ეტაპიდან სხვა გზით
// გასვლისას მომლოდინე მოთხოვნა უქმდება.

const CONTRACT_DEADLINE_STAGE        = 'EXECUTING';     // ხელშეკრულება
const CONTRACT_DEADLINE_RETURN_STAGE = 'PREPARATION';   // დამუშავების პროცესში
const CONTRACT_DEADLINE_WORK_DAYS    = 5;
const CONTRACT_DEADLINE_BATCH        = 30;              // ერთ გაშვებაზე მაქსიმუმ ამდენი დილი
const CONTRACT_DEADLINE_FIELD        = 'UF_CRM_CONTRACT_DEADLINE';  // გაგრძელებული ვადა (თარიღი), წერს BP დასტურის შემდეგ
const CONTRACT_EXTEND_BP_ID          = 90;              // BP "ხელშეკრულების ვადის გაგრძელება" (bitrix/bp/contract-extension-template.php)

/** N სამუშაო დღის (ორშ-პარ) დამატება, დღის დრო იგივე რჩება */
function contractDeadlineAddWorkDays($timestamp, $days)
{
    $result = $timestamp;
    while ($days > 0) {
        $result = strtotime("+1 day", $result);
        if ((int)date("N", $result) <= 5) {
            $days--;
        }
    }
    return $result;
}

/**
 * "ხელშეკრულების" ეტაპზე მყოფი დილები ვადით:
 * [ID, TITLE, ASSIGNED_BY_ID, ENTERED (ts), DEADLINE (ts), EXTENDED (ts ან false - დადასტურებული გაგრძელება)]
 */
function contractDeadlineDeals($assignedById = 0, $dealId = 0)
{
    global $DB;

    $sql = "SELECT d.ID, d.TITLE, d.ASSIGNED_BY_ID, u." . CONTRACT_DEADLINE_FIELD . " AS EXTENDED,"
        . " (SELECT MAX(h.CREATED_TIME) FROM b_crm_deal_stage_history h"
        . "  WHERE h.OWNER_ID = d.ID AND h.STAGE_ID = '" . CONTRACT_DEADLINE_STAGE . "') AS ENTERED"
        . " FROM b_crm_deal d"
        . " LEFT JOIN b_uts_crm_deal u ON u.VALUE_ID = d.ID"
        . " WHERE d.STAGE_ID = '" . CONTRACT_DEADLINE_STAGE . "' AND d.CATEGORY_ID = 0 AND d.CLOSED = 'N'";
    if ($assignedById > 0) {
        $sql .= " AND d.ASSIGNED_BY_ID = " . (int)$assignedById;
    }
    if ($dealId > 0) {
        $sql .= " AND d.ID = " . (int)$dealId;
    }

    $deals = [];
    $res = $DB->Query($sql);
    while ($row = $res->Fetch()) {
        $entered = $row["ENTERED"] ? strtotime($row["ENTERED"]) : false;
        if (!$entered) {
            continue;
        }
        $row["ENTERED"]  = $entered;
        $row["DEADLINE"] = contractDeadlineAddWorkDays($entered, CONTRACT_DEADLINE_WORK_DAYS);

        // დადასტურებული გაგრძელება: ვადა იმ დღის ბოლომდე
        $extended = $row["EXTENDED"] ? strtotime(substr((string)$row["EXTENDED"], 0, 10) . " 23:59:59") : false;
        $row["EXTENDED"] = $extended ?: false;
        if ($extended && $extended > $row["DEADLINE"]) {
            $row["DEADLINE"] = $extended;
        }
        $deals[] = $row;
    }
    return $deals;
}

/** ერთი დილის ვადა (იგივე სტრუქტურა) ან false, თუ დილი ხელშეკრულების ეტაპზე არ არის */
function contractDeadlineForDeal($dealId)
{
    $deals = contractDeadlineDeals(0, (int)$dealId);
    return $deals ? $deals[0] : false;
}

function contractDeadlineFormat($timestamp)
{
    return date("d/m/Y H:i", $timestamp);
}

/** ვადაგასული დილის დაბრუნება "დამუშავების პროცესში" + ბინის გათავისუფლება */
function contractDeadlineReturnDeal($dealId, $deal = null)
{
    $dealId = (int)$dealId;

    $fields = [
        "STAGE_ID"              => CONTRACT_DEADLINE_RETURN_STAGE,
        "UF_CRM_1779278567041"  => "",   // დარეზერვებულია თარიღამდე
        "UF_CRM_1779278640735"  => "",   // რეზერვაციის ტიპი
        CONTRACT_DEADLINE_FIELD => "",   // გაგრძელებული ვადა
    ];
    $Deal = new CCrmDeal(false);
    $Deal->Update($dealId, $fields, true, true, [
        "DISABLE_USER_FIELD_CHECK" => true,
        "CURRENT_USER"             => 1,
    ]);

    // 7 bp statusChange: new stage -> დილის საკუთარი ბინა თავისუფლდება
    CModule::IncludeModule('bizproc');
    $errors = [];
    CBPDocument::StartWorkflow(7, ["crm", "CCrmDocumentDeal", "DEAL_" . $dealId], [], $errors);

    // კომენტარი bp-ის შემდეგ - ბინის რეალური სტატუსით
    if (class_exists('\Bitrix\Crm\Timeline\CommentEntry')) {
        \Bitrix\Crm\Timeline\CommentEntry::create([
            "TEXT"      => contractDeadlineComment(contractDeadlineProducts($dealId), $deal),
            "AUTHOR_ID" => 1,
            "BINDINGS"  => [["ENTITY_TYPE_ID" => CCrmOwnerType::Deal, "ENTITY_ID" => $dealId]],
        ]);
    }
}

/** დილის ბინები: [LABEL, STATUS] */
function contractDeadlineProducts($dealId)
{
    CModule::IncludeModule('iblock');
    $products = [];
    foreach (CCrmDeal::LoadProductRows($dealId) as $row) {
        $element = CIBlockElement::GetList([], ["ID" => $row["PRODUCT_ID"]], false, false, ["ID", "PROPERTY__P64GYD", "PROPERTY___VO9RG4", "PROPERTY___6KWOWZ"])->Fetch();
        if (!$element) {
            continue;
        }
        $products[] = [
            "LABEL"  => trim($element["PROPERTY___VO9RG4_VALUE"] . " N" . $element["PROPERTY___6KWOWZ_VALUE"]),
            "STATUS" => (string)$element["PROPERTY__P64GYD_VALUE"],
        ];
    }
    return $products;
}

/** "გათავისუფლდა უძრავი ქონება" მხოლოდ მაშინ, თუ ბინა მართლა თავისუფალია */
function contractDeadlineComment($products, $deal = null)
{
    if ($deal && !empty($deal["EXTENDED"])) {
        $text = "სისტემური კომენტარი:\nხელშეკრულების ეტაპის გაგრძელებული ვადა (" . contractDeadlineFormat($deal["EXTENDED"]) . ") ამოიწურა, დილი დაბრუნდა დამუშავების პროცესში.";
    } else {
        $text = "სისტემური კომენტარი:\nხელშეკრულების ეტაპზე " . CONTRACT_DEADLINE_WORK_DAYS . " სამუშაო დღე ამოიწურა, დილი დაბრუნდა დამუშავების პროცესში.";
    }

    $freed = $kept = [];
    foreach ($products as $product) {
        if ($product["STATUS"] === "თავისუფალი") {
            $freed[] = $product["LABEL"];
        } else {
            $kept[] = $product["LABEL"] . " (" . $product["STATUS"] . ")";
        }
    }
    if ($freed) {
        $text .= "\nგათავისუფლდა უძრავი ქონება: " . implode(", ", $freed);
    }
    if ($kept) {
        $text .= "\nუძრავი ქონება არ გათავისუფლებულა: " . implode(", ", $kept);
    }
    return $text;
}

/** ამ დილზე მიმდინარე (დასტურის მომლოდინე) გაგრძელების მოთხოვნების workflow ID-ები */
function contractDeadlinePendingWorkflows($dealId)
{
    if (CONTRACT_EXTEND_BP_ID <= 0) {
        return [];
    }
    $ids = [];
    $res = \Bitrix\Main\Application::getConnection()->query(
        "SELECT ID FROM b_bp_workflow_state WHERE DOCUMENT_ID = 'DEAL_" . (int)$dealId . "'"
        . " AND WORKFLOW_TEMPLATE_ID = " . (int)CONTRACT_EXTEND_BP_ID
        . " AND STATE NOT IN ('Completed', 'Terminated')"
    );
    while ($row = $res->fetch()) {
        $ids[] = $row["ID"];
    }
    return $ids;
}

/** ამ დილზე გაგრძელების მოთხოვნა უკვე მიდის და დასტურს ელოდება? */
function contractDeadlineRequestPending($dealId)
{
    return count(contractDeadlinePendingWorkflows($dealId)) > 0;
}

/**
 * ეტაპიდან გასვლისას: გაგრძელებული ვადა იშლება (პირდაპირ UF-ში, CRM-ის Update-ის და BP-ების გარეშე)
 * და დასტურის მომლოდინე გაგრძელების მოთხოვნა უქმდება, რომ ნუკიმ უკვე წასული დილი არ დაადასტუროს.
 * OnAfterCrmDealUpdate ბარათიდანაც, კანბანიდანაც და BP-დანაც ეშვება.
 */
function contractDeadlineOnAfterDealUpdate($fields)
{
    global $USER_FIELD_MANAGER;
    try {
        $dealId   = (int)($fields["ID"] ?? 0);
        $newStage = (string)($fields["STAGE_ID"] ?? "");
        if ($dealId <= 0 || $newStage === "" || $newStage === CONTRACT_DEADLINE_STAGE) {
            return;
        }

        $alreadyCleared = array_key_exists(CONTRACT_DEADLINE_FIELD, $fields) && (string)$fields[CONTRACT_DEADLINE_FIELD] === "";
        if (!$alreadyCleared) {
            $row = CCrmDeal::GetListEx([], ["=ID" => $dealId, "CHECK_PERMISSIONS" => "N"], false, false, ["ID", CONTRACT_DEADLINE_FIELD])->Fetch();
            if ($row && !empty($row[CONTRACT_DEADLINE_FIELD])) {
                $USER_FIELD_MANAGER->Update("CRM_DEAL", $dealId, [CONTRACT_DEADLINE_FIELD => ""]);
            }
        }

        $pending = contractDeadlinePendingWorkflows($dealId);
        if ($pending && CModule::IncludeModule('bizproc')) {
            $reason = "ვადის გაგრძელების მოთხოვნა გაუქმდა: დილი ხელშეკრულების ეტაპიდან გავიდა";
            foreach ($pending as $workflowId) {
                $errors = [];
                CBPDocument::TerminateWorkflow($workflowId, ["crm", "CCrmDocumentDeal", "DEAL_" . $dealId], $errors, $reason);
            }
            if (class_exists('\Bitrix\Crm\Timeline\CommentEntry')) {
                \Bitrix\Crm\Timeline\CommentEntry::create([
                    "TEXT"      => "სისტემური კომენტარი:\n" . $reason,
                    "AUTHOR_ID" => 1,
                    "BINDINGS"  => [["ENTITY_TYPE_ID" => CCrmOwnerType::Deal, "ENTITY_ID" => $dealId]],
                ]);
            }
        }
    } catch (\Throwable $e) {
        // ვადის გასუფთავების შეცდომამ დილის შენახვა არ უნდა შეაჩეროს
    }
}

AddEventHandler('crm', 'OnAfterCrmDealUpdate', 'contractDeadlineOnAfterDealUpdate');

/** agent: CAgent "monolithContractDeadlineAgent();" */
function monolithContractDeadlineAgent()
{
    if (CModule::IncludeModule('crm')) {
        $now = time();
        $done = 0;
        foreach (contractDeadlineDeals() as $deal) {
            if ($deal["DEADLINE"] > $now) {
                continue;
            }
            // გაგრძელების მოთხოვნა ნუკის პასუხს ელოდება - დილს არ ვაბრუნებთ; უარყოფისას მომდევნო გაშვება დააბრუნებს
            if (contractDeadlineRequestPending($deal["ID"])) {
                continue;
            }
            contractDeadlineReturnDeal($deal["ID"], $deal);
            if (++$done >= CONTRACT_DEADLINE_BATCH) {
                break;
            }
        }
    }
    return "monolithContractDeadlineAgent();";
}
