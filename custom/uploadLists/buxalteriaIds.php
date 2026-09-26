<?php
/**
 * Excel-იდან ბუღალტერიის ID-ების ატვირთვა სიაში "ბუღალტერიის აიდი" (სია 30).
 *
 * Excel: A=დილის ID, B=ბუღალტერიის ID, პირველი სტრიქონი სვეტების დასახელებაა.
 * დილს სიაში ელემენტი თუ არ აქვს, იქმნება (Deal + BuxalteriisId);
 * თუ აქვს და ბუღალტერიის ID განსხვავდება, ახლდება მხოლოდ BuxalteriisId.
 *
 * ატვირთვისას მხოლოდ აჩვენებს რა მოხდება, ჩაწერა ღილაკით ხდება.
 *
 * UI: https://crm.monolith.ge/custom/uploadLists/buxalteriaIds.php
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

global $USER, $APPLICATION;
if (!$USER->IsAuthorized()) {
    $APPLICATION->AuthForm('');
}

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');

define('BXI_IBLOCK_ID', 30);
define('BXI_PROP_DEAL', 'Deal');
define('BXI_PROP_BUX', 'BuxalteriisId');
define('BXI_CACHE_DIR', '/upload/tmp_buxalteria_ids/');
define('BXI_APPLY_CHUNK', 100);

$run = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['run'] ?? '') === '1';
// ჩაწერა ნაწილებად მიდის (AJAX, offset-ით), რომ დიდ ფაილზე მოთხოვნა timeout-ზე არ გავიდეს
$applyRequested = $run && ($_POST['apply'] ?? '') === '1';
$apply = $applyRequested && check_bitrix_sessid();
$offset = max(0, (int)($_POST['offset'] ?? 0));

/**
 * დადებითი მთელი რიცხვი სტრიქონად: '' თუ ცარიელია, null თუ არასწორია ან 0.
 */
function bxiNormId($value)
{
    $value = str_replace(["\xc2\xa0", ' '], '', trim((string)$value));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d+$/', $value)) {
        $value = ltrim($value, '0');
    } elseif (is_numeric($value) && (float)$value >= 0 && floor((float)$value) == (float)$value) {
        $value = sprintf('%.0f', (float)$value);
    } else {
        return null;
    }
    return ($value === '' || $value === '0') ? null : $value;
}

/** CRM-ზე მიბმული ველის მნიშვნელობიდან დილის ID ("123" ან "D_123") */
function bxiDealIdFromValue($value)
{
    return preg_match('/^(?:D_)?(\d+)$/', trim((string)$value), $m) ? (int)$m[1] : 0;
}

function bxiParseCsv($filePath)
{
    $rows = [];
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return $rows;
    }
    if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
        $content = substr($content, 3);
    }

    $delimiter = substr_count($content, ';') > substr_count($content, ',') ? ';' : ',';
    $handle = fopen('php://memory', 'r+');
    if ($handle === false) {
        return $rows;
    }
    fwrite($handle, $content);
    rewind($handle);
    while (($data = fgetcsv($handle, 30000, $delimiter)) !== false) {
        $rows[] = $data;
    }
    fclose($handle);

    return $rows;
}

function bxiParseFile($filePath, &$error = '')
{
    $error = '';
    $sniff = (string)@file_get_contents($filePath, false, null, 0, 2);

    if ($sniff === 'PK') {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/custom/simplexlsx/src/SimpleXLSX.php';
        $xlsx = \Shuchkin\SimpleXLSX::parse($filePath);
        if (!$xlsx) {
            $error = 'xlsx ვერ წაიკითხა: ' . \Shuchkin\SimpleXLSX::parseError();
            return [];
        }
        return $xlsx->rows();
    }

    if ($sniff === "\xD0\xCF") {
        $error = 'ფაილი ძველი .xls ფორმატისაა. Excel-ში შეინახე .xlsx-ად და ატვირთე თავიდან.';
        return [];
    }

    $rows = bxiParseCsv($filePath);
    if (!$rows) {
        $error = 'ფაილი ვერ წაიკითხა. ატვირთე .xlsx ან .csv.';
    }
    return $rows;
}

