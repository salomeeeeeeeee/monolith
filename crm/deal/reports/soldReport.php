<?php
ob_start();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once __DIR__ . '/helpers.php';
CJSCore::Init(['jquery']);

$APPLICATION->SetTitle('Sold Report');

$lang = $_GET['lang'] ?? 'ge';
$t = reportGetProductLabels($lang);
$t = array_merge($t, [
    'h2_summary' => $lang === 'eng' ? 'Sales Summary' : 'გაყიდვების შეჯამება',
    'col_count' => $lang === 'eng' ? 'Count' : 'რაოდენობა',
    'col_area' => $lang === 'eng' ? 'Total Area (m²)' : 'ჯამური ფართი (m²)',
    'col_deal_price' => $lang === 'eng' ? 'Sale Total Price ($)' : 'გაყიდვის ჯამური ფასი ($)',
    'col_prod_price' => $lang === 'eng' ? 'Stock Price ($)' : 'Stock ფასი ($)',
    'col_diff_price' => $lang === 'eng' ? 'Diff % (Total)' : 'სხვაობა % (ჯამური)',
    'col_deal_avg' => $lang === 'eng' ? 'Sale Average Price ($)' : 'გაყიდვის საშუალო ფასი ($)',
    'col_prod_avg' => $lang === 'eng' ? 'Stock Average Price ($)' : 'Stock საშუალო ფასი ($)',
    'col_diff_avg' => $lang === 'eng' ? 'Diff % (Average)' : 'სხვაობა % (საშუალო)',
    'xls_deal_price' => $lang === 'eng' ? 'Sale Price ($)' : 'გაყიდვის ფასი ($)',
    'xls_deal_price_sqm' => $lang === 'eng' ? 'Sale Price per sqm ($)' : 'გაყიდვის ფასი კვ.მ-ზე ($)',
    'xls_num' => '#',
    'xls_project' => $lang === 'eng' ? 'Project' : 'პროექტი',
    'xls_block' => $lang === 'eng' ? 'Block' : 'ბლოკი',
    'xls_unit' => $lang === 'eng' ? 'Unit Name' : 'დასახელება',
    'xls_type' => $lang === 'eng' ? 'Product Type' : 'პროდუქტის ტიპი',
    'xls_bedrooms' => $lang === 'eng' ? 'Bedrooms' : 'საძინებლები',
    'xls_area' => $lang === 'eng' ? 'Total Area (sqm)' : 'სრული ფართი (კვ.მ)',
    'xls_price_sqm' => $lang === 'eng' ? 'Price per sqm ($)' : 'ფასი კვ.მ-ზე ($)',
    'xls_price' => $lang === 'eng' ? 'Price ($)' : 'ფასი ($)',
    'xls_price_gel' => $lang === 'eng' ? 'Price (GEL)' : 'ფასი (GEL)',
    'xls_resp' => $lang === 'eng' ? 'Responsible' : 'პასუხისმგებელი',
    'xls_owner' => $lang === 'eng' ? 'Owner' : 'მფლობელი',
]);

$filters = [
    'project' => $_GET['project'] ?? '',
    'sector' => $_GET['sector'] ?? '',
    'block' => $_GET['block'] ?? '',
    'barter' => $_GET['barter'] ?? '',
    'responsible' => $_GET['responsible'] ?? '',
];

$products = reportGetSoldProducts();
$products = reportEnrichDealBedrooms($products);
$filterOptions = [
    'projects' => reportGetUniqueValues($products, F_PROJECT),
    'sectors' => reportGetUniqueValues($products, F_SECTOR),
    'blocks' => array_values(array_diff(reportGetUniqueValues($products, F_BLOCK), ['P'])),
    'barters' => [
        D_BARTER_YES => $t['barter_yes'],
        D_BARTER_NO => $t['barter_no'],
    ],
    'responsibles' => reportGetUniqueValues($products, 'DEAL_RESPONSIBLE_NAME'),
];
$filteredProducts = reportFilterProducts($products, $filters);

/** Percentage difference of the sale value against the stock value (stock is the baseline). */
function soldDiffPercent($saleValue, $stockValue)
{
    $stockValue = (float)$stockValue;
    if (abs($stockValue) < 0.005) {
        return null;
    }
    return round((((float)$saleValue - $stockValue) / $stockValue) * 100, 2);
}

