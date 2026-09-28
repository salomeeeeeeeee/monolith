<?php
/**
 * დილებზე კონტაქტების მიბმა ველიდან "მყიდველი ძველი ბაზისთვის" (UF_CRM_1787819849026).
 *
 * იმპორტისას ზოგ დილს კონტაქტი ვერ მიება. გვერდი აჩვენებს დილებს, რომლებსაც
 * ეს ველი შევსებული აქვთ, მაგრამ არც ერთი კონტაქტი არ აქვთ მიბმული, და ველის
 * ტექსტით ეძებს კონტაქტს:
 *  - სახელის ზუსტი დამთხვევა (სახელი გვარი / გვარი სახელი / სრული სახელი);
 *  - კონტაქტი, რომელიც იმავე ტექსტიან სხვა დილზეა მიბმული;
 *  - თუ მთლიანი ტექსტი ვერ მოიძებნა, "/", ",", "და" და ა.შ.-ით გაყოფილი
 *    თითო სახელი ცალკე;
 *  - ზუსტის არარსებობისას მსგავსი სახელები (მხოლოდ შემოთავაზება).
 *
 * ცალსახა დამთხვევა წინასწარ არის მონიშნული, დანარჩენი ხელით ირჩევა
 * (ხელით ძებნაც შეიძლება სახელით ან ტელეფონით). მიბმა მხოლოდ ღილაკით ხდება.
 *
 * UI: https://crm.monolith.ge/crm/deal/migration/oldBaseContactsToDeals.php
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

CModule::IncludeModule('crm');

define('D_OLD_BUYER', 'UF_CRM_1787819849026');
define('D_PROJECT', 'UF_CRM_1779277729207');
define('C_PERSONAL_NO', 'UF_CRM_1781244744534');
define('FUZZY_MIN', 0.75);
define('FUZZY_CONTAINS', 0.8);
define('FUZZY_LIMIT', 5);
define('TOKEN_MAX_CONTACTS', 300);
define('SEARCH_LIMIT', 20);

global $USER;
if (!is_object($USER) || !$USER->IsAdmin()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'გვერდი მხოლოდ ადმინისტრატორისთვისაა.';
    exit;
}

$action = (string)($_GET['action'] ?? '');
$apply = $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['apply'] ?? '') === '1'
    && check_bitrix_sessid();
$sessidField = bitrix_sessid_post();

if (function_exists('session_write_close')) {
    session_write_close();
}

function obcFieldText($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    return trim((string)$value);
}

/** შედარებისთვის: პატარა ასოები, ნებისმიერი ნიშანი და სივრცე -> ერთი სივრცე */
function obcNorm($value)
{
    $value = mb_strtolower((string)$value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    return trim($value);
}

/** 3+ სიმბოლოიანი სიტყვები */
function obcTokens($norm)
{
    $out = [];
    foreach (explode(' ', $norm) as $w) {
        if (mb_strlen($w) >= 3) {
            $out[$w] = true;
        }
    }
    return array_keys($out);
}

/** მსგავსი სახელების საძიებო გასაღებები: სიტყვა + პირველი 4 ასო */
function obcTokenKeys(array $words)
{
    $keys = [];
    foreach ($words as $w) {
        $keys[$w] = true;
        if (mb_strlen($w) >= 5) {
            $keys['~' . mb_substr($w, 0, 4)] = true;
        }
    }
    return array_keys($keys);
}

/** რამდენიმე მყიდველი ერთ ველში: "/", ",", ";", "+", "&", ახალი ხაზი, " და " */
function obcSplitNames($text)
{
    $parts = preg_split('/\s*[\/,;+&\r\n]+\s*|\s+და\s+/u', (string)$text);
    $out = [];
    foreach ($parts as $part) {
        $part = trim($part);
        $norm = obcNorm($part);
        if ($norm !== '' && !isset($out[$norm])) {
            $out[$norm] = $part;
        }
    }
    return array_values($out);
}

/** სიმბოლოების დონის მსგავსება 0..1 (levenshtein ქართულ ასოებზე) */
function obcSimilarity($a, $b)
{
    if ($a === $b) {
        return 1.0;
    }
    $ca = mb_str_split($a);
    $cb = mb_str_split($b);
    $len = max(count($ca), count($cb));
    if ($len === 0) {
        return 0.0;
    }
    // თითო სიმბოლო -> ერთი ბაიტი, რომ levenshtein-მა ასოებით დაითვალოს
    $map = [];
    $sa = '';
    $sb = '';
    foreach ($ca as $ch) {
        if (!isset($map[$ch])) {
            $map[$ch] = chr(count($map) % 256);
        }
        $sa .= $map[$ch];
    }
    foreach ($cb as $ch) {
        if (!isset($map[$ch])) {
            $map[$ch] = chr(count($map) % 256);
        }
        $sb .= $map[$ch];
    }
    return 1 - levenshtein($sa, $sb) / $len;
}

/** ტელეფონის შედარების გასაღები: ბოლო 9 ციფრი */
function obcPhoneKeys(array $phones)
{
    $keys = [];
    foreach ($phones as $phone) {
        foreach (preg_split('/[;,\/]+/', (string)$phone) as $p) {
            $digits = preg_replace('/\D+/', '', $p);
            if (strlen($digits) >= 7) {
                $keys[substr($digits, -9)] = true;
            }
        }
    }
    return array_keys($keys);
}

/** [contacts, byKey, byToken] */
function obcLoadContacts()
{
    $contacts = [];
    $byKey = [];
    $byToken = [];
    $res = CCrmContact::GetListEx(
        ['ID' => 'ASC'],
        ['CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'FULL_NAME']
    );
    while ($c = $res->Fetch()) {
        $id = (int)$c['ID'];
        $name = trim((string)($c['NAME'] ?? ''));
        $last = trim((string)($c['LAST_NAME'] ?? ''));
        $second = trim((string)($c['SECOND_NAME'] ?? ''));
        $keys = array_values(array_unique(array_filter([
            obcNorm($name . ' ' . $last),
            obcNorm($last . ' ' . $name),
            obcNorm($name . ' ' . $second . ' ' . $last),
            obcNorm($last . ' ' . $name . ' ' . $second),
            obcNorm($c['FULL_NAME'] ?? ''),
        ], 'strlen')));
        $tokens = [];
        foreach ($keys as $k) {
            $byKey[$k][$id] = true;
            foreach (obcTokens($k) as $t) {
                $tokens[$t] = true;
            }
        }
        foreach (obcTokenKeys(array_keys($tokens)) as $tk) {
            $byToken[$tk][$id] = true;
        }
        $display = trim((string)($c['FULL_NAME'] ?? ''));
        if ($display === '') {
            $display = trim($name . ' ' . $last);
        }
        $contacts[$id] = [
            'name'   => $display !== '' ? $display : ('#' . $id),
            'keys'   => $keys,
            'tokens' => $tokens,
            'hay'    => implode(' | ', $keys),
        ];
    }
    return [$contacts, $byKey, $byToken];
}

/** ტელეფონები, მიბმული დილების რაოდენობა, პირადი ნომერი */
function obcLoadContactDetails(array $ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $info = [];
    foreach ($ids as $id) {
        $info[$id] = ['phones' => [], 'phone_keys' => [], 'deals' => 0, 'personal' => ''];
    }
    foreach (array_chunk($ids, 500) as $chunk) {
        $res = CCrmFieldMulti::GetList([], ['ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'PHONE', 'ELEMENT_ID' => $chunk]);
        while ($m = $res->Fetch()) {
            $id = (int)$m['ELEMENT_ID'];
            $value = trim((string)$m['VALUE']);
            if (isset($info[$id]) && $value !== '' && !in_array($value, $info[$id]['phones'], true)) {
                $info[$id]['phones'][] = $value;
            }
        }
        $res = \Bitrix\Crm\Binding\DealContactTable::getList([
            'filter' => ['@CONTACT_ID' => $chunk],
            'select' => ['CONTACT_ID'],
        ]);
        while ($b = $res->fetch()) {
            $id = (int)$b['CONTACT_ID'];
            if (isset($info[$id])) {
                $info[$id]['deals']++;
            }
        }
        $res = CCrmContact::GetListEx([], ['CHECK_PERMISSIONS' => 'N', 'ID' => $chunk], false, false, ['ID', C_PERSONAL_NO]);
        while ($c = $res->Fetch()) {
            $id = (int)$c['ID'];
            if (isset($info[$id])) {
                $info[$id]['personal'] = obcFieldText($c[C_PERSONAL_NO] ?? '');
            }
        }
    }
    foreach ($info as $id => $row) {
        $info[$id]['phone_keys'] = obcPhoneKeys($row['phones']);
    }
    return $info;
}

/** სახელის ზუსტი დამთხვევა; ვერ პოვნისას ფრჩხილების შიგთავსის გარეშე */
function obcExactIds($text, array $byKey)
{
    $norm = obcNorm($text);
    if ($norm !== '' && isset($byKey[$norm])) {
        return array_keys($byKey[$norm]);
    }
    $stripped = obcNorm(preg_replace('/\([^)]*\)?/u', ' ', (string)$text));
    if ($stripped !== '' && $stripped !== $norm && isset($byKey[$stripped])) {
        return array_keys($byKey[$stripped]);
    }
    return [];
}

/** მსგავსი სახელები: [contactId => მსგავსება] */
function obcFuzzy($text, array $contacts, array $byToken)
{
    $norm = obcNorm($text);
    if (mb_strlen($norm) < 4) {
        return [];
    }
    $words = obcTokens($norm);
    $pool = [];
    foreach (obcTokenKeys($words) as $tk) {
        if (isset($byToken[$tk]) && count($byToken[$tk]) <= TOKEN_MAX_CONTACTS) {
            $pool += $byToken[$tk];
        }
    }
    $scores = [];
    foreach ($pool as $cid => $_) {
        $c = $contacts[$cid];
        $best = 0.0;
        foreach ($c['keys'] as $k) {
            $best = max($best, obcSimilarity($norm, $k));
        }
        // ყველა სიტყვა კონტაქტის სახელში არის (მაგ. მხოლოდ გვარი წერია)
        if ($words && $best < FUZZY_CONTAINS) {
            $all = true;
            foreach ($words as $w) {
                if (!isset($c['tokens'][$w])) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $best = FUZZY_CONTAINS;
            }
        }
        if ($best >= FUZZY_MIN) {
            $scores[$cid] = $best;
        }
    }
    arsort($scores);
    return array_slice($scores, 0, FUZZY_LIMIT, true);
}

/** რიგი "მთავარი" კონტაქტისთვის: მეტი დილი, პირადი ნომერი, მეტი ტელეფონი */
function obcRank(array $d)
{
    return [(int)$d['deals'], $d['personal'] !== '' ? 1 : 0, count($d['phones'])];
}

function obcSortByRank(array $ids, array $details)
{
    usort($ids, function ($a, $b) use ($details) {
        return (obcRank($details[$b]) <=> obcRank($details[$a])) ?: ($a <=> $b);
    });
    return $ids;
}

/**
 * რომელი კანდიდატი მოინიშნოს წინასწარ.
 * $cands: [contactId => ['name'|'same', ...]]
 * @return array [არჩეული ID-ები, სახე: exact|same|dupes|ambiguous]
 */
function obcPickDefault(array $cands, array $details)
{
    $ids = array_keys($cands);
    if (count($ids) === 1) {
        return [$ids, in_array('name', $cands[$ids[0]], true) ? 'exact' : 'same'];
    }
    $both = [];
    foreach ($cands as $cid => $src) {
        if (in_array('name', $src, true) && in_array('same', $src, true)) {
            $both[] = $cid;
        }
    }
    if (count($both) === 1) {
        return [$both, 'same'];
    }
    // ერთი ადამიანის დუბლიკატები: ყველას საერთო ტელეფონი აქვს მთავართან
    $ids = obcSortByRank($ids, $details);
    $best = $ids[0];
    $bestPhones = $details[$best]['phone_keys'];
    if ($bestPhones) {
        foreach (array_slice($ids, 1) as $cid) {
            if (!array_intersect($bestPhones, $details[$cid]['phone_keys'])) {
                return [[], 'ambiguous'];
            }
        }
        return [[$best], 'dupes'];
    }
    return [[], 'ambiguous'];
}

function obcLoadStageNames()
{
    $categories = [0 => 'ძირითადი'];
    if (class_exists('\Bitrix\Crm\Category\DealCategory')) {
        try {
            foreach ((array)\Bitrix\Crm\Category\DealCategory::getAll(true) as $cat) {
                $id = (int)($cat['ID'] ?? 0);
                if ($id > 0) {
                    $categories[$id] = (string)($cat['NAME'] ?? ('ვორონკა ' . $id));
                }
            }
        } catch (Throwable $e) {
            // მხოლოდ ძირითადი ვორონკა დარჩება
        }
    }
    $stages = [];
    foreach (array_keys($categories) as $catId) {
        $list = CCrmStatus::GetStatusList($catId > 0 ? 'DEAL_STAGE_' . $catId : 'DEAL_STAGE');
        $stages[$catId] = is_array($list) ? $list : [];
    }
    return [$categories, $stages];
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** კონტაქტის აღწერა (HTML): ბმული, სახელი, ტელეფონი, დილები, პ/ნ */
function obcContactDesc($cid, array $contacts, array $details)
{
    $d = $details[$cid] ?? ['phones' => [], 'deals' => 0, 'personal' => ''];
    $meta = [];
    if ($d['phones']) {
        $meta[] = implode(', ', $d['phones']);
    }
    $meta[] = (int)$d['deals'] . ' დილი';
    if ($d['personal'] !== '') {
        $meta[] = 'პ/ნ ' . $d['personal'];
    }
    return '<a href="/crm/contact/details/' . (int)$cid . '/" target="_blank">#' . (int)$cid . '</a> '
        . '<b>' . h($contacts[$cid]['name'] ?? ('#' . $cid)) . '</b> '
        . '<span class="muted">' . h(implode(' · ', $meta)) . '</span>';
}

function obcOptionHtml($dealId, $cid, $descHtml, $tagHtml, $checked)
{
    return '<label class="opt"><input type="checkbox" class="pick" data-deal="' . (int)$dealId . '" value="' . (int)$cid . '"'
        . ($checked ? ' checked' : '') . '> <span class="desc">' . $descHtml . '</span> ' . $tagHtml . '</label>';
}

[$contacts, $byKey, $byToken] = obcLoadContacts();

// ხელით ძებნა (AJAX): სახელით ან ტელეფონით
if ($action === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim((string)($_GET['q'] ?? ''));
    $found = [];
    $digits = preg_replace('/\D+/', '', $q);
    if (strlen($digits) >= 6) {
        $res = CCrmFieldMulti::GetList([], ['ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'PHONE', '%VALUE' => $digits]);
        while ($m = $res->Fetch()) {
            $found[(int)$m['ELEMENT_ID']] = 1.0;
        }
    }
    $qNorm = obcNorm(preg_replace('/\d+/', ' ', $q));
    $words = $qNorm === '' ? [] : explode(' ', $qNorm);
    if ($words) {
        foreach ($contacts as $cid => $c) {
            foreach ($words as $w) {
                if (strpos($c['hay'], $w) === false) {
                    continue 2;
                }
            }
            $best = 0.0;
            foreach ($c['keys'] as $k) {
                $best = max($best, obcSimilarity($qNorm, $k));
            }
            $found[$cid] = max($found[$cid] ?? 0.0, $best);
        }
    }
    arsort($found);
    $found = array_slice($found, 0, SEARCH_LIMIT, true);
    $details = obcLoadContactDetails(array_keys($found));
    $items = [];
    foreach (array_keys($found) as $cid) {
        if (isset($contacts[$cid])) {
            $items[] = ['id' => $cid, 'html' => obcContactDesc($cid, $contacts, $details)];
        }
    }
    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. მიბმა
$applied = [];
$applyCounts = ['deals' => 0, 'contacts' => 0, 'failed' => 0, 'skipped' => 0];
if ($apply) {
    $payload = json_decode((string)($_POST['payload'] ?? ''), true);
    $wanted = [];
    if (is_array($payload)) {
        foreach ($payload as $dealId => $ids) {
            $dealId = (int)$dealId;
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids), function ($cid) use ($contacts) {
                return isset($contacts[$cid]);
            })));
            if ($dealId > 0 && $ids) {
                $wanted[$dealId] = $ids;
            }
        }
    }

    $existingDeals = [];
    foreach (array_chunk(array_keys($wanted), 500) as $chunk) {
        $res = CCrmDeal::GetListEx([], ['CHECK_PERMISSIONS' => 'N', 'ID' => $chunk], false, false, ['ID', 'TITLE']);
        while ($d = $res->Fetch()) {
            $existingDeals[(int)$d['ID']] = (string)$d['TITLE'];
        }
    }

    foreach ($wanted as $dealId => $ids) {
        $row = ['title' => $existingDeals[$dealId] ?? '', 'contacts' => $ids, 'status' => 'bound', 'error' => ''];
        if (!isset($existingDeals[$dealId])) {
            $row['status'] = 'failed';
            $row['error'] = 'დილი ვერ მოიძებნა';
            $applyCounts['failed']++;
            $applied[$dealId] = $row;
            continue;
        }
        try {
            $existing = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($dealId);
            $new = array_values(array_diff($ids, $existing));
            $row['contacts'] = $new;
            if (!$new) {
                $row['status'] = 'skipped';
                $applyCounts['skipped']++;
            } elseif (!$existing) {
                // პირველი კონტაქტი მთავარი ხდება
                \Bitrix\Crm\Binding\DealContactTable::bindContactIDs($dealId, $new);
            } else {
                // უკვე აქვს კონტაქტი: მთავარს არ ვცვლით, ახლები ბოლოში ემატება
                $bindings = [];
                foreach ($new as $i => $cid) {
                    $bindings[] = [
                        'CONTACT_ID' => $cid,
                        'SORT'       => 10 * (count($existing) + $i + 1),
                        'IS_PRIMARY' => 'N',
                    ];
                }
                \Bitrix\Crm\Binding\DealContactTable::bindContacts($dealId, $bindings);
            }
            if ($row['status'] === 'bound') {
                $applyCounts['deals']++;
                $applyCounts['contacts'] += count($new);
            }
        } catch (Throwable $e) {
            $row['status'] = 'failed';
            $row['error'] = $e->getMessage();
            $applyCounts['failed']++;
        }
        $applied[$dealId] = $row;
    }
}

// 2. დილების სკანირება
[$categories, $stageNames] = obcLoadStageNames();

$deals = [];
$res = CCrmDeal::GetListEx(
    ['ID' => 'ASC'],
    ['CHECK_PERMISSIONS' => 'N', '!' . D_OLD_BUYER => false],
    false,
    false,
    ['ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'OPPORTUNITY', 'CURRENCY_ID', D_OLD_BUYER, D_PROJECT]
);
while ($deal = $res->Fetch()) {
    $text = obcFieldText($deal[D_OLD_BUYER] ?? '');
    if (obcNorm($text) === '') {
        continue;
    }
    $deal['_text'] = $text;
    $deal['_key'] = obcNorm($text);
    $deals[(int)$deal['ID']] = $deal;
}

$bound = [];
foreach (array_chunk(array_keys($deals), 500) as $chunk) {
    $res = \Bitrix\Crm\Binding\DealContactTable::getList([
        'filter' => ['@DEAL_ID' => $chunk],
        'select' => ['DEAL_ID', 'CONTACT_ID'],
    ]);
    while ($b = $res->fetch()) {
        $bound[(int)$b['DEAL_ID']][] = (int)$b['CONTACT_ID'];
    }
}

// იგივე ტექსტი სხვა დილზე -> იქ მიბმული კონტაქტები
$sameText = [];
foreach ($deals as $dealId => $deal) {
    foreach ($bound[$dealId] ?? [] as $cid) {
        $sameText[$deal['_key']][$cid] = true;
    }
}

// 3. კანდიდატები
$rows = [];
$needDetails = [];
foreach ($deals as $dealId => $deal) {
    if (!empty($bound[$dealId])) {
        continue;
    }
    $text = $deal['_text'];
    $groups = [];

    $whole = [];
    foreach (obcExactIds($text, $byKey) as $cid) {
        $whole[$cid][] = 'name';
    }
    foreach (array_keys($sameText[$deal['_key']] ?? []) as $cid) {
        $whole[$cid][] = 'same';
    }
    if ($whole) {
        $groups[] = ['label' => '', 'cands' => $whole, 'fuzzy' => []];
    } else {
        $parts = obcSplitNames($text);
        if (!$parts) {
            $parts = [$text];
        }
        $partCands = [];
        $taken = [];
        foreach ($parts as $i => $part) {
            $partCands[$i] = [];
            foreach (obcExactIds($part, $byKey) as $cid) {
                $partCands[$i][$cid][] = 'name';
                $taken[$cid] = true;
            }
        }
        foreach ($parts as $i => $part) {
            // სხვა ნაწილში უკვე ნაპოვნი კონტაქტი მსგავსებში აღარ მეორდება
            $fuzzy = $partCands[$i] ? [] : array_diff_key(obcFuzzy($part, $contacts, $byToken), $taken);
            $taken += $fuzzy;
            $groups[] = [
                'label' => count($parts) > 1 ? $part : '',
                'cands' => $partCands[$i],
                'fuzzy' => $fuzzy,
            ];
        }
    }
    foreach ($groups as $g) {
        foreach (array_keys($g['cands']) as $cid) {
            $needDetails[$cid] = true;
        }
        foreach (array_keys($g['fuzzy']) as $cid) {
            $needDetails[$cid] = true;
        }
    }
    $rows[$dealId] = ['deal' => $deal, 'groups' => $groups];
}

$details = obcLoadContactDetails(array_keys($needDetails));

$statusCounts = ['auto' => 0, 'partial' => 0, 'review' => 0, 'none' => 0];
$keyCounts = [];
foreach ($rows as $dealId => $row) {
    $picked = 0;
    $hasCands = false;
    foreach ($row['groups'] as $gi => $g) {
        if ($g['cands']) {
            [$selected, $kind] = obcPickDefault($g['cands'], $details);
        } else {
            $selected = [];
            $kind = $g['fuzzy'] ? 'fuzzy' : 'none';
        }
        $order = obcSortByRank(array_keys($g['cands']), $details);
        // არჩეული პირველი
        usort($order, function ($a, $b) use ($selected) {
            return (int)in_array($b, $selected, true) <=> (int)in_array($a, $selected, true);
        });
        $rows[$dealId]['groups'][$gi]['selected'] = $selected;
        $rows[$dealId]['groups'][$gi]['kind'] = $kind;
        $rows[$dealId]['groups'][$gi]['order'] = $order;
        if ($selected) {
            $picked++;
        }
        if ($g['cands'] || $g['fuzzy']) {
            $hasCands = true;
        }
    }
    if ($picked === count($row['groups'])) {
        $status = 'auto';
    } elseif ($picked > 0) {
        $status = 'partial';
    } elseif ($hasCands) {
        $status = 'review';
    } else {
        $status = 'none';
    }
    $rows[$dealId]['status'] = $status;
    $statusCounts[$status]++;
    $key = $row['deal']['_key'];
    $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
}

$statusLabels = [
    'auto'    => 'მზადაა',
    'partial' => 'ნაწილობრივ',
    'review'  => 'ასარჩევი',
    'none'    => 'ვერ მოიძებნა',
];
$kindNotes = [
    'dupes'     => 'ერთი ადამიანის დუბლიკატი კონტაქტები (საერთო ტელეფონი), მონიშნულია მთავარი',
    'ambiguous' => 'ამ სახელით რამდენიმე კონტაქტია, აირჩიე სწორი',
    'fuzzy'     => 'ზუსტი დამთხვევა არ არის, მსგავსი სახელები:',
    'none'      => 'კონტაქტი ვერ მოიძებნა',
];
$srcTags = [
    'name' => '<span class="tag tag-name">სახელი ემთხვევა</span>',
    'same' => '<span class="tag tag-same">იგივე მყიდველი სხვა დილზე</span>',
];

$selfUrl = strtok($_SERVER['REQUEST_URI'], '?');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>კონტაქტები ძველი ბაზიდან</title>
    <style>
        :root {
            --bg: #f4f5f7;
            --panel: #fff;
            --text: #00335b;
            --muted: #6b7a8a;
            --danger: #c0392b;
            --warn: #b9770e;
            --ok: #0e7c66;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        h2 { font-size: 15px; margin: 0 0 12px; }
        .sub { color: var(--muted); margin: 0 0 20px; font-size: 14px; line-height: 1.5; }
        .card {
            background: var(--panel);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 28px rgba(0, 51, 91, 0.08);
            margin-bottom: 20px;
        }
        .bar { position: sticky; top: 0; z-index: 5; }
        .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        button, .btn {
            height: 40px;
            border: 0;
            border-radius: 8px;
            padding: 0 18px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            background: var(--text);
            color: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        button.apply { background: var(--ok); }
        button:disabled { opacity: .45; cursor: not-allowed; }
        button.filter { background: #eef3f7; color: var(--text); height: 34px; padding: 0 12px; font-size: 13px; }
        button.filter.active { background: var(--text); color: #fff; }
        button.small { height: 30px; padding: 0 10px; font-size: 12px; }
        button.link {
            display: inline;
            background: none;
            color: var(--text);
            height: auto;
            padding: 0;
            margin-top: 6px;
            font-size: 12px;
            text-align: left;
            text-decoration: underline;
            font-weight: 400;
        }
        input[type=text] {
            height: 34px;
            border: 1px solid #d5dde5;
            border-radius: 8px;
            padding: 0 10px;
            font-size: 13px;
            color: var(--text);
        }
        #text-filter { width: 220px; }
        .counts { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
        .chip { background: #eef3f7; border-radius: 999px; padding: 6px 12px; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #eef1f4; vertical-align: top; }
        th { font-size: 12px; color: var(--muted); }
        td.deal { width: 220px; }
        td.text { width: 260px; }
        tr.has-pick td.deal { box-shadow: inset 3px 0 0 var(--ok); }
        .old-text { font-weight: 600; white-space: pre-wrap; }
        .st { font-weight: 600; font-size: 12px; }
        .st-auto { color: var(--ok); }
        .st-partial, .st-review { color: var(--warn); }
        .st-none { color: var(--danger); }
        .group { margin-bottom: 8px; }
        .group-label { font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        .group-label b { color: var(--text); }
        .note { font-size: 12px; color: var(--warn); margin-bottom: 4px; }
        .note.none { color: var(--danger); }
        .opt { display: flex; gap: 6px; align-items: baseline; padding: 3px 0; cursor: pointer; line-height: 1.4; }
        .opt input { margin: 0; flex: 0 0 auto; position: relative; top: 2px; }
        .tag { font-size: 11px; border-radius: 999px; padding: 1px 8px; white-space: nowrap; }
        .tag-name { background: #e3f4ef; color: var(--ok); }
        .tag-same { background: #e6eefb; color: #2456a6; }
        .tag-fuzzy { background: #fdf1e1; color: var(--warn); }
        .tag-manual { background: #eef3f7; color: var(--text); }
        .manual { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .manual input { width: 220px; height: 30px; }
        .mresults { flex-basis: 100%; }
        .mitem { padding: 5px 8px; border-radius: 6px; cursor: pointer; }
        .mitem:hover { background: #eef3f7; }
        .muted { color: var(--muted); font-size: 12px; }
        .warn { color: var(--danger); font-size: 13px; margin: 8px 0 0; }
        .ok { color: var(--ok); font-weight: 600; }
        a { color: inherit; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>კონტაქტების მიბმა ძველი ბაზიდან</h1>
    <p class="sub">
        დილები, რომლებსაც ველი "მყიდველი ძველი ბაზისთვის" (<?= h(D_OLD_BUYER) ?>) შევსებული აქვთ,
        მაგრამ არც ერთი კონტაქტი არ აქვთ მიბმული. ცალსახა დამთხვევა წინასწარ არის მონიშნული,
        დანარჩენი მონიშნე ხელით ან მოძებნე სახელით / ტელეფონით. მიება ყველა მონიშნული,
        ფილტრით დამალულიც. დილის სხვა ველებს და თანხას არ ეხება.
    </p>

    <?php if ($apply): ?>
        <div class="card">
            <h2>შედეგი</h2>
            <div class="counts">
                <span class="chip">დილი: <?= (int)$applyCounts['deals'] ?></span>
                <span class="chip">მიბმული კონტაქტი: <?= (int)$applyCounts['contacts'] ?></span>
                <span class="chip">გამოტოვდა (უკვე მიბმული): <?= (int)$applyCounts['skipped'] ?></span>
                <span class="chip">შეცდომა: <?= (int)$applyCounts['failed'] ?></span>
            </div>
            <?php if (!$applied): ?>
                <p class="muted">მოსანიშნი არაფერი მოვიდა.</p>
            <?php else: ?>
                <table>
                    <tbody>
                    <?php foreach ($applied as $dealId => $r): ?>
                        <tr>
                            <td><a href="/crm/deal/details/<?= (int)$dealId ?>/" target="_blank">#<?= (int)$dealId ?></a></td>
                            <td>
                                <?php foreach ($r['contacts'] as $cid): ?>
                                    <div>#<?= (int)$cid ?> <?= h($contacts[$cid]['name'] ?? '') ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'bound'): ?>
                                    <span class="ok">მიება</span>
                                <?php elseif ($r['status'] === 'skipped'): ?>
                                    <span class="muted">უკვე მიბმული იყო</span>
                                <?php else: ?>
                                    <span class="warn">შეცდომა: <?= h($r['error']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form class="card bar" method="post" id="apply-form">
        <?= $sessidField ?>
        <input type="hidden" name="apply" value="1">
        <input type="hidden" name="payload" id="payload" value="">
        <div class="counts">
            <span class="chip">ველით შევსებული დილი: <?= count($deals) ?></span>
            <span class="chip">კონტაქტის გარეშე: <?= count($rows) ?></span>
            <span class="chip">კონტაქტი: <?= count($contacts) ?></span>
        </div>
        <div class="row">
            <button type="button" class="filter active" data-filter="all">ყველა (<?= count($rows) ?>)</button>
            <?php foreach ($statusLabels as $st => $label): ?>
                <button type="button" class="filter" data-filter="<?= h($st) ?>"><?= h($label) ?> (<?= (int)$statusCounts[$st] ?>)</button>
            <?php endforeach; ?>
            <input type="text" id="text-filter" placeholder="ფილტრი: სახელი ან დილის ID">
            <span style="flex:1"></span>
            <button type="submit" class="apply" id="apply-btn" disabled>მიბმა</button>
            <a class="btn" href="<?= h($selfUrl) ?>">თავიდან შემოწმება</a>
        </div>
    </form>

    <div class="card">
        <?php if (!$rows): ?>
            <p class="muted">კონტაქტის გარეშე დილი, რომელსაც ველი შევსებული აქვს, არ არის.</p>
        <?php else: ?>
            <table id="rows">
                <thead>
                <tr>
                    <th>დილი</th>
                    <th>მყიდველი ძველი ბაზიდან</th>
                    <th>კონტაქტი</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $dealId => $row):
                    $deal = $row['deal'];
                    $categoryId = (int)($deal['CATEGORY_ID'] ?? 0);
                    $stageId = (string)($deal['STAGE_ID'] ?? '');
                    $project = obcFieldText($deal[D_PROJECT] ?? '');
                    $key = $deal['_key'];
                    $siblings = ($keyCounts[$key] ?? 1) - 1;
                ?>
                    <tr class="deal-row" data-deal="<?= (int)$dealId ?>" data-status="<?= h($row['status']) ?>"
                        data-key="<?= h($key) ?>" data-search="<?= h($key . ' ' . $dealId) ?>">
                        <td class="deal">
                            <a href="/crm/deal/details/<?= (int)$dealId ?>/" target="_blank">#<?= (int)$dealId ?></a>
                            <span class="st st-<?= h($row['status']) ?>"><?= h($statusLabels[$row['status']]) ?></span>
                            <div><?= h($deal['TITLE'] ?? '') ?></div>
                            <div class="muted">
                                <?= h($stageNames[$categoryId][$stageId] ?? $stageId) ?>
                                <?php if ($project !== ''): ?> · <?= h($project) ?><?php endif; ?>
                            </div>
                            <div class="muted"><?= h(number_format((float)($deal['OPPORTUNITY'] ?? 0), 0, '.', ' ') . ' ' . ($deal['CURRENCY_ID'] ?? '')) ?></div>
                        </td>
                        <td class="text">
                            <div class="old-text"><?= h($deal['_text']) ?></div>
                            <?php if ($siblings > 0): ?>
                                <button type="button" class="link copy-same">მონიშვნის გადატანა იგივე ტექსტის <?= (int)$siblings ?> დილზე</button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php foreach ($row['groups'] as $g): ?>
                                <div class="group">
                                    <?php if ($g['label'] !== ''): ?>
                                        <div class="group-label">"<b><?= h($g['label']) ?></b>"</div>
                                    <?php endif; ?>
                                    <?php if (isset($kindNotes[$g['kind']])): ?>
                                        <div class="note <?= $g['kind'] === 'none' ? 'none' : '' ?>"><?= h($kindNotes[$g['kind']]) ?></div>
                                    <?php endif; ?>
                                    <?php foreach ($g['order'] as $cid):
                                        $tags = '';
                                        foreach (array_unique($g['cands'][$cid]) as $src) {
                                            $tags .= $srcTags[$src] ?? '';
                                        }
                                        echo obcOptionHtml($dealId, $cid, obcContactDesc($cid, $contacts, $details), $tags, in_array($cid, $g['selected'], true));
                                    endforeach; ?>
                                    <?php foreach ($g['fuzzy'] as $cid => $score):
                                        $tag = '<span class="tag tag-fuzzy">მსგავსი ' . (int)round($score * 100) . '%</span>';
                                        echo obcOptionHtml($dealId, $cid, obcContactDesc($cid, $contacts, $details), $tag, false);
                                    endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="manual-picks"></div>
                            <div class="manual">
                                <input type="text" class="msearch" placeholder="სხვა კონტაქტი: სახელი ან ტელეფონი">
                                <button type="button" class="small mbtn">ძებნა</button>
                                <div class="mresults"></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<script>
    (function () {
        const SEARCH_URL = <?= json_encode($selfUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const form = document.getElementById('apply-form');
        const applyBtn = document.getElementById('apply-btn');
        const payloadInput = document.getElementById('payload');
        const textFilter = document.getElementById('text-filter');
        const table = document.getElementById('rows');
        const rows = Array.from(document.querySelectorAll('tr.deal-row'));
        let activeFilter = 'all';

        function norm(s) {
            return String(s).toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
        }

        function selection() {
            const map = {};
            document.querySelectorAll('input.pick:checked').forEach(function (cb) {
                const d = cb.dataset.deal;
                map[d] = map[d] || [];
                if (map[d].indexOf(cb.value) === -1) {
                    map[d].push(cb.value);
                }
            });
            return map;
        }

        function totals(map) {
            let contacts = 0;
            Object.keys(map).forEach(function (k) { contacts += map[k].length; });
            return {deals: Object.keys(map).length, contacts: contacts};
        }

        function refreshCounts() {
            const t = totals(selection());
            applyBtn.textContent = 'მიბმა (' + t.deals + ' დილი, ' + t.contacts + ' კონტაქტი)';
            applyBtn.disabled = t.deals === 0;
            rows.forEach(function (r) {
                r.classList.toggle('has-pick', !!r.querySelector('input.pick:checked'));
            });
        }

        function applyFilter() {
            const q = norm(textFilter.value);
            rows.forEach(function (r) {
                const okStatus = activeFilter === 'all' || r.dataset.status === activeFilter;
                const okText = !q || r.dataset.search.indexOf(q) !== -1;
                r.hidden = !(okStatus && okText);
            });
        }

        function addManual(row, id, descHtml) {
            let cb = row.querySelector('input.pick[value="' + id + '"]');
            if (!cb) {
                const label = document.createElement('label');
                label.className = 'opt';
                label.innerHTML = '<input type="checkbox" class="pick"> <span class="desc">' + descHtml + '</span> <span class="tag tag-manual">ხელით</span>';
                cb = label.querySelector('input');
                cb.dataset.deal = row.dataset.deal;
                cb.value = id;
                row.querySelector('.manual-picks').appendChild(label);
            }
            cb.checked = true;
        }

        function runSearch(row) {
            const input = row.querySelector('.msearch');
            const box = row.querySelector('.mresults');
            const q = input.value.trim();
            if (q.length < 2) {
                box.innerHTML = '<div class="muted">მინიმუმ 2 სიმბოლო</div>';
                return;
            }
            box.innerHTML = '<div class="muted">იძებნება...</div>';
            fetch(SEARCH_URL + '?action=search&q=' + encodeURIComponent(q), {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    const items = (data && data.items) || [];
                    if (!items.length) {
                        box.innerHTML = '<div class="muted">ვერ მოიძებნა</div>';
                        return;
                    }
                    box.innerHTML = '';
                    items.forEach(function (it) {
                        const div = document.createElement('div');
                        div.className = 'mitem';
                        div.dataset.id = it.id;
                        div.innerHTML = it.html;
                        box.appendChild(div);
                    });
                })
                .catch(function () {
                    box.innerHTML = '<div class="warn">ძებნა ვერ მოხერხდა</div>';
                });
        }

        function copySame(row, btn) {
            const picked = Array.from(row.querySelectorAll('input.pick:checked')).map(function (cb) {
                return {id: cb.value, html: cb.closest('label').querySelector('.desc').innerHTML};
            });
            const siblings = rows.filter(function (r) { return r !== row && r.dataset.key === row.dataset.key; });
            siblings.forEach(function (r) {
                r.querySelectorAll('input.pick').forEach(function (cb) { cb.checked = false; });
                picked.forEach(function (p) { addManual(r, p.id, p.html); });
            });
            refreshCounts();
            btn.textContent = 'გადატანილია ' + siblings.length + ' დილზე';
        }

        document.querySelectorAll('button.filter').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('button.filter').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                activeFilter = btn.dataset.filter;
                applyFilter();
            });
        });
        textFilter.addEventListener('input', applyFilter);

        if (table) {
            table.addEventListener('change', function (e) {
                if (e.target.matches('input.pick')) {
                    refreshCounts();
                }
            });
            table.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.matches('.msearch')) {
                    e.preventDefault();
                    runSearch(e.target.closest('tr'));
                }
            });
            table.addEventListener('click', function (e) {
                if (e.target.closest('.mitem a')) {
                    return;
                }
                const searchBtn = e.target.closest('.mbtn');
                if (searchBtn) {
                    runSearch(searchBtn.closest('tr'));
                    return;
                }
                const item = e.target.closest('.mitem');
                if (item) {
                    const row = item.closest('tr');
                    addManual(row, item.dataset.id, item.innerHTML);
                    row.querySelector('.mresults').innerHTML = '';
                    row.querySelector('.msearch').value = '';
                    refreshCounts();
                    return;
                }
                const copyBtn = e.target.closest('.copy-same');
                if (copyBtn) {
                    copySame(copyBtn.closest('tr'), copyBtn);
                }
            });
        }

        form.addEventListener('submit', function (e) {
            const map = selection();
            const t = totals(map);
            if (!t.deals) {
                e.preventDefault();
                return;
            }
            const ok = confirm(
                'დარწმუნებული ხარ?\n\n' +
                t.deals + ' დილზე მიება ' + t.contacts + ' კონტაქტი.'
            );
            if (!ok) {
                e.preventDefault();
                return;
            }
            payloadInput.value = JSON.stringify(map);
            applyBtn.disabled = true;
            applyBtn.textContent = 'მიმდინარეობს მიბმა...';
        });

        refreshCounts();
    })();
</script>
</body>
</html>
