<?php
/**
 * Dailo — დილების ბაზა (ზარები / ჩატები / სტადიის ისტორია)
 *
 * URL:     https://crm.monolith.ge/rest/public/dealBase.php
 * Method:  GET
 * Auth:    Authorization: Bearer MonolithDailoDealBase2026
 *
 * Query:
 *   startDate  — d/m/Y ან Y-m-d (default: დღეს − 7 დღე)
 *   endDate    — d/m/Y ან Y-m-d (default: დღეს)
 *
 * მაგალითი W2: tetri-kvadrati/rest/public/dealBase.php
 */

ob_start();
define("STOP_STATISTICS",       true);
define("NO_KEEP_STATISTIC",     "Y");
define("NO_AGENT_STATISTIC",    "Y");
define("NO_AGENT_CHECK",        true);
define("DisableEventsCheck",    true);
define("NOT_CHECK_PERMISSIONS", true);

const DEAL_BASE_API_TOKEN = 'MonolithDailoDealBase2026';

// ── Auth ────────────────────────────────────────────────────────────────

function dealBaseGetRequestToken()
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['HTTP_X_API_TOKEN'] ?? '',
        $_GET['token'] ?? '',
    ];

    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            $key = strtolower((string)$name);
            if ($key === 'authorization' || $key === 'x-api-token') {
                $candidates[] = $value;
            }
        }
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            $key = strtolower((string)$name);
            if ($key === 'authorization' || $key === 'x-api-token') {
                $candidates[] = $value;
            }
        }
    }

    foreach ($candidates as $raw) {
        $raw = trim((string)$raw);
        if ($raw === '') {
            continue;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $raw, $m)) {
            return $m[1];
        }
        return $raw;
    }

    return '';
}

