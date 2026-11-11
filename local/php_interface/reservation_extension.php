<?php

// ჯავშნის ვადის ცვლილება: ბარათის ღილაკი "რეზერვაციის ცვლილება" -> updateReservation.php ->
// saveUpdatereservation.php -> BP 21 "update reservation" (დასტური: user 3 + group_g20).
//
// შეზღუდვა (2026-11-11): ერთ რეზერვაციაზე ვადა მხოლოდ ერთხელ გრძელდება, რეზერვაციის ტიპის მიუხედავად.
// ითვლება მხოლოდ დადასტურებული მოთხოვნა, რომელიც მიმდინარე რეზერვაციის დაწყების შემდეგ დადასტურდა
// (რეზერვაციის დაწყება = დილზე BP 6 / BP 99-ის ბოლო გაშვება). უარყოფილი მოთხოვნა არ ითვლება.
// სანამ მოთხოვნა დასტურს ელოდება, ახალს ვერ გააგზავნის. ახალი რეზერვაცია (ვადის ამოწურვის შემდეგ
// ხელახლა, მხოლოდ არასტანდარტული, დასტურით) ისევ ერთ გაგრძელებას იძლევა.
//
// ვადის ამოწურვა: BP 6 / BP 99 ჯავშანს მხოლოდ მაშინ ათავისუფლებს, თუ თარიღის ველში ისევ თავდაპირველი
// თარიღია, ამიტომ გაგრძელებული ჯავშანი არასდროს თავისუფლდებოდა. agent "monolithReservationExtensionAgent();"
// გაგრძელებულ ჯავშანს ახალი თარიღის მომდევნო სამუშაო დღეს ათავისუფლებს (როგორც BP 6 არასტანდარტულს).

const RESERVATION_EXT_BP_ID          = 21;               // BP "update reservation"
const RESERVATION_EXT_START_BP_IDS   = [6, 99];          // რეზერვაციის BP-ები (6 - ჯავშანი, 99 - დაშლილი დილის ვადა)
const RESERVATION_EXT_LIMIT          = 1;                // რამდენჯერ შეიძლება გაგრძელება ერთ რეზერვაციაზე
const RESERVATION_EXT_STAGE          = 'FINAL_INVOICE';  // დადასტურებული რეზერვაცია
const RESERVATION_EXT_RETURN_STAGE   = 'PREPARATION';    // დამუშავების პროცესში
const RESERVATION_EXT_DATE_FIELD     = 'UF_CRM_1779278567041';  // დარეზერვებულია თარიღამდე
const RESERVATION_EXT_TYPE_FIELD     = 'UF_CRM_1779278640735';  // რეზერვაციის ტიპი
const RESERVATION_EXT_BATCH          = 30;               // agent-ის ერთ გაშვებაზე მაქსიმუმ ამდენი დილი

/** "12/11/2026", "2026-11-12", Date ობიექტი -> ამ დღის 00:00 timestamp ან false */
function reservationExtParseDate($value)
{
    if ($value instanceof \Bitrix\Main\Type\Date) {
        $value = $value->format("Y-m-d");
    }
    $value = trim((string)$value);
    if ($value === "") {
        return false;
    }
    foreach (["!d/m/Y", "!d.m.Y", "!Y-m-d"] as $format) {
        $dt = DateTime::createFromFormat($format, substr($value, 0, 10));
        if ($dt instanceof DateTime) {
            return $dt->getTimestamp();
        }
    }
    return false;
}

function reservationExtFormatDate($timestamp)
{
    return $timestamp ? date("d/m/Y", $timestamp) : "";
}

/** შემდეგი სამუშაო დღე (ორშ-პარ) 00:00 */
function reservationExtNextWorkDay($timestamp)
{
    $result = strtotime(date("Y-m-d", $timestamp) . " +1 day");
    while ((int)date("N", $result) > 5) {
        $result = strtotime("+1 day", $result);
    }
    return $result;
}

