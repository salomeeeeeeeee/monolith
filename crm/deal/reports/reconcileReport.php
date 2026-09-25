<?php
/**
 * შედარების რეპორტი — CRM დილები vs კატალოგის პროდუქტები.
 *
 * ორი წყარო, რომლებზეც დანარჩენი რეპორტები დგას:
 *   - დილის მხარე: სტადია "გაყიდულია" (semantic S) + დილის პროექტი/თანხა
 *   - პროდუქტის მხარე: iblock 14, სტატუსი "გაყიდული" + პროდუქტის პროექტი/ფასი
 * აქ ჩანს ყველა ჩანაწერი, სადაც ეს ორი ერთმანეთს არ ემთხვევა — დილისა და
 * პროდუქტის ID-ებით, რომ პირდაპირ გასწორდეს.
 */
ob_start();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once __DIR__ . '/helpers.php';

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');
CModule::IncludeModule('catalog');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

$APPLICATION->SetTitle('Reconcile Report');

$lang = $_GET['lang'] ?? 'ge';
$eng = $lang === 'eng';

$t = [
    'filter_project' => $eng ? 'Project:' : 'პროექტი:',
    'filter_issue' => $eng ? 'Issue:' : 'პრობლემა:',
    'all_projects' => $eng ? 'All projects' : 'ყველა პროექტი',
    'all_issues' => $eng ? 'All issues' : 'ყველა პრობლემა',
    'apply' => $eng ? 'Apply filters' : 'ფილტრის გამოყენება',
    'clear' => $eng ? 'Clear' : 'გასუფთავება',
    'export' => $eng ? '📥 Export to Excel' : '📥 Excel-ში ექსპორტი',
    'h2_summary' => $eng ? 'Deals vs products by project' : 'დილები vs პროდუქტები პროექტების მიხედვით',
    'h2_rows' => $eng ? 'Mismatched records' : 'აცდენილი ჩანაწერები',
    'col_project' => $eng ? 'Project' : 'პროექტი',
    'col_crm_num' => $eng ? 'CRM won deals' : 'CRM გაყიდული დილები',
    'col_crm_sum' => $eng ? 'CRM amount ($)' : 'CRM თანხა ($)',
    'col_rep_num' => $eng ? 'Report sold units' : 'რეპორტის გაყიდული',
    'col_rep_sum' => $eng ? 'Report amount ($)' : 'რეპორტის თანხა ($)',
    'col_diff_num' => $eng ? 'Diff (count)' : 'სხვაობა (რაოდ.)',
    'col_diff_sum' => $eng ? 'Diff ($)' : 'სხვაობა ($)',
    'col_issue' => $eng ? 'Issue' : 'პრობლემა',
    'col_deal' => $eng ? 'Deal' : 'დილი',
    'col_stage' => $eng ? 'Stage' : 'სტადია',
    'col_deal_project' => $eng ? 'Deal project' : 'დილის პროექტი',
    'col_amount' => $eng ? 'Deal amount ($)' : 'დილის თანხა ($)',
    'col_product' => $eng ? 'Product' : 'პროდუქტი',
    'col_unit' => $eng ? 'Unit' : 'ერთეული',
    'col_prod_project' => $eng ? 'Product project' : 'პროდუქტის პროექტი',
    'col_status' => $eng ? 'Product status' : 'პროდუქტის სტატუსი',
    'col_note' => $eng ? 'Details' : 'დეტალები',
    'col_total' => 'TOTAL',
    'no_rows' => $eng ? 'No mismatches found.' : 'აცდენა არ მოიძებნა.',
];

