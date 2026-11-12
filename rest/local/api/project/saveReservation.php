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

    // სხვა დილები, რომლებზეც იგივე ბინა იყო მიბმული
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
    if (!$productsByDeal) return [];

    // რომელიმე მათგანი იყო თუ არა რეზერვაციის ეტაპზე ამ მომხმარებლის პასუხისმგებლობით
    $result = [];
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

// ── rs.ge: პირადი ნომრით სახელის გადამოწმება ──
const RS_SU = 'FMGSOFT:12345678910';
const RS_SP = '123456';

// აბრუნებს: სახელს (string), '' თუ ვერ მოიძებნა, null თუ სერვისი მიუწვდომელია
function rsGetNameByPersonalId($id) {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
        <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
          <soap:Body>
            <get_name_from_tin xmlns="http://tempuri.org/">
              <su>' . htmlspecialchars(RS_SU, ENT_XML1) . '</su>
              <sp>' . htmlspecialchars(RS_SP, ENT_XML1) . '</sp>
              <tin>' . htmlspecialchars((string)$id, ENT_XML1) . '</tin>
            </get_name_from_tin>
          </soap:Body>
        </soap:Envelope>';

    // Bitrix-ის HTTP კლიენტი - სერვერზე curl გაფართოება არ არის
    $http = new \Bitrix\Main\Web\HttpClient([
        "socketTimeout" => 5,
        "streamTimeout" => 10,
    ]);
    $http->setHeader("Content-Type", "text/xml; charset=utf-8");
    $output = $http->post("https://services.rs.ge/WayBillService/WayBillService.asmx", $xml);
    $noResult = strpos((string)$output, 'get_name_from_tinResult') === false;
    if ($output === false || $output === '' || ((int)$http->getStatus() >= 500 && $noResult)) {
        return null;
    }

    $doc = new DOMDocument;
    if (!@$doc->loadXML($output)) return null;
    $node = $doc->getElementsByTagName('get_name_from_tinResult')->item(0);
    return $node ? trim($node->nodeValue) : '';
}

// სიტყვებად დაშლა: პატარა ასოები, წერტილები/ტირეები -> ჰარი
function nameWords($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = preg_replace('/[^\p{L}\s]+/u', ' ', $s);
    return preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
}

// სახელის და გვარის ყველა სიტყვა უნდა დაემთხვეს rs.ge-ს პასუხს
// (სრულად, ან ინიციალით, თუ rs.ge ინიციალს აბრუნებს: „გ. მაისურაძე“)
function nameMatchesRs($firstName, $lastName, $rsName) {
    $rsWords = nameWords($rsName);
    $ourWords = array_merge(nameWords($firstName), nameWords($lastName));
    if (!$rsWords || !$ourWords) return false;

    foreach ($ourWords as $word) {
        $found = false;
        foreach ($rsWords as $i => $rsWord) {
            $isInitial = mb_strlen($rsWord, 'UTF-8') === 1;
            if ($rsWord === $word || ($isInitial && $rsWord === mb_substr($word, 0, 1, 'UTF-8'))) {
                unset($rsWords[$i]);   // ერთი სიტყვა ორჯერ არ ითვლება
                $found = true;
                break;
            }
        }
        if (!$found) return false;
    }
    return true;
}

// ყოველთვის მოწმდება rs.ge-ზე, ნომრის სიგრძის მიუხედავად
// აბრუნებს ['ok' => bool, 'rsName' => string, 'message' => string]
function verifyClientIdentity($firstName, $lastName, $idNumber) {
    $idNumber = trim((string)$idNumber);
    if ($idNumber === '') {
        return ["ok" => false, "rsName" => "", "message" => "პირადი ნომერი სავალდებულოა"];
    }
    $rsName = rsGetNameByPersonalId($idNumber);
    if ($rsName === null) {
        return ["ok" => false, "rsName" => "",
                "message" => "პირადი ნომრის გადამოწმება rs.ge-ზე ვერ მოხერხდა, სცადეთ თავიდან"];
    }
    if ($rsName === '' || !nameWords($rsName)) {
        return ["ok" => false, "rsName" => "",
                "message" => "პირადი ნომერი არასწორია"];
    }
    if (!nameMatchesRs($firstName, $lastName, $rsName)) {
        return ["ok" => false, "rsName" => $rsName,
                "message" => "პირადი ნომერი არასწორია"];
    }
    return ["ok" => true, "rsName" => $rsName, "message" => ""];
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

// ── ფორმაში პირადი ნომრის ცოცხალი შემოწმება (rs.ge) ──
if (($_GET['action'] ?? '') === 'checkPersonalId') {
    try {
        $check = verifyClientIdentity(
            $_GET['firstName'] ?? '',
            $_GET['lastName']  ?? '',
            trim($_GET['id']   ?? '')
        );
        // rs.ge-ს სახელი ბრაუზერში არ იგზავნება
        $response = ["status" => 200, "ok" => $check["ok"], "message" => $check["message"]];
    } catch (\Throwable $e) {
        $response = ["status" => 200, "ok" => false, "rsName" => "", "message" => "გადამოწმების შეცდომა: " . $e->getMessage()];
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
$idNumber   = trim($_POST['idNumber']    ?? '');
$phone      = trim($_POST['phone']       ?? '');
$contactId  = intval($_POST['contact_id'] ?? 0);

// პირადი ნომერი სავალდებულოა და სახელი/გვარი უნდა ემთხვეოდეს rs.ge-ს - სანამ კონტაქტი ან დილი შეიცვლება
if ($idNumber === '') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => 400, "message" => "პირადი ნომერი სავალდებულოა"], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $identity = verifyClientIdentity($firstName, $lastName, $idNumber);
} catch (\Throwable $e) {
    $identity = ["ok" => false, "rsName" => "", "message" => "პირადი ნომრის გადამოწმების შეცდომა: " . $e->getMessage()];
}
if (!$identity["ok"]) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["status" => 403, "message" => $identity["message"]], JSON_UNESCAPED_UNICODE);
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
    "idNumber"    => $idNumber,
    "passportFile"     => $filePath ? CFile::MakeFileArray($filePath) : '',
    "passportFileLink" => $passportFileId ? buildPassportFileLink($passportFileId) : '',
);

// ── Update contact: NAME, LAST_NAME, ID number, PHONE, passport file ──
$contactUpdateLog = "no contact update\n";
if ($contactId > 0) {
    $contactFields = [];

    if ($firstName !== '') $contactFields['NAME']      = $firstName;
    if ($lastName  !== '') $contactFields['LAST_NAME'] = $lastName;
    if ($idNumber  !== '') $contactFields['UF_CRM_1781244744534'] = $idNumber;

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

file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/saveres_errors.txt",
    "dealId: " . $dealId . "\n" .
    "contactId: " . $contactId . "\n" .
    "rs.ge name: " . $identity["rsName"] . "\n" .
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