<?php

require_once __DIR__ . '/config.php';

/** Short TTL for report catalog / schedule snapshots (seconds). */
define('REPORT_DATA_CACHE_TTL', 120);

function reportCacheGet($key, $dir = '/crm/deal/reports')
{
    if (!class_exists('\Bitrix\Main\Data\Cache')) {
        return null;
    }
    $cache = \Bitrix\Main\Data\Cache::createInstance();
    if ($cache->initCache(REPORT_DATA_CACHE_TTL, $key, $dir)) {
        return $cache->getVars();
    }
    return null;
}

function reportCacheSet($key, $value, $dir = '/crm/deal/reports')
{
    if (!class_exists('\Bitrix\Main\Data\Cache')) {
        return;
    }
    $cache = \Bitrix\Main\Data\Cache::createInstance();
    if ($cache->initCache(REPORT_DATA_CACHE_TTL, $key, $dir)) {
        return;
    }
    if ($cache->startDataCache()) {
        $cache->endDataCache($value);
    }
}

function reportScalarProp($value)
{
    if (is_array($value)) {
        if (array_key_exists('VALUE', $value)) {
            return reportScalarProp($value['VALUE']);
        }
        if (isset($value[0])) {
            return reportScalarProp($value[0]);
        }
        return '';
    }
    return $value;
}

function reportGetNbgRate($date = null)
{
    static $memory = [];
    $date = $date ?: date('Y-m-d');
    if (isset($memory[$date])) {
        return $memory[$date];
    }

    $cached = reportCacheGet('nbg_usd_' . $date, '/crm/deal/reports/nbg');
    if ($cached !== null && is_numeric($cached)) {
        return $memory[$date] = (float)$cached;
    }

    $url = "https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=USD&date={$date}";
    $response = @file_get_contents($url);
    $rate = 1.0;
    if ($response !== false) {
        $data = json_decode($response);
        $rate = (float)($data[0]->currencies[0]->rate ?? 1);
    }

    reportCacheSet('nbg_usd_' . $date, $rate, '/crm/deal/reports/nbg');
    return $memory[$date] = $rate;
}

function reportGetUserName($id)
{
    $id = (int)$id;
    if ($id <= 0) {
        return '';
    }
    $names = reportBatchUserNames([$id]);
    return $names[$id] ?? '';
}

function reportGetContactName($contactId)
{
    $contactId = (int)$contactId;
    if ($contactId <= 0) {
        return '';
    }
    $names = reportBatchContactNames([$contactId]);
    return $names[$contactId] ?? '';
}

function reportBatchUserNames(array $ids)
{
    static $cache = [];
    $result = [];
    $missing = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        if (array_key_exists($id, $cache)) {
            $result[$id] = $cache[$id];
        } else {
            $missing[$id] = true;
        }
    }
    if (!empty($missing)) {
        $by = 'id';
        $order = 'asc';
        $res = CUser::GetList(
            $by,
            $order,
            ['ID' => implode('|', array_keys($missing))],
            ['FIELDS' => ['ID', 'NAME', 'LAST_NAME']]
        );
        while ($row = $res->Fetch()) {
            $id = (int)$row['ID'];
            $name = trim(($row['NAME'] ?? '') . ' ' . ($row['LAST_NAME'] ?? ''));
            $cache[$id] = $name;
            $result[$id] = $name;
            unset($missing[$id]);
        }
        foreach ($missing as $id => $_) {
            $cache[$id] = '';
            $result[$id] = '';
        }
    }
    return $result;
}

function reportBatchContactNames(array $ids)
{
    static $cache = [];
    $result = [];
    $missing = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        if (array_key_exists($id, $cache)) {
            $result[$id] = $cache[$id];
        } else {
            $missing[$id] = true;
        }
    }
    if (!empty($missing)) {
        foreach (array_chunk(array_keys($missing), 500) as $chunk) {
            $res = CCrmContact::GetList(
                [],
                ['ID' => $chunk, 'CHECK_PERMISSIONS' => 'N'],
                ['ID', 'NAME', 'LAST_NAME']
            );
            while ($row = $res->Fetch()) {
                $id = (int)$row['ID'];
                $name = trim(($row['NAME'] ?? '') . ' ' . ($row['LAST_NAME'] ?? ''));
                $cache[$id] = $name;
                $result[$id] = $name;
                unset($missing[$id]);
            }
        }
        foreach ($missing as $id => $_) {
            $cache[$id] = '';
            $result[$id] = '';
        }
    }
    return $result;
}

function reportBatchBasePrices(array $productIds)
{
    $prices = [];
    if (empty($productIds)) {
        return $prices;
    }

    $baseGroupId = 0;
    if (class_exists('CCatalogGroup')) {
        $base = CCatalogGroup::GetBaseGroup();
        $baseGroupId = (int)($base['ID'] ?? 0);
    }

    foreach (array_chunk(array_map('intval', $productIds), 500) as $chunk) {
        $chunk = array_values(array_filter($chunk));
        if (empty($chunk)) {
            continue;
        }
        $filter = ['PRODUCT_ID' => $chunk];
        if ($baseGroupId > 0) {
            $filter['CATALOG_GROUP_ID'] = $baseGroupId;
        }
        $res = CPrice::GetList(
            [],
            $filter,
            false,
            false,
            ['PRODUCT_ID', 'PRICE', 'CATALOG_GROUP_ID']
        );
        while ($row = $res->Fetch()) {
            $pid = (int)$row['PRODUCT_ID'];
            if (!isset($prices[$pid])) {
                $prices[$pid] = (float)$row['PRICE'];
            }
        }
    }

    return $prices;
}

function reportGetDealsByFilter($arFilter, $arSelect = [], $arSort = ['ID' => 'ASC'])
{
    $resArr = [];
    if (!isset($arFilter['CHECK_PERMISSIONS'])) {
        $arFilter['CHECK_PERMISSIONS'] = 'N';
    }
    $res = CCrmDeal::GetList($arSort, $arFilter, $arSelect);
    while ($arDeal = $res->Fetch()) {
        $resArr[$arDeal['ID']] = $arDeal;
    }
    return $resArr;
}

