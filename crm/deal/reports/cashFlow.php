<?php
ob_start();
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
require_once __DIR__ . '/helpers.php';

$fromDate = !empty($_GET['from_date']) ? $_GET['from_date'] : null;
$toDate = !empty($_GET['to_date']) ? $_GET['to_date'] : null;
$project = $_GET['project'] ?? '';
$period = $_GET['period'] ?? 'year';

if (!empty($fromDate) && !empty($toDate)) {
    $from = DateTime::createFromFormat('Y-m-d', $fromDate);
    $to = DateTime::createFromFormat('Y-m-d', $toDate);
    if ($from && $to) {
        switch ($period) {
            case 'month':
                $from->modify('first day of this month')->setTime(0, 0, 0);
                $to->modify('last day of this month')->setTime(23, 59, 59);
                break;
            case 'year':
                $from->setDate((int)$from->format('Y'), 1, 1)->setTime(0, 0, 0);
                $to->setDate((int)$to->format('Y'), 12, 31)->setTime(23, 59, 59);
                break;
        }
        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');
    }
}

$arFilter = ['STAGE_ID' => REPORT_CASHFLOW_STAGES];
if (!empty($project)) {
    $arFilter[D_PROJECT] = $project;
}

$deals = reportGetDealsByFilter($arFilter, [
    'ID', 'TITLE', 'CONTACT_FULL_NAME', 'OPPORTUNITY', D_PROJECT, D_CONTRACT_DATE,
]);
$dealsIds = array_keys($deals);

$filterDealIds = [];
foreach ($dealsIds as $id) {
    $filterDealIds[] = $id;
    $filterDealIds[] = $id . ' ';
    $filterDealIds[] = ' ' . $id;
}

list($daricxvebi, $gadaxdebi) = reportGetDaricxvebiDaGadaxdebi($fromDate, $toDate, $filterDealIds);
$gadaxdebi = reportFilterPaymentsByDates($fromDate, $toDate, $gadaxdebi);

$dealsForExcel = [];
foreach ($daricxvebi as $item) {
    $dealId = $item['DEAL_ID'];
    if (isset($deals[$dealId])) {
        $dealsForExcel[$dealId] = $deals[$dealId];
        $dealsForExcel[$dealId]['payment'] = $dealsForExcel[$dealId]['payment'] ?? 0;
    }
}
foreach ($gadaxdebi as $item) {
    $dealId = $item['DEAL_ID'];
    if (isset($deals[$dealId])) {
        $dealsForExcel[$dealId] = $deals[$dealId];
        $dealsForExcel[$dealId]['payment'] = ($dealsForExcel[$dealId]['payment'] ?? 0) + $item['AMOUNT'];
    }
}

$grouped_daricxvebi = [];
$grouped_gadaxdebi = [];

foreach ($dealsForExcel as &$dealRow) {
    $dealRow['payment'] = $dealRow['payment'] ?? 0;
    $dealRow['gadaxdebi_and_daricxvebi_by_dates'] = $dealRow['gadaxdebi_and_daricxvebi_by_dates'] ?? [];
}
unset($dealRow);

