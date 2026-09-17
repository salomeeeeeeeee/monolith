<?php

ob_start();
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
$APPLICATION->SetTitle("გადახდების ატვირთვა");

use Shuchkin\SimpleXLSX;

ini_set('error_reporting', E_ALL);
ini_set('display_errors', true);
ini_set('max_execution_time', 120);
ini_set('memory_limit', '512M');

require_once $_SERVER["DOCUMENT_ROOT"] . '/custom/simplexlsx/src/SimpleXLSX.php';
require_once $_SERVER["DOCUMENT_ROOT"] . '/rest/local/api/calculator/helpers.php';

if (!CModule::IncludeModule("crm")) {
    die("CRM module not loaded");
}
if (!CModule::IncludeModule("iblock")) {
    die("IBlock module not loaded");
}

define('UPLOAD_PAYMENTS_IBLOCK', 23);

/**
 * თარიღის ნორმალიზაცია → d/m/Y
 * მხარს უჭერს: DD/MM/YYYY, DD.MM.YYYY, YYYY-MM-DD, Excel serial
 */
function paymentsUpload_normalizeDate($value)
{
    if ($value === null || $value === '') {
        return false;
    }

    if (is_numeric($value) && floatval($value) > 20000 && floatval($value) < 60000) {
        $unix = ((float)$value - 25569) * 86400;
        return date('d/m/Y', (int)$unix);
    }

    $value = trim((string)$value);

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
        return sprintf('%02d/%02d/%04d', intval($m[1]), intval($m[2]), intval($m[3]));
    }
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
        return sprintf('%02d/%02d/%04d', intval($m[1]), intval($m[2]), intval($m[3]));
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $m)) {
        return sprintf('%02d/%02d/%04d', intval($m[3]), intval($m[2]), intval($m[1]));
    }

    $ts = strtotime($value);
    if ($ts) {
        return date('d/m/Y', $ts);
    }

    return false;
}

/**
 * თანხის/კურსის ნორმალიზაცია (მძიმე → წერტილი)
 */
function paymentsUpload_normalizeNumber($value)
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (is_numeric($value)) {
        return floatval($value);
    }
    $value = trim((string)$value);
    $value = str_replace(' ', '', $value);
    $value = str_replace(',', '.', $value);
    return floatval($value);
}

/**
 * ჰედერებიდან სვეტების ინდექსები
 * ფორმატი: Deal ID | თარიღი | USD | კურსი(NBG) | GEL
 * ცარიელი/დამალული სვეტები იგნორირდება სახელებით ან პოზიციით
 */