function reportProductPropertyCodes()
{
    return [
        F_PROJECT,
        F_TYPE,
        F_STATUS,
        F_BLOCK,
        F_SECTOR,
        F_TOTAL_AREA,
        F_BEDROOMS,
        F_KVM_PRICE,
        F_UNIT_NO,
        F_FLOOR,
        'OWNER_DEAL',
        'ownerDeal',
        'OWNER_CONTACT',
        'OWNER_PERSONAL_CONTACT',
        'DEAL_RESPONSIBLE',
    ];
}

function reportProductSelectFields()
{
    $select = ['ID', 'IBLOCK_ID', 'NAME'];
    foreach (reportProductPropertyCodes() as $code) {
        $select[] = 'PROPERTY_' . $code;
    }
    return $select;
}

function reportMapProductFetchRow(array $ob)
{
    $row = [
        'ID' => $ob['ID'],
        'IBLOCK_ID' => $ob['IBLOCK_ID'] ?? '',
        'NAME' => $ob['~NAME'] ?? ($ob['NAME'] ?? ''),
    ];
    foreach (reportProductPropertyCodes() as $code) {
        $key = 'PROPERTY_' . $code . '_VALUE';
        if (array_key_exists($key, $ob)) {
            $row[$code] = reportScalarProp($ob[$key]);
        }
    }
    if (empty($row['OWNER_DEAL']) && !empty($row['ownerDeal'])) {
        $row['OWNER_DEAL'] = $row['ownerDeal'];
    }
    return $row;
}

function reportNormalizeProductRow($arFields, $arProps, $nbg = null)
{
    $row = [];
    foreach ($arFields as $key => $value) {
        $row[$key] = $value;
    }
    foreach ($arProps as $key => $prop) {
        $code = !empty($prop['CODE']) ? $prop['CODE'] : $key;
        $row[$code] = $prop['VALUE'];
    }

    if (!empty($row['OWNER_PERSONAL_CONTACT'])) {
        $row['OWNER_CONTACT_NAME'] = reportGetContactName($row['OWNER_PERSONAL_CONTACT']);
    } elseif (!empty($row['OWNER_CONTACT'])) {
        $row['OWNER_CONTACT_NAME'] = reportGetContactName($row['OWNER_CONTACT']);
    }

    if (!empty($row['DEAL_RESPONSIBLE'])) {
        $row['DEAL_RESPONSIBLE_NAME'] = reportGetUserName($row['DEAL_RESPONSIBLE']);
    }

    $price = CPrice::GetBasePrice($row['ID']);
    $row['PRICE'] = isset($price['PRICE']) ? round((float)$price['PRICE'], 2) : 0;
    $row['PRICE_GEL'] = round($row['PRICE'] * ($nbg ?: reportGetNbgRate()), 2);
    $row['KVM_PRICE'] = isset($row[F_KVM_PRICE]) ? (float)$row[F_KVM_PRICE] : 0;

    return $row;
}

function reportGetProducts($arFilter = [])
{
    static $runtime = [];

    $filter = array_merge([
        'IBLOCK_ID' => REPORT_PRODUCT_IBLOCK,
        'CHECK_PERMISSIONS' => 'N',
    ], $arFilter);

    $cacheKey = 'products_' . md5(serialize($filter));
    if (isset($runtime[$cacheKey])) {
        return $runtime[$cacheKey];
    }

    $cached = reportCacheGet($cacheKey);
    if (is_array($cached)) {
        return $runtime[$cacheKey] = $cached;
    }

    $nbg = reportGetNbgRate(date('Y-m-d'));
    $raw = [];
    $productIds = [];
    $contactIds = [];
    $userIds = [];

    $res = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        $filter,
        false,
        false,
        reportProductSelectFields()
    );
    while ($ob = $res->GetNext()) {
        $id = (int)$ob['ID'];
        if ($id <= 0) {
            continue;
        }
        $row = reportMapProductFetchRow($ob);
        $raw[$id] = $row;
        $productIds[] = $id;

        $contactRaw = $row['OWNER_PERSONAL_CONTACT'] ?? '';
        if ($contactRaw === '' || $contactRaw === null) {
            $contactRaw = $row['OWNER_CONTACT'] ?? '';
        }
        $contactId = (int)reportExtractDealId($contactRaw);
        if ($contactId > 0) {
            $contactIds[$contactId] = true;
        }
        $userId = (int)($row['DEAL_RESPONSIBLE'] ?? 0);
        if ($userId > 0) {
            $userIds[$userId] = true;
        }
    }

    $contacts = reportBatchContactNames(array_keys($contactIds));
    $users = reportBatchUserNames(array_keys($userIds));
    $prices = reportBatchBasePrices($productIds);

    $elements = [];
    foreach ($raw as $id => $row) {
        $contactRaw = $row['OWNER_PERSONAL_CONTACT'] ?? '';
        if ($contactRaw === '' || $contactRaw === null) {
            $contactRaw = $row['OWNER_CONTACT'] ?? '';
        }
        $contactId = (int)reportExtractDealId($contactRaw);
        $userId = (int)($row['DEAL_RESPONSIBLE'] ?? 0);

        $row['OWNER_CONTACT_NAME'] = $contactId > 0 ? ($contacts[$contactId] ?? '') : '';
        $row['DEAL_RESPONSIBLE_NAME'] = $userId > 0 ? ($users[$userId] ?? '') : '';
        $row['PRICE'] = isset($prices[$id]) ? round((float)$prices[$id], 2) : 0;
        $row['PRICE_GEL'] = round($row['PRICE'] * $nbg, 2);
        $row['KVM_PRICE'] = isset($row[F_KVM_PRICE]) ? (float)$row[F_KVM_PRICE] : 0;
        $elements[$id] = $row;
    }

    reportCacheSet($cacheKey, $elements);
    return $runtime[$cacheKey] = $elements;
}

