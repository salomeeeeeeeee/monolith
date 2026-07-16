<?php
ob_start();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once __DIR__ . '/helpers.php';

$APPLICATION->SetTitle('Debt Report');

$lang = $_GET['lang'] ?? 'ge';
$t = reportGetProductLabels($lang);
$t = array_merge($t, [
    'title_general' => $lang === 'eng' ? 'General Products' : 'ზოგადი პროდუქტები',
    'col_prod_type' => $lang === 'eng' ? 'Product Type' : 'პროდუქტის ტიპი',
    'col_sold_amt' => $lang === 'eng' ? 'Total Sale Amount' : 'ჯამური გაყიდვების თანხა',
    'col_scheduled' => $lang === 'eng' ? 'Total Scheduled' : 'ჯამური დარიცხვა',
    'col_paid' => $lang === 'eng' ? 'Total Paid' : 'ჯამური გადახდა',
    'col_debt' => $lang === 'eng' ? 'Current Debt' : 'მიმდინარე დავალიანება',
    'no_data' => $lang === 'eng' ? 'No data available' : 'მონაცემი არ მოიძებნა',
    'xls_deal' => $lang === 'eng' ? 'Deal#' : 'გარიგება#',
    'xls_client' => $lang === 'eng' ? 'Client' : 'კლიენტი',
    'xls_type' => $lang === 'eng' ? 'Product Type' : 'პროდუქტის ტიპი',
    'xls_total_price' => $lang === 'eng' ? 'Total Sale Amount ($)' : 'სრული გაყიდვების თანხა ($)',
    'xls_scheduled' => $lang === 'eng' ? 'Total Scheduled ($)' : 'ჯამური დარიცხვა ($)',
    'xls_paid' => $lang === 'eng' ? 'Total Paid ($)' : 'ჯამური გადახდა ($)',
    'xls_debt' => $lang === 'eng' ? 'Current Debt ($)' : 'მიმდინარე დავალიანება ($)',
]);

$filterProject = trim($_GET['project'] ?? '');
$filterBlock = trim($_GET['block'] ?? '');
$filterResponsible = trim($_GET['responsible'] ?? '');

$arFilter = ['STAGE_ID' => REPORT_WON_STAGE];
if ($filterProject !== '') {
    $arFilter[D_PROJECT] = $filterProject;
}
if ($filterBlock !== '') {
    $arFilter[D_BLOCK] = $filterBlock;
}
if ($filterResponsible !== '') {
    $arFilter['ASSIGNED_BY_ID'] = $filterResponsible;
}

$deals = reportGetDealsByFilter($arFilter, [
    'ID', 'CONTACT_FULL_NAME', 'OPPORTUNITY', D_PROJECT, D_BLOCK, D_TYPE, 'ASSIGNED_BY_ID',
]);
$dealsIds = array_keys($deals);

// Full schedule from list 22 (all rows), not only dates <= today
$daricxvebi = reportGetDaricxvebi($dealsIds, false);
$gadaxdebi = reportGetGadaxdebi($dealsIds);

$productsByDeal = [];
foreach (reportGetProducts() as $row) {
    $ownerDealId = reportExtractDealId($row['OWNER_DEAL'] ?? '');
    if ($ownerDealId !== '' && isset($deals[$ownerDealId])) {
        $productsByDeal[$ownerDealId] = $row;
    }
}

foreach ($deals as &$deal) {
    $deal['jamuriDaricxvaUpToToday'] = 0;
    $deal['jamuriGadaxdaUpToToday'] = 0;
}
unset($deal);

foreach ($daricxvebi as $d) {
    $dealId = reportExtractDealId($d['DEAL_ID'] ?? '');
    if ($dealId !== '' && isset($deals[$dealId])) {
        $deals[$dealId]['jamuriDaricxvaUpToToday'] += $d['daricxva_amount'];
    }
}
foreach ($gadaxdebi as $g) {
    $dealId = reportExtractDealId($g['DEAL_ID'] ?? '');
    if ($dealId !== '' && isset($deals[$dealId])) {
        $deals[$dealId]['jamuriGadaxdaUpToToday'] += $g['gadaxda_amount'];
    }
}
foreach ($deals as &$deal) {
    $deal['mimdinareDavalianeba'] = $deal['jamuriDaricxvaUpToToday'] - $deal['jamuriGadaxdaUpToToday'];
}
unset($deal);