function paymentsUpload_detectColumns($headers)
{
    $map = array(
        'deal' => null,
        'date' => null,
        'usd' => null,
        'nbg' => null,
        'gel' => null,
    );

    foreach ($headers as $i => $header) {
        $h = mb_strtolower(trim((string)$header));
        if ($h === '') {
            continue;
        }

        if ($map['deal'] === null && (
            strpos($h, 'crm') !== false
            || strpos($h, 'deal') !== false
            || strpos($h, 'დილ') !== false
            || $h === 'id'
        )) {
            $map['deal'] = $i;
            continue;
        }
        if ($map['date'] === null && (
            strpos($h, 'თარიღ') !== false
            || strpos($h, 'date') !== false
            || strpos($h, 'tarigi') !== false
        )) {
            $map['date'] = $i;
            continue;
        }
        if ($map['usd'] === null && (
            $h === 'usd'
            || strpos($h, 'თანხ') !== false
            || strpos($h, 'tanxa') !== false
            || strpos($h, 'amount') !== false
        )) {
            $map['usd'] = $i;
            continue;
        }
        if ($map['nbg'] === null && (
            strpos($h, 'კურს') !== false
            || strpos($h, 'nbg') !== false
            || strpos($h, 'rate') !== false
            || strpos($h, 'kurs') !== false
        )) {
            $map['nbg'] = $i;
            continue;
        }
        if ($map['gel'] === null && (
            $h === 'gel'
            || strpos($h, 'tanxa_gel') !== false
            || strpos($h, 'lari') !== false
        )) {
            $map['gel'] = $i;
            continue;
        }
    }

    // fallback: პირველი 5 არაცარიელი სვეტი
    if ($map['deal'] === null || $map['date'] === null || $map['usd'] === null) {
        $nonEmpty = array();
        foreach ($headers as $i => $header) {
            if (trim((string)$header) !== '') {
                $nonEmpty[] = $i;
            }
        }
        // თუ ჰედერები საერთოდ ცარიელია — 0..4
        if (count($nonEmpty) < 3) {
            $nonEmpty = array(0, 1, 2, 3, 4);
        }
        if ($map['deal'] === null && isset($nonEmpty[0])) {
            $map['deal'] = $nonEmpty[0];
        }
        if ($map['date'] === null && isset($nonEmpty[1])) {
            $map['date'] = $nonEmpty[1];
        }
        if ($map['usd'] === null && isset($nonEmpty[2])) {
            $map['usd'] = $nonEmpty[2];
        }
        if ($map['nbg'] === null && isset($nonEmpty[3])) {
            $map['nbg'] = $nonEmpty[3];
        }
        if ($map['gel'] === null && isset($nonEmpty[4])) {
            $map['gel'] = $nonEmpty[4];
        }
    }

    return $map;
}

global $USER;

if ($USER->GetID()) {
    $NotAuthorized = false;
    $user_id = $USER->GetID();
    $USER->Authorize(1);
} else {
    $NotAuthorized = true;
    $USER->Authorize(1);
}