function reportGetAllInventoryProducts()
{
    return reportGetProducts();
}

function reportGetSoldProducts()
{
    return array_filter(reportGetProducts(), static function ($product) {
        return ($product[F_STATUS] ?? '') === 'გაყიდული';
    });
}

function reportGetReservedProducts()
{
    return array_filter(reportGetProducts(), static function ($product) {
        return ($product[F_STATUS] ?? '') === REPORT_RESERVED_STATUS;
    });
}

/**
 * Products linked to the given deal IDs via OWNER_DEAL (uses cached catalog).
 */
function reportGetProductsForDeals(array $dealIds)
{
    $dealIdSet = reportBuildDealIdSet($dealIds);
    if (empty($dealIdSet)) {
        return [];
    }

    $matched = [];
    foreach (reportGetProducts() as $id => $row) {
        $ownerDealId = reportExtractDealId($row['OWNER_DEAL'] ?? '');
        if ($ownerDealId !== '' && isset($dealIdSet[$ownerDealId])) {
            $matched[$id] = $row;
        }
    }
    return $matched;
}

/**
 * Attach reservation date/stage from linked OWNER_DEAL onto product rows.
 */
function reportEnrichReservationMeta(array $products)
{
    $dealIds = [];
    foreach ($products as $product) {
        $dealId = reportExtractDealId($product['OWNER_DEAL'] ?? '');
        if ($dealId !== '') {
            $dealIds[$dealId] = true;
        }
    }

    $dealMeta = [];
    if (!empty($dealIds)) {
        $res = CCrmDeal::GetList(
            ['ID' => 'ASC'],
            ['ID' => array_keys($dealIds), 'CHECK_PERMISSIONS' => 'N'],
            ['ID', 'STAGE_ID', D_RESERVATION_DATE, 'CONTACT_ID', 'OPPORTUNITY']
        );
        while ($row = $res->Fetch()) {
            $dealMeta[(string)$row['ID']] = $row;
        }
    }

    foreach ($products as $id => $product) {
        $dealId = reportExtractDealId($product['OWNER_DEAL'] ?? '');
        $meta = ($dealId !== '' && isset($dealMeta[$dealId])) ? $dealMeta[$dealId] : null;
        $products[$id]['RESERVATION_DATE'] = $meta[D_RESERVATION_DATE] ?? '';
        $products[$id]['RESERVATION_STAGE'] = $meta['STAGE_ID'] ?? '';
        if (empty($products[$id]['OWNER_CONTACT_NAME']) && !empty($meta['CONTACT_ID'])) {
            $products[$id]['OWNER_CONTACT_NAME'] = reportGetContactName($meta['CONTACT_ID']);
        }
    }

    return $products;
}

/**
 * Attach bedroom count from linked OWNER_DEAL (D_BEDROOMS) onto product rows.
 */
function reportEnrichDealBedrooms(array $products)
{
    $dealIds = [];
    foreach ($products as $product) {
        $dealId = reportExtractProductOwnerDealId($product);
        if ($dealId !== '') {
            $dealIds[$dealId] = true;
        }
    }

    $dealBedrooms = [];
    if (!empty($dealIds)) {
        $res = CCrmDeal::GetList(
            ['ID' => 'ASC'],
            ['ID' => array_keys($dealIds), 'CHECK_PERMISSIONS' => 'N'],
            ['ID', D_BEDROOMS]
        );
        while ($row = $res->Fetch()) {
            $dealBedrooms[(string)$row['ID']] = $row[D_BEDROOMS] ?? '';
        }
    }

    foreach ($products as $id => $product) {
        $dealId = reportExtractProductOwnerDealId($product);
        $products[$id][D_BEDROOMS] = ($dealId !== '' && isset($dealBedrooms[$dealId]))
            ? (string)$dealBedrooms[$dealId]
            : '';
    }

    return $products;
}

function reportGetUniqueValues($items, $field)
{
    $values = [];
    foreach ($items as $item) {
        if (!empty($item[$field])) {
            $values[$item[$field]] = true;
        }
    }
    $values = array_keys($values);
    sort($values);
    return $values;
}

function reportParseAmount($value)
{
    if ($value === null || $value === '') {
        return 0;
    }
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    $text = trim((string)$value);
    if ($text === '') {
        return 0;
    }
    // Bitrix money: "1234.56|USD" or "USD|1234.56"
    if (strpos($text, '|') !== false) {
        $parts = explode('|', $text);
        foreach ($parts as $part) {
            $part = trim(str_replace([' ', ','], ['', ''], $part));
            if ($part !== '' && is_numeric($part)) {
                return round((float)$part, 2);
            }
        }
    }
    $text = str_replace([' ', ','], ['', ''], $text);
    return is_numeric($text) ? round((float)$text, 2) : 0;
}

function reportExtractProductOwnerDealId(array $product)
{
    foreach (['OWNER_DEAL', 'ownerDeal'] as $key) {
        $dealId = reportExtractDealId($product[$key] ?? '');
        if ($dealId !== '') {
            return $dealId;
        }
    }

    return '';
}

/**
 * Deal ID => [catalog product ID => true] from CRM product rows (deal "Products" tab).
 */
function reportGetDealProductIdsMap(array $dealIds)
{
    $map = [];
    foreach ($dealIds as $dealId) {
        $dealId = (int)$dealId;
        if ($dealId <= 0) {
            continue;
        }

        $res = CCrmProductRow::GetList(
            ['ID' => 'ASC'],
            ['OWNER_TYPE' => 'D', 'OWNER_ID' => $dealId]
        );
        while ($row = $res->Fetch()) {
            $productId = (int)($row['PRODUCT_ID'] ?? 0);
            if ($productId > 0) {
                $map[(string)$dealId][$productId] = true;
            }
        }
    }

    return $map;
}

