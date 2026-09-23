<?php
ob_start();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once __DIR__ . '/leadsHelpers.php';
CJSCore::Init(['jquery']);
CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');

$APPLICATION->SetTitle('Leads Report');

$lang = $_GET['lang'] ?? 'ge';

// ── ფილტრები ────────────────────────────────────────────────────────────

$from = leadsParseYmd($_GET['from'] ?? '') ?: date('Y-m-01');
$to = leadsParseYmd($_GET['to'] ?? '') ?: date('Y-m-d');
if ($from > $to) {
    list($from, $to) = [$to, $from];
}
$project = trim((string)($_GET['project'] ?? ''));

$compareFrom = leadsParseYmd($_GET['compare_from'] ?? '');
$compareTo = leadsParseYmd($_GET['compare_to'] ?? '');
$compareError = '';
if (($compareFrom !== '') !== ($compareTo !== '')) {
    $compareError = 'აირჩიე დასაწყისი და დასასრული';
} elseif ($compareFrom !== '' && $compareFrom > $compareTo) {
    $compareError = 'დასაწყისი უნდა იყოს დასასრულამდე';
}
$hasCompare = $compareFrom !== '' && $compareTo !== '' && $compareError === '';

// ── მონაცემები ──────────────────────────────────────────────────────────

$stages = leadsLoadStages();
$sources = leadsLoadSources();

$periodDeals = leadsLoadDeals($from, $to);
$projects = leadsProjectOptions($periodDeals);
$deals = leadsFilterByProject($periodDeals, $project);

// დინამიკა: მიმდინარე თვე + წინა 2 თვე, მთავარი თარიღის ფილტრის დამოუკიდებლად
$monthKeys = [];
for ($i = 2; $i >= 0; $i--) {
    $monthKeys[] = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' month'));
}
$dynamicsDeals = leadsFilterByProject(
    leadsLoadDeals($monthKeys[0] . '-01', date('Y-m-t', strtotime(end($monthKeys) . '-01'))),
    $project
);

$compareDeals = $hasCompare ? leadsFilterByProject(leadsLoadDeals($compareFrom, $compareTo), $project) : [];

$contactAssigned = leadsContactAssignedMap(array_merge(
    array_column($deals, 'CONTACT_ID'),
    array_column($compareDeals, 'CONTACT_ID')
));
$userIds = array_merge(
    array_column($deals, 'ASSIGNED_BY_ID'),
    array_column($deals, 'CREATED_BY_ID'),
    array_column($compareDeals, 'ASSIGNED_BY_ID'),
    array_column($compareDeals, 'CREATED_BY_ID'),
    array_values($contactAssigned)
);

$ctx = [
    'stages' => $stages,
    'sources' => $sources,
    'paths' => leadsLoadStagePaths(array_keys($deals)),
    'userNames' => reportBatchUserNames(array_values(array_unique(array_map('intval', $userIds)))),
    'contactAssigned' => $contactAssigned,
    'ccLossMap' => leadsUfEnumMap(D_LOSS_REASON_CC),
    'salesLossMap' => leadsUfEnumMap(D_LOSS_REASON_SALES),
];

$report = leadsBuildReport($deals, $ctx);
$buckets = $report['buckets'];
$rows = $report['rows'];

$compareMain = leadsSummarizeOutcome($deals, $stages, 'cmp:main:', $buckets);
$compareCustom = leadsEmptyOutcome();
if ($hasCompare) {
    $compareCustom = leadsSummarizeOutcome($compareDeals, $stages, 'cmp:custom:', $buckets);
    foreach ($compareDeals as $id => $deal) {
        if (!isset($rows[(int)$id])) {
            $rows[(int)$id] = leadsDealRow($deal, leadsClassify($deal, $stages), $ctx);
        }
    }
}

$months = leadsMonthDynamics($dynamicsDeals, $stages, $monthKeys);
$cost = leadsBuildCostRoi(leadsLoadMarketingCosts(), $report['costLeads'], $from, $to);
$dailo = leadsLoadDailoStats($from, $to);
$dailoTotals = leadsDailoTotals($dailo, $from, $to);

$total = $report['total'];

// ── რენდერის დამხმარეები ────────────────────────────────────────────────

