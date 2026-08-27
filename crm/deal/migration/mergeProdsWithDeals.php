<?php
/**
 * WON დილებზე პროდუქტის მიბმა: პროექტი + ფართის ტიპი,
 * მატჩი სექტორი / ბლოკი / სართული / ნომერი.
 * დილის OPPORTUNITY არ იცვლება (IS_MANUAL_OPPORTUNITY = Y).
 *
 * UI: https://crm.monolith.ge/crm/deal/test.php
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');
if (function_exists('session_write_close')) {
    session_write_close();
}

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');
CModule::IncludeModule('catalog');

define('PRODUCT_IBLOCK_ID', 14);
define('PROP_OWNER_DEAL', 'ownerDeal');
define('PROP_OWNER_CONTACT', 'ownerContact');
define('PROP_OWNER_COMPANY', 'ownerCompany');
define('D_PROJECT', 'UF_CRM_1779277729207');
define('D_TYPE', 'UF_CRM_1779277898205');
define('D_SECTOR', 'UF_CRM_1781768590754');
define('D_BLOCK', 'UF_CRM_1779277644355');
define('D_FLOOR', 'UF_CRM_1779277828822');
define('D_NUMBER', 'UF_CRM_1779277613798');

$run = isset($_REQUEST['run']) && $_REQUEST['run'] === '1';
$apply = isset($_REQUEST['apply']) && $_REQUEST['apply'] === '1';
$filterProject = trim((string)($_REQUEST['project'] ?? ''));
$filterType = trim((string)($_REQUEST['type'] ?? ''));

function normVal($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    $value = trim((string)$value);
    $value = str_replace(["\xc2\xa0", ' '], '', $value);
    $value = str_replace(',', '.', $value);
    return mb_strtolower($value);
}

function numVal($value)
{
    $value = normVal($value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return round((float)$value, 4);
}

function valsEqual($a, $b)
{
    $na = numVal($a);
    $nb = numVal($b);
    if ($na !== null && $nb !== null) {
        return abs($na - $nb) < 0.0001;
    }
    return normVal($a) === normVal($b);
}

function formatCrmBind($entityType, $id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return '';
    }
    switch ($entityType) {
        case 'deal':
            return 'D_' . $id;
        case 'contact':
            return 'C_' . $id;
        case 'company':
            return 'CO_' . $id;
        default:
            return (string)$id;
    }
}

function resolveDealContactId(array $deal)
{
    $contactId = (int)($deal['CONTACT_ID'] ?? 0);
    if ($contactId > 0) {
        return $contactId;
    }
    if (class_exists('\Bitrix\Crm\Binding\DealContactTable')) {
        $contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs((int)$deal['ID']);
        if (!empty($contactIds)) {
            return (int)$contactIds[0];
        }
    }
    return 0;
}

function dealHasProducts($dealId)
{
    $res = CCrmProductRow::GetList(
        ['ID' => 'ASC'],
        ['OWNER_TYPE' => 'D', 'OWNER_ID' => (int)$dealId],
        false,
        ['nTopCount' => 1],
        ['ID']
    );
    return (bool)$res->Fetch();
}

function findMatchingProducts(array $deal)
{
    $project = trim((string)($deal[D_PROJECT] ?? ''));
    $type    = trim((string)($deal[D_TYPE] ?? ''));
    $sector  = trim((string)($deal[D_SECTOR] ?? ''));
    $block   = trim((string)($deal[D_BLOCK] ?? ''));
    $floor   = trim((string)($deal[D_FLOOR] ?? ''));
    $number  = trim((string)($deal[D_NUMBER] ?? ''));

    $criteria = [
        'project' => $project,
        'type'    => $type,
        'sector'  => $sector,
        'block'   => $block,
        'floor'   => $floor,
        'number'  => $number,
    ];

    $filter = [
        'IBLOCK_ID' => PRODUCT_IBLOCK_ID,
        'ACTIVE'    => 'Y',
        'CHECK_PERMISSIONS' => 'N',
    ];
    if ($project !== '') {
        $filter['PROPERTY___VO9RG4'] = $project;
    }
    if ($sector !== '') {
        $filter['PROPERTY__3BU0JH'] = $sector;
    }
    if ($block !== '') {
        $filter['PROPERTY__L24CUB'] = $block;
    }
    if ($number !== '') {
        $filter['PROPERTY___6KWOWZ'] = $number;
    }

    $matches = [];
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        $filter,
        false,
        false,
        ['ID', 'NAME', 'IBLOCK_ID']
    );

    while ($ob = $res->GetNextElement()) {
        $fields = $ob->GetFields();
        $props  = $ob->GetProperties();

        $prodProject = $props['__VO9RG4']['VALUE'] ?? '';
        $prodType    = $props['__X1GCRZ']['VALUE'] ?? '';
        $prodSector  = $props['_3BU0JH']['VALUE'] ?? '';
        $prodBlock   = $props['_L24CUB']['VALUE'] ?? '';
        $prodFloor   = $props['_FTRIDL']['VALUE'] ?? '';
        $prodNumber  = $props['__6KWOWZ']['VALUE'] ?? '';

        if ($project !== '' && !valsEqual($prodProject, $project)) {
            continue;
        }
        if ($type !== '' && !valsEqual($prodType, $type)) {
            continue;
        }
        if ($sector !== '' && !valsEqual($prodSector, $sector)) {
            continue;
        }
        if ($block !== '' && !valsEqual($prodBlock, $block)) {
            continue;
        }
        if ($floor !== '' && !valsEqual($prodFloor, $floor)) {
            continue;
        }
        if ($number !== '' && !valsEqual($prodNumber, $number)) {
            continue;
        }

        $priceRow = CPrice::GetBasePrice((int)$fields['ID']);
        $matches[] = [
            'ID'      => (int)$fields['ID'],
            'NAME'    => $fields['NAME'],
            'PRICE'   => (float)($priceRow['PRICE'] ?? 0),
            'project' => $prodProject,
            'type'    => $prodType,
            'sector'  => $prodSector,
            'block'   => $prodBlock,
            'floor'   => $prodFloor,
            'number'  => $prodNumber,
        ];
    }

    return [$criteria, $matches];
}

function restoreDealOpportunity($dealId, $opportunity, $currencyId)
{
    $crmDeal = new CCrmDeal(false);
    $fields = [
        'IS_MANUAL_OPPORTUNITY' => 'Y',
        'OPPORTUNITY' => $opportunity,
    ];
    if ($currencyId !== '') {
        $fields['CURRENCY_ID'] = $currencyId;
    }
    return (bool)$crmDeal->Update($dealId, $fields);
}

function loadProjectTypeMap()
{
    $map = [];
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => PRODUCT_IBLOCK_ID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'IBLOCK_ID']
    );
    while ($ob = $res->GetNextElement()) {
        $props = $ob->GetProperties();
        $project = trim((string)($props['__VO9RG4']['VALUE'] ?? ''));
        $type = trim((string)($props['__X1GCRZ']['VALUE'] ?? ''));
        if ($project === '' || $type === '') {
            continue;
        }
        if (!isset($map[$project])) {
            $map[$project] = [];
        }
        $map[$project][$type] = true;
    }
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($map as $project => $types) {
        $keys = array_keys($types);
        natcasesort($keys);
        $map[$project] = array_values($keys);
    }
    return $map;
}

$projectTypeMap = loadProjectTypeMap();

$results = [];
$counts = [
    'deals' => 0,
    'already_has_product' => 0,
    'missing_fields' => 0,
    'not_found' => 0,
    'ambiguous' => 0,
    'matched' => 0,
    'attached' => 0,
    'failed' => 0,
];

$select = [
    'ID', 'TITLE', 'CONTACT_ID', 'COMPANY_ID',
    'OPPORTUNITY', 'CURRENCY_ID', 'IS_MANUAL_OPPORTUNITY',
    D_PROJECT, D_TYPE, D_SECTOR, D_BLOCK, D_FLOOR, D_NUMBER,
];

$processDeal = function ($deal) use ($apply, &$results, &$counts) {
    $dealId = (int)$deal['ID'];
    $counts['deals']++;
    $row = [
        'deal_id' => $dealId,
        'title' => $deal['TITLE'] ?? '',
        'opportunity_before' => (float)($deal['OPPORTUNITY'] ?? 0),
        'currency' => $deal['CURRENCY_ID'] ?? '',
        'status' => '',
    ];

    if (dealHasProducts($dealId)) {
        $row['status'] = 'already_has_product';
        $counts['already_has_product']++;
        $results[] = $row;
        return;
    }

    [$criteria, $matches] = findMatchingProducts($deal);
    $row['criteria'] = $criteria;

    $required = ['project', 'type', 'sector', 'block', 'floor', 'number'];
    foreach ($required as $key) {
        if (trim((string)($criteria[$key] ?? '')) === '') {
            $row['status'] = 'missing_fields';
            $counts['missing_fields']++;
            $results[] = $row;
            return;
        }
    }

    if (count($matches) === 0) {
        $row['status'] = 'not_found';
        $counts['not_found']++;
        $results[] = $row;
        return;
    }

    if (count($matches) > 1) {
        $row['status'] = 'ambiguous';
        $row['matches'] = $matches;
        $counts['ambiguous']++;
        $results[] = $row;
        return;
    }

    $product = $matches[0];
    $row['product'] = $product;
    $counts['matched']++;

    if (!$apply) {
        $row['status'] = 'would_attach';
        $results[] = $row;
        return;
    }

    $originalOpportunity = (float)($deal['OPPORTUNITY'] ?? 0);
    $currencyId = (string)($deal['CURRENCY_ID'] ?? '');

    $saved = CCrmDeal::SaveProductRows($dealId, [[
        'PRODUCT_ID' => $product['ID'],
        'PRICE'      => $product['PRICE'],
        'QUANTITY'   => 1,
    ]]);

    if (!$saved) {
        $row['status'] = 'save_failed';
        $counts['failed']++;
        $results[] = $row;
        return;
    }

    $restored = restoreDealOpportunity($dealId, $originalOpportunity, $currencyId);
    $row['opportunity_restored'] = $restored;

    $verifyRes = CCrmDeal::GetListEx(
        [],
        ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['nTopCount' => 1],
        ['ID', 'OPPORTUNITY', 'IS_MANUAL_OPPORTUNITY']
    );
    $verify = $verifyRes ? $verifyRes->Fetch() : false;
    $row['opportunity_after'] = (float)($verify['OPPORTUNITY'] ?? 0);
    $row['is_manual_opportunity'] = $verify['IS_MANUAL_OPPORTUNITY'] ?? '';

    $contactId = resolveDealContactId($deal);
    $companyId = (int)($deal['COMPANY_ID'] ?? 0);
    $ownerProps = [
        PROP_OWNER_DEAL => formatCrmBind('deal', $dealId),
    ];
    if ($contactId > 0) {
        $ownerProps[PROP_OWNER_CONTACT] = formatCrmBind('contact', $contactId);
    }
    if ($companyId > 0) {
        $ownerProps[PROP_OWNER_COMPANY] = formatCrmBind('company', $companyId);
    }

    try {
        CIBlockElement::SetPropertyValuesEx($product['ID'], PRODUCT_IBLOCK_ID, $ownerProps);
    } catch (Throwable $e) {
        $row['owner_sync_error'] = $e->getMessage();
    }

    $row['status'] = 'attached';
    $counts['attached']++;
    $results[] = $row;
};

if ($run && $filterProject !== '' && $filterType !== '') {
    $arFilter = [
        'CATEGORY_ID' => 0,
        'STAGE_ID' => 'WON',
        D_PROJECT => $filterProject,
        'CHECK_PERMISSIONS' => 'N',
    ];
    $res = CCrmDeal::GetListEx(
        ['ID' => 'ASC'],
        $arFilter,
        false,
        false,
        array_merge($select, ['STAGE_ID', 'CATEGORY_ID'])
    );
    while ($deal = $res->Fetch()) {
        $type = trim((string)($deal[D_TYPE] ?? ''));
        if (!valsEqual($type, $filterType)) {
            continue;
        }
        $processDeal($deal);
    }
}

$statusLabels = [
    'already_has_product' => 'უკვე აქვს პროდუქტი',
    'missing_fields' => 'აკლია ველი',
    'not_found' => 'პროდუქტი ვერ მოიძებნა',
    'ambiguous' => 'რამდენიმე მატჩი',
    'would_attach' => 'მიება (dry run)',
    'attached' => 'მიება',
    'save_failed' => 'შეცდომა',
];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>პროდუქტის მიბმა დილებზე</title>
    <style>
        :root {
            --bg: #f4f5f7;
            --panel: #fff;
            --text: #00335b;
            --muted: #6b7a8a;
            --accent: #72c4b1;
            --danger: #c0392b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .sub { color: var(--muted); margin: 0 0 20px; font-size: 14px; }
        .card {
            background: var(--panel);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 28px rgba(0, 51, 91, 0.08);
            margin-bottom: 20px;
        }
        .row { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
        label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; }
        select, button {
            height: 40px;
            border-radius: 8px;
            font-size: 14px;
        }
        select {
            min-width: 220px;
            border: 1px solid #d5dce3;
            padding: 0 10px;
            background: #fff;
            color: var(--text);
        }
        .check { display: flex; align-items: center; gap: 8px; height: 40px; font-size: 14px; }
        button {
            border: 0;
            padding: 0 18px;
            background: var(--text);
            color: #fff;
            cursor: pointer;
            font-weight: 600;
        }
        button.apply { background: #0e7c66; }
        button:disabled { opacity: .5; cursor: not-allowed; }
        .counts { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        .chip {
            background: #eef3f7;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 13px;
        }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f4; vertical-align: top; }
        th { font-size: 12px; color: var(--muted); }
        .st-would_attach, .st-attached { color: #0e7c66; font-weight: 600; }
        .st-not_found, .st-missing_fields, .st-save_failed, .st-ambiguous { color: var(--danger); font-weight: 600; }
        .st-already_has_product { color: var(--muted); }
        .warn { color: var(--danger); font-size: 13px; margin-top: 8px; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>პროდუქტის მიბმა WON დილებზე</h1>
    <p class="sub">აირჩიე პროექტი და ფართის ტიპი. სექტორი + ბლოკი + სართული + ნომერი ველებით იძებნება შესაბამისი პროდუქტი და ებმევა დილზე. დილის თანხა არ იცვლება. პროდუქტზეც ივსება მფლობელის დილი და კონტაქტი/კომპანია</p>

    <form class="card" method="get" id="bind-form">
        <input type="hidden" name="run" value="1">
        <div class="row">
            <div>
                <label for="project">პროექტი</label>
                <select name="project" id="project" required>
                    <option value="">— აირჩიე —</option>
                    <?php foreach (array_keys($projectTypeMap) as $projectName): ?>
                        <option value="<?= htmlspecialchars($projectName) ?>" <?= $filterProject === $projectName ? 'selected' : '' ?>>
                            <?= htmlspecialchars($projectName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="type">ფართის ტიპი</label>
                <select name="type" id="type" required>
                    <option value="">— ჯერ აირჩიე პროექტი —</option>
                </select>
            </div>
            <label class="check">
                <input type="checkbox" name="apply" value="1" <?= $apply ? 'checked' : '' ?>>
                რეალურად მიბმა
            </label>
            <button type="submit" id="run-btn">ნახვა/გაშვება</button>
        </div>
        <p class="warn" id="apply-warn" style="<?= $apply ? '' : 'display:none' ?>">
            Apply ჩართულია: პროდუქტი მიებმევა დილებს, რომლებსაც ჯერ პროდუქტი არ აქვთ.
        </p>
    </form>

    <?php if ($run): ?>
        <div class="card">
            <?php if ($filterProject === '' || $filterType === ''): ?>
                <p>აირჩიე პროექტი და ფართის ტიპი.</p>
            <?php else: ?>
                <div class="counts">
                    <span class="chip">რეჟიმი: <?= $apply ? 'APPLY' : 'DRY RUN' ?></span>
                    <span class="chip"><?= htmlspecialchars($filterProject) ?> / <?= htmlspecialchars($filterType) ?></span>
                    <span class="chip">დილები: <?= (int)$counts['deals'] ?></span>
                    <span class="chip">უკვე აქვს: <?= (int)$counts['already_has_product'] ?></span>
                    <span class="chip">აკლია ველი: <?= (int)$counts['missing_fields'] ?></span>
                    <span class="chip">ვერ მოიძებნა: <?= (int)$counts['not_found'] ?></span>
                    <span class="chip">რამდენიმე მატჩი: <?= (int)$counts['ambiguous'] ?></span>
                    <span class="chip">მატჩი: <?= (int)$counts['matched'] ?></span>
                    <span class="chip">მიება: <?= (int)$counts['attached'] ?></span>
                    <span class="chip">შეცდომა: <?= (int)$counts['failed'] ?></span>
                </div>
                <table>
                    <thead>
                    <tr>
                        <th>დილი</th>
                        <th>სტატუსი</th>
                        <th>სექტორი / ბლოკი / სართული / ნომერი</th>
                        <th>პროდუქტი</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $row):
                        $c = $row['criteria'] ?? [];
                        $p = $row['product'] ?? null;
                        $st = $row['status'] ?? '';
                    ?>
                        <tr>
                            <td>
                                <a href="/crm/deal/details/<?= (int)$row['deal_id'] ?>/" target="_blank">
                                    #<?= (int)$row['deal_id'] ?>
                                </a>
                                <div><?= htmlspecialchars((string)($row['title'] ?? '')) ?></div>
                            </td>
                            <td class="st-<?= htmlspecialchars($st) ?>">
                                <?= htmlspecialchars($statusLabels[$st] ?? $st) ?>
                            </td>
                            <td>
                                <?= htmlspecialchars((string)($c['sector'] ?? '')) ?>
                                /
                                <?= htmlspecialchars((string)($c['block'] ?? '')) ?>
                                /
                                <?= htmlspecialchars((string)($c['floor'] ?? '')) ?>
                                /
                                <?= htmlspecialchars((string)($c['number'] ?? '')) ?>
                            </td>
                            <td>
                                <?php if ($p): ?>
                                    #<?= (int)$p['ID'] ?> <?= htmlspecialchars((string)$p['NAME']) ?>
                                <?php elseif (!empty($row['matches'])): ?>
                                    <?php foreach ($row['matches'] as $m): ?>
                                        <div>#<?= (int)$m['ID'] ?> <?= htmlspecialchars((string)$m['NAME']) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<script>
    const map = <?= json_encode($projectTypeMap, JSON_UNESCAPED_UNICODE) ?>;
    const projectEl = document.getElementById('project');
    const typeEl = document.getElementById('type');
    const selectedType = <?= json_encode($filterType, JSON_UNESCAPED_UNICODE) ?>;
    const applyBox = document.querySelector('input[name="apply"]');
    const runBtn = document.getElementById('run-btn');
    const applyWarn = document.getElementById('apply-warn');

    function fillTypes() {
        const project = projectEl.value;
        const types = map[project] || [];
        typeEl.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = types.length ? '— აირჩიე —' : '— ჯერ აირჩიე პროექტი —';
        typeEl.appendChild(placeholder);
        types.forEach(function (t) {
            const opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            if (t === selectedType) opt.selected = true;
            typeEl.appendChild(opt);
        });
    }

    function syncApplyUi() {
        const on = applyBox.checked;
        runBtn.textContent = on ? 'გაშვება' : 'ნახვა';
        runBtn.classList.toggle('apply', on);
        applyWarn.style.display = on ? '' : 'none';
    }

    projectEl.addEventListener('change', fillTypes);
    applyBox.addEventListener('change', syncApplyUi);
    fillTypes();
    syncApplyUi();
</script>
</body>
</html>