function reportExtractDealId($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    if (ctype_digit($text)) {
        return (string)((int)$text);
    }
    // CRM bind formats: D_61042, DEAL_61042, [D]61042
    if (preg_match('/(?:^|[^\d])(?:D_|DEAL_)?(\d+)(?:[^\d]|$)/i', $text, $matches)) {
        return (string)((int)$matches[1]);
    }
    if (preg_match('/(\d+)/', $text, $matches)) {
        return (string)((int)$matches[1]);
    }
    return '';
}

function reportParseDate($value)
{
    if (is_array($value)) {
        $value = $value['VALUE'] ?? ($value[0] ?? '');
    }
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    $formats = ['d/m/Y', 'd.m.Y', 'Y-m-d', 'd/m/Y H:i:s', 'Y-m-d H:i:s', 'd.m.Y H:i:s'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $text);
        if ($dt instanceof DateTime) {
            $dt->setTime(0, 0, 0);
            return $dt;
        }
    }

    $ts = strtotime(str_replace('/', '.', $text));
    if ($ts) {
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $dt->setTime(0, 0, 0);
        return $dt;
    }
    return null;
}

function reportBuildDealIdSet($dealIds)
{
    $set = [];
    foreach ((array)$dealIds as $id) {
        $normalized = reportExtractDealId($id);
        if ($normalized !== '') {
            $set[$normalized] = true;
        }
    }
    return $set;
}

/**
 * Load all property rows for an iblock (cached). Used by schedule/payment reports.
 */
function reportLoadAllIblockPropertyRows($iblockId, array $sort = ['ID' => 'ASC'])
{
    static $runtime = [];
    $iblockId = (int)$iblockId;
    if ($iblockId <= 0) {
        return [];
    }

    $cacheKey = 'iblock_rows_' . $iblockId . '_' . md5(serialize($sort));
    if (isset($runtime[$cacheKey])) {
        return $runtime[$cacheKey];
    }

    $cached = reportCacheGet($cacheKey, '/crm/deal/reports/iblock');
    if (is_array($cached)) {
        return $runtime[$cacheKey] = $cached;
    }

    $rows = [];
    $page = 1;
    $pageSize = 500;

    do {
        $pageCount = 0;
        $res = CIBlockElement::GetList(
            $sort,
            ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nPageSize' => $pageSize, 'iNumPage' => $page],
            ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_*']
        );

        while ($ob = $res->GetNext()) {
            $pageCount++;
            $row = [
                'ID' => $ob['ID'],
                'IBLOCK_ID' => $ob['IBLOCK_ID'] ?? '',
                'NAME' => $ob['~NAME'] ?? ($ob['NAME'] ?? ''),
            ];
            foreach ($ob as $key => $val) {
                if (preg_match('/^PROPERTY_(.+)_VALUE$/', $key, $m)) {
                    $row[$m[1]] = reportScalarProp($val);
                }
            }
            $rows[] = $row;
        }

        $page++;
    } while ($pageCount === $pageSize);

    reportCacheSet($cacheKey, $rows, '/crm/deal/reports/iblock');
    return $runtime[$cacheKey] = $rows;
}

/**
 * Load iblock rows linked to deals via CRM DEAL property.
 * Matches in PHP (Bitrix PROPERTY_DEAL filter is unreliable for CRM binds).
 */
function reportLoadIblockRowsForDeals($iblockId, array $dealIds, array $sort = ['ID' => 'ASC'])
{
    $dealIdSet = reportBuildDealIdSet($dealIds);
    if (empty($dealIdSet) || empty($iblockId)) {
        return [];
    }

    $rows = [];
    foreach (reportLoadAllIblockPropertyRows($iblockId, $sort) as $row) {
        $dealId = reportExtractDealId($row['DEAL'] ?? '');
        if ($dealId === '' || !isset($dealIdSet[$dealId])) {
            continue;
        }
        $row['_DEAL_ID'] = $dealId;
        $rows[] = $row;
    }

    return $rows;
}

function reportGetDaricxvebi($dealIds, $upToToday = false)
{
    if (empty($dealIds)) {
        return [];
    }

    $today = new DateTime('today');
    $items = [];

    foreach (reportLoadIblockRowsForDeals(REPORT_SCHEDULE_IBLOCK, $dealIds, ['PROPERTY_TARIGI' => 'ASC']) as $row) {
        $dateRaw = $row['TARIGI'] ?? '';
        if ($upToToday) {
            $dateObj = reportParseDate($dateRaw);
            if (!$dateObj || $dateObj > $today) {
                continue;
            }
        }

        $amount = reportParseAmount($row['TANXA'] ?? ($row['TANXA_NUMBR'] ?? 0));
        if ($amount == 0.0 && isset($row['TANXA_NUMBR'])) {
            $amount = reportParseAmount($row['TANXA_NUMBR']);
        }

        $items[] = [
            'DEAL_ID' => $row['_DEAL_ID'],
            'daricxva_date' => $dateRaw,
            'daricxva_amount' => $amount,
        ];
    }

    return $items;
}

function reportGetGadaxdebi($dealIds)
{
    if (empty($dealIds)) {
        return [];
    }

    $items = [];
    foreach (reportLoadIblockRowsForDeals(REPORT_PAYMENT_IBLOCK, $dealIds, ['ID' => 'ASC']) as $row) {
        $amount = reportParseAmount($row['TANXA'] ?? ($row['TANXA_NUMBR'] ?? 0));
        $items[] = [
            'DEAL_ID' => $row['_DEAL_ID'],
            'gadaxda_date' => $row['date'] ?? ($row['TARIGI'] ?? ''),
            'gadaxda_amount' => $amount,
        ];
    }

    return $items;
}

