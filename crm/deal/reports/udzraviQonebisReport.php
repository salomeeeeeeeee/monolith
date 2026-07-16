<?php
ob_start();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once __DIR__ . '/helpers.php';
CJSCore::Init(['jquery']);

$APPLICATION->SetTitle('Status Report');

$lang = $_GET['lang'] ?? 'ge';
$t = reportGetProductLabels($lang);
if ($lang === 'eng') {
    $t = array_merge($t, [
        'h2_count' => 'By Status - Count',
        'h2_area' => 'By Status - Sq. Meters',
        'h2_price' => 'By Status - Price ($)',
        'col_free' => 'For Sale',
        'col_reserved' => 'Reserved',
        'col_sold' => 'Sold',
    ]);
} else {
    $t = array_merge($t, [
        'h2_count' => 'სტატუსების მიხედვით - რაოდენობები',
        'h2_area' => 'სტატუსების მიხედვით - კვადრატულობები',
        'h2_price' => 'სტატუსების მიხედვით - თანხები',
        'col_free' => 'თავისუფალი',
        'col_reserved' => 'დაჯავშნილი',
        'col_sold' => 'გაყიდული',
    ]);
}

$filters = [
    'project' => $_GET['project'] ?? '',
    'sector' => $_GET['sector'] ?? '',
    'block' => $_GET['block'] ?? '',
    'responsible' => $_GET['responsible'] ?? '',
];

$products = reportGetAllInventoryProducts();
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
    $prodStatus = $product[F_STATUS] ?? '';

    reportAddProductAggregate($resArray, $prodType, $prodStatus, $product);

    $subType = reportResolveApartmentSubtype($product);
    if ($subType) {
        reportAddProductAggregate($resArray, $subType, $prodStatus, $product);
    }
}
$resArray = reportSortProductTypes($resArray);

ob_end_clean();
reportPageBegin(
    $lang === 'eng' ? 'Property Status Report' : 'უძრავი ქონების სტატუსი',
    $lang === 'eng' ? 'Inventory breakdown by type, status, area and price.' : 'ინვენტარის განაწილება ტიპის, სტატუსის, ფართისა და ფასის მიხედვით.',
    $lang
);
reportRenderFilterForm($filters, $filterOptions, $t, $lang);

$statuses = REPORT_STATUSES;
$status_totals_num = $status_totals_area = $status_totals_price = [];
foreach ($statuses as $status) {
    $status_totals_num[$status] = $status_totals_area[$status] = $status_totals_price[$status] = 0;
}
foreach ($resArray as $prodType => $infos) {
    if (in_array($prodType, REPORT_APARTMENT_SUBTYPES, true)) {
        continue;
    }
    foreach ($statuses as $status) {
        $status_totals_num[$status] += $infos[$status]['num'] ?? 0;
        $status_totals_area[$status] += $infos[$status]['total_area'] ?? 0;
        $status_totals_price[$status] += $infos[$status]['price'] ?? 0;
    }
}
$apt_status_totals_area = [];
foreach ($statuses as $status) {
    $apt_status_totals_area[$status] = 0;
    foreach (REPORT_APARTMENT_SUBTYPES as $aptType) {
        $apt_status_totals_area[$status] += $resArray[$aptType][$status]['total_area'] ?? 0;
    }
}
?>

