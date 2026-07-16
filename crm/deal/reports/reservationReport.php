<?php
ob_start();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once __DIR__ . '/helpers.php';
CJSCore::Init(['jquery']);

$APPLICATION->SetTitle('Reservation Report');

$lang = $_GET['lang'] ?? 'ge';
$t = reportGetProductLabels($lang);
$stageLabels = [
    'ge' => [
        'PREPAYMENT_INVOICE' => 'მოთხოვნილი რეზერვაცია',
        'FINAL_INVOICE' => 'დადასტურებული რეზერვაცია',
    ],
    'eng' => [
        'PREPAYMENT_INVOICE' => 'Requested reservation',
        'FINAL_INVOICE' => 'Confirmed reservation',
    ],
];
$t = array_merge($t, [
    'h2_summary' => $lang === 'eng' ? 'Reservation Summary' : 'რეზერვაციების შეჯამება',
    'col_count' => $lang === 'eng' ? 'Quantity' : 'რაოდენობა',
    'col_area' => $lang === 'eng' ? 'Total Area (m²)' : 'ჯამური ფართი (m²)',
    'col_price' => $lang === 'eng' ? 'Amount ($)' : 'თანხა ($)',
    'no_data' => $lang === 'eng' ? 'No reserved deals found.' : 'დაჯავშნილი დილები არ მოიძებნა.',
    'xls_deal' => $lang === 'eng' ? 'Deal#' : 'გარიგება#',
    'xls_client' => $lang === 'eng' ? 'Client Name' : 'კლიენტის სახელი',
    'xls_project' => $lang === 'eng' ? 'Project' : 'პროექტი',
    'xls_block' => $lang === 'eng' ? 'Block' : 'ბლოკი',
    'xls_unit' => $lang === 'eng' ? 'Unit Name' : 'დასახელება',
    'xls_unit_no' => $lang === 'eng' ? 'Apartment №' : 'ბინის №',
    'xls_type' => $lang === 'eng' ? 'Property Type' : 'ქონების ტიპი',
    'xls_bedrooms' => $lang === 'eng' ? 'Bedrooms' : 'საძინებლები',
    'xls_area' => $lang === 'eng' ? 'Total Area (sqm)' : 'სრული ფართი (კვ.მ)',
    'xls_price_sqm' => $lang === 'eng' ? 'Price per sqm ($)' : 'ფასი კვ.მ-ზე ($)',
    'xls_price' => $lang === 'eng' ? 'Total Price ($)' : 'ჯამური ღირებულება ($)',
    'xls_resp' => $lang === 'eng' ? 'Responsible' : 'პასუხისმგებელი',
    'xls_reserved_until' => $lang === 'eng' ? 'Reserved Until' : 'დაჯავშნილია თარიღამდე',
    'xls_stage' => $lang === 'eng' ? 'Reservation Type' : 'რეზერვაციის ტიპი',
]);

$filterProject = trim($_GET['project'] ?? '');
$filterBlock = trim($_GET['block'] ?? '');
$filterResponsible = trim($_GET['responsible'] ?? '');

$arFilter = ['STAGE_ID' => REPORT_RESERVATION_STAGES];
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
    'ID',
    'TITLE',
    'STAGE_ID',
    'CONTACT_ID',
    'CONTACT_FULL_NAME',
    'OPPORTUNITY',
    'ASSIGNED_BY_ID',
    D_PROJECT,
    D_BLOCK,
    D_TYPE,
    D_RESERVATION_DATE,
]);

$productsByDeal = [];
foreach (reportGetProducts() as $row) {
    $ownerDealId = reportExtractDealId($row['OWNER_DEAL'] ?? '');
    if ($ownerDealId !== '' && isset($deals[$ownerDealId])) {
        $productsByDeal[$ownerDealId] = $row;
    }
}

foreach ($deals as &$deal) {
    $dealId = reportExtractDealId($deal['ID'] ?? '');
    $product = $productsByDeal[$dealId] ?? null;

    $deal['RESPONSIBLE_NAME'] = reportGetUserName($deal['ASSIGNED_BY_ID'] ?? '');
    $deal['AMOUNT'] = reportParseAmount($deal['OPPORTUNITY'] ?? 0);
    $deal['TOTAL_AREA'] = $product ? (float)($product[F_TOTAL_AREA] ?? 0) : 0;
    $deal['UNIT_NAME'] = $product['NAME'] ?? '';
    $deal['UNIT_NO'] = $product[F_UNIT_NO] ?? '';
    $deal['BEDROOMS'] = $product[F_BEDROOMS] ?? '';
    $deal['KVM_PRICE'] = $product['KVM_PRICE'] ?? ($product[F_KVM_PRICE] ?? '');
    $deal['STAGE_LABEL'] = ($stageLabels[$lang][$deal['STAGE_ID'] ?? ''] ?? ($deal['STAGE_ID'] ?? ''));

    if (empty($deal['CONTACT_FULL_NAME']) && !empty($deal['CONTACT_ID'])) {
        $deal['CONTACT_FULL_NAME'] = reportGetContactName($deal['CONTACT_ID']);
    }
}
unset($deal);

