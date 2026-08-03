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

// ── Configuration ───────────────────────────────────────────────────────

const LEAD_CATEGORY_ID    = 0;
const LEAD_STAGE_ID       = "NEW";
const LEAD_ASSIGNED_BY_ID = 1;

const F_SOURCE_NAME     = "UF_CRM_1785330209796"; // list: 480 = the page, 481 = campaign name
const F_TOPIC           = "UF_CRM_1785482520";
const F_INTEREST        = "UF_CRM_1785482547";
const F_GENDER_AGE      = "UF_CRM_1785330326014";
const F_MESSAGES        = "UF_CRM_1785332399202";
const F_CREATED_AT      = "UF_CRM_1780473089474"; // date
const F_CONVERSATION_ID = "UF_CRM_1785332437403";

$SOURCE_ID_MAP = [
    "facebook"      => "REPEAT_SALE",
    "fb"            => "REPEAT_SALE",
    "messenger"     => "REPEAT_SALE",
    "fb-messenger"  => "REPEAT_SALE",
    "instagram"     => "WEBFORM",
    "ig"            => "WEBFORM",
    "widget"        => "UC_TDG7W5",
    "whatsapp"      => "CALL",
    "tiktok"        => "UC_GY61WN",
];
const DEFAULT_SOURCE_ID = "UC_WA3ZO7"; // Other

$WORKFLOW_ID_MAP = [
    "REPEAT_SALE" => 777,
    "WEBFORM"     => 888,
    "UC_TDG7W5"   => 999,
    "CALL"        => 111,
    "UC_GY61WN"   => 222,
    "UC_WA3ZO7"   => 333,
];

$SOURCE_NAME_ENUM_MAP = [
    "the page"      => 480,
    "page"          => 480,
    "campaign name" => 481,
    "campaign"      => 481,
];
const SOURCE_NAME_ENUM_OTHER = 482;

// ── Helpers ─────────────────────────────────────────────────────────────

function leadReadInput()
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

function leadPickValue(array $input, array $keys)
{
    foreach ($keys as $key) {
        if (isset($input[$key]) && !is_array($input[$key]) && trim((string)$input[$key]) !== '') {
            return trim((string)$input[$key]);
        }
        if (isset($input[$key]) && is_array($input[$key])) {
            return $input[$key];
        }
    }
    return '';
}

function leadNormalizePhone($phone)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') {
        return '';
    }
    return '+' . $digits;
}

function leadPhoneSearchPart($phone)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    return strlen($digits) > 9 ? substr($digits, -9) : $digits;
}

