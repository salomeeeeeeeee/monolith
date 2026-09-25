<?php

require_once __DIR__ . '/helpers.php';

/*
 * ლიდების რეპორტი — მონაცემები და დათვლა.
 * ლოგიკა გადმოტანილია /local/localApps/leads-report/index.html-დან (v2026-09-10e),
 * + Dailo-ს დღიური სტატისტიკა (rest/public/addStats.php → DAILO_STATS_* სიები).
 */

const LEADS_CATEGORY_ID = 0;

const LEADS_CALL_CENTER_STAGES = ['NEW', 'UC_WX29F1'];
const LEADS_SALES_HANDOFF_STAGES = ['PREPARATION'];
const LEADS_SALES_FROM_STAGES = ['PREPARATION', 'PREPAYMENT_INVOICE', 'FINAL_INVOICE', 'EXECUTING', 'UC_NSTB3H', 'UC_NJ7A78', 'WON'];
const LEADS_JUNK_SALES_STAGES = ['LOSE', 'UC_QFL99J'];
const LEADS_PIPELINE_ORDER = ['NEW', 'UC_WX29F1', 'PREPARATION', 'PREPAYMENT_INVOICE', 'FINAL_INVOICE', 'EXECUTING', 'UC_NSTB3H', 'UC_NJ7A78'];
const LEADS_LOST_UNKNOWN = '__UNKNOWN__';
const LEADS_LOST_UNKNOWN_LABEL = 'უცნობი / ისტორია არ არის';

const LEADS_AGENT_SOURCE_IDS = ['UC_HN9W32'];
const LEADS_AGENT_SOURCE_NAMES = ['სააგენტო/აგენტი', 'აგენტი', 'სააგენტო'];

const LEADS_MARKETING_SOURCES = [
    'CALL' => 'WhatsApp',
    'WEBFORM' => 'Instagram',
    'REPEAT_SALE' => 'Facebook',
    'CALLBACK' => 'Facebook comment',
    'RC_GENERATOR' => 'Facebook: Comments - Open Channel 2',
    'STORE' => 'Facebook - Open Channel Monolith',
    'BOOKING' => 'Facebook lead',
    '3|FACEBOOK' => 'Facebook - Open Channel 3',
    'UC_TDG7W5' => 'Widget',
    'UC_GY61WN' => 'Tiktok',
];

const LEADS_CALL_SOURCE_ID = 'EMAIL';
const LEADS_CALL_SOURCE_NAME = 'ზარი';
const LEADS_CALL_SOURCE_LABEL = 'შემოსული ზარები';
const LEADS_CC_UNASSIGNED = '__unassigned__';
const LEADS_CC_UNASSIGNED_LABEL = 'სხვა / არ არის მითითებული';

/** მარკეტინგის ხარჯი / ROI — SOURCE_ID → ხარჯის ჯგუფი (ლისტი 27-ის წყაროს იგივე ჯგუფები). */
const LEADS_COST_SOURCE_BUCKETS = [
    'facebook' => ['REPEAT_SALE', 'CALLBACK', 'RC_GENERATOR', 'STORE', 'BOOKING', '3|FACEBOOK'],
    'whatsapp' => ['CALL'],
    'instagram' => ['WEBFORM'],
    'tiktok' => ['UC_GY61WN'],
    'widget' => ['UC_TDG7W5'],
];
const LEADS_COST_SOURCE_ORDER = ['facebook', 'google', 'yandex', 'general', 'whatsapp', 'instagram', 'tiktok', 'widget'];
const LEADS_COST_SOURCE_SHORT = [
    'facebook' => 'FB',
    'google' => 'Google',
    'yandex' => 'Yandex',
    'general' => 'General',
    'whatsapp' => 'WA',
    'instagram' => 'IG',
    'tiktok' => 'TT',
    'widget' => 'Widget',
];

const LEADS_INBOUND_CHANNELS = [
    'facebook' => ['label' => 'FB', 'sources' => ['REPEAT_SALE', 'BOOKING', 'CALLBACK']],
    'fbMessenger' => ['label' => 'FB MESSENGER', 'sources' => ['STORE', 'RC_GENERATOR', '3|FACEBOOK']],
    'instagram' => ['label' => 'INSTAGRAM', 'sources' => ['WEBFORM']],
    'whatsapp' => ['label' => 'WHATSAPP', 'sources' => ['CALL']],
];

const LEADS_WON_PROPERTY_BUCKETS = [
    'apartment' => ['label' => 'ბინა', 'pattern' => '/^ბინა|სტუდიო|დუპლექს|აპარტ/iu'],
    'parking' => ['label' => 'ავტოსადგომი', 'pattern' => '/ავტოფარეხ|ავტოსადგომ|პარკინგ/iu'],
    'commercial' => ['label' => 'კომერციული', 'pattern' => '/კომერც|დამხმარე/iu'],
    'office' => ['label' => 'საოფისე', 'pattern' => '/საოფის/iu'],
];

/**
 * Dailo-ს არხი → CRM-ის SOURCE_ID-ები. Dailo Messenger-ის ლიდებს CRM-ში „Facebook“ (REPEAT_SALE)
 * წყაროთი ქმნის, ამიტომ ის აქ Open Channel-ებთან ერთადაა.
 */
const LEADS_DAILO_CHANNEL_SOURCES = [
    'Messenger' => ['REPEAT_SALE', 'STORE', 'RC_GENERATOR', '3|FACEBOOK'],
    'Instagram' => ['WEBFORM'],
    'WhatsApp' => ['CALL'],
    'Widget' => ['UC_TDG7W5'],
    'TikTok' => ['UC_GY61WN'],
];

/** Facebook/Dailo ინტეგრაცია CREATED_BY / ASSIGNED_BY = 1-ს წერს. */
const LEADS_SYSTEM_USER_NAMES = ['1' => 'ადმინისტრატორი'];
const LEADS_EMPTY_LABEL = 'მითითებული არ არის';
const LEADS_MONTH_NAMES = ['იან', 'თებ', 'მარ', 'აპრ', 'მაი', 'ივნ', 'ივლ', 'აგვ', 'სექ', 'ოქტ', 'ნოე', 'დეკ'];

// ── ზოგადი ──────────────────────────────────────────────────────────────

