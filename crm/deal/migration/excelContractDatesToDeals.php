<?php
/**
 * Excel-იდან დილების ხელშეკრულების გაფორმების თარიღის (მონოლითი) განახლება.
 *
 * რეჟიმი 1 (by_id):     A=Deal ID, B=თარიღი
 * რეჟიმი 2 (by_params): A=სექტორი, B=ბლოკი, C=სართული, D=ნომერი, E=თარიღი
 *                       (+ არჩეული პროექტი / ფართის ტიპი)
 *
 * UI: https://crm.monolith.ge/crm/deal/migration/excelContractDatesToDeals.php
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');

define('PRODUCT_IBLOCK_ID', 14);
define('D_CONTRACT_DATE', 'UF_CRM_1779278774084');
define('D_PROJECT', 'UF_CRM_1779277729207');
define('D_TYPE', 'UF_CRM_1779277898205');
define('D_SECTOR', 'UF_CRM_1781768590754');
define('D_BLOCK', 'UF_CRM_1779277644355');
define('D_FLOOR', 'UF_CRM_1779277828822');
define('D_NUMBER', 'UF_CRM_1779277613798');

$run = isset($_REQUEST['run']) && $_REQUEST['run'] === '1';
$apply = isset($_REQUEST['apply']) && $_REQUEST['apply'] === '1';
$mode = trim((string)($_REQUEST['mode'] ?? 'by_id'));
if (!in_array($mode, ['by_id', 'by_params'], true)) {
    $mode = 'by_id';
}
$filterProject = trim((string)($_REQUEST['project'] ?? ''));
$filterType = trim((string)($_REQUEST['type'] ?? ''));

function ecdColLettersToIndex($letters)
{
    $letters = strtoupper($letters);
    $index = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function ecdParseCellRef($ref)
{
    if (!preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) {
        return [0, 0];
    }
    return [ecdColLettersToIndex($m[1]), (int)$m[2] - 1];
}

function ecdParseXlsxNative($filePath)
{
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return [];
    }

    $sharedStrings = [];
    $ssContent = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssContent !== false) {
        $ssXml = @simplexml_load_string($ssContent);
        if ($ssXml) {
            foreach ($ssXml->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetPath = 'xl/worksheets/sheet1.xml';
    $workbook = $zip->getFromName('xl/workbook.xml');
    if ($workbook !== false) {
        $wbXml = @simplexml_load_string($workbook);
        if ($wbXml && isset($wbXml->sheets->sheet[0])) {
            $attrs = $wbXml->sheets->sheet[0]->attributes('r', true);
            $rid = (string)($attrs['id'] ?? '');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($rels !== false && $rid !== '') {
                $relsXml = @simplexml_load_string($rels);
                if ($relsXml) {
                    foreach ($relsXml->Relationship as $rel) {
                        $relAttrs = $rel->attributes();
                        if ((string)$relAttrs['Id'] === $rid) {
                            $target = (string)$relAttrs['Target'];
                            $sheetPath = 'xl/' . ltrim(str_replace('../', '', $target), '/');
                            break;
                        }
                    }
                }
            }
        }
    }

    $sheetContent = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetContent === false) {
        return [];
    }

    $sheetXml = @simplexml_load_string($sheetContent);
    if (!$sheetXml || !isset($sheetXml->sheetData->row)) {
        return [];
    }

    $grid = [];
    $maxRow = 0;
    $maxCol = 0;

    foreach ($sheetXml->sheetData->row as $row) {
        $rowAttrs = $row->attributes();
        $rowIndex = isset($rowAttrs['r']) ? (int)$rowAttrs['r'] - 1 : 0;

        foreach ($row->c as $cell) {
            $cellAttrs = $cell->attributes();
            $ref = (string)($cellAttrs['r'] ?? '');
            if ($ref === '') {
                continue;
            }

            [$colIndex, $parsedRow] = ecdParseCellRef($ref);
            if ($rowIndex <= 0) {
                $rowIndex = $parsedRow;
            }

            $type = (string)($cellAttrs['t'] ?? '');
            $value = isset($cell->v) ? (string)$cell->v : '';
            if ($type === 's' && $value !== '' && isset($sharedStrings[(int)$value])) {
                $value = $sharedStrings[(int)$value];
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $value = (string)$cell->is->t;
            }

            if (!isset($grid[$rowIndex])) {
                $grid[$rowIndex] = [];
            }
            $grid[$rowIndex][$colIndex] = $value;
            $maxRow = max($maxRow, $rowIndex);
            $maxCol = max($maxCol, $colIndex);
        }
    }

    $rows = [];
    for ($r = 0; $r <= $maxRow; $r++) {
        $line = [];
        for ($c = 0; $c <= $maxCol; $c++) {
            $line[] = $grid[$r][$c] ?? '';
        }
        $rows[] = $line;
    }

    return $rows;
}

function ecdParseXlsxSimple($filePath)
{
    $simplePath = $_SERVER['DOCUMENT_ROOT'] . '/custom/simplexlsx/src/SimpleXLSX.php';
    if (!file_exists($simplePath)) {
        return [];
    }

    require_once $simplePath;
    if (!class_exists('Shuchkin\\SimpleXLSX') && !class_exists('SimpleXLSX')) {
        return [];
    }

    $xlsx = class_exists('Shuchkin\\SimpleXLSX')
        ? \Shuchkin\SimpleXLSX::parse($filePath)
        : SimpleXLSX::parse($filePath);

    if (!$xlsx) {
        return [];
    }

    return $xlsx->rows();
}

function ecdParseXlsxPhpSpreadsheet($filePath)
{
    $autoload = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/php_interface/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        return [];
    }

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = [];
    foreach ($sheet->toArray(null, true, true, false) as $data) {
        $rows[] = $data;
    }
    return $rows;
}

function ecdParseCsvFile($filePath)
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

function ecdParseExcelFile($filePath, &$error = '')
{
    $error = '';
    if (!is_readable($filePath)) {
        $error = 'ფაილი ვერ წაიკითხა (არ არსებობს ან წვდომა აკლია).';
        return [];
    }

    $sniff = @file_get_contents($filePath, false, null, 0, 4);
    $isZip = $sniff !== false && substr($sniff, 0, 2) === 'PK';

    if ($isZip) {
        $parsers = [
            'PhpSpreadsheet' => 'ecdParseXlsxPhpSpreadsheet',
            'SimpleXLSX' => 'ecdParseXlsxSimple',
            'ZipArchive' => 'ecdParseXlsxNative',
        ];
        $errors = [];
        foreach ($parsers as $name => $fn) {
            try {
                $rows = $fn($filePath);
                if ($rows) {
                    return $rows;
                }
                $errors[] = $name . ': ცარიელი შედეგი';
            } catch (Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }
        $error = 'xlsx ვერ წაიკითხა. ' . implode('; ', $errors);
        return [];
    }

    $rows = ecdParseCsvFile($filePath);
    if (!$rows) {
        $error = 'csv ვერ წაიკითხა.';
    }
    return $rows;
}

function ecdCacheUploadedFile()
{
    $cacheDir = $_SERVER['DOCUMENT_ROOT'] . '/upload/tmp_excel_contract_dates_cache/';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    if (
        !empty($_FILES['datafile']['tmp_name'])
        && ($_FILES['datafile']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
    ) {
        $origName = (string)($_FILES['datafile']['name'] ?? 'upload.xlsx');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'xlsx';
        }
        $cachedPath = $cacheDir . bitrix_sessid() . '.' . $ext;
        if (move_uploaded_file($_FILES['datafile']['tmp_name'], $cachedPath)) {
            $_SESSION['ecd_cached_file'] = $cachedPath;
            $_SESSION['ecd_cached_name'] = $origName;
            return $cachedPath;
        }
    }

    if (!empty($_SESSION['ecd_cached_file']) && file_exists($_SESSION['ecd_cached_file'])) {
        return $_SESSION['ecd_cached_file'];
    }

    return null;
}

function ecdExtractField($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    return trim((string)$value);
}

function ecdNormVal($value)
{
    $value = ecdExtractField($value);
    $value = str_replace(["\xc2\xa0", ' '], '', $value);
    $value = str_replace(',', '.', $value);
    return mb_strtolower($value);
}

function ecdNumVal($value)
{
    $value = ecdNormVal($value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return round((float)$value, 4);
}

function ecdValsEqual($a, $b)
{
    $na = ecdNumVal($a);
    $nb = ecdNumVal($b);
    if ($na !== null && $nb !== null) {
        return abs($na - $nb) < 0.0001;
    }
    return ecdNormVal($a) === ecdNormVal($b);
}

function ecdKeyPart($value)
{
    $num = ecdNumVal($value);
    if ($num !== null) {
        return rtrim(rtrim(sprintf('%.4f', $num), '0'), '.');
    }
    return ecdNormVal($value);
}

function ecdBuildDealKey($sector, $block, $floor, $number)
{
    return implode('|', [
        ecdKeyPart($sector),
        ecdKeyPart($block),
        ecdKeyPart($floor),
        ecdKeyPart($number),
    ]);
}

function ecdLoadProjectTypeMap()
{
    $map = [];
    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        ['IBLOCK_ID' => PRODUCT_IBLOCK_ID, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', 'IBLOCK_ID']
    );
    while ($ob = $res->GetNextElement()) {
        $props = $ob->GetProperties();
        $project = trim((string)($props['__VO9RG4']['VALUE'] ?? ''));
        $type = trim((string)($props['__X1GCRZ']['VALUE'] ?? ''));
        if ($project === '' || $type === '') {
            continue;
        }
        if (!isset($map[$project])) {
            $map[$project] = [];
        }
        $map[$project][$type] = true;
    }
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($map as $project => $types) {
        $keys = array_keys($types);
        natcasesort($keys);
        $map[$project] = array_values($keys);
    }
    return $map;
}

function ecdLoadWonDealsIndex($project, $type)
{
    $index = [];
    $select = [
        'ID', 'TITLE', 'STAGE_ID',
        D_PROJECT, D_TYPE, D_SECTOR, D_BLOCK, D_FLOOR, D_NUMBER, D_CONTRACT_DATE,
    ];
    $filter = [
        'STAGE_SEMANTIC_ID' => 'S',
        'CHECK_PERMISSIONS' => 'N',
    ];

    $res = CCrmDeal::GetListEx(['ID' => 'ASC'], $filter, false, false, $select);
    while ($deal = $res->Fetch()) {
        if (!ecdValsEqual(ecdExtractField($deal[D_PROJECT] ?? ''), $project)) {
            continue;
        }
        if (!ecdValsEqual(ecdExtractField($deal[D_TYPE] ?? ''), $type)) {
            continue;
        }

        $key = ecdBuildDealKey(
            ecdExtractField($deal[D_SECTOR] ?? ''),
            ecdExtractField($deal[D_BLOCK] ?? ''),
            ecdExtractField($deal[D_FLOOR] ?? ''),
            ecdExtractField($deal[D_NUMBER] ?? '')
        );
        if ($key === '|||') {
            continue;
        }
        if (!isset($index[$key])) {
            $index[$key] = [];
        }
        $index[$key][] = $deal;
    }

    return $index;
}

/**
 * Excel თარიღი → Y-m-d (ან null).
 */
