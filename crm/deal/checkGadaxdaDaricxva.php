<?php
/**
 * WON დილები: დარიცხვები vs გადახდები (CRM product rows + კატალოგი)
 * გაშვება: https://crm.monolith.ge/crm/deal/checkGadaxdaDaricxva.php
 */
ob_start();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once __DIR__ . '/reports/helpers.php';

CJSCore::Init(['jquery']);
$APPLICATION->SetTitle('');

define('D_FLOOR', 'UF_CRM_1779277828822');
define('D_UNIT_NO', 'UF_CRM_1779277613798');

function cgddFormatContractDate($value)
{
    $dt = reportParseDate($value);
    if ($dt instanceof DateTime) {
        return $dt->format('d.m.Y');
    }
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    return trim((string)$value);
}

function cgddSumByDeal(array $items, $amountKey)
{
    $sums = [];
    foreach ($items as $item) {
        $dealId = reportExtractDealId($item['DEAL_ID'] ?? '');
        if ($dealId === '') {
            continue;
        }
        $sums[$dealId] = ($sums[$dealId] ?? 0) + (float)($item[$amountKey] ?? 0);
    }
    return $sums;
}

$arFilter = ['STAGE_ID' => REPORT_WON_STAGE, 'CHECK_PERMISSIONS' => 'N'];
$deals = [];
$res = CCrmDeal::GetListEx(
    ['ID' => 'ASC'],
    $arFilter,
    false,
    false,
    [
        'ID',
        'OPPORTUNITY',
        'CONTACT_ID',
        'CONTACT_FULL_NAME',
        'COMPANY_ID',
        'COMPANY_TITLE',
        D_PROJECT,
        D_BLOCK,
        D_TYPE,
        D_FLOOR,
        D_UNIT_NO,
        D_CONTRACT_DATE,
    ]
);
while ($row = $res->Fetch()) {
    $deals[$row['ID']] = $row;
}

$dealIds = array_keys($deals);
$daricxvaByDeal = cgddSumByDeal(reportGetDaricxvebi($dealIds, false), 'daricxva_amount');
$gadaxdaByDeal = cgddSumByDeal(reportGetGadaxdebi($dealIds), 'gadaxda_amount');

$catalogProducts = reportGetProducts();
$dealProductIdsMap = reportGetDealProductIdsMap($dealIds);

// Fallback when CRM rows exist but catalog OWNER_DEAL was never synced.
$productsByOwnerDeal = [];
foreach ($catalogProducts as $product) {
    $ownerDealId = reportExtractProductOwnerDealId($product);
    if ($ownerDealId !== '' && isset($deals[$ownerDealId])) {
        $productsByOwnerDeal[$ownerDealId][(int)$product['ID']] = $product;
    }
}

$dataArr = [];
$dealSum = 0;
$daricxvaSum = 0;
$gadaxdaSum = 0;
$uniqueClients = [];

