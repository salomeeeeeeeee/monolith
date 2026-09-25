<?php
/**
 * Dailo - დღიური სტატისტიკის მიღება (conversations / leads / comments)
 *
 * URL:     https://crm.monolith.ge/rest/public/addStats.php
 * Method:  POST, Content-Type: application/json
 * Auth:    Authorization: Bearer <STATS_API_TOKEN>   ან   X-Api-Token: <STATS_API_TOKEN>
 *
 * სიები ერთხელ იქმნება აქ:
 *   https://crm.monolith.ge/custom/setup/dailoStatsLists.php
 *
 * მონაცემი ორ სიაში ჯდება:
 *   DAILO_STATS_DAILY   - 1 ჩანაწერი = 1 დღე (totals + comments)
 *   DAILO_STATS_CHANNEL - 1 ჩანაწერი = დღე + არხი
 *
 * ერთი და იმავე თარიღის ხელახლა გამოგზავნა არსებულ ჩანაწერს აახლებს და არ
 * ამრავლებს (გასაღები - XML_ID), რომ რეპორტში ორმაგად არ დაითვალოს.
 * მიიღება როგორც ერთი დღე {...}, ისე დღეების მასივი [{...},{...}] (backfill-ისთვის).
 */

ob_start();
define("STOP_STATISTICS",       true);
define("NO_KEEP_STATISTIC",     "Y");
define("NO_AGENT_STATISTIC",    "Y");
define("NO_AGENT_CHECK",        true);
define("DisableEventsCheck",    true);
define("NOT_CHECK_PERMISSIONS", true);

const STATS_API_TOKEN = 'MonolithDailoStats2026';

// ── Auth ────────────────────────────────────────────────────────────────

function statsGetRequestToken()
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['HTTP_X_API_TOKEN'] ?? '',
        $_GET['token'] ?? '',
        $_POST['token'] ?? '',
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

if (statsGetRequestToken() !== STATS_API_TOKEN) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(
        ['status' => 401, 'message' => 'Unauthorized - Bearer token is required'],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

CModule::IncludeModule('iblock');

// ── Configuration ───────────────────────────────────────────────────────

const STATS_DAILY_IBLOCK_CODE   = 'DAILO_STATS_DAILY';
const STATS_CHANNEL_IBLOCK_CODE = 'DAILO_STATS_CHANNEL';
const STATS_AUTHOR_ID           = 1;
const STATS_XML_PREFIX          = 'dailo-stats-';

/** არხის სახელის ნორმალიზაცია - რომ Messenger/fb/fb-messenger ერთ რიგში მოხვდეს. */
$STATS_CHANNEL_MAP = [
    'messenger'    => 'Messenger',
    'fb'           => 'Messenger',
    'facebook'     => 'Messenger',
    'fb-messenger' => 'Messenger',
    'instagram'    => 'Instagram',
    'ig'           => 'Instagram',
    'whatsapp'     => 'WhatsApp',
    'wa'           => 'WhatsApp',
    'widget'       => 'Widget',
    'site'         => 'Widget',
    'chat'         => 'Widget',
    'tiktok'       => 'TikTok',
    'viber'        => 'Viber',
    'telegram'     => 'Telegram',
];

// ── Helpers ─────────────────────────────────────────────────────────────

function statsReadInput()
{
    $raw = file_get_contents('php://input');

    if (is_string($raw) && $raw !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }
    }

    return !empty($_POST) ? $_POST : [];
}

function statsPick(array $input, array $keys)
{
    foreach ($keys as $key) {
        if (isset($input[$key]) && !is_array($input[$key]) && trim((string)$input[$key]) !== '') {
            return trim((string)$input[$key]);
        }
    }
    return '';
}

/** რიცხვი, ან null თუ ველი საერთოდ არ მოსულა (0 ≠ "არ მოსულა"). */
function statsPickInt(array $input, array $keys)
{
    foreach ($keys as $key) {
        if (isset($input[$key]) && !is_array($input[$key]) && trim((string)$input[$key]) !== '') {
            return (int)round((float)str_replace(',', '.', (string)$input[$key]));
        }
    }
    return null;
}

/** ნებისმიერი გონივრული ფორმატი → YYYY-MM-DD (ან '' თუ ვერ გაიშიფრა). */
function statsNormalizeDate($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    $timestamp = is_numeric($value) ? (int)$value : strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : '';
}

function statsNormalizeChannel($name)
{
    global $STATS_CHANNEL_MAP;

    $name = trim((string)$name);
    if ($name === '') {
        return '';
    }

    $key = preg_replace('/[\s_]+/', '-', strtolower($name));

    // უცნობი არხი უცვლელად ინახება - ახალი წყარო არ უნდა დაიკარგოს.
    return $STATS_CHANNEL_MAP[$key] ?? $name;
}

function statsSlug($value)
{
    $slug = function_exists('mb_strtolower') ? mb_strtolower((string)$value, 'UTF-8') : strtolower((string)$value);
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
    $slug = trim((string)$slug, '-');

    return $slug !== '' ? $slug : substr(md5((string)$value), 0, 8);
}