function ecdParseDate($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }

    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $serial = (float)$value;
        if ($serial > 20000 && $serial < 80000) {
            $unix = (int)(($serial - 25569) * 86400);
            if ($unix > 0) {
                return gmdate('Y-m-d', $unix);
            }
        }
    }

    $text = trim((string)$value);
    $text = str_replace(["\xc2\xa0"], '', $text);
    if ($text === '') {
        return null;
    }

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:\s+\d{1,2}:\d{1,2}(?::\d{1,2})?)?$/', $text, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        return null;
    }

    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $text, $m)) {
        $d = (int)$m[1];
        $mo = (int)$m[2];
        $y = (int)$m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        return null;
    }

    $ts = strtotime($text);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return null;
}

function ecdFormatBitrixDate($ymd)
{
    if ($ymd === null || $ymd === '') {
        return '';
    }
    $shortFormat = CSite::GetDateFormat('SHORT');
    $formatted = CDatabase::FormatDate($ymd, 'YYYY-MM-DD', $shortFormat);
    if ($formatted) {
        return $formatted;
    }
    $parts = explode('-', $ymd);
    if (count($parts) !== 3) {
        return '';
    }
    return sprintf('%02d/%02d/%04d', (int)$parts[2], (int)$parts[1], (int)$parts[0]);
}