foreach ($deals as $deal) {
    $dealId = (string)$deal['ID'];
    $linkedProductIds = array_keys($dealProductIdsMap[$dealId] ?? []);
    if (empty($linkedProductIds) && !empty($productsByOwnerDeal[$dealId])) {
        $linkedProductIds = array_keys($productsByOwnerDeal[$dealId]);
    }

    $productLinks = [];
    $productOwnerDealIds = [];
    $productStatuses = [];
    $productProjects = [];
    $productBlocks = [];
    $productTypes = [];
    $productUnitNos = [];
    $productFloors = [];
    $primaryProductId = 0;
    foreach ($linkedProductIds as $linkedProductId) {
        $linkedProductId = (int)$linkedProductId;
        if ($linkedProductId <= 0) {
            continue;
        }
        if ($primaryProductId === 0) {
            $primaryProductId = $linkedProductId;
        }
        $productLinks[] = '<a target="_blank" href="/crm/catalog/' . REPORT_PRODUCT_IBLOCK . '/product/' . $linkedProductId . '/">' . $linkedProductId . '</a>';
        $catalogProduct = $catalogProducts[$linkedProductId] ?? ($productsByOwnerDeal[$dealId][$linkedProductId] ?? null);
        if (!$catalogProduct) {
            continue;
        }
        $ownerDealId = reportExtractProductOwnerDealId($catalogProduct);
        if ($ownerDealId !== '') {
            $productOwnerDealIds[] = $ownerDealId;
        }
        if (!empty($catalogProduct[F_STATUS])) {
            $productStatuses[] = $catalogProduct[F_STATUS];
        }
        if (!empty($catalogProduct[F_PROJECT])) {
            $productProjects[] = $catalogProduct[F_PROJECT];
        }
        if (!empty($catalogProduct[F_BLOCK])) {
            $productBlocks[] = $catalogProduct[F_BLOCK];
        }
        if (!empty($catalogProduct[F_TYPE])) {
            $productTypes[] = $catalogProduct[F_TYPE];
        }
        if (!empty($catalogProduct[F_UNIT_NO])) {
            $productUnitNos[] = $catalogProduct[F_UNIT_NO];
        }
        if (!empty($catalogProduct[F_FLOOR])) {
            $productFloors[] = $catalogProduct[F_FLOOR];
        }
    }

    $productId = $primaryProductId;

    $contactId = (int)($deal['CONTACT_ID'] ?? 0);
    $contactName = trim((string)($deal['CONTACT_FULL_NAME'] ?? ''));
    $companyId = (int)($deal['COMPANY_ID'] ?? 0);
    $companyName = trim((string)($deal['COMPANY_TITLE'] ?? ''));

    if ($contactName !== '') {
        $clientHtml = '<a target="_blank" href="/crm/contact/details/' . $contactId . '/">' . htmlspecialchars($contactName) . '</a>';
        $clientKey = $contactName;
    } elseif ($companyName !== '') {
        $clientHtml = '<a target="_blank" href="/crm/company/details/' . $companyId . '/">' . htmlspecialchars($companyName) . '</a>';
        $clientKey = $companyName;
    } else {
        $clientHtml = '';
        $clientKey = '';
    }

    if ($clientKey !== '') {
        $uniqueClients[$clientKey] = true;
    }

    $price = round((float)($deal['OPPORTUNITY'] ?? 0), 2);
    $daricxva = round($daricxvaByDeal[$dealId] ?? 0, 2);
    $gadaxda = round($gadaxdaByDeal[$dealId] ?? 0, 2);

    $productLink = !empty($productLinks) ? implode(', ', $productLinks) : '';
    $productStatus = !empty($productStatuses) ? implode(' / ', $productStatuses) : '';
    $uniqueOwnerDealIds = array_values(array_unique($productOwnerDealIds));
    $productOwnerDealLinks = [];
    foreach ($uniqueOwnerDealIds as $ownerDealId) {
        $productOwnerDealLinks[] = '<a target="_blank" href="/crm/deal/details/' . $ownerDealId . '/">' . $ownerDealId . '</a>';
    }

    $dataArr[] = [
        'ID' => (int)$deal['ID'],
        'client' => $clientHtml,
        'clientKey' => $clientKey,
        'productID' => $productLink,
        'productIdNum' => $productId,
        'prodOwnerDeal' => implode(', ', $productOwnerDealLinks),
        'prodOwnerDealNum' => (int)($uniqueOwnerDealIds[0] ?? 0),
        'prodProject' => implode(' / ', array_unique($productProjects)),
        'prodBlock' => implode(' / ', array_unique($productBlocks)),
        'prodFlatType' => implode(' / ', array_unique($productTypes)),
        'prodFlatNum' => implode(' / ', array_unique($productUnitNos)),
        'prodFlatFloor' => implode(' / ', array_unique($productFloors)),
        'productStatus' => $productStatus,
        'project' => $deal[D_PROJECT] ?? '',
        'block' => $deal[D_BLOCK] ?? '',
        'flatType' => $deal[D_TYPE] ?? '',
        'flatNum' => $deal[D_UNIT_NO] ?? '',
        'flatFloor' => $deal[D_FLOOR] ?? '',
        'xelshGafDate' => cgddFormatContractDate($deal[D_CONTRACT_DATE] ?? ''),
        'PRICE' => $price,
        'daricxva' => $daricxva,
        'gadaxda' => $gadaxda,
    ];

    $dealSum += $price;
    $daricxvaSum += $daricxva;
    $gadaxdaSum += $gadaxda;
}