$resArray = [];
foreach ($deals as $deal) {
    $prodType = $deal[D_TYPE] ?? '';
    if (($deal[D_BLOCK] ?? '') === 'P') {
        $prodType = 'გარე ავტოსადგომი';
    } elseif ($prodType === 'ავტოსადგომი') {
        $prodType = 'შიდა ავტოსადგომი';
    }

    if ($prodType === '') {
        $prodType = 'სხვა';
    }

    if (!isset($resArray[$prodType])) {
        $resArray[$prodType] = ['num' => 0, 'total_area' => 0, 'price' => 0];
    }
    $resArray[$prodType]['num']++;
    $resArray[$prodType]['total_area'] += (float)$deal['TOTAL_AREA'];
    $resArray[$prodType]['price'] += (float)$deal['AMOUNT'];

    $bedrooms = (string)($deal['BEDROOMS'] ?? '');
    $subType = null;
    if ($prodType === 'ბინა') {
        if ($bedrooms === '1') {
            $subType = 'ბინა (1 საძ.)';
        } elseif ($bedrooms === '2') {
            $subType = 'ბინა (2 საძ.)';
        } elseif ($bedrooms === '3') {
            $subType = 'ბინა (3 საძ.)';
        }
    }
    if ($subType) {
        if (!isset($resArray[$subType])) {
            $resArray[$subType] = ['num' => 0, 'total_area' => 0, 'price' => 0];
        }
        $resArray[$subType]['num']++;
        $resArray[$subType]['total_area'] += (float)$deal['TOTAL_AREA'];
        $resArray[$subType]['price'] += (float)$deal['AMOUNT'];
    }
}
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

$allDeals = reportGetDealsByFilter(['STAGE_ID' => REPORT_RESERVATION_STAGES], ['ID', D_PROJECT, D_BLOCK, 'ASSIGNED_BY_ID']);
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
    $lang === 'eng' ? 'Reservation Report' : 'რეზერვაციის რეპორტი',
    $lang === 'eng'
        ? 'Reserved deals summary by property type (requested + confirmed reservation stages).'
        : 'დაჯავშნილი დილების შეჯამება ქონების ტიპების მიხედვით (მოთხოვნილი + დადასტურებული რეზერვაცია).',
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
        <?php if (empty($resArray)): ?>
            <tr>
                <td class="report-empty" colspan="4"><?= $t['no_data'] ?></td>
            </tr>
        <?php else: ?>
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
        <?php endif; ?>
    </tbody>
<?php reportBlockClose(); ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const dealsData = <?= json_encode(array_values($deals), JSON_UNESCAPED_UNICODE) ?>;
const t = <?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>;
const prodTypeMap = <?= json_encode($t['prod_types'], JSON_UNESCAPED_UNICODE) ?>;
const typeField = <?= json_encode(D_TYPE) ?>;
const projectField = <?= json_encode(D_PROJECT) ?>;
const blockField = <?= json_encode(D_BLOCK) ?>;
const dateField = <?= json_encode(D_RESERVATION_DATE) ?>;

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
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(summaryRows), 'Reservation Summary');

    const fields = [
        { key: 'ID', label: t.xls_deal },
        { key: 'CONTACT_FULL_NAME', label: t.xls_client },
        { key: projectField, label: t.xls_project },
        { key: typeField, label: t.xls_type },
        { key: 'BEDROOMS', label: t.xls_bedrooms },
        { key: blockField, label: t.xls_block },
        { key: 'UNIT_NAME', label: t.xls_unit },
        { key: 'UNIT_NO', label: t.xls_unit_no },
        { key: 'TOTAL_AREA', label: t.xls_area },
        { key: 'KVM_PRICE', label: t.xls_price_sqm },
        { key: 'AMOUNT', label: t.xls_price },
        { key: 'RESPONSIBLE_NAME', label: t.xls_resp },
        { key: dateField, label: t.xls_reserved_until },
        { key: 'STAGE_LABEL', label: t.xls_stage },
    ];
    const rows = dealsData.map(function(d) {
        const row = {};
        fields.forEach(function(f) {
            if (f.key === typeField) row[f.label] = translateType(d[f.key] ?? '');
            else row[f.label] = d[f.key] ?? '';
        });
        return row;
    });
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(rows, { header: fields.map(f => f.label) }), 'Deals');
    const fileName = (<?= json_encode($lang) ?> === 'eng' ? 'reservation_report_' : 'rezervaciis_report_')
        + new Date().toISOString().slice(0, 10) + '.xlsx';
    XLSX.writeFile(wb, fileName);
}
</script>
<?php reportPageEnd($lang); ?>