function statsIblockId($code)
{
    static $cache = [];

    if (isset($cache[$code])) {
        return $cache[$code];
    }

    $row = CIBlock::GetList([], ['CODE' => $code, 'CHECK_PERMISSIONS' => 'N'])->Fetch();

    return $cache[$code] = $row ? (int)$row['ID'] : 0;
}

function statsFindByXmlId($iblockId, $xmlId)
{
    $res = CIBlockElement::GetList(
        ['ID' => 'DESC'],
        ['IBLOCK_ID' => $iblockId, '=XML_ID' => $xmlId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['nTopCount' => 1],
        ['ID']
    );

    $row = $res->Fetch();
    return $row ? (int)$row['ID'] : 0;
}

/** არსებობს - აახლებს, არ არსებობს - ქმნის. აბრუნებს ['id' => int, 'created' => bool]. */
function statsUpsert($iblockId, $xmlId, $name, array $properties)
{
    $el = new CIBlockElement();

    $fields = [
        'IBLOCK_ID'       => $iblockId,
        'NAME'            => $name,
        'XML_ID'          => $xmlId,
        'ACTIVE'          => 'Y',
        'MODIFIED_BY'     => STATS_AUTHOR_ID,
        'PROPERTY_VALUES' => $properties,
    ];

    $existingId = statsFindByXmlId($iblockId, $xmlId);

    if ($existingId > 0) {
        if (!$el->Update($existingId, $fields)) {
            return ['id' => 0, 'created' => false, 'error' => $el->LAST_ERROR];
        }
        return ['id' => $existingId, 'created' => false];
    }

    $fields['CREATED_BY'] = STATS_AUTHOR_ID;

    $newId = $el->Add($fields);
    if (!is_numeric($newId) || $newId <= 0) {
        return ['id' => 0, 'created' => false, 'error' => $el->LAST_ERROR];
    }

    return ['id' => (int)$newId, 'created' => true];
}

/** ამ თარიღის არხების ჩანაწერები, რომლებიც ახალ payload-ში აღარ არის - იშლება. */
function statsRemoveStaleChannels($iblockId, $date, array $keepXmlIds)
{
    $removed = 0;

    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        [
            'IBLOCK_ID'          => $iblockId,
            'PROPERTY_STAT_DATE' => $date,
            'CHECK_PERMISSIONS'  => 'N',
        ],
        false,
        false,
        ['ID', 'XML_ID']
    );

    while ($row = $res->Fetch()) {
        if (in_array($row['XML_ID'], $keepXmlIds, true)) {
            continue;
        }
        if (CIBlockElement::Delete((int)$row['ID'])) {
            $removed++;
        }
    }

    return $removed;
}

