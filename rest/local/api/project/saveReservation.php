<?php
ob_start();
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
CModule::IncludeModule('crm');

// PHP-ის ფატალური შეცდომის შემთხვევაშიც (მაგ. timeout) პასუხი JSON უნდა იყოს, რომ ფორმამ მიზეზი აჩვენოს
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => 500, "message" => "სერვერის შეცდომა: " . $err['message']], JSON_UNESCAPED_UNICODE);
});

function getCorrectDateRA($date) {
    if (!$date) return ["", ""];
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
        return [$date, $date];
    }
    $parts   = explode("T", $date);
    $dateArr = explode("-", $parts[0]);
    $fixedDate = $dateArr[2] . "/" . $dateArr[1] . "/" . $dateArr[0];
    return [$fixedDate, $fixedDate];
}

function getContactInfo($contactId) {
    $arContact = array();
    $res = CCrmContact::GetList(array("ID" => "ASC"), array("ID" => $contactId), array("ID", "UF_CRM_1779873020955"));
    if ($arContact = $res->Fetch()) {
        $PHONE = \CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'MOBILE|WORK', "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $MAIL  = \CCrmFieldMulti::GetList(array(), array('ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'HOME|WORK',  "ELEMENT_ID" => $arContact["ID"]))->Fetch();
        $arContact["PHONE"] = $PHONE["VALUE"];
        $arContact["EMAIL"] = $MAIL["VALUE"];
        return $arContact;
    }
    return $arContact;
}

// დილს ოდესმე ჰქონდა დადასტურებული რეზერვაცია (FINAL_INVOICE) - ხელახლა მხოლოდ არასტანდარტული ჯავშანი
function dealHadConfirmedReservation($dealID) {
    return (bool)\Bitrix\Crm\History\Entity\DealStageHistoryTable::getList([
        "select" => ["ID"],
        "filter" => ["=OWNER_ID" => (int)$dealID, "=STAGE_ID" => "FINAL_INVOICE"],
        "limit"  => 1,
    ])->fetch();
}

// ── ჯავშნების ჟურნალი ──
// დილის წაგებისას ბინა შეიძლება დილის პროდუქტებიდან მოიხსნას, ამიტომ თითოეულ ჯავშანს
// (დილი, ბინა, პასუხისმგებელი) ცალკე ცხრილში ვინახავთ - შემოწმება მასზეც ხდება
const RES_LOG_TABLE = 'monolith_reservation_log';

function reservationLogEnsureTable() {
    static $done = false;
    if ($done) return;
    \Bitrix\Main\Application::getConnection()->queryExecute(
        "CREATE TABLE IF NOT EXISTS " . RES_LOG_TABLE . " (
            ID         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            DEAL_ID    INT UNSIGNED NOT NULL,
            PRODUCT_ID INT UNSIGNED NOT NULL,
            USER_ID    INT UNSIGNED NOT NULL,
            CREATED_AT DATETIME     NOT NULL,
            KEY IX_USER_PRODUCT (USER_ID, PRODUCT_ID),
            KEY IX_DEAL (DEAL_ID)
        )"
    );
    $done = true;
}

// ჯავშნის გაგზავნისას: დილის ყველა ბინა + დილის პასუხისმგებელი
function reservationLogAdd($dealID) {
    $dealID = (int)$dealID;
    if (!$dealID) return;
    $deal = CCrmDeal::GetListEx([], ["ID" => $dealID, "CHECK_PERMISSIONS" => "N"], false, false, ["ID", "ASSIGNED_BY_ID"])->Fetch();
    $userID = (int)($deal["ASSIGNED_BY_ID"] ?? 0);
    if (!$userID) return;

    reservationLogEnsureTable();
    $conn = \Bitrix\Main\Application::getConnection();
    foreach ((array)CCrmDeal::LoadProductRows($dealID) as $row) {
        $pid = (int)$row["PRODUCT_ID"];
        if (!$pid) continue;
        $conn->queryExecute(
            "INSERT INTO " . RES_LOG_TABLE . " (DEAL_ID, PRODUCT_ID, USER_ID, CREATED_AT)
             VALUES ({$dealID}, {$pid}, {$userID}, NOW())"
        );
    }
}

