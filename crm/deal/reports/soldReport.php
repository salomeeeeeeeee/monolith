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
    'xls_sheet_all' => $lang === 'eng' ? 'All Sales' : 'ყველა გაყიდვა',
    'xls_no_resp' => $lang === 'eng' ? 'No Responsible' : 'პასუხისმგებლის გარეშე',
    'xls_loading' => $lang === 'eng' ? 'Loading...' : 'იტვირთება...',
    'xls_failed' => $lang === 'eng' ? 'Excel export failed, please try again.' : 'Excel-ის ჩამოტვირთვა ვერ მოხერხდა, სცადეთ თავიდან.',
]);

// Sales sheets of the Excel: the accountants' contract register first, then the report's own columns.
$exportColumns = [
    'BUX_ID' => $lang === 'eng' ? 'Accounting Code' : 'ბუღ. კოდი',
    F_PROJECT => $lang === 'eng' ? 'Project Name' : 'პროექტის დასახელება',
    'PROJECT_CODE' => $lang === 'eng' ? 'Project Code' : 'პროექტის კოდი',
    'CONTRACT_DATE' => $lang === 'eng' ? 'Contract Date' : 'ხელშ.თარიღი',
    'BUYER' => $lang === 'eng' ? 'Buyer' : 'მყიდველი',
    'BUYER_ID' => $lang === 'eng' ? 'Personal ID / Company Code' : 'პირადი ნომერი/ საიდენტ. კოდი',
    'BUYER_PHONE' => $lang === 'eng' ? 'Contact Phone' : 'საკონტაქტო ტელ. ნომერი',
    'CONTRACT_STATUS' => $lang === 'eng' ? 'Contract Status' : 'ხელშეკრულების სტატუსი',
    F_TYPE => $lang === 'eng' ? 'Property Type' : 'უძ. ქონების სახე',
    'CADASTRAL' => $lang === 'eng' ? 'Cadastral Code' : 'საკადასტრო კოდი',
    'PHASE' => $lang === 'eng' ? 'Phase' : 'ფაზა',
    F_SECTOR => $lang === 'eng' ? 'Sector' : 'სექტორი',
    F_BLOCK => $lang === 'eng' ? 'Block' : 'ბლოკი',
    F_FLOOR => $lang === 'eng' ? 'Floor' : 'სართული',
    F_UNIT_NO => $lang === 'eng' ? 'Property No.' : 'უძ. ქონების N',
    F_TOTAL_AREA => $lang === 'eng' ? 'Total Area' : 'საერთო ფართი',
    'INNER_AREA' => $lang === 'eng' ? 'Inner Area' : 'შიდა ფართი',
    'DEAL_KVM_PRICE' => $lang === 'eng' ? 'Price per sqm' : '1 კვ.მ ღირებულება',
    'DEAL_PRICE' => $lang === 'eng' ? 'Contract Value' : 'სახელშეკრულებო ღირებულება',
    'METER_FEE' => $lang === 'eng' ? 'Metering Fee' : 'გამრიცხველიანების თანხა',
    'FIRST_PAY_DATE' => $lang === 'eng' ? 'First Payment Date' : 'პირველადი შენატანის თარიღი',
    'FIRST_PAY_AMOUNT' => $lang === 'eng' ? 'First Payment Amount' : 'პირველადი შენატანის თანხა',
    'FIRST_PAY_PCT' => $lang === 'eng' ? 'First Payment %' : 'პირველადი შენატანის %',
    'PLAN_TO_MONTH' => $lang === 'eng' ? 'Planned (incl. current month)' : 'გეგმიური შემოსატანი (მიმდინარე თვის ჩათვლით)',
    'PAID_TO_MONTH' => $lang === 'eng' ? 'Paid (incl. current month)' : 'ფაქტიურად გადახდილი (მიმდინარე თვის ჩათვლით)',
    'DEBT' => $lang === 'eng' ? 'Current Debt (accumulated)' : 'მიმდინარე დავალიანება (აკუმულირებული)',
    'DEBT_PREV' => $lang === 'eng' ? 'Previous Period Debt' : 'წინა პერიოდის დავალიანება',
    'PAID_PCT' => $lang === 'eng' ? 'Total Paid (%)' : 'სულ ფაქტიურად შემოსული (%)',
    'REMAINING' => $lang === 'eng' ? 'Total Remaining' : 'სულ დარჩენილი დავალიანება',
    'METER_FEE_DUE' => $lang === 'eng' ? 'Metering Fee Due' : 'გადასახდელი გამრიცხველიანება',
    'LAST_PAY_DATE' => $lang === 'eng' ? 'Last Payment Date' : 'ბოლო შენატანის თარიღი',
    'LAST_PAY_AMOUNT' => $lang === 'eng' ? 'Last Payment Amount' : 'ბოლო შენატანის თანხა',
    'LAST_PAY_PCT' => $lang === 'eng' ? 'Last Payment %' : 'ბოლო შენატანის %',
    'INSTALLMENTS' => $lang === 'eng' ? 'Installments (first - last date)' : 'განვადება (პირველი - ბოლო თარიღი)',
    'NAME' => $lang === 'eng' ? 'Unit Name' : 'დასახელება',
    'BEDROOMS' => $lang === 'eng' ? 'Bedrooms' : 'საძინებლები',
    'KVM_PRICE' => $lang === 'eng' ? 'Stock Price per sqm ($)' : 'Stock ფასი კვ.მ-ზე ($)',
    'PRICE' => $lang === 'eng' ? 'Stock Price ($)' : 'Stock ფასი ($)',
    'PRICE_GEL' => $lang === 'eng' ? 'Stock Price (GEL)' : 'Stock ფასი (GEL)',
    'DEAL_RESPONSIBLE_NAME' => $lang === 'eng' ? 'Responsible' : 'პასუხისმგებელი',
    'BARTER' => $lang === 'eng' ? 'Barter' : 'ბარტერი',
];