function dealBaseJsonExit($payload, $httpCode = 200)
{
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (dealBaseGetRequestToken() !== DEAL_BASE_API_TOKEN) {
    dealBaseJsonExit(['status' => 401, 'message' => 'Unauthorized - Bearer token is required'], 401);
}

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

CModule::IncludeModule('crm');
\Bitrix\Main\Loader::includeModule('im');

global $USER;
$authorizedUser = false;
if (!$USER->IsAuthorized()) {
    $USER->Authorize(1);
    $authorizedUser = true;
}

// ── Helpers ─────────────────────────────────────────────────────────────

function dealBaseGetDealsByFilter($arFilter)
{
    $arSelect = [
        "ID", "TITLE", "CONTACT_FULL_NAME", "STAGE_ID", "SOURCE_ID",
        "OPPORTUNITY", "CURRENCY_ID", "DATE_CREATE", "CONTACT_ID",
        "ASSIGNED_BY_NAME", "ASSIGNED_BY_LAST_NAME",
    ];
    $res = CCrmDeal::GetListEx(["ID" => "ASC"], $arFilter, false, false, $arSelect);
    $deals = [];
    while ($arDeal = $res->Fetch()) {
        $deals[] = $arDeal;
    }
    return $deals;
}

function dealBaseGetChatsConversations($chatIds)
{
    global $DB;

    $res = [];
    $chatIds = array_map('intval', $chatIds);
    $chatIds = array_filter($chatIds, fn($id) => $id > 0);
    if (empty($chatIds)) {
        return $res;
    }

    $idsStr = implode(',', $chatIds);

    $userMap = [];
    $dbUsers = $DB->Query("SELECT CHAT_ID, USER_ID FROM b_imopenlines_session WHERE CHAT_ID IN ($idsStr)");
    while ($u = $dbUsers->Fetch()) {
        $userMap[(int)$u['CHAT_ID']] = (int)$u['USER_ID'];
    }

    $dbMessages = $DB->Query("
        SELECT CHAT_ID, ID, DATE_CREATE, AUTHOR_ID, MESSAGE
        FROM b_im_message
        WHERE CHAT_ID IN ($idsStr)
        ORDER BY CHAT_ID, DATE_CREATE ASC
    ");

    while ($msg = $dbMessages->Fetch()) {
        $chatId = (int)$msg['CHAT_ID'];
        $clientUserId = $userMap[$chatId] ?? 0;
        $res[$chatId][] = [
            'ID'          => (int)$msg['ID'],
            'DATE_CREATE' => $msg['DATE_CREATE'],
            'AUTHOR_ID'   => (int)$msg['AUTHOR_ID'],
            'ROLE'        => ((int)$msg['AUTHOR_ID'] === $clientUserId) ? 'Client' : 'Operator',
            'MESSAGE'     => $msg['MESSAGE'],
        ];
    }

    return $res;
}

function dealBaseGetSessionsFullInfo($sessionIds)
{
    global $DB;

    $res = [];
    $chatIds = [];

    $sessionIds = array_map('intval', $sessionIds);
    $sessionIds = array_filter($sessionIds, fn($id) => $id > 0);
    if (empty($sessionIds)) {
        return $res;
    }

    $idsStr = implode(',', $sessionIds);
    $dbRes = $DB->Query("SELECT * FROM b_imopenlines_session WHERE ID IN ($idsStr)");

    while ($row = $dbRes->Fetch()) {
        $sessionId = (int)$row['ID'];
        $chatId = (int)$row['CHAT_ID'];
        $res[$sessionId] = $row;
        if ($chatId > 0) {
            $chatIds[] = $chatId;
        }
    }

    $chatMessages = dealBaseGetChatsConversations($chatIds);

    foreach ($res as $sessionId => &$session) {
        $chatId = (int)$session['CHAT_ID'];
        $session['MESSAGES'] = $chatMessages[$chatId] ?? [];
    }
    unset($session);

    return $res;
}

function dealBaseGetSessionsByDealIds($dealIds)
{
    global $DB;

    $sessionIds = [];
    $dealIds = array_map('intval', $dealIds);
    $dealIds = array_filter($dealIds, fn($id) => $id > 0);
    if (empty($dealIds)) {
        return $sessionIds;
    }

    $idsStr = implode(',', $dealIds);
    $sql = "
        SELECT b.OWNER_ID AS DEAL_ID, s.ID AS SESSION_ID
        FROM b_imopenlines_session s
        INNER JOIN b_crm_act_bind b
            ON s.CRM_ACTIVITY_ID = b.ACTIVITY_ID
        WHERE b.OWNER_TYPE_ID = 2
          AND b.OWNER_ID IN ($idsStr)
    ";

    $dbRes = $DB->Query($sql);
    $sessionIdArray = [];
    $dealSessionMap = [];

    while ($row = $dbRes->Fetch()) {
        $dealId = (int)$row['DEAL_ID'];
        $sessionId = (int)$row['SESSION_ID'];
        $dealSessionMap[$dealId][] = $sessionId;
        $sessionIdArray[] = $sessionId;
    }

    $sessionsFullData = dealBaseGetSessionsFullInfo($sessionIdArray);

    foreach ($dealSessionMap as $dealId => $sessionList) {
        foreach ($sessionList as $sessionId) {
            if (isset($sessionsFullData[$sessionId])) {
                $sessionIds[$dealId][$sessionId] = $sessionsFullData[$sessionId];
            }
        }
    }

    return $sessionIds;
}

function dealBaseRecordingBaseUrl()
{
    $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'crm.monolith.ge');
    $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
    return $scheme . '://' . $host;
}

function dealBaseGetCallsForDeals(array $dealIds)
{
    global $DB;

    $dealIds = array_map('intval', $dealIds);
    $result = array_fill_keys($dealIds, []);

    if (empty($dealIds)) {
        return $result;
    }

    $dealIdsSql = implode(',', $dealIds);
    $sql = "
        SELECT ACTIVITY_ID, OWNER_ID
        FROM b_crm_act_bind
        WHERE OWNER_TYPE_ID = " . (int)\CCrmOwnerType::Deal . "
          AND OWNER_ID IN ($dealIdsSql)
    ";

    $bindingsRes = $DB->Query($sql);
    $bindingMap = [];

    while ($row = $bindingsRes->Fetch()) {
        $aid = (int)$row['ACTIVITY_ID'];
        $did = (int)$row['OWNER_ID'];
        if (!isset($bindingMap[$aid])) {
            $bindingMap[$aid] = [];
        }
        $bindingMap[$aid][] = $did;
    }

    $activityIDs = array_keys($bindingMap);
    if (empty($activityIDs)) {
        return $result;
    }

    $res = \CCrmActivity::GetList(
        ['START_TIME' => 'ASC'],
        [
            'TYPE_ID'           => \CCrmActivityType::Call,
            'ID'                => $activityIDs,
            'CHECK_PERMISSIONS' => 'N',
        ],
        false,
        false,
        [
            'ID',
            'SUBJECT',
            'START_TIME',
            'END_TIME',
            'DESCRIPTION',
            'CREATED_BY',
            'RESPONSIBLE_ID',
            'STORAGE_ELEMENT_IDS',
            'PROVIDER_PARAMS',
        ]
    );

    $baseUrl = dealBaseRecordingBaseUrl();

    while ($activity = $res->Fetch()) {
        $aid = (int)$activity['ID'];
        if (!empty($activity['STORAGE_ELEMENT_IDS'])) {
            $fileIds = unserialize($activity['STORAGE_ELEMENT_IDS']);
            if (is_array($fileIds) && !empty($fileIds)) {
                $fileId = (int)reset($fileIds);
                $activity['RECORDING_FILE_ID'] = $fileId;
                $activity['RECORDING_URL'] = $baseUrl
                    . "/bitrix/tools/disk/focus.php?objectId={$fileId}&cmd=show&action=showObjectInGrid&ncc=1";
                $activity['RECORDING_TYPE'] = 'disk';
            }
        }

        foreach ($bindingMap[$aid] as $did) {
            $result[$did][] = $activity;
        }
    }

    return $result;
}

function dealBaseGetDealStageLogs($dealIds)
{
    if (empty($dealIds) || !is_array($dealIds)) {
        return [];
    }

    $arFilter = [
        'ENTITY_TYPE'       => \CCrmOwnerType::DealName,
        'ENTITY_ID'         => $dealIds,
        'ENTITY_FIELD'      => 'STAGE_ID',
        'CHECK_PERMISSIONS' => 'N',
    ];

    $arSelect = ['ID', 'EVENT_NAME', 'DATE_CREATE', 'USER_ID', 'ENTITY_ID', 'ENTITY_FIELDS'];
    $res = CCrmEvent::GetList(['DATE_CREATE' => 'ASC'], $arFilter, false, false, $arSelect);

    $arStageChanges = [];
    while ($arEvent = $res->Fetch()) {
        $dealId = $arEvent['ENTITY_ID'];
        $arStageChanges[$dealId][] = $arEvent;
    }

    return $arStageChanges;
}

/** Accept Y-m-d or d/m/Y → Bitrix filter date d/m/Y */
function dealBaseFixDate($date)
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $parts = explode('-', $date);
        return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }
    return $date;
}