function ecdNormalizeStoredDate($value)
{
    $raw = ecdExtractField($value);
    if ($raw === '') {
        return null;
    }
    return ecdParseDate($raw);
}

function ecdRowIsEmptyId(array $row)
{
    return trim((string)($row[0] ?? '')) === '' && trim((string)($row[1] ?? '')) === '';
}

function ecdRowIsEmptyParams(array $row)
{
    for ($i = 0; $i <= 4; $i++) {
        if (trim((string)($row[$i] ?? '')) !== '') {
            return false;
        }
    }
    return true;
}

/**
 * დილზე თარიღის ჩაწერა + ვერიფიკაცია.
 */
function ecdApplyContractDate($dealId, $ymd, $bitrixDate, &$error = '')
{
    $error = '';
    $deal = new CCrmDeal(false);
    $fields = [D_CONTRACT_DATE => $bitrixDate];
    $ok = $deal->Update($dealId, $fields);
    if (!$ok) {
        $error = (string)$deal->LAST_ERROR;
        return false;
    }

    $verify = CCrmDeal::GetListEx(
        [],
        ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['nTopCount' => 1],
        ['ID', D_CONTRACT_DATE]
    )->Fetch();
    $afterYmd = ecdNormalizeStoredDate($verify[D_CONTRACT_DATE] ?? '');
    if ($afterYmd === $ymd) {
        return true;
    }

    $error = 'Update OK, მაგრამ ველი არ ჩაიწერა (after='
        . ($afterYmd ?: 'ცარიელი')
        . ', sent=' . $bitrixDate . ')';
    return false;
}