// AJAX — batch დამუშავება
if ($_SERVER["REQUEST_METHOD"] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'process_batch') {

    while (ob_get_level()) {
        ob_end_clean();
    }

    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_WARNING & ~E_NOTICE);
    ini_set('display_errors', 0);

    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        if ($errno === E_DEPRECATED) {
            return true;
        }
        if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR || $errno === E_COMPILE_ERROR) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        }
        return true;
    });

    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array(
                'success' => 0,
                'errors' => array('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']),
            ), JSON_UNESCAPED_UNICODE);
            exit;
        }
    });

    $results = array(
        'success' => 0,
        'errors' => array(),
    );

    try {
        $batchData = json_decode($_POST['batch_data'], true);
        $columns = json_decode($_POST['columns'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON data: ' . json_last_error_msg());
        }

        if (!is_array($columns) || $columns['deal'] === null || $columns['date'] === null || $columns['usd'] === null) {
            throw new Exception('სვეტების მაპინგი არასწორია');
        }

        $colDeal = intval($columns['deal']);
        $colDate = intval($columns['date']);
        $colUsd = intval($columns['usd']);
        $colNbg = isset($columns['nbg']) && $columns['nbg'] !== null && $columns['nbg'] !== ''
            ? intval($columns['nbg']) : null;
        $colGel = isset($columns['gel']) && $columns['gel'] !== null && $columns['gel'] !== ''
            ? intval($columns['gel']) : null;

        foreach ($batchData as $rowData) {
            $i = $rowData['index'];
            $row = $rowData['data'];

            $dealRaw = trim((string)($row[$colDeal] ?? ''));
            if ($dealRaw === '') {
                continue;
            }

            if (!is_numeric($dealRaw)) {
                $results['errors'][] = "სტრიქონი $i: Deal ID არასწორია ($dealRaw)";
                continue;
            }

            $dealId = intval($dealRaw);

            $date = paymentsUpload_normalizeDate($row[$colDate] ?? '');
            if (!$date) {
                $results['errors'][] = "სტრიქონი $i: თარიღი არასწორია";
                continue;
            }

            $amount = paymentsUpload_normalizeNumber($row[$colUsd] ?? '');
            if (!$amount || $amount == 0) {
                $results['errors'][] = "სტრიქონი $i: თანხა (USD) ცარიელია ან 0";
                continue;
            }
            $amount = round($amount, 2);

            $nbgRate = 0;
            if ($colNbg !== null) {
                $nbgRate = paymentsUpload_normalizeNumber($row[$colNbg] ?? '');
            }
            if (!$nbgRate) {
                $nbgDate = null;
                if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $dm)) {
                    $nbgDate = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
                }
                $nbgRate = calcGetNbgRate($nbgDate);
            }
            $nbgRate = round(floatval($nbgRate), 4);

            $amountGel = 0;
            if ($colGel !== null) {
                $amountGel = paymentsUpload_normalizeNumber($row[$colGel] ?? '');
            }
            if (!$amountGel && $nbgRate) {
                $amountGel = round($amount * $nbgRate, 2);
            } else {
                $amountGel = round(floatval($amountGel), 2);
            }

            $dealRes = CCrmDeal::GetList(
                array("ID" => "ASC"),
                array("ID" => $dealId),
                array("ID", "STAGE_ID", "CURRENCY_ID")
            );
            $dealData = $dealRes->Fetch();

            if (!$dealData) {
                $results['errors'][] = "სტრიქონი $i: Deal ID $dealId არ მოიძებნა";
                continue;
            }

            if ($dealData["STAGE_ID"] !== "WON") {
                $results['errors'][] = "სტრიქონი $i: Deal ID $dealId არ არის გაყიდული (STAGE_ID: " . $dealData["STAGE_ID"] . ")";
                continue;
            }

            $fullDeal = calcGetDealInfoByID($dealId);
            $meta = $fullDeal ? calcGetDealMetaForPlan($fullDeal) : array(
                'PROJECT' => '',
                'KORPUSI' => '',
                'BINIS_NOMERI' => '',
                'floor' => '',
                'ZETIPI' => '',
                'KONTRAKT_DATE' => '',
                'xelshNum' => '',
                'CONTACT' => '',
                'FULL_NAME' => '',
            );

            $currency = !empty($dealData['CURRENCY_ID']) ? $dealData['CURRENCY_ID'] : 'USD';

            $arForAdd = array(
                'IBLOCK_ID' => UPLOAD_PAYMENTS_IBLOCK,
                'NAME' => 'გადახდა ' . $dealId,
                'ACTIVE' => 'Y',
            );

            $arProps = array(
                'date' => $date,
                'TANXA' => $amount . '|' . $currency,
                'tanxa_gel' => (string)$amountGel,
                'DEAL' => $dealId,
                'DEAL_ID' => $dealId,
                'PROJECT' => $meta['PROJECT'],
                'KORPUSI' => $meta['KORPUSI'],
                'BINIS_NOMERI' => $meta['BINIS_NOMERI'],
                'floor' => $meta['floor'],
                'ZETIPI' => $meta['ZETIPI'],
                'KONTRAKT_DATE' => $meta['KONTRAKT_DATE'],
                'CURRENCY' => $currency,
                'NBG' => (string)$nbgRate,
                'FULL_NAME' => $meta['FULL_NAME'],
                'xelshNum' => $meta['xelshNum'],
            );

            $el = new CIBlockElement;
            $arForAdd['PROPERTY_VALUES'] = $arProps;
            if ($El_ID = $el->Add($arForAdd)) {
                $results['success']++;
            } else {
                $results['errors'][] = "სტრიქონი $i: Error: " . $el->LAST_ERROR;
            }
        }
    } catch (Exception $e) {
        if (strpos($e->getFile(), '/bitrix/') === false || strpos($e->getMessage(), 'deprecated') === false) {
            $results['errors'][] = 'შეცდომა: ' . $e->getMessage() . ' (File: ' . $e->getFile() . ', Line: ' . $e->getLine() . ')';
        }
    } catch (Error $e) {
        if (strpos($e->getFile(), '/bitrix/') === false || strpos($e->getMessage(), 'deprecated') === false) {
            $results['errors'][] = 'Fatal error: ' . $e->getMessage() . ' (File: ' . $e->getFile() . ', Line: ' . $e->getLine() . ')';
        }
    }

    restore_error_handler();

    if (isset($NotAuthorized) && $NotAuthorized) {
        $USER->Logout();
    } elseif (isset($user_id)) {
        $USER->Authorize($user_id);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($results, JSON_UNESCAPED_UNICODE);
    exit;
}