// ჟურნალიდან: რომელი ბინები ჰქონდა ამ მომხმარებელს დაჯავშნილი სხვა დილზე
function reservationLogFind($dealID, $userID, array $productIDs) {
    $productIDs = array_filter(array_map('intval', $productIDs));
    if (!$productIDs || !(int)$userID) return [];
    reservationLogEnsureTable();
    $res = \Bitrix\Main\Application::getConnection()->query(
        "SELECT DISTINCT PRODUCT_ID FROM " . RES_LOG_TABLE . "
         WHERE USER_ID = " . (int)$userID . "
           AND DEAL_ID <> " . (int)$dealID . "
           AND PRODUCT_ID IN (" . implode(",", $productIDs) . ")"
    );
    $found = [];
    while ($r = $res->fetch()) $found[] = (int)$r["PRODUCT_ID"];
    return $found;
}

// დილზე მიბმული ბინები, რომლებიც ამ მომხმარებელს სხვა დილზე უკვე ჰქონდა დაჯავშნილი
// (დილი იყო რეზერვაციის ეტაპზე, როცა პასუხისმგებელი ეს მომხმარებელი იყო) - მხოლოდ არასტანდარტული ჯავშანი
// აბრუნებს ბინების სახელებს (ცარიელი მასივი = შეზღუდვა არ არის)
function apartmentsReservedBeforeByUser($dealID, $userID) {
    $dealID = (int)$dealID;
    $userID = (int)$userID;
    if (!$dealID || !$userID) return [];

    // ამ დილის ბინები
    $names = [];
    foreach ((array)CCrmDeal::LoadProductRows($dealID) as $row) {
        $pid = (int)$row["PRODUCT_ID"];
        if ($pid) $names[$pid] = $row["PRODUCT_NAME"] ?: ("ID " . $pid);
    }
    if (!$names) return [];

    $result = [];

    // 1. ჯავშნების ჟურნალი (მუშაობს, თუნდაც წაგებულ დილს ბინა მოხსნილი ჰქონდეს)
    foreach (reservationLogFind($dealID, $userID, array_keys($names)) as $pid) {
        $result[$pid] = $names[$pid];
    }

    // 2. ძველი ჯავშნები (ჟურნალამდე): სხვა დილები, რომლებზეც იგივე ბინა ჯერ კიდევ მიბმულია
    $productsByDeal = [];
    $rows = \Bitrix\Crm\ProductRowTable::getList([
        "select" => ["OWNER_ID", "PRODUCT_ID"],
        "filter" => [
            "=OWNER_TYPE" => "D",
            "@PRODUCT_ID" => array_keys($names),
            "!=OWNER_ID"  => $dealID,
        ],
    ]);
    while ($r = $rows->fetch()) {
        $productsByDeal[(int)$r["OWNER_ID"]][] = (int)$r["PRODUCT_ID"];
    }
    if (!$productsByDeal) return array_values($result);

    // რომელიმე მათგანი იყო თუ არა რეზერვაციის ეტაპზე ამ მომხმარებლის პასუხისმგებლობით
    $history = \Bitrix\Crm\History\Entity\DealStageHistoryTable::getList([
        "select" => ["OWNER_ID"],
        "filter" => [
            "@OWNER_ID"       => array_keys($productsByDeal),
            "@STAGE_ID"       => ["PREPAYMENT_INVOICE", "FINAL_INVOICE"],
            "=RESPONSIBLE_ID" => $userID,
        ],
    ]);
    while ($h = $history->fetch()) {
        foreach ($productsByDeal[(int)$h["OWNER_ID"]] ?? [] as $pid) {
            $result[$pid] = $names[$pid];
        }
    }
    return array_values($result);
}

/** Absolute public URL with encoded filename (spaces, (), etc.) */
function buildPassportFileLink($fileId) {
    $path = CFile::GetPath($fileId);
    if (!$path) {
        return '';
    }
    $parts = explode('/', $path);
    $encoded = array_map(function ($part) {
        return $part === '' ? '' : rawurlencode($part);
    }, $parts);
    $host = preg_replace('/:\d+$/', '', $_SERVER["HTTP_HOST"]);
    return "https://" . $host . implode('/', $encoded);
}

global $USER;
$currentUserId = (is_object($USER) && $USER->IsAuthorized()) ? (int)$USER->GetID() : 0;

