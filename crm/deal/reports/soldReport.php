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
    'h2_avg' => $lang === 'eng' ? 'Average Price' : 'საშუალო ფასი',
    'col_count' => $lang === 'eng' ? 'Count' : 'რაოდენობა',
    'col_area' => $lang === 'eng' ? 'Total Area (m²)' : 'ჯამური ფართი (m²)',
    'col_price' => $lang === 'eng' ? 'Total Price ($)' : 'ჯამური ფასი ($)',
    'col_avg_price' => $lang === 'eng' ? 'Average Price ($)' : 'საშუალო ფასი ($)',
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
    'responsible' => $_GET['responsible'] ?? '',
];

$products = reportGetSoldProducts();
$filterOptions = [
    'projects' => reportGetUniqueValues($products, F_PROJECT),
    'sectors' => reportGetUniqueValues($products, F_SECTOR),
    'blocks' => array_values(array_diff(reportGetUniqueValues($products, F_BLOCK), ['P'])),
    'responsibles' => reportGetUniqueValues($products, 'DEAL_RESPONSIBLE_NAME'),
];
$filteredProducts = reportFilterProducts($products, $filters);

$resArray = [];
foreach ($filteredProducts as $product) {
    $prodType = reportResolveProductType($product);
    if (!isset($resArray[$prodType])) {
        $resArray[$prodType] = ['num' => 0, 'total_area' => 0, 'price' => 0, 'KVM_PRICE' => 0];
    }
    $resArray[$prodType]['num']++;
    $resArray[$prodType]['total_area'] += (float)($product[F_TOTAL_AREA] ?? 0);
    $resArray[$prodType]['price'] += (float)($product['PRICE'] ?? 0);
    $resArray[$prodType]['KVM_PRICE'] += (float)($product['KVM_PRICE'] ?? 0);

    $subType = reportResolveApartmentSubtype($product);
    if ($subType) {
        if (!isset($resArray[$subType])) {
            $resArray[$subType] = ['num' => 0, 'total_area' => 0, 'price' => 0, 'KVM_PRICE' => 0];
        }
        $resArray[$subType]['num']++;
        $resArray[$subType]['total_area'] += (float)($product[F_TOTAL_AREA] ?? 0);
        $resArray[$subType]['price'] += (float)($product['PRICE'] ?? 0);
        $resArray[$subType]['KVM_PRICE'] += (float)($product['KVM_PRICE'] ?? 0);
    }
}

foreach ($resArray as $prodType => &$infos) {
    if ($infos['num'] <= 0) {
        $infos['average_price'] = 0;
        continue;
    }
    if (str_contains($prodType, 'ბინა') || $prodType === 'კომერციული') {
        $infos['average_price'] = round($infos['KVM_PRICE'] / $infos['num'], 2);
    } else {
        $infos['average_price'] = round($infos['price'] / $infos['num'], 2);
    }
}
unset($infos);
$resArray = reportSortProductTypes($resArray);

$total_num = $total_area = $total_price = 0;
foreach ($resArray as $prodType => $infos) {
    if (in_array($prodType, REPORT_APARTMENT_SUBTYPES, true)) {
        continue;
    }
    $total_num += $infos['num'];
    $total_area += $infos['total_area'];
    $total_price += $infos['price'];
}

ob_end_clean();
reportPageBegin(
    $lang === 'eng' ? 'Sales Report' : 'გაყიდვების რეპორტი',
    $lang === 'eng' ? 'Sold units summary with average pricing by property type.' : 'გაყიდული ერთეულების შეჯამება საშუალო ფასებით.',
    $lang
);
reportRenderFilterForm($filters, $filterOptions, $t, $lang);
?>

<?php reportBlockOpen($t['h2_summary']); ?>
    <thead>
        <tr>
            <th><?= $t['col_type'] ?></th>
            <th><?= $t['col_count'] ?></th>
            <th><?= $t['col_area'] ?></th>
            <th><?= $t['col_price'] ?></th>
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
            <td>$<?= number_format($infos['price'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td><?= $t['col_total'] ?></td>
            <td><?= $total_num ?></td>
            <td><?= number_format($total_area, 2) ?></td>
            <td>$<?= number_format($total_price, 2) ?></td>
        </tr>
    </tbody>
<?php reportBlockClose(); ?>

<?php reportBlockOpen($t['h2_avg']); ?>
    <thead>
        <tr>
            <th><?= $t['col_type'] ?></th>
            <th><?= $t['col_avg_price'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($resArray as $prodType => $infos):
            $isSubRow = in_array($prodType, REPORT_APARTMENT_SUBTYPES, true);
        ?>
        <tr <?= $isSubRow ? 'class="sub-row"' : '' ?>>
            <td><?= reportSubTypeCell($prodType, $t, $isSubRow) ?></td>
            <td>$<?= number_format($infos['average_price'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
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
        { key: '<?= F_BEDROOMS ?>', label: t.xls_bedrooms },
        { key: '<?= F_TOTAL_AREA ?>', label: t.xls_area },
        { key: 'KVM_PRICE', label: t.xls_price_sqm },
        { key: 'PRICE', label: t.xls_price },
        { key: 'PRICE_GEL', label: t.xls_price_gel },
        { key: 'DEAL_RESPONSIBLE_NAME', label: t.xls_resp },
        { key: 'OWNER_CONTACT_NAME', label: t.xls_owner },
    ];
    let counter = 1;
    const rows = productsData.map(function(p) {
        const row = {};
        fields.forEach(function(f) {
            if (f.label === t.xls_num) row[f.label] = counter;
            else if (f.key === '<?= F_TYPE ?>') row[f.label] = translateType(p[f.key] ?? '');
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