$resArray = [];
foreach ($deals as $deal) {
    $prodType = $deal[D_TYPE] ?? '';
    $dealKey = reportExtractDealId($deal['ID'] ?? '');
    $bedrooms = $productsByDeal[$dealKey][F_BEDROOMS] ?? '';

    if (($deal[D_BLOCK] ?? '') === 'P') {
        $prodType = 'გარე ავტოსადგომი';
    } elseif ($prodType === 'ავტოსადგომი') {
        $prodType = 'შიდა ავტოსადგომი';
    }

    if (!isset($resArray[$prodType])) {
        $resArray[$prodType] = [
            'jamuriGayidvebisAmount' => 0,
            'jamuriDaricxvaUpToToday' => 0,
            'jamuriGadaxdaUpToToday' => 0,
            'mimdinareDavalianeba' => 0,
        ];
    }

    $resArray[$prodType]['jamuriGayidvebisAmount'] += (float)($deal['OPPORTUNITY'] ?? 0);
    $resArray[$prodType]['jamuriDaricxvaUpToToday'] += (float)($deal['jamuriDaricxvaUpToToday'] ?? 0);
    $resArray[$prodType]['jamuriGadaxdaUpToToday'] += (float)($deal['jamuriGadaxdaUpToToday'] ?? 0);
    $resArray[$prodType]['mimdinareDavalianeba'] = $resArray[$prodType]['jamuriDaricxvaUpToToday'] - $resArray[$prodType]['jamuriGadaxdaUpToToday'];

    if ($prodType === 'ბინა') {
        if ($bedrooms === '1') {
            $subType = 'ბინა (1 საძ.)';
        } elseif ($bedrooms === '2') {
            $subType = 'ბინა (2 საძ.)';
        } elseif ($bedrooms === '3') {
            $subType = 'ბინა (3 საძ.)';
        } else {
            continue;
        }

        if (!isset($resArray[$subType])) {
            $resArray[$subType] = [
                'jamuriGayidvebisAmount' => 0,
                'jamuriDaricxvaUpToToday' => 0,
                'jamuriGadaxdaUpToToday' => 0,
                'mimdinareDavalianeba' => 0,
            ];
        }
        $resArray[$subType]['jamuriGayidvebisAmount'] += (float)($deal['OPPORTUNITY'] ?? 0);
        $resArray[$subType]['jamuriDaricxvaUpToToday'] += (float)($deal['jamuriDaricxvaUpToToday'] ?? 0);
        $resArray[$subType]['jamuriGadaxdaUpToToday'] += (float)($deal['jamuriGadaxdaUpToToday'] ?? 0);
        $resArray[$subType]['mimdinareDavalianeba'] = $resArray[$subType]['jamuriDaricxvaUpToToday'] - $resArray[$subType]['jamuriGadaxdaUpToToday'];
    }
}

$resArray = reportSortProductTypes($resArray);

$generalTotals = [
    'jamuriGayidvebisAmount' => 0,
    'jamuriDaricxvaUpToToday' => 0,
    'jamuriGadaxdaUpToToday' => 0,
    'mimdinareDavalianeba' => 0,
];
foreach ($resArray as $prodType => $data) {
    if (in_array($prodType, REPORT_APARTMENT_SUBTYPES, true)) {
        continue;
    }
    $generalTotals['jamuriGayidvebisAmount'] += $data['jamuriGayidvebisAmount'];
    $generalTotals['jamuriDaricxvaUpToToday'] += $data['jamuriDaricxvaUpToToday'];
    $generalTotals['jamuriGadaxdaUpToToday'] += $data['jamuriGadaxdaUpToToday'];
    $generalTotals['mimdinareDavalianeba'] += $data['mimdinareDavalianeba'];
}

$allDeals = reportGetDealsByFilter(['STAGE_ID' => REPORT_WON_STAGE], ['ID', D_PROJECT, D_BLOCK, 'ASSIGNED_BY_ID']);
$projects = $blocks = $responsibles = [];
foreach ($allDeals as $deal) {
    if (!empty($deal[D_PROJECT]) && !in_array($deal[D_PROJECT], $projects, true)) {
        $projects[] = $deal[D_PROJECT];
    }
    if (!empty($deal[D_BLOCK]) && $deal[D_BLOCK] !== 'P' && !in_array($deal[D_BLOCK], $blocks, true)) {
        $blocks[] = $deal[D_BLOCK];
    }
    if (!empty($deal['ASSIGNED_BY_ID'])) {
        $responsibles[$deal['ASSIGNED_BY_ID']] = reportGetUserName($deal['ASSIGNED_BY_ID']);
    }
}
sort($projects);
sort($blocks);
asort($responsibles);