$dealCount = count($dataArr);
$clientCount = count($uniqueClients);
$dealSum = round($dealSum, 2);
$daricxvaSum = round($daricxvaSum, 2);
$gadaxdaSum = round($gadaxdaSum, 2);

$clientDealCountMap = [];
foreach ($dataArr as $item) {
    $key = $item['clientKey'];
    if ($key !== '') {
        $clientDealCountMap[$key] = ($clientDealCountMap[$key] ?? 0) + 1;
    }
}
foreach ($dataArr as &$item) {
    $item['clientDealCount'] = $clientDealCountMap[$item['clientKey']] ?? 1;
}
unset($item);

ob_end_clean();

reportPageBegin('', 'WON დილები — ღირებულება, დარიცხვები და გადახდები.');
?>

<style>
    .cgdd-table-wrap {
        max-height: calc(100vh - 220px);
        overflow: auto;
    }

    #reportTable thead tr.total-row td {
        position: sticky;
        top: 0;
        z-index: 3;
        background: linear-gradient(180deg, #eef4f9, #e4edf5) !important;
    }

    #reportTable thead tr:not(.total-row) th {
        position: sticky;
        top: var(--cgdd-header-offset, 42px);
        z-index: 2;
    }

    #reportTable thead th.sortable {
        cursor: pointer;
        user-select: none;
    }
</style>

<section class="report-filter report-filter--inline">
    <div class="report-filter__grid report-filter__grid--cashflow">
        <div class="report-field">
            <label for="project">პროექტი</label>
            <select id="project">
                <option value="">TOTAL</option>
            </select>
        </div>
        <div class="report-field">
            <label for="block">ბლოკი</label>
            <select id="block">
                <option value="">TOTAL</option>
            </select>
        </div>
        <div class="report-field">
            <label for="flatType">ფართის ტიპი</label>
            <select id="flatType">
                <option value="">TOTAL</option>
            </select>
        </div>
    </div>
    <div class="report-filter__actions">
        <button type="button" class="btn btn-export" onclick="exportTableToExcel()">
            <span class="btn-icon">↓</span>Excel-ში ექსპორტი
        </button>
    </div>
</section>

<div class="table-wrap cgdd-table-wrap">
    <table class="report-table" id="reportTable">
        <thead>
            <tr class="total-row">
                <td id="dealCountHeader">რაოდენობა: <?= $dealCount ?></td>
                <td id="sxvaobaSumHeader">ჯამი: <?= number_format(round($dealSum - $daricxvaSum, 2), 2) ?></td>
                <td id="clientCountHeader">რაოდენობა: <?= $clientCount ?></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td id="priceSum">ჯამი: <?= number_format($dealSum, 2) ?></td>
                <td id="daricxvaSum">ჯამი: <?= number_format($daricxvaSum, 2) ?></td>
                <td id="gadaxdaSum">ჯამი: <?= number_format($gadaxdaSum, 2) ?></td>
            </tr>
            <tr>
                <th class="sortable" data-sort="id" data-label="ID">ID ↕</th>
                <th class="sortable" data-sort="sxvaoba" data-label="სხვაობა">სხვაობა ↕</th>
                <th class="sortable" data-sort="client" data-label="კლიენტი">კლიენტი ↕</th>
                <th class="sortable" data-sort="dealCount" data-label="დილების რაოდენობა">დილების რაოდენობა ↕</th>
                <th class="sortable" data-sort="productId" data-label="პროდუქტის ID">პროდუქტის ID ↕</th>
                <th class="sortable" data-sort="prodOwnerDeal" data-label="პროდუქტის დილი">პროდუქტის დილი ↕</th>
                <th class="sortable" data-sort="prodProject" data-label="პროდუქტის პროექტი">პროდუქტის პროექტი ↕</th>
                <th class="sortable" data-sort="prodBlock" data-label="პროდუქტის ბლოკი">პროდუქტის ბლოკი ↕</th>
                <th class="sortable" data-sort="prodFlatType" data-label="პროდუქტის ფართის ტიპი">პროდუქტის ფართის ტიპი ↕</th>
                <th class="sortable" data-sort="prodFlatNum" data-label="პროდუქტის ბინის ნომერი">პროდუქტის ბინის ნომერი ↕</th>
                <th class="sortable" data-sort="prodFlatFloor" data-label="პროდუქტის სართული">პროდუქტის სართული ↕</th>
                <th class="sortable" data-sort="productStatus" data-label="პროდუქტის სტატუსი">პროდუქტის სტატუსი ↕</th>
                <th class="sortable" data-sort="project" data-label="პროექტი">პროექტი ↕</th>
                <th class="sortable" data-sort="block" data-label="ბლოკი">ბლოკი ↕</th>
                <th class="sortable" data-sort="flatType" data-label="ფართის ტიპი">ფართის ტიპი ↕</th>
                <th class="sortable" data-sort="flatNum" data-label="ბინის ნომერი">ბინის ნომერი ↕</th>
                <th class="sortable" data-sort="flatFloor" data-label="სართული">სართული ↕</th>
                <th class="sortable" data-sort="xelshGafDate" data-label="ხელშეკრულების გაფორმების თარიღი">ხელშეკრულების გაფორმების თარიღი ↕</th>
                <th class="sortable" data-sort="price" data-label="ბინის ღირებულება">ბინის ღირებულება ↕</th>
                <th class="sortable" data-sort="daricxva" data-label="დარიცხვები">დარიცხვები ↕</th>
                <th class="sortable" data-sort="gadaxda" data-label="გადახდები">გადახდები ↕</th>
            </tr>
        </thead>
        <tbody id="tableBody"></tbody>
    </table>
