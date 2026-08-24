<?php

ob_start();
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");
$APPLICATION->SetTitle("upload plan");

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

/** სია 22 — განვადება (TARIGI); სია 23 — გადახდები (date, DEAL, TANXA) */
define('UPLOAD_LIST_IBLOCK_INSTALLMENTS', 22);
define('UPLOAD_LIST_IBLOCK_PAYMENTS', 23);

/**
 * ჰედერის თარიღიდან (თვე/წელი) + exact_number (თვის დღე) → d/m/Y
 * მაგ: ჰედერი 31/01/2026, exact=15 → 15/01/2026
 */
function planUpload_buildDateWithExactDay($headerDate, $exactDay)
{
    $exactDay = intval($exactDay);
    if ($exactDay < 1 || $exactDay > 31) {
        return false;
    }

    $headerDate = trim((string)$headerDate);
    $year = null;
    $month = null;

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $headerDate, $m)) {
        $month = intval($m[2]);
        $year = intval($m[3]);
    } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $headerDate, $m)) {
        $year = intval($m[1]);
        $month = intval($m[2]);
    } elseif (preg_match('/^(\d{4})-(\d{1,2})$/', $headerDate, $m)) {
        $year = intval($m[1]);
        $month = intval($m[2]);
    } else {
        $parsed = planUpload_parseMonthYearHeader($headerDate);
        if (!$parsed) {
            return false;
        }
        $year = $parsed['year'];
        $month = $parsed['month'];
    }

    if (!$year || $month < 1 || $month > 12) {
        return false;
    }

    $daysInMonth = intval(date('t', strtotime(sprintf('%04d-%02d-01', $year, $month))));
    $day = min($exactDay, $daysInMonth);

    return sprintf('%02d/%02d/%04d', $day, $month, $year);
}