/** მიმდინარე რეზერვაციაში დადასტურებული გაგრძელებები: დასტურის დროები ("Y-m-d H:i:s"), ძველიდან ახლისკენ */
function reservationExtApprovedInCurrentReservation($dealId)
{
    global $DB;
    $documentId = "DEAL_" . (int)$dealId;

    $sql = "SELECT t.MODIFIED AS APPROVED"
        . " FROM b_bp_workflow_state s"
        . " JOIN b_bp_task t ON t.WORKFLOW_ID = s.ID AND t.STATUS = 1"
        . " WHERE s.DOCUMENT_ID = '" . $documentId . "' AND s.WORKFLOW_TEMPLATE_ID = " . (int)RESERVATION_EXT_BP_ID
        . " AND t.MODIFIED >= COALESCE((SELECT MAX(r.STARTED) FROM b_bp_workflow_state r"
        . "   WHERE r.DOCUMENT_ID = '" . $documentId . "'"
        . "   AND r.WORKFLOW_TEMPLATE_ID IN (" . implode(",", array_map("intval", RESERVATION_EXT_START_BP_IDS)) . ")), '1970-01-01')"
        . " ORDER BY t.MODIFIED";

    $approved = [];
    $res = $DB->Query($sql);
    while ($row = $res->Fetch()) {
        $approved[] = (string)$row["APPROVED"];
    }
    return $approved;
}

/** ამ დილზე გაგრძელების მოთხოვნა დასტურს ელოდება? */
function reservationExtRequestPending($dealId)
{
    global $DB;
    $row = $DB->Query(
        "SELECT ID FROM b_bp_workflow_state WHERE DOCUMENT_ID = 'DEAL_" . (int)$dealId . "'"
        . " AND WORKFLOW_TEMPLATE_ID = " . (int)RESERVATION_EXT_BP_ID
        . " AND (STATE IS NULL OR STATE NOT IN ('Completed', 'Terminated')) LIMIT 1"
    )->Fetch();
    return (bool)$row;
}

/**
 * ფორმისა და შენახვისთვის: შეიძლება თუ არა ვადის გაგრძელება.
 * [DEAL (ID, TITLE, STAGE_ID, თარიღი, ტიპი) ან null, DATE (ts|false), APPROVED (array), PENDING, ALLOWED, REASON]
 */
function reservationExtInfo($dealId)
{
    $dealId = (int)$dealId;
    $deal = $dealId > 0 ? CCrmDeal::GetListEx(
        [],
        ["=ID" => $dealId, "CHECK_PERMISSIONS" => "N"],
        false,
        false,
        ["ID", "TITLE", "STAGE_ID", RESERVATION_EXT_DATE_FIELD, RESERVATION_EXT_TYPE_FIELD]
    )->Fetch() : false;

    $info = [
        "DEAL"     => $deal ?: null,
        "DATE"     => $deal ? reservationExtParseDate($deal[RESERVATION_EXT_DATE_FIELD] ?? "") : false,
        "APPROVED" => [],
        "PENDING"  => false,
        "ALLOWED"  => false,
        "REASON"   => "",
    ];

    if (!$deal) {
        $info["REASON"] = "დილი ვერ მოიძებნა";
        return $info;
    }
    if ($deal["STAGE_ID"] !== RESERVATION_EXT_STAGE) {
        $info["REASON"] = "ჯავშნის ვადის ცვლილება მხოლოდ დადასტურებულ რეზერვაციაზე შეიძლება";
        return $info;
    }

    $info["PENDING"]  = reservationExtRequestPending($dealId);
    $info["APPROVED"] = reservationExtApprovedInCurrentReservation($dealId);

    if ($info["PENDING"]) {
        $info["REASON"] = "ჯავშნის ვადის ცვლილების მოთხოვნა უკვე გაგზავნილია და დასტურს ელოდება";
    } elseif (count($info["APPROVED"]) >= RESERVATION_EXT_LIMIT) {
        $info["REASON"] = "ამ რეზერვაციაზე ჯავშნის ვადა ერთხელ უკვე გაგრძელდა (" . date("d/m/Y", strtotime(end($info["APPROVED"]))) . "). მეორედ გაგრძელება აღარ შეიძლება";
    } else {
        $info["ALLOWED"] = true;
    }
    return $info;
}

/**
 * ვადაგასული გაგრძელებული ჯავშნები: დადასტურებული რეზერვაცია, მიმდინარე რეზერვაციაში დადასტურებული
 * გაგრძელებით, და ველის თარიღის მომდევნო სამუშაო დღე დადგა. [ID, TITLE, ASSIGNED_BY_ID, DATE (ts)]
 */