</div>

<script src="//unpkg.com/xlsx/dist/shim.min.js"></script>
<script src="//unpkg.com/xlsx/dist/xlsx.full.min.js"></script>
<script>
const dataArr = <?= json_encode($dataArr, JSON_UNESCAPED_UNICODE) ?>;
const projectSelect = document.getElementById('project');
const blockSelect = document.getElementById('block');
const flatTypeSelect = document.getElementById('flatType');
const tableBody = document.getElementById('tableBody');

let sortColumn = null;
let sortAsc = true;
let currentFilteredData = dataArr.slice();

projectSelect.addEventListener('change', filterTable);
blockSelect.addEventListener('change', filterTable);
flatTypeSelect.addEventListener('change', filterTable);

document.querySelectorAll('#reportTable thead th.sortable').forEach(function (th) {
    th.addEventListener('click', function () {
        const key = th.dataset.sort;
        if (sortColumn === key) {
            sortAsc = !sortAsc;
        } else {
            sortColumn = key;
            sortAsc = true;
        }
        updateSortIndicators();
        drawTable(getSorted(currentFilteredData));
    });
});

function extractClientName(html) {
    return (html.match(/>([^<]+)</) || [])[1] || '';
}

function parseSortDate(value) {
    const raw = String(value || '').trim();
    if (!raw) {
        return 0;
    }
    const isoMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (isoMatch) {
        return new Date(isoMatch[1], isoMatch[2] - 1, isoMatch[3]).getTime();
    }
    const geMatch = raw.match(/^(\d{2})\.(\d{2})\.(\d{4})/);
    if (geMatch) {
        return new Date(geMatch[3], geMatch[2] - 1, geMatch[1]).getTime();
    }
    const slashMatch = raw.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
    if (slashMatch) {
        return new Date(slashMatch[3], slashMatch[2] - 1, slashMatch[1]).getTime();
    }
    const parsed = Date.parse(raw);
    return isNaN(parsed) ? 0 : parsed;
}