// ── Main ────────────────────────────────────────────────────────────────

$sourceArr = CCrmStatus::GetStatusList('SOURCE');
$stageArr  = CCrmStatus::GetStatusList('DEAL_STAGE');
$today     = date('Y-m-d');

$startDate = dealBaseFixDate($_GET['startDate'] ?? '');
$endDate   = dealBaseFixDate($_GET['endDate'] ?? '');

if ($startDate === '') {
    $startDate = dealBaseFixDate(date('Y-m-d', strtotime('-1 week', strtotime($today))));
}
if ($endDate === '') {
    $endDate = dealBaseFixDate($today);
}

$arFilter = [
    'CATEGORY_ID'        => 0,
    'CHECK_PERMISSIONS'  => 'N',
    '>=DATE_CREATE'      => $startDate . ' 00:00:00 am',
    '<=DATE_CREATE'      => $endDate . ' 11:59:00 pm',
];

$deals = dealBaseGetDealsByFilter($arFilter);

$dealIds = [];
foreach ($deals as $deal) {
    $dealIds[] = $deal['ID'];
}

$dealSessions = dealBaseGetSessionsByDealIds($dealIds);
$dealCalls    = dealBaseGetCallsForDeals($dealIds);
$stageLogs    = dealBaseGetDealStageLogs($dealIds);

$tableRes = [];

foreach ($deals as $deal) {
    $dealId = $deal['ID'];

    $res = [
        'dealId'         => $dealId,
        'dealTitle'      => $deal['TITLE'],
        'contactName'    => $deal['CONTACT_FULL_NAME'],
        'responsible'    => trim($deal['ASSIGNED_BY_NAME'] . ' ' . $deal['ASSIGNED_BY_LAST_NAME']),
        'stage'          => $stageArr[$deal['STAGE_ID']] ?? $deal['STAGE_ID'],
        'source'         => $sourceArr[$deal['SOURCE_ID']] ?? $deal['SOURCE_ID'],
        'amount'         => $deal['OPPORTUNITY'] . ' ' . $deal['CURRENCY_ID'],
        'dateCreate'     => $deal['DATE_CREATE'],
        'contactId'      => $deal['CONTACT_ID'],
        'callHistory'    => $dealCalls[$dealId] ?? [],
        'stageHistory'   => [],
        'messageHistory' => [],
    ];

    $dealSession = $dealSessions[$dealId] ?? null;
    if ($dealSession) {
        foreach ($dealSession as $sessionID => $singleSession) {
            foreach ($singleSession['MESSAGES'] as $sessionMessage) {
                if ($sessionMessage['AUTHOR_ID'] > 0) {
                    $sessionMessage['sessionID'] = $sessionID;
                    $sessionMessage['SOURCE'] = $singleSession['SOURCE'] ?? null;
                    $res['messageHistory'][] = $sessionMessage;
                }
            }
        }
    }

    if (!empty($stageLogs[$dealId])) {
        foreach ($stageLogs[$dealId] as $log) {
            $res['stageHistory'][] = [
                'user'       => trim(($log['CREATED_BY_NAME'] ?? '') . ' ' . ($log['CREATED_BY_LAST_NAME'] ?? '')),
                'time'       => $log['DATE_CREATE'],
                'old stage'  => $log['EVENT_TEXT_1'] ?? '',
                'new stage'  => $log['EVENT_TEXT_2'] ?? '',
            ];
        }
    }

    $tableRes[] = $res;
}

if ($authorizedUser) {
    $USER->Logout();
}

dealBaseJsonExit($tableRes);