function leadFindContactByPhone($phone)
{
    $search = leadPhoneSearchPart($phone);
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

function leadGetMultiValues($entityId, $elementId, $typeId)
{
    $values = [];
    $res = \CCrmFieldMulti::GetList(
        [],
        ['ENTITY_ID' => $entityId, 'TYPE_ID' => $typeId, 'ELEMENT_ID' => $elementId]
    );
    while ($row = $res->Fetch()) {
        if (!empty($row['VALUE'])) {
            $values[] = $row['VALUE'];
        }
    }
    return $values;
}

function leadBuildMessages($messages)
{
    if (is_string($messages)) {
        return trim($messages);
    }

    if (!is_array($messages)) {
        return '';
    }

    $lines = [];
    foreach ($messages as $message) {
        if (is_string($message)) {
            $lines[] = trim($message);
            continue;
        }
        if (!is_array($message)) {
            continue;
        }

        $author = (string)($message['role'] ?? $message['author'] ?? $message['from'] ?? $message['sender'] ?? '');
        $text   = (string)($message['text'] ?? $message['message'] ?? $message['content'] ?? $message['body'] ?? '');
        $time   = (string)($message['created_at'] ?? $message['time'] ?? $message['timestamp'] ?? '');

        if (trim($text) === '') {
            continue;
        }

        $line = '';
        if ($time !== '') {
            $line .= '[' . $time . '] ';
        }
        if ($author !== '') {
            $line .= $author . ': ';
        }
        $lines[] = $line . trim($text);
    }

    return implode("\n", $lines);
}

function leadFormatDate($value)
{
    if ($value === '' || $value === null) {
        return '';
    }

    if (is_numeric($value)) {
        $timestamp = (int)$value;
    } else {
        $timestamp = strtotime((string)$value);
    }

    if (!$timestamp) {
        return '';
    }

    return CDatabase::FormatDate(
        date('Y-m-d', $timestamp),
        'YYYY-MM-DD',
        CSite::GetDateFormat('SHORT')
    );
}

function leadResolveSourceId($sourceType)
{
    global $SOURCE_ID_MAP;

    $key = strtolower(trim((string)$sourceType));
    $key = preg_replace('/[\s_]+/', '-', $key);

    return $SOURCE_ID_MAP[$key] ?? DEFAULT_SOURCE_ID;
}

function leadResolveSourceNameEnum($sourceName)
{
    global $SOURCE_NAME_ENUM_MAP;

    $value = trim((string)$sourceName);
    if ($value === '') {
        return '';
    }

    if (ctype_digit($value)) {
        return (int)$value;
    }

    $key = strtolower($value);
    if (isset($SOURCE_NAME_ENUM_MAP[$key])) {
        return $SOURCE_NAME_ENUM_MAP[$key];
    }

    foreach ($SOURCE_NAME_ENUM_MAP as $needle => $enumId) {
        if (strpos($key, $needle) !== false) {
            return $enumId;
        }
    }

    return SOURCE_NAME_ENUM_OTHER;
}

function leadFindDealByConversationId($conversationId)
{
    if ($conversationId === '') {
        return null;
    }

    $res = CCrmDeal::GetListEx(
        ['ID' => 'DESC'],
        [F_CONVERSATION_ID => $conversationId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['nTopCount' => 1],
        [
            'ID',
            'TITLE',
            'SOURCE_ID',
            'CONTACT_ID',
            F_SOURCE_NAME,
            F_TOPIC,
            F_INTEREST,
            F_GENDER_AGE,
            F_MESSAGES,
            F_CREATED_AT,
            F_CONVERSATION_ID,
        ]
    );

    $deal = $res->Fetch();
    return $deal ?: null;
}

function leadIsEmptyValue($value)
{
    if (is_array($value)) {
        return empty($value);
    }
    return trim((string)$value) === '';
}

function leadRespond(array $payload, $httpCode = 200)
{
    ob_end_clean();
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Request ─────────────────────────────────────────────────────────────

$input = leadReadInput();

if (empty($input)) {
    leadRespond(['status' => 400, 'message' => 'Empty or invalid request body'], 400);
}

$firstName      = leadPickValue($input, ['first_name', 'firstName', 'name']);
$lastName       = leadPickValue($input, ['last_name', 'lastName', 'surname']);
$phoneRaw       = leadPickValue($input, ['phone_number', 'phoneNumber', 'phone', 'mobileNum']);
$email          = leadPickValue($input, ['email', 'mail']);
$sourceType     = leadPickValue($input, ['source_type', 'sourceType', 'source']);
$sourceName     = leadPickValue($input, ['source_name', 'sourceName']);
$topic          = leadPickValue($input, ['topic']);
$interest       = leadPickValue($input, ['interest']);
$gender         = leadPickValue($input, ['gender']);
$age            = leadPickValue($input, ['age']);
$genderAge      = leadPickValue($input, ['gender_age', 'genderAge']);
$createdAt      = leadPickValue($input, ['created_at', 'createdAt', 'date']);
$conversationId = leadPickValue($input, ['conversation_id', 'conversationId']);

$messages = leadBuildMessages($input['messages'] ?? ($input['conversation'] ?? ''));

$phone = leadNormalizePhone($phoneRaw);
if ($phone === '') {
    leadRespond(['status' => 400, 'message' => 'phone_number is required'], 400);
}

if ($genderAge === '') {
    $genderAge = trim(implode(', ', array_filter([$gender, $age], function ($part) {
        return trim((string)$part) !== '';
    })));
}

$sourceId       = leadResolveSourceId($sourceType);
$sourceNameEnum = leadResolveSourceNameEnum($sourceName);

global $USER;
$authorizedHere = false;
if (!$USER->IsAuthorized()) {
    $USER->Authorize(LEAD_ASSIGNED_BY_ID);
    $authorizedHere = true;
}

// ── Contact: reuse by phone, otherwise create ───────────────────────────

$contactCreated = false;
$contactUpdatedFields = [];
$contactId = leadFindContactByPhone($phone);

if ($contactId > 0) {
    $existing = CCrmContact::GetList(
        ['ID' => 'ASC'],
        ['ID' => $contactId, 'CHECK_PERMISSIONS' => 'N'],
        ['ID', 'NAME', 'LAST_NAME']
    )->Fetch();

    $contactFields = [];

    if ($firstName !== '' && leadIsEmptyValue($existing['NAME'] ?? '')) {
        $contactFields['NAME'] = $firstName;
    }
    if ($lastName !== '' && leadIsEmptyValue($existing['LAST_NAME'] ?? '')) {
        $contactFields['LAST_NAME'] = $lastName;
    }

    if ($email !== '') {
        $existingEmails = leadGetMultiValues('CONTACT', $contactId, 'EMAIL');
        $alreadyThere = false;
        foreach ($existingEmails as $existingEmail) {
            if (strcasecmp(trim($existingEmail), $email) === 0) {
                $alreadyThere = true;
                break;
            }
        }
        if (!$alreadyThere && empty($existingEmails)) {
            $fieldMulti = new CCrmFieldMulti();
            $fieldMulti->Add([
                'ENTITY_ID'  => 'CONTACT',
                'ELEMENT_ID' => $contactId,
                'TYPE_ID'    => 'EMAIL',
                'VALUE_TYPE' => 'WORK',
                'VALUE'      => $email,
            ]);
            $contactUpdatedFields[] = 'EMAIL';
        }
    }

    if (!empty($contactFields)) {
        $contact = new CCrmContact(false);
        $contact->Update($contactId, $contactFields, true, true, [
            'DISABLE_USER_FIELD_CHECK' => true,
            'CURRENT_USER'             => LEAD_ASSIGNED_BY_ID,
        ]);
        $contactUpdatedFields = array_merge($contactUpdatedFields, array_keys($contactFields));
    }
} else {
    $contactFields = [
        'NAME'           => $firstName,
        'LAST_NAME'      => $lastName,
        'ASSIGNED_BY_ID' => LEAD_ASSIGNED_BY_ID,
        'OPENED'         => 'Y',
        'FM'             => [
            'PHONE' => [
                'n0' => ['VALUE' => $phone, 'VALUE_TYPE' => 'WORK'],
            ],
        ],
    ];

    if ($email !== '') {
        $contactFields['FM']['EMAIL'] = [
            'n0' => ['VALUE' => $email, 'VALUE_TYPE' => 'WORK'],
        ];
    }

    $contact = new CCrmContact(false);
    $contactId = $contact->Add($contactFields, true, [
        'DISABLE_USER_FIELD_CHECK' => true,
        'REGISTER_SONET_EVENT'     => true,
        'CURRENT_USER'             => LEAD_ASSIGNED_BY_ID,
    ]);

    if (!$contactId) {
        if ($authorizedHere) {
            $USER->Logout();
        }
        leadRespond([
            'status'  => 500,
            'message' => 'Failed to create contact: ' . $contact->LAST_ERROR,
        ], 500);
    }

    $contactCreated = true;
}

// ── Deal: update existing one for this conversation, otherwise create ───

$dealValues = [
    F_SOURCE_NAME     => $sourceNameEnum,
    F_TOPIC           => $topic,
    F_INTEREST        => $interest,
    F_GENDER_AGE      => $genderAge,
    F_MESSAGES        => $messages,
    F_CREATED_AT      => leadFormatDate($createdAt),
    F_CONVERSATION_ID => $conversationId,
];

$existingDeal = leadFindDealByConversationId($conversationId);

if ($existingDeal) {
    $dealId = (int)$existingDeal['ID'];
    $dealUpdate = [];

    foreach ($dealValues as $code => $value) {
        if (!leadIsEmptyValue($value) && leadIsEmptyValue($existingDeal[$code] ?? '')) {
            $dealUpdate[$code] = $value;
        }
    }

    if (leadIsEmptyValue($existingDeal['SOURCE_ID'] ?? '')) {
        $dealUpdate['SOURCE_ID'] = $sourceId;
    }

    if (empty($existingDeal['CONTACT_ID']) && $contactId > 0) {
        $dealUpdate['CONTACT_ID'] = $contactId;
    }

    if (!empty($dealUpdate)) {
        $deal = new CCrmDeal(false);
        $deal->Update($dealId, $dealUpdate, true, true, [
            'DISABLE_USER_FIELD_CHECK' => true,
            'CURRENT_USER'             => LEAD_ASSIGNED_BY_ID,
        ]);
    }

    if ($authorizedHere) {
        $USER->Logout();
    }

    leadRespond([
        'status'               => 200,
        'message'              => 'Deal already exists, existing record updated',
        'created'              => false,
        'dealId'               => $dealId,
        'contactId'            => (int)$contactId,
        'contactCreated'       => $contactCreated,
        'updatedDealFields'    => array_keys($dealUpdate),
        'updatedContactFields' => $contactUpdatedFields,
    ]);
}

$dealTitle = trim($firstName . ' ' . $lastName);
if ($dealTitle === '') {
    $dealTitle = $phone;
}
$dealTitle = 'Dailo Lead — ' . $dealTitle;

$dealFields = array_merge($dealValues, [
    'TITLE'          => $dealTitle,
    'CATEGORY_ID'    => LEAD_CATEGORY_ID,
    'STAGE_ID'       => LEAD_STAGE_ID,
    'SOURCE_ID'      => $sourceId,
    'CONTACT_ID'     => $contactId,
    'ASSIGNED_BY_ID' => LEAD_ASSIGNED_BY_ID,
    'OPENED'         => 'Y',
]);

$deal = new CCrmDeal(false);
$dealId = $deal->Add($dealFields, true, [
    'DISABLE_USER_FIELD_CHECK' => true,
    'REGISTER_SONET_EVENT'     => true,
    'CURRENT_USER'             => LEAD_ASSIGNED_BY_ID,
]);

if (!is_numeric($dealId) || $dealId <= 0) {
    if ($authorizedHere) {
        $USER->Logout();
    }
    leadRespond([
        'status'    => 500,
        'message'   => 'Failed to create deal: ' . $deal->LAST_ERROR,
        'contactId' => (int)$contactId,
    ], 500);
}

// ── Start the workflow matching the lead source ─────────────────────────

// $workflowId = $WORKFLOW_ID_MAP[$sourceId] ?? $WORKFLOW_ID_MAP[DEFAULT_SOURCE_ID];
$workflowId = 86;
$workflowErrors = [];
$startedWorkflow = null;

try {
    $startedWorkflow = CBPDocument::StartWorkflow(
        $workflowId,
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
    'message'        => 'OK',
    'dealCreated'    => true,
    'contactCreated' => $contactCreated,
    'dealId'         => (int)$dealId,
    'contactId'      => (int)$contactId,
    
    // 'sourceId'       => $sourceId,
    // 'workflowId'     => $workflowId,
];

// if (!$startedWorkflow) {
//     $response['workflowWarning'] = 'Workflow may not have started';
//     $response['workflowErrors']  = $workflowErrors;
// }

leadRespond($response);