function reportGetDaricxvebiDaGadaxdebi($fromDate, $toDate, $dealIds)
{
    $daricxvebi = [];
    $gadaxdebi = [];

    $fromObj = !empty($fromDate) ? DateTime::createFromFormat('Y-m-d', $fromDate) : null;
    $toObj = !empty($toDate) ? DateTime::createFromFormat('Y-m-d', $toDate) : null;
    if ($fromObj) {
        $fromObj->setTime(0, 0, 0);
    }
    if ($toObj) {
        $toObj->setTime(23, 59, 59);
    }

    foreach (reportLoadIblockRowsForDeals(REPORT_SCHEDULE_IBLOCK, $dealIds, ['PROPERTY_TARIGI' => 'ASC']) as $row) {
        $dateRaw = $row['TARIGI'] ?? '';
        $dateObj = reportParseDate($dateRaw);
        if ($fromObj && (!$dateObj || $dateObj < $fromObj)) {
            continue;
        }
        if ($toObj && (!$dateObj || $dateObj > $toObj)) {
            continue;
        }

        $daricxvebi[] = [
            'DEAL_ID' => $row['_DEAL_ID'],
            'DATE' => $dateRaw,
            'AMOUNT' => reportParseAmount($row['TANXA'] ?? ($row['TANXA_NUMBR'] ?? 0)),
        ];
    }

    foreach (reportLoadIblockRowsForDeals(REPORT_PAYMENT_IBLOCK, $dealIds, ['ID' => 'ASC']) as $row) {
        $dateRaw = $row['date'] ?? ($row['TARIGI'] ?? '');
        $dateObj = reportParseDate($dateRaw);
        if ($fromObj && $dateObj && $dateObj < $fromObj) {
            continue;
        }
        if ($toObj && $dateObj && $dateObj > $toObj) {
            continue;
        }

        $gadaxdebi[] = [
            'DEAL_ID' => $row['_DEAL_ID'],
            'DATE' => $dateRaw,
            'AMOUNT' => reportParseAmount($row['TANXA'] ?? ($row['TANXA_NUMBR'] ?? 0)),
        ];
    }

    return [$daricxvebi, $gadaxdebi];
}

function reportFilterPaymentsByDates($fromDate, $toDate, $payments)
{
    if (empty($fromDate) || empty($toDate)) {
        return $payments;
    }

    $fromObj = DateTime::createFromFormat('Y-m-d', $fromDate);
    $toObj = DateTime::createFromFormat('Y-m-d', $toDate);
    if (!$fromObj || !$toObj) {
        return $payments;
    }

    return array_values(array_filter($payments, function ($item) use ($fromObj, $toObj) {
        $itemDateStr = $item['DATE'] ?? '';
        if (!$itemDateStr) {
            return false;
        }

        $formats = ['d/m/Y', 'Y-m-d', 'Y-m-d H:i:s', 'd.m.Y'];
        $itemDate = false;
        foreach ($formats as $fmt) {
            $d = DateTime::createFromFormat($fmt, $itemDateStr);
            if ($d) {
                $itemDate = $d;
                break;
            }
        }
        if (!$itemDate) {
            return false;
        }

        return $itemDate >= $fromObj && $itemDate <= $toObj;
    }));
}

function reportResolveProductType($product)
{
    if (($product[F_BLOCK] ?? '') === 'P') {
        return 'გარე ავტოსადგომი';
    }

    $prodType = $product[F_TYPE] ?? '';
    if ($prodType === 'ავტოსადგომი') {
        return 'შიდა ავტოსადგომი';
    }

    return $prodType;
}

/**
 * @param bool $preferDealBedrooms When true (deal-based reports): D_BEDROOMS, then F_BEDROOMS.
 *                                  When false (product inventory): F_BEDROOMS only.
 */
function reportResolveApartmentSubtype($product, $preferDealBedrooms = false)
{
    if (($product[F_TYPE] ?? '') !== 'ბინა') {
        return null;
    }

    $bedrooms = '';
    if ($preferDealBedrooms) {
        $bedrooms = (string)($product[D_BEDROOMS] ?? '');
    }
    if ($bedrooms === '') {
        $bedrooms = (string)($product[F_BEDROOMS] ?? '');
    }
    if ($bedrooms === '1') {
        return 'ბინა (1 საძ.)';
    }
    if ($bedrooms === '2') {
        return 'ბინა (2 საძ.)';
    }
    if ($bedrooms === '3') {
        return 'ბინა (3 საძ.)';
    }

    return null;
}

function reportAddProductAggregate(&$resArray, $prodType, $status, $product)
{
    if (!isset($resArray[$prodType][$status])) {
        $resArray[$prodType][$status] = ['num' => 0, 'total_area' => 0, 'price' => 0, 'KVM_PRICE' => 0];
    }

    $resArray[$prodType][$status]['num']++;
    $resArray[$prodType][$status]['total_area'] += (float)($product[F_TOTAL_AREA] ?? 0);
    $resArray[$prodType][$status]['price'] += (float)($product['PRICE'] ?? 0);
    $resArray[$prodType][$status]['KVM_PRICE'] += (float)($product['KVM_PRICE'] ?? 0);
}

function reportSortProductTypes(array $resArray)
{
    uksort($resArray, function ($a, $b) {
        $posA = REPORT_TYPE_ORDER[$a] ?? 99;
        $posB = REPORT_TYPE_ORDER[$b] ?? 99;
        if ($posA !== $posB) {
            return $posA - $posB;
        }
        return strcmp($a, $b);
    });
    return $resArray;
}

