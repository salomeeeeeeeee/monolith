<?php
if (!defined('B_PROLOG_INCLUDED')) {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
}

CModule::IncludeModule('iblock');
CModule::IncludeModule('crm');

if (!function_exists('calcGetCIBlockElementsByFilter')) {
    function calcGetCIBlockElementsByFilter($arFilter, $arSelect = ['ID', 'IBLOCK_ID', 'NAME', 'DATE_ACTIVE_FROM', 'PROPERTY_*'], $arSort = ['ID' => 'ASC'], $count = 9999)
    {
        $arElements = [];
        $res = CIBlockElement::GetList($arSort, $arFilter, false, ['nPageSize' => $count], $arSelect);
        while ($ob = $res->GetNextElement()) {
            $arFields = $ob->GetFields();
            $arProps = $ob->GetProperties();
            $row = [];
            foreach ($arFields as $key => $val) {
                $row[$key] = $val;
            }
            foreach ($arProps as $key => $prop) {
                $row[$key] = $prop['VALUE'];
            }
            $arElements[] = $row;
        }
        return $arElements;
    }
}

if (!function_exists('calcGetElementByID')) {
    function calcGetElementByID($ID)
    {
        if (!$ID || !is_numeric($ID)) {
            return null;
        }
        $res = CIBlockElement::GetList([], ['ID' => $ID], false, ['nPageSize' => 1], ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_*']);
        if ($ob = $res->GetNextElement()) {
            $arFields = $ob->GetFields();
            $arProps = $ob->GetProperties();
            $row = [];
            foreach ($arFields as $key => $val) {
                $row[$key] = $val;
            }
            foreach ($arProps as $key => $prop) {
                $row[$key] = $prop['VALUE'];
            }
            return $row;
        }
        return null;
    }
}

if (!function_exists('calcAddCIBlockElement')) {
    function calcAddCIBlockElement($arForAdd, $arProps = [])
    {
        $el = new CIBlockElement();
        $arForAdd['PROPERTY_VALUES'] = $arProps;
        if ($id = $el->Add($arForAdd)) {
            return $id;
        }
        return 'Error: ' . $el->LAST_ERROR;
    }
}

if (!function_exists('calcGetDealInfoByID')) {
    function calcGetDealInfoByID($dealID)
    {
        $res = CCrmDeal::GetList(['ID' => 'ASC'], ['ID' => $dealID], []);
        if ($arDeal = $res->Fetch()) {
            return $arDeal;
        }
        return false;
    }
}

if (!function_exists('calcGetProductDataByID')) {
    function calcGetProductDataByID($ID)
    {
        if (!is_numeric($ID)) {
            return [];
        }
        $arElements = [];
        $res = CIBlockElement::GetList([], ['ID' => $ID], false, ['nPageSize' => 1], ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_*']);
        while ($ob = $res->GetNextElement()) {
            $arFields = $ob->GetFields();
            $arProps = $ob->GetProperties();
            $row = [];
            foreach ($arFields as $key => $val) {
                $row[$key] = $val;
            }
            foreach ($arProps as $key => $prop) {
                $row[$key] = $prop['VALUE'];
            }
            $price = CPrice::GetBasePrice($row['ID']);
            $row['PRICE'] = isset($price['PRICE']) ? round($price['PRICE'], 2) : 0;
            $row['Number'] = $row['__6KWOWZ'] ?? '';
            $row['FLOOR'] = $row['_FTRIDL'] ?? '';
            $row['TOTAL_AREA'] = is_numeric($row['__173JA5'] ?? null) ? floatval($row['__173JA5']) : 1;
            $row['PROJECT'] = $row['__VO9RG4'] ?? '';
            $row['BUILDING'] = $row['_L24CUB'] ?? '';
            $row['PRODUCT_TYPE'] = $row['__X1GCRZ'] ?? '';
            $row['KVM_PRICE'] = $row['TOTAL_AREA'] > 0 ? round($row['PRICE'] / $row['TOTAL_AREA'], 2) : $row['PRICE'];
            $arElements[] = $row;
        }
        return $arElements;
    }
}

if (!function_exists('calcValidateDate')) {
    function calcValidateDate($date, $format = 'd/m/Y')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}

if (!function_exists('calcDateCompare')) {
    function calcDateCompare($date1, $date2)
    {
        if (!$date1 || !$date2) {
            return false;
        }
        $start = DateTime::createFromFormat('d/m/Y', $date1);
        $end = DateTime::createFromFormat('d/m/Y', $date2);
        return $start < $end;
    }
}

if (!function_exists('calcMonthsBetweenDates')) {
    function calcMonthsBetweenDates($date1, $date2)
    {
        $dateTime1 = DateTime::createFromFormat('d/m/Y', $date1);
        $dateTime2 = DateTime::createFromFormat('d/m/Y', $date2);
        $interval = $dateTime1->diff($dateTime2);
        return $interval->format('%m') + 12 * $interval->format('%y');
    }
}

if (!function_exists('calcDateAddMonths')) {
    function calcDateAddMonths($date, $month)
    {
        $dateTime = DateTime::createFromFormat('d/m/Y', $date);
        $dateTime->modify('+' . intval($month) . ' months');
        return $dateTime->format('d/m/Y');
    }
}

if (!function_exists('calcStartDatesMonthsFirstDate')) {
    function calcStartDatesMonthsFirstDate($date)
    {
        $arrDate = explode('/', $date);
        return '01/' . $arrDate[1] . '/' . $arrDate[2];
    }
}

if (!function_exists('calcGetPaymentDay')) {
    function calcGetPaymentDay($date)
    {
        $arrDate = explode('/', $date);
        return $arrDate[0];
    }
}

if (!function_exists('calcGetPaymentDate')) {
    function calcGetPaymentDate($date, $day)
    {
        $dateObj = DateTime::createFromFormat('d/m/Y', $date);
        $lastDay = $dateObj->format('t');
        if (intval($lastDay) < intval($day)) {
            return $lastDay . '/' . $dateObj->format('m') . '/' . $dateObj->format('Y');
        }
        return $day . '/' . $dateObj->format('m') . '/' . $dateObj->format('Y');
    }
}

if (!function_exists('calcGetNbgRate')) {
    function calcGetNbgRate($date = null)
    {
        $date = $date ?: date('Y-m-d');
        $url = "https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=USD&date={$date}";
        $response = @file_get_contents($url);
        if (!$response) {
            return 0;
        }
        $data = json_decode($response);
        return $data[0]->currencies[0]->rate ?? 0;
    }
}

if (!function_exists('calcDateToNumber')) {
    function calcDateToNumber($date)
    {
        $dateARR = explode('/', $date);
        if (count($dateARR) !== 3) {
            return 0;
        }
        return intval($dateARR[2] . $dateARR[1] . $dateARR[0]);
    }
}

if (!function_exists('calcFormatBitrixDate')) {
    function calcFormatBitrixDate($value)
    {
        if (!$value) {
            return '';
        }
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $value, $m)) {
            return $m[1] . '/' . $m[2] . '/' . $m[3];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        return $value;
    }
}

if (!function_exists('calcParseMonthsFromName')) {
    function calcParseMonthsFromName($name)
    {
        if (preg_match('/(\d+)\s*თვე/u', $name, $m)) {
            return intval($m[1]);
        }
        return null;
    }
}

if (!function_exists('calcNormalizeProjectName')) {
    function calcNormalizeProjectName($name)
    {
        $name = is_array($name) ? ($name[0] ?? '') : (string)$name;
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }
}

if (!function_exists('calcProjectMatches')) {
    function calcProjectMatches($elementProject, $dealProject)
    {
        $a = calcNormalizeProjectName($elementProject);
        $b = calcNormalizeProjectName($dealProject);
        if ($b === '') {
            return true;
        }
        if ($a === '') {
            return false;
        }
        return $a === $b || mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false;
    }
}

if (!function_exists('calcIsActiveCondition')) {
    function calcIsActiveCondition($active)
    {
        if (is_array($active)) {
            $active = $active[0] ?? '';
        }
        $active = trim((string)$active);
        if ($active === '') {
            return true;
        }
        $inactive = ['არა', 'no', 'n', '0', 'false', 'inactive', 'არააქტიური'];
        return !in_array(mb_strtolower($active), $inactive, true);
    }
}

if (!function_exists('calcGetInstallmentConditions')) {
    function calcGetInstallmentConditions($projectName, $iblockId = 20)
    {
        $all = calcGetCIBlockElementsByFilter(['IBLOCK_ID' => $iblockId]);
        $matched = [];
        foreach ($all as $element) {
            if (!calcIsActiveCondition($element['ACTIVE'] ?? '')) {
                continue;
            }
            $elProject = $element['PROJECT'] ?? '';
            if (!calcProjectMatches($elProject, $projectName)) {
                continue;
            }
            $matched[] = $element;
        }
        return $matched;
    }
}

if (!function_exists('calcBuildScheduleHtml')) {
    function calcBuildScheduleHtml($rows)
    {
        $html = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">';
        $html .= '<thead><tr style="background:#1e3a5f;color:#fff;"><th>#</th><th>თარიღი</th><th>თანხა ($)</th><th>დარჩენილი ძირი</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td style="text-align:center;">' . htmlspecialchars((string)$row['payment']) . '</td>';
            $html .= '<td style="text-align:center;">' . htmlspecialchars((string)$row['date']) . '</td>';
            $html .= '<td style="text-align:right;">' . number_format(floatval($row['amount']), 2, '.', ',') . '</td>';
            $html .= '<td style="text-align:right;">' . number_format(floatval($row['leftToPay'] ?? 0), 2, '.', ',') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }
}

if (!function_exists('calcGetDealMetaForPlan')) {
    function calcGetDealMetaForPlan($dealData)
    {
        return [
            'PROJECT' => $dealData['UF_CRM_1779277729207'] ?? '',
            'KORPUSI' => $dealData['UF_CRM_1779277644355'] ?? '',
            'BINIS_NOMERI' => $dealData['UF_CRM_1779277613798'] ?? '',
            'floor' => $dealData['UF_CRM_1779277828822'] ?? '',
            'ZETIPI' => $dealData['UF_CRM_1779277898205'] ?? '',
            'KONTRAKT_DATE' => $dealData['UF_CRM_1779278590201'] ?? '',
            'xelshNum' => trim((string)($dealData['UF_CRM_1769416547'] ?? '')),
            'CONTACT' => $dealData['CONTACT_ID'] ?? '',
            'FULL_NAME' => $dealData['CONTACT_FULL_NAME'] ?? '',
        ];
    }
}
