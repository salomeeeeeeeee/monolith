<?php

// "ხელშეკრულების" ეტაპზე დილს აქვს 5 სამუშაო დღე. ვადის გასვლის შემდეგ agent-ი დილს
// "დამუშავების პროცესში" აბრუნებს და 7 bp statusChange ბინას ათავისუფლებს.
// ვადა ითვლება ეტაპზე ბოლო გადასვლიდან (b_crm_deal_stage_history), ამიტომ ძველ დილებზეც მუშაობს.

const CONTRACT_DEADLINE_STAGE        = 'EXECUTING';     // ხელშეკრულება
const CONTRACT_DEADLINE_RETURN_STAGE = 'PREPARATION';   // დამუშავების პროცესში
const CONTRACT_DEADLINE_WORK_DAYS    = 5;
const CONTRACT_DEADLINE_BATCH        = 30;              // ერთ გაშვებაზე მაქსიმუმ ამდენი დილი

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
 * [ID, TITLE, ASSIGNED_BY_ID, ENTERED (ts), DEADLINE (ts)]
 */
function contractDeadlineDeals($assignedById = 0)
{
    global $DB;

    $sql = "SELECT d.ID, d.TITLE, d.ASSIGNED_BY_ID,"
        . " (SELECT MAX(h.CREATED_TIME) FROM b_crm_deal_stage_history h"
        . "  WHERE h.OWNER_ID = d.ID AND h.STAGE_ID = '" . CONTRACT_DEADLINE_STAGE . "') AS ENTERED"
        . " FROM b_crm_deal d"
        . " WHERE d.STAGE_ID = '" . CONTRACT_DEADLINE_STAGE . "' AND d.CATEGORY_ID = 0 AND d.CLOSED = 'N'";
    if ($assignedById > 0) {
        $sql .= " AND d.ASSIGNED_BY_ID = " . (int)$assignedById;
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
        $deals[] = $row;
    }
    return $deals;
}

/** ვადაგასული დილის დაბრუნება "დამუშავების პროცესში" + ბინის გათავისუფლება */
function contractDeadlineReturnDeal($dealId)
{
    $dealId = (int)$dealId;

    $fields = [
        "STAGE_ID"             => CONTRACT_DEADLINE_RETURN_STAGE,
        "UF_CRM_1779278567041" => "",   // დარეზერვებულია თარიღამდე
        "UF_CRM_1779278640735" => "",   // რეზერვაციის ტიპი
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
            "TEXT"      => contractDeadlineComment(contractDeadlineProducts($dealId)),
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
function contractDeadlineComment($products)
{
    $text = "სისტემური კომენტარი:\nხელშეკრულების ეტაპზე " . CONTRACT_DEADLINE_WORK_DAYS . " სამუშაო დღე ამოიწურა, დილი დაბრუნდა დამუშავების პროცესში.";

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
            contractDeadlineReturnDeal($deal["ID"]);
            if (++$done >= CONTRACT_DEADLINE_BATCH) {
                break;
            }
        }
    }
    return "monolithContractDeadlineAgent();";
}