/** პრობლემის კოდი => [წარწერა, სიმძიმე] */
$issueMeta = [
    'deal_no_rows' => [$eng ? 'Won deal without product rows' : 'გაყიდულ დილს პროდუქტი არ აქვს', 'high'],
    'deal_row_unlinked' => [$eng ? 'Product row not linked to catalog' : 'პროდუქტის მწკრივი კატალოგზე მიბმული არაა', 'high'],
    'deal_backlink_missing' => [$eng ? 'Product does not point back to the deal' : 'პროდუქტი დილზე უკან არ მიუთითებს', 'mid'],
    'deal_product_not_sold' => [$eng ? 'Won deal, product not marked sold' : 'დილი გაყიდულია, პროდუქტი — არა', 'high'],
    'product_deal_not_won' => [$eng ? 'Sold product, deal not won' : 'პროდუქტი გაყიდულია, დილი — არა', 'high'],
    'product_no_deal' => [$eng ? 'Sold product without a deal' : 'გაყიდულ პროდუქტს დილი არ აქვს', 'high'],
    'deal_multi_products' => [$eng ? 'One deal, several sold products' : 'ერთ დილზე რამდენიმე გაყიდული პროდუქტი', 'mid'],
    'project_mismatch' => [$eng ? 'Deal project != product project' : 'დილისა და პროდუქტის პროექტი სხვადასხვაა', 'mid'],
    'deal_no_project' => [$eng ? 'Won deal without a project' : 'გაყიდულ დილს პროექტი არ აქვს', 'low'],
    'status_unknown' => [$eng ? 'Product status is empty/unknown' : 'პროდუქტის სტატუსი ცარიელი/უცნობია', 'low'],
];

$filters = [
    'project' => reportGetFilterValues('project'),
    'issue' => reportGetFilterValues('issue'),
];

/* ------------------------------------------------------------------ helpers */

function rcNorm($value)
{
    return mb_strtolower(trim((string)$value));
}

function rcOwnerDealId(array $product)
{
    $raw = $product['ownerDeal'] ?? '';
    if ($raw === '' || $raw === null) {
        $raw = $product['OWNER_DEAL'] ?? '';
    }
    return reportExtractDealId($raw);
}

/** 'S' — გაყიდული, 'F' — წარუმატებელი, 'P' — მიმდინარე */
function rcStageSemantic($stageId, $categoryId)
{
    try {
        $semantic = CCrmDeal::GetSemanticID((string)$stageId, (int)$categoryId);
        if (in_array($semantic, ['S', 'F', 'P'], true)) {
            return $semantic;
        }
    } catch (Throwable $e) {
        // fallback ქვემოთ
    }
    $stageId = (string)$stageId;
    $short = strtoupper(strpos($stageId, ':') !== false ? substr($stageId, strpos($stageId, ':') + 1) : $stageId);
    if ($short === 'WON') {
        return 'S';
    }
    if ($short === 'LOSE' || $short === 'APOLOGY') {
        return 'F';
    }
    return 'P';
}

function rcLoadCategories()
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
    return $categories;
}

function rcLoadStageMap(array $categories)
{
    $map = [];
    foreach (array_keys($categories) as $catId) {
        $stages = CCrmStatus::GetStatusList($catId > 0 ? 'DEAL_STAGE_' . $catId : 'DEAL_STAGE');
        if (is_array($stages) && $stages) {
            $map[(int)$catId] = $stages;
        }
    }
    return $map;
}

function rcDealSelect()
{
    return ['ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID', 'OPPORTUNITY', 'CONTACT_FULL_NAME', D_PROJECT, D_BLOCK];
}

/** ყველა დილი, რომლის სტადიაც "გაყიდულია" (ნებისმიერი ვორონკა). */
function rcLoadWonDeals()
{
    $deals = [];
    $res = CCrmDeal::GetListEx(
        ['ID' => 'ASC'],
        ['STAGE_SEMANTIC_ID' => 'S', 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        rcDealSelect()
    );
    while ($row = $res->Fetch()) {
        if (rcStageSemantic($row['STAGE_ID'] ?? '', $row['CATEGORY_ID'] ?? 0) !== 'S') {
            continue;
        }
        $deals[(string)$row['ID']] = $row;
    }

    // ძველ ბილდებზე STAGE_SEMANTIC_ID ფილტრმა შეიძლება არ იმუშაოს
    if (empty($deals)) {
        $res = CCrmDeal::GetListEx(
            ['ID' => 'ASC'],
            ['STAGE_ID' => REPORT_WON_STAGE, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            rcDealSelect()
        );
        while ($row = $res->Fetch()) {
            $deals[(string)$row['ID']] = $row;
        }
    }

    return $deals;
}

function rcLoadDealsByIds(array $dealIds)
{
    $deals = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $dealIds))));
    if (!$ids) {
        return $deals;
    }
    foreach (array_chunk($ids, 500) as $chunk) {
        $res = CCrmDeal::GetListEx(
            ['ID' => 'ASC'],
            ['ID' => $chunk, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            rcDealSelect()
        );
        while ($row = $res->Fetch()) {
            $deals[(string)$row['ID']] = $row;
        }
    }
    return $deals;
}