switch ($period) {
    case 'day':
        foreach ($daricxvebi as $item) {
            $date = $item['DATE'];
            $amount = $item['AMOUNT'];
            $grouped_daricxvebi[$date] = ($grouped_daricxvebi[$date] ?? 0) + $amount;
            $dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$date]['daricxva'] =
                ($dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$date]['daricxva'] ?? 0) + $amount;
        }
        foreach ($gadaxdebi as $item) {
            $date = $item['DATE'];
            $amount = $item['AMOUNT'];
            $grouped_gadaxdebi[$date] = ($grouped_gadaxdebi[$date] ?? 0) + $amount;
            $dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$date]['gadaxda'] =
                ($dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$date]['gadaxda'] ?? 0) + $amount;
        }
        uksort($grouped_daricxvebi, fn($a, $b) => strtotime($a) <=> strtotime($b));
        uksort($grouped_gadaxdebi, fn($a, $b) => strtotime($a) <=> strtotime($b));
        break;

    case 'month':
        foreach ($daricxvebi as $item) {
            if (empty($item['DATE'])) {
                continue;
            }
            $dateObj = DateTime::createFromFormat('d/m/Y', $item['DATE']);
            if (!$dateObj) {
                continue;
            }
            $monthKey = $dateObj->format('Y-m');
            $amount = (float)$item['AMOUNT'];
            $grouped_daricxvebi[$monthKey] = ($grouped_daricxvebi[$monthKey] ?? 0) + $amount;
            $dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$monthKey]['daricxva'] =
                ($dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$monthKey]['daricxva'] ?? 0) + $amount;
        }
        foreach ($gadaxdebi as $item) {
            if (empty($item['DATE'])) {
                continue;
            }
            $dateObj = DateTime::createFromFormat('d/m/Y', $item['DATE']);
            if (!$dateObj) {
                continue;
            }
            $monthKey = $dateObj->format('Y-m');
            $amount = (float)$item['AMOUNT'];
            $grouped_gadaxdebi[$monthKey] = ($grouped_gadaxdebi[$monthKey] ?? 0) + $amount;
            $dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$monthKey]['gadaxda'] =
                ($dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$monthKey]['gadaxda'] ?? 0) + $amount;
        }
        uksort($grouped_daricxvebi, fn($a, $b) => strtotime($a . '-01') <=> strtotime($b . '-01'));
        uksort($grouped_gadaxdebi, fn($a, $b) => strtotime($a . '-01') <=> strtotime($b . '-01'));
        break;

    case 'year':
        foreach ($daricxvebi as $item) {
            if (empty($item['DATE'])) {
                continue;
            }
            $dateObj = DateTime::createFromFormat('d/m/Y', $item['DATE']);
            $yearKey = $dateObj ? $dateObj->format('Y') : '';
            $amount = (float)$item['AMOUNT'];
            $grouped_daricxvebi[$yearKey] = ($grouped_daricxvebi[$yearKey] ?? 0) + $amount;
            $dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$yearKey]['daricxva'] =
                ($dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$yearKey]['daricxva'] ?? 0) + $amount;
        }
        foreach ($gadaxdebi as $item) {
            if (empty($item['DATE'])) {
                continue;
            }
            $dateObj = DateTime::createFromFormat('d/m/Y', $item['DATE']);
            $yearKey = $dateObj ? $dateObj->format('Y') : '';
            $amount = (float)$item['AMOUNT'];
            $grouped_gadaxdebi[$yearKey] = ($grouped_gadaxdebi[$yearKey] ?? 0) + $amount;
            $dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$yearKey]['gadaxda'] =
                ($dealsForExcel[$item['DEAL_ID']]['gadaxdebi_and_daricxvebi_by_dates'][$yearKey]['gadaxda'] ?? 0) + $amount;
        }
        uksort($grouped_daricxvebi, fn($a, $b) => (int)$a <=> (int)$b);
        uksort($grouped_gadaxdebi, fn($a, $b) => (int)$a <=> (int)$b);
        break;
}

$allDealsForProjects = reportGetDealsByFilter(['STAGE_ID' => REPORT_CASHFLOW_STAGES], ['ID', D_PROJECT]);
$projects = [];
foreach ($allDealsForProjects as $deal) {
    if (!empty($deal[D_PROJECT]) && !in_array($deal[D_PROJECT], $projects, true)) {
        $projects[] = $deal[D_PROJECT];
    }
}
sort($projects);

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

ob_end_clean();
reportPageBegin('Cashflow Report', 'დარიცხვებისა და გადახდების დინამიკა არჩეულ პერიოდში.', 'ge');
reportRenderCashflowFilterForm($period, $fromDate, $toDate, $project, $projects);
?>