// ── ჯავშნის ფორმის გახსნისას: შეუძლია თუ არა სტანდარტული ჯავშანი ──
if (($_GET['action'] ?? '') === 'checkStandard') {
    $checkDealId = (int)($_GET['deal_id'] ?? 0);
    try {
        $response = [
            "status"           => 200,
            "repeatApartments" => apartmentsReservedBeforeByUser($checkDealId, $currentUserId),
            "hadConfirmed"     => $checkDealId ? dealHadConfirmedReservation($checkDealId) : false,
        ];
    } catch (\Throwable $e) {
        $response = ["status" => 500, "message" => $e->getMessage(), "repeatApartments" => []];
    }
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

$userSelect      = $_POST['userSelect']  ?? '';
$dealId          = $_POST['deal_id']     ?? '';
$comment         = $_POST['comment']     ?? '';
$prodNum         = $_POST['prodNum']     ?? '';
$passportFileId  = $_POST['passport'] ?? null;

// ბინის გარეშე ჯავშანი არ შეიძლება
if ($dealId && !MonolithApartmentStageGuard::dealHasApartment($dealId)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => 409, "message" => MonolithApartmentStageGuard::MESSAGE], JSON_UNESCAPED_UNICODE);
    exit;
}

// დილზე დარჩენილი ბინა ამასობაში სხვამ დაჯავშნა - ჯავშანი არ უნდა წავიდეს (BP 7 მფლობელს გადააწერდა)
$busyApartments = $dealId ? MonolithApartmentStageGuard::apartmentsHeldByOthers($dealId) : [];
if ($busyApartments) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => 409, "message" => MonolithApartmentStageGuard::busyMessage($busyApartments)], JSON_UNESCAPED_UNICODE);
    exit;
}

// სტანდარტული (უფასო) ჯავშანი დადასტურებული რეზერვაციის შემდეგ აღარ შეიძლება
if ($userSelect == '41' && $dealId && dealHadConfirmedReservation($dealId)) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => 403, "message" => "სტანდარტული რეზერვაცია ამ დილზე აღარ არის ხელმისაწვდომი"], JSON_UNESCAPED_UNICODE);
    exit;
}

// ბინა ამ მომხმარებელს უკვე ჰქონდა დაჯავშნილი სხვა დილზე:
//   - სტანდარტული ჯავშანი აღარ შეიძლება
//   - არასტანდარტული ჯავშანი მიდის BP 100-ზე (ნაცვლად BP 6-ისა)
$repeatApartments = [];
if ($dealId) {
    try {
        $repeatApartments = apartmentsReservedBeforeByUser($dealId, $currentUserId);
    } catch (\Throwable $e) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["status" => 500, "message" => "შემოწმების შეცდომა: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($userSelect == '41' && $repeatApartments) {
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "status"  => 403,
            "message" => "სტანდარტული რეზერვაცია მიუწვდომელია: მოცემული უძრავი ქონება უკვე იყო თქვენს მიერ დაჯავშნილი. შესაძლებელია მხოლოდ არასტანდარტული რეზერვაცია.",
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
$filePath        = $passportFileId ? $_SERVER["DOCUMENT_ROOT"] . CFile::GetPath($passportFileId) : null;

$firstName  = trim($_POST['firstName']   ?? '');
$lastName   = trim($_POST['lastName']    ?? '');
$phone      = trim($_POST['phone']       ?? '');
$commLang   = intval($_POST['commLanguage'] ?? 0);   // საკომუნიკაციო ენა - სიის მნიშვნელობის ID
$contactId  = intval($_POST['contact_id'] ?? 0);

// საკომუნიკაციო ენა სავალდებულოა
if (!$commLang) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => 400, "message" => "აირჩიეთ საკომუნიკაციო ენა"], JSON_UNESCAPED_UNICODE);
    exit;
}

// fallback: resolve contact from deal if not passed
if (!$contactId && $dealId) {
    $contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($dealId);
    $contactId  = intval($contactIds[0] ?? 0);
}

list($reserveDate, $onlyDate) = getCorrectDateRA($_POST['reserveDate'] ?? '');

$params = array(
    "type"        => $userSelect,
    "numOfAps"    => $prodNum,
    "reserveDate" => $reserveDate,
    "onlyDate"    => $onlyDate,
    "comment"     => $comment,
    "firstName"   => $firstName,
    "lastName"    => $lastName,
    "passportFile"     => $filePath ? CFile::MakeFileArray($filePath) : '',
    "passportFileLink" => $passportFileId ? buildPassportFileLink($passportFileId) : '',
);