function lrH($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** რიცხვი — კლიკზე ლიდების სიას ხსნის, თუ სიაში რამე არის. */
function lrLink($value, $bucketKey, $title, array $buckets, $class = '')
{
    $text = is_string($value) ? $value : leadsFmtInt($value);
    if (empty($buckets[$bucketKey])) {
        return '<span class="lr-num ' . $class . '">' . $text . '</span>';
    }
    return '<button type="button" class="lr-link ' . $class . '" data-bucket="' . lrH($bucketKey) . '" data-title="' . lrH($title) . '">' . $text . '</button>';
}

function lrHelp($text)
{
    return $text !== '' ? '<span class="lr-help" title="' . lrH($text) . '">?</span>' : '';
}

/**
 * KPI ბარათი. $opt: bucket, title, pct, hint, tone (won|lost|work|accent), help
 */
function lrKpi($eyebrow, $value, array $opt = [])
{
    $tone = !empty($opt['tone']) ? ' tone-' . $opt['tone'] : '';
    $attrs = '';
    $class = 'lr-kpi';
    if (!empty($opt['bucket'])) {
        $class .= ' is-link';
        $attrs = ' role="button" tabindex="0" data-bucket="' . lrH($opt['bucket']) . '" data-title="' . lrH($opt['title'] ?? $eyebrow) . '"';
    }
    $html = '<div class="' . $class . '"' . $attrs . '>';
    $html .= '<div class="lr-kpi__head"><p class="lr-kpi__eyebrow' . $tone . '">' . lrH($eyebrow) . '</p>' . lrHelp($opt['help'] ?? '') . '</div>';
    $html .= '<div class="lr-kpi__value' . $tone . '">' . (is_string($value) ? $value : leadsFmtInt($value)) . '</div>';
    if (isset($opt['pct'])) {
        $html .= '<p class="lr-kpi__pct' . $tone . '">' . lrH($opt['pct']) . '</p>';
    }
    if (!empty($opt['hint'])) {
        $html .= '<p class="lr-kpi__hint">' . lrH($opt['hint']) . '</p>';
    }
    return $html . '</div>';
}

function lrSectionOpen($title, $badge = '', $actions = '', $hint = '')
{
    echo '<section class="report-block lr-section"><div class="lr-section__head"><div class="lr-section__title">'
        . '<h2 class="report-block__title">' . lrH($title) . '</h2>' . $badge . '</div>'
        . ($actions !== '' ? '<div class="lr-section__actions">' . $actions . '</div>' : '') . '</div>'
        . ($hint !== '' ? '<p class="lr-section__hint">' . lrH($hint) . '</p>' : '')
        . '<div class="lr-body">';
}

function lrSectionClose()
{
    echo '</div></section>';
}

function lrBadge($label, $value)
{
    return '<span class="lr-badge"><span class="lr-badge__label">' . lrH($label) . '</span><span class="lr-badge__value">' . $value . '</span></span>';
}

function lrShareTone($pct)
{
    if ($pct <= 0) {
        return 'empty';
    }
    return $pct > 50 ? 'green' : ($pct >= 30 ? 'yellow' : 'red');
}

function lrConvTone($pct)
{
    if ($pct <= 0) {
        return 'empty';
    }
    return $pct > 20 ? 'blue' : ($pct >= 10 ? 'green' : 'yellow');
}

/** ზოლიანი რიგი: სახელი | სვეტები | ზოლი. */
function lrBar($name, array $cols, $pct, $tone, $extra = false, $muted = false)
{
    $width = $pct > 0 ? max(min($pct, 100), 2) : 0;
    $class = 'lr-bar lr-bar--c' . count($cols) . ($extra ? ' lr-extra' : '') . ($muted ? ' is-muted' : '');
    $html = '<div class="' . $class . '"><span class="lr-bar__name" title="' . lrH($name) . '">' . lrH($name) . '</span>';
    foreach ($cols as $col) {
        $html .= '<span class="lr-bar__col">' . $col . '</span>';
    }
    return $html . '<span class="lr-track"><span class="lr-fill lr-fill--' . $tone . '" style="width:' . round($width, 2) . '%"></span></span></div>';
}

function lrBarHead($name, array $cols)
{
    $html = '<div class="lr-bar lr-bar--head lr-bar--c' . count($cols) . '"><span>' . lrH($name) . '</span>';
    foreach ($cols as $col) {
        $html .= '<span class="lr-bar__col">' . lrH($col) . '</span>';
    }
    return $html . '<span></span></div>';
}

function lrMoreButton($listId, $count, $limit = 10)
{
    return $count > $limit ? '<button type="button" class="lr-more" data-more="' . lrH($listId) . '">More</button>' : '';
}

function lrCostCells(array $row)
{
    $blank = !$row['hasCost'];
    return '<td>' . ($blank ? '—' : leadsFmtUsd($row['cost'])) . '</td>'
        . '<td>' . leadsFmtInt($row['leads']) . '</td>'
        . '<td>' . ($blank || $row['cpl'] === null ? '—' : leadsFmtUsd($row['cpl'])) . '</td>'
        . '<td class="tone-won">' . leadsFmtInt($row['won']) . '</td>'
        . '<td>' . ($row['cr'] === null ? '—' : leadsFmtPct($row['cr'])) . '</td>'
        . '<td>' . ($blank || $row['costPerApt'] === null ? '—' : leadsFmtUsd($row['costPerApt'])) . '</td>'
        . '<td>' . ($row['won'] > 0 || $row['revenue'] > 0 ? leadsFmtUsd($row['revenue']) : '—') . '</td>'
        . '<td>' . ($blank || $row['roi'] === null ? '—' : number_format($row['roi'], 2)) . '</td>';
}

$costHead = '<tr><th>წყარო</th><th>Campaign for project</th><th title="ხარჯი ლისტი 27 · spend">Total Cost</th><th>Leads</th>'
    . '<th title="CPL = Total Cost ÷ Leads">CPL</th><th class="tone-won">Won</th><th title="CR % = Won ÷ Leads × 100">CR %</th>'
    . '<th title="Cost per Apt. = Total Cost ÷ Won">Cost per Apt.</th><th title="Revenue = Σ OPPORTUNITY (WON)">Revenue</th>'
    . '<th title="ROI = Revenue ÷ Total Cost">ROI</th></tr>';

// Excel-ის ექსპორტები
$exports = [
    'sources' => [['წყარო', 'ლიდები', '%']],
    'sourcesWon' => [['წყარო', 'ლიდები', 'წარმატებული', 'კონვ.%']],
    'dailo' => [['თარიღი', 'საუბრები', 'ლიდები', 'კონვ.%', 'კომენტარები', 'პასუხგაცემული', 'დამალული']],
];

$projectSuffix = $project !== '' ? ' · ' . $project : '';

ob_end_clean();
reportPageBegin(
    'ლიდების რეპორტი',
    'ლიდები Create date-ით: წყაროები, შედეგები, მენეჯერები, მარკეტინგი, ზარები და სოციალური არხების (Dailo) სტატისტიკა.',
    $lang
);
?>

<section class="report-filter">
    <form method="GET" action="" class="report-filter__form">
        <input type="hidden" name="lang" value="<?= lrH($lang) ?>">
        <div class="report-filter__grid">
            <div class="report-field">
                <label for="from">Create date — დან</label>
                <input type="date" name="from" id="from" value="<?= lrH($from) ?>">
            </div>
            <div class="report-field">
                <label for="to">Create date — მდე</label>
                <input type="date" name="to" id="to" value="<?= lrH($to) ?>">
            </div>
            <div class="report-field">
                <label for="project">პროექტი</label>
                <select name="project" id="project">
                    <option value="">ყველა</option>
                    <?php foreach ($projects as $option): ?>
                        <option value="<?= lrH($option) ?>" <?= $option === $project ? 'selected' : '' ?>><?= lrH($option) ?></option>
                    <?php endforeach; ?>
                    <?php if ($project !== '' && !in_array($project, $projects, true)): ?>
                        <option value="<?= lrH($project) ?>" selected><?= lrH($project) ?></option>
                    <?php endif; ?>
                </select>
            </div>
        </div>
        <div class="report-filter__actions">
            <button type="submit" class="btn btn-primary">განახლება</button>
            <button type="button" class="btn btn-ghost" onclick="window.location.href='?lang=<?= lrH(rawurlencode($lang)) ?>'">გასუფთავება</button>
            <span class="lr-status">
                <?= lrH($from . ' — ' . $to . $projectSuffix) ?>:
                <?= leadsFmtInt($total) ?> ლიდი, <?= leadsFmtInt($report['won']) ?> წარმატებული,
                კონვ.: <?= leadsFmtPct(leadsShare($report['won'], $total)) ?>
            </span>
        </div>
    </form>
</section>

<?php // ── ლიდების მოცულობა + წყაროები ───────────────────────────────────── ?>
<?php lrSectionOpen('ლიდების მოცულობა'); ?>
    <div class="lr-kpis">
        <?= lrKpi('ჯამური ლიდების რაოდენობა', $total, ['bucket' => 'kpi:total', 'title' => 'ჯამური ლიდების რაოდენობა', 'hint' => 'ყველა ლიდი · ყველა წყარო', 'help' => 'ჯამური ლიდები = ყველა ლიდი ფილტრის პერიოდში (Create date).']) ?>
        <?= lrKpi('აგენტის ლიდები', $report['agent'], ['bucket' => 'kpi:agent', 'title' => 'აგენტის ლიდები — საბროკერო კომპანია / კერძო ბროკერი', 'hint' => 'სააგენტო / აგენტი', 'help' => 'აგენტის ლიდები = წყარო: საბროკერო კომპანია / კერძო ბროკერი.']) ?>
        <?= lrKpi('მარკეტინგის ლიდები', $report['marketingLeads'], ['bucket' => 'kpi:marketing', 'title' => 'მარკეტინგის ლიდები', 'hint' => 'FB · IG · WhatsApp · TikTok · Widget', 'help' => 'მარკეტინგის ლიდები = Facebook, Instagram, WhatsApp, TikTok, Widget და სხვა მარკეტინგის SOURCE_ID.']) ?>
        <?= lrKpi('სხვა', $report['other'], ['bucket' => 'kpi:other', 'title' => 'სხვა — დანარჩენი ლიდები (ჯამური − აგენტი − მარკეტინგი)', 'hint' => 'დანარჩენი წყაროები', 'help' => 'სხვა = ჯამური − აგენტი − მარკეტინგი.']) ?>
    </div>
<?php lrSectionClose(); ?>

<?php
lrSectionOpen(
    'ლიდების წყაროები',
    lrBadge('ჯამური', leadsFmtInt($total)),
    $total ? '<button type="button" class="btn btn-export lr-btn-sm" data-export="sources" data-file="leads-by-source">Excel</button>' : ''
);
if (!$total): ?>
    <div class="lr-empty">—</div>
<?php else: ?>
    <div class="lr-bars">
        <?= lrBarHead('წყარო', ['ლიდები', '%']) ?>
        <?php foreach ($report['sources'] as $name => $data):
            $share = leadsShare($data['count'], $total);
            $exports['sources'][] = [$name, $data['count'], round($share, 2)];
            echo lrBar($name, [
                lrLink($data['count'], 'source:' . $name, 'წყარო — ' . $name, $buckets),
                '<span title="' . lrH(leadsFmtInt($data['count']) . ' / ' . leadsFmtInt($total) . ' × 100') . '">' . leadsFmtPct($share) . '</span>',
            ], $share, lrShareTone($share));
        endforeach;
        $exports['sources'][] = ['სულ', $total, 100]; ?>
    </div>
<?php endif;
lrSectionClose(); ?>

<?php // ── შედეგები ─────────────────────────────────────────────────────── ?>
<?php lrSectionOpen('შედეგები'); ?>
    <div class="lr-kpis">
        <?= lrKpi('წარმატებული', $report['won'], ['bucket' => 'kpi:won', 'title' => 'წარმატებული', 'tone' => 'won', 'pct' => leadsFmtPct(leadsShare($report['won'], $total)), 'hint' => 'WON ÷ ჯამური ლიდები', 'help' => 'კონვერსია = წარმატებული ÷ ჯამური ლიდები × 100']) ?>
        <?= lrKpi('წარმატებული აგენტი', $report['wonAgent'], ['bucket' => 'kpi:wonAgent', 'title' => 'წარმატებული აგენტი', 'tone' => 'won', 'pct' => leadsFmtPct(leadsShare($report['wonAgent'], $report['agent'])), 'hint' => 'WON ÷ აგენტის ლიდები', 'help' => 'კონვერსია = წარმატებული აგენტი ÷ აგენტის ლიდები × 100']) ?>
        <?= lrKpi('წარმატებული მარკეტინგი', $report['wonMarketing'], ['bucket' => 'kpi:wonMarketing', 'title' => 'წარმატებული მარკეტინგი', 'tone' => 'won', 'pct' => leadsFmtPct(leadsShare($report['wonMarketing'], $report['marketingLeads'])), 'hint' => 'WON ÷ მარკეტინგის ლიდები', 'help' => 'კონვერსია = წარმატებული მარკეტინგი ÷ მარკეტინგის ლიდები × 100']) ?>
        <?= lrKpi('წარმატებული სხვა', $report['wonOther'], ['bucket' => 'kpi:wonOther', 'title' => 'წარმატებული სხვა', 'tone' => 'won', 'pct' => leadsFmtPct(leadsShare($report['wonOther'], $report['other'])), 'hint' => 'WON ÷ სხვა ლიდები', 'help' => 'კონვერსია = წარმატებული სხვა ÷ სხვა ლიდები × 100']) ?>
    </div>
    <div class="lr-kpis lr-kpis--gap">
        <?= lrKpi('დამუშავებაში', $report['inWork'], ['bucket' => 'kpi:inWork', 'title' => 'დამუშავებაში', 'tone' => 'work', 'pct' => leadsFmtPct(leadsShare($report['inWork'], $total)), 'hint' => 'დამუშავებაში · ჯამური ლიდების %', 'help' => '% = დამუშავებაში ÷ ჯამური ლიდები × 100.']) ?>
        <?= lrKpi('წარუმატებელი', $report['junk'], ['bucket' => 'kpi:junk', 'title' => 'წარუმატებელი', 'tone' => 'lost', 'pct' => leadsFmtPct(leadsShare($report['junk'], $total)), 'hint' => 'ჯამური ლიდების %', 'help' => '% = წარუმატებელი ÷ ჯამური ლიდები × 100.']) ?>
        <?= lrKpi('კონვერსია (წარმ./ჯამი) (ლიდები)', leadsFmtPct(leadsShare($report['won'], $total)), ['bucket' => 'kpi:won', 'title' => 'კონვერსია (წარმ./ჯამი) (ლიდები)', 'tone' => 'accent', 'hint' => 'წარმატებული ÷ ჯამური', 'help' => 'კონვერსია = წარმატებული ÷ ჯამური ლიდები × 100.']) ?>
        <?= lrKpi('კონვერსია (წარმ/qualified) (ლიდები)', leadsFmtPct(leadsShare($report['wonQualified'], $report['qualified'])), ['bucket' => 'kpi:convQualified', 'title' => 'კონვერსია (წარმ/qualified) (ლიდები)', 'tone' => 'accent', 'hint' => 'წარმატებული(qualified) ÷ qualified (' . leadsFmtInt($report['qualified']) . ')', 'help' => 'კონვერსია = (წარმატებული ∩ qualified) ÷ qualified × 100. Qualified = PREPARATION ახლა ან ისტორიაში, ან სეილის შემდგომი ეტაპი.']) ?>
    </div>
<?php lrSectionClose(); ?>

<div class="lr-two">
    <?php
    $lostTotal = array_sum($report['lostFrom']);
    lrSectionOpen('წარუმატებლის ანალიზი — ეტაპების მიხედვით', lrBadge('ჯამური', leadsFmtInt($lostTotal)), '', 'რომელი ეტაპიდან ილოსთება');
    if (!$lostTotal): ?>
        <div class="lr-empty">—</div>
    <?php else: ?>
        <div class="lr-bars">
            <?= lrBarHead('ეტაპი', ['რაოდ.', '%']) ?>
            <?php foreach ($report['lostFrom'] as $stageId => $count):
                $label = $stageId === LEADS_LOST_UNKNOWN ? LEADS_LOST_UNKNOWN_LABEL : ($stages[$stageId]['name'] ?? $stageId);
                $pct = leadsShare($count, $lostTotal);
                echo lrBar($label, [lrLink($count, 'lostFrom:' . $stageId, 'წარუმატებელი — ' . $label, $buckets), leadsFmtPct($pct)], $pct, 'lost');
            endforeach; ?>
        </div>
    <?php endif;
    lrSectionClose(); ?>

    <?php lrSectionOpen('ლიდის ეტაპები (liveboard)'); ?>
        <?php if (!$total): ?>
            <div class="lr-empty">—</div>
        <?php else: ?>
            <div class="lr-bars">
                <?= lrBarHead('ეტაპი', ['რაოდ.', '%']) ?>
                <?php foreach ([['წარმატებული', 'won', 'won'], ['დამუშავებაში', 'inWork', 'work'], ['წარუმატებელი', 'junk', 'lost']] as $status):
                    $count = $report['status'][$status[1]];
                    $pct = leadsShare($count, $total);
                    echo lrBar($status[0], [leadsFmtInt($count), leadsFmtPct($pct)], $pct, $status[2]);
                endforeach; ?>
            </div>
        <?php endif; ?>
    <?php lrSectionClose(); ?>
</div>

<?php lrSectionOpen('წარმატებული ლიდები უძრავი ქონების ტიპების მიხ.'); ?>
    <div class="lr-kpis">
        <?php foreach (LEADS_WON_PROPERTY_BUCKETS as $key => $bucket): ?>
            <?= lrKpi($bucket['label'], $report['wonProperty'][$key], ['bucket' => 'property:' . $key, 'title' => 'წარმატებული ლიდები — ' . $bucket['label'], 'hint' => 'WON · ზეტიპი', 'help' => 'WON ლიდები, ზეტიპი = ' . $bucket['label'] . '.']) ?>
        <?php endforeach; ?>
    </div>
<?php lrSectionClose(); ?>

<?php
$wonSources = array_filter($report['sources'], function ($data) {
    return $data['won'] > 0;
});
uasort($wonSources, function ($a, $b) {
    return $b['won'] - $a['won'];
});
lrSectionOpen(
    'წარმატებული ლიდები წყაროების მიხ.',
    lrBadge('ჯამური', leadsFmtInt($report['won'])),
    $wonSources ? '<button type="button" class="btn btn-export lr-btn-sm" data-export="sourcesWon" data-file="won-leads-by-source">Excel</button>' : ''
);
if (!$wonSources): ?>
    <div class="lr-empty">—</div>
<?php else: ?>
    <div class="lr-bars">
        <?= lrBarHead('წყარო', ['ლიდები', 'წარმ.', 'კონვ.']) ?>
        <?php
        $wonLeadsTotal = 0;
        foreach ($wonSources as $name => $data):
            $conv = leadsShare($data['won'], $data['count']);
            $wonLeadsTotal += $data['count'];
            $exports['sourcesWon'][] = [$name, $data['count'], $data['won'], round($conv, 2)];
            echo lrBar($name, [
                lrLink($data['count'], 'source:' . $name, 'წყარო — ' . $name, $buckets),
                lrLink($data['won'], 'sourceWon:' . $name, 'წარმატებული — ' . $name, $buckets, 'tone-won'),
                '<span title="' . lrH(leadsFmtInt($data['won']) . ' / ' . leadsFmtInt($data['count']) . ' × 100') . '">' . leadsFmtPct($conv) . '</span>',
            ], $conv, lrConvTone($conv));
        endforeach;
        $exports['sourcesWon'][] = ['სულ', $wonLeadsTotal, $report['won'], round(leadsShare($report['won'], $wonLeadsTotal), 2)]; ?>
    </div>
<?php endif;
lrSectionClose(); ?>

<?php // ── მენეჯერები ───────────────────────────────────────────────────── ?>
<?php
$managerTotal = array_sum(array_column($report['managers'], 'total'));
lrSectionOpen('ლიდები გაყიდვების მენეჯერების მიხ.', lrBadge('ჯამური ლიდები', leadsFmtInt($managerTotal)), '', 'პასუხისმგებელი მენეჯერი (ASSIGNED_BY_ID)');
if (!$report['managers']): ?>
    <div class="lr-empty">—</div>
<?php else: ?>
    <div class="lr-cards">
        <?php foreach ($report['managers'] as $name => $x): ?>
            <div class="lr-card">
                <p class="lr-card__title"><?= lrH($name) ?></p>
                <?php foreach ([['total', 'სულ ლიდები', ''], ['won', 'წარმატებული', 'won'], ['junk', 'წარუმატებელი', 'lost'], ['inWork', 'დამუშავებაში', 'work']] as $metric): ?>
                    <div class="lr-stat">
                        <span class="lr-stat__label"><?= $metric[1] ?></span>
                        <span class="lr-stat__values">
                            <?= lrLink($x[$metric[0]], 'manager:' . $name . ':' . $metric[0], $name . ' — ' . $metric[1], $buckets, $metric[2] !== '' ? 'tone-' . $metric[2] : '') ?>
                            <?php if ($metric[0] !== 'total'): ?>
                                <span class="lr-stat__pct tone-<?= $metric[2] ?>"><?= leadsFmtPct(leadsShare($x[$metric[0]], $x['total'])) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif;
lrSectionClose(); ?>

<?php
lrSectionOpen('ლიდები გაყიდვების მენეჯერების მიხ. და წყაროების მიხ.', lrBadge('ჯამური ლიდები', leadsFmtInt($managerTotal)), '', 'პასუხისმგებელი მენეჯერი (ASSIGNED_BY_ID) · წყარო (SOURCE)');
if (!$report['managers']): ?>
    <div class="lr-empty">—</div>
<?php else: ?>
    <div class="lr-cards lr-cards--wide">
        <?php foreach ($report['managers'] as $name => $x):
            $managerSources = $report['managerSources'][$name] ?? [];
            $managerSum = array_sum($managerSources); ?>
            <div class="lr-card">
                <p class="lr-card__title"><?= lrH($name) ?></p>
                <div class="lr-stat lr-stat--total"><span class="lr-stat__label">სულ ლიდები</span><span class="lr-stat__values"><?= leadsFmtInt($managerSum) ?></span></div>
                <div class="lr-bars lr-bars--compact">
                    <?= lrBarHead('წყარო', ['ლიდები', '%']) ?>
                    <?php foreach ($report['managerSourceOrder'] as $sourceName):
                        $count = $managerSources[$sourceName] ?? 0;
                        $share = leadsShare($count, $managerSum);
                        echo lrBar($sourceName, [
                            lrLink($count, 'managerSource:' . $name . ':' . $sourceName, $name . ' — ' . $sourceName, $buckets),
                            leadsFmtPct($share),
                        ], $share, $count > 0 ? lrShareTone($share) : 'empty', false, $count === 0);
                    endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif;
lrSectionClose(); ?>

<?php
$callsTotal = $report['calls']['calls'];
lrSectionOpen('ზარები მენეჯერების მიხედვით', lrBadge('ჯამური ზარები', leadsFmtInt($callsTotal)), '', 'ლიდი დაარეგისტრირა / პასუხისმგებელი');
if (!$report['ccOperators']): ?>
    <div class="lr-empty">—</div>
<?php else: ?>
    <div class="lr-cards">
        <?php foreach ($report['ccOperators'] as $operator => $x):
            $name = $operator === LEADS_CC_UNASSIGNED ? LEADS_CC_UNASSIGNED_LABEL : leadsUserName($operator, $ctx['userNames']); ?>
            <div class="lr-card">
                <p class="lr-card__title"><?= lrH($name) ?></p>
                <?php foreach ([['calls', 'ზარები', ''], ['redirected', 'წარმატებული', 'won'], ['inWork', 'დამუშავებაში', 'work'], ['unsuccessful', 'წარუმატებელი', 'lost']] as $metric): ?>
                    <div class="lr-stat">
                        <span class="lr-stat__label"><?= $metric[1] ?></span>
                        <span class="lr-stat__values">
                            <?= lrLink($x[$metric[0]], 'ccOperator:' . $operator . ':' . $metric[0], 'ზარები — ' . $name . ' — ' . $metric[1], $buckets, $metric[2] !== '' ? 'tone-' . $metric[2] : '') ?>
                            <?php if ($metric[0] === 'calls'): ?>
                                <span class="lr-stat__pct"><?= leadsFmtPct(leadsShare($x['calls'], $callsTotal)) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif;
lrSectionClose(); ?>

<?php // ── დინამიკა + შედარება ──────────────────────────────────────────── ?>
<?php lrSectionOpen('ლიდების დინამიკა', '', '', 'მიმდინარე თვე + წინა 2 თვე (Create date; მთავარი თარიღის ფილტრის დამოუკიდებლად)'); ?>
    <div class="lr-months">
        <?php foreach ($months as $key => $data): ?>
            <div class="lr-month<?= $key === end($monthKeys) ? ' is-current' : '' ?>">
                <p class="lr-month__label"><?= lrH(leadsMonthLabel($key)) ?></p>
                <?= lrKpi('ჯამური ლიდები', $data['total'], ['help' => 'ამ თვის ჯამური ლიდები · Create date ამ თვეში.']) ?>
                <?= lrKpi('წარმატებული', $data['won'], ['tone' => 'won', 'pct' => leadsFmtPct(leadsShare($data['won'], $data['total'])), 'help' => '% = წარმატებული ÷ ამ თვის ჯამური ლიდები × 100.']) ?>
                <?= lrKpi('წარუმატებელი', $data['junk'], ['tone' => 'lost', 'pct' => leadsFmtPct(leadsShare($data['junk'], $data['total'])), 'help' => '% = წარუმატებელი ÷ ამ თვის ჯამური ლიდები × 100.']) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="lr-compare">
        <p class="lr-compare__title">შედარება თარიღების მიხედვით</p>
        <div class="lr-compare__grid">
            <div class="lr-compare__head">
                <p class="lr-compare__label">მთავარი ფილტრი</p>
                <p class="lr-compare__range"><?= lrH($from . ' — ' . $to) ?></p>
            </div>
            <div class="lr-compare__head">
                <form method="GET" action="" class="report-filter__form lr-compare__form">
                    <input type="hidden" name="lang" value="<?= lrH($lang) ?>">
                    <input type="hidden" name="from" value="<?= lrH($from) ?>">
                    <input type="hidden" name="to" value="<?= lrH($to) ?>">
                    <input type="hidden" name="project" value="<?= lrH($project) ?>">
                    <input type="date" name="compare_from" value="<?= lrH($compareFrom) ?>">
                    <span>—</span>
                    <input type="date" name="compare_to" value="<?= lrH($compareTo) ?>">
                    <button type="submit" class="btn btn-primary lr-btn-sm">შედარება</button>
                </form>
                <p class="lr-compare__range">
                    <?php if ($compareError !== ''): ?>
                        <span class="tone-lost"><?= lrH($compareError) ?></span>
                    <?php elseif ($hasCompare): ?>
                        <?= lrH($compareFrom . ' — ' . $compareTo . ' · ' . leadsFmtInt($compareCustom['total']) . ' ლიდი') ?>
                    <?php else: ?>
                        აირჩიე თარიღები და დააჭირე შედარებას
                    <?php endif; ?>
                </p>
            </div>
            <?php foreach ([['total', 'ჯამური ლიდები', ''], ['won', 'წარმატებული', 'won'], ['junk', 'წარუმატებელი', 'lost']] as $metric):
                foreach (['main' => $compareMain, 'custom' => $compareCustom] as $side => $stats):
                    $sideLabel = $side === 'main' ? 'მთავარი ფილტრი' : 'შედარება';
                    $opt = ['bucket' => 'cmp:' . $side . ':' . $metric[0], 'title' => $sideLabel . ' — ' . $metric[1], 'tone' => $metric[2]];
                    if ($metric[0] !== 'total') {
                        $opt['pct'] = leadsFmtPct(leadsShare($stats[$metric[0]], $stats['total']));
                    }
                    echo lrKpi($metric[1], $stats[$metric[0]], $opt);
                endforeach;
            endforeach; ?>
        </div>
    </div>
<?php lrSectionClose(); ?>

<?php
lrSectionOpen('ლიდის ინფორმაცია — პროექტი', lrBadge('ჯამური', leadsFmtInt($total)));
if (!$report['projects']): ?>
    <div class="lr-empty">—</div>
<?php else: ?>
    <div class="lr-bars" id="lrProjects">
        <?= lrBarHead('პროექტი', ['რაოდ.', '%']) ?>
        <?php $i = 0;
        foreach ($report['projects'] as $label => $count):
            $share = leadsShare($count, $total);
            echo lrBar($label, [
                lrLink($count, 'project:' . $label, 'ლიდის ინფორმაცია — პროექტი — ' . $label, $buckets),
                leadsFmtPct($share),
            ], $share, lrShareTone($share), $i++ >= 10);
        endforeach; ?>
    </div>
    <?= lrMoreButton('lrProjects', count($report['projects'])) ?>
<?php endif;
lrSectionClose(); ?>

<?php // ── მარკეტინგი ───────────────────────────────────────────────────── ?>
<?php
$mkt = $report['marketingTotals'];
$mktBadge = lrBadge('მარკეტინგის ლიდები', lrLink($mkt['total'], 'kpi:marketing', 'მარკეტინგი — ჯამური', $buckets) . ' <span class="lr-badge__pct">(' . leadsFmtPct(leadsShare($mkt['total'], $total)) . ')</span>');
lrSectionOpen(
    'მარკეტინგის ანალიზი',
    $mktBadge,
    '<button type="button" class="btn btn-ghost lr-btn-sm" data-info="lrInfoMarketing" data-title="მარკეტინგის ლიდების ანალიზი">წყაროები ⓘ</button>'
); ?>
    <div class="lr-kpis">
        <?= lrKpi('წარმატებული', $mkt['won'], ['bucket' => 'mktSummary:won', 'title' => 'მარკეტინგი — წარმატებული', 'tone' => 'won', 'pct' => leadsFmtPct(leadsShare($mkt['won'], $mkt['total'])), 'hint' => 'WON · მარკეტინგის ლიდების %', 'help' => '% = მარკეტინგის წარმატებული ÷ მარკეტინგის ლიდები × 100.']) ?>
        <?= lrKpi('წარუმატებელი', $mkt['junk'], ['bucket' => 'mktSummary:junk', 'title' => 'მარკეტინგი — წარუმატებელი', 'tone' => 'lost', 'pct' => leadsFmtPct(leadsShare($mkt['junk'], $mkt['total'])), 'hint' => 'JUNK · მარკეტინგის ლიდების %', 'help' => '% = მარკეტინგის წარუმატებელი ÷ მარკეტინგის ლიდები × 100.']) ?>
        <?= lrKpi('დამუშავებაში', $mkt['inWork'], ['bucket' => 'mktSummary:inWork', 'title' => 'მარკეტინგი — დამუშავებაში', 'tone' => 'work', 'pct' => leadsFmtPct(leadsShare($mkt['inWork'], $mkt['total'])), 'hint' => 'აქტიური ეტაპი · მარკეტინგის ლიდების %', 'help' => '% = მარკეტინგის დამუშავებაში ÷ მარკეტინგის ლიდები × 100.']) ?>
    </div>
    <div class="table-wrap lr-table-wrap">
        <table class="report-table">
            <thead>
                <tr><th>წყარო</th><th>რაოდენობა</th><th class="tone-won">წარმატებული</th><th>კონვერსია %</th><th class="tone-lost">წარუმატებელი</th><th class="tone-work">დამუშავებაში</th></tr>
            </thead>
            <tbody>
                <?php foreach ($report['marketing'] as $name => $x): ?>
                    <tr class="<?= $x['total'] ? '' : 'is-muted' ?>">
                        <td><span class="row-main"><?= lrH($name) ?></span></td>
                        <td><?= lrLink($x['total'], 'mkt:' . $name . ':total', 'მარკეტინგი — ' . $name, $buckets) ?></td>
                        <td><?= lrLink($x['won'], 'mkt:' . $name . ':won', 'მარკეტინგი — ' . $name . ' — წარმატებული', $buckets, 'tone-won') ?></td>
                        <td><?= leadsFmtPct(leadsShare($x['won'], $x['total'])) ?></td>
                        <td><?= lrLink($x['junk'], 'mkt:' . $name . ':junk', 'მარკეტინგი — ' . $name . ' — წარუმატებელი', $buckets, 'tone-lost') ?></td>
                        <td><?= lrLink($x['inWork'], 'mkt:' . $name . ':inWork', 'მარკეტინგი — ' . $name . ' — დამუშავებაში', $buckets, 'tone-work') ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>ჯამი</td>
                    <td><?= leadsFmtInt($mkt['total']) ?></td>
                    <td><?= leadsFmtInt($mkt['won']) ?></td>
                    <td><?= leadsFmtPct(leadsShare($mkt['won'], $mkt['total'])) ?></td>
                    <td><?= leadsFmtInt($mkt['junk']) ?></td>
                    <td><?= leadsFmtInt($mkt['inWork']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
<?php lrSectionClose(); ?>

<?php lrSectionOpen(
    'მარკეტინგის ხარჯი და ROI',
    '',
    '<button type="button" class="btn btn-ghost lr-btn-sm" data-info="lrInfoCost" data-title="მარკეტინგის ხარჯი და ROI">აღწერა ⓘ</button>'
); ?>
    <p class="lr-subtitle">ჯამური</p>
    <div class="table-wrap lr-table-wrap">
        <table class="report-table lr-cost-table">
            <thead><?= $costHead ?></thead>
            <tbody>
                <?php if (!$cost['hasData']): ?>
                    <tr><td class="report-empty" colspan="10">—</td></tr>
                <?php else: ?>
                    <tr class="total-row"><td>All</td><td>Total</td><?= lrCostCells($cost['totals']) ?></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="lr-subtitle">წყაროების მიხედვით</p>
    <div class="table-wrap lr-table-wrap">
        <table class="report-table lr-cost-table">
            <thead><?= $costHead ?></thead>
            <tbody>
                <?php if (!$cost['groups']): ?>
                    <tr><td class="report-empty" colspan="10">—</td></tr>
                <?php endif; ?>
                <?php foreach ($cost['groups'] as $source => $group): ?>
                    <tr>
                        <td>
                            <?php if ($group['projects']): ?>
                                <button type="button" class="lr-expand" data-expand="cost-<?= lrH($source) ?>" title="პროექტების ჩვენება">+</button>
                            <?php else: ?>
                                <span class="lr-expand is-empty">+</span>
                            <?php endif; ?>
                            <span class="row-main"><?= lrH(leadsCostSourceLabel($source)) ?></span>
                        </td>
                        <td>Total</td>
                        <?= lrCostCells($group['totals']) ?>
                    </tr>
                    <?php foreach ($group['projects'] as $row): ?>
                        <tr class="sub-row" data-parent="cost-<?= lrH($source) ?>" hidden>
                            <td></td>
                            <td><span class="row-sub"><span class="row-sub__dot"></span><?= lrH($row['project']) ?></span></td>
                            <?= lrCostCells($row) ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="lr-subtitle">შემოსვლის არხები</p>
    <div class="table-wrap lr-table-wrap">
        <table class="report-table report-table--matrix lr-table-narrow">
            <thead>
                <tr><?php foreach (LEADS_INBOUND_CHANNELS as $channel): ?><th><?= lrH($channel['label']) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach (LEADS_INBOUND_CHANNELS as $key => $channel): ?>
                        <td><?= lrLink($report['inbound'][$key], 'inbound:' . $key, 'შემოსვლის არხი — ' . $channel['label'], $buckets) ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>
<?php lrSectionClose(); ?>

<?php // ── სოციალური არხები — Dailo (addStats) ──────────────────────────── ?>
<?php
$dailoHint = 'მონაცემი Dailo-დან (addStats სერვისი) · დღე = სტატისტიკის თარიღი (STAT_DATE), იგივე პერიოდი რაც მთავარ ფილტრში.';
if ($project !== '') {
    $dailoHint .= ' Dailo-ს მონაცემი პროექტის ფილტრს არ ექვემდებარება — CRM სვეტები კი მხოლოდ „' . $project . '“-ს ითვლის.';
}
lrSectionOpen(
    'სოციალური არხები — Dailo',
    lrBadge('დღეები', leadsFmtInt($dailoTotals['daysWithData']) . ' / ' . leadsFmtInt($dailoTotals['daysInPeriod'])),
    $dailo['days'] ? '<button type="button" class="btn btn-export lr-btn-sm" data-export="dailo" data-file="dailo-daily-stats">Excel</button>' : '',
    $dailoHint
);
if (!$dailo['available']): ?>
    <div class="lr-empty">Dailo-ს სიები ჯერ არ არის შექმნილი (/custom/setup/dailoStatsLists.php).</div>
<?php elseif (!$dailo['days']): ?>
    <div class="lr-empty">ამ პერიოდისთვის Dailo-დან მონაცემი არ მოსულა.</div>
<?php else: ?>
    <div class="lr-kpis">
        <?= lrKpi('საუბრები', $dailoTotals['conversations'], ['hint' => 'ყველა არხი', 'help' => 'Dailo-ს საუბრები (conversations) პერიოდის დღეების ჯამი.']) ?>
        <?= lrKpi('ლიდები (Dailo)', $dailoTotals['leads'], ['tone' => 'won', 'hint' => 'Dailo-ს მიერ დაფიქსირებული', 'help' => 'Dailo-ს ლიდები (leads) პერიოდის დღეების ჯამი.']) ?>
        <?= lrKpi('კონვერსია', leadsFmtPct(leadsShare($dailoTotals['leads'], $dailoTotals['conversations'])), ['tone' => 'accent', 'hint' => 'ლიდები ÷ საუბრები', 'help' => 'კონვერსია = Dailo ლიდები ÷ საუბრები × 100.']) ?>
        <?= lrKpi('კომენტარები', $dailoTotals['comments'], ['hint' => 'სულ', 'help' => 'კომენტარები სულ (comments.total).']) ?>
        <?= lrKpi('პასუხგაცემული', $dailoTotals['answered'], ['tone' => 'won', 'pct' => leadsFmtPct(leadsShare($dailoTotals['answered'], $dailoTotals['comments'])), 'hint' => 'კომენტარების %', 'help' => '% = პასუხგაცემული ÷ კომენტარები × 100.']) ?>
        <?= lrKpi('დამალული', $dailoTotals['hidden'], ['tone' => 'lost', 'pct' => leadsFmtPct(leadsShare($dailoTotals['hidden'], $dailoTotals['comments'])), 'hint' => 'კომენტარების %', 'help' => '% = დამალული ÷ კომენტარები × 100.']) ?>
    </div>
    <?php if ($dailo['lastReceived'] !== ''): ?>
        <p class="lr-note">ბოლო მიღება: <?= lrH($dailo['lastReceived']) ?></p>
    <?php endif; ?>

    <p class="lr-subtitle">არხების მიხედვით</p>
    <div class="table-wrap lr-table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th>არხი</th>
                    <th>საუბრები</th>
                    <th class="tone-won">Dailo ლიდები</th>
                    <th>კონვ. %</th>
                    <th title="CRM ლიდები იგივე პერიოდში, არხის შესაბამისი წყაროებით">CRM ლიდები</th>
                    <th class="tone-won" title="CRM-ის წარმატებული ლიდები არხის წყაროებით">CRM წარმატებული</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $dailoChannels = array_unique(array_merge(array_keys($dailo['channels']), array_keys(LEADS_DAILO_CHANNEL_SOURCES)));
                $channelTotals = ['conversations' => 0, 'leads' => 0, 'crm' => 0, 'crmWon' => 0];
                foreach ($dailoChannels as $channel):
                    $d = $dailo['channels'][$channel] ?? ['conversations' => 0, 'leads' => 0];
                    $crm = $report['dailoCrm'][$channel] ?? null;
                    $channelTotals['conversations'] += $d['conversations'];
                    $channelTotals['leads'] += $d['leads'];
                    $channelTotals['crm'] += $crm['leads'] ?? 0;
                    $channelTotals['crmWon'] += $crm['won'] ?? 0;
                    $sourceNames = [];
                    if (array_key_exists($channel, LEADS_DAILO_CHANNEL_SOURCES)) {
                        foreach (LEADS_DAILO_CHANNEL_SOURCES[$channel] as $sourceId) {
                            $sourceNames[] = $sources[$sourceId] ?? $sourceId;
                        }
                    } ?>
                    <tr class="<?= $d['conversations'] || $d['leads'] || !empty($crm['leads']) ? '' : 'is-muted' ?>">
                        <td><span class="row-main"><?= lrH($channel) ?></span></td>
                        <td><?= leadsFmtInt($d['conversations']) ?></td>
                        <td class="tone-won"><?= leadsFmtInt($d['leads']) ?></td>
                        <td><?= leadsFmtPct(leadsShare($d['leads'], $d['conversations'])) ?></td>
                        <td title="<?= lrH(implode(', ', $sourceNames)) ?>">
                            <?= $crm === null ? '—' : lrLink($crm['leads'], 'dailoCrm:' . $channel, 'CRM ლიდები — ' . $channel, $buckets) ?>
                        </td>
                        <td>
                            <?= $crm === null ? '—' : lrLink($crm['won'], 'dailoCrmWon:' . $channel, 'CRM წარმატებული — ' . $channel, $buckets, 'tone-won') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>ჯამი</td>
                    <td><?= leadsFmtInt($channelTotals['conversations']) ?></td>
                    <td><?= leadsFmtInt($channelTotals['leads']) ?></td>
                    <td><?= leadsFmtPct(leadsShare($channelTotals['leads'], $channelTotals['conversations'])) ?></td>
                    <td><?= leadsFmtInt($channelTotals['crm']) ?></td>
                    <td><?= leadsFmtInt($channelTotals['crmWon']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="lr-subtitle">დღეების მიხედვით</p>
    <div class="table-wrap lr-table-wrap">
        <table class="report-table" id="lrDailoDays">
            <thead>
                <tr><th>თარიღი</th><th>საუბრები</th><th class="tone-won">ლიდები</th><th>კონვ. %</th><th>კომენტარები</th><th>პასუხგაცემული</th><th>დამალული</th></tr>
            </thead>
            <tbody>
                <?php $i = 0;
                foreach ($dailo['days'] as $date => $day):
                    $conv = leadsShare($day['leads'], $day['conversations']);
                    $exports['dailo'][] = [$date, $day['conversations'], $day['leads'], round($conv, 2), $day['comments'], $day['answered'], $day['hidden']]; ?>
                    <tr class="<?= $i++ >= 10 ? 'lr-extra' : '' ?>">
                        <td><?= lrH($date) ?></td>
                        <td><?= leadsFmtInt($day['conversations']) ?></td>
                        <td class="tone-won"><?= leadsFmtInt($day['leads']) ?></td>
                        <td><?= leadsFmtPct($conv) ?></td>
                        <td><?= leadsFmtInt($day['comments']) ?></td>
                        <td><?= leadsFmtInt($day['answered']) ?></td>
                        <td><?= leadsFmtInt($day['hidden']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= lrMoreButton('lrDailoDays', count($dailo['days'])) ?>
<?php endif;
lrSectionClose(); ?>

<?php // ── წარუმატებლობის მიზეზები ──────────────────────────────────────── ?>
<?php
$lossTotal = array_sum($report['lossReasons']);
lrSectionOpen('წარუმატებლობის მიზეზები', lrBadge('ჯამური', leadsFmtInt($lossTotal))); ?>
    <div class="table-wrap lr-table-wrap">
        <table class="report-table lr-table-narrow" id="lrLossReasons">
            <thead><tr><th>მიზეზი</th><th>რაოდ.</th><th>%</th></tr></thead>
            <tbody>
                <?php if (!$report['lossReasons']): ?>
                    <tr><td class="report-empty" colspan="3">—</td></tr>
                <?php endif; ?>
                <?php $i = 0;
                foreach ($report['lossReasons'] as $reason => $count): ?>
                    <tr class="<?= $i++ >= 10 ? 'lr-extra' : '' ?>">
                        <td><span class="tone-lost"><?= lrH($reason) ?></span></td>
                        <td><?= lrLink($count, 'loss:' . $reason, 'წარუმატებლობის მიზეზი — ' . $reason, $buckets) ?></td>
                        <td><?= leadsFmtPct(leadsShare($count, $lossTotal)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= lrMoreButton('lrLossReasons', count($report['lossReasons'])) ?>
<?php lrSectionClose(); ?>

<?php // ── ზარები ───────────────────────────────────────────────────────── ?>
<?php lrSectionOpen('ზარების მაჩვენებლები'); ?>
    <div class="lr-kpis">
        <?= lrKpi('დამუშავებული ზარები', $report['callCenter'], ['bucket' => 'kpi:callCenter', 'title' => 'ზარების დამუშავებული', 'hint' => 'ეტაპი 1 ან 2 · ან CC-დან lost', 'help' => 'ზარების დამუშავებული = ეტაპი 1 ან 2, ან CC-დან პირდაპირ lost.']) ?>
        <?= lrKpi('წარუმატებელი', $report['junkCallCenter'], ['bucket' => 'kpi:junkCallCenter', 'title' => 'წარუმატებელი — JUNK (Call Center)', 'tone' => 'lost', 'hint' => 'წარუმატებელი ზარები']) ?>
    </div>
<?php lrSectionClose(); ?>

<?php
$calls = $report['calls'];
lrSectionOpen('ზარები', '', '', 'შემოსული ზარები (SOURCE: ზარი)'); ?>
    <div class="lr-kpis">
        <?= lrKpi('ზარების ჯამი', $calls['calls'], ['bucket' => 'calls:calls', 'title' => 'ზარები — ' . LEADS_CALL_SOURCE_LABEL]) ?>
        <?= lrKpi('წარმატებული', $calls['redirected'], ['bucket' => 'calls:redirected', 'title' => 'ზარები — წარმატებული', 'tone' => 'won', 'pct' => leadsFmtPct(leadsShare($calls['redirected'], $calls['calls']))]) ?>
        <?= lrKpi('წარუმატებელი', $calls['unsuccessful'], ['bucket' => 'calls:unsuccessful', 'title' => 'ზარები — წარუმატებელი', 'tone' => 'lost', 'pct' => leadsFmtPct(leadsShare($calls['unsuccessful'], $calls['calls']))]) ?>
        <?= lrKpi('დამუშავებაში', $calls['inWork'], ['bucket' => 'calls:inWork', 'title' => 'ზარები — დამუშავებაში', 'tone' => 'work', 'pct' => leadsFmtPct(leadsShare($calls['inWork'], $calls['calls']))]) ?>
    </div>
    <div class="table-wrap lr-table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th>ზარის ტიპი</th><th>რაოდ</th>
                    <th class="tone-won">წარმატ.</th><th class="tone-won">%</th>
                    <th class="tone-won">წარმ. (WON)</th><th class="tone-won">%</th>
                    <th class="tone-lost">წარუმ.</th><th class="tone-lost">%</th>
                    <th class="tone-work">დამუშ.</th><th class="tone-work">%</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$calls['calls']): ?>
                    <tr><td class="report-empty" colspan="10">—</td></tr>
                <?php else: ?>
                    <tr>
                        <td><span class="row-main"><?= lrH(LEADS_CALL_SOURCE_LABEL) ?></span></td>
                        <td><?= lrLink($calls['calls'], 'calls:calls', 'ზარები — ' . LEADS_CALL_SOURCE_LABEL, $buckets) ?></td>
                        <?php foreach ([['redirected', 'წარმატებული', 'won'], ['won', 'WON', 'won'], ['unsuccessful', 'წარუმატებელი', 'lost'], ['inWork', 'დამუშავებაში', 'work']] as $metric): ?>
                            <td><?= lrLink($calls[$metric[0]], 'calls:' . $metric[0], 'ზარები — ' . LEADS_CALL_SOURCE_LABEL . ' — ' . $metric[1], $buckets, 'tone-' . $metric[2]) ?></td>
                            <td><?= leadsFmtPct(leadsShare($calls[$metric[0]], $calls['calls'])) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="total-row">
                        <td>ჯამი</td>
                        <td><?= leadsFmtInt($calls['calls']) ?></td>
                        <?php foreach (['redirected', 'won', 'unsuccessful', 'inWork'] as $metric): ?>
                            <td><?= leadsFmtInt($calls[$metric]) ?></td>
                            <td><?= leadsFmtPct(leadsShare($calls[$metric], $calls['calls'])) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php lrSectionClose(); ?>

<?php // ── ინფო ფანჯრების შიგთავსი ──────────────────────────────────────── ?>
<template id="lrInfoMarketing">
    <p class="lr-info__hint">აჯამებს: რეპორტის ფილტრის პერიოდში (Create date) შემდეგი წყაროების ყველა ლიდს. აგენტის და „სხვა“ კატეგორიის წყაროები აქ არ შედის. რიცხვებზე დაკლიკებით — ლიდების სია.</p>
    <ol class="lr-info__list">
        <?php foreach (LEADS_MARKETING_SOURCES as $sourceId => $label): ?>
            <li><?= lrH($sources[$sourceId] ?? $label) ?> <span class="lr-info__id">(<?= lrH($sourceId) ?>)</span></li>
        <?php endforeach; ?>
    </ol>
</template>
<template id="lrInfoCost">
    <p class="lr-info__hint">წყარო და Total Cost — <a href="/services/lists/<?= REPORT_MARKETING_COST_IBLOCK ?>/view/0/" target="_blank" rel="noopener">მარკეტინგული ხარჯების ლისტი</a>. ხარჯი: ლისტის თვე ემთხვევა რეპორტის ფილტრს. Leads / Won — CRM-ის მარკეტინგის წყაროები, Create date ფილტრი.</p>
    <ul class="lr-info__list">
        <li><b>Total Cost</b> — ხარჯი ლისტი 27 · spend.</li>
        <li><b>Leads</b> — მარკეტინგის ლიდები.</li>
        <li><b>CPL</b> — Cost Per Lead = Total Cost ÷ Leads.</li>
        <li><b>Won</b> — WON მარკეტინგის ლიდები.</li>
        <li><b>CR %</b> — Won ÷ Leads × 100.</li>
        <li><b>Cost per Apt.</b> — Total Cost ÷ Won.</li>
        <li><b>Revenue</b> — Σ OPPORTUNITY (WON).</li>
        <li><b>ROI</b> — Revenue ÷ Total Cost.</li>
    </ul>
</template>

<div class="lr-modal" id="lrModal" hidden>
    <div class="lr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="lrModalTitle">
        <div class="lr-modal__head">
            <h3 class="lr-modal__title" id="lrModalTitle">Details</h3>
            <div class="lr-modal__actions">
                <button type="button" class="btn btn-export lr-btn-sm" id="lrModalExport">Excel</button>
                <button type="button" class="btn btn-ghost lr-btn-sm" id="lrModalClose">დახურვა</button>
            </div>
        </div>
        <div class="lr-modal__body">
            <div class="lr-modal__info" id="lrModalInfo" hidden></div>
            <table class="report-table lr-modal__table" id="lrModalTable">
                <thead><tr id="lrModalHead"></tr></thead>
                <tbody id="lrModalBody"></tbody>
            </table>
        </div>
    </div>
</div>

<style>
    .lr-section { overflow: hidden; }
    .lr-section__head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px 0; flex-wrap: wrap; }
    .lr-section__title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .lr-section__actions { display: flex; gap: 8px; }
    .lr-section__hint { margin: 6px 16px 0; font-size: 12px; color: var(--rp-muted); }
    .lr-body { padding: 12px 16px 16px; }
    .lr-subtitle { margin: 14px 0 0; font-size: 12px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--rp-muted); }
    .lr-subtitle:first-child { margin-top: 0; }
    .lr-note { margin: 8px 0 0; font-size: 12px; color: var(--rp-muted); }
    .lr-empty { padding: 18px; text-align: center; color: var(--rp-muted); }
    .lr-status { margin-left: auto; font-size: 12px; color: var(--rp-muted); }
    .lr-btn-sm { padding: 7px 12px; font-size: 11px; }
    .lr-two { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 10px; }
    .lr-table-wrap { padding: 8px 0 0; }
    .lr-table-narrow { min-width: 0; }
    .report-table tr.is-muted td { color: #a3adb8; }
    .lr-section .report-table thead th:not(:first-child) { text-align: right; }
    .lr-section .report-table--matrix thead th { text-align: center; }
    .lr-cost-table th:nth-child(2), .lr-cost-table td:nth-child(2) { text-align: left !important; }

    .tone-won { color: #15803d !important; }
    .tone-lost { color: #c0392b !important; }
    .tone-work { color: #b7791f !important; }
    .tone-accent { color: #1a6b5c !important; }

    .lr-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; background: var(--rp-primary-soft); color: var(--rp-primary); font-size: 12px; }
    .lr-badge__label { font-weight: 600; color: var(--rp-muted); }
    .lr-badge__value { font-weight: 700; }
    .lr-badge__pct { font-weight: 600; color: var(--rp-muted); }

    .lr-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
    .lr-kpis--gap { margin-top: 10px; }
    .lr-kpi { position: relative; padding: 12px 14px; border: 1px solid var(--rp-border); border-radius: 4px; background: #fff; }
    .lr-kpi.is-link { cursor: pointer; transition: border-color 0.15s ease, transform 0.15s ease; }
    .lr-kpi.is-link:hover, .lr-kpi.is-link:focus { border-color: var(--rp-accent); transform: translateY(-1px); outline: none; }
    .lr-kpi__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 6px; }
    .lr-kpi__eyebrow { margin: 0; font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: var(--rp-muted); }
    .lr-kpi__value { margin-top: 6px; font-size: 26px; font-weight: 700; color: var(--rp-primary); font-variant-numeric: tabular-nums; }
    .lr-kpi__pct { margin: 2px 0 0; font-size: 13px; font-weight: 600; }
    .lr-kpi__hint { margin: 4px 0 0; font-size: 11px; color: var(--rp-muted); }
    .lr-help { flex-shrink: 0; display: inline-grid; place-items: center; width: 16px; height: 16px; border-radius: 50%; background: var(--rp-surface-2); color: var(--rp-muted); font-size: 10px; font-weight: 700; cursor: help; }

    .lr-link { padding: 0; border: 0; background: none; font: inherit; font-weight: 700; color: var(--rp-primary); cursor: pointer; text-decoration: underline dotted; text-underline-offset: 3px; }
    .lr-link:hover { color: #1a6b5c; }
    .lr-num { font-weight: 600; }

    .lr-bars { display: grid; }
    .lr-bar { display: grid; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid #eef1f4; font-size: 13px; }
    .lr-bar--c2 { grid-template-columns: minmax(140px, 1.3fr) 70px 70px minmax(100px, 2fr); }
    .lr-bar--c3 { grid-template-columns: minmax(140px, 1.3fr) 70px 70px 70px minmax(100px, 2fr); }
    .lr-bar--head { font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--rp-muted); }
    .lr-bar.is-muted { color: #a3adb8; }
    .lr-bar__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
    .lr-bar__col { text-align: right; font-variant-numeric: tabular-nums; }
    .lr-track { height: 8px; border-radius: 4px; background: #eef1f4; overflow: hidden; }
    .lr-fill { display: block; height: 100%; border-radius: 4px; }
    .lr-fill--green { background: #22a06b; }
    .lr-fill--yellow { background: #e0a526; }
    .lr-fill--red { background: #d64545; }
    .lr-fill--blue { background: #2f7fd8; }
    .lr-fill--won { background: #22a06b; }
    .lr-fill--work { background: #e0a526; }
    .lr-fill--lost { background: #d64545; }
    .lr-fill--empty { background: transparent; }
    .lr-bars--compact .lr-bar { padding: 5px 0; font-size: 12px; }
    .lr-bars--compact .lr-bar--c2 { grid-template-columns: minmax(100px, 1.5fr) 50px 60px minmax(60px, 1fr); }

    .lr-extra { display: none !important; }
    .is-expanded .lr-bar.lr-extra { display: grid !important; }
    .is-expanded tr.lr-extra { display: table-row !important; }
    .lr-more { margin-top: 10px; padding: 6px 14px; border: 1px solid var(--rp-border); border-radius: 2px; background: #fff; color: var(--rp-primary); font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }

    .lr-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 10px; }
    .lr-cards--wide { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
    .lr-card { padding: 12px 14px; border: 1px solid var(--rp-border); border-radius: 4px; background: #fff; }
    .lr-card__title { margin: 0 0 8px; font-size: 13px; font-weight: 700; color: var(--rp-primary); }
    .lr-stat { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; padding: 5px 0; border-bottom: 1px solid #eef1f4; font-size: 13px; }
    .lr-stat:last-child { border-bottom: 0; }
    .lr-stat--total { font-weight: 700; }
    .lr-stat__label { color: var(--rp-muted); }
    .lr-stat__values { display: inline-flex; gap: 8px; align-items: baseline; font-variant-numeric: tabular-nums; }
    .lr-stat__pct { font-size: 12px; font-weight: 600; color: var(--rp-muted); }

    .lr-months { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
    .lr-month { display: grid; gap: 8px; padding: 10px; border-radius: 4px; background: var(--rp-surface-2); }
    .lr-month.is-current { background: var(--rp-accent-soft); }
    .lr-month__label { margin: 0; font-size: 13px; font-weight: 700; color: var(--rp-primary); text-transform: uppercase; }

    .lr-compare { margin-top: 16px; }
    .lr-compare__title { margin: 0 0 10px; font-size: 12px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--rp-muted); }
    .lr-compare__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .lr-compare__head { padding: 10px 12px; border-radius: 4px; background: var(--rp-surface-2); }
    .lr-compare__label { margin: 0; font-size: 12px; font-weight: 700; color: var(--rp-primary); text-transform: uppercase; }
    .lr-compare__range { margin: 6px 0 0; font-size: 12px; color: var(--rp-muted); }
    .lr-compare__form { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .lr-compare__form input[type="date"] { padding: 6px 8px; border: 1px solid var(--rp-border); border-radius: 4px; font: inherit; font-size: 12px; }

    .lr-cost-table { min-width: 900px; }
    .lr-expand { display: inline-grid; place-items: center; width: 20px; height: 20px; margin-right: 6px; border: 1px solid var(--rp-border); border-radius: 2px; background: #fff; color: var(--rp-primary); font: inherit; font-weight: 700; cursor: pointer; }
    .lr-expand.is-empty { visibility: hidden; }

    .lr-modal { position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0, 36, 69, 0.45); }
    .lr-modal[hidden] { display: none; }
    .lr-modal__dialog { display: flex; flex-direction: column; width: min(1280px, 100%); max-height: calc(100vh - 32px); border-radius: 4px; background: #fff; box-shadow: 0 20px 50px rgba(0, 36, 69, 0.3); }
    .lr-modal__head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--rp-border); }
    .lr-modal__title { margin: 0; font-size: 14px; font-weight: 700; color: var(--rp-primary); }
    .lr-modal__actions { display: flex; gap: 8px; }
    .lr-modal__body { overflow: auto; padding: 8px; }
    .lr-modal__table { min-width: 1100px; font-size: 12px; }
    .lr-modal__table tbody td { padding: 8px 10px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: left !important; }
    .lr-modal__table thead th { padding: 9px 10px; }
    .lr-modal__info { padding: 8px 10px; font-size: 13px; line-height: 1.5; color: #2a3a4a; }
    .lr-info__hint { margin: 0 0 10px; color: var(--rp-muted); }
    .lr-info__list { margin: 0; padding-left: 20px; }
    .lr-info__list li { padding: 3px 0; }
    .lr-info__id { color: var(--rp-muted); font-size: 12px; }

    @media (max-width: 768px) {
        .lr-two, .lr-compare__grid { grid-template-columns: 1fr; }
        .lr-bar--c2, .lr-bar--c3 { grid-template-columns: minmax(100px, 1fr) repeat(3, auto); }
        .lr-bar--c2 .lr-track, .lr-bar--c3 .lr-track { display: none; }
        .lr-status { margin-left: 0; }
    }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
(function () {
    const LR = <?= json_encode(
        ['rows' => $rows, 'buckets' => $buckets, 'exports' => $exports],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PARTIAL_OUTPUT_ON_ERROR
    ) ?>;
    const HEAD = ['ID', 'Create date', 'ეტაპი', 'წყარო', 'კლიენტი', 'ლიდი დაარეგისტრირა', 'კონტაქტზე პასუხისმგებელი', 'პასუხისმგებელი', 'ლოსთის მიზეზი', 'ლოსთის მიზეზი დეტალურად', 'უინტერესობის მიზეზი'];

    const modal = document.getElementById('lrModal');
    const modalTitle = document.getElementById('lrModalTitle');
    const modalInfo = document.getElementById('lrModalInfo');
    const modalTable = document.getElementById('lrModalTable');
    const modalBody = document.getElementById('lrModalBody');
    const modalExport = document.getElementById('lrModalExport');
    let modalRows = [];

    document.getElementById('lrModalHead').innerHTML = HEAD.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('');

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fmtInt(n) {
        return new Intl.NumberFormat('en-US').format(n || 0);
    }

    function openRows(title, ids) {
        modalRows = (ids || []).map(function (id) { return LR.rows[id]; }).filter(Boolean);
        modalTitle.textContent = title + ' (' + fmtInt(modalRows.length) + ')';
        modalInfo.hidden = true;
        modalTable.hidden = false;
        modalExport.hidden = modalRows.length === 0;
        modalBody.innerHTML = modalRows.length
            ? modalRows.map(function (r) {
                return '<tr><td><a href="/crm/deal/details/' + r[0] + '/" target="_blank" rel="noopener">' + r[0] + '</a></td>'
                    + r.slice(1).map(function (v) { return '<td title="' + esc(v) + '">' + esc(v) + '</td>'; }).join('')
                    + '</tr>';
            }).join('')
            : '<tr><td class="report-empty" colspan="' + HEAD.length + '">ჩანაწერები არ მოიძებნა</td></tr>';
        modal.hidden = false;
    }

    function openInfo(title, html) {
        modalRows = [];
        modalTitle.textContent = title;
        modalInfo.innerHTML = html;
        modalInfo.hidden = false;
        modalTable.hidden = true;
        modalExport.hidden = true;
        modal.hidden = false;
    }

    function closeModal() {
        modal.hidden = true;
        modalRows = [];
    }

    function writeXlsx(aoa, fileName, sheetName) {
        if (typeof XLSX === 'undefined' || !aoa.length) return;
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(aoa), sheetName);
        XLSX.writeFile(wb, fileName + '-' + new Date().toISOString().slice(0, 10) + '.xlsx');
    }

    modalExport.addEventListener('click', function () {
        writeXlsx([HEAD].concat(modalRows), 'deals-report', 'Deals');
    });
    document.getElementById('lrModalClose').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
        if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('.lr-kpi[data-bucket]')) {
            e.preventDefault();
            openRows(e.target.dataset.title, LR.buckets[e.target.dataset.bucket]);
        }
    });

    document.addEventListener('click', function (e) {
        if (e.target.closest('.lr-help')) return;

        const more = e.target.closest('[data-more]');
        if (more) {
            const list = document.getElementById(more.dataset.more);
            const open = list.classList.toggle('is-expanded');
            more.textContent = open ? 'Less' : 'More';
            return;
        }

        const expand = e.target.closest('[data-expand]');
        if (expand) {
            const open = expand.textContent.trim() === '+';
            document.querySelectorAll('tr[data-parent="' + expand.dataset.expand + '"]').forEach(function (tr) { tr.hidden = !open; });
            expand.textContent = open ? '−' : '+';
            expand.title = open ? 'პროექტების დამალვა' : 'პროექტების ჩვენება';
            return;
        }

        const exportBtn = e.target.closest('[data-export]');
        if (exportBtn) {
            writeXlsx(LR.exports[exportBtn.dataset.export] || [], exportBtn.dataset.file || 'export', 'Report');
            return;
        }

        const info = e.target.closest('[data-info]');
        if (info) {
            const tpl = document.getElementById(info.dataset.info);
            if (tpl) openInfo(info.dataset.title || '', tpl.innerHTML);
            return;
        }

        const bucket = e.target.closest('[data-bucket]');
        if (bucket) {
            openRows(bucket.dataset.title || 'Details', LR.buckets[bucket.dataset.bucket]);
        }
    });
})();
</script>
<?php reportPageEnd($lang); ?>