function soldDiffCell($percent)
{
    if ($percent === null) {
        return '<span class="diff diff--na">—</span>';
    }
    $class = 'diff--zero';
    if ($percent > 0.005) {
        $class = 'diff--pos';
    } elseif ($percent < -0.005) {
        $class = 'diff--neg';
    }
    $sign = $percent > 0.005 ? '+' : '';
    return '<span class="diff ' . $class . '">' . $sign . number_format($percent, 2) . '%</span>';
}

$emptyBucket = [
    'num' => 0,
    'total_area' => 0,
    'price' => 0,
    'KVM_PRICE' => 0,
    'deal_price' => 0,
    'deal_kvm_price' => 0,
];

$resArray = [];
foreach ($filteredProducts as $product) {
    $buckets = [reportResolveProductType($product)];
    $subType = reportResolveApartmentSubtype($product, true);
    if ($subType) {
        $buckets[] = $subType;
    }

    foreach ($buckets as $bucket) {
        if (!isset($resArray[$bucket])) {
            $resArray[$bucket] = $emptyBucket;
        }
        $resArray[$bucket]['num']++;
        $resArray[$bucket]['total_area'] += (float)($product[F_TOTAL_AREA] ?? 0);
        $resArray[$bucket]['price'] += (float)($product['PRICE'] ?? 0);
        $resArray[$bucket]['KVM_PRICE'] += (float)($product['KVM_PRICE'] ?? 0);
        $resArray[$bucket]['deal_price'] += (float)($product['DEAL_PRICE'] ?? 0);
        $resArray[$bucket]['deal_kvm_price'] += (float)($product['DEAL_KVM_PRICE'] ?? 0);
    }
}

foreach ($resArray as $prodType => &$infos) {
    if ($infos['total_area'] <= 0) {
        $infos['average_price'] = 0;
        $infos['deal_average_price'] = 0;
        continue;
    }
    // Average = total price / total area (same for sale and stock so Diff % stays comparable).
    $infos['average_price'] = round($infos['price'] / $infos['total_area'], 2);
    $infos['deal_average_price'] = round($infos['deal_price'] / $infos['total_area'], 2);
}
unset($infos);
$resArray = reportSortProductTypes($resArray);

$total_num = $total_area = $total_price = $total_deal_price = 0;
foreach ($resArray as $prodType => $infos) {
    if (in_array($prodType, REPORT_APARTMENT_SUBTYPES, true)) {
        continue;
    }
    $total_num += $infos['num'];
    $total_area += $infos['total_area'];
    $total_price += $infos['price'];
    $total_deal_price += $infos['deal_price'];
}

ob_end_clean();
reportPageBegin(
    $lang === 'eng' ? 'Sales Report' : 'გაყიდვების რეპორტი',
    $lang === 'eng' ? 'Sold units summary with average pricing by property type.' : 'გაყიდული ერთეულების შეჯამება საშუალო ფასებით.',
    $lang
);
reportRenderFilterForm($filters, $filterOptions, $t, $lang);
?>