function getSortValue(item, key) {
    switch (key) {
        case 'id':
            return parseInt(item.ID, 10) || 0;
        case 'sxvaoba':
            return parseFloat(item.PRICE) - parseFloat(item.daricxva);
        case 'client':
            return extractClientName(item.client).toLowerCase();
        case 'dealCount':
            return parseInt(item.clientDealCount, 10) || 0;
        case 'productId':
            return parseInt(item.productIdNum, 10) || 0;
        case 'prodOwnerDeal':
            return parseInt(item.prodOwnerDealNum, 10) || 0;
        case 'prodProject':
            return String(item.prodProject || '').toLowerCase();
        case 'prodBlock':
            return String(item.prodBlock || '').toLowerCase();
        case 'prodFlatType':
            return String(item.prodFlatType || '').toLowerCase();
        case 'prodFlatNum':
            return parseFloat(String(item.prodFlatNum || '').replace(/[^\d.-]/g, '')) || 0;
        case 'prodFlatFloor':
            return parseFloat(String(item.prodFlatFloor || '').replace(/[^\d.-]/g, '')) || 0;
        case 'productStatus':
            return String(item.productStatus || '').toLowerCase();
        case 'project':
            return String(item.project || '').toLowerCase();
        case 'block':
            return String(item.block || '').toLowerCase();
        case 'flatType':
            return String(item.flatType || '').toLowerCase();
        case 'flatNum':
            return parseFloat(String(item.flatNum || '').replace(/[^\d.-]/g, '')) || 0;
        case 'flatFloor':
            return parseFloat(String(item.flatFloor || '').replace(/[^\d.-]/g, '')) || 0;
        case 'xelshGafDate':
            return parseSortDate(item.xelshGafDate);
        case 'price':
            return parseFloat(item.PRICE) || 0;
        case 'daricxva':
            return parseFloat(item.daricxva) || 0;
        case 'gadaxda':
            return parseFloat(item.gadaxda) || 0;
        default:
            return '';
    }
}

function compareSortValues(a, b, key) {
    const va = getSortValue(a, key);
    const vb = getSortValue(b, key);

    if (typeof va === 'number' && typeof vb === 'number') {
        return sortAsc ? va - vb : vb - va;
    }

    const cmp = String(va).localeCompare(String(vb), undefined, { numeric: true, sensitivity: 'base' });
    return sortAsc ? cmp : -cmp;
}

function getSorted(data) {
    if (!sortColumn) {
        return data.slice();
    }
    return data.slice().sort((a, b) => compareSortValues(a, b, sortColumn));
}

function updateSortIndicators() {
    document.querySelectorAll('#reportTable thead th.sortable').forEach(function (th) {
        const key = th.dataset.sort;
        const label = th.dataset.label || '';
        const indicator = sortColumn === key ? (sortAsc ? '↑' : '↓') : '↕';
        th.textContent = label + ' ' + indicator;
    });
}

function updateStickyHeaderOffset() {
    const totalRow = document.querySelector('#reportTable thead tr.total-row');
    if (totalRow) {
        document.documentElement.style.setProperty('--cgdd-header-offset', totalRow.offsetHeight + 'px');
    }
}

function populateFilters() {
    const projects = [...new Set(dataArr.map(item => item.project))].filter(Boolean).sort();
    const blocks = [...new Set(dataArr.map(item => item.block))].filter(Boolean).sort((a, b) =>
        a.toString().localeCompare(b.toString(), undefined, { numeric: true, sensitivity: 'base' })
    );
    const flatTypes = [...new Set(dataArr.map(item => item.flatType))].filter(Boolean).sort((a, b) =>
        a.toString().localeCompare(b.toString(), undefined, { numeric: true, sensitivity: 'base' })
    );

    projects.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p;
        projectSelect.appendChild(opt);
    });

    blocks.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b;
        opt.textContent = b;
        blockSelect.appendChild(opt);
    });

    flatTypes.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t;
        opt.textContent = t;
        flatTypeSelect.appendChild(opt);
    });
}

function filterTable() {
    const selectedProject = projectSelect.value;
    const selectedBlock = blockSelect.value;
    const selectedFlatType = flatTypeSelect.value;

    currentFilteredData = dataArr.filter(item => {
        const projectMatch = selectedProject === '' || item.project === selectedProject;
        const blockMatch = selectedBlock === '' || item.block === selectedBlock;
        const flatTypeMatch = selectedFlatType === '' || item.flatType === selectedFlatType;
        return projectMatch && blockMatch && flatTypeMatch;
    });

    drawTable(getSorted(currentFilteredData));
}