/**
 * ახალი ფაილი ინახება სესიაზე, რომ «ჩაწერა»-მ იგივე ფაილი გამოიყენოს.
 */
function bxiCacheUploadedFile()
{
    $file = $_FILES['datafile'] ?? null;
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
        $dir = $_SERVER['DOCUMENT_ROOT'] . BXI_CACHE_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . md5(bitrix_sessid() . '|bxi') . '.dat';
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return null;
        }
        $_SESSION['bxi_file'] = $path;
        $_SESSION['bxi_name'] = (string)($file['name'] ?? '');
        return $path;
    }

    $path = (string)($_SESSION['bxi_file'] ?? '');
    return ($path !== '' && is_file($path)) ? $path : null;
}

/** ['deal' => property ID, 'bux' => property ID], კოდით (რეგისტრი არ აქვს მნიშვნელობა) */
function bxiLoadProps()
{
    $byCode = [];
    $res = CIBlockProperty::GetList([], ['IBLOCK_ID' => BXI_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N']);
    while ($prop = $res->Fetch()) {
        $byCode[mb_strtolower((string)$prop['CODE'])] = (int)$prop['ID'];
    }
    return [
        'deal' => $byCode[mb_strtolower(BXI_PROP_DEAL)] ?? 0,
        'bux' => $byCode[mb_strtolower(BXI_PROP_BUX)] ?? 0,
    ];
}

/** dealId => TITLE (მხოლოდ არსებული დილები) */
function bxiLoadDealTitles(array $ids)
{
    $titles = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $res = CCrmDeal::GetListEx(
            [],
            ['@ID' => $chunk, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'TITLE']
        );
        while ($deal = $res->Fetch()) {
            $titles[(int)$deal['ID']] = (string)$deal['TITLE'];
        }
    }
    return $titles;
}

/** dealId => ['id' => ელემენტის ID, 'bux' => ბუღალტერიის ID]; რამდენიმე ელემენტისას პირველი */
function bxiLoadExisting($dealPropId, $buxPropId)
{
    $index = [];
    $dealKey = 'PROPERTY_' . $dealPropId . '_VALUE';
    $buxKey = 'PROPERTY_' . $buxPropId . '_VALUE';
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => BXI_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'IBLOCK_ID', 'PROPERTY_' . $dealPropId, 'PROPERTY_' . $buxPropId]
    );
    while ($row = $res->Fetch()) {
        $dealId = bxiDealIdFromValue($row[$dealKey] ?? '');
        if ($dealId > 0 && !isset($index[$dealId])) {
            $index[$dealId] = [
                'id' => (int)$row['ID'],
                'bux' => (string)bxiNormId($row[$buxKey] ?? ''),
            ];
        }
    }
    return $index;
}

$statusLabels = [
    'create' => 'შეიქმნება',
    'update' => 'განახლდება',
    'created' => 'შეიქმნა',
    'updated' => 'განახლდა',
    'same' => 'უკვე არსებობს',
    'not_found' => 'დილი ვერ მოიძებნა',
    'invalid_deal' => 'არასწორი დილის ID',
    'invalid_bux' => 'არასწორი ბუღალტერიის ID',
    'duplicate' => 'დუბლი ფაილში',
    'failed' => 'შეცდომა',
];
$badStatuses = ['not_found', 'invalid_deal', 'invalid_bux', 'duplicate', 'failed'];

$items = [];
$counts = array_fill_keys(array_keys($statusLabels), 0);
$range = [];
$error = '';
$fileName = '';
$fileHash = '';
$props = bxiLoadProps();