function leadsFmtInt($n)
{
    return number_format((float)$n, 0, '.', ',');
}

function leadsFmtPct($n)
{
    return number_format(is_finite((float)$n) ? (float)$n : 0, 2, '.', ',') . '%';
}

function leadsFmtUsd($n)
{
    return $n === null ? '—' : '$' . number_format((float)$n, 2, '.', ',');
}

function leadsShare($part, $total)
{
    return $total ? ($part / $total) * 100 : 0;
}

function leadsNormalizeStage($stageId)
{
    $stageId = (string)$stageId;
    $pos = strrpos($stageId, ':');
    return $pos === false ? $stageId : substr($stageId, $pos + 1);
}

function leadsNormalizeLabel($value)
{
    return trim((string)preg_replace('/\s+/u', ' ', (string)$value));
}

function leadsParseYmd($value)
{
    $value = trim((string)$value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return '';
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $value : '';
}

function leadsCompareStrings($a, $b)
{
    static $collator = null;
    if ($collator === null) {
        $collator = class_exists('Collator') ? new Collator('ka_GE') : false;
    }
    return $collator ? $collator->compare((string)$a, (string)$b) : strcmp((string)$a, (string)$b);
}

function leadsDealDay($dateCreate)
{
    $ts = MakeTimeStamp((string)$dateCreate);
    return $ts ? date('Y-m-d', $ts) : '';
}

function leadsFirstValue($raw)
{
    if (is_array($raw)) {
        $raw = reset($raw);
    }
    if ($raw === null || $raw === false) {
        return '';
    }
    return trim((string)$raw);
}

function leadsUfIds($raw)
{
    if ($raw === null || $raw === false || $raw === '') {
        return [];
    }
    $ids = [];
    foreach ((array)$raw as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            $ids[] = $value;
        }
    }
    return $ids;
}

function leadsUserName($userId, array $names)
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        return '—';
    }
    if (!empty($names[$userId])) {
        return $names[$userId];
    }
    if (array_key_exists((string)$userId, LEADS_SYSTEM_USER_NAMES)) {
        return LEADS_SYSTEM_USER_NAMES[(string)$userId];
    }
    return 'ID ' . $userId;
}

// ── წყაროები ────────────────────────────────────────────────────────────

function leadsIsAgentSource($sourceId, $sourceName)
{
    return in_array((string)$sourceId, LEADS_AGENT_SOURCE_IDS, true)
        || in_array((string)$sourceName, LEADS_AGENT_SOURCE_NAMES, true);
}

function leadsIsMarketingSource($sourceId, $sourceName)
{
    if (array_key_exists((string)$sourceId, LEADS_MARKETING_SOURCES)) {
        return true;
    }
    $norm = leadsNormalizeLabel($sourceName);
    if ($norm === '') {
        return false;
    }
    foreach (LEADS_MARKETING_SOURCES as $label) {
        if (leadsNormalizeLabel($label) === $norm) {
            return true;
        }
    }
    return false;
}

function leadsIsIncomingCall($sourceId, $sourceName)
{
    if (preg_match('/შემოსული ზარი BK/iu', (string)$sourceName)) {
        return false;
    }
    return (string)$sourceId === LEADS_CALL_SOURCE_ID || (string)$sourceName === LEADS_CALL_SOURCE_NAME;
}

function leadsSourceIdsBucket($sourceId, array $buckets)
{
    foreach ($buckets as $key => $ids) {
        if (in_array((string)$sourceId, $ids, true)) {
            return $key;
        }
    }
    return '';
}

/** ლისტი 27-ის წყაროს სახელი → ხარჯის ჯგუფი. */
function leadsCostBucketFromLabel($label)
{
    $name = mb_strtolower(trim((string)$label));
    if ($name === '') {
        return '';
    }
    $patterns = [
        'whatsapp' => '/whatsapp|ვოტსაპ|ვაიბერ|viber|\bwa\b/u',
        'instagram' => '/instagram|ინსტ|\big\b/u',
        'tiktok' => '/tiktok|ტიკ.?ტოკ|\btt\b/u',
        'widget' => '/widget|ვიჯეტ/u',
        'facebook' => '/facebook|ფეისბ|fb\b|meta|მეტა/u',
        'google' => '/google|გუგლ|adwords|gdn/u',
        'yandex' => '/yandex|იანდექს|яндекс/u',
        'general' => '/general|საერთო|other|სხვა/u',
    ];
    foreach ($patterns as $bucket => $pattern) {
        if (preg_match($pattern, $name)) {
            return $bucket;
        }
    }
    return '';
}

function leadsCostSourceSortIndex($source)
{
    $idx = array_search($source, LEADS_COST_SOURCE_ORDER, true);
    return $idx === false ? count(LEADS_COST_SOURCE_ORDER) : $idx;
}

function leadsCostSourceLabel($source)
{
    return array_key_exists($source, LEADS_COST_SOURCE_SHORT) ? LEADS_COST_SOURCE_SHORT[$source] : $source;
}

// ── ეტაპები ─────────────────────────────────────────────────────────────

function leadsLoadStages()
{
    $stages = [];
    $rows = CCrmStatus::GetStatus('DEAL_STAGE');
    foreach ((array)$rows as $key => $row) {
        $id = (string)($row['STATUS_ID'] ?? $key);
        $semantics = strtoupper(trim((string)($row['SEMANTICS'] ?? '')));
        if ($semantics === 'SUCCESS') {
            $semantics = 'S';
        } elseif ($semantics === 'FAILURE' || $semantics === 'APOLOGY') {
            $semantics = 'F';
        }
        $stages[$id] = ['name' => (string)($row['NAME'] ?? $id), 'semantics' => $semantics];
    }

    $builtin = [
        'WON' => ['გაყიდული', 'S'],
        'LOSE' => ['გაუქმებული ხელშეკრულება', 'F'],
        'UC_QFL99J' => ['წარუმატებელი მოლაპარაკება', 'F'],
    ];
    foreach ($builtin as $id => $meta) {
        if (!isset($stages[$id])) {
            $stages[$id] = ['name' => $meta[0], 'semantics' => $meta[1]];
        } elseif ($stages[$id]['semantics'] === '') {
            $stages[$id]['semantics'] = $meta[1];
        }
    }
    foreach ($stages as $id => $meta) {
        if ($meta['semantics'] === '') {
            $stages[$id]['semantics'] = 'P';
        }
    }
    return $stages;
}