function planUpload_parseMonthYearHeader($monthYear)
{
    $parts = explode('-', $monthYear);
    if (count($parts) != 2) {
        return false;
    }

    $monthAbbr = $parts[0];
    $year = '20' . $parts[1];

    $months = array(
        'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
        'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
        'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
    );

    if (!isset($months[$monthAbbr])) {
        return false;
    }

    return array(
        'month' => $months[$monthAbbr],
        'year' => intval($year),
    );
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

// AJAX მოთხოვნის დამუშავება
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
        $headers = json_decode($_POST['headers'], true);
        $iblockId = intval($_POST['iblock_id']);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON data: ' . json_last_error_msg());
        }

        if (!in_array($iblockId, [UPLOAD_LIST_IBLOCK_INSTALLMENTS, UPLOAD_LIST_IBLOCK_PAYMENTS], true)) {
            throw new Exception('არასწორი სიის ID');
        }

        foreach ($batchData as $rowData) {
            $i = $rowData['index'];
            $row = $rowData['data'];

            if (empty($row[0])) {
                continue;
            }

            $dealId = trim($row[0] ?? '');
            $exactDay = trim($row[1] ?? '');

            if (empty($dealId) || !is_numeric($dealId)) {
                $results['errors'][] = "სტრიქონი $i: Deal ID არასწორია ან ცარიელია";
                continue;
            }

            if ($exactDay === '' || !is_numeric($exactDay) || intval($exactDay) < 1 || intval($exactDay) > 31) {
                $results['errors'][] = "სტრიქონი $i: თვის დღე (exact_number) არასწორია — მოსალოდნელია 1–31";
                continue;
            }

            $dealId = intval($dealId);
            $exactDay = intval($exactDay);

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
            $isPaymentsList = ($iblockId === UPLOAD_LIST_IBLOCK_PAYMENTS);

            // C სვეტიდან — თარიღის ჰედერები და თანხები
            for ($j = 2; $j < count($row); $j++) {
                $amount = floatval(trim($row[$j] ?? ''));

                if (!$amount || $amount == 0 || $amount == 0.00) {
                    continue;
                }

                if (!isset($headers[$j]) || $headers[$j] === '' || $headers[$j] === null) {
                    continue;
                }

                $monthYear = trim($headers[$j] ?? '');
                $date = planUpload_buildDateWithExactDay($monthYear, $exactDay);

                if (!$date) {
                    $results['errors'][] = "სტრიქონი $i, სვეტი " . chr(65 + $j) . ": ვერ დამუშავდა თარიღი '$monthYear' + დღე $exactDay";
                    continue;
                }

                $amount = round($amount, 2);
                $nbgRate = calcGetNbgRate();
                $amountGel = $nbgRate ? round($amount * $nbgRate, 2) : 0;

                $arForAdd = array(
                    'IBLOCK_ID' => $iblockId,
                    'NAME' => $isPaymentsList ? ('გადახდა ' . $dealId) : ('განვადება ' . $dealId),
                    'ACTIVE' => 'Y',
                );

                if ($isPaymentsList) {
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
                } else {
                    $arProps = array(
                        'TARIGI' => $date,
                        'TANXA' => $amount . '|' . $currency,
                        'TANXA_NUMBR' => $amount,
                        'amount_GEL' => $amountGel,
                        'DEAL' => $dealId,
                        'PROJECT' => $meta['PROJECT'],
                        'KORPUSI' => $meta['KORPUSI'],
                        'BINIS_NOMERI' => $meta['BINIS_NOMERI'],
                        'floor' => $meta['floor'],
                        'ZETIPI' => $meta['ZETIPI'],
                        'KONTRAKT_DATE' => $meta['KONTRAKT_DATE'],
                        'NBG' => (string)$nbgRate,
                        'xelshNum' => $meta['xelshNum'],
                        'CONTACT' => $meta['CONTACT'],
                    );
                }

                $el = new CIBlockElement;
                $arForAdd['PROPERTY_VALUES'] = $arProps;
                if ($El_ID = $el->Add($arForAdd)) {
                    $results['success']++;
                } else {
                    $results['errors'][] = "სტრიქონი $i, თარიღი $date: Error: " . $el->LAST_ERROR;
                }
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
    <title>განვადების / გადახდების ატვირთვა</title>
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
        .list-selection-box {
            background-color: #e7f3ff;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #b3d9ff;
        }
        .list-selection-box h5 {
            color: #0066cc;
            margin-bottom: 15px;
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
        .form-select {
            font-size: 16px;
            padding: 10px;
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
            <h1>Upload Plan</h1>
        </div>

        <div class="list-selection-box">
            <h5>აირჩიეთ რომელ ლისტში გსურთ ატვირთვა და ატვირთეთ შესაბამისი Excel ფაილი</h5>
            <select class="form-select" id="iblockSelect" required>
                <option value="">-- აირჩიეთ სია --</option>
                <option value="22">განვადება (22)</option>
                <option value="23">გადახდები (23)</option>
            </select>
        </div>

        <div class="info-box">
            <h5>ინსტრუქცია:</h5>
            <ul>
                <li>ზემოთ აირჩიეთ სია: <strong>განვადება</strong> (22) ან <strong>გადახდები</strong> (23) — ერთი და იგივე Excel ფორმატი.</li>
                <li><strong>A</strong> სვეტი: Deal ID</li>
                <li><strong>B</strong> სვეტი: თვის ზუსტი დღე (1–31), მაგ: <code>15</code></li>
                <li><strong>C</strong> სვეტიდან: თარიღი ჰედერში (დღე/თვე/წელი, მაგ: <code>31/01/2026</code>) და თანხა ქვემოთ</li>
                <li>რეალური თარიღი = B სვეტის დღე + ჰედერის თვე/წელი → მაგ. B=<code>15</code>, ჰედერი=<code>31/01/2026</code> → <code>15/01/2026</code></li>
                <li>Deal-ს ვეძებთ Deal ID-ით; მხოლოდ გაყიდული (WON) გარიგებები</li>
            </ul>
            <div class="format-example">
                deal_id | exact_number | 31/01/2026 | 28/02/2026<br>
                69366&nbsp;&nbsp;&nbsp;|&nbsp;15&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;|&nbsp;777&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;|&nbsp;888
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

        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const iblockSelect = document.getElementById('iblockSelect');
            if (!iblockSelect.value) {
                showError('გთხოვთ აირჩიოთ სია, სადაც გსურთ მონაცემების ატვირთვა!');
                iblockSelect.focus();
                return;
            }

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
            const iblockSelect = document.getElementById('iblockSelect');
            if (iblockSelect.value) {
                startBatchProcessing(xlsxData);
            } else {
                showError('გთხოვთ აირჩიოთ სია, სადაც გსურთ მონაცემების ატვირთვა!');
            }
        } else if (uploadMessage && uploadMessage.startsWith('error:')) {
            showError(uploadMessage);
        }

        async function startBatchProcessing(data) {
            if (!data || data.length < 2) {
                showError('ფაილი ცარიელია ან არასწორი ფორმატისაა');
                return;
            }

            const iblockId = document.getElementById('iblockSelect').value;
            if (!iblockId) {
                showError('გთხოვთ აირჩიოთ სია!');
                return;
            }

            document.getElementById('uploadBtn').disabled = true;
            document.getElementById('progressContainer').style.display = 'block';

            const headers = data[0];
            const rows = data.slice(1);
            const BATCH_SIZE = 5;
            const totalBatches = Math.ceil(rows.length / BATCH_SIZE);

            document.getElementById('totalCount').textContent = rows.length;

            let totalSuccess = 0;
            let allErrors = [];
            let processedRows = 0;

            const selectedListName = document.getElementById('iblockSelect').options[document.getElementById('iblockSelect').selectedIndex].text;

            for (let i = 0; i < totalBatches; i++) {
                const start = i * BATCH_SIZE;
                const end = Math.min(start + BATCH_SIZE, rows.length);
                const batch = rows.slice(start, end);

                const batchData = batch.map((row, idx) => ({
                    index: start + idx + 2,
                    data: row
                }));

                try {
                    const result = await processBatch(batchData, headers, iblockId);
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

            showResults(totalSuccess, allErrors, selectedListName);
            document.getElementById('uploadBtn').disabled = false;
        }

        function processBatch(batchData, headers, iblockId) {
            return new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('action', 'process_batch');
                formData.append('batch_data', JSON.stringify(batchData));
                formData.append('headers', JSON.stringify(headers));
                formData.append('iblock_id', iblockId);

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

        function showResults(successCount, errors, listName = '') {
            let html = '';

            if (successCount > 0) {
                html += `<div class="alert alert-success">
                    <strong>✓ წარმატება!</strong><br>
                    წარმატებით დაემატა ${successCount} ჩანაწერი${listName ? ' სიაში: <strong>' + listName + '</strong>' : ''}
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

            document.getElementById('results').innerHTML = html;
        }

        function showError(message) {
            document.getElementById('results').innerHTML =
                `<div class="alert alert-danger">${message}</div>`;
        }
    </script>
</body>
</html>