// ── Update contact: NAME, LAST_NAME, PHONE, communication language, passport file ──
$contactUpdateLog = "no contact update\n";
if ($contactId > 0) {
    $contactFields = [];

    if ($firstName !== '') $contactFields['NAME']      = $firstName;
    if ($lastName  !== '') $contactFields['LAST_NAME'] = $lastName;
    if ($commLang)          $contactFields['UF_CRM_1795509844']   = $commLang;   // საკომუნიკაციო ენა (სია)

    // Passport file
    if ($passportFileId && $filePath) {
        $contactFields['UF_CRM_1779873020955'] = CFile::MakeFileArray($filePath);
    }

    // Phone — only update if a value was provided
    if ($phone !== '') {
        // fetch existing phone row to get its ID so we update rather than duplicate
        $existingPhone = \CCrmFieldMulti::GetList(
            [],
            ['ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'PHONE', 'ELEMENT_ID' => $contactId]
        )->Fetch();

        if ($existingPhone) {
            $contactFields['FM'] = [
                'PHONE' => [
                    $existingPhone['ID'] => [
                        'ID'         => $existingPhone['ID'],
                        'VALUE'      => $phone,
                        'VALUE_TYPE' => $existingPhone['VALUE_TYPE'] ?: 'WORK',
                    ]
                ]
            ];
        } else {
            $contactFields['FM'] = [
                'PHONE' => [
                    'n0' => ['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']
                ]
            ];
        }
    }

    if (!empty($contactFields)) {
        $contactObj = new CCrmContact(false);
        $updateResult = $contactObj->Update($contactId, $contactFields);
        $contactUpdateLog = "contact update result: " . var_export($updateResult, true) . "\n"
            . ($updateResult ? "" : "contact update error: " . $contactObj->LAST_ERROR . "\n")
            . "fields: " . print_r($contactFields, true) . "\n";
    }
}

// ── Date formatting ──
$fullFormat  = CSite::GetDateFormat('FULL');
$shortFormat = CSite::GetDateFormat('SHORT');

$todayForBitrix = CDatabase::FormatDate(
    date('Y-m-d H:i:s'),
    'YYYY-MM-DD HH:MI:SS',
    $fullFormat
);

$reserveDateForBitrix = '';
if ($onlyDate) {
    $parts = explode("/", $onlyDate);
    if (count($parts) === 3) {
        list($day, $month, $year) = $parts;
        $isoDate = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
        $reserveDateForBitrix = CDatabase::FormatDate($isoDate, 'YYYY-MM-DD', $shortFormat);
    }
}

// ── Deal update ──
$arrForAdd = [
    'UF_CRM_1779278640735' => $userSelect,
    'UF_CRM_1779278590201' => $todayForBitrix,
    'UF_CRM_1779278567041' => $reserveDateForBitrix,
    'UF_CRM_1781181993925' => $comment,
];

$Deal = new CCrmDeal();
$Deal->Update($dealId, $arrForAdd);

// ── Start workflow ──
// ხელახალი ჯავშანი იმავე მომხმარებლისგან (მხოლოდ არასტანდარტული) - BP 100, სხვა შემთხვევაში BP 6
$workflowTemplateId = $repeatApartments ? 100 : 6;

$arErrorsTmp = array();
$wfId = CBPDocument::StartWorkflow(
    $workflowTemplateId,
    array("crm", "CCrmDocumentDeal", "DEAL_$dealId"),
    $params,
    $arErrorsTmp
);

// ჯავშანი წავიდა - ჟურნალში ჩაწერა (შეცდომამ ჯავშანი არ უნდა ჩაშალოს)
$resLogError = "";
if ($wfId) {
    try {
        reservationLogAdd($dealId);
    } catch (\Throwable $e) {
        $resLogError = $e->getMessage();
    }
}

file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/saveres_errors.txt",
    "reservationLog error: " . $resLogError . "\n" .
    "dealId: " . $dealId . "\n" .
    "contactId: " . $contactId . "\n" .
    "workflowTemplateId: " . $workflowTemplateId . "\n" .
    "repeatApartments: " . implode(", ", $repeatApartments) . "\n" .
    "wfId: " . var_export($wfId, true) . "\n" .
    $contactUpdateLog .
    "params: " . print_r($params, true) . "\n" .
    "errors: " . print_r($arErrorsTmp, true) . "\n" .
    "POST: " . print_r($_POST, true) . "\n" .
    "passportFileId: " . var_export($passportFileId, true) . "\n"
);

// ── Response ──
$resArr = array();
if (!empty($dealId) && is_numeric($dealId)) {
    $resArr["status"]  = 200;
    $resArr["message"] = "Sent successfully";
    if (!$wfId) {
        $resArr["wf_warning"] = "Workflow may not have started";
        $resArr["wf_errors"]  = $arErrorsTmp;
    }
} else {
    $resArr["status"]  = 405;
    $resArr["message"] = "Method not Found";
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resArr, JSON_UNESCAPED_UNICODE);
?>