function reportGetProductLabels($lang = 'ge')
{
    $labels = [
        'ge' => [
            'filter_project' => 'პროექტი:',
            'filter_sector' => 'სექტორი:',
            'filter_block' => 'ბლოკი:',
            'filter_responsible' => 'პასუხისმგებელი:',
            'all_projects' => 'ყველა პროექტი',
            'all_sectors' => 'ყველა სექტორი',
            'all_blocks' => 'ყველა ბლოკი',
            'all_responsible' => 'ყველა პასუხისმგებელი',
            'apply' => 'ფილტრის გამოყენება',
            'clear' => 'გასუფთავება',
            'export' => '📥 Excel-ში ექსპორტი',
            'col_type' => 'ქონების ტიპი',
            'col_total' => 'TOTAL',
            'prod_types' => [
                'ბინა' => 'ბინა',
                'ბინა (1 საძ.)' => 'ბინა (1 საძ.)',
                'ბინა (2 საძ.)' => 'ბინა (2 საძ.)',
                'ბინა (3 საძ.)' => 'ბინა (3 საძ.)',
                'სტუდიო' => 'სტუდიო',
                'დუპლექსი' => 'დუპლექსი',
                'შიდა ავტოსადგომი' => 'შიდა ავტოსადგომი',
                'გარე ავტოსადგომი' => 'გარე ავტოსადგომი',
                'დამხმარე' => 'დამხმარე',
                'აპარტამენტი' => 'აპარტამენტი',
                'კომერციული' => 'კომერციული',
            ],
        ],
        'eng' => [
            'filter_project' => 'Project:',
            'filter_sector' => 'Sector:',
            'filter_block' => 'Block:',
            'filter_responsible' => 'Responsible:',
            'all_projects' => 'All Projects',
            'all_sectors' => 'All Sectors',
            'all_blocks' => 'All Blocks',
            'all_responsible' => 'All Responsible',
            'apply' => 'Apply Filters',
            'clear' => 'Clear',
            'export' => '📥 Export to Excel',
            'col_type' => 'Property Type',
            'col_total' => 'TOTAL',
            'prod_types' => [
                'ბინა' => 'Flat',
                'ბინა (1 საძ.)' => 'Flat (1 Bed.)',
                'ბინა (2 საძ.)' => 'Flat (2 Bed.)',
                'ბინა (3 საძ.)' => 'Flat (3 Bed.)',
                'სტუდიო' => 'Studio',
                'დუპლექსი' => 'Duplex',
                'შიდა ავტოსადგომი' => 'Indoor Parking',
                'გარე ავტოსადგომი' => 'Outdoor Parking',
                'დამხმარე' => 'Additional',
                'აპარტამენტი' => 'Apartment',
                'კომერციული' => 'Commercial',
            ],
        ],
    ];

    return $labels[$lang] ?? $labels['ge'];
}

function reportTranslateProdType($name, $labels)
{
    return $labels['prod_types'][$name] ?? $name;
}

function reportFilterProducts(array $products, array $filters)
{
    $filtered = [];
    foreach ($products as $product) {
        $match = true;
        if (!empty($filters['project']) && ($product[F_PROJECT] ?? '') != $filters['project']) {
            $match = false;
        }
        if (!empty($filters['sector']) && ($product[F_SECTOR] ?? '') != $filters['sector']) {
            $match = false;
        }
        if (!empty($filters['block']) && ($product[F_BLOCK] ?? '') != $filters['block']) {
            $match = false;
        }
        if (!empty($filters['responsible']) && ($product['DEAL_RESPONSIBLE_NAME'] ?? '') != $filters['responsible']) {
            $match = false;
        }
        if ($match) {
            $filtered[$product['ID']] = $product;
        }
    }
    return $filtered;
}

