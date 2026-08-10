<?php
ob_start();
define("STOP_STATISTICS",       true);
define("NO_KEEP_STATISTIC",     "Y");
define("NO_AGENT_STATISTIC",    "Y");
define("NO_AGENT_CHECK",        true);
define("DisableEventsCheck",    true);
define("NOT_CHECK_PERMISSIONS", true);

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

CModule::IncludeModule('crm');
CModule::IncludeModule('bizproc');

const AGENT_LEAD_CATEGORY_ID    = 0;
const AGENT_LEAD_STAGE_ID       = "NEW";
const AGENT_LEAD_ASSIGNED_BY_ID = 1;
const AGENT_LEAD_WORKFLOW_ID    = 86;
const AGENT_LEAD_SOURCE_ID      = "UC_HN9W32";
const AGENT_LEAD_TITLE_PREFIX   = "აგენტის ფორმა — ";

function agentLeadRespond(array $payload, $httpCode = 200)
{
    ob_end_clean();
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    agentLeadRespond(['status' => 200, 'message' => 'OK']);
}

function agentLeadReadInput()
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
        parse_str($raw, $parsed);
        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }
    }

    return [];
}

function agentLeadPick($input, array $keys, $default = '')
{
    foreach ($keys as $key) {
        if (!isset($input[$key])) {
            continue;
        }
        if (is_array($input[$key])) {
            return $input[$key];
        }
        $value = trim((string)$input[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return $default;
}

function agentLeadNormalizePhone($phone)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') {
        return '';
    }
    return '+' . $digits;
}

function agentLeadPhoneSearchPart($phone)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    return strlen($digits) > 9 ? substr($digits, -9) : $digits;
}

function agentLeadCollectPhones($input)
{
    $phones = [];
    $raw = agentLeadPick($input, ['phones', 'phone_numbers', 'phoneNumbers'], []);

    if (is_string($raw) && $raw !== '') {
        $raw = preg_split('/[\n,;]+/', $raw);
    }

    if (!is_array($raw)) {
        $raw = [];
    }

    $single = agentLeadPick($input, ['phone', 'phone_number', 'phoneNumber', 'mobile']);
    if ($single !== '') {
        array_unshift($raw, $single);
    }

    foreach ($raw as $item) {
        if (is_array($item)) {
            $item = $item['value'] ?? $item['phone'] ?? '';
        }
        $normalized = agentLeadNormalizePhone($item);
        if ($normalized === '') {
            continue;
        }
        if (!in_array($normalized, $phones, true)) {
            $phones[] = $normalized;
        }
    }

    return $phones;
}

function agentLeadFindContactByPhone($phone)
{
    $search = agentLeadPhoneSearchPart($phone);
    if ($search === '') {
        return 0;
    }

    $res = \CCrmFieldMulti::GetList(
        [],
        [
            'ENTITY_ID' => 'CONTACT',
            'TYPE_ID'   => 'PHONE',
            '%VALUE'    => $search,
        ]
    );

    while ($row = $res->Fetch()) {
        if (!empty($row['ELEMENT_ID'])) {
            return (int)$row['ELEMENT_ID'];
        }
    }

    return 0;
}

function agentLeadGetPhones($contactId)
{
    $values = [];
    $res = \CCrmFieldMulti::GetList(
        [],
        ['ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'PHONE', 'ELEMENT_ID' => $contactId]
    );
    while ($row = $res->Fetch()) {
        if (!empty($row['VALUE'])) {
            $values[] = agentLeadNormalizePhone($row['VALUE']);
        }
    }
    return array_values(array_filter($values));
}

function agentLeadBuildComments($agency, $agent, $agencyComment)
{
    $lines = [];
    if ($agency !== '') {
        $lines[] = 'სააგენტო: ' . $agency;
    }
    if ($agent !== '') {
        $lines[] = 'აგენტი: ' . $agent;
    }
    if ($agencyComment !== '') {
        $lines[] = 'სააგენტოს კომენტარი: ' . $agencyComment;
    }
    return implode("\n", $lines);
}

function agentLeadBuildCommentsHtml($agency, $agent, $agencyComment)
{
    $text = agentLeadBuildComments($agency, $agent, $agencyComment);
    if ($text === '') {
        return '';
    }
    return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false);
}