$projectTypeMap = ecdLoadProjectTypeMap();
$results = [];
$counts = [
    'rows' => 0,
    'missing_id' => 0,
    'missing_fields' => 0,
    'invalid_date' => 0,
    'not_found' => 0,
    'ambiguous' => 0,
    'already_same' => 0,
    'would_update' => 0,
    'updated' => 0,
    'failed' => 0,
];
$fileError = '';
$cachedFileName = $_SESSION['ecd_cached_name'] ?? '';
$dealIndexCount = 0;

$statusLabels = [
    'missing_id' => 'აკლია ID',
    'missing_fields' => 'აკლია ველი',
    'invalid_date' => 'არასწორი თარიღი',
    'not_found' => 'დილი ვერ მოიძებნა',
    'ambiguous' => 'რამდენიმე დილი',
    'already_same' => 'უკვე ემთხვევა',
    'would_update' => 'განახლდება',
    'updated' => 'განახლდა',
    'failed' => 'შეცდომა',
];

if ($run) {
    if ($mode === 'by_params' && ($filterProject === '' || $filterType === '')) {
        $fileError = 'პარამეტრებით ძებნისთვის აირჩიე პროექტი და ფართის ტიპი.';
    } else {
        $cachedPath = ecdCacheUploadedFile();
        if (!$cachedPath) {
            $fileError = 'ატვირთე Excel ფაილი (.xlsx ან .csv).';
        } else {
            $cachedFileName = $_SESSION['ecd_cached_name'] ?? basename($cachedPath);
            $parseError = '';
            $rows = ecdParseExcelFile($cachedPath, $parseError);
            if (!$rows) {
                $fileError = $parseError !== ''
                    ? $parseError
                    : 'ფაილი ვერ წაიკითხა. დარწმუნდი რომ .xlsx ან .csv ფორმატშია.';
            } elseif ($mode === 'by_id') {
                foreach ($rows as $i => $row) {
                    if ($i === 0) {
                        continue;
                    }
                    if (ecdRowIsEmptyId($row)) {
                        continue;
                    }

                    $counts['rows']++;
                    $excelRow = $i + 1;
                    $rawId = trim((string)($row[0] ?? ''));
                    $rawDate = $row[1] ?? '';
                    $dealId = (int)preg_replace('/[^\d]/', '', $rawId);
                    $ymd = ecdParseDate($rawDate);
                    $bitrixDate = ecdFormatBitrixDate($ymd);

                    $item = [
                        'excel_row' => $excelRow,
                        'mode' => 'by_id',
                        'deal_id' => $dealId > 0 ? $dealId : null,
                        'date_raw' => is_scalar($rawDate) ? (string)$rawDate : '',
                        'date_ymd' => $ymd,
                        'date_bitrix' => $bitrixDate,
                        'date_before' => null,
                        'deal_title' => '',
                        'status' => '',
                        'error' => '',
                    ];

                    if ($dealId <= 0) {
                        $item['status'] = 'missing_id';
                        $counts['missing_id']++;
                        $results[] = $item;
                        continue;
                    }

                    if ($ymd === null) {
                        $item['status'] = 'invalid_date';
                        $counts['invalid_date']++;
                        $results[] = $item;
                        continue;
                    }

                    $existing = CCrmDeal::GetListEx(
                        [],
                        ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
                        false,
                        ['nTopCount' => 1],
                        ['ID', 'TITLE', D_CONTRACT_DATE]
                    )->Fetch();

                    if (!$existing) {
                        $item['status'] = 'not_found';
                        $counts['not_found']++;
                        $results[] = $item;
                        continue;
                    }

                    $item['deal_title'] = (string)($existing['TITLE'] ?? '');
                    $beforeYmd = ecdNormalizeStoredDate($existing[D_CONTRACT_DATE] ?? '');
                    $item['date_before'] = $beforeYmd;

                    if ($beforeYmd === $ymd) {
                        $item['status'] = 'already_same';
                        $counts['already_same']++;
                        $results[] = $item;
                        continue;
                    }

                    if (!$apply) {
                        $item['status'] = 'would_update';
                        $counts['would_update']++;
                        $results[] = $item;
                        continue;
                    }

                    $err = '';
                    if (ecdApplyContractDate($dealId, $ymd, $bitrixDate, $err)) {
                        $item['status'] = 'updated';
                        $counts['updated']++;
                    } else {
                        $item['status'] = 'failed';
                        $item['error'] = $err;
                        $counts['failed']++;
                    }
                    $results[] = $item;
                }
            } else {
                // by_params
                $dealIndex = ecdLoadWonDealsIndex($filterProject, $filterType);
                foreach ($dealIndex as $bucket) {
                    $dealIndexCount += count($bucket);
                }

                foreach ($rows as $i => $row) {
                    if ($i === 0) {
                        continue;
                    }
                    if (ecdRowIsEmptyParams($row)) {
                        continue;
                    }

                    $counts['rows']++;
                    $excelRow = $i + 1;
                    $sector = trim((string)($row[0] ?? ''));
                    $block = trim((string)($row[1] ?? ''));
                    $floor = trim((string)($row[2] ?? ''));
                    $number = trim((string)($row[3] ?? ''));
                    $rawDate = $row[4] ?? '';
                    $ymd = ecdParseDate($rawDate);
                    $bitrixDate = ecdFormatBitrixDate($ymd);

                    $item = [
                        'excel_row' => $excelRow,
                        'mode' => 'by_params',
                        'sector' => $sector,
                        'block' => $block,
                        'floor' => $floor,
                        'number' => $number,
                        'date_raw' => is_scalar($rawDate) ? (string)$rawDate : '',
                        'date_ymd' => $ymd,
                        'date_bitrix' => $bitrixDate,
                        'date_before' => null,
                        'deal_id' => null,
                        'deal_title' => '',
                        'status' => '',
                        'error' => '',
                        'deals' => [],
                    ];

                    $required = [
                        'სექტორი' => $sector,
                        'ბლოკი' => $block,
                        'სართული' => $floor,
                        'ნომერი' => $number,
                    ];
                    $missing = [];
                    foreach ($required as $name => $val) {
                        if (trim((string)$val) === '') {
                            $missing[] = $name;
                        }
                    }
                    if ($missing) {
                        $item['status'] = 'missing_fields';
                        $item['error'] = implode(', ', $missing);
                        $counts['missing_fields']++;
                        $results[] = $item;
                        continue;
                    }

                    if ($ymd === null) {
                        $item['status'] = 'invalid_date';
                        $counts['invalid_date']++;
                        $results[] = $item;
                        continue;
                    }

                    $key = ecdBuildDealKey($sector, $block, $floor, $number);
                    $matches = $dealIndex[$key] ?? [];

                    if (count($matches) === 0) {
                        $item['status'] = 'not_found';
                        $counts['not_found']++;
                        $results[] = $item;
                        continue;
                    }

                    if (count($matches) > 1) {
                        $item['status'] = 'ambiguous';
                        $item['deals'] = array_map(static function ($deal) {
                            return [
                                'id' => (int)$deal['ID'],
                                'title' => $deal['TITLE'] ?? '',
                            ];
                        }, $matches);
                        $counts['ambiguous']++;
                        $results[] = $item;
                        continue;
                    }

                    $existing = $matches[0];
                    $dealId = (int)$existing['ID'];
                    $item['deal_id'] = $dealId;
                    $item['deal_title'] = (string)($existing['TITLE'] ?? '');
                    $beforeYmd = ecdNormalizeStoredDate($existing[D_CONTRACT_DATE] ?? '');
                    $item['date_before'] = $beforeYmd;

                    if ($beforeYmd === $ymd) {
                        $item['status'] = 'already_same';
                        $counts['already_same']++;
                        $results[] = $item;
                        continue;
                    }

                    if (!$apply) {
                        $item['status'] = 'would_update';
                        $counts['would_update']++;
                        $results[] = $item;
                        continue;
                    }

                    $err = '';
                    if (ecdApplyContractDate($dealId, $ymd, $bitrixDate, $err)) {
                        $item['status'] = 'updated';
                        $counts['updated']++;
                    } else {
                        $item['status'] = 'failed';
                        $item['error'] = $err;
                        $counts['failed']++;
                    }
                    $results[] = $item;
                }
            }
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Excel → ხელშეკრულების თარიღი</title>
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
        .wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
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
        select, button, input[type="file"] {
            height: 40px;
            border-radius: 8px;
            font-size: 14px;
        }
        select, input[type="file"] {
            min-width: 220px;
            border: 1px solid #d5dce3;
            padding: 0 10px;
            background: #fff;
            color: var(--text);
        }
        input[type="file"] { padding-top: 8px; min-width: 260px; }
        .check { display: flex; align-items: center; gap: 8px; height: 40px; font-size: 14px; }
        button {
            border: 0;
            padding: 0 18px;
            background: var(--text);
            color: #fff;
            cursor: pointer;
            font-weight: 600;
        }
        button.apply { background: var(--accent); }
        .mode-switch {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .mode-switch label.mode-opt {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            padding: 10px 14px;
            border: 1px solid #d5dce3;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            background: #fff;
        }
        .mode-switch label.mode-opt.active {
            border-color: var(--text);
            background: #eef3f7;
        }
        .mode-switch input { margin: 0; }
        .howto {
            background: #f7fafc;
            border-left: 3px solid var(--text);
            padding: 12px 14px;
            margin: 0 0 16px;
            font-size: 13px;
            line-height: 1.55;
            color: var(--text);
        }
        .howto ul { margin: 8px 0 0; padding-left: 18px; }
        .howto li { margin: 4px 0; }
        .howto .note { color: var(--muted); margin-top: 8px; }
        .counts { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        .chip {
            background: #eef3f7;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 13px;
        }
        .chip.bad { background: #fdecea; color: var(--danger); }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef1f4; vertical-align: top; }
        th { font-size: 12px; color: var(--muted); white-space: nowrap; }
        .st-would_update, .st-updated, .st-already_same { color: var(--accent); font-weight: 600; }
        .st-not_found, .st-missing_id, .st-missing_fields, .st-invalid_date,
        .st-failed, .st-ambiguous { color: var(--danger); font-weight: 600; }
        .warn, .error { color: var(--danger); font-size: 13px; margin-top: 8px; }
        .muted { color: var(--muted); font-size: 12px; }
        .table-wrap { overflow-x: auto; }
        code { background: #eef3f7; padding: 1px 6px; border-radius: 4px; font-size: 12px; }
        .params-only { display: none; }
        body.mode-by_params .params-only { display: block; }
        body.mode-by_params .params-only.inline { display: flex; }
    </style>
</head>
<body class="mode-<?= htmlspecialchars($mode) ?>">
<div class="wrap">
    <h1>Excel → ხელშეკრულების გაფორმების თარიღი</h1>
    <p class="sub">
        განახლდება ველი <code><?= htmlspecialchars(D_CONTRACT_DATE) ?></code>
        (ხელშეკრულების გაფორმების თარიღი (მონოლითი)).
        აირჩიე რეჟიმი, ატვირთე შესაბამისი Excel, ჯერ «ნახვა», შემდეგ «რეალურად განახლება».
    </p>

    <form class="card" method="post" enctype="multipart/form-data" id="date-form">
        <input type="hidden" name="run" value="1">

        <div class="mode-switch">
            <label class="mode-opt <?= $mode === 'by_id' ? 'active' : '' ?>">
                <input type="radio" name="mode" value="by_id" <?= $mode === 'by_id' ? 'checked' : '' ?>>
                დილის ID-ით
            </label>
            <label class="mode-opt <?= $mode === 'by_params' ? 'active' : '' ?>">
                <input type="radio" name="mode" value="by_params" <?= $mode === 'by_params' ? 'checked' : '' ?>>
                უძრავი ქონების პარამეტრებით
            </label>
        </div>

        <div class="howto" id="howto-by_id" style="<?= $mode === 'by_id' ? '' : 'display:none' ?>">
            <strong>რეჟიმი: დილის ID</strong>
            <ul>
                <li><strong>A</strong> — დილის ID (მაგ. 67941)</li>
                <li><strong>B</strong> — ხელშეკრულების გაფორმების თარიღი</li>
            </ul>
            <div class="note">პირველი სტრიქონი header-ია და იგნორირდება. თარიღი: DD/MM/YYYY ან YYYY-MM-DD.</div>
        </div>

        <div class="howto" id="howto-by_params" style="<?= $mode === 'by_params' ? '' : 'display:none' ?>">
            <strong>რეჟიმი: უძრავი ქონების პარამეტრები</strong>
            <ul>
                <li>ჯერ აირჩიე <strong>პროექტი</strong> და <strong>ფართის ტიპი</strong> (ფორმაში ქვემოთ)</li>
                <li><strong>A</strong> — სექტორი</li>
                <li><strong>B</strong> — ბლოკი</li>
                <li><strong>C</strong> — სართული</li>
                <li><strong>D</strong> — ნომერი</li>
                <li><strong>E</strong> — ხელშეკრულების გაფორმების თარიღი</li>
            </ul>
            <div class="note">
                მატჩი: პროექტი + ტიპი + სექტორი + ბლოკი + სართული + ნომერი.
                იძებნება მხოლოდ <strong>WON</strong> დილებში (იგივე ლოგიკა რაც ფასების მიგრაციაში).
                თუ რამდენიმე დილი დაემთხვა — არ განახლდება .
            </div>
        </div>

        <div class="row">
            <div class="params-only <?= $mode === 'by_params' ? 'inline' : '' ?>" id="project-wrap" style="gap:16px;flex-wrap:wrap;align-items:flex-end;">
                <div>
                    <label for="project">პროექტი</label>
                    <select name="project" id="project">
                        <option value="">— აირჩიე —</option>
                        <?php foreach (array_keys($projectTypeMap) as $projectName): ?>
                            <option value="<?= htmlspecialchars($projectName) ?>" <?= $filterProject === $projectName ? 'selected' : '' ?>>
                                <?= htmlspecialchars($projectName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="type">ფართის ტიპი</label>
                    <select name="type" id="type">
                        <option value="">— ჯერ აირჩიე პროექტი —</option>
                    </select>
                </div>
            </div>
            <div>
                <label for="datafile">Excel ფაილი</label>
                <input type="file" name="datafile" id="datafile" accept=".xlsx,.xls,.csv">
                <?php if ($cachedFileName !== ''): ?>
                    <div class="muted">კეშში: <?= htmlspecialchars($cachedFileName) ?> (თავიდან ატვირთვა შეცვლის)</div>
                <?php endif; ?>
            </div>
            <label class="check">
                <input type="checkbox" name="apply" value="1" <?= $apply ? 'checked' : '' ?>>
                რეალურად განახლება
            </label>
            <button type="submit" id="run-btn">ნახვა</button>
        </div>
        <p class="warn" id="apply-warn" style="<?= $apply ? '' : 'display:none' ?>">
            Apply ჩართულია: CRM-ში ჩაიწერება თარიღი ველში «ხელშეკრულების გაფორმების თარიღი (მონოლითი)».
        </p>
        <?php if ($fileError !== ''): ?>
            <p class="error"><?= htmlspecialchars($fileError) ?></p>
        <?php endif; ?>
    </form>

    <?php if ($run && $fileError === ''): ?>
        <div class="card">
            <div class="counts">
                <span class="chip">რეჟიმი: <?= $mode === 'by_id' ? 'ID' : 'პარამეტრები' ?> / <?= $apply ? 'APPLY' : 'DRY RUN' ?></span>
                <?php if ($mode === 'by_params'): ?>
                    <span class="chip"><?= htmlspecialchars($filterProject) ?> / <?= htmlspecialchars($filterType) ?></span>
                    <span class="chip">WON ინდექსი: <?= (int)$dealIndexCount ?></span>
                <?php endif; ?>
                <span class="chip">სტრიქონები: <?= (int)$counts['rows'] ?></span>
                <span class="chip">განახლდება: <?= (int)$counts['would_update'] ?></span>
                <span class="chip">განახლდა: <?= (int)$counts['updated'] ?></span>
                <span class="chip">უკვე ემთხვევა: <?= (int)$counts['already_same'] ?></span>
                <span class="chip bad">ვერ მოიძებნა: <?= (int)$counts['not_found'] ?></span>
                <?php if ($mode === 'by_params'): ?>
                    <span class="chip bad">რამდენიმე დილი: <?= (int)$counts['ambiguous'] ?></span>
                    <span class="chip bad">აკლია ველი: <?= (int)$counts['missing_fields'] ?></span>
                <?php else: ?>
                    <span class="chip bad">აკლია ID: <?= (int)$counts['missing_id'] ?></span>
                <?php endif; ?>
                <span class="chip bad">არასწორი თარიღი: <?= (int)$counts['invalid_date'] ?></span>
                <span class="chip bad">შეცდომა: <?= (int)$counts['failed'] ?></span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Excel #</th>
                        <th>სტატუსი</th>
                        <?php if ($mode === 'by_params'): ?>
                            <th>სექტორი / ბლოკი / სართული / ნომერი</th>
                        <?php endif; ?>
                        <th>დილი</th>
                        <th>თარიღი (ძვ. → ახ.)</th>
                        <th>Excel raw</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $row):
                        $st = $row['status'] ?? '';
                    ?>
                        <tr>
                            <td><?= (int)($row['excel_row'] ?? 0) ?></td>
                            <td class="st-<?= htmlspecialchars($st) ?>">
                                <?= htmlspecialchars($statusLabels[$st] ?? $st) ?>
                                <?php if (!empty($row['error'])): ?>
                                    <div class="muted"><?= htmlspecialchars((string)$row['error']) ?></div>
                                <?php endif; ?>
                            </td>
                            <?php if ($mode === 'by_params'): ?>
                                <td>
                                    <?= htmlspecialchars((string)($row['sector'] ?? '')) ?>
                                    / <?= htmlspecialchars((string)($row['block'] ?? '')) ?>
                                    / <?= htmlspecialchars((string)($row['floor'] ?? '')) ?>
                                    / <?= htmlspecialchars((string)($row['number'] ?? '')) ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <?php if (!empty($row['deal_id'])): ?>
                                    <a href="/crm/deal/details/<?= (int)$row['deal_id'] ?>/" target="_blank">
                                        #<?= (int)$row['deal_id'] ?>
                                    </a>
                                    <div><?= htmlspecialchars((string)($row['deal_title'] ?? '')) ?></div>
                                <?php elseif (!empty($row['deals'])): ?>
                                    <?php foreach ($row['deals'] as $d): ?>
                                        <div>
                                            <a href="/crm/deal/details/<?= (int)$d['id'] ?>/" target="_blank">
                                                #<?= (int)$d['id'] ?>
                                            </a>
                                            <?= htmlspecialchars((string)($d['title'] ?? '')) ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= !empty($row['date_before']) ? htmlspecialchars($row['date_before']) : '—' ?>
                                →
                                <?= !empty($row['date_ymd']) ? htmlspecialchars($row['date_ymd']) : '—' ?>
                                <?php if (!empty($row['date_bitrix'])): ?>
                                    <div class="muted">Bitrix: <?= htmlspecialchars($row['date_bitrix']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="muted"><?= htmlspecialchars((string)($row['date_raw'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
    const map = <?= json_encode($projectTypeMap, JSON_UNESCAPED_UNICODE) ?>;
    const selectedType = <?= json_encode($filterType, JSON_UNESCAPED_UNICODE) ?>;
    const projectEl = document.getElementById('project');
    const typeEl = document.getElementById('type');
    const applyBox = document.querySelector('input[name="apply"]');
    const runBtn = document.getElementById('run-btn');
    const applyWarn = document.getElementById('apply-warn');
    const modeRadios = document.querySelectorAll('input[name="mode"]');
    const howtoId = document.getElementById('howto-by_id');
    const howtoParams = document.getElementById('howto-by_params');
    const projectWrap = document.getElementById('project-wrap');

    function fillTypes() {
        const project = projectEl.value;
        const types = map[project] || [];
        typeEl.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = types.length ? '— აირჩიე —' : '— ჯერ აირჩიე პროექტი —';
        typeEl.appendChild(placeholder);
        types.forEach(function (t) {
            const opt = document.createElement('option');
            opt.value = t;
            opt.textContent = t;
            if (t === selectedType) opt.selected = true;
            typeEl.appendChild(opt);
        });
    }

    function syncModeUi() {
        const mode = document.querySelector('input[name="mode"]:checked').value;
        document.body.classList.remove('mode-by_id', 'mode-by_params');
        document.body.classList.add('mode-' + mode);
        howtoId.style.display = mode === 'by_id' ? '' : 'none';
        howtoParams.style.display = mode === 'by_params' ? '' : 'none';
        projectWrap.style.display = mode === 'by_params' ? 'flex' : 'none';
        projectEl.required = mode === 'by_params';
        typeEl.required = mode === 'by_params';
        document.querySelectorAll('.mode-opt').forEach(function (el) {
            el.classList.toggle('active', el.querySelector('input').checked);
        });
    }

    function syncApplyUi() {
        const on = applyBox.checked;
        runBtn.textContent = on ? 'გაშვება' : 'ნახვა';
        runBtn.classList.toggle('apply', on);
        applyWarn.style.display = on ? '' : 'none';
    }

    modeRadios.forEach(function (r) {
        r.addEventListener('change', syncModeUi);
    });
    projectEl.addEventListener('change', fillTypes);
    applyBox.addEventListener('change', syncApplyUi);
    fillTypes();
    syncModeUi();
    syncApplyUi();
</script>
</body>
</html>