$xlsxData = null;
$uploadMessage = '';

if ($_SERVER["REQUEST_METHOD"] == 'POST' && isset($_FILES["image"])) {
    $file = $_FILES["image"];

    if (!is_dir('xlsxFiles')) {
        mkdir('xlsxFiles');
    }

    if ($file && strlen($file["tmp_name"])) {
        $timestamp = date("Ymdhisa");
        $filePath = 'xlsxFiles/' . $timestamp . '/' . $file["name"];
        mkdir(dirname($filePath), 0777, true);
        move_uploaded_file($file['tmp_name'], $filePath);

        if ($xlsx = SimpleXLSX::parse($filePath)) {
            // თუ რამდენიმე sheet არის — ვირჩევთ იმას, რომლის სახელშიც არის „გადახდ“
            if (method_exists($xlsx, 'sheetNames') && method_exists($xlsx, 'changeSheet')) {
                $sheetNames = $xlsx->sheetNames();
                if (is_array($sheetNames)) {
                    foreach ($sheetNames as $idx => $name) {
                        if (mb_stripos((string)$name, 'გადახდ') !== false) {
                            $xlsx->changeSheet($idx);
                            break;
                        }
                    }
                }
            }
            $xlsxData = $xlsx->rows();
            $uploadMessage = 'success';
        } else {
            $uploadMessage = 'error: ' . SimpleXLSX::parseError();
        }
    }
}

if ($NotAuthorized) {
    $USER->Logout();
} else {
    $USER->Authorize($user_id);
}

ob_end_clean();
?>

