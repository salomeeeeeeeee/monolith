<?php
/**
 * Meta (Facebook / Instagram) რეკლამის ხარჯების მიღება
 *
 * URL:     https://crm.monolith.ge/rest/public/addMetaSpend.php
 * Method:  POST, Content-Type: application/json
 * Auth:    Authorization: Bearer <TOKEN>   ან   X-Api-Token: <TOKEN>
 *
 * ტოკენი git-ში არ ინახება (რეპო საჯაროა) - სერვერზეა:
 *   /local/php_interface/meta_spend_secret.php   (ნიმუში: meta_spend_secret.example.php)
 *
 * სია ერთხელ იქმნება აქ:
 *   https://crm.monolith.ge/custom/setup/metaSpendList.php
 *
 * მონაცემი ჯდება სიაში META_AD_SPEND: 1 ჩანაწერი = 1 რეკლამა × 1 პერიოდი
 * (date_start .. date_stop) - ანუ Meta Insights API-ის ერთი რიგი level=ad-ზე.
 *
 * მიიღება:
 *   - ერთი რიგი            {...}
 *   - რიგების მასივი       [{...}, {...}]
 *   - Meta-ს პასუხი პირდაპირ {"data": [{...}], "paging": {...}}   (ან {"rows": [...]})
 *
 * ერთი და იმავე რეკლამის იმავე პერიოდის ხელახლა გამოგზავნა არსებულ ჩანაწერს
 * აახლებს და ახალს არ ქმნის (გასაღები - XML_ID), ასე რომ Meta-ს მიერ გვიან
 * დაზუსტებული ხარჯი ძველს ჩაანაცვლებს და ორმაგად არ დაითვლება.
 */

ob_start();
define("STOP_STATISTICS",       true);
define("NO_KEEP_STATISTIC",     "Y");
define("NO_AGENT_STATISTIC",    "Y");
define("NO_AGENT_CHECK",        true);
define("DisableEventsCheck",    true);
define("NOT_CHECK_PERMISSIONS", true);

const META_SPEND_IBLOCK_CODE = 'META_AD_SPEND';
const META_SPEND_AUTHOR_ID   = 1;
const META_SPEND_XML_PREFIX  = 'meta-spend-';
const META_SPEND_MAX_ROWS    = 2000; // ~15 ms/რიგი → 2000 რიგი ≈ 30 წმ

function metaSpendRespond(array $payload, $httpCode = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Auth ────────────────────────────────────────────────────────────────

function metaSpendExpectedToken()
{
    $file = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/meta_spend_secret.php';
    $token = is_file($file) ? include $file : '';

    return is_string($token) ? trim($token) : '';
}

function metaSpendRequestToken()
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['HTTP_X_API_TOKEN'] ?? '',
    ];

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            $key = strtolower((string)$name);
            if ($key === 'authorization' || $key === 'x-api-token') {
                $candidates[] = $value;
            }
        }
    }

    // სათადარიგო - თუ გამომგზავნ ხელსაწყოს ჰედერის დამატება არ შეუძლია
    $candidates[] = $_GET['token'] ?? '';

    foreach ($candidates as $raw) {
        $raw = is_string($raw) ? trim($raw) : '';
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    metaSpendRespond(['status' => 405, 'message' => 'Method not allowed - use POST'], 405);
}

$expectedToken = metaSpendExpectedToken();
if ($expectedToken === '') {
    metaSpendRespond(['status' => 500, 'message' => 'Endpoint is not configured'], 500);
}

if (!hash_equals($expectedToken, metaSpendRequestToken())) {
    metaSpendRespond(['status' => 401, 'message' => 'Unauthorized - Bearer token is required'], 401);
}

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

@set_time_limit(300);
CModule::IncludeModule('iblock');

// ── Helpers ─────────────────────────────────────────────────────────────

/** JSON body → მასივი; null = body მოვიდა, მაგრამ JSON გაუმართავია ($error-ში მიზეზი). */
function metaSpendReadInput(&$error)
{
    $error = '';
    $raw = file_get_contents('php://input');

    if (is_string($raw) && trim($raw) !== '') {
        // დიდი ID-ები (Meta-ს ID 17+ ციფრია) რიცხვად რომ მოვიდეს, სიზუსტე არ დაიკარგოს
        $data = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (is_array($data)) {
            return $data;
        }
        if (empty($_POST)) {
            $error = json_last_error() !== JSON_ERROR_NONE
                ? 'Invalid JSON: ' . json_last_error_msg()
                : 'Invalid JSON: an object or an array of objects is expected';
            return null;
        }
    }

    return !empty($_POST) ? $_POST : [];
}

function metaSpendIsList(array $value)
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function metaSpendPick(array $row, array $keys)
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && is_scalar($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return '';
}

function metaSpendCut($value, $length)
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