/** [dealId => [პროდუქტის მწკრივი, ...]] — დილის "პროდუქტების" ტაბი */
function rcLoadProductRows(array $dealIds)
{
    $byDeal = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $dealIds))));
    if (!$ids) {
        return $byDeal;
    }

    if (class_exists('\Bitrix\Crm\ProductRowTable')) {
        foreach (array_chunk($ids, 500) as $chunk) {
            $res = \Bitrix\Crm\ProductRowTable::getList([
                'select' => ['ID', 'OWNER_ID', 'PRODUCT_ID', 'PRODUCT_NAME', 'PRICE', 'QUANTITY'],
                'filter' => ['=OWNER_TYPE' => 'D', '@OWNER_ID' => $chunk],
                'order' => ['ID' => 'ASC'],
            ]);
            while ($row = $res->fetch()) {
                $byDeal[(string)$row['OWNER_ID']][] = $row;
            }
        }
        return $byDeal;
    }

    foreach (array_chunk($ids, 200) as $chunk) {
        $res = CCrmProductRow::GetList(
            ['ID' => 'ASC'],
            ['OWNER_TYPE' => 'D', 'OWNER_ID' => $chunk],
            false,
            false,
            ['ID', 'OWNER_ID', 'PRODUCT_ID', 'PRODUCT_NAME', 'PRICE', 'QUANTITY']
        );
        while ($row = $res->Fetch()) {
            $byDeal[(string)$row['OWNER_ID']][] = $row;
        }
    }
    return $byDeal;
}

function rcUnitLabel(array $product)
{
    $parts = [];
    if (!empty($product[F_BLOCK])) {
        $parts[] = 'ბლ. ' . $product[F_BLOCK];
    }
    if (!empty($product[F_SECTOR])) {
        $parts[] = 'სექ. ' . $product[F_SECTOR];
    }
    if (($product[F_UNIT_NO] ?? '') !== '') {
        $parts[] = '№' . $product[F_UNIT_NO];
    }
    if (!empty($product[F_TYPE])) {
        $parts[] = $product[F_TYPE];
    }
    return implode(' · ', $parts);
}

/* --------------------------------------------------------------------- data */

$categories = rcLoadCategories();
$stageMap = rcLoadStageMap($categories);

$products = reportGetProducts();
$wonDeals = rcLoadWonDeals();

// გაყიდული პროდუქტები + დილები, რომლებზეც ისინი მიუთითებენ
$soldProducts = [];
$soldByDeal = [];
$referencedDealIds = [];
foreach ($products as $id => $product) {
    if (($product[F_STATUS] ?? '') !== 'გაყიდული') {
        continue;
    }
    $soldProducts[$id] = $product;
    $dealId = rcOwnerDealId($product);
    if ($dealId !== '') {
        $soldByDeal[$dealId][] = $product;
        if (!isset($wonDeals[$dealId])) {
            $referencedDealIds[] = $dealId;
        }
    }
}
$otherDeals = rcLoadDealsByIds($referencedDealIds);
$allDeals = $wonDeals + $otherDeals;

$rowsByDeal = rcLoadProductRows(array_keys($wonDeals));

// პროდუქტი ID-ით, რომ დილის მწკრივიდან კატალოგში მოვძებნოთ
$productById = [];
foreach ($products as $id => $product) {
    $productById[(string)(int)$id] = $product;
}

/* ----------------------------------------------------------- classification */

$issues = [];

