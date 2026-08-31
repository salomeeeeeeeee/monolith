<?php
/**
 * Excel-იდან WON დილების ფასების განახლება.
 * A=სექტორი, B=ბლოკი, C=სართული, D=ნომერი, E=სრული ფართი,
 * F=კვ.მ ფასი, G=სრული ფასი (OPPORTUNITY)
 *
 * UI: https://crm.monolith.ge/crm/deal/migration/excelPricesToDeals.php
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

CModule::IncludeModule('crm');
CModule::IncludeModule('iblock');
CModule::IncludeModule('catalog');

define('PRODUCT_IBLOCK_ID', 14);
define('D_PROJECT', 'UF_CRM_1779277729207');
define('D_TYPE', 'UF_CRM_1779277898205');
define('D_SECTOR', 'UF_CRM_1781768590754');
define('D_BLOCK', 'UF_CRM_1779277644355');
define('D_FLOOR', 'UF_CRM_1779277828822');
define('D_NUMBER', 'UF_CRM_1779277613798');
define('D_AREA', 'UF_CRM_1779277886804');
define('D_KVM_PRICE', 'UF_CRM_1779277671391');

$run = isset($_REQUEST['run']) && $_REQUEST['run'] === '1';
$apply = isset($_REQUEST['apply']) && $_REQUEST['apply'] === '1';
$updateArea = isset($_REQUEST['update_area']) && $_REQUEST['update_area'] === '1';
$filterProject = trim((string)($_REQUEST['project'] ?? ''));
$filterType = trim((string)($_REQUEST['type'] ?? ''));

function epdNormVal($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    $value = trim((string)$value);
    $value = str_replace(["\xc2\xa0", ' '], '', $value);
    $value = str_replace(',', '.', $value);
    return mb_strtolower($value);
}

function epdNumVal($value)
{
    $value = epdNormVal($value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return round((float)$value, 4);
}

function epdValsEqual($a, $b)
{
    $na = epdNumVal($a);
    $nb = epdNumVal($b);
    if ($na !== null && $nb !== null) {
        return abs($na - $nb) < 0.0001;
    }
    return epdNormVal($a) === epdNormVal($b);
}

function epdParseArea($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    $text = str_replace(["\xc2\xa0", ' '], '', $text);
    $text = str_replace(',', '.', $text);
    if (!is_numeric($text)) {
        return null;
    }
    return round((float)$text, 4);
}

function epdParsePrice($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    $text = str_replace(["\xc2\xa0", ' ', '$', '₾', '€'], '', $text);
    if (strpos($text, '|') !== false) {
        $parts = explode('|', $text);
        foreach ($parts as $part) {
            $part = trim(str_replace(',', '', $part));
            if ($part !== '' && is_numeric($part)) {
                return round((float)$part, 2);
            }
        }
    }
    $text = str_replace(',', '', $text);
    if ($text === '' || !is_numeric($text)) {
        return null;
    }
    return round((float)$text, 2);
}

function epdLoadProjectTypeMap()
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

function epdColLettersToIndex($letters)
{
    $letters = strtoupper($letters);
    $index = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - 64);
    }
    return $index - 1;
}

function epdParseCellRef($ref)
{
    if (!preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) {
        return [0, 0];
    }
    return [epdColLettersToIndex($m[1]), (int)$m[2] - 1];
}

function epdParseXlsxNative($filePath)
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

            [$colIndex, $parsedRow] = epdParseCellRef($ref);
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

function epdParseXlsxSimple($filePath)
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

function epdParseXlsxPhpSpreadsheet($filePath)
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

function epdParseCsvFile($filePath)
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

function epdParseExcelFile($filePath, &$error = '')
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
            'PhpSpreadsheet' => 'epdParseXlsxPhpSpreadsheet',
            'SimpleXLSX' => 'epdParseXlsxSimple',
            'ZipArchive' => 'epdParseXlsxNative',
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

    $rows = epdParseCsvFile($filePath);
    if (!$rows) {
        $error = 'csv ვერ წაიკითხა.';
    }
    return $rows;
}

function epdCacheUploadedFile()
{
    $cacheDir = $_SERVER['DOCUMENT_ROOT'] . '/upload/tmp_excel_prices_cache/';
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
            $_SESSION['epd_cached_file'] = $cachedPath;
            $_SESSION['epd_cached_name'] = $origName;
            return $cachedPath;
        }
    }

    if (!empty($_SESSION['epd_cached_file']) && file_exists($_SESSION['epd_cached_file'])) {
        return $_SESSION['epd_cached_file'];
    }

    return null;
}

function epdKeyPart($value)
{
    $num = epdNumVal($value);
    if ($num !== null) {
        return rtrim(rtrim(sprintf('%.4f', $num), '0'), '.');
    }
    return epdNormVal($value);
}

function epdBuildDealKey($sector, $block, $floor, $number)
{
    return implode('|', [
        epdKeyPart($sector),
        epdKeyPart($block),
        epdKeyPart($floor),
        epdKeyPart($number),
    ]);
}

function epdExtractField($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    return trim((string)$value);
}

function epdLoadWonDealsIndex($project, $type)
{
    $index = [];
    $select = [
        'ID', 'TITLE', 'STAGE_ID', 'CATEGORY_ID',
        'OPPORTUNITY', 'CURRENCY_ID', 'IS_MANUAL_OPPORTUNITY',
        D_PROJECT, D_TYPE, D_SECTOR, D_BLOCK, D_FLOOR, D_NUMBER, D_AREA, D_KVM_PRICE,
    ];
    $filter = [
        'STAGE_SEMANTIC_ID' => 'S',
        'CHECK_PERMISSIONS' => 'N',
    ];

    $res = CCrmDeal::GetListEx(['ID' => 'ASC'], $filter, false, false, $select);
    while ($deal = $res->Fetch()) {
        if (!epdValsEqual(epdExtractField($deal[D_PROJECT] ?? ''), $project)) {
            continue;
        }
        if (!epdValsEqual(epdExtractField($deal[D_TYPE] ?? ''), $type)) {
            continue;
        }

        $key = epdBuildDealKey(
            epdExtractField($deal[D_SECTOR] ?? ''),
            epdExtractField($deal[D_BLOCK] ?? ''),
            epdExtractField($deal[D_FLOOR] ?? ''),
            epdExtractField($deal[D_NUMBER] ?? '')
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

function epdRowIsEmpty(array $row)
{
    for ($i = 0; $i <= 6; $i++) {
        if (trim((string)($row[$i] ?? '')) !== '') {
            return false;
        }
    }
    return true;
}

$projectTypeMap = epdLoadProjectTypeMap();
$results = [];
$counts = [
    'rows' => 0,
    'missing_fields' => 0,
    'invalid_prices' => 0,
    'invalid_area' => 0,
    'not_found' => 0,
    'ambiguous' => 0,
    'already_same' => 0,
    'would_update' => 0,
    'updated' => 0,
    'failed' => 0,
];
$fileError = '';
$cachedFileName = $_SESSION['epd_cached_name'] ?? '';
$dealIndexCount = 0;

if ($run) {
    if ($filterProject === '' || $filterType === '') {
        $fileError = 'აირჩიე პროექტი და ფართის ტიპი.';
    } else {
        $cachedPath = epdCacheUploadedFile();
        if (!$cachedPath) {
            $fileError = 'ატვირთე Excel ფაილი (.xlsx ან .csv).';
        } else {
            $cachedFileName = $_SESSION['epd_cached_name'] ?? basename($cachedPath);
            $parseError = '';
            $rows = epdParseExcelFile($cachedPath, $parseError);
            if (!$rows) {
                $fileError = $parseError !== ''
                    ? $parseError
                    : 'ფაილი ვერ წაიკითხა. დარწმუნდი რომ .xlsx ან .csv ფორმატშია.';
            } else {
                $dealIndex = epdLoadWonDealsIndex($filterProject, $filterType);
                foreach ($dealIndex as $bucket) {
                    $dealIndexCount += count($bucket);
                }

                foreach ($rows as $i => $row) {
                    if ($i === 0) {
                        continue;
                    }
                    if (epdRowIsEmpty($row)) {
                        continue;
                    }

                    $counts['rows']++;
                    $excelRow = $i + 1;
                    $sector = trim((string)($row[0] ?? ''));
                    $block = trim((string)($row[1] ?? ''));
                    $floor = trim((string)($row[2] ?? ''));
                    $number = trim((string)($row[3] ?? ''));
                    $area = trim((string)($row[4] ?? ''));
                    $areaExcel = epdParseArea($area);
                    $kvmExcel = epdParsePrice($row[5] ?? '');
                    if ($kvmExcel === null) {
                        $kvmExcel = 0.0;
                    }
                    $oppExcel = epdParsePrice($row[6] ?? '');

                    $resultRow = [
                        'excel_row' => $excelRow,
                        'sector' => $sector,
                        'block' => $block,
                        'floor' => $floor,
                        'number' => $number,
                        'area' => $area,
                        'area_excel' => $areaExcel,
                        'kvm_excel' => $kvmExcel,
                        'opportunity_excel' => $oppExcel,
                        'status' => '',
                    ];

                    $required = [
                        'sector' => $sector,
                        'block' => $block,
                        'floor' => $floor,
                        'number' => $number,
                    ];
                    $missing = [];
                    foreach ($required as $name => $val) {
                        if (trim((string)$val) === '') {
                            $missing[] = $name;
                        }
                    }
                    if ($missing) {
                        $resultRow['status'] = 'missing_fields';
                        $resultRow['missing'] = $missing;
                        $counts['missing_fields']++;
                        $results[] = $resultRow;
                        continue;
                    }

                    if ($oppExcel === null || $oppExcel <= 0) {
                        $resultRow['status'] = 'invalid_prices';
                        $counts['invalid_prices']++;
                        $results[] = $resultRow;
                        continue;
                    }

                    if ($updateArea && $areaExcel === null) {
                        $resultRow['status'] = 'invalid_area';
                        $counts['invalid_area']++;
                        $results[] = $resultRow;
                        continue;
                    }

                    $key = epdBuildDealKey($sector, $block, $floor, $number);
                    $matches = $dealIndex[$key] ?? [];

                    if (count($matches) === 0) {
                        $resultRow['status'] = 'not_found';
                        $counts['not_found']++;
                        $results[] = $resultRow;
                        continue;
                    }

                    if (count($matches) > 1) {
                        $resultRow['status'] = 'ambiguous';
                        $resultRow['deals'] = array_map(static function ($deal) {
                            return [
                                'id' => (int)$deal['ID'],
                                'title' => $deal['TITLE'] ?? '',
                            ];
                        }, $matches);
                        $counts['ambiguous']++;
                        $results[] = $resultRow;
                        continue;
                    }

                    $deal = $matches[0];
                    $dealId = (int)$deal['ID'];
                    $oppBefore = round((float)($deal['OPPORTUNITY'] ?? 0), 2);
                    $kvmBefore = epdParsePrice($deal[D_KVM_PRICE] ?? '') ?? 0.0;
                    $areaBefore = epdParseArea($deal[D_AREA] ?? '');

                    $resultRow['deal_id'] = $dealId;
                    $resultRow['deal_title'] = $deal['TITLE'] ?? '';
                    $resultRow['opportunity_before'] = $oppBefore;
                    $resultRow['kvm_before'] = $kvmBefore;
                    $resultRow['area_before'] = $areaBefore;
                    $resultRow['currency'] = $deal['CURRENCY_ID'] ?? '';

                    $oppSame = abs($oppBefore - $oppExcel) < 0.01
                        && ($deal['IS_MANUAL_OPPORTUNITY'] ?? '') === 'Y';
                    $kvmSame = abs($kvmBefore - $kvmExcel) < 0.01;
                    $areaSame = !$updateArea
                        || ($areaBefore !== null && $areaExcel !== null && abs($areaBefore - $areaExcel) < 0.0001);

                    if ($oppSame && $kvmSame && $areaSame) {
                        $resultRow['status'] = 'already_same';
                        $counts['already_same']++;
                        $results[] = $resultRow;
                        continue;
                    }

                    if (!$apply) {
                        $resultRow['status'] = 'would_update';
                        $resultRow['opportunity_after'] = $oppExcel;
                        $resultRow['kvm_after'] = $kvmExcel;
                        if ($updateArea) {
                            $resultRow['area_after'] = $areaExcel;
                        }
                        $counts['would_update']++;
                        $results[] = $resultRow;
                        continue;
                    }

                    $crmDeal = new CCrmDeal(false);
                    $fieldsUpdate = [
                        'IS_MANUAL_OPPORTUNITY' => 'Y',
                        'OPPORTUNITY' => $oppExcel,
                        D_KVM_PRICE => $kvmExcel,
                    ];
                    if ($updateArea && $areaExcel !== null) {
                        $fieldsUpdate[D_AREA] = $areaExcel;
                    }
                    if (!empty($deal['CURRENCY_ID'])) {
                        $fieldsUpdate['CURRENCY_ID'] = $deal['CURRENCY_ID'];
                    }

                    $ok = $crmDeal->Update($dealId, $fieldsUpdate);
                    if (!$ok) {
                        $resultRow['status'] = 'failed';
                        $resultRow['error'] = $crmDeal->LAST_ERROR;
                        $counts['failed']++;
                        $results[] = $resultRow;
                        continue;
                    }

                    $verifySelect = ['ID', 'OPPORTUNITY', 'IS_MANUAL_OPPORTUNITY', D_KVM_PRICE];
                    if ($updateArea) {
                        $verifySelect[] = D_AREA;
                    }
                    $verifyRes = CCrmDeal::GetListEx(
                        [],
                        ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
                        false,
                        ['nTopCount' => 1],
                        $verifySelect
                    );
                    $verify = $verifyRes ? $verifyRes->Fetch() : false;

                    $resultRow['status'] = 'updated';
                    $resultRow['opportunity_after'] = round((float)($verify['OPPORTUNITY'] ?? 0), 2);
                    $resultRow['kvm_after'] = epdParsePrice($verify[D_KVM_PRICE] ?? '') ?? 0.0;
                    if ($updateArea) {
                        $resultRow['area_after'] = epdParseArea($verify[D_AREA] ?? '');
                    }
                    $counts['updated']++;
                    $results[] = $resultRow;
                }
            }
        }
    }
}

$statusLabels = [
    'missing_fields' => 'აკლია ველი',
    'invalid_prices' => 'არასწორი ფასი G',
    'invalid_area' => 'არასწორი ფართი E',
    'not_found' => 'დილი ვერ მოიძებნა',
    'ambiguous' => 'რამდენიმე დილი',
    'already_same' => 'უკვე ემთხვევა',
    'would_update' => 'განახლდება (dry run)',
    'updated' => 'განახლდა',
    'failed' => 'შეცდომა',
];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Excel → დილის ფასები</title>
    <style>
        :root {
            --bg: #f4f5f7;
            --panel: #fff;
            --text: #00335b;
            --muted: #6b7a8a;
            --accent: #72c4b1;
            --danger: #c0392b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 24px 16px 48px; }
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
        input[type="file"] { padding-top: 8px; }
        .check { display: flex; align-items: center; gap: 8px; height: 40px; font-size: 14px; }
        button {
            border: 0;
            padding: 0 18px;
            background: var(--text);
            color: #fff;
            cursor: pointer;
            font-weight: 600;
        }
        button.apply { background: #0e7c66; }
        button:disabled { opacity: .5; cursor: not-allowed; }
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
        .st-would_update, .st-updated, .st-already_same { color: #0e7c66; font-weight: 600; }
        .st-not_found, .st-missing_fields, .st-invalid_prices, .st-invalid_area, .st-failed, .st-ambiguous { color: var(--danger); font-weight: 600; }
        .warn, .error { color: var(--danger); font-size: 13px; margin-top: 8px; }
        .muted { color: var(--muted); font-size: 12px; }
        .table-wrap { overflow-x: auto; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Excel → WON დილის ფასები</h1>
    <p class="sub">
        აირჩიე პროექტი და ფართის ტიპი, ატვირთე Excel.
        სვეტები: A=სექტორი, B=ბლოკი, C=სართული, D=ნომერი, E=სრული ფართი, F=კვ.მ ფასი, G=სრული ფასი.
        მატჩი:პროექტი + ტიპი + სექტორი + ბლოკი + სართული + ნომერი (სრული ფართით არ ეძებს). ფართის დილზე განახლება შესაძლებელია მოპწიჭკვის შემდეგ.
        ჯერ «ნახვა», შემდეგ «რეალურად განახლება», განახლდება: კვ/მ ღირებულება $ და 
        ჯამური ფასი. შესაძლებელია "სრული ფართი" ს გაანხლებაც.
    </p>

    <form class="card" method="post" enctype="multipart/form-data" id="price-form">
        <input type="hidden" name="run" value="1">
        <div class="row">
            <div>
                <label for="project">პროექტი</label>
                <select name="project" id="project" required>
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
                <select name="type" id="type" required>
                    <option value="">— ჯერ აირჩიე პროექტი —</option>
                </select>
            </div>
            <div>
                <label for="datafile">Excel ფაილი</label>
                <input type="file" name="datafile" id="datafile" accept=".xlsx,.xls,.csv">
                <?php if ($cachedFileName !== ''): ?>
                    <div class="muted">კეშში: <?= htmlspecialchars($cachedFileName) ?> (თავიდან ატვირთვა შეცვლის)</div>
                <?php endif; ?>
            </div>
            <label class="check">
                <input type="checkbox" name="update_area" value="1" <?= $updateArea ? 'checked' : '' ?>>
                სრული ფართის განახლება (E → დილი)
            </label>
            <label class="check">
                <input type="checkbox" name="apply" value="1" <?= $apply ? 'checked' : '' ?>>
                რეალურად განახლება
            </label>
            <button type="submit" id="run-btn">ნახვა</button>
        </div>
        <p class="warn" id="apply-warn" style="<?= $apply ? '' : 'display:none' ?>">
            Apply ჩართულია: CRM-ში ჩაიწერება F (კვ.მ ფასი) და G (OPPORTUNITY)<span id="area-warn-extra"><?= $updateArea ? ', ასევე E (სრული ფართი)' : '' ?></span>.
        </p>
        <?php if ($fileError !== ''): ?>
            <p class="error"><?= htmlspecialchars($fileError) ?></p>
        <?php endif; ?>
    </form>

    <?php if ($run && $fileError === '' && ($filterProject !== '' && $filterType !== '')): ?>
        <div class="card">
            <div class="counts">
                <span class="chip">რეჟიმი: <?= $apply ? 'APPLY' : 'DRY RUN' ?></span>
                <span class="chip">ფართი: <?= $updateArea ? 'განახლდება' : 'არა' ?></span>
                <span class="chip"><?= htmlspecialchars($filterProject) ?> / <?= htmlspecialchars($filterType) ?></span>
                <span class="chip">WON დილები ინდექსში: <?= (int)$dealIndexCount ?></span>
                <span class="chip">სტრიქონები: <?= (int)$counts['rows'] ?></span>
                <span class="chip bad">ვერ მოიძებნა: <?= (int)$counts['not_found'] ?></span>
                <span class="chip bad">რამდენიმე დილი: <?= (int)$counts['ambiguous'] ?></span>
                <span class="chip">განახლდება: <?= (int)$counts['would_update'] ?></span>
                <span class="chip">განახლდა: <?= (int)$counts['updated'] ?></span>
                <span class="chip">უკვე ემთხვევა: <?= (int)$counts['already_same'] ?></span>
                <span class="chip bad">აკლია ველი: <?= (int)$counts['missing_fields'] ?></span>
                <span class="chip bad">არასწორი ფასი: <?= (int)$counts['invalid_prices'] ?></span>
                <span class="chip bad">არასწორი ფართი: <?= (int)$counts['invalid_area'] ?></span>
                <span class="chip bad">შეცდომა: <?= (int)$counts['failed'] ?></span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Excel #</th>
                        <th>სტატუსი</th>
                        <th>სექტორი / ბლოკი / სართული / ნომერი</th>
                        <th>დილი</th>
                        <?php if ($updateArea): ?>
                        <th>ფართი (ძვ. → ახ.)</th>
                        <?php endif; ?>
                        <th>კვ.მ (ძვ. → ახ.)</th>
                        <th>სრული (ძვ. → ახ.)</th>
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
                            <td>
                                <?= htmlspecialchars((string)($row['sector'] ?? '')) ?>
                                / <?= htmlspecialchars((string)($row['block'] ?? '')) ?>
                                / <?= htmlspecialchars((string)($row['floor'] ?? '')) ?>
                                / <?= htmlspecialchars((string)($row['number'] ?? '')) ?>
                                <?php if (!$updateArea && ($row['area'] ?? '') !== ''): ?>
                                    <div class="muted">Excel E: <?= htmlspecialchars((string)$row['area']) ?></div>
                                <?php endif; ?>
                            </td>
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
                            <?php if ($updateArea): ?>
                            <td>
                                <?php if (isset($row['area_before'])): ?>
                                    <?= $row['area_before'] !== null ? number_format((float)$row['area_before'], 2, '.', ',') : '—' ?>
                                    →
                                    <?= number_format((float)($row['area_after'] ?? $row['area_excel'] ?? 0), 2, '.', ',') ?>
                                <?php elseif ($row['area_excel'] !== null): ?>
                                    Excel: <?= number_format((float)$row['area_excel'], 2, '.', ',') ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <?php if (isset($row['kvm_before'])): ?>
                                    <?= number_format((float)$row['kvm_before'], 2, '.', ',') ?>
                                    →
                                    <?= number_format((float)($row['kvm_after'] ?? $row['kvm_excel'] ?? 0), 2, '.', ',') ?>
                                <?php elseif ($row['kvm_excel'] !== null): ?>
                                    Excel: <?= number_format((float)$row['kvm_excel'], 2, '.', ',') ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($row['opportunity_before'])): ?>
                                    <?= number_format((float)$row['opportunity_before'], 2, '.', ',') ?>
                                    →
                                    <?= number_format((float)($row['opportunity_after'] ?? $row['opportunity_excel'] ?? 0), 2, '.', ',') ?>
                                <?php elseif ($row['opportunity_excel'] !== null): ?>
                                    Excel: <?= number_format((float)$row['opportunity_excel'], 2, '.', ',') ?>
                                <?php else: ?>
                                    —
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
    const map = <?= json_encode($projectTypeMap, JSON_UNESCAPED_UNICODE) ?>;
    const projectEl = document.getElementById('project');
    const typeEl = document.getElementById('type');
    const selectedType = <?= json_encode($filterType, JSON_UNESCAPED_UNICODE) ?>;
    const applyBox = document.querySelector('input[name="apply"]');
    const areaBox = document.querySelector('input[name="update_area"]');
    const runBtn = document.getElementById('run-btn');
    const applyWarn = document.getElementById('apply-warn');
    const areaWarnExtra = document.getElementById('area-warn-extra');

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

    function syncApplyUi() {
        const on = applyBox.checked;
        runBtn.textContent = on ? 'გაშვება' : 'ნახვა';
        runBtn.classList.toggle('apply', on);
        applyWarn.style.display = on ? '' : 'none';
        if (areaWarnExtra) {
            areaWarnExtra.textContent = areaBox && areaBox.checked ? ', ასევე E (სრული ფართი)' : '';
        }
    }

    projectEl.addEventListener('change', fillTypes);
    applyBox.addEventListener('change', syncApplyUi);
    if (areaBox) {
        areaBox.addEventListener('change', syncApplyUi);
    }
    fillTypes();
    syncApplyUi();
</script>
</body>
</html>