function getDotted(numb) {
    return parseFloat(numb).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function safe(value) {
    return (value === null || value === undefined) ? '' : value;
}

function drawTable(filteredData) {
    tableBody.innerHTML = '';

    let filteredDealSum = 0;
    let filteredDaricxvaSum = 0;
    let filteredSxvaoba = 0;
    let filteredGadaxdaSum = 0;
    const uniqueClients = {};

    filteredData.forEach(item => {
        const clientName = item.client.match(/>([^<]+)</);
        if (clientName && clientName[1]) {
            uniqueClients[clientName[1]] = true;
        }

        const price = parseFloat(item.PRICE) || 0;
        const daricxva = parseFloat(item.daricxva) || 0;
        const sxvaoba = parseFloat((price - daricxva).toFixed(2));

        filteredDealSum += price;
        filteredDaricxvaSum += daricxva;
        filteredSxvaoba += sxvaoba;
        filteredGadaxdaSum += parseFloat(item.gadaxda) || 0;

        const row = document.createElement('tr');
        row.innerHTML = `
            <td><a target="_blank" href="/crm/deal/details/${safe(item.ID)}/">${safe(item.ID)}</a></td>
            <td class="amount">${sxvaoba}</td>
            <td>${item.client}</td>
            <td class="amount">${safe(item.clientDealCount)}</td>
            <td>${item.productID || ''}</td>
            <td>${item.prodOwnerDeal || ''}</td>
            <td>${safe(item.prodProject)}</td>
            <td>${safe(item.prodBlock)}</td>
            <td>${safe(item.prodFlatType)}</td>
            <td>${safe(item.prodFlatNum)}</td>
            <td>${safe(item.prodFlatFloor)}</td>
            <td>${safe(item.productStatus)}</td>
            <td>${safe(item.project)}</td>
            <td>${safe(item.block)}</td>
            <td>${safe(item.flatType)}</td>
            <td>${safe(item.flatNum)}</td>
            <td>${safe(item.flatFloor)}</td>
            <td>${safe(item.xelshGafDate)}</td>
            <td class="amount">${safe(item.PRICE)}</td>
            <td class="amount">${safe(item.daricxva)}</td>
            <td class="amount">${safe(item.gadaxda)}</td>
        `;
        tableBody.appendChild(row);
    });

    document.getElementById('dealCountHeader').textContent = 'რაოდენობა: ' + filteredData.length;
    document.getElementById('clientCountHeader').textContent = 'რაოდენობა: ' + Object.keys(uniqueClients).length;
    document.getElementById('priceSum').textContent = 'ჯამი: ' + getDotted(filteredDealSum);
    document.getElementById('daricxvaSum').textContent = 'ჯამი: ' + getDotted(filteredDaricxvaSum);
    document.getElementById('sxvaobaSumHeader').textContent = 'ჯამი: ' + getDotted(filteredSxvaoba);
    document.getElementById('gadaxdaSum').textContent = 'ჯამი: ' + getDotted(filteredGadaxdaSum);
}

function exportTableToExcel() {
    const tableSelect = document.getElementById('reportTable');
    const wsData = [];

    for (let j = 0; j < tableSelect.rows[0].cells.length; j++) {
        wsData[0] = wsData[0] || [];
        wsData[0].push(tableSelect.rows[0].cells[j].innerText || '');
    }
    wsData[1] = [];
    for (let j = 0; j < tableSelect.rows[1].cells.length; j++) {
        wsData[1].push(tableSelect.rows[1].cells[j].innerText || '');
    }

    const tbody = tableSelect.getElementsByTagName('tbody')[0];
    for (let i = 0; i < tbody.rows.length; i++) {
        const row = [];
        for (let j = 0; j < tbody.rows[i].cells.length; j++) {
            const cellText = tbody.rows[i].cells[j].innerText || '';
            if (j === 1 || j === 3 || j >= 18) {
                const number = parseFloat(cellText.replace(/,/g, ''));
                row.push(isNaN(number) ? 0 : number);
            } else {
                row.push(cellText);
            }
        }
        wsData.push(row);
    }

    const wb = XLSX.utils.book_new();
    wb.Props = { Title: 'daricxva gadaxda', Subject: 'report', CreatedDate: new Date() };
    const ws = XLSX.utils.aoa_to_sheet(wsData);
    XLSX.utils.book_append_sheet(wb, ws, 'daricxva gadaxda');
    XLSX.writeFile(wb, 'daricxva_gadaxda.xlsx');
}

populateFilters();
drawTable(dataArr);
updateStickyHeaderOffset();
window.addEventListener('resize', updateStickyHeaderOffset);
</script>

<?php
reportPageEnd();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