<!doctype html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>გადახდების ატვირთვა</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.0-beta1/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-0evHe/X+R7YkIZDRvuzKMRqM+OrBnVFBL6DOitfPri4tjfHxaWutUpFmBp4vmVor" crossorigin="anonymous">
    <style>
        .container {
            max-width: 800px;
            margin-top: 50px;
        }
        .info-box {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .info-box h5 {
            color: #495057;
        }
        .info-box ul {
            margin-bottom: 0;
        }
        .progress-container {
            display: none;
            margin-top: 20px;
        }
        .progress {
            height: 30px;
        }
        .progress-bar {
            font-size: 14px;
            line-height: 30px;
        }
        #results {
            margin-top: 20px;
        }
        .format-example {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 13px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px 12px;
            margin-top: 10px;
            overflow-x: auto;
            white-space: nowrap;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>გადახდების ატვირთვა</h1>
            <a href="/custom/uploadLists/plan.php" class="btn btn-outline-secondary btn-sm">განვადების ატვირთვა →</a>
        </div>

        <div class="info-box">
            <h5>ინსტრუქცია (Excel ფორმატი):</h5>
            <ul>
                <li><strong>A</strong> — Deal ID (CRM ID)</li>
                <li><strong>B</strong> — თარიღი (მაგ: <code>05/08/2025</code>)</li>
                <li><strong>C</strong> — თანხა USD</li>
                <li><strong>D</strong> — კურსი</li>
                <li><strong>E</strong> — თანხა GEL</li>
            </ul>
            <ul class="mt-2">
                <li>დანარჩენი ველები (პროექტი, კორპუსი, ბინა, სახელი და ა.შ.) ივსება Deal-იდან</li>
                <li>მხოლოდ გაყიდული (WON) გარიგებები</li>
                <li>თუ კურსი/GEL ცარიელია — NBG API-დან აიღება თარიღის მიხედვით</li>
          
            </ul>
            <div class="format-example">
                CRM ID | თარიღი&nbsp;&nbsp;&nbsp;&nbsp;| USD&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;| კურსი&nbsp;| GEL<br>
                67941&nbsp;&nbsp;|&nbsp;05/08/2025&nbsp;|&nbsp;17000.00&nbsp;|&nbsp;2.7029&nbsp;|&nbsp;45949.30
            </div>
        </div>

        <form method="post" enctype="multipart/form-data" id="uploadForm">
            <div class="mb-3">
                <label class="form-label">აირჩიეთ Excel ფაილი (.xlsx)</label>
                <input type="file" name="image" class="form-control" accept=".xlsx" required>
            </div>
            <button type="submit" class="btn btn-primary" id="uploadBtn">
                ატვირთვა და დამუშავება
            </button>
        </form>

        <div class="progress-container" id="progressContainer">
            <div class="alert alert-info">
                <strong>მიმდინარეობს დამუშავება...</strong>
                <p class="mb-0">გთხოვთ დაელოდოთ და არ დახუროთ ეს გვერდი.</p>
            </div>
            <div class="progress">
                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar"
                     style="width: 0%" id="progressBar">0%</div>
            </div>
            <div class="mt-2 text-center" id="progressText">
                <small class="text-muted">დამუშავებულია: <span id="processedCount">0</span> / <span id="totalCount">0</span></small>
            </div>
        </div>

        <div id="results"></div>
    </div>

    <script>
        const xlsxData = <?php echo $xlsxData ? json_encode($xlsxData) : 'null'; ?>;
        const uploadMessage = '<?php echo $uploadMessage; ?>';

        /** ჰედერებიდან სვეტების მაპინგი (იგივე ლოგიკა რაც PHP-ში) */
        function detectColumns(headers) {
            const map = { deal: null, date: null, usd: null, nbg: null, gel: null };

            headers.forEach((header, i) => {
                const h = String(header || '').trim().toLowerCase();
                if (!h) return;

                if (map.deal === null && (h.includes('crm') || h.includes('deal') || h.includes('დილ') || h === 'id')) {
                    map.deal = i;
                    return;
                }
                if (map.date === null && (h.includes('თარიღ') || h.includes('date') || h.includes('tarigi'))) {
                    map.date = i;
                    return;
                }
                if (map.usd === null && (h === 'usd' || h.includes('თანხ') || h.includes('tanxa') || h.includes('amount'))) {
                    map.usd = i;
                    return;
                }
                if (map.nbg === null && (h.includes('კურს') || h.includes('nbg') || h.includes('rate') || h.includes('kurs'))) {
                    map.nbg = i;
                    return;
                }
                if (map.gel === null && (h === 'gel' || h.includes('tanxa_gel') || h.includes('lari'))) {
                    map.gel = i;
                    return;
                }
            });

            if (map.deal === null || map.date === null || map.usd === null) {
                const nonEmpty = [];
                headers.forEach((header, i) => {
                    if (String(header || '').trim() !== '') nonEmpty.push(i);
                });
                const cols = nonEmpty.length >= 3 ? nonEmpty : [0, 1, 2, 3, 4];
                if (map.deal === null) map.deal = cols[0] ?? 0;
                if (map.date === null) map.date = cols[1] ?? 1;
                if (map.usd === null) map.usd = cols[2] ?? 2;
                if (map.nbg === null) map.nbg = cols[3] ?? 3;
                if (map.gel === null) map.gel = cols[4] ?? 4;
            }

            return map;
        }

        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const scriptContent = doc.querySelector('script').textContent;
                const dataMatch = scriptContent.match(/const xlsxData = (.+?);/);

                if (dataMatch && dataMatch[1] !== 'null') {
                    const data = JSON.parse(dataMatch[1]);
                    startBatchProcessing(data);
                } else {
                    showError('ფაილის წაკითხვა ვერ მოხერხდა');
                }
            })
            .catch(error => {
                showError('შეცდომა: ' + error.message);
            });
        });

        if (xlsxData && uploadMessage === 'success') {
            startBatchProcessing(xlsxData);
        } else if (uploadMessage && uploadMessage.startsWith('error:')) {
            showError(uploadMessage);
        }

        async function startBatchProcessing(data) {
            if (!data || data.length < 2) {
                showError('ფაილი ცარიელია ან არასწორი ფორმატისაა');
                return;
            }

            document.getElementById('uploadBtn').disabled = true;
            document.getElementById('progressContainer').style.display = 'block';

            const headers = data[0];
            const columns = detectColumns(headers);
            const rows = data.slice(1).filter(row => {
                const dealVal = row[columns.deal];
                return dealVal !== null && dealVal !== undefined && String(dealVal).trim() !== '';
            });

            const BATCH_SIZE = 5;
            const totalBatches = Math.ceil(rows.length / BATCH_SIZE);

            document.getElementById('totalCount').textContent = rows.length;

            let totalSuccess = 0;
            let allErrors = [];
            let processedRows = 0;

            for (let i = 0; i < totalBatches; i++) {
                const start = i * BATCH_SIZE;
                const end = Math.min(start + BATCH_SIZE, rows.length);
                const batch = rows.slice(start, end);

                const batchData = batch.map((row, idx) => ({
                    index: start + idx + 2,
                    data: row
                }));

                try {
                    const result = await processBatch(batchData, columns);
                    totalSuccess += result.success;
                    allErrors = allErrors.concat(result.errors || []);
                    processedRows += batch.length;

                    const percent = Math.round((processedRows / rows.length) * 100);
                    document.getElementById('progressBar').style.width = percent + '%';
                    document.getElementById('progressBar').textContent = percent + '%';
                    document.getElementById('processedCount').textContent = processedRows;
                } catch (error) {
                    allErrors.push('Batch ' + (i + 1) + ' შეცდომა: ' + error.message);
                }

                await new Promise(resolve => setTimeout(resolve, 300));
            }

            showResults(totalSuccess, allErrors);
            document.getElementById('uploadBtn').disabled = false;
        }

        function processBatch(batchData, columns) {
            return new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('action', 'process_batch');
                formData.append('batch_data', JSON.stringify(batchData));
                formData.append('columns', JSON.stringify(columns));

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    return response.text().then(text => {
                        if (!response.ok) {
                            if (response.status === 500) {
                                throw new Error('HTTP 500 შეცდომა: ' + (text.substring(0, 500) || 'უცნობი შეცდომა'));
                            }
                            throw new Error('HTTP error! status: ' + response.status + ' - ' + text.substring(0, 200));
                        }
                        return text;
                    });
                })
                .then(text => {
                    try {
                        resolve(JSON.parse(text));
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        console.error('Response text:', text);
                        reject(new Error('Invalid JSON response: ' + text.substring(0, 500)));
                    }
                })
                .catch(error => reject(error));
            });
        }

        function showResults(successCount, errors) {
            let html = '';

            if (successCount > 0) {
                html += `<div class="alert alert-success">
                    <strong>✓ წარმატება!</strong><br>
                    წარმატებით დაემატა ${successCount} გადახდა სიაში: <strong>გადახდები (23)</strong>
                </div>`;
            }

            if (errors.length > 0) {
                html += `<div class="alert alert-danger">
                    <strong>⚠ შეცდომები:</strong><br>`;
                errors.forEach(error => {
                    html += `<div>• ${error}</div>`;
                });
                html += '</div>';
            }

            if (successCount === 0 && errors.length === 0) {
                html = `<div class="alert alert-warning">დასამუშავებელი ჩანაწერი ვერ მოიძებნა</div>`;
            }

            document.getElementById('results').innerHTML = html;
        }

        function showError(message) {
            document.getElementById('results').innerHTML =
                `<div class="alert alert-danger">${message}</div>`;
        }
    </script>
</body>
</html>