function rcAddIssue(&$issues, $code, $deal, $product, $note, $extra = [])
{
    global $stageMap, $categories;

    $categoryId = $deal ? (int)($deal['CATEGORY_ID'] ?? 0) : 0;
    $stageId = $deal ? (string)($deal['STAGE_ID'] ?? '') : '';

    $issues[] = array_merge([
        'issue' => $code,
        'deal_id' => $deal ? (string)$deal['ID'] : '',
        'deal_title' => $deal ? (string)($deal['TITLE'] ?? '') : '',
        'deal_contact' => $deal ? (string)($deal['CONTACT_FULL_NAME'] ?? '') : '',
        'deal_stage' => $deal ? (string)($stageMap[$categoryId][$stageId] ?? $stageId) : '',
        'deal_category' => $deal ? (string)($categories[$categoryId] ?? ('#' . $categoryId)) : '',
        'deal_project' => $deal ? (string)($deal[D_PROJECT] ?? '') : '',
        'deal_amount' => $deal ? reportParseAmount($deal['OPPORTUNITY'] ?? 0) : 0,
        'product_id' => $product ? (string)$product['ID'] : '',
        'product_name' => $product ? (string)($product['NAME'] ?? '') : '',
        'product_unit' => $product ? rcUnitLabel($product) : '',
        'product_project' => $product ? (string)($product[F_PROJECT] ?? '') : '',
        'product_status' => $product ? (string)($product[F_STATUS] ?? '') : '',
        'product_price' => $product ? (float)($product['PRICE'] ?? 0) : 0,
        'note' => $note,
    ], $extra);
}

// --- დილის მხრიდან
foreach ($wonDeals as $dealId => $deal) {
    $dealProject = (string)($deal[D_PROJECT] ?? '');
    if (trim($dealProject) === '') {
        rcAddIssue($issues, 'deal_no_project', $deal, null, $eng
            ? 'The deal is won but has no project, so every project-filtered report skips it.'
            : 'დილი გაყიდულია, მაგრამ პროექტი არ აქვს — პროექტით გაფილტრულ რეპორტში არ ხვდება.');
    }

    $rows = $rowsByDeal[$dealId] ?? [];
    if (!$rows) {
        rcAddIssue($issues, 'deal_no_rows', $deal, null, $eng
            ? 'The deal has no rows on its Products tab.'
            : 'დილის "პროდუქტების" ტაბი ცარიელია.');
        continue;
    }

    foreach ($rows as $row) {
        $pid = (string)(int)($row['PRODUCT_ID'] ?? 0);
        $rowName = trim((string)($row['PRODUCT_NAME'] ?? ''));
        $rowPrice = reportParseAmount($row['PRICE'] ?? 0);
        $product = ($pid !== '0' && isset($productById[$pid])) ? $productById[$pid] : null;

        if ($product === null) {
            rcAddIssue($issues, 'deal_row_unlinked', $deal, null, $eng
                ? sprintf('Row "%s" ($%s) is free text or points to a deleted catalog item (PRODUCT_ID=%s).', $rowName, number_format($rowPrice, 2), $pid)
                : sprintf('მწკრივი "%s" ($%s) თავისუფალი ტექსტია ან წაშლილ კატალოგის ერთეულზე მიუთითებს (PRODUCT_ID=%s).', $rowName, number_format($rowPrice, 2), $pid),
                ['product_id' => $pid !== '0' ? $pid : '', 'product_name' => $rowName, 'product_price' => $rowPrice]);
            continue;
        }

        $backlink = rcOwnerDealId($product);
        if ($backlink !== (string)$dealId) {
            rcAddIssue($issues, 'deal_backlink_missing', $deal, $product, $eng
                ? sprintf('The catalog item points to %s instead of this deal.', $backlink !== '' ? '#' . $backlink : 'nothing')
                : sprintf('კატალოგის ერთეული %s-ზე მიუთითებს, ამ დილზე არა.', $backlink !== '' ? '#' . $backlink : 'არსად'));
        }

        $status = (string)($product[F_STATUS] ?? '');
        if ($status !== 'გაყიდული') {
            rcAddIssue($issues, 'deal_product_not_sold', $deal, $product, $eng
                ? sprintf('The deal is won but the catalog status is "%s".', $status !== '' ? $status : '—')
                : sprintf('დილი გაყიდულია, კატალოგის სტატუსი კი — "%s".', $status !== '' ? $status : '—'));
        }

        $productProject = (string)($product[F_PROJECT] ?? '');
        if ($dealProject !== '' && $productProject !== '' && rcNorm($dealProject) !== rcNorm($productProject)) {
            rcAddIssue($issues, 'project_mismatch', $deal, $product, $eng
                ? sprintf('Deal project "%s" vs product project "%s".', $dealProject, $productProject)
                : sprintf('დილის პროექტი "%s", პროდუქტისა — "%s".', $dealProject, $productProject));
        }
    }
}