function reportRenderFilterForm($filters, $options, $labels, $lang, $schema = 'inventory')
{
    $fields = [];
    if ($schema === 'deals') {
        $fields = [
            ['name' => 'project', 'id' => 'project', 'label' => $labels['filter_project'], 'all' => $labels['all_projects'], 'options' => $options['projects'] ?? [], 'value' => $filters['project'] ?? ''],
            ['name' => 'block', 'id' => 'block', 'label' => $labels['filter_block'], 'all' => $labels['all_blocks'], 'options' => $options['blocks'] ?? [], 'value' => $filters['block'] ?? ''],
            ['name' => 'responsible', 'id' => 'responsible', 'label' => $labels['filter_responsible'], 'all' => $labels['all_responsible'], 'options' => $options['responsibles'] ?? [], 'value' => $filters['responsible'] ?? '', 'assoc' => true],
        ];
    } else {
        $fields = [
            ['name' => 'project', 'id' => 'project', 'label' => $labels['filter_project'], 'all' => $labels['all_projects'], 'options' => $options['projects'] ?? [], 'value' => $filters['project'] ?? ''],
            ['name' => 'sector', 'id' => 'sector', 'label' => $labels['filter_sector'], 'all' => $labels['all_sectors'], 'options' => $options['sectors'] ?? [], 'value' => $filters['sector'] ?? ''],
            ['name' => 'block', 'id' => 'block', 'label' => $labels['filter_block'], 'all' => $labels['all_blocks'], 'options' => $options['blocks'] ?? [], 'value' => $filters['block'] ?? ''],
            ['name' => 'responsible', 'id' => 'responsible', 'label' => $labels['filter_responsible'], 'all' => $labels['all_responsible'], 'options' => $options['responsibles'] ?? [], 'value' => $filters['responsible'] ?? ''],
        ];
    }
    ?>
    <section class="report-filter">
        <form method="GET" action="" class="report-filter__form">
            <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
            <div class="report-filter__grid">
                <?php foreach ($fields as $field): ?>
                    <div class="report-field">
                        <label for="<?= $field['id'] ?>"><?= $field['label'] ?></label>
                        <select name="<?= $field['name'] ?>" id="<?= $field['id'] ?>">
                            <option value=""><?= $field['all'] ?></option>
                            <?php foreach ($field['options'] as $key => $option): ?>
                                <?php
                                $optValue = !empty($field['assoc']) ? $key : $option;
                                $optLabel = !empty($field['assoc']) ? $option : $option;
                                $selected = (string)$field['value'] === (string)$optValue;
                                ?>
                                <option value="<?= htmlspecialchars($optValue) ?>" <?= $selected ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($optLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="report-filter__actions">
                <button type="submit" class="btn btn-primary"><?= $labels['apply'] ?></button>
                <button type="button" class="btn btn-ghost" onclick="window.location.href='<?= $_SERVER['PHP_SELF'] ?>'">
                    <?= $labels['clear'] ?>
                </button>
                <button type="button" class="btn btn-export" onclick="exportToExcel()">
                    <span class="btn-icon">↓</span><?= $labels['export'] ?>
                </button>
            </div>
        </form>
    </section>
    <?php
}

function reportRenderCashflowFilterForm($period, $fromDate, $toDate, $project, $projects)
{
    ?>
    <section class="report-filter report-filter--cashflow">
        <form method="get" id="newCalendarForm" class="report-filter__form">
            <div class="report-filter__grid report-filter__grid--cashflow">
                <div class="report-field">
                    <label for="period">პერიოდი</label>
                    <select name="period" id="period">
                        <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>დღე</option>
                        <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>თვე</option>
                        <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>წელი</option>
                    </select>
                </div>
                <div class="report-field">
                    <label for="from_date">დაწყების თარიღი</label>
                    <input type="date" name="from_date" id="from_date" value="<?= htmlspecialchars($fromDate ?? '') ?>">
                </div>
                <div class="report-field">
                    <label for="to_date">დამთავრების თარიღი</label>
                    <input type="date" name="to_date" id="to_date" value="<?= htmlspecialchars($toDate ?? '') ?>">
                </div>
                <div class="report-field">
                    <label for="project">პროექტი</label>
                    <select name="project" id="project">
                        <option value="">ყველა</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?= htmlspecialchars($proj) ?>" <?= $project == $proj ? 'selected' : '' ?>><?= htmlspecialchars($proj) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="report-filter__actions">
                <button type="submit" class="btn btn-primary">ძებნა</button>
                <button type="button" class="btn btn-ghost" onclick="window.location.href='<?= $_SERVER['PHP_SELF'] ?>'">გასუფთავება</button>
                <button type="button" class="btn btn-export" onclick="exportTableToExcel()">
                    <span class="btn-icon">↓</span>Export to Excel
                </button>
            </div>
        </form>
    </section>
    <?php
}

function reportPageBegin($title, $subtitle = '', $lang = 'ge')
{
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php reportCommonStyles(); ?>
    <div class="report-page" lang="<?= htmlspecialchars($lang) ?>">
        <header class="report-hero">
            <div class="report-hero__content">
                <p class="report-hero__eyebrow">Monolith · Reports</p>
                <h1 class="report-hero__title"><?= htmlspecialchars($title) ?></h1>
                <?php if ($subtitle !== ''): ?>
                    <p class="report-hero__subtitle"><?= htmlspecialchars($subtitle) ?></p>
                <?php endif; ?>
            </div>
        </header>
        <main class="report-main">
    <?php
}

function reportPageEnd($lang = 'ge')
{
    $loadingText = $lang === 'eng' ? 'Filtering...' : 'ფილტრდება...';
    $loadingSub = $lang === 'eng' ? 'Please wait' : 'გთხოვთ, დაელოდოთ';
    ?>
        </main>
        <div class="report-page-loader" id="reportPageLoader" aria-live="polite" aria-busy="false" hidden>
            <div class="report-page-loader__card">
                <div class="report-page-loader__spinner"></div>
                <p class="report-page-loader__text"><?= htmlspecialchars($loadingText) ?></p>
                <p class="report-page-loader__sub"><?= htmlspecialchars($loadingSub) ?></p>
            </div>
        </div>
    </div>
    <script>
    (function () {
        function showReportLoader() {
            var loader = document.getElementById('reportPageLoader');
            if (!loader) return;
            loader.hidden = false;
            loader.setAttribute('aria-busy', 'true');
            document.body.classList.add('is-report-loading');
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'monolith-report-loading', loading: true }, '*');
                }
            } catch (e) {}
        }

        document.querySelectorAll('.report-filter__form').forEach(function (form) {
            form.addEventListener('submit', function () {
                showReportLoader();
            });
        });

        document.querySelectorAll('.report-filter__actions .btn-ghost').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showReportLoader();
            });
        });
    })();
    </script>
    <?php
}

function reportBlockOpen($title, $extraTableClass = '')
{
    $tableClass = trim('report-table ' . $extraTableClass);
    echo '<section class="report-block"><div class="report-block__head"><h2 class="report-block__title">' . htmlspecialchars($title) . '</h2></div><div class="table-wrap"><table class="' . htmlspecialchars($tableClass) . '">';
}

function reportBlockClose()
{
    echo '</table></div></section>';
}

function reportSubTypeCell($prodType, $labels, $isSubRow)
{
    $text = reportTranslateProdType($prodType, $labels);
    if ($isSubRow) {
        return '<span class="row-sub"><span class="row-sub__dot"></span>' . htmlspecialchars($text) . '</span>';
    }
    return '<span class="row-main">' . htmlspecialchars($text) . '</span>';
}