function leadsClassify(array $deal, array $stages)
{
    $stageId = leadsNormalizeStage($deal['STAGE_ID'] ?? '');
    $meta = $stages[$stageId] ?? ['name' => $stageId, 'semantics' => ''];
    $semantics = $meta['semantics'] !== '' ? $meta['semantics'] : strtoupper((string)($deal['STAGE_SEMANTIC_ID'] ?? ''));

    $isWon = $stageId === 'WON' || $semantics === 'S';
    $isJunk = in_array($stageId, LEADS_JUNK_SALES_STAGES, true) || $semantics === 'F';

    $upperName = mb_strtoupper((string)$meta['name']);
    $junkBucket = null;
    if (strpos($upperName, 'JUNK (CALL CENTER)') !== false) {
        $junkBucket = 'callCenter';
    } elseif (in_array($stageId, LEADS_JUNK_SALES_STAGES, true) || strpos($upperName, 'JUNK (SALES)') !== false) {
        $junkBucket = 'sales';
    }

    return [
        'stageId' => $stageId,
        'stageName' => $meta['name'] !== '' ? $meta['name'] : $stageId,
        'isWon' => $isWon,
        'isJunk' => $isJunk,
        'isInWork' => !$isWon && !$isJunk,
        'junkBucket' => $junkBucket,
        'isCallCenterStage' => in_array($stageId, LEADS_CALL_CENTER_STAGES, true),
    ];
}

function leadsIsUnsuccessful(array $c)
{
    return !$c['isWon'] && ($c['junkBucket'] !== null || $c['isJunk']);
}

function leadsIsSalesManagerJunk(array $c)
{
    if ($c['junkBucket'] === 'sales') {
        return true;
    }
    if ($c['junkBucket'] === 'callCenter') {
        return false;
    }
    return $c['isJunk'];
}

function leadsIsSalesManagerInWork(array $c)
{
    if ($c['isWon'] || leadsIsSalesManagerJunk($c)) {
        return false;
    }
    return $c['junkBucket'] === null && !$c['isJunk'];
}

function leadsPathHas(array $path, array $stageIds)
{
    foreach ($path as $stageId) {
        if (in_array($stageId, $stageIds, true)) {
            return true;
        }
    }
    return false;
}

function leadsIsSalesRedirected(array $c, array $path)
{
    return in_array($c['stageId'], LEADS_SALES_FROM_STAGES, true) || leadsPathHas($path, LEADS_SALES_FROM_STAGES);
}

/** Qualified = PREPARATION ახლა ან ისტორიაში, ან სეილის შემდგომი ეტაპი. */
function leadsIsQualified(array $c, array $path)
{
    return leadsIsSalesRedirected($c, $path);
}

function leadsIsFailureStage($stageId, array $stages)
{
    if (in_array($stageId, LEADS_JUNK_SALES_STAGES, true)) {
        return true;
    }
    return ($stages[$stageId]['semantics'] ?? '') === 'F';
}

function leadsIsDirectLostFromCallCenter(array $path, array $stages)
{
    for ($i = 1, $n = count($path); $i < $n; $i++) {
        if (in_array($path[$i - 1], LEADS_CALL_CENTER_STAGES, true) && leadsIsFailureStage($path[$i], $stages)) {
            return true;
        }
    }
    return false;
}

function leadsIsCallCenterProcessed(array $c, array $path, array $stages)
{
    if (leadsIsSalesRedirected($c, $path)) {
        return false;
    }
    if ($c['isCallCenterStage']) {
        return true;
    }
    if (!$c['isJunk']) {
        return false;
    }
    if (leadsIsDirectLostFromCallCenter($path, $stages)) {
        return true;
    }
    return !leadsPathHas($path, LEADS_SALES_HANDOFF_STAGES);
}

/** რომელი ეტაპიდან წავიდა წარუმატებელში — ისტორიაში პირველი failure-ის წინა ეტაპი. */
function leadsLostFromStage(array $c, array $path, array $stages)
{
    foreach ($path as $i => $stageId) {
        if (!leadsIsFailureStage($stageId, $stages)) {
            continue;
        }
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!leadsIsFailureStage($path[$j], $stages)) {
                return $path[$j];
            }
        }
        return LEADS_LOST_UNKNOWN;
    }
    return LEADS_LOST_UNKNOWN;
}

// ── მონაცემების ჩატვირთვა ───────────────────────────────────────────────

function leadsLoadDeals($from, $to)
{
    $filter = [
        '>=DATE_CREATE' => ConvertTimeStamp(strtotime($from . ' 00:00:00'), 'FULL'),
        '<=DATE_CREATE' => ConvertTimeStamp(strtotime($to . ' 23:59:59'), 'FULL'),
        'CATEGORY_ID' => LEADS_CATEGORY_ID,
    ];
    $select = [
        'ID', 'TITLE', 'DATE_CREATE', 'STAGE_ID', 'STAGE_SEMANTIC_ID',
        'ASSIGNED_BY_ID', 'CREATED_BY_ID', 'CONTACT_ID', 'SOURCE_ID', 'OPPORTUNITY',
        D_PROJECT, D_TYPE, D_LOSS_REASON_SALES, D_LOSS_REASON_DETAIL, D_LOSS_REASON_CC,
    ];
    return reportGetDealsByFilter($filter, $select, ['DATE_CREATE' => 'ASC', 'ID' => 'ASC']);
}

/** @param array $projects selected projects; empty = all */
function leadsFilterByProject(array $deals, array $projects)
{
    if (!$projects) {
        return $deals;
    }
    return array_filter($deals, function ($deal) use ($projects) {
        return reportValueMatches(leadsFirstValue($deal[D_PROJECT] ?? ''), $projects);
    });
}

function leadsProjectOptions(array $deals)
{
    $values = [];
    foreach ($deals as $deal) {
        $values[] = ['project' => leadsFirstValue($deal[D_PROJECT] ?? '')];
    }
    $projects = reportGetUniqueValues($values, 'project');
    usort($projects, 'leadsCompareStrings');
    return $projects;
}