// --- პროდუქტის მხრიდან
foreach ($soldProducts as $product) {
    $dealId = rcOwnerDealId($product);

    if ($dealId === '') {
        rcAddIssue($issues, 'product_no_deal', null, $product, $eng
            ? 'The catalog item is marked sold but is not linked to any deal.'
            : 'კატალოგის ერთეული გაყიდულია, მაგრამ არცერთ დილზე არაა მიბმული.');
        continue;
    }

    if (!isset($wonDeals[$dealId])) {
        $deal = $allDeals[$dealId] ?? null;
        rcAddIssue($issues, 'product_deal_not_won', $deal, $product, $deal
            ? ($eng
                ? 'The item is sold in the catalog, but the deal does not sit in a won stage.'
                : 'კატალოგში გაყიდულია, დილი კი გაყიდულის სტადიაზე არ დგას.')
            : ($eng
                ? sprintf('Deal #%s does not exist any more.', $dealId)
                : sprintf('დილი #%s აღარ არსებობს.', $dealId)),
            $deal ? [] : ['deal_id' => $dealId]);
    }
}

foreach ($soldByDeal as $dealId => $list) {
    if (count($list) < 2) {
        continue;
    }
    $deal = $allDeals[$dealId] ?? null;
    foreach ($list as $product) {
        rcAddIssue($issues, 'deal_multi_products', $deal, $product, $eng
            ? sprintf('%d sold items share this deal, so its amount is counted %d times in the sales report.', count($list), count($list))
            : sprintf('ერთ დილზე %d გაყიდული ერთეულია — გაყიდვების რეპორტში დილის თანხა %d-ჯერ ითვლება.', count($list), count($list)),
            $deal ? [] : ['deal_id' => (string)$dealId]);
    }
}

// --- კატალოგის ჰიგიენა
foreach ($products as $product) {
    $status = (string)($product[F_STATUS] ?? '');
    if (in_array($status, REPORT_STATUSES, true)) {
        continue;
    }
    $dealId = rcOwnerDealId($product);
    rcAddIssue($issues, 'status_unknown', $dealId !== '' ? ($allDeals[$dealId] ?? null) : null, $product, $eng
        ? 'The status is outside the four known values, so the item is missing from the inventory report totals.'
        : 'სტატუსი ოთხ ცნობილ მნიშვნელობას გარეთაა — ერთეული უძრავი ქონების რეპორტის ჯამებში არ ჩანს.');
}

/* -------------------------------------------------------------- per project */

$summary = [];

function rcSummaryBucket(&$summary, $project)
{
    $key = rcNorm($project);
    if ($key === '') {
        $key = '__none__';
    }
    if (!isset($summary[$key])) {
        $summary[$key] = [
            'label' => $project !== '' ? $project : '—',
            'crm_num' => 0,
            'crm_sum' => 0,
            'rep_num' => 0,
            'rep_sum' => 0,
        ];
    } elseif ($summary[$key]['label'] === '—' && $project !== '') {
        $summary[$key]['label'] = $project;
    }
    return $key;
}

foreach ($wonDeals as $deal) {
    $key = rcSummaryBucket($summary, (string)($deal[D_PROJECT] ?? ''));
    $summary[$key]['crm_num']++;
    $summary[$key]['crm_sum'] += reportParseAmount($deal['OPPORTUNITY'] ?? 0);
}

$soldWithDealPrice = reportEnrichDealBedrooms($soldProducts);
foreach ($soldWithDealPrice as $product) {
    $key = rcSummaryBucket($summary, (string)($product[F_PROJECT] ?? ''));
    $summary[$key]['rep_num']++;
    $summary[$key]['rep_sum'] += (float)($product['DEAL_PRICE'] ?? 0);
}