function reportCommonStyles()
{
    ?>
    <style>
        :root {
            --rp-bg: #f4f5f7;
            --rp-surface: #ffffff;
            --rp-surface-2: #f0f2f5;
            --rp-text: #00335b;
            --rp-muted: #6b7a8a;
            --rp-border: #dde2e8;
            --rp-primary: #00335b;
            --rp-primary-soft: #e8eef4;
            --rp-accent: #72c4b1;
            --rp-accent-soft: #e8f7f3;
            --rp-shadow: 0 10px 28px rgba(0, 51, 91, 0.08);
            --rp-radius: 10px;
        }

        .report-page {
            font-family: "Montserrat", "Segoe UI", sans-serif;
            color: var(--rp-text);
            background: linear-gradient(180deg, #ffffff 0%, var(--rp-bg) 100%);
            padding: 10px;
            min-height: 100vh;
            box-sizing: border-box;
        }

        .report-page *, .report-page *::before, .report-page *::after { box-sizing: border-box; }

        .report-hero {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 10px;
            padding: 16px 18px;
            border-radius: 4px;
            background: linear-gradient(135deg, #002445 0%, #00335b 55%, #0a4a75 100%);
            color: #fff;
            box-shadow: var(--rp-shadow);
            position: relative;
            overflow: hidden;
        }

        .report-hero::after {
            content: "";
            position: absolute;
            inset: auto -40px -80px auto;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(114, 196, 177, 0.18);
        }

        .report-hero__eyebrow {
            margin: 0 0 8px;
            font-size: 11px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--rp-accent);
            font-weight: 700;
            opacity: 1;
        }

        .report-hero__title {
            margin: 0;
            font-size: clamp(22px, 3vw, 28px);
            line-height: 1.15;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .report-hero__subtitle {
            margin: 6px 0 0;
            max-width: 720px;
            color: rgba(255,255,255,0.82);
            font-size: 13px;
            font-weight: 400;
            text-transform: none;
            letter-spacing: 0;
        }

        .report-main { display: grid; gap: 10px; }

        .report-filter,
        .report-block {
            background: var(--rp-surface);
            border: 1px solid var(--rp-border);
            border-radius: 4px;
            box-shadow: var(--rp-shadow);
        }

        .report-filter { padding: 14px 16px; }

        .report-filter__head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            margin-bottom: 12px;
        }

        .report-filter__eyebrow,
        .report-block__eyebrow {
            margin: 0 0 4px;
            font-size: 11px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--rp-accent);
            font-weight: 700;
        }

        .report-filter__title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: var(--rp-primary);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .report-filter__grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
        }

        .report-filter--cashflow {
            width: 100%;
        }

        .report-filter__grid--cashflow {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }

        .report-filter__grid--cashflow .report-field {
            flex: 0 0 auto;
            width: 180px;
            max-width: 180px;
        }

        .report-field label {
            display: block;
            margin-bottom: 7px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--rp-muted);
        }

        .report-field select,
        .report-field input[type="date"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--rp-border);
            border-radius: 4px;
            background: var(--rp-surface-2);
            color: var(--rp-text);
            font: inherit;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .report-field select:focus,
        .report-field input[type="date"]:focus {
            outline: none;
            border-color: var(--rp-accent);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(114, 196, 177, 0.2);
        }

        .report-filter__actions {
            display: flex;
            gap: 10px;
            margin-top: 14px;
            flex-wrap: wrap;
            align-items: center;
        }

        .report-filter__actions .btn-export {
            margin-left: auto;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 18px;
            border: none;
            border-radius: 2px;
            cursor: pointer;
            font: inherit;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-size: 12px;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary {
            background: var(--rp-primary);
            color: #fff;
            box-shadow: 0 8px 20px rgba(0, 51, 91, 0.22);
        }

        .btn-primary:hover {
            background: #002445;
        }

        .btn-ghost {
            background: #fff;
            color: var(--rp-text);
            border: 1px solid var(--rp-border);
        }

        .btn-export {
            background: var(--rp-accent-soft);
            color: #1a6b5c;
            border: 1px solid #b8e4da;
        }

        .btn-icon {
            width: 22px;
            height: 22px;
            display: inline-grid;
            place-items: center;
            border-radius: 2px;
            background: rgba(255,255,255,0.72);
            font-size: 12px;
        }

        .report-block__head {
            padding: 12px 16px 0;
        }

        .report-block__title,
        .table-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: var(--rp-primary);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .table-wrap {
            overflow: auto;
            padding: 10px 8px 8px;
        }

        .report-table,
        .sales-table,
        .cashflow-table,
        #table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 640px;
            font-size: 14px;
        }

        .report-table thead th,
        .sales-table thead th,
        .cashflow-table thead th,
        #table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 13px 14px;
            text-align: left;
            background: var(--rp-primary-soft);
            color: var(--rp-primary);
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border-bottom: 1px solid var(--rp-border);
        }

        .report-table tbody td,
        .sales-table tbody td,
        .cashflow-table tbody td,
        #table tbody td {
            padding: 13px 14px;
            border-bottom: 1px solid #eef1f4;
            background: #fff;
            color: #2a3a4a;
        }

        .report-table tbody tr:hover td,
        .sales-table tbody tr:hover td,
        .cashflow-table tbody tr:hover td,
        #table tbody tr:hover td {
            background: #f7faf9;
        }

        .report-table tbody tr:last-child td,
        .sales-table tbody tr:last-child td,
        .cashflow-table tbody tr:last-child td,
        #table tbody tr:last-child td {
            border-bottom: none;
        }

        .total-row td {
            background: linear-gradient(180deg, #eef4f9, #e4edf5) !important;
            font-weight: 700;
            color: var(--rp-primary);
        }

        .sub-row td {
            background: #fafbfc !important;
        }

        .row-main { font-weight: 600; color: var(--rp-primary); }
        .row-sub {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--rp-muted);
            font-size: 13px;
        }
        .row-sub__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--rp-accent);
            flex-shrink: 0;
        }

        .amount,
        .report-table td:not(:first-child),
        .cashflow-table td:not(:first-child) {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .report-table td:first-child,
        .cashflow-table td:first-child { text-align: left; }

        .report-table--matrix th,
        .report-table--matrix td {
            text-align: center !important;
        }

        .report-empty {
            text-align: center;
            padding: 28px !important;
            color: var(--rp-muted);
        }

        .report-page-loader {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: grid;
            place-items: center;
            background: rgba(244, 245, 247, 0.82);
            backdrop-filter: blur(3px);
        }

        .report-page-loader[hidden] {
            display: none !important;
        }

        .report-page-loader__card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            min-width: 220px;
            padding: 26px 32px;
            border-radius: 4px;
            background: #fff;
            border: 1px solid var(--rp-border);
            box-shadow: 0 16px 40px rgba(0, 51, 91, 0.12);
        }

        .report-page-loader__spinner {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 3px solid #d5dde6;
            border-top-color: var(--rp-primary);
            animation: report-spin 0.75s linear infinite;
        }

        .report-page-loader__text {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: var(--rp-text);
        }

        .report-page-loader__sub {
            margin: 0;
            font-size: 12px;
            color: var(--rp-muted);
        }

        body.is-report-loading {
            overflow: hidden;
        }

        @keyframes report-spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .report-page { padding: 8px; }
            .report-hero { flex-direction: column; padding: 14px; }
            .report-filter__head { flex-direction: column; align-items: stretch; }
            .btn-export { width: 100%; justify-content: center; }
        }
    </style>
    <?php
}