<?php reportBlockOpen($t['h2_count']); ?>
    <thead>
        <tr>
            <th><?= $t['col_type'] ?></th>
            <th><?= $t['col_free'] ?></th>
            <th><?= $t['col_reserved'] ?></th>
            <th><?= $t['col_sold'] ?></th>
            <th>NFS</th>
            <th><?= $t['col_total'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($resArray as $prodType => $infos):
            $row_total = 0;
            foreach ($statuses as $status) {
                $row_total += $infos[$status]['num'] ?? 0;
            }
            $isSubRow = in_array($prodType, REPORT_APARTMENT_SUBTYPES, true);
        ?>
        <tr <?= $isSubRow ? 'class="sub-row"' : '' ?>>
            <td><?= reportSubTypeCell($prodType, $t, $isSubRow) ?></td>
            <?php foreach ($statuses as $status): ?>
                <td><?= $infos[$status]['num'] ?? 0 ?></td>
            <?php endforeach; ?>
            <td><?= $row_total ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td><?= $t['col_total'] ?></td>
            <?php foreach ($statuses as $status): ?>
                <td><?= $status_totals_num[$status] ?></td>
            <?php endforeach; ?>
            <td><?= array_sum($status_totals_num) ?></td>
        </tr>
    </tbody>
<?php reportBlockClose(); ?>

<?php reportBlockOpen($t['h2_area']); ?>
    <thead>
        <tr>
            <th><?= $t['col_type'] ?></th>
            <th><?= $t['col_free'] ?></th>
            <th><?= $t['col_reserved'] ?></th>
            <th><?= $t['col_sold'] ?></th>
            <th>NFS</th>
            <th><?= $t['col_total'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($resArray as $prodType => $infos):
            $row_total = 0;
            foreach ($statuses as $status) {
                $row_total += $infos[$status]['total_area'] ?? 0;
            }
            $isSubRow = in_array($prodType, REPORT_APARTMENT_SUBTYPES, true);
        ?>
        <tr <?= $isSubRow ? 'class="sub-row"' : '' ?>>
            <td><?= reportSubTypeCell($prodType, $t, $isSubRow) ?></td>
            <?php foreach ($statuses as $status): ?>
                <td><?= number_format($infos[$status]['total_area'] ?? 0, 2) ?></td>
            <?php endforeach; ?>
            <td><?= number_format($row_total, 2) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td><?= $t['col_total'] ?></td>
            <?php foreach ($statuses as $status): ?>
                <td><?= number_format($apt_status_totals_area[$status], 2) ?></td>
            <?php endforeach; ?>
            <td><?= number_format(array_sum($apt_status_totals_area), 2) ?></td>
        </tr>
    </tbody>
<?php reportBlockClose(); ?>

<?php reportBlockOpen($t['h2_price']); ?>
    <thead>
        <tr>
            <th><?= $t['col_type'] ?></th>
            <th><?= $t['col_free'] ?></th>
            <th><?= $t['col_reserved'] ?></th>
            <th><?= $t['col_sold'] ?></th>
            <th>NFS</th>
            <th><?= $t['col_total'] ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($resArray as $prodType => $infos):
            $row_total = 0;
            foreach ($statuses as $status) {
                $row_total += $infos[$status]['price'] ?? 0;
            }
            $isSubRow = in_array($prodType, REPORT_APARTMENT_SUBTYPES, true);
        ?>
        <tr <?= $isSubRow ? 'class="sub-row"' : '' ?>>
            <td><?= reportSubTypeCell($prodType, $t, $isSubRow) ?></td>
            <?php foreach ($statuses as $status): ?>
                <td><?= number_format($infos[$status]['price'] ?? 0, 2) ?></td>
            <?php endforeach; ?>
            <td><?= number_format($row_total, 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
<?php reportBlockClose(); ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const productsData = <?= json_encode(array_values($filteredProducts)) ?>;
const prodTypeMap = <?= json_encode($t['prod_types']) ?>;

function translateType(name) { return prodTypeMap[name] || name; }

function exportToExcel() {
    const wb = XLSX.utils.book_new();
    const summaryRows = [];
    document.querySelectorAll('.report-block__title').forEach(function(titleEl) {
        summaryRows.push([titleEl.innerText.trim()]);
        let table = titleEl.closest('.report-block').querySelector('table');
        if (!table) return;
        const headerRow = [];
        table.querySelectorAll('thead tr th').forEach(function(th) { headerRow.push(th.innerText.trim()); });
        summaryRows.push(headerRow);
        table.querySelectorAll('tbody tr').forEach(function(tr) {
            const row = [];
            tr.querySelectorAll('td').forEach(function(td) {
                let val = td.innerText.trim();
                const num = parseFloat(val.replace(/,/g, ''));
                if (!isNaN(num) && val !== '') val = num;
                row.push(val);
            });
            summaryRows.push(row);
        });
        summaryRows.push([]);
    });
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(summaryRows), 'Status Summary');

    const fields = [
        { key: '', label: '#' },
        { key: '<?= F_PROJECT ?>', label: 'Project' },
        { key: '<?= F_BLOCK ?>', label: 'Block' },
        { key: 'NAME', label: 'Unit Name' },
        { key: '<?= F_STATUS ?>', label: 'Status' },
        { key: '<?= F_TYPE ?>', label: 'Product Type' },
        { key: '<?= F_BEDROOMS ?>', label: 'Bedrooms' },
        { key: '<?= F_TOTAL_AREA ?>', label: 'Total Area (sqm)' },
        { key: 'PRICE', label: 'Price ($)' },
        { key: 'PRICE_GEL', label: 'Price (GEL)' },
        { key: 'DEAL_RESPONSIBLE_NAME', label: 'Responsible' },
        { key: 'OWNER_CONTACT_NAME', label: 'Owner' },
    ];
    let counter = 1;
    const rows = productsData.map(function(p) {
        const row = {};
        fields.forEach(function(f) {
            if (f.label === '#') row[f.label] = counter;
            else if (f.key === '<?= F_STATUS ?>' || f.key === '<?= F_TYPE ?>') row[f.label] = translateType(p[f.key] ?? '');
            else row[f.label] = p[f.key] ?? '';
        });
        counter++;
        return row;
    });
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(rows, { header: fields.map(f => f.label) }), 'Products');
    XLSX.writeFile(wb, 'product_report_' + new Date().toISOString().slice(0, 10) + '.xlsx');
}
</script>
<?php reportPageEnd($lang); ?>