uasort($summary, static function ($a, $b) {
    return strcasecmp($a['label'], $b['label']);
});

/* ------------------------------------------------------------------ filters */

$projectValues = [];
foreach ($issues as $issue) {
    $projectValues[] = ['project' => $issue['deal_project']];
    $projectValues[] = ['project' => $issue['product_project']];
}
$projectOptions = reportGetUniqueValues($projectValues, 'project');

$issueCounts = [];
foreach ($issues as $issue) {
    $issueCounts[$issue['issue']] = ($issueCounts[$issue['issue']] ?? 0) + 1;
}

$filteredIssues = array_values(array_filter($issues, static function ($issue) use ($filters) {
    if ($filters['issue'] && !in_array($issue['issue'], $filters['issue'], true)) {
        return false;
    }
    if ($filters['project']
        && !reportValueMatches($issue['deal_project'], $filters['project'])
        && !reportValueMatches($issue['product_project'], $filters['project'])) {
        return false;
    }
    return true;
}));

$severityOrder = ['high' => 0, 'mid' => 1, 'low' => 2];
usort($filteredIssues, static function ($a, $b) use ($issueMeta, $severityOrder) {
    $sa = $severityOrder[$issueMeta[$a['issue']][1] ?? 'low'] ?? 3;
    $sb = $severityOrder[$issueMeta[$b['issue']][1] ?? 'low'] ?? 3;
    if ($sa !== $sb) {
        return $sa - $sb;
    }
    if ($a['issue'] !== $b['issue']) {
        return strcmp($a['issue'], $b['issue']);
    }
    return (int)$a['deal_id'] <=> (int)$b['deal_id'];
});

ob_end_clean();
reportPageBegin(
    $eng ? 'Reconcile Report' : 'შედარების რეპორტი',
    $eng
        ? 'Every record where the CRM deal side and the catalog product side disagree, with deal and product IDs.'
        : 'ყველა ჩანაწერი, სადაც CRM-ის დილი და კატალოგის პროდუქტი ერთმანეთს არ ემთხვევა — დილისა და პროდუქტის ID-ებით.',
    $lang
);
?>

<section class="report-filter">
    <form method="GET" action="" class="report-filter__form">
        <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
        <div class="report-filter__grid">
            <div class="report-field">
                <label for="project"><?= $t['filter_project'] ?></label>
                <?php reportRenderMultiSelect('project', 'project', $t['all_projects'], $projectOptions, $filters['project']); ?>
            </div>
            <div class="report-field">
                <label for="issue"><?= $t['filter_issue'] ?></label>
                <?php
                $issueOptions = [];
                foreach ($issueMeta as $code => $meta) {
                    if (!empty($issueCounts[$code])) {
                        $issueOptions[$code] = $meta[0] . ' (' . (int)$issueCounts[$code] . ')';
                    }
                }
                reportRenderMultiSelect('issue', 'issue', $t['all_issues'] . ' (' . count($issues) . ')', $issueOptions, $filters['issue'], true);
                ?>
            </div>
        </div>
        <div class="report-filter__actions">
            <button type="submit" class="btn btn-primary"><?= $t['apply'] ?></button>
            <button type="button" class="btn btn-ghost" onclick="window.location.href='<?= $_SERVER['PHP_SELF'] ?>?lang=<?= htmlspecialchars($lang) ?>'"><?= $t['clear'] ?></button>
            <button type="button" class="btn btn-export" onclick="exportToExcel()">
                <span class="btn-icon">↓</span><?= $t['export'] ?>
            </button>
        </div>
    </form>
</section>