function leadsLoadSources()
{
    $sources = [];
    foreach ((array)CCrmStatus::GetStatusList('SOURCE') as $id => $name) {
        $sources[(string)$id] = (string)$name;
    }
    return $sources;
}

function leadsSourceName(array $deal, array $sources)
{
    $id = (string)($deal['SOURCE_ID'] ?? '');
    if ($id !== '' && isset($sources[$id])) {
        return $sources[$id];
    }
    return $id !== '' ? $id : 'უცნობი';
}

function leadsUfEnumMap($fieldName)
{
    $map = [];
    $field = CUserTypeEntity::GetList([], ['ENTITY_ID' => 'CRM_DEAL', 'FIELD_NAME' => $fieldName])->Fetch();
    if (!$field) {
        return $map;
    }
    $res = CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $field['ID']]);
    while ($row = $res->Fetch()) {
        $map[(string)$row['ID']] = (string)$row['VALUE'];
    }
    return $map;
}

/** DEAL ID → ეტაპების თანმიმდევრობა (crm.stagehistory-ის ანალოგი). */
function leadsLoadStagePaths(array $dealIds)
{
    $paths = [];
    foreach ($dealIds as $id) {
        $paths[(int)$id] = [];
    }
    if (empty($paths) || !class_exists('\Bitrix\Crm\History\Entity\DealStageHistoryTable')) {
        return $paths;
    }
    foreach (array_chunk(array_keys($paths), 500) as $chunk) {
        $res = \Bitrix\Crm\History\Entity\DealStageHistoryTable::getList([
            'select' => ['OWNER_ID', 'STAGE_ID', 'CATEGORY_ID'],
            'filter' => ['@OWNER_ID' => $chunk],
            'order' => ['CREATED_TIME' => 'ASC', 'ID' => 'ASC'],
        ]);
        while ($row = $res->fetch()) {
            $category = (string)($row['CATEGORY_ID'] ?? '');
            if ($category !== '' && (int)$category !== LEADS_CATEGORY_ID) {
                continue;
            }
            $paths[(int)$row['OWNER_ID']][] = leadsNormalizeStage($row['STAGE_ID']);
        }
    }
    return $paths;
}

function leadsContactAssignedMap(array $contactIds)
{
    $map = [];
    $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
    foreach (array_chunk($contactIds, 500) as $chunk) {
        $res = CCrmContact::GetList([], ['ID' => $chunk, 'CHECK_PERMISSIONS' => 'N'], ['ID', 'ASSIGNED_BY_ID']);
        while ($row = $res->Fetch()) {
            $map[(int)$row['ID']] = (int)$row['ASSIGNED_BY_ID'];
        }
    }
    return $map;
}

function leadsIblockIdByCode($code)
{
    if (!CModule::IncludeModule('iblock')) {
        return 0;
    }
    $row = CIBlock::GetList([], ['CODE' => $code, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
    return $row ? (int)$row['ID'] : 0;
}

/** თვე ლისტიდან („01/08/2026“ ან „2026-08“) → [პირველი დღე, ბოლო დღე]. */
function leadsCostPeriod($raw)
{
    $raw = trim((string)$raw);
    if (preg_match('/(\d{4})[-\/.](\d{1,2})/', $raw, $m)) {
        $year = (int)$m[1];
        $month = (int)$m[2];
    } elseif (preg_match('/(\d{1,2})[-\/.](\d{4})/', $raw, $m)) {
        $month = (int)$m[1];
        $year = (int)$m[2];
    } else {
        return null;
    }
    if ($month < 1 || $month > 12) {
        return null;
    }
    $from = sprintf('%04d-%02d-01', $year, $month);
    return [$from, date('Y-m-t', strtotime($from))];
}

/** მარკეტინგის ხარჯები (ლისტი 27: თვე / ხარჯი / წყარო). */
function leadsLoadMarketingCosts()
{
    $entries = [];
    if (!CModule::IncludeModule('iblock')) {
        return $entries;
    }
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => REPORT_MARKETING_COST_IBLOCK, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'IBLOCK_ID']
    );
    while ($element = $res->GetNextElement()) {
        $entry = ['month' => '', 'spend' => 0.0, 'source' => ''];
        foreach ($element->GetProperties() as $prop) {
            $code = mb_strtolower((string)$prop['CODE']);
            $name = mb_strtolower((string)$prop['NAME']);
            $value = is_array($prop['VALUE']) ? reset($prop['VALUE']) : $prop['VALUE'];
            if ($code === 'month' || mb_strpos($name, 'თვე') !== false) {
                $entry['month'] = (string)$value;
            } elseif ($code === 'spend' || mb_strpos($name, 'ხარჯ') !== false) {
                $entry['spend'] = (float)str_replace(',', '.', (string)$value);
            } elseif ($code === 'source' || mb_strpos($name, 'წყარო') !== false) {
                $enum = $prop['VALUE_ENUM'] ?? '';
                $entry['source'] = (string)(is_array($enum) ? reset($enum) : ($enum !== '' ? $enum : $value));
            }
        }
        $entries[] = $entry;
    }
    return $entries;
}