function statsRespond(array $payload, $httpCode = 200)
{
    ob_end_clean();
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── ერთი დღის დამუშავება ────────────────────────────────────────────────

function statsProcessDay(array $day, $dailyIblockId, $channelIblockId)
{
    $date = statsNormalizeDate(statsPick($day, ['date', 'day', 'stat_date', 'statDate', 'report_date']));
    if ($date === '') {
        return ['error' => 'date is required (YYYY-MM-DD)'];
    }

    $totals   = isset($day['totals']) && is_array($day['totals']) ? $day['totals'] : [];
    $comments = isset($day['comments']) && is_array($day['comments']) ? $day['comments'] : [];
    $channels = isset($day['channels']) && is_array($day['channels']) ? $day['channels'] : [];

    // არხების რიგები - ერთი და იგივე არხი ორჯერ რომ მოვიდეს, ჯამდება.
    $channelRows = [];
    foreach ($channels as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = statsNormalizeChannel(statsPick($row, ['channel', 'name', 'source', 'title']));
        if ($name === '') {
            continue;
        }

        if (!isset($channelRows[$name])) {
            $channelRows[$name] = ['conversations' => 0, 'leads' => 0];
        }

        $channelRows[$name]['conversations'] += (int)(statsPickInt($row, ['conversations', 'chats', 'dialogs']) ?? 0);
        $channelRows[$name]['leads']         += (int)(statsPickInt($row, ['leads', 'lead_count', 'leadsCount']) ?? 0);
    }

    $channelConversations = 0;
    $channelLeads = 0;
    foreach ($channelRows as $row) {
        $channelConversations += $row['conversations'];
        $channelLeads += $row['leads'];
    }

    // totals-ს ვენდობით; თუ არ მოვიდა - არხებიდან ვაჯამებთ.
    $conversations = statsPickInt($totals, ['conversations', 'chats', 'dialogs']);
    if ($conversations === null) {
        $conversations = statsPickInt($day, ['conversations']) ?? $channelConversations;
    }

    $leads = statsPickInt($totals, ['leads']);
    if ($leads === null) {
        $leads = statsPickInt($day, ['leads']) ?? $channelLeads;
    }

    $commentsTotal = statsPickInt($comments, ['total', 'count']);
    if ($commentsTotal === null) {
        $commentsTotal = statsPickInt($totals, ['comments']) ?? 0;
    }

    $daily = statsUpsert(
        $dailyIblockId,
        STATS_XML_PREFIX . $date,
        $date,
        [
            'STAT_DATE'         => $date,
            'PERIOD_FROM'       => statsPick($day, ['period_from', 'periodFrom', 'from']),
            'PERIOD_TO'         => statsPick($day, ['period_to', 'periodTo', 'to']),
            'CONVERSATIONS'     => $conversations,
            'LEADS'             => $leads,
            'COMMENTS_TOTAL'    => $commentsTotal,
            'COMMENTS_ANSWERED' => statsPickInt($comments, ['answered', 'replied']) ?? 0,
            'COMMENTS_HIDDEN'   => statsPickInt($comments, ['hidden']) ?? 0,
            'RECEIVED_AT'       => date('Y-m-d H:i:s'),
            'RAW_JSON'          => json_encode($day, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]
    );

    if (empty($daily['id'])) {
        return ['error' => 'Failed to save daily stats: ' . ($daily['error'] ?? 'unknown error')];
    }

    $channelsCreated = 0;
    $channelsUpdated = 0;
    $keepXmlIds = [];
    $channelErrors = [];

    foreach ($channelRows as $name => $row) {
        $xmlId = STATS_XML_PREFIX . $date . '-' . statsSlug($name);
        $keepXmlIds[] = $xmlId;

        $saved = statsUpsert(
            $channelIblockId,
            $xmlId,
            $date . ' - ' . $name,
            [
                'STAT_DATE'     => $date,
                'CHANNEL'       => $name,
                'CONVERSATIONS' => $row['conversations'],
                'LEADS'         => $row['leads'],
                'PARENT_ID'     => $daily['id'],
            ]
        );

        if (empty($saved['id'])) {
            $channelErrors[] = $name . ': ' . ($saved['error'] ?? 'unknown error');
            continue;
        }

        if ($saved['created']) {
            $channelsCreated++;
        } else {
            $channelsUpdated++;
        }
    }

    // ზედმეტი არხების წაშლა მხოლოდ მაშინ, როცა payload-ში არხები საერთოდ მოვიდა -
    // არხების გარეშე გამოგზავნილმა კორექციამ არსებული ჭრილი არ უნდა წაშალოს.
    $channelsRemoved = $channelRows
        ? statsRemoveStaleChannels($channelIblockId, $date, $keepXmlIds)
        : 0;

    $result = [
        'date'     => $date,
        'dailyId'  => $daily['id'],
        'created'  => $daily['created'],
        'channels' => [
            'created' => $channelsCreated,
            'updated' => $channelsUpdated,
            'removed' => $channelsRemoved,
        ],
    ];

    if (!empty($channelErrors)) {
        $result['channelErrors'] = $channelErrors;
    }

    return $result;
}

// ── Request ─────────────────────────────────────────────────────────────

$input = statsReadInput();

if (empty($input)) {
    statsRespond(['status' => 400, 'message' => 'Empty or invalid request body'], 400);
}

$dailyIblockId   = statsIblockId(STATS_DAILY_IBLOCK_CODE);
$channelIblockId = statsIblockId(STATS_CHANNEL_IBLOCK_CODE);

if ($dailyIblockId <= 0 || $channelIblockId <= 0) {
    statsRespond([
        'status'  => 500,
        'message' => 'Stats lists are not created yet - run /custom/setup/dailoStatsLists.php',
    ], 500);
}

// ერთი დღე {...}, დღეების მასივი [{...}], ან {"days": [{...}]}
if (isset($input['days']) && is_array($input['days'])) {
    $days = $input['days'];
    $isBatch = true;
} elseif (array_keys($input) === range(0, count($input) - 1)) {
    $days = $input;
    $isBatch = true;
} else {
    $days = [$input];
    $isBatch = false;
}

global $USER;
$authorizedHere = false;
if (is_object($USER) && !$USER->IsAuthorized()) {
    $USER->Authorize(STATS_AUTHOR_ID);
    $authorizedHere = true;
}

$results = [];
$errors  = [];

foreach ($days as $day) {
    if (!is_array($day)) {
        $errors[] = 'Invalid day entry - object expected';
        continue;
    }

    $result = statsProcessDay($day, $dailyIblockId, $channelIblockId);

    if (isset($result['error'])) {
        $errors[] = $result['error'];
        continue;
    }

    $results[] = $result;
}

if ($authorizedHere) {
    $USER->Logout();
}

if (empty($results)) {
    statsRespond([
        'status'  => 400,
        'message' => $errors ? implode('; ', $errors) : 'Nothing to save',
    ], 400);
}

if ($isBatch) {
    $response = [
        'status'  => 200,
        'message' => 'OK',
        'days'    => count($results),
        'results' => $results,
    ];
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    statsRespond($response);
}

statsRespond(array_merge(['status' => 200, 'message' => 'OK'], $results[0]));