<style>
    .report-table--reconcile { min-width: 1280px; }
    .rc-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; line-height: 1.4; }
    .rc-badge--high { background: #fdecea; color: #c0392b; }
    .rc-badge--mid { background: #fdf3e2; color: #9a6400; }
    .rc-badge--low { background: #eef1f4; color: #6b7a8a; }
    .rc-link { color: #00335b; font-weight: 600; text-decoration: none; border-bottom: 1px solid rgba(0, 51, 91, 0.25); }
    .rc-link:hover { border-bottom-color: #00335b; }
    .rc-muted { color: #6b7a8a; font-size: 11px; display: block; margin-top: 2px; }
    .rc-note { color: #44546a; font-size: 12px; display: inline-block; max-width: 360px; }
    .rc-diff--bad { color: #c0392b; font-weight: 600; }
    .rc-diff--ok { color: #1b7f5a; font-weight: 600; }
    .rc-empty { padding: 18px; color: #6b7a8a; }
</style>

<?php reportBlockOpen($t['h2_summary']); ?>
    <thead>
        <tr>
            <th><?= $t['col_project'] ?></th>
            <th><?= $t['col_crm_num'] ?></th>
            <th><?= $t['col_crm_sum'] ?></th>
            <th><?= $t['col_rep_num'] ?></th>
            <th><?= $t['col_rep_sum'] ?></th>
            <th><?= $t['col_diff_num'] ?></th>
            <th><?= $t['col_diff_sum'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php
        $totCrmNum = $totCrmSum = $totRepNum = $totRepSum = 0;
        foreach ($summary as $bucket):
            $diffNum = $bucket['rep_num'] - $bucket['crm_num'];
            $diffSum = $bucket['rep_sum'] - $bucket['crm_sum'];
            $totCrmNum += $bucket['crm_num'];
            $totCrmSum += $bucket['crm_sum'];
            $totRepNum += $bucket['rep_num'];
            $totRepSum += $bucket['rep_sum'];
        ?>
        <tr>
            <td><span class="row-main"><?= htmlspecialchars($bucket['label']) ?></span></td>
            <td><?= (int)$bucket['crm_num'] ?></td>
            <td>$<?= number_format($bucket['crm_sum'], 2) ?></td>
            <td><?= (int)$bucket['rep_num'] ?></td>
            <td>$<?= number_format($bucket['rep_sum'], 2) ?></td>
            <td class="<?= $diffNum === 0 ? 'rc-diff--ok' : 'rc-diff--bad' ?>"><?= $diffNum > 0 ? '+' : '' ?><?= $diffNum ?></td>
            <td class="<?= abs($diffSum) < 0.005 ? 'rc-diff--ok' : 'rc-diff--bad' ?>"><?= $diffSum > 0 ? '+' : '' ?>$<?= number_format($diffSum, 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td><?= $t['col_total'] ?></td>
            <td><?= $totCrmNum ?></td>
            <td>$<?= number_format($totCrmSum, 2) ?></td>
            <td><?= $totRepNum ?></td>
            <td>$<?= number_format($totRepSum, 2) ?></td>
            <td><?= ($totRepNum - $totCrmNum) > 0 ? '+' : '' ?><?= $totRepNum - $totCrmNum ?></td>
            <td><?= ($totRepSum - $totCrmSum) > 0 ? '+' : '' ?>$<?= number_format($totRepSum - $totCrmSum, 2) ?></td>
        </tr>
    </tbody>
<?php reportBlockClose(); ?>

<?php reportBlockOpen($t['h2_rows'] . ' — ' . count($filteredIssues), 'report-table--reconcile'); ?>
    <thead>
        <tr>
            <th><?= $t['col_issue'] ?></th>
            <th><?= $t['col_deal'] ?></th>
            <th><?= $t['col_stage'] ?></th>
            <th><?= $t['col_deal_project'] ?></th>
            <th><?= $t['col_amount'] ?></th>
            <th><?= $t['col_product'] ?></th>
            <th><?= $t['col_unit'] ?></th>
            <th><?= $t['col_prod_project'] ?></th>
            <th><?= $t['col_status'] ?></th>
            <th><?= $t['col_note'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$filteredIssues): ?>
        <tr><td colspan="10" class="rc-empty"><?= $t['no_rows'] ?></td></tr>
        <?php endif; ?>
        <?php foreach ($filteredIssues as $issue):
            $meta = $issueMeta[$issue['issue']] ?? [$issue['issue'], 'low'];
            $who = $issue['deal_contact'] !== '' ? $issue['deal_contact'] : $issue['deal_title'];
        ?>
        <tr>
            <td><span class="rc-badge rc-badge--<?= htmlspecialchars($meta[1]) ?>"><?= htmlspecialchars($meta[0]) ?></span></td>
            <td>
                <?php if ($issue['deal_id'] !== ''): ?>
                    <a class="rc-link" href="/crm/deal/details/<?= (int)$issue['deal_id'] ?>/" target="_blank" rel="noopener">#<?= (int)$issue['deal_id'] ?></a>
                    <?php if ($who !== ''): ?><span class="rc-muted"><?= htmlspecialchars($who) ?></span><?php endif; ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
            <td>
                <?= $issue['deal_stage'] !== '' ? htmlspecialchars($issue['deal_stage']) : '—' ?>
                <?php if ($issue['deal_category'] !== ''): ?><span class="rc-muted"><?= htmlspecialchars($issue['deal_category']) ?></span><?php endif; ?>
            </td>
            <td><?= $issue['deal_project'] !== '' ? htmlspecialchars($issue['deal_project']) : '—' ?></td>
            <td><?= $issue['deal_id'] !== '' ? '$' . number_format($issue['deal_amount'], 2) : '—' ?></td>
            <td>
                <?php if ($issue['product_id'] !== ''): ?>
                    <a class="rc-link" href="/crm/catalog/<?= REPORT_PRODUCT_IBLOCK ?>/product/<?= (int)$issue['product_id'] ?>/" target="_blank" rel="noopener">#<?= (int)$issue['product_id'] ?></a>
                <?php else: ?>
                    —
                <?php endif; ?>
                <?php if ($issue['product_name'] !== ''): ?><span class="rc-muted"><?= htmlspecialchars($issue['product_name']) ?></span><?php endif; ?>
            </td>
            <td><?= $issue['product_unit'] !== '' ? htmlspecialchars($issue['product_unit']) : '—' ?></td>
            <td><?= $issue['product_project'] !== '' ? htmlspecialchars($issue['product_project']) : '—' ?></td>
            <td><?= $issue['product_status'] !== '' ? htmlspecialchars($issue['product_status']) : '—' ?></td>
            <td><span class="rc-note"><?= htmlspecialchars($issue['note']) ?></span></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
<?php reportBlockClose(); ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const issuesData = <?= json_encode($filteredIssues, JSON_UNESCAPED_UNICODE) ?>;
const issueLabels = <?= json_encode(array_map(static function ($m) { return $m[0]; }, $issueMeta), JSON_UNESCAPED_UNICODE) ?>;
const summaryData = <?= json_encode(array_values($summary), JSON_UNESCAPED_UNICODE) ?>;
const t = <?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>;

function exportToExcel() {
    const wb = XLSX.utils.book_new();

    const summaryRows = [[t.col_project, t.col_crm_num, t.col_crm_sum, t.col_rep_num, t.col_rep_sum, t.col_diff_num, t.col_diff_sum]];
    summaryData.forEach(function (b) {
        summaryRows.push([b.label, b.crm_num, b.crm_sum, b.rep_num, b.rep_sum, b.rep_num - b.crm_num, b.rep_sum - b.crm_sum]);
    });
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(summaryRows), 'Summary');

    const header = [t.col_issue, t.col_deal, 'Deal ID', t.col_stage, t.col_deal_project, t.col_amount,
        t.col_product, 'Product ID', t.col_unit, t.col_prod_project, t.col_status, t.col_note];
    const rows = issuesData.map(function (r) {
        return [
            issueLabels[r.issue] || r.issue,
            r.deal_contact || r.deal_title || '',
            r.deal_id ? Number(r.deal_id) : '',
            r.deal_stage || '',
            r.deal_project || '',
            r.deal_id ? Number(r.deal_amount) : '',
            r.product_name || '',
            r.product_id ? Number(r.product_id) : '',
            r.product_unit || '',
            r.product_project || '',
            r.product_status || '',
            r.note || '',
        ];
    });
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([header].concat(rows)), 'Mismatches');
    XLSX.writeFile(wb, 'reconcile_report_' + new Date().toISOString().slice(0, 10) + '.xlsx');
}
</script>
<?php reportPageEnd($lang); ?>