function agentLeadAddTimelineComment($dealId, $text, $authorId)
{
    $dealId = (int)$dealId;
    $authorId = (int)$authorId;
    if ($dealId <= 0 || trim((string)$text) === '') {
        return 0;
    }

    $commentId = 0;

    if (class_exists('\Bitrix\Crm\Timeline\CommentEntry')) {
        try {
            $commentId = (int)\Bitrix\Crm\Timeline\CommentEntry::create([
                'TEXT'      => $text,
                'AUTHOR_ID' => $authorId > 0 ? $authorId : 1,
                'BINDINGS'  => [
                    [
                        'ENTITY_TYPE_ID' => \CCrmOwnerType::Deal,
                        'ENTITY_ID'      => $dealId,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $commentId = 0;
        }
    }

    if ($commentId <= 0 && class_exists('\CCrmEvent')) {
        $event = new \CCrmEvent();
        $eventId = (int)$event->Add([
            'ENTITY_TYPE'  => \CCrmOwnerType::DealName,
            'ENTITY_ID'    => $dealId,
            'EVENT_TYPE'   => 1,
            'EVENT_NAME'   => 'აგენტის ფორმა',
            'EVENT_TEXT_1' => $text,
            'USER_ID'      => $authorId > 0 ? $authorId : 1,
        ], false);
        if ($eventId > 0) {
            $commentId = $eventId;
        }
    }

    return $commentId;
}

$input = agentLeadReadInput();
if (empty($input)) {
    agentLeadRespond(['status' => 400, 'message' => 'ცარიელი მოთხოვნა'], 400);
}

$clientName     = agentLeadPick($input, ['client_name', 'clientName', 'full_name', 'fullName', 'name']);
$firstName      = agentLeadPick($input, ['first_name', 'firstName']);
$lastName       = agentLeadPick($input, ['last_name', 'lastName', 'surname']);
$agency         = agentLeadPick($input, ['agency', 'agency_name', 'agencyName']);
$agent          = agentLeadPick($input, ['agent', 'agent_name', 'agentName']);
$agencyComment  = agentLeadPick($input, ['agency_comment', 'agencyComment', 'comment']);
$phones         = agentLeadCollectPhones($input);

if ($clientName !== '' && ($firstName === '' && $lastName === '')) {
    $parts = preg_split('/\s+/u', $clientName, 2);
    $firstName = trim((string)($parts[0] ?? ''));
    $lastName  = trim((string)($parts[1] ?? ''));
}

if ($firstName === '' && $lastName === '' && $clientName === '') {
    agentLeadRespond(['status' => 400, 'message' => 'კლიენტის სახელი/გვარი სავალდებულოა'], 400);
}

if (empty($phones)) {
    agentLeadRespond(['status' => 400, 'message' => 'მიუთითეთ მინიმუმ ერთი ტელეფონის ნომერი'], 400);
}

if ($agency === '' || $agent === '') {
    agentLeadRespond(['status' => 400, 'message' => 'სააგენტო და აგენტი სავალდებულოა'], 400);
}

$displayName = trim($firstName . ' ' . $lastName);
if ($displayName === '') {
    $displayName = $clientName;
}

$comments = agentLeadBuildComments($agency, $agent, $agencyComment);
$commentsHtml = agentLeadBuildCommentsHtml($agency, $agent, $agencyComment);

global $USER;
$authorizedHere = false;
if (!$USER->IsAuthorized()) {
    $USER->Authorize(AGENT_LEAD_ASSIGNED_BY_ID);
    $authorizedHere = true;
}

$contactCreated = false;
$contactId = 0;
foreach ($phones as $phone) {
    $contactId = agentLeadFindContactByPhone($phone);
    if ($contactId > 0) {
        break;
    }
}

if ($contactId > 0) {
    $existing = CCrmContact::GetList(
        ['ID' => 'ASC'],
        ['ID' => $contactId, 'CHECK_PERMISSIONS' => 'N'],
        ['ID', 'NAME', 'LAST_NAME']
    )->Fetch();

    $contactFields = [];
    if ($firstName !== '' && trim((string)($existing['NAME'] ?? '')) === '') {
        $contactFields['NAME'] = $firstName;
    }
    if ($lastName !== '' && trim((string)($existing['LAST_NAME'] ?? '')) === '') {
        $contactFields['LAST_NAME'] = $lastName;
    }

    if (!empty($contactFields)) {
        $contact = new CCrmContact(false);
        $contact->Update($contactId, $contactFields, true, true, [
            'DISABLE_USER_FIELD_CHECK' => true,
            'CURRENT_USER'             => AGENT_LEAD_ASSIGNED_BY_ID,
        ]);
    }

    $existingPhones = agentLeadGetPhones($contactId);
    $fieldMulti = new CCrmFieldMulti();
    $n = 0;
    foreach ($phones as $phone) {
        $already = false;
        foreach ($existingPhones as $existingPhone) {
            if (agentLeadPhoneSearchPart($existingPhone) === agentLeadPhoneSearchPart($phone)) {
                $already = true;
                break;
            }
        }
        if ($already) {
            continue;
        }
        $fieldMulti->Add([
            'ENTITY_ID'  => 'CONTACT',
            'ELEMENT_ID' => $contactId,
            'TYPE_ID'    => 'PHONE',
            'VALUE_TYPE' => $n === 0 ? 'WORK' : 'MOBILE',
            'VALUE'      => $phone,
        ]);
        $n++;
    }
} else {
    $fmPhones = [];
    foreach ($phones as $index => $phone) {
        $fmPhones['n' . $index] = [
            'VALUE'      => $phone,
            'VALUE_TYPE' => $index === 0 ? 'WORK' : 'MOBILE',
        ];
    }

    $contactFields = [
        'NAME'           => $firstName !== '' ? $firstName : $displayName,
        'LAST_NAME'      => $lastName,
        'ASSIGNED_BY_ID' => AGENT_LEAD_ASSIGNED_BY_ID,
        'OPENED'         => 'Y',
        'FM'             => ['PHONE' => $fmPhones],
    ];

    $contact = new CCrmContact(false);
    $contactId = $contact->Add($contactFields, true, [
        'DISABLE_USER_FIELD_CHECK' => true,
        'REGISTER_SONET_EVENT'     => true,
        'CURRENT_USER'             => AGENT_LEAD_ASSIGNED_BY_ID,
    ]);

    if (!$contactId) {
        if ($authorizedHere) {
            $USER->Logout();
        }
        agentLeadRespond([
            'status'  => 500,
            'message' => 'კონტაქტის შექმნა ვერ მოხერხდა: ' . $contact->LAST_ERROR,
        ], 500);
    }

    $contactCreated = true;
}

$dealTitle = AGENT_LEAD_TITLE_PREFIX . ($displayName !== '' ? $displayName : $phones[0]);

$dealFields = [
    'TITLE'          => $dealTitle,
    'CATEGORY_ID'    => AGENT_LEAD_CATEGORY_ID,
    'STAGE_ID'       => AGENT_LEAD_STAGE_ID,
    'SOURCE_ID'      => AGENT_LEAD_SOURCE_ID,
    'CONTACT_ID'     => (int)$contactId,
    'ASSIGNED_BY_ID' => AGENT_LEAD_ASSIGNED_BY_ID,
    'OPENED'         => 'Y',
    'COMMENTS'       => $commentsHtml !== '' ? $commentsHtml : $comments,
];

$deal = new CCrmDeal(false);
$dealId = $deal->Add($dealFields, true, [
    'DISABLE_USER_FIELD_CHECK' => true,
    'REGISTER_SONET_EVENT'     => true,
    'CURRENT_USER'             => AGENT_LEAD_ASSIGNED_BY_ID,
]);

if (!is_numeric($dealId) || $dealId <= 0) {
    if ($authorizedHere) {
        $USER->Logout();
    }
    agentLeadRespond([
        'status'    => 500,
        'message'   => 'დილის შექმნა ვერ მოხერხდა: ' . $deal->LAST_ERROR,
        'contactId' => (int)$contactId,
    ], 500);
}

// ტაიმლაინის კომენტარი — UI-ში ეს ჩანს, არა მხოლოდ COMMENTS ველი
$timelineCommentId = agentLeadAddTimelineComment(
    (int)$dealId,
    $comments,
    AGENT_LEAD_ASSIGNED_BY_ID
);

// თუ Add-ზე COMMENTS არ ჩაჯდა, განახლებით მეორედ ვცდილობთ
if ($commentsHtml !== '') {
    $dealCheck = CCrmDeal::GetListEx(
        [],
        ['ID' => (int)$dealId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['nTopCount' => 1],
        ['ID', 'COMMENTS']
    )->Fetch();

    $existingComments = trim((string)($dealCheck['COMMENTS'] ?? ''));
    if ($existingComments === '') {
        $dealUpdateFields = ['COMMENTS' => $commentsHtml];
        $dealUpdater = new CCrmDeal(false);
        $dealUpdater->Update((int)$dealId, $dealUpdateFields, true, true, [
            'DISABLE_USER_FIELD_CHECK' => true,
            'CURRENT_USER'             => AGENT_LEAD_ASSIGNED_BY_ID,
        ]);
    }
}

$workflowErrors = [];
$startedWorkflow = null;

try {
    $startedWorkflow = CBPDocument::StartWorkflow(
        AGENT_LEAD_WORKFLOW_ID,
        ['crm', 'CCrmDocumentDeal', 'DEAL_' . $dealId],
        [],
        $workflowErrors
    );
} catch (Exception $e) {
    $workflowErrors[] = $e->getMessage();
}

if ($authorizedHere) {
    $USER->Logout();
}

$response = [
    'status'         => 200,
    'message'        => 'დილი წარმატებით შეიქმნა',
    'dealCreated'    => true,
    'contactCreated' => $contactCreated,
    'dealId'         => (int)$dealId,
    'contactId'      => (int)$contactId,
    'dealTitle'      => $dealTitle,
    'workflowId'     => AGENT_LEAD_WORKFLOW_ID,
    'commentId'      => (int)$timelineCommentId,
];

if (!$startedWorkflow) {
    $response['workflowWarning'] = 'პროცესი შესაძლოა არ გაეშვა';
    $response['workflowErrors']  = $workflowErrors;
}

agentLeadRespond($response);