if (!$props['deal'] || !$props['bux']) {
    $error = 'სია ' . BXI_IBLOCK_ID . '-ში ვერ მოიძებნა ველები ' . BXI_PROP_DEAL . ' / ' . BXI_PROP_BUX . '.';
} elseif ($applyRequested && !$apply) {
    $error = 'სესია ამოიწურა. ატვირთე ფაილი თავიდან.';
} elseif ($run) {
    $path = bxiCacheUploadedFile();
    if (!$path) {
        $error = 'ატვირთე Excel ფაილი (.xlsx ან .csv).';
    } else {
        $fileName = (string)($_SESSION['bxi_name'] ?? '');
        $fileHash = (string)md5_file($path);
        $parseError = '';
        $rows = [];

        if ($apply && ($_POST['file_hash'] ?? '') !== $fileHash) {
            $error = 'ფაილი შეიცვალა სხვა ფანჯარაში. ატვირთე თავიდან და ისე ჩაწერე.';
        } else {
            $rows = bxiParseFile($path, $parseError);
            if (!$rows) {
                $error = $parseError !== '' ? $parseError : 'ფაილი ცარიელია.';
            }
        }

        $seen = [];
        foreach ($rows as $i => $row) {
            $rawDeal = trim((string)($row[0] ?? ''));
            $rawBux = trim((string)($row[1] ?? ''));
            if ($rawDeal === '' && $rawBux === '') {
                continue;
            }

            $dealId = bxiNormId($rawDeal);
            if ($i === 0 && !$dealId) {
                continue; // სვეტების დასახელება
            }
            $buxId = bxiNormId($rawBux);

            $item = [
                'row' => $i + 1,
                'deal_raw' => $rawDeal,
                'bux_raw' => $rawBux,
                'deal_id' => $dealId ? (int)$dealId : 0,
                'bux' => $buxId ? $buxId : '',
                'bux_before' => '',
                'title' => '',
                'element_id' => 0,
                'status' => '',
                'error' => '',
            ];

            if (!$dealId) {
                $item['status'] = 'invalid_deal';
            } elseif (!$buxId) {
                $item['status'] = 'invalid_bux';
                $item['error'] = $rawBux === '' ? 'ცარიელია' : 'უნდა იყოს მთელი რიცხვი';
            } elseif (isset($seen[$item['deal_id']])) {
                $item['status'] = 'duplicate';
                $item['error'] = 'იგივე დილი უკვე არის Excel სტრიქონში ' . $seen[$item['deal_id']];
            } else {
                $seen[$item['deal_id']] = $item['row'];
            }
            $items[] = $item;
        }

        if ($rows && !$items) {
            $error = 'ფაილში დასამუშავებელი სტრიქონი ვერ მოიძებნა.';
        }

        // ჩაწერისას მუშავდება მხოლოდ მიმდინარე ნაწილი
        $range = $apply
            ? array_slice(array_keys($items), $offset, BXI_APPLY_CHUNK)
            : array_keys($items);
        $dealIds = [];
        foreach ($range as $k) {
            if ($items[$k]['status'] === '') {
                $dealIds[] = $items[$k]['deal_id'];
            }
        }
        $titles = $dealIds ? bxiLoadDealTitles($dealIds) : [];
        $existing = $dealIds ? bxiLoadExisting($props['deal'], $props['bux']) : [];

        foreach ($range as $k) {
            $item = &$items[$k];
            if ($item['status'] !== '') {
                continue;
            }
            $dealId = $item['deal_id'];
            if (!isset($titles[$dealId])) {
                $item['status'] = 'not_found';
                continue;
            }
            $item['title'] = $titles[$dealId];

            $current = $existing[$dealId] ?? null;
            if ($current) {
                $item['element_id'] = $current['id'];
                $item['bux_before'] = $current['bux'];
                if ($current['bux'] === $item['bux']) {
                    $item['status'] = 'same';
                    continue;
                }
                $item['status'] = 'update';
                if ($apply) {
                    CIBlockElement::SetPropertyValuesEx($current['id'], BXI_IBLOCK_ID, [$props['bux'] => $item['bux']]);
                    $item['status'] = 'updated';
                }
                continue;
            }

            $item['status'] = 'create';
            if ($apply) {
                $el = new CIBlockElement();
                $newId = $el->Add([
                    'IBLOCK_ID' => BXI_IBLOCK_ID,
                    'NAME' => 'დილი ' . $dealId,
                    'ACTIVE' => 'Y',
                    'PROPERTY_VALUES' => [
                        $props['deal'] => $dealId,
                        $props['bux'] => $item['bux'],
                    ],
                ]);
                if ($newId) {
                    $item['status'] = 'created';
                    $item['element_id'] = (int)$newId;
                } else {
                    $item['status'] = 'failed';
                    $item['error'] = (string)$el->LAST_ERROR;
                }
            }
        }
        unset($item);

        foreach ($items as $item) {
            if (isset($counts[$item['status']])) {
                $counts[$item['status']]++;
            }
        }
    }
}