<style>
    .report-table--compare { min-width: 1180px; }
    .diff { font-weight: 600; font-variant-numeric: tabular-nums; }
    .diff--pos { color: #1b7f5a; }
    .diff--neg { color: #c0392b; }
    .diff--zero { color: #6b7a8a; }
    .diff--na { color: #b0bac4; font-weight: 500; }
</style>

<?php reportBlockOpen($t['h2_summary'], 'report-table--compare'); ?>
    <thead>
        <tr>
            <th><?= $t['col_type'] ?></th>
            <th><?= $t['col_count'] ?></th>
            <th><?= $t['col_area'] ?></th>
            <th><?= $t['col_deal_price'] ?></th>
            <th><?= $t['col_prod_price'] ?></th>
            <th><?= $t['col_diff_price'] ?></th>
            <th><?= $t['col_deal_avg'] ?></th>
            <th><?= $t['col_prod_avg'] ?></th>
            <th><?= $t['col_diff_avg'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($resArray as $prodType => $infos):
            $isSubRow = in_array($prodType, REPORT_APARTMENT_SUBTYPES, true);
        ?>
        <tr <?= $isSubRow ? 'class="sub-row"' : '' ?>>
            <td><?= reportSubTypeCell($prodType, $t, $isSubRow) ?></td>
            <td><?= $infos['num'] ?></td>
            <td><?= number_format($infos['total_area'], 2) ?></td>
            <td>$<?= number_format($infos['deal_price'], 2) ?></td>
            <td>$<?= number_format($infos['price'], 2) ?></td>
            <td><?= soldDiffCell(soldDiffPercent($infos['deal_price'], $infos['price'])) ?></td>
            <td>$<?= number_format($infos['deal_average_price'], 2) ?></td>
            <td>$<?= number_format($infos['average_price'], 2) ?></td>
            <td><?= soldDiffCell(soldDiffPercent($infos['deal_average_price'], $infos['average_price'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td><?= $t['col_total'] ?></td>
            <td><?= $total_num ?></td>
            <td><?= number_format($total_area, 2) ?></td>
            <td>$<?= number_format($total_deal_price, 2) ?></td>
            <td>$<?= number_format($total_price, 2) ?></td>
            <td><?= soldDiffCell(soldDiffPercent($total_deal_price, $total_price)) ?></td>
            <td>—</td>
            <td>—</td>
            <td>—</td>
        </tr>
    </tbody>
<?php reportBlockClose(); ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const productsData = <?= json_encode(array_values($filteredProducts)) ?>;
const t = <?= json_encode($t) ?>;
const prodTypeMap = <?= json_encode($t['prod_types']) ?>;

function translateType(name) { return prodTypeMap[name] || name; }

function exportToExcel() {
    const wb = XLSX.utils.book_new();
    const summaryRows = [];
    document.querySelectorAll('.report-block__title').forEach(function(titleEl) {
        summaryRows.push([titleEl.innerText.trim()]);
        const table = titleEl.closest('.report-block').querySelector('table');
        if (!table) return;
        const headerRow = [];
        table.querySelectorAll('thead tr th').forEach(function(th) { headerRow.push(th.innerText.trim()); });
        summaryRows.push(headerRow);
        table.querySelectorAll('tbody tr').forEach(function(tr) {
            const row = [];
            tr.querySelectorAll('td').forEach(function(td) {
                let val = td.innerText.trim();
                if (val.startsWith('$')) {
                    const num = parseFloat(val.replace('$', '').replace(/,/g, ''));
                    val = isNaN(num) ? val : num;
                } else {
                    const num = parseFloat(val.replace(/,/g, ''));
                    if (!isNaN(num) && val !== '') val = num;
                }
                row.push(val);
            });
            summaryRows.push(row);
        });
        summaryRows.push([]);
    });
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(summaryRows), 'Sales Summary');

    const fields = [
        { key: '', label: t.xls_num },
        { key: '<?= F_PROJECT ?>', label: t.xls_project },
        { key: '<?= F_BLOCK ?>', label: t.xls_block },
        { key: 'NAME', label: t.xls_unit },
        { key: '<?= F_TYPE ?>', label: t.xls_type },
        { key: '<?= D_BEDROOMS ?>', label: t.xls_bedrooms },
        { key: '<?= F_TOTAL_AREA ?>', label: t.xls_area },
        { key: 'KVM_PRICE', label: t.xls_price_sqm },
        { key: 'PRICE', label: t.xls_price },
        { key: 'PRICE_GEL', label: t.xls_price_gel },
        { key: 'DEAL_KVM_PRICE', label: t.xls_deal_price_sqm },
        { key: 'DEAL_PRICE', label: t.xls_deal_price },
        { key: 'DEAL_RESPONSIBLE_NAME', label: t.xls_resp },
        { key: 'OWNER_CONTACT_NAME', label: t.xls_owner },
    ];
    let counter = 1;
    const rows = productsData.map(function(p) {
        const row = {};
        fields.forEach(function(f) {
            if (f.label === t.xls_num) row[f.label] = counter;
            else if (f.key === '<?= F_TYPE ?>') row[f.label] = translateType(p[f.key] ?? '');
            else if (f.key === '<?= D_BEDROOMS ?>') row[f.label] = p['<?= D_BEDROOMS ?>'] || p['<?= F_BEDROOMS ?>'] || '';
            else row[f.label] = p[f.key] ?? '';
        });
        counter++;
        return row;
    });
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(rows, { header: fields.map(f => f.label) }), 'Products');
    XLSX.writeFile(wb, 'sold_report_' + new Date().toISOString().slice(0, 10) + '.xlsx');
}
</script>
<?php reportPageEnd($lang); ?>