/** YYYY-MM-DD (ან DD.MM.YYYY / ISO datetime) → YYYY-MM-DD; '' თუ თარიღი არასწორია. */
function metaSpendNormalizeDate($value)
{
    $value = trim((string)$value);

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|[T\s])/', $value, $m)) {
        list(, $y, $mo, $d) = $m;
    } elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})$/', $value, $m)) {
        list(, $d, $mo, $y) = $m;
    } else {
        return '';
    }

    return checkdate((int)$mo, (int)$d, (int)$y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
}

/** "1234.56" / "1 234,56" / 1234.56 → float; null თუ რიცხვი არ არის. */
function metaSpendParseNumber($value)
{
    $value = str_replace([' ', "\xC2\xA0"], '', trim((string)$value));
    if (strpos($value, ',') !== false && strpos($value, '.') === false) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float)$value : null;
}

/**
 * ერთი რიგის შემოწმება და ნორმალიზება.
 * აბრუნებს ['error' => '...'] ან ['xml_id', 'name', 'props'].
 */
function metaSpendNormalizeRow($row)
{
    if (!is_array($row) || metaSpendIsList($row)) {
        return ['error' => 'row must be a JSON object'];
    }

    $dateStart = metaSpendNormalizeDate(metaSpendPick($row, ['date_start', 'dateStart', 'date']));
    if ($dateStart === '') {
        return ['error' => 'date_start is missing or invalid (YYYY-MM-DD expected)'];
    }

    $rawStop = metaSpendPick($row, ['date_stop', 'dateStop', 'date_end', 'dateEnd']);
    $dateStop = $rawStop === '' ? $dateStart : metaSpendNormalizeDate($rawStop);
    if ($dateStop === '') {
        return ['error' => 'date_stop is not a valid date (YYYY-MM-DD)'];
    }
    if ($dateStop < $dateStart) {
        return ['error' => 'date_stop is earlier than date_start'];
    }

    $ids = [];
    foreach (['campaign_id' => 'campaignId', 'adset_id' => 'adsetId', 'ad_id' => 'adId'] as $key => $alias) {
        $id = metaSpendPick($row, [$key, $alias]);
        if ($id !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return ['error' => $key . ' has invalid format (digits expected)'];
        }
        $ids[$key] = $id;
    }

    // გასაღები - ყველაზე დეტალური ID, რაც მოვიდა (ჩვეულებრივ ad_id)
    if ($ids['ad_id'] !== '') {
        $level = 'ad';
    } elseif ($ids['adset_id'] !== '') {
        $level = 'adset';
    } elseif ($ids['campaign_id'] !== '') {
        $level = 'campaign';
    } else {
        return ['error' => 'ad_id is required (or adset_id / campaign_id for aggregated rows)'];
    }

    $rawSpend = metaSpendPick($row, ['spend']);
    if ($rawSpend === '') {
        return ['error' => 'spend is required'];
    }
    $spend = metaSpendParseNumber($rawSpend);
    if ($spend === null) {
        return ['error' => 'spend must be a number'];
    }
    if ($spend < 0) {
        return ['error' => 'spend cannot be negative'];
    }

    $names = [
        'campaign_name' => metaSpendCut(metaSpendPick($row, ['campaign_name', 'campaignName']), 255),
        'adset_name'    => metaSpendCut(metaSpendPick($row, ['adset_name', 'adsetName']), 255),
        'ad_name'       => metaSpendCut(metaSpendPick($row, ['ad_name', 'adName']), 255),
    ];

    $currency = strtoupper(metaSpendCut(metaSpendPick($row, ['account_currency', 'currency']), 10));

    $period = $dateStart === $dateStop ? $dateStart : $dateStart . ' - ' . $dateStop;
    $title = $names['ad_name'] ?: ($names['adset_name'] ?: ($names['campaign_name'] ?: $ids[$level . '_id']));

    $rawJson = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return [
        'xml_id' => META_SPEND_XML_PREFIX . $level . '-' . $ids[$level . '_id'] . '-' . $dateStart . '-' . $dateStop,
        'name'   => metaSpendCut($period . ' · ' . $title, 255),
        'props'  => [
            'DATE_START'    => ConvertTimeStamp(strtotime($dateStart . ' 00:00:00'), 'SHORT'),
            'DATE_STOP'     => ConvertTimeStamp(strtotime($dateStop . ' 00:00:00'), 'SHORT'),
            'CAMPAIGN_NAME' => $names['campaign_name'],
            'CAMPAIGN_ID'   => $ids['campaign_id'],
            'ADSET_NAME'    => $names['adset_name'],
            'ADSET_ID'      => $ids['adset_id'],
            'AD_NAME'       => $names['ad_name'],
            'AD_ID'         => $ids['ad_id'],
            'SPEND'         => $spend,
            'CURRENCY'      => $currency,
            'RECEIVED_AT'   => ConvertTimeStamp(time(), 'FULL'),
            'RAW_JSON'      => metaSpendCut((string)$rawJson, 20000),
        ],
    ];
}

