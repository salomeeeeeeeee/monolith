<?php
/**
 * გაყიდულ (WON) დილებზე კვ.მ ფასის გამოთვლა:
 * OPPORTUNITY / სრული ფართი (UF_CRM_1779277886804) → კვ/მ ღირებულება $ (UF_CRM_1779277671391)
 *
 * UI:     https://crm.monolith.ge/crm/deal/migration/kvmPriceFromDealAmount.php
 * Dry:    ?ajax=1&project=Dighomi
 * Apply:  ?ajax=1&project=Dighomi&apply=1
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');
if (function_exists('session_write_close')) {
    session_write_close();
}

CModule::IncludeModule('crm');

define('D_PROJECT', 'UF_CRM_1779277729207');
define('D_TYPE', 'UF_CRM_1779277898205');
define('D_AREA', 'UF_CRM_1779277886804');
define('D_KVM_PRICE', 'UF_CRM_1779277671391');
define('WON_STAGE', 'WON');
define('TARGET_CURRENCY', 'USD');

$DEFAULT_PROJECT = 'Dighomi';
$ALL_TYPES = '__all__';

$isAjax = isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === '1';
$apply = isset($_REQUEST['apply']) && $_REQUEST['apply'] === '1';
$onlyEmpty = isset($_REQUEST['only_empty']) && $_REQUEST['only_empty'] === '1';
$filterProject = trim((string)($_REQUEST['project'] ?? $DEFAULT_PROJECT));
$filterType = trim((string)($_REQUEST['type'] ?? $ALL_TYPES));

function kvmFieldText($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    return trim((string)$value);
}

function kvmParseNumber($value)
{
    $text = kvmFieldText($value);
    if ($text === '') {
        return null;
    }
    $text = str_replace(["\xc2\xa0", ' ', '$', '₾', '€'], '', $text);
    $text = str_replace(',', '.', $text);
    if (!is_numeric($text)) {
        return null;
    }
    return (float)$text;
}

function kvmValsEqual($a, $b)
{
    return mb_strtolower(trim((string)$a)) === mb_strtolower(trim((string)$b));
}

function kvmLoadWonDeals()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    $res = CCrmDeal::GetListEx(
        ['ID' => 'ASC'],
        ['STAGE_ID' => WON_STAGE, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        [
            'ID', 'TITLE', 'STAGE_ID', 'OPPORTUNITY', 'CURRENCY_ID', 'IS_MANUAL_OPPORTUNITY',
            D_PROJECT, D_TYPE, D_AREA, D_KVM_PRICE,
        ]
    );
    while ($deal = $res->Fetch()) {
        $cache[] = $deal;
    }
    return $cache;
}

function kvmBuildFilterMap(array $deals)
{
    $map = [];
    foreach ($deals as $deal) {
        $project = kvmFieldText($deal[D_PROJECT] ?? '');
        if ($project === '') {
            continue;
        }
        $type = kvmFieldText($deal[D_TYPE] ?? '');
        if (!isset($map[$project])) {
            $map[$project] = [];
        }
        if ($type !== '') {
            $map[$project][$type] = true;
        }
    }
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($map as $project => $types) {
        $keys = array_keys($types);
        natcasesort($keys);
        $map[$project] = array_values($keys);
    }
    return $map;
}

if ($isAjax) {
    $counts = [
        'deals' => 0,
        'no_area' => 0,
        'no_amount' => 0,
        'other_currency' => 0,
        'has_value' => 0,
        'already_same' => 0,
        'would_update' => 0,
        'updated' => 0,
        'failed' => 0,
    ];
    $results = [];

    foreach (kvmLoadWonDeals() as $deal) {
        $project = kvmFieldText($deal[D_PROJECT] ?? '');
        if (!kvmValsEqual($project, $filterProject)) {
            continue;
        }
        $type = kvmFieldText($deal[D_TYPE] ?? '');
        if ($filterType !== $ALL_TYPES && $filterType !== '' && !kvmValsEqual($type, $filterType)) {
            continue;
        }

        $counts['deals']++;
        $dealId = (int)$deal['ID'];
        $amount = round((float)($deal['OPPORTUNITY'] ?? 0), 2);
        $area = kvmParseNumber($deal[D_AREA] ?? '');
        $kvmBefore = kvmParseNumber($deal[D_KVM_PRICE] ?? '');
        $currency = (string)($deal['CURRENCY_ID'] ?? '');

        $row = [
            'deal_id' => $dealId,
            'deal_title' => $deal['TITLE'] ?? '',
            'project' => $project,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'area' => $area,
            'kvm_before' => $kvmBefore,
            'kvm_new' => null,
        ];

        if ($currency !== '' && $currency !== TARGET_CURRENCY) {
            $row['result'] = 'other_currency';
            $counts['other_currency']++;
            $results[] = $row;
            continue;
        }
        if ($amount <= 0) {
            $row['result'] = 'no_amount';
            $counts['no_amount']++;
            $results[] = $row;
            continue;
        }
        if ($area === null || $area <= 0) {
            $row['result'] = 'no_area';
            $counts['no_area']++;
            $results[] = $row;
            continue;
        }

        $kvmNew = round($amount / $area, 2);
        $row['kvm_new'] = $kvmNew;

        if ($onlyEmpty && $kvmBefore !== null && $kvmBefore > 0) {
            $row['result'] = 'has_value';
            $counts['has_value']++;
            $results[] = $row;
            continue;
        }
        if ($kvmBefore !== null && abs($kvmBefore - $kvmNew) < 0.01) {
            $row['result'] = 'already_same';
            $counts['already_same']++;
            $results[] = $row;
            continue;
        }
        if (!$apply) {
            $row['result'] = 'would_update';
            $counts['would_update']++;
            $results[] = $row;
            continue;
        }

        // OPPORTUNITY / CURRENCY_ID / IS_MANUAL_OPPORTUNITY უცვლელად გადაეცემა,
        // რომ Update-მა დილის თანხა თავიდან არ გადათვალოს
        $crmDeal = new CCrmDeal(false);
        $fieldsUpdate = [
            D_KVM_PRICE => $kvmNew,
            'OPPORTUNITY' => $amount,
            'IS_MANUAL_OPPORTUNITY' => ($deal['IS_MANUAL_OPPORTUNITY'] ?? '') === 'Y' ? 'Y' : 'N',
        ];
        if ($currency !== '') {
            $fieldsUpdate['CURRENCY_ID'] = $currency;
        }

        $ok = $crmDeal->Update($dealId, $fieldsUpdate);
        if (!$ok) {
            $row['result'] = 'failed';
            $row['error'] = $crmDeal->LAST_ERROR;
            $counts['failed']++;
            $results[] = $row;
            continue;
        }

        $verifyRes = CCrmDeal::GetListEx(
            [],
            ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1],
            ['ID', 'OPPORTUNITY', D_KVM_PRICE]
        );
        $verify = $verifyRes ? $verifyRes->Fetch() : false;
        $row['kvm_after'] = kvmParseNumber($verify[D_KVM_PRICE] ?? '');
        $row['amount_after'] = round((float)($verify['OPPORTUNITY'] ?? 0), 2);
        $row['result'] = 'updated';
        $counts['updated']++;
        $results[] = $row;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'apply' => $apply,
        'filter' => [
            'project' => $filterProject,
            'type' => $filterType,
            'only_empty' => $onlyEmpty,
        ],
        'counts' => $counts,
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
    die();
}

$FILTER_MAP = kvmBuildFilterMap(kvmLoadWonDeals());
$PROJECT_OPTIONS = array_keys($FILTER_MAP);
if (!in_array($filterProject, $PROJECT_OPTIONS, true) && !empty($PROJECT_OPTIONS)) {
    $filterProject = in_array($DEFAULT_PROJECT, $PROJECT_OPTIONS, true) ? $DEFAULT_PROJECT : $PROJECT_OPTIONS[0];
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>კვ.მ ფასი დილის თანხიდან</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f5f7;
            --panel: #ffffff;
            --text: #00335b;
            --muted: #6b7a8a;
            --primary: #00335b;
            --accent: #72c4b1;
            --warn: #c47a3a;
            --danger: #b94a48;
            --ok: #2f8f6b;
            --line: #dde2e8;
            --shadow: 0 10px 28px rgba(0, 51, 91, 0.08);
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--text);
            font-family: "Montserrat", "Segoe UI", sans-serif;
        }
        .page { max-width: none; margin: 0 auto; padding: 16px 20px 40px; width: 100%; }
        .hero {
            background: linear-gradient(135deg, #00335b 0%, #0a4a75 55%, #1a6b7a 100%);
            color: #fff;
            border-radius: 10px;
            padding: 22px 24px;
            box-shadow: var(--shadow);
            margin-bottom: 16px;
        }
        .hero h1 { margin: 0 0 8px; font-size: 22px; font-weight: 700; }
        .hero p { margin: 0; opacity: .92; font-size: 14px; line-height: 1.55; max-width: 820px; }
        .grid { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 16px; align-items: start; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: var(--shadow);
            padding: 16px;
        }
        .panel h2 { margin: 0 0 12px; font-size: 15px; font-weight: 700; }
        .desc-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 8px;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.45;
        }
        .desc-list li { padding-left: 14px; position: relative; }
        .desc-list li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 7px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent);
        }
        .field { display: grid; gap: 6px; margin-bottom: 12px; }
        .field label { font-size: 12px; font-weight: 600; color: var(--muted); }
        .field select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 10px 12px;
            font: inherit;
            color: var(--text);
            background: #fff;
        }
        .actions { display: grid; gap: 8px; margin-top: 4px; }
        button {
            border: 0;
            border-radius: 6px;
            padding: 11px 14px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        button:disabled { opacity: .45; cursor: not-allowed; }
        .btn-diff { background: var(--accent); color: #08353a; }
        .btn-run { background: var(--primary); color: #fff; }
        .btn-ghost { background: #eef2f6; color: var(--text); }
        .stats { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .stat {
            background: #f7f9fb;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 8px 10px;
            min-width: 110px;
        }
        .stat b { display: block; font-size: 16px; line-height: 1.2; }
        .stat span { font-size: 11px; color: var(--muted); }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .toolbar .left { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            background: #fff;
            color: var(--muted);
            cursor: pointer;
            user-select: none;
        }
        .chip input { margin: 0; }
        .status { font-size: 13px; color: var(--muted); min-height: 20px; }
        .status.err { color: var(--danger); }
        .status.ok { color: var(--ok); }
        .table-wrap {
            overflow-x: hidden;
            overflow-y: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
            max-height: 62vh;
        }
        table { width: 100%; border-collapse: collapse; font-size: 12px; table-layout: fixed; }
        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            word-break: break-word;
        }
        th {
            position: sticky;
            top: 0;
            background: #f0f4f8;
            z-index: 1;
            font-weight: 600;
            white-space: nowrap;
        }
        th:nth-child(1), td:nth-child(1) { width: 120px; }
        th:nth-child(2), td:nth-child(2) { width: 26%; }
        th:nth-child(3), td:nth-child(3) { width: 14%; }
        th:nth-child(4), td:nth-child(4) { width: 12%; }
        th:nth-child(5), td:nth-child(5) { width: 14%; }
        th:nth-child(6), td:nth-child(6) { width: 20%; }
        tr:hover td { background: #fafcfd; }
        .badge {
            display: inline-block;
            border-radius: 4px;
            padding: 2px 7px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .b-would { background: #fff4e5; color: var(--warn); }
        .b-same { background: #eaf7f1; color: var(--ok); }
        .b-updated { background: #e5f3ff; color: #1a5f9e; }
        .b-fail, .b-miss { background: #fdecea; color: var(--danger); }
        .diff-old { color: var(--danger); text-decoration: line-through; margin-right: 6px; }
        .diff-new { color: var(--ok); font-weight: 600; }
        a.entity-link { color: var(--primary); text-decoration: none; font-weight: 600; }
        a.entity-link:hover { text-decoration: underline; color: #0a4a75; }
        .empty { padding: 28px; text-align: center; color: var(--muted); font-size: 13px; }
        .spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(0,0,0,.15);
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin .7s linear infinite;
            vertical-align: -1px;
            margin-right: 6px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <h1>კვ.მ ფასი დილის თანხიდან</h1>
        <p>
            გაყიდულ (WON) დილებზე ითვლის კვადრატულის ფასს: დილის ფასი ÷ სრული ფართი (მონოლითი)
            და წერს „კვ/მ ღირებულება $“ ველში. დილის თანხას არ ცვლის.
        </p>
    </div>

    <div class="grid">
        <aside class="panel">
            <h2>ფილტრი</h2>
            <div class="field">
                <label for="project">პროექტი</label>
                <select id="project">
                    <?php foreach ($PROJECT_OPTIONS as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $opt === $filterProject ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="type">ფართის ტიპი</label>
                <select id="type"></select>
            </div>

            <div class="actions">
                <button type="button" class="btn-diff" id="btnDiff">განსხვავების ნახვა</button>
                <button type="button" class="btn-run" id="btnRun" disabled>გაშვება</button>
                <button type="button" class="btn-ghost" id="btnReset">გასუფთავება</button>
            </div>

            <h2 style="margin-top:18px">რას აკეთებს</h2>
            <ul class="desc-list">
                <li>იღებს WON სტადიის დილებს არჩეული პროექტით</li>
                <li>კვ.მ ფასი = დილის ფასი ÷ სრული ფართი (2 ათწილადამდე)</li>
                <li>წერს მხოლოდ „კვ/მ ღირებულება $“ ველში</li>
                <li>ფართის ან თანხის გარეშე დილს ტოვებს უცვლელად</li>
                <li>არა-USD ვალუტის დილს არ ეხება</li>
            </ul>
        </aside>

        <section class="panel">
            <div class="toolbar">
                <div class="left">
                    <label class="chip"><input type="checkbox" id="onlyDiff" checked> მხოლოდ განსხვავება</label>
                    <label class="chip"><input type="checkbox" id="onlyEmpty"> მხოლოდ ცარიელ ველზე ჩაწერა</label>
                </div>
                <div class="status" id="statusMsg">აირჩიე ფილტრი და დააჭირე „განსხვავების ნახვა“.</div>
            </div>
            <div class="stats" id="stats"></div>
            <div class="table-wrap" id="tableWrap">
                <div class="empty">ჯერ შედეგი არაა.</div>
            </div>
        </section>
    </div>
</div>

<script>
(function () {
    const el = (id) => document.getElementById(id);
    const project = el('project');
    const type = el('type');
    const btnDiff = el('btnDiff');
    const btnRun = el('btnRun');
    const btnReset = el('btnReset');
    const statusMsg = el('statusMsg');
    const stats = el('stats');
    const tableWrap = el('tableWrap');
    const onlyDiff = el('onlyDiff');
    const onlyEmpty = el('onlyEmpty');

    const ALL_TYPES = <?= json_encode($ALL_TYPES) ?>;
    const filterMap = <?= json_encode($FILTER_MAP, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    let lastPayload = null;
    let busy = false;

    const RESULT_LABELS = {
        would_update: 'განსხვავება',
        already_same: 'იგივეა',
        updated: 'განახლდა',
        failed: 'შეცდომა',
        no_area: 'ფართი არაა',
        no_amount: 'თანხა არაა',
        other_currency: 'სხვა ვალუტა',
        has_value: 'უკვე შევსებულია',
    };

    const RESULT_CLASS = {
        would_update: 'b-would',
        already_same: 'b-same',
        updated: 'b-updated',
        failed: 'b-fail',
        no_area: 'b-miss',
        no_amount: 'b-miss',
        other_currency: 'b-miss',
        has_value: 'b-same',
    };

    function fillTypes() {
        const prev = type.value;
        const types = filterMap[project.value] || [];
        type.innerHTML = '';
        const all = document.createElement('option');
        all.value = ALL_TYPES;
        all.textContent = 'ყველა ტიპი';
        type.appendChild(all);
        types.forEach(function (val) {
            const opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val;
            if (val === prev) opt.selected = true;
            type.appendChild(opt);
        });
    }

    function onFilterChanged() {
        btnRun.disabled = true;
        statusMsg.className = 'status';
        statusMsg.textContent = 'ფილტრი შეიცვალა — თავიდან ნახე განსხვავება.';
    }

    function money(n) {
        const x = Number(n || 0);
        return x.toLocaleString('ka-GE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function num(n, digits) {
        if (n === null || n === undefined || n === '') return '—';
        return Number(n).toLocaleString('ka-GE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    }

    function setBusy(v, text) {
        busy = v;
        btnDiff.disabled = v;
        btnRun.disabled = v || !lastPayload || !(lastPayload.counts && lastPayload.counts.would_update > 0);
        statusMsg.className = 'status';
        if (text) {
            statusMsg.innerHTML = (v ? '<span class="spinner"></span>' : '') + text;
        }
    }

    function filterParams() {
        const p = new URLSearchParams();
        p.set('ajax', '1');
        p.set('project', project.value);
        p.set('type', type.value);
        if (onlyEmpty.checked) p.set('only_empty', '1');
        return p;
    }

    async function run(apply) {
        if (busy) return;
        if (apply) {
            const n = lastPayload && lastPayload.counts ? lastPayload.counts.would_update : 0;
            const ok = confirm(
                'დარწმუნებული ხარ?\n\n' +
                'ფილტრი: ' + project.value + ' / ' + (type.value === ALL_TYPES ? 'ყველა ტიპი' : type.value) + '\n' +
                'განახლდება დაახლ. ' + n + ' დილი.\n\n' +
                'ეს რეალურად ჩაწერს CRM-ში.'
            );
            if (!ok) return;
        }

        setBusy(true, apply ? 'მიმდინარეობს ჩაწერა…' : 'მიმდინარეობს შედარება…');
        try {
            const p = filterParams();
            if (apply) p.set('apply', '1');
            const res = await fetch(location.pathname + '?' + p.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            lastPayload = data;
            render(data);
            statusMsg.className = 'status ok';
            statusMsg.textContent = apply
                ? ('დასრულდა. განახლდა: ' + (data.counts.updated || 0) + ', შეცდომა: ' + (data.counts.failed || 0))
                : ('ნაპოვნია დილი: ' + (data.counts.deals || 0) + ', განსხვავება: ' + (data.counts.would_update || 0));
            btnRun.disabled = apply || !(data.counts && data.counts.would_update > 0);
        } catch (e) {
            statusMsg.className = 'status err';
            statusMsg.textContent = 'შეცდომა: ' + (e && e.message ? e.message : e);
            btnRun.disabled = true;
        } finally {
            busy = false;
            btnDiff.disabled = false;
        }
    }

    function visibleRows(data) {
        let rows = data.results || [];
        if (onlyDiff.checked) {
            rows = rows.filter((r) => r.result === 'would_update' || r.result === 'updated' || r.result === 'failed');
        }
        return rows;
    }

    function renderStats(counts) {
        const items = [
            ['deals', 'დილი'],
            ['would_update', 'განსხვავება'],
            ['already_same', 'იგივეა'],
            ['updated', 'განახლდა'],
            ['has_value', 'შევსებული'],
            ['no_area', 'ფართი არაა'],
            ['no_amount', 'თანხა არაა'],
            ['other_currency', 'სხვა ვალუტა'],
            ['failed', 'შეცდომა'],
        ];
        stats.innerHTML = items.map(([k, label]) => (
            '<div class="stat"><b>' + (counts[k] || 0) + '</b><span>' + label + '</span></div>'
        )).join('');
    }

    function kvmCell(r) {
        const showDiff = r.result === 'would_update' || r.result === 'updated' || r.result === 'failed';
        const after = (r.result === 'updated' && r.kvm_after != null) ? r.kvm_after : r.kvm_new;
        if (!showDiff || after == null) {
            return num(r.kvm_before, 2);
        }
        const before = r.kvm_before == null ? '—' : money(r.kvm_before);
        return '<span class="diff-old">' + before + '</span><span class="diff-new">' + money(after) + '</span>';
    }

    function render(data) {
        renderStats(data.counts || {});
        const rows = visibleRows(data);
        if (!rows.length) {
            tableWrap.innerHTML = '<div class="empty">ამ ფილტრებით საჩვენებელი ჩანაწერი არაა.</div>';
            return;
        }

        let html = '<table><thead><tr>' +
            '<th>შედეგი</th><th>დილი</th><th>დილის ფასი</th><th>სრული ფართი</th><th>ტიპი</th><th>კვ.მ ფასი</th>' +
            '</tr></thead><tbody>';

        for (const r of rows) {
            const badge = '<span class="badge ' + (RESULT_CLASS[r.result] || '') + '">' +
                (RESULT_LABELS[r.result] || r.result) + '</span>';
            const dealCell = '<a class="entity-link" href="/crm/deal/details/' + r.deal_id + '/" target="_blank" rel="noopener">#' + r.deal_id + '</a>' +
                (r.deal_title ? '<div style="color:#6b7a8a">' + escapeHtml(r.deal_title) + '</div>' : '');

            html += '<tr>' +
                '<td>' + badge + (r.error ? '<div style="color:#b94a48;margin-top:4px">' + escapeHtml(r.error) + '</div>' : '') + '</td>' +
                '<td>' + dealCell + '</td>' +
                '<td>' + money(r.amount) + ' ' + escapeHtml(r.currency || '') + '</td>' +
                '<td>' + num(r.area, 2) + '</td>' +
                '<td>' + escapeHtml(r.type || '—') + '</td>' +
                '<td>' + kvmCell(r) + '</td>' +
                '</tr>';
        }
        html += '</tbody></table>';
        tableWrap.innerHTML = html;
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    btnDiff.addEventListener('click', () => run(false));
    btnRun.addEventListener('click', () => run(true));
    btnReset.addEventListener('click', () => {
        lastPayload = null;
        stats.innerHTML = '';
        tableWrap.innerHTML = '<div class="empty">ჯერ შედეგი არაა.</div>';
        statusMsg.className = 'status';
        statusMsg.textContent = 'აირჩიე ფილტრი და დააჭირე „განსხვავების ნახვა“.';
        btnRun.disabled = true;
    });
    onlyDiff.addEventListener('change', () => lastPayload && render(lastPayload));
    onlyEmpty.addEventListener('change', onFilterChanged);
    project.addEventListener('change', () => { fillTypes(); onFilterChanged(); });
    type.addEventListener('change', onFilterChanged);

    fillTypes();
})();
</script>
</body>
</html>
<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