/** Dailo-ს დღიური ჩანაწერები და არხების ჭრილი პერიოდში (STAT_DATE = YYYY-MM-DD). */
function leadsLoadDailoStats($from, $to)
{
    $stats = ['available' => false, 'days' => [], 'channels' => [], 'lastReceived' => ''];
    $dailyId = leadsIblockIdByCode(REPORT_DAILO_DAILY_CODE);
    $channelId = leadsIblockIdByCode(REPORT_DAILO_CHANNEL_CODE);
    if ($dailyId <= 0) {
        return $stats;
    }
    $stats['available'] = true;

    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => $dailyId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        [
            'ID', 'IBLOCK_ID', 'PROPERTY_STAT_DATE', 'PROPERTY_CONVERSATIONS', 'PROPERTY_LEADS',
            'PROPERTY_COMMENTS_TOTAL', 'PROPERTY_COMMENTS_ANSWERED', 'PROPERTY_COMMENTS_HIDDEN', 'PROPERTY_RECEIVED_AT',
        ]
    );
    while ($row = $res->Fetch()) {
        $date = leadsParseYmd($row['PROPERTY_STAT_DATE_VALUE'] ?? '');
        if ($date === '' || $date < $from || $date > $to) {
            continue;
        }
        $stats['days'][$date] = [
            'conversations' => (int)$row['PROPERTY_CONVERSATIONS_VALUE'],
            'leads' => (int)$row['PROPERTY_LEADS_VALUE'],
            'comments' => (int)$row['PROPERTY_COMMENTS_TOTAL_VALUE'],
            'answered' => (int)$row['PROPERTY_COMMENTS_ANSWERED_VALUE'],
            'hidden' => (int)$row['PROPERTY_COMMENTS_HIDDEN_VALUE'],
        ];
        $received = (string)($row['PROPERTY_RECEIVED_AT_VALUE'] ?? '');
        if ($received > $stats['lastReceived']) {
            $stats['lastReceived'] = $received;
        }
    }
    krsort($stats['days']);

    if ($channelId > 0) {
        $res = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => $channelId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'PROPERTY_STAT_DATE', 'PROPERTY_CHANNEL', 'PROPERTY_CONVERSATIONS', 'PROPERTY_LEADS']
        );
        while ($row = $res->Fetch()) {
            $date = leadsParseYmd($row['PROPERTY_STAT_DATE_VALUE'] ?? '');
            $channel = trim((string)($row['PROPERTY_CHANNEL_VALUE'] ?? ''));
            if ($date === '' || $channel === '' || $date < $from || $date > $to) {
                continue;
            }
            if (!isset($stats['channels'][$channel])) {
                $stats['channels'][$channel] = ['conversations' => 0, 'leads' => 0];
            }
            $stats['channels'][$channel]['conversations'] += (int)$row['PROPERTY_CONVERSATIONS_VALUE'];
            $stats['channels'][$channel]['leads'] += (int)$row['PROPERTY_LEADS_VALUE'];
        }
        uasort($stats['channels'], function ($a, $b) {
            return $b['conversations'] - $a['conversations'] ?: $b['leads'] - $a['leads'];
        });
    }

    return $stats;
}

// ── მოდალის რიგები ──────────────────────────────────────────────────────

/** [ID, თარიღი, ეტაპი, წყარო, კლიენტი, დაარეგისტრირა, კონტაქტის პასუხ., პასუხისმგებელი, ლოსის მიზეზი, დეტალურად, უინტერესობის მიზეზი] */
function leadsDealRow(array $deal, array $c, array $ctx)
{
    $ccIds = leadsUfIds($deal[D_LOSS_REASON_CC] ?? '');
    $ccLabels = [];
    foreach ($ccIds as $id) {
        $ccLabels[] = $ctx['ccLossMap'][$id] ?? $id;
    }
    $lossCc = $ccLabels ? implode(', ', $ccLabels) : '—';

    $salesId = leadsFirstValue($deal[D_LOSS_REASON_SALES] ?? '');
    $lossSales = $salesId !== '' ? ($ctx['salesLossMap'][$salesId] ?? $salesId) : '—';

    $detail = leadsFirstValue($deal[D_LOSS_REASON_DETAIL] ?? '');
    $contactId = (int)($deal['CONTACT_ID'] ?? 0);
    $contactAssigned = $contactId > 0 ? (int)($ctx['contactAssigned'][$contactId] ?? 0) : 0;
    $title = trim((string)($deal['TITLE'] ?? ''));

    return [
        (int)$deal['ID'],
        leadsDealDay($deal['DATE_CREATE'] ?? ''),
        $c['stageName'],
        leadsSourceName($deal, $ctx['sources']),
        $title !== '' ? $title : '—',
        leadsUserName($deal['CREATED_BY_ID'] ?? 0, $ctx['userNames']),
        leadsUserName($contactAssigned, $ctx['userNames']),
        leadsUserName($deal['ASSIGNED_BY_ID'] ?? 0, $ctx['userNames']),
        $lossCc !== '—' ? $lossCc : $lossSales,
        $detail !== '' ? $detail : '—',
        $lossCc,
    ];
}

// ── ძირითადი დათვლა ─────────────────────────────────────────────────────

function leadsEmptyOutcome()
{
    return ['total' => 0, 'won' => 0, 'junk' => 0, 'inWork' => 0];
}

/** ჯამური / წარმატებული / წარუმატებელი — დინამიკისა და შედარებისთვის. */
function leadsSummarizeOutcome(array $deals, array $stages, $bucketPrefix, array &$buckets)
{
    $stats = leadsEmptyOutcome();
    foreach ($deals as $deal) {
        $id = (int)$deal['ID'];
        $c = leadsClassify($deal, $stages);
        $stats['total']++;
        $buckets[$bucketPrefix . 'total'][] = $id;
        if ($c['isWon']) {
            $stats['won']++;
            $buckets[$bucketPrefix . 'won'][] = $id;
        } elseif (leadsIsUnsuccessful($c)) {
            $stats['junk']++;
            $buckets[$bucketPrefix . 'junk'][] = $id;
        }
    }
    return $stats;
}

function leadsMonthDynamics(array $deals, array $stages, array $monthKeys)
{
    $months = [];
    foreach ($monthKeys as $key) {
        $months[$key] = leadsEmptyOutcome();
    }
    foreach ($deals as $deal) {
        $key = substr(leadsDealDay($deal['DATE_CREATE'] ?? ''), 0, 7);
        if (!isset($months[$key])) {
            continue;
        }
        $c = leadsClassify($deal, $stages);
        $months[$key]['total']++;
        if ($c['isWon']) {
            $months[$key]['won']++;
        } elseif (leadsIsUnsuccessful($c)) {
            $months[$key]['junk']++;
        }
    }
    return $months;
}

function leadsMonthLabel($key)
{
    list($year, $month) = explode('-', $key);
    return LEADS_MONTH_NAMES[(int)$month - 1] . ' ' . $year;
}