function reservationExtExpiredDeals($now = null)
{
    global $DB;
    $now = $now ?: time();

    $res = $DB->Query(
        "SELECT d.ID, d.TITLE, d.ASSIGNED_BY_ID, u." . RESERVATION_EXT_DATE_FIELD . " AS RES_DATE"
        . " FROM b_crm_deal d"
        . " JOIN b_uts_crm_deal u ON u.VALUE_ID = d.ID"
        . " WHERE d.STAGE_ID = '" . RESERVATION_EXT_STAGE . "' AND d.CATEGORY_ID = 0 AND d.CLOSED = 'N'"
        . " AND u." . RESERVATION_EXT_DATE_FIELD . " IS NOT NULL"
        . " AND u." . RESERVATION_EXT_DATE_FIELD . " < '" . date("Y-m-d", $now) . "'"
        . " ORDER BY d.ID"
    );

    $deals = [];
    while ($row = $res->Fetch()) {
        $date = reservationExtParseDate($row["RES_DATE"]);
        if (!$date || reservationExtNextWorkDay($date) > $now) {
            continue;
        }
        if (!reservationExtApprovedInCurrentReservation($row["ID"])) {
            continue;   // გაგრძელება არ ყოფილა - ამას BP 6 / BP 99 აგვარებს
        }
        $row["DATE"] = $date;
        $deals[] = $row;
    }
    return $deals;
}

/** ვადაგასული გაგრძელებული ჯავშნის გათავისუფლება: დამუშავების პროცესში + BP 7 (ბინა თავისუფლდება) + კომენტარი */
function reservationExtReleaseDeal($deal)
{
    $dealId = (int)$deal["ID"];

    $Deal = new CCrmDeal(false);
    $Deal->Update($dealId, [
        "STAGE_ID"                 => RESERVATION_EXT_RETURN_STAGE,
        RESERVATION_EXT_DATE_FIELD => "",
        RESERVATION_EXT_TYPE_FIELD => "",
    ], true, true, [
        "DISABLE_USER_FIELD_CHECK" => true,
        "CURRENT_USER"             => 1,
    ]);

    // 7 bp statusChange: new stage -> დილის საკუთარი ბინა თავისუფლდება
    CModule::IncludeModule('bizproc');
    $errors = [];
    CBPDocument::StartWorkflow(7, ["crm", "CCrmDocumentDeal", "DEAL_" . $dealId], [], $errors);

    // კომენტარი bp-ის შემდეგ - ბინის რეალური სტატუსით
    $text = "სისტემური კომენტარი:\nგაგრძელებული რეზერვაციის ვადა (" . reservationExtFormatDate($deal["DATE"]) . ") ამოიწურა, დილი დაბრუნდა დამუშავების პროცესში.";
    if (function_exists('contractDeadlineProducts')) {
        $freed = $kept = [];
        foreach (contractDeadlineProducts($dealId) as $product) {
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
    }
    if (class_exists('\Bitrix\Crm\Timeline\CommentEntry')) {
        \Bitrix\Crm\Timeline\CommentEntry::create([
            "TEXT"      => $text,
            "AUTHOR_ID" => (int)$deal["ASSIGNED_BY_ID"] ?: 1,
            "BINDINGS"  => [["ENTITY_TYPE_ID" => CCrmOwnerType::Deal, "ENTITY_ID" => $dealId]],
        ]);
    }
}

/** agent: CAgent "monolithReservationExtensionAgent();" (ყოველ 15 წუთში) */
function monolithReservationExtensionAgent()
{
    if (CModule::IncludeModule('crm')) {
        $done = 0;
        foreach (reservationExtExpiredDeals() as $deal) {
            // მოთხოვნა დასტურს ელოდება - პასუხამდე არ ვათავისუფლებთ
            if (reservationExtRequestPending($deal["ID"])) {
                continue;
            }
            try {
                reservationExtReleaseDeal($deal);
            } catch (\Throwable $e) {
                // ერთი დილის შეცდომამ დანარჩენი არ უნდა შეაჩეროს
            }
            if (++$done >= RESERVATION_EXT_BATCH) {
                break;
            }
        }
    }
    return "monolithReservationExtensionAgent();";
}