$filters = [
    'project' => reportGetFilterValues('project'),
    'sector' => reportGetFilterValues('sector'),
    'block' => reportGetFilterValues('block'),
    'barter' => reportGetFilterValues('barter'),
    'responsible' => reportGetFilterValues('responsible'),
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

// Excel button: rows of the sales sheets, fetched on demand (the payment lists are slow to read).
if (($_GET['export'] ?? '') === 'rows') {
    $numeric = ['BUX_ID', F_FLOOR, F_UNIT_NO, F_TOTAL_AREA, 'INNER_AREA', 'BEDROOMS'];
    $rows = [];
    foreach (reportEnrichSoldExport($filteredProducts, $lang) as $product) {
        $product[F_PROJECT] = reportFilterLabel($product[F_PROJECT] ?? '');
        $product[F_TYPE] = reportTranslateProdType($product[F_TYPE] ?? '', $t);
        $product['BEDROOMS'] = ($product[D_BEDROOMS] ?? '') !== '' ? $product[D_BEDROOMS] : ($product[F_BEDROOMS] ?? '');
        $product['BARTER'] = ($product[D_BARTER] ?? '') === D_BARTER_YES ? $t['barter_yes'] : $t['barter_no'];
        $row = [];
        foreach (array_keys($exportColumns) as $key) {
            $value = $product[$key] ?? '';
            $row[] = in_array($key, $numeric, true) && is_numeric($value) ? (float)$value : $value;
        }
        $rows[] = $row;
    }
    reportSendJson($rows);
}

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
        return '<span class="diff diff--na">-</span>';
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
            <td>-</td>
            <td>-</td>
            <td>-</td>
        </tr>
    </tbody>
<?php reportBlockClose(); ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const t = <?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>;
const exportColumns = <?= json_encode(array_values($exportColumns), JSON_UNESCAPED_UNICODE) ?>;
const respColumn = <?= json_encode(array_search('DEAL_RESPONSIBLE_NAME', array_keys($exportColumns), true)) ?>;

async function exportToExcel() {
    const button = document.querySelector('.btn-export');
    const buttonHtml = button.innerHTML;
    button.disabled = true;
    button.innerText = t.xls_loading;
    try {
        const url = new URL(window.location.href);
        url.searchParams.set('export', 'rows');
        const response = await fetch(url.toString(), { credentials: 'same-origin' });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        writeWorkbook(await response.json());
    } catch (e) {
        console.error(e);
        alert(t.xls_failed);
    } finally {
        button.disabled = false;
        button.innerHTML = buttonHtml;
    }
}

/** Excel sheet names: at most 31 chars, no []:*?/\ and unique within the workbook. */
function sheetName(name, used) {
    const base = String(name).replace(/[\[\]:*?\/\\]/g, ' ').replace(/^'+|'+$/g, '').trim().slice(0, 31) || t.xls_no_resp;
    let result = base;
    for (let n = 2; used.has(result.toLowerCase()); n++) {
        const suffix = ' (' + n + ')';
        result = base.slice(0, 31 - suffix.length) + suffix;
    }
    used.add(result.toLowerCase());
    return result;
}

function salesSheet(rows) {
    const ws = XLSX.utils.aoa_to_sheet([exportColumns].concat(rows));
    ws['!cols'] = exportColumns.map(function(label, i) {
        let width = Math.min(label.length, 30);
        rows.forEach(function(row) { width = Math.max(width, String(row[i]).length); });
        return { wch: Math.min(width + 2, 45) };
    });
    ws['!autofilter'] = { ref: ws['!ref'] };
    return ws;
}

function writeWorkbook(rows) {
    const wb = XLSX.utils.book_new();
    const used = new Set();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(summaryRows()), sheetName('Sales Summary', used));
    XLSX.utils.book_append_sheet(wb, salesSheet(rows), sheetName(t.xls_sheet_all, used));

    // A sheet per responsible person.
    const byResp = {};
    rows.forEach(function(row) {
        const name = row[respColumn] || t.xls_no_resp;
        (byResp[name] = byResp[name] || []).push(row);
    });
    Object.keys(byResp).sort(function(a, b) { return a.localeCompare(b, 'ka'); }).forEach(function(name) {
        XLSX.utils.book_append_sheet(wb, salesSheet(byResp[name]), sheetName(name, used));
    });

    XLSX.writeFile(wb, 'sold_report_' + new Date().toISOString().slice(0, 10) + '.xlsx');
}

function summaryRows() {
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
    return summaryRows;
}
</script>
<?php reportPageEnd($lang); ?>