function leadsBuildReport(array $deals, array $ctx)
{
    $stages = $ctx['stages'];
    $sources = $ctx['sources'];
    $buckets = [];
    $rows = [];

    $r = [
        'total' => 0, 'agent' => 0, 'marketing' => 0, 'other' => 0,
        'won' => 0, 'wonAgent' => 0, 'wonMarketing' => 0, 'wonOther' => 0,
        'inWork' => 0, 'junk' => 0, 'qualified' => 0, 'wonQualified' => 0,
        'callCenter' => 0, 'junkCallCenter' => 0,
        'status' => ['won' => 0, 'inWork' => 0, 'junk' => 0],
        'wonProperty' => array_fill_keys(array_keys(LEADS_WON_PROPERTY_BUCKETS), 0),
        'sources' => [],
        'managers' => [],
        'managerSources' => [],
        'ccOperators' => [],
        'calls' => ['calls' => 0, 'redirected' => 0, 'won' => 0, 'unsuccessful' => 0, 'inWork' => 0],
        'projects' => [],
        'lossReasons' => [],
        'lostFrom' => [],
        'marketing' => [],
        'marketingTotals' => ['total' => 0, 'won' => 0, 'junk' => 0, 'inWork' => 0],
        'inbound' => array_fill_keys(array_keys(LEADS_INBOUND_CHANNELS), 0),
        'costLeads' => [],
        'dailoCrm' => [],
    ];

    // მარკეტინგის ცხრილში ყველა წყარო ჩანს, ნულოვანიც.
    $marketingSeed = LEADS_MARKETING_SOURCES + [LEADS_CALL_SOURCE_ID => LEADS_CALL_SOURCE_NAME];
    foreach ($marketingSeed as $sourceId => $label) {
        $name = $sources[$sourceId] ?? $label;
        $r['marketing'][$name] = ['total' => 0, 'won' => 0, 'junk' => 0, 'inWork' => 0];
    }
    foreach (array_keys(LEADS_DAILO_CHANNEL_SOURCES) as $channel) {
        $r['dailoCrm'][$channel] = ['leads' => 0, 'won' => 0];
    }
    $inboundSources = array_map(function ($channel) {
        return $channel['sources'];
    }, LEADS_INBOUND_CHANNELS);

    foreach ($deals as $deal) {
        $id = (int)$deal['ID'];
        $c = leadsClassify($deal, $stages);
        $path = $ctx['paths'][$id] ?? [];
        $sourceId = (string)($deal['SOURCE_ID'] ?? '');
        $sourceName = leadsSourceName($deal, $sources);
        $isAgent = leadsIsAgentSource($sourceId, $sourceName);
        $isMarketing = leadsIsMarketingSource($sourceId, $sourceName);
        $salesDeal = leadsIsSalesRedirected($c, $path);
        $rows[$id] = leadsDealRow($deal, $c, $ctx);

        // მოცულობა
        $r['total']++;
        $buckets['kpi:total'][] = $id;
        if ($isAgent) {
            $r['agent']++;
            $buckets['kpi:agent'][] = $id;
        }
        if ($isMarketing) {
            $r['marketing_count'] = ($r['marketing_count'] ?? 0) + 1;
            $buckets['kpi:marketing'][] = $id;
        }
        if (!$isAgent && !$isMarketing) {
            $r['other']++;
            $buckets['kpi:other'][] = $id;
        }

        // შედეგები
        if (leadsIsQualified($c, $path)) {
            $r['qualified']++;
            if ($c['isWon']) {
                $r['wonQualified']++;
                $buckets['kpi:convQualified'][] = $id;
            }
        }
        if (leadsIsCallCenterProcessed($c, $path, $stages)) {
            $r['callCenter']++;
            $buckets['kpi:callCenter'][] = $id;
        }
        if ($c['isWon']) {
            $r['won']++;
            $buckets['kpi:won'][] = $id;
            if ($isAgent) {
                $r['wonAgent']++;
                $buckets['kpi:wonAgent'][] = $id;
            } elseif ($isMarketing) {
                $r['wonMarketing']++;
                $buckets['kpi:wonMarketing'][] = $id;
            } else {
                $r['wonOther']++;
                $buckets['kpi:wonOther'][] = $id;
            }
        }
        if ($c['isJunk']) {
            $r['junk']++;
            $buckets['kpi:junk'][] = $id;
            if ($c['junkBucket'] === 'callCenter') {
                $r['junkCallCenter']++;
                $buckets['kpi:junkCallCenter'][] = $id;
            }
        }
        if ($c['isInWork']) {
            $r['inWork']++;
            $buckets['kpi:inWork'][] = $id;
        }

        // ლიდის ეტაპები (liveboard)
        if ($c['isWon']) {
            $r['status']['won']++;
        } elseif ($c['junkBucket'] !== null || $c['isJunk']) {
            $r['status']['junk']++;
        } else {
            $r['status']['inWork']++;
        }

        // წარმატებული — ქონების ტიპით
        if ($c['isWon']) {
            $type = leadsFirstValue($deal[D_TYPE] ?? '');
            foreach (LEADS_WON_PROPERTY_BUCKETS as $key => $bucket) {
                if ($type !== '' && (preg_match($bucket['pattern'], $type) || mb_strtolower($type) === mb_strtolower($bucket['label']))) {
                    $r['wonProperty'][$key]++;
                    $buckets['property:' . $key][] = $id;
                    break;
                }
            }
        }

        // წყაროები
        if (!isset($r['sources'][$sourceName])) {
            $r['sources'][$sourceName] = ['count' => 0, 'won' => 0];
        }
        $r['sources'][$sourceName]['count']++;
        $buckets['source:' . $sourceName][] = $id;
        if ($c['isWon']) {
            $r['sources'][$sourceName]['won']++;
            $buckets['sourceWon:' . $sourceName][] = $id;
        }

        // გაყიდვების მენეჯერები (ASSIGNED_BY_ID)
        $managerId = (int)($deal['ASSIGNED_BY_ID'] ?? 0);
        if ($managerId > 0) {
            $manager = leadsUserName($managerId, $ctx['userNames']);
            if (!isset($r['managers'][$manager])) {
                $r['managers'][$manager] = ['total' => 0, 'won' => 0, 'junk' => 0, 'inWork' => 0];
            }
            $r['managers'][$manager]['total']++;
            $buckets['manager:' . $manager . ':total'][] = $id;
            $metric = null;
            if ($c['isWon']) {
                $metric = 'won';
            } elseif (leadsIsSalesManagerInWork($c)) {
                $metric = 'inWork';
            } elseif (leadsIsSalesManagerJunk($c)) {
                $metric = 'junk';
            }
            if ($metric !== null) {
                $r['managers'][$manager][$metric]++;
                $buckets['manager:' . $manager . ':' . $metric][] = $id;
            }
            $r['managerSources'][$manager][$sourceName] = ($r['managerSources'][$manager][$sourceName] ?? 0) + 1;
            $buckets['managerSource:' . $manager . ':' . $sourceName][] = $id;
        }

        // შემოსული ზარები + ზარები მენეჯერების მიხედვით
        if (leadsIsIncomingCall($sourceId, $sourceName)) {
            $regId = (int)($deal['CREATED_BY_ID'] ?? 0);
            $operatorId = 0;
            foreach ([$regId, $managerId] as $candidate) {
                if ($candidate > 0 && $candidate !== 1) {
                    $operatorId = $candidate;
                    break;
                }
            }
            if ($operatorId === 0) {
                $operatorId = $regId > 0 ? $regId : $managerId;
            }
            $operator = $operatorId > 0 ? (string)$operatorId : LEADS_CC_UNASSIGNED;
            if (!isset($r['ccOperators'][$operator])) {
                $r['ccOperators'][$operator] = ['calls' => 0, 'redirected' => 0, 'inWork' => 0, 'unsuccessful' => 0];
            }

            $r['calls']['calls']++;
            $r['ccOperators'][$operator]['calls']++;
            $buckets['calls:calls'][] = $id;
            $buckets['ccOperator:' . $operator . ':calls'][] = $id;
            if ($c['isWon']) {
                $r['calls']['won']++;
                $buckets['calls:won'][] = $id;
            }
            if ($c['isJunk'] && $c['junkBucket'] === 'callCenter') {
                $metric = 'unsuccessful';
            } elseif ($salesDeal) {
                $metric = 'redirected';
            } else {
                $metric = 'inWork';
            }
            $r['calls'][$metric]++;
            $r['ccOperators'][$operator][$metric]++;
            $buckets['calls:' . $metric][] = $id;
            $buckets['ccOperator:' . $operator . ':' . $metric][] = $id;
        }

        // ლიდის ინფორმაცია — პროექტი
        $project = leadsFirstValue($deal[D_PROJECT] ?? '');
        $projectLabel = $project !== '' ? $project : LEADS_EMPTY_LABEL;
        $r['projects'][$projectLabel] = ($r['projects'][$projectLabel] ?? 0) + 1;
        $buckets['project:' . $projectLabel][] = $id;

        // წარუმატებლობის მიზეზები + რომელი ეტაპიდან
        if ($c['isJunk']) {
            $reason = $rows[$id][8];
            $r['lossReasons'][$reason] = ($r['lossReasons'][$reason] ?? 0) + 1;
            $buckets['loss:' . $reason][] = $id;

            $fromStage = leadsLostFromStage($c, $path, $stages);
            $r['lostFrom'][$fromStage] = ($r['lostFrom'][$fromStage] ?? 0) + 1;
            $buckets['lostFrom:' . $fromStage][] = $id;
        }

        // მარკეტინგის ანალიზი, ROI, შემოსვლის არხები, Dailo-ს CRM სვეტი
        if ($isMarketing) {
            $name = array_key_exists($sourceId, LEADS_MARKETING_SOURCES) ? ($sources[$sourceId] ?? LEADS_MARKETING_SOURCES[$sourceId]) : $sourceName;
            if (!isset($r['marketing'][$name])) {
                $r['marketing'][$name] = ['total' => 0, 'won' => 0, 'junk' => 0, 'inWork' => 0];
            }
            $metric = $c['isWon'] ? 'won' : (leadsIsUnsuccessful($c) ? 'junk' : 'inWork');
            $r['marketing'][$name]['total']++;
            $r['marketing'][$name][$metric]++;
            $r['marketingTotals']['total']++;
            $r['marketingTotals'][$metric]++;
            $buckets['mkt:' . $name . ':total'][] = $id;
            $buckets['mkt:' . $name . ':' . $metric][] = $id;
            $buckets['mktSummary:' . $metric][] = $id;

            $costSource = leadsSourceIdsBucket($sourceId, LEADS_COST_SOURCE_BUCKETS);
            if ($costSource !== '') {
                $costProject = $project !== '' ? $project : '—';
                $key = $costSource . '||' . $costProject;
                if (!isset($r['costLeads'][$key])) {
                    $r['costLeads'][$key] = ['source' => $costSource, 'project' => $costProject, 'leads' => 0, 'won' => 0, 'revenue' => 0.0];
                }
                $r['costLeads'][$key]['leads']++;
                if ($c['isWon']) {
                    $r['costLeads'][$key]['won']++;
                    $r['costLeads'][$key]['revenue'] += (float)($deal['OPPORTUNITY'] ?? 0);
                }
            }
        }
        $inbound = leadsSourceIdsBucket($sourceId, $inboundSources);
        if ($inbound !== '') {
            $r['inbound'][$inbound]++;
            $buckets['inbound:' . $inbound][] = $id;
        }
        $dailoChannel = leadsSourceIdsBucket($sourceId, LEADS_DAILO_CHANNEL_SOURCES);
        if ($dailoChannel !== '') {
            $r['dailoCrm'][$dailoChannel]['leads']++;
            $buckets['dailoCrm:' . $dailoChannel][] = $id;
            if ($c['isWon']) {
                $r['dailoCrm'][$dailoChannel]['won']++;
                $buckets['dailoCrmWon:' . $dailoChannel][] = $id;
            }
        }
    }
    $r['marketingLeads'] = $r['marketing_count'] ?? 0;
    unset($r['marketing_count']);

    uasort($r['sources'], function ($a, $b) {
        return $b['count'] - $a['count'];
    });
    arsort($r['projects']);
    arsort($r['lossReasons']);
    uksort($r['managers'], 'leadsCompareStrings');
    uksort($r['marketing'], function ($a, $b) use ($r) {
        return ($r['marketing'][$b]['total'] - $r['marketing'][$a]['total']) ?: leadsCompareStrings($a, $b);
    });

    // წარუმატებელი ეტაპებით — პაიპლაინის რიგით, უცნობი ბოლოს
    $order = array_flip(LEADS_PIPELINE_ORDER);
    $lostFrom = $r['lostFrom'];
    uksort($r['lostFrom'], function ($a, $b) use ($order, $lostFrom) {
        if ($a === LEADS_LOST_UNKNOWN) {
            return 1;
        }
        if ($b === LEADS_LOST_UNKNOWN) {
            return -1;
        }
        $ai = $order[$a] ?? 1000;
        $bi = $order[$b] ?? 1000;
        return ($ai - $bi) ?: (($lostFrom[$b] - $lostFrom[$a]) ?: strcmp((string)$a, (string)$b));
    });

    // ზარები მენეჯერებით — ზარების კლებით, „არ არის მითითებული“ ბოლოს
    $operators = $r['ccOperators'];
    uksort($r['ccOperators'], function ($a, $b) use ($operators, $ctx) {
        if ($a === LEADS_CC_UNASSIGNED) {
            return 1;
        }
        if ($b === LEADS_CC_UNASSIGNED) {
            return -1;
        }
        return ($operators[$b]['calls'] - $operators[$a]['calls'])
            ?: leadsCompareStrings(leadsUserName($a, $ctx['userNames']), leadsUserName($b, $ctx['userNames']));
    });

    // მენეჯერი × წყარო — წყაროების საერთო რიგი
    $sourceTotals = [];
    foreach ($r['managerSources'] as $managerSources) {
        foreach ($managerSources as $name => $count) {
            $sourceTotals[$name] = ($sourceTotals[$name] ?? 0) + $count;
        }
    }
    uksort($sourceTotals, function ($a, $b) use ($sourceTotals) {
        return ($sourceTotals[$b] - $sourceTotals[$a]) ?: leadsCompareStrings($a, $b);
    });
    $r['managerSourceOrder'] = array_keys($sourceTotals);

    $r['buckets'] = $buckets;
    $r['rows'] = $rows;
    return $r;
}