if ($applyRequested) {
    $done = [];
    foreach ($range as $k) {
        $done[] = array_intersect_key($items[$k], array_flip(['row', 'status', 'error', 'element_id']));
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $error, 'total' => count($items), 'items' => $done], JSON_UNESCAPED_UNICODE);
    die();
}

$pending = $counts['create'] + $counts['update'];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ბუღალტერიის ID-ების ატვირთვა</title>
    <style>
        :root {
            --bg: #f4f7fa;
            --panel: #fff;
            --text: #00335b;
            --muted: #6b7c8a;
            --danger: #c0392b;
            --accent: #0e7c66;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        a { color: inherit; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        .sub { color: var(--muted); margin: 0 0 20px; font-size: 14px; line-height: 1.5; }
        .card {
            background: var(--panel);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 28px rgba(0, 51, 91, 0.08);
            margin-bottom: 20px;
        }
        .row { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
        label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; }
        input[type="file"] {
            height: 40px;
            min-width: 280px;
            max-width: 100%;
            border: 1px solid #d5dce3;
            border-radius: 8px;
            padding: 8px 10px 0;
            background: #fff;
            color: var(--text);
            font-size: 14px;
        }
        .btn {
            height: 40px;
            border: 0;
            border-radius: 8px;
            padding: 0 18px;
            background: var(--text);
            color: #fff;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        .btn.apply { background: var(--accent); }
        .howto {
            background: #f7fafc;
            border-left: 3px solid var(--text);
            padding: 12px 14px;
            margin: 0 0 16px;
            font-size: 13px;
            line-height: 1.55;
        }
        .howto ul { margin: 8px 0 0; padding-left: 18px; }
        .howto li { margin: 4px 0; }
        .howto .note { color: var(--muted); margin-top: 8px; }
        .head-line {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .done {
            background: #e8f5f1;
            color: var(--accent);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .counts { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .chip {
            border: 1px solid transparent;
            background: #eef3f7;
            color: var(--text);
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 13px;
            cursor: pointer;
        }
        .chip.bad { background: #fdecea; color: var(--danger); }
        .chip.active { border-color: currentColor; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f4; vertical-align: top; }
        th { font-size: 12px; color: var(--muted); white-space: nowrap; }
        .st-create, .st-update, .st-created, .st-updated { color: var(--accent); font-weight: 600; }
        .st-same { color: var(--muted); font-weight: 600; }
        .st-not_found, .st-invalid_deal, .st-invalid_bux, .st-duplicate, .st-failed { color: var(--danger); font-weight: 600; }
        .error { color: var(--danger); font-size: 13px; margin: 12px 0 0; }
        .muted { color: var(--muted); font-size: 12px; }
        .table-wrap { overflow-x: auto; }
        .old { color: var(--muted); text-decoration: line-through; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>ბუღალტერიის ID-ების ატვირთვა</h1>
    <p class="sub">
        Excel-ის თითო სტრიქონზე სიაში
        <a href="/services/lists/<?= BXI_IBLOCK_ID ?>/view/0/" target="_blank">ბუღალტერიის აიდი</a>
        იქმნება ელემენტი.
    </p>

    <form class="card" method="post" enctype="multipart/form-data">
        <input type="hidden" name="run" value="1">

        <div class="howto">
            <strong>Excel ფორმატი</strong>
            <ul>
                <li><strong>A</strong> - დილის ID (მაგ. 78466)</li>
                <li><strong>B</strong> - ბუღალტერიის ID</li>
            </ul>
            <div class="note">
                პირველი სტრიქონი სვეტების დასახელებაა.
                თუ დილს სიაში ელემენტი უკვე აქვს, ახალი არ იქმნება: სხვა ბუღალტერიის ID-ის შემთხვევაში არსებული ახლდება.
            </div>
        </div>

        <div class="row">
            <div>
                <label for="datafile">Excel ფაილი</label>
                <input type="file" name="datafile" id="datafile" accept=".xlsx,.csv" required>
            </div>
            <button type="submit" class="btn">ნახვა</button>
        </div>
        <?php if ($error !== ''): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
    </form>

    <?php if ($items): ?>
        <div class="card">
            <div class="done" id="apply-done" style="display:none"></div>
            <p class="error" id="apply-error" style="display:none"></p>

            <div class="head-line">
                <div class="muted">
                    ფაილი: <?= htmlspecialchars($fileName) ?> · სტრიქონები: <?= count($items) ?>
                </div>
                <?php if ($pending > 0): ?>
                    <button type="button" class="btn apply" id="apply-btn">ჩაწერა (<?= (int)$pending ?>)</button>
                <?php endif; ?>
            </div>

            <div class="counts" id="counts"></div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Excel #</th>
                        <th>სტატუსი</th>
                        <th>დილი</th>
                        <th>ბუღალტერიის ID</th>
                        <th>სიის ელემენტი</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item):
                        $st = $item['status'];
                    ?>
                        <tr data-status="<?= htmlspecialchars($st) ?>" data-row="<?= (int)$item['row'] ?>">
                            <td><?= (int)$item['row'] ?></td>
                            <td class="status-cell st-<?= htmlspecialchars($st) ?>">
                                <?= htmlspecialchars($statusLabels[$st] ?? $st) ?>
                                <?php if ($item['error'] !== ''): ?>
                                    <div class="muted"><?= htmlspecialchars($item['error']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item['title'] !== ''): ?>
                                    <a href="/crm/deal/details/<?= (int)$item['deal_id'] ?>/" target="_blank">#<?= (int)$item['deal_id'] ?></a>
                                    <div class="muted"><?= htmlspecialchars($item['title']) ?></div>
                                <?php else: ?>
                                    <?= htmlspecialchars($item['deal_raw'] !== '' ? $item['deal_raw'] : '-') ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($item['bux_before'] !== '' && $item['bux_before'] !== $item['bux']): ?>
                                    <span class="old"><?= htmlspecialchars($item['bux_before']) ?></span> →
                                <?php endif; ?>
                                <?= htmlspecialchars($item['bux'] !== '' ? $item['bux'] : ($item['bux_raw'] !== '' ? $item['bux_raw'] : '-')) ?>
                            </td>
                            <td class="element-cell">
                                <?php if ($item['element_id']): ?>
                                    <a href="/services/lists/<?= BXI_IBLOCK_ID ?>/element/0/<?= (int)$item['element_id'] ?>/" target="_blank">#<?= (int)$item['element_id'] ?></a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
    const statusLabels = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>;
    const badStatuses = <?= json_encode($badStatuses) ?>;
    const applyParams = {
        sessid: <?= json_encode(bitrix_sessid()) ?>,
        fileHash: <?= json_encode($fileHash) ?>,
        total: <?= count($items) ?>,
        pending: <?= (int)$pending ?>,
        chunk: <?= BXI_APPLY_CHUNK ?>,
        elementUrl: '/services/lists/<?= BXI_IBLOCK_ID ?>/element/0/'
    };
    const countsEl = document.getElementById('counts');
    let activeFilter = '';

    function applyFilter() {
        document.querySelectorAll('tbody tr[data-status]').forEach(function (tr) {
            tr.style.display = !activeFilter || tr.dataset.status === activeFilter ? '' : 'none';
        });
    }

    function renderChips() {
        if (!countsEl) return;
        const rows = document.querySelectorAll('tbody tr[data-status]');
        const counts = {};
        rows.forEach(function (tr) {
            counts[tr.dataset.status] = (counts[tr.dataset.status] || 0) + 1;
        });
        if (activeFilter && !counts[activeFilter]) activeFilter = '';

        countsEl.innerHTML = '';
        const addChip = function (filter, text, bad) {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'chip' + (bad ? ' bad' : '') + (filter === activeFilter ? ' active' : '');
            chip.textContent = text;
            chip.addEventListener('click', function () {
                activeFilter = filter;
                renderChips();
                applyFilter();
            });
            countsEl.appendChild(chip);
        };
        addChip('', 'ყველა: ' + rows.length, false);
        Object.keys(statusLabels).forEach(function (status) {
            if (counts[status]) {
                addChip(status, statusLabels[status] + ': ' + counts[status], badStatuses.indexOf(status) !== -1);
            }
        });
    }

    function updateRow(item) {
        const tr = document.querySelector('tbody tr[data-row="' + item.row + '"]');
        if (!tr) return;
        tr.dataset.status = item.status;

        const statusCell = tr.querySelector('.status-cell');
        statusCell.className = 'status-cell st-' + item.status;
        statusCell.textContent = statusLabels[item.status] || item.status;
        if (item.error) {
            const err = document.createElement('div');
            err.className = 'muted';
            err.textContent = item.error;
            statusCell.appendChild(err);
        }

        if (item.element_id) {
            const link = document.createElement('a');
            link.href = applyParams.elementUrl + item.element_id + '/';
            link.target = '_blank';
            link.textContent = '#' + item.element_id;
            const elementCell = tr.querySelector('.element-cell');
            elementCell.textContent = '';
            elementCell.appendChild(link);
        }
    }

    async function postChunk(offset) {
        const body = new FormData();
        body.append('run', '1');
        body.append('apply', '1');
        body.append('sessid', applyParams.sessid);
        body.append('file_hash', applyParams.fileHash);
        body.append('offset', String(offset));

        const response = await fetch(window.location.pathname, {method: 'POST', body: body});
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('HTTP ' + response.status + ': ' + text.replace(/<[^>]+>/g, ' ').trim().substring(0, 300));
        }
    }

    const applyBtn = document.getElementById('apply-btn');
    if (applyBtn) {
        applyBtn.addEventListener('click', async function () {
            if (!confirm('სიაში ჩაიწერება ' + applyParams.pending + ' ჩანაწერი. გავაგრძელო?')) return;

            const errorEl = document.getElementById('apply-error');
            const doneEl = document.getElementById('apply-done');
            const result = {created: 0, updated: 0, failed: 0};
            applyBtn.disabled = true;
            errorEl.style.display = 'none';

            for (let offset = 0; offset < applyParams.total; offset += applyParams.chunk) {
                applyBtn.textContent = 'იწერება... ' + offset + ' / ' + applyParams.total;
                let data;
                try {
                    data = await postChunk(offset);
                    if (data.error) throw new Error(data.error);
                } catch (e) {
                    errorEl.textContent = 'ჩაწერა შეწყდა (' + offset + ' / ' + applyParams.total + '): '
                        + e.message.replace(/\.$/, '')
                        + '. უკვე ჩაწერილები სიაში დარჩა; ფაილი ხელახლა ატვირთე და ისინი გამოტოვდება.';
                    errorEl.style.display = '';
                    applyBtn.textContent = 'შეწყდა';
                    renderChips();
                    applyFilter();
                    return;
                }
                data.items.forEach(function (item) {
                    if (result[item.status] !== undefined) result[item.status]++;
                    updateRow(item);
                });
                renderChips();
                applyFilter();
            }

            applyBtn.style.display = 'none';
            doneEl.textContent = 'ჩაიწერა: შეიქმნა ' + result.created + ', განახლდა ' + result.updated
                + (result.failed ? ', შეცდომა ' + result.failed : '');
            doneEl.style.display = '';
        });
    }

    renderChips();
</script>
</body>
</html>