<?php if (!empty($grouped_daricxvebi) || !empty($grouped_gadaxdebi)): ?>
    <?php
    $allDates = array_unique(array_merge(array_keys($grouped_daricxvebi), array_keys($grouped_gadaxdebi)));
    usort($allDates, function ($a, $b) use ($period) {
        if ($period === 'year') {
            return (int)$a <=> (int)$b;
        }
        if ($period === 'month') {
            return strtotime($a . '-01') <=> strtotime($b . '-01');
        }
        return strtotime($a) <=> strtotime($b);
    });
    $geoMonths = [
        '01' => 'იანვარი', '02' => 'თებერვალი', '03' => 'მარტი', '04' => 'აპრილი',
        '05' => 'მაისი', '06' => 'ივნისი', '07' => 'ივლისი', '08' => 'აგვისტო',
        '09' => 'სექტემბერი', '10' => 'ოქტომბერი', '11' => 'ნოემბერი', '12' => 'დეკემბერი',
    ];
    reportBlockOpen('', 'report-table--matrix');
    ?>
        <thead>
            <tr>
                <?php foreach ($allDates as $date):
                    $displayDate = $date;
                    if ($period === 'month') {
                        [$y, $m] = explode('-', $date);
                        $displayDate = ($geoMonths[$m] ?? $m) . ' ' . $y;
                    } elseif ($period === 'day' && strpos($date, '/') !== false) {
                        [$d, $m, $y] = explode('/', $date);
                        $displayDate = ltrim($d, '0') . ' ' . ($geoMonths[$m] ?? $m) . ' ' . $y;
                    }
                ?>
                    <th colspan="2"><?= htmlspecialchars($displayDate) ?></th>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach ($allDates as $date): ?>
                    <th>დარიცხვა ($)</th>
                    <th>გადახდა ($)</th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <tr>
                <?php foreach ($allDates as $date): ?>
                    <td><?= number_format($grouped_daricxvebi[$date] ?? 0, 2, '.', ',') ?></td>
                    <td><?= number_format($grouped_gadaxdebi[$date] ?? 0, 2, '.', ',') ?></td>
                <?php endforeach; ?>
            </tr>
        </tbody>
    <?php
    reportBlockClose();
    ?>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
let statistika = <?php echo json_encode($dealsForExcel, JSON_UNESCAPED_UNICODE); ?>;

function exportTableToExcel() {
    const allDatesSet = new Set();
    Object.values(statistika).forEach(deal => {
        Object.keys(deal.gadaxdebi_and_daricxvebi_by_dates || {}).forEach(date => allDatesSet.add(date));
    });
    const allDates = Array.from(allDatesSet).sort((a,b) => new Date(a) - new Date(b));
    const data = [];
    Object.values(statistika).forEach(deal => {
        const row = {
            'კლიენტი': deal.CONTACT_FULL_NAME || '',
            'ხელშეკრულება': deal.TITLE || '',
            'გაფორმების თარიღი': deal['<?= D_CONTRACT_DATE ?>'] || '',
            'კონტრ. ღირებულება': deal.OPPORTUNITY || 0,
            'გადაიხადა': deal.payment || 0,
            'დარჩენილი': (deal.OPPORTUNITY || 0) - (deal.payment || 0),
        };
        allDates.forEach(date => {
            row[`დარიცხვა ${date}`] = deal.gadaxdebi_and_daricxvebi_by_dates?.[date]?.daricxva || 0;
            row[`გადახდა ${date}`] = deal.gadaxdebi_and_daricxvebi_by_dates?.[date]?.gadaxda || 0;
        });
        row['ჯამური დარიცხვა'] = Object.values(deal.gadaxdebi_and_daricxvebi_by_dates || {}).reduce((s, v) => s + (v.daricxva || 0), 0);
        row['ჯამური გადახდა'] = Object.values(deal.gadaxdebi_and_daricxvebi_by_dates || {}).reduce((s, v) => s + (v.gadaxda || 0), 0);
        data.push(row);
    });
    const ws = XLSX.utils.json_to_sheet(data);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
    XLSX.writeFile(wb, 'cashflow_report.xlsx');
}
</script>
<?php reportPageEnd('ge'); ?>