/** ხარჯი (ლისტი 27) + მარკეტინგის ლიდები → წყაროების ჯგუფები და პროექტები. */
function leadsBuildCostRoi(array $costEntries, array $costLeads, $from, $to)
{
    $costRows = [];
    foreach ($costEntries as $entry) {
        $period = leadsCostPeriod($entry['month']);
        if (!$period || $period[0] > $to || $period[1] < $from) {
            continue;
        }
        $source = leadsCostBucketFromLabel($entry['source']);
        if ($source === '') {
            continue;
        }
        $key = $source . '||—';
        if (!isset($costRows[$key])) {
            $costRows[$key] = ['source' => $source, 'project' => '—', 'cost' => 0.0];
        }
        $costRows[$key]['cost'] += $entry['spend'];
    }

    $compose = function ($source, $project) use ($costRows, $costLeads) {
        $key = $source . '||' . $project;
        $cost = isset($costRows[$key]) && $costRows[$key]['cost'] > 0 ? $costRows[$key]['cost'] : 0.0;
        $lead = $costLeads[$key] ?? ['leads' => 0, 'won' => 0, 'revenue' => 0.0];
        $hasCost = $cost > 0;
        return [
            'source' => $source,
            'project' => $project,
            'hasCost' => $hasCost,
            'cost' => $cost,
            'leads' => $lead['leads'],
            'won' => $lead['won'],
            'revenue' => $lead['revenue'],
            'cpl' => $hasCost && $lead['leads'] > 0 ? $cost / $lead['leads'] : null,
            'cr' => $lead['leads'] > 0 ? ($lead['won'] / $lead['leads']) * 100 : null,
            'costPerApt' => $hasCost && $lead['won'] > 0 ? $cost / $lead['won'] : null,
            'roi' => $hasCost ? $lead['revenue'] / $cost : null,
        ];
    };
    $finalize = function (array $m) {
        $m['hasCost'] = true;
        $m['cpl'] = $m['leads'] > 0 ? $m['cost'] / $m['leads'] : null;
        $m['cr'] = $m['leads'] > 0 ? ($m['won'] / $m['leads']) * 100 : null;
        $m['costPerApt'] = $m['won'] > 0 ? $m['cost'] / $m['won'] : null;
        $m['roi'] = $m['cost'] > 0 ? $m['revenue'] / $m['cost'] : null;
        return $m;
    };
    $empty = ['cost' => 0.0, 'leads' => 0, 'won' => 0, 'revenue' => 0.0];

    $totals = $empty;
    $groups = [];
    foreach (array_unique(array_merge(array_keys($costRows), array_keys($costLeads))) as $key) {
        list($source, $project) = explode('||', $key, 2);
        $row = $compose($source, $project);
        if (!isset($groups[$source])) {
            $groups[$source] = ['source' => $source, 'totals' => $empty, 'projects' => []];
        }
        foreach (['cost', 'leads', 'won', 'revenue'] as $field) {
            $groups[$source]['totals'][$field] += $row[$field];
            $totals[$field] += $row[$field];
        }
        if (isset($costLeads[$key]) && $costLeads[$key]['won'] > 0) {
            $groups[$source]['projects'][] = $row;
        }
    }

    foreach ($groups as $source => $group) {
        if ($group['totals']['cost'] <= 0 && $group['totals']['leads'] <= 0) {
            unset($groups[$source]);
            continue;
        }
        $groups[$source]['totals'] = $finalize($group['totals']);
        usort($groups[$source]['projects'], function ($a, $b) {
            return ($b['won'] - $a['won']) ?: (($b['revenue'] <=> $a['revenue']) ?: leadsCompareStrings($a['project'], $b['project']));
        });
    }
    uasort($groups, function ($a, $b) {
        return leadsCostSourceSortIndex($a['source']) - leadsCostSourceSortIndex($b['source']);
    });

    return [
        'hasData' => $totals['cost'] > 0 || $totals['leads'] > 0,
        'totals' => $finalize($totals),
        'groups' => $groups,
    ];
}

/** Dailo — პერიოდის ჯამები. */
function leadsDailoTotals(array $dailo, $from, $to)
{
    $totals = ['conversations' => 0, 'leads' => 0, 'comments' => 0, 'answered' => 0, 'hidden' => 0];
    foreach ($dailo['days'] as $day) {
        foreach ($totals as $field => $value) {
            $totals[$field] += $day[$field];
        }
    }
    $totals['daysWithData'] = count($dailo['days']);
    $totals['daysInPeriod'] = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    return $totals;
}