ob_end_clean();
reportPageBegin(
    $lang === 'eng' ? 'Debt Report' : 'დავალიანების რეპორტი',
    $lang === 'eng' ? 'Scheduled vs paid amounts and current outstanding balance.' : 'დარიცხვები, გადახდები და მიმდინარე დავალიანება.',
    $lang
);
reportRenderFilterForm(
    [
        'project' => $filterProject,
        'block' => $filterBlock,
        'responsible' => $filterResponsible,
    ],
    [
        'projects' => $projects,
        'blocks' => $blocks,
        'responsibles' => $responsibles,
    ],
    $t,
    $lang,
    'deals'
);
?>

<?php reportBlockOpen($t['title_general']); ?>
    <thead>
        <tr>
            <th><?= $t['col_prod_type'] ?></th>
            <th class="amount"><?= $t['col_sold_amt'] ?></th>
            <th class="amount"><?= $t['col_scheduled'] ?></th>
            <th class="amount"><?= $t['col_paid'] ?></th>
            <th class="amount"><?= $t['col_debt'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($resArray)): ?>
            <tr><td colspan="5" class="report-empty"><?= $t['no_data'] ?></td></tr>
        <?php else: ?>
            <?php foreach ($resArray as $prodType => $data):
                $isSubRow = in_array($prodType, REPORT_APARTMENT_SUBTYPES, true);
            ?>
            <tr <?= $isSubRow ? 'class="sub-row"' : '' ?>>
                <td><?= reportSubTypeCell($prodType, $t, $isSubRow) ?></td>
                <td class="amount"><?= number_format($data['jamuriGayidvebisAmount'], 2) ?></td>
                <td class="amount"><?= number_format($data['jamuriDaricxvaUpToToday'], 2) ?></td>
                <td class="amount"><?= number_format($data['jamuriGadaxdaUpToToday'], 2) ?></td>
                <td class="amount"><?= number_format($data['mimdinareDavalianeba'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td><?= $t['col_total'] ?></td>
                <td class="amount"><?= number_format($generalTotals['jamuriGayidvebisAmount'], 2) ?></td>
                <td class="amount"><?= number_format($generalTotals['jamuriDaricxvaUpToToday'], 2) ?></td>
                <td class="amount"><?= number_format($generalTotals['jamuriGadaxdaUpToToday'], 2) ?></td>
                <td class="amount"><?= number_format($generalTotals['mimdinareDavalianeba'], 2) ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
<?php reportBlockClose(); ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const dealsData = <?= json_encode(array_values($deals)) ?>;
const t = <?= json_encode($t) ?>;
const prodTypeMap = <?= json_encode($t['prod_types']) ?>;
function translateType(name) { return prodTypeMap[name] || name; }

function exportToExcel() {
    const wb = XLSX.utils.book_new();
    const summaryRows = [[document.querySelector('.report-block__title').innerText.trim()]];
    const headerRow = [];
    document.querySelectorAll('.report-table thead tr th').forEach(function(th) { headerRow.push(th.innerText.trim()); });
    summaryRows.push(headerRow);
    document.querySelectorAll('.report-table tbody tr').forEach(function(tr) {
        const row = [];
        tr.querySelectorAll('td').forEach(function(td) {
            let val = td.innerText.trim();
            const num = parseFloat(val.replace(/,/g, ''));
            if (!isNaN(num) && val !== '') val = num;
            row.push(val);
        });
        summaryRows.push(row);
    });
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(summaryRows), 'Debt Summary');

    const fields = [
        { key: 'ID', label: t.xls_deal },
        { key: 'CONTACT_FULL_NAME', label: t.xls_client },
        { key: '<?= D_TYPE ?>', label: t.xls_type },
        { key: 'OPPORTUNITY', label: t.xls_total_price },
        { key: 'jamuriDaricxvaUpToToday', label: t.xls_scheduled },
        { key: 'jamuriGadaxdaUpToToday', label: t.xls_paid },
        { key: 'mimdinareDavalianeba', label: t.xls_debt },
    ];
    const rows = dealsData.map(function(p) {
        const row = {};
        fields.forEach(function(f) {
            row[f.label] = f.key === '<?= D_TYPE ?>' ? translateType(p[f.key] ?? '') : (p[f.key] ?? '');
        });
        return row;
    });
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(rows, { header: fields.map(f => f.label) }), 'Deal Details');
    XLSX.writeFile(wb, 'debt_report_' + new Date().toISOString().slice(0, 10) + '.xlsx');
}
</script>
<?php reportPageEnd($lang); ?>