function metaSpendIblockId()
{
    $row = CIBlock::GetList([], ['CODE' => META_SPEND_IBLOCK_CODE, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
    return $row ? (int)$row['ID'] : 0;
}

/** XML_ID => ჩანაწერის ID - უკვე არსებული ჩანაწერები, ერთი მოთხოვნით 500-ობით. */
function metaSpendExistingIds($iblockId, array $xmlIds)
{
    $map = [];

    foreach (array_chunk(array_values(array_unique($xmlIds)), 500) as $chunk) {
        $res = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, '=XML_ID' => $chunk, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'XML_ID']
        );
        while ($row = $res->Fetch()) {
            $map[$row['XML_ID']] = (int)$row['ID'];
        }
    }

    return $map;
}

// ── Request ─────────────────────────────────────────────────────────────

$inputError = '';
$input = metaSpendReadInput($inputError);

if ($input === null) {
    metaSpendRespond(['status' => 400, 'message' => $inputError], 400);
}
if (empty($input)) {
    metaSpendRespond(['status' => 400, 'message' => 'Empty request body'], 400);
}

// {"data": [...]} (Meta-ს ფორმატი), {"rows": [...]}, [...] ან ერთი რიგი {...}
if (isset($input['data']) && is_array($input['data'])) {
    $rows = metaSpendIsList($input['data']) ? $input['data'] : [$input['data']];
} elseif (isset($input['rows']) && is_array($input['rows'])) {
    $rows = metaSpendIsList($input['rows']) ? $input['rows'] : [$input['rows']];
} elseif (metaSpendIsList($input)) {
    $rows = $input;
} else {
    $rows = [$input];
}

if (empty($rows)) {
    metaSpendRespond(['status' => 400, 'message' => 'No rows to save'], 400);
}
if (count($rows) > META_SPEND_MAX_ROWS) {
    metaSpendRespond([
        'status'  => 400,
        'message' => 'Too many rows (' . count($rows) . ') - max ' . META_SPEND_MAX_ROWS . ' per request, split it',
    ], 400);
}

$iblockId = metaSpendIblockId();
if ($iblockId <= 0) {
    metaSpendRespond([
        'status'  => 500,
        'message' => 'List is not created yet - run /custom/setup/metaSpendList.php',
    ], 500);
}

$prepared = [];
$errors = [];

foreach (array_values($rows) as $index => $row) {
    $item = metaSpendNormalizeRow($row);
    if (isset($item['error'])) {
        $errors[] = ['index' => $index, 'message' => $item['error']];
        continue;
    }
    $item['index'] = $index;
    $prepared[] = $item;
}

$existing = $prepared ? metaSpendExistingIds($iblockId, array_column($prepared, 'xml_id')) : [];

$created = 0;
$updated = 0;
$saved = [];
$el = new CIBlockElement();

foreach ($prepared as $item) {
    $fields = [
        'IBLOCK_ID'       => $iblockId,
        'NAME'            => $item['name'],
        'XML_ID'          => $item['xml_id'],
        'ACTIVE'          => 'Y',
        'MODIFIED_BY'     => META_SPEND_AUTHOR_ID,
        'PROPERTY_VALUES' => $item['props'],
    ];

    $existingId = $existing[$item['xml_id']] ?? 0;

    if ($existingId > 0) {
        if (!$el->Update($existingId, $fields, false, false)) {
            $errors[] = ['index' => $item['index'], 'message' => 'Save failed: ' . strip_tags((string)$el->LAST_ERROR)];
            continue;
        }
        $updated++;
        $saved[] = ['index' => $item['index'], 'id' => $existingId, 'created' => false];
        continue;
    }

    $fields['CREATED_BY'] = META_SPEND_AUTHOR_ID;

    $newId = $el->Add($fields, false, false);
    if (!is_numeric($newId) || (int)$newId <= 0) {
        $errors[] = ['index' => $item['index'], 'message' => 'Save failed: ' . strip_tags((string)$el->LAST_ERROR)];
        continue;
    }

    // იგივე გასაღები იმავე მოთხოვნაში მეორედ - უკვე განახლებად ჩაითვლება
    $existing[$item['xml_id']] = (int)$newId;
    $created++;
    $saved[] = ['index' => $item['index'], 'id' => (int)$newId, 'created' => true];
}

usort($errors, function ($a, $b) {
    return $a['index'] <=> $b['index'];
});

if (empty($saved)) {
    metaSpendRespond([
        'status'   => 400,
        'message'  => 'Nothing saved',
        'received' => count($rows),
        'errors'   => $errors,
    ], 400);
}

$response = [
    'status'   => 200,
    'message'  => $errors ? 'Partially saved - see errors' : 'OK',
    'received' => count($rows),
    'created'  => $created,
    'updated'  => $updated,
    'failed'   => count($errors),
];

if (count($rows) === 1) {
    $response['id'] = $saved[0]['id'];
}
if ($errors) {
    $response['errors'] = $errors;
}

metaSpendRespond($response);
