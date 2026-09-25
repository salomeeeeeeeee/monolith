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

function reportMapProductFetchRow(array $fields, array $props = [])
{
    $row = [
        'ID' => $fields['ID'] ?? '',
        'IBLOCK_ID' => $fields['IBLOCK_ID'] ?? '',
        'NAME' => $fields['~NAME'] ?? ($fields['NAME'] ?? ''),
    ];

    // Prefer GetProperties() — CRM bind fields (ownerDeal) are often empty via PROPERTY_* GetNext.
    if (!empty($props)) {
        foreach (reportProductPropertyCodes() as $code) {
            if (!isset($props[$code])) {
                continue;
            }
            $row[$code] = reportScalarProp($props[$code]['VALUE'] ?? '');
        }
    } else {
        foreach (reportProductPropertyCodes() as $code) {
            $key = 'PROPERTY_' . $code . '_VALUE';
            if (array_key_exists($key, $fields)) {
                $row[$code] = reportScalarProp($fields[$key]);
            }
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

    // v2: load CRM-bind props via GetProperties (ownerDeal was empty with PROPERTY_* GetNext)
    $cacheKey = 'products_v2_' . md5(serialize($filter));
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
        ['ID', 'IBLOCK_ID', 'NAME']
    );
    while ($ob = $res->GetNextElement()) {
        $fields = $ob->GetFields();
        $id = (int)($fields['ID'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $row = reportMapProductFetchRow($fields, $ob->GetProperties());
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
 * Attach bedroom count, barter and deal-side pricing from linked OWNER_DEAL onto product rows.
 * Adds DEAL_PRICE (deal amount, OPPORTUNITY) and DEAL_KVM_PRICE (deal price per sqm).
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

    $dealMeta = [];
    if (!empty($dealIds)) {
        $res = CCrmDeal::GetList(
            ['ID' => 'ASC'],
            ['ID' => array_keys($dealIds), 'CHECK_PERMISSIONS' => 'N'],
            ['ID', 'OPPORTUNITY', D_BEDROOMS, D_BARTER, D_KVM_PRICE]
        );
        while ($row = $res->Fetch()) {
            $dealMeta[(string)$row['ID']] = $row;
        }
    }

    foreach ($products as $id => $product) {
        $dealId = reportExtractProductOwnerDealId($product);
        $meta = ($dealId !== '' && isset($dealMeta[$dealId])) ? $dealMeta[$dealId] : null;
        $products[$id][D_BEDROOMS] = $meta ? (string)($meta[D_BEDROOMS] ?? '') : '';
        $products[$id][D_BARTER] = $meta ? (string)($meta[D_BARTER] ?? '') : '';
        $products[$id]['DEAL_PRICE'] = $meta ? reportParseAmount($meta['OPPORTUNITY'] ?? '') : 0;
        $products[$id]['DEAL_KVM_PRICE'] = $meta ? reportParseAmount($meta[D_KVM_PRICE] ?? '') : 0;
    }

    return $products;
}

/**
 * Display form of a filter value: trimmed, "X /X /X" collapsed to "X",
 * then the REPORT_FILTER_LABELS label if there is one.
 */
function reportFilterLabel($value)
{
    $text = trim((string)reportScalarProp($value));
    if (strpos($text, '/') !== false) {
        $parts = array_values(array_filter(array_map('trim', explode('/', $text)), 'strlen'));
        if ($parts && count(array_unique(array_map('mb_strtolower', $parts))) === 1) {
            $text = $parts[0];
        }
    }
    return REPORT_FILTER_LABELS[mb_strtolower($text)] ?? $text;
}

/** Comparison key: values with the same key are one filter option. */
function reportFilterKey($value)
{
    return mb_strtolower(reportFilterLabel($value));
}

/** Filter options of a field: one per key, labelled with its most common spelling. */
function reportGetUniqueValues($items, $field)
{
    $spellings = [];
    foreach ($items as $item) {
        $label = reportFilterLabel($item[$field] ?? '');
        if (!empty($label)) {
            $key = mb_strtolower($label);
            $spellings[$key][$label] = ($spellings[$key][$label] ?? 0) + 1;
        }
    }
    $values = [];
    foreach ($spellings as $counts) {
        arsort($counts);
        $values[] = (string)key($counts);
    }
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
    return reportExtractDealId($product['ownerDeal'] ?? '');
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
 * Uses GetProperties() so CRM-bind DEAL is populated (PROPERTY_* GetNext often leaves it empty).
 */
function reportLoadAllIblockPropertyRows($iblockId, array $sort = ['ID' => 'ASC'])
{
    static $runtime = [];
    $iblockId = (int)$iblockId;
    if ($iblockId <= 0) {
        return [];
    }

    // Always load by ID — Bitrix property-sort + pagination skips/duplicates rows.
    $cacheKey = 'iblock_rows_v3_' . $iblockId;
    if (isset($runtime[$cacheKey])) {
        return $runtime[$cacheKey];
    }

    $cached = reportCacheGet($cacheKey, '/crm/deal/reports/iblock');
    if (is_array($cached)) {
        return $runtime[$cacheKey] = $cached;
    }

    $rows = [];
    $lastId = 0;
    $pageSize = 500;

    do {
        $pageCount = 0;
        $res = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => $iblockId,
                'CHECK_PERMISSIONS' => 'N',
                '>ID' => $lastId,
            ],
            false,
            ['nPageSize' => $pageSize],
            ['ID', 'IBLOCK_ID', 'NAME']
        );

        while ($ob = $res->GetNextElement()) {
            $pageCount++;
            $fields = $ob->GetFields();
            $lastId = (int)$fields['ID'];
            $row = [
                'ID' => $fields['ID'],
                'IBLOCK_ID' => $fields['IBLOCK_ID'] ?? '',
                'NAME' => $fields['~NAME'] ?? ($fields['NAME'] ?? ''),
            ];
            foreach ($ob->GetProperties() as $code => $prop) {
                $propCode = !empty($prop['CODE']) ? $prop['CODE'] : $code;
                $row[$propCode] = reportScalarProp($prop['VALUE'] ?? '');
            }
            $rows[] = $row;
        }
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

    foreach (reportLoadIblockRowsForDeals(REPORT_SCHEDULE_IBLOCK, $dealIds) as $row) {
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

    foreach (reportLoadIblockRowsForDeals(REPORT_SCHEDULE_IBLOCK, $dealIds) as $row) {
        $dateRaw = $row['TARIGI'] ?? '';
        $dateObj = reportParseDate($dateRaw);
        if (!$dateObj) {
            continue;
        }
        if ($fromObj && $dateObj < $fromObj) {
            continue;
        }
        if ($toObj && $dateObj > $toObj) {
            continue;
        }

        $daricxvebi[] = [
            'DEAL_ID' => $row['_DEAL_ID'],
            'DATE' => $dateObj->format('Y-m-d'),
            'AMOUNT' => reportParseAmount($row['TANXA'] ?? ($row['TANXA_NUMBR'] ?? 0)),
        ];
    }

    foreach (reportLoadIblockRowsForDeals(REPORT_PAYMENT_IBLOCK, $dealIds) as $row) {
        $dateRaw = $row['date'] ?? ($row['TARIGI'] ?? '');
        $dateObj = reportParseDate($dateRaw);
        if (!$dateObj) {
            continue;
        }
        if ($fromObj && $dateObj < $fromObj) {
            continue;
        }
        if ($toObj && $dateObj > $toObj) {
            continue;
        }

        $gadaxdebi[] = [
            'DEAL_ID' => $row['_DEAL_ID'],
            'DATE' => $dateObj->format('Y-m-d'),
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
            'filter_barter' => 'ბარტერი:',
            'filter_responsible' => 'პასუხისმგებელი:',
            'all_projects' => 'ყველა პროექტი',
            'all_sectors' => 'ყველა სექტორი',
            'all_blocks' => 'ყველა ბლოკი',
            'all_barter' => 'ყველა',
            'all_responsible' => 'ყველა პასუხისმგებელი',
            'barter_yes' => 'დიახ',
            'barter_no' => 'არა',
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
            'filter_barter' => 'Barter:',
            'filter_responsible' => 'Responsible:',
            'all_projects' => 'All Projects',
            'all_sectors' => 'All Sectors',
            'all_blocks' => 'All Blocks',
            'all_barter' => 'All',
            'all_responsible' => 'All Responsible',
            'barter_yes' => 'Yes',
            'barter_no' => 'No',
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

/**
 * Selected values of a multi-select filter from GET (name[]=a&name[]=b).
 * A plain name=a is accepted too, so old links keep working.
 */
function reportGetFilterValues($name)
{
    $raw = $_GET[$name] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    $values = [];
    foreach ($raw as $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $value = trim((string)$value);
        if ($value !== '' && !in_array($value, $values, true)) {
            $values[] = $value;
        }
    }
    return $values;
}

/**
 * True when nothing is selected (= all) or the value is one of the selected ones.
 * Compared by reportFilterKey(), so "Ethno City", "Ethno city" and "Ethno city /Ethno city" match each other.
 */
function reportValueMatches($value, array $selected)
{
    if (empty($selected)) {
        return true;
    }
    return in_array(reportFilterKey($value), array_map('reportFilterKey', $selected), true);
}

function reportFilterProducts(array $products, array $filters)
{
    $filtered = [];
    foreach ($products as $product) {
        $match = reportValueMatches($product[F_PROJECT] ?? '', $filters['project'] ?? [])
            && reportValueMatches($product[F_SECTOR] ?? '', $filters['sector'] ?? [])
            && reportValueMatches($product[F_BLOCK] ?? '', $filters['block'] ?? [])
            && reportValueMatches($product['DEAL_RESPONSIBLE_NAME'] ?? '', $filters['responsible'] ?? []);
        if ($match && !empty($filters['barter'])) {
            $barterValue = (string)($product[D_BARTER] ?? '');
            $barterMatch = false;
            foreach ($filters['barter'] as $wanted) {
                // "არა" = ყველაფერი, რაც ბარტერად არ არის მონიშნული (მათ შორის ცარიელი).
                if ((string)$wanted === D_BARTER_NO ? $barterValue !== D_BARTER_YES : $barterValue === (string)$wanted) {
                    $barterMatch = true;
                    break;
                }
            }
            $match = $barterMatch;
        }
        if ($match) {
            $filtered[$product['ID']] = $product;
        }
    }
    return $filtered;
}

/**
 * <select multiple> that reportPageEnd() turns into a checkbox dropdown.
 * Nothing selected means "all" ($allLabel is shown then).
 *
 * @param array $options value list, or value => label map when $assoc is true
 */
function reportRenderMultiSelect($name, $id, $allLabel, array $options, array $selected, $assoc = false)
{
    $selectedKeys = array_map('reportFilterKey', $selected);
    ?>
    <select name="<?= htmlspecialchars($name) ?>[]" id="<?= htmlspecialchars($id) ?>" multiple data-multi data-all="<?= htmlspecialchars($allLabel) ?>">
        <?php foreach ($options as $key => $option): ?>
            <?php $optValue = (string)($assoc ? $key : $option); ?>
            <option value="<?= htmlspecialchars($optValue) ?>" <?= in_array(reportFilterKey($optValue), $selectedKeys, true) ? 'selected' : '' ?>>
                <?= htmlspecialchars($option) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

function reportRenderFilterForm($filters, $options, $labels, $lang, $schema = 'inventory')
{
    $fields = [];
    if ($schema === 'deals') {
        $fields = [
            ['name' => 'project', 'id' => 'project', 'label' => $labels['filter_project'], 'all' => $labels['all_projects'], 'options' => $options['projects'] ?? [], 'value' => $filters['project'] ?? []],
            ['name' => 'block', 'id' => 'block', 'label' => $labels['filter_block'], 'all' => $labels['all_blocks'], 'options' => $options['blocks'] ?? [], 'value' => $filters['block'] ?? []],
            ['name' => 'responsible', 'id' => 'responsible', 'label' => $labels['filter_responsible'], 'all' => $labels['all_responsible'], 'options' => $options['responsibles'] ?? [], 'value' => $filters['responsible'] ?? [], 'assoc' => true],
        ];
    } else {
        $fields = [
            ['name' => 'project', 'id' => 'project', 'label' => $labels['filter_project'], 'all' => $labels['all_projects'], 'options' => $options['projects'] ?? [], 'value' => $filters['project'] ?? []],
            ['name' => 'sector', 'id' => 'sector', 'label' => $labels['filter_sector'], 'all' => $labels['all_sectors'], 'options' => $options['sectors'] ?? [], 'value' => $filters['sector'] ?? []],
            ['name' => 'block', 'id' => 'block', 'label' => $labels['filter_block'], 'all' => $labels['all_blocks'], 'options' => $options['blocks'] ?? [], 'value' => $filters['block'] ?? []],
        ];
        if (!empty($options['barters'])) {
            $fields[] = [
                'name' => 'barter',
                'id' => 'barter',
                'label' => $labels['filter_barter'],
                'all' => $labels['all_barter'],
                'options' => $options['barters'],
                'value' => $filters['barter'] ?? [],
                'assoc' => true,
            ];
        }
        $fields[] = ['name' => 'responsible', 'id' => 'responsible', 'label' => $labels['filter_responsible'], 'all' => $labels['all_responsible'], 'options' => $options['responsibles'] ?? [], 'value' => $filters['responsible'] ?? []];
    }
    ?>
    <section class="report-filter">
        <form method="GET" action="" class="report-filter__form">
            <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
            <div class="report-filter__grid">
                <?php foreach ($fields as $field): ?>
                    <div class="report-field">
                        <label for="<?= $field['id'] ?>"><?= $field['label'] ?></label>
                        <?php reportRenderMultiSelect($field['name'], $field['id'], $field['all'], $field['options'], (array)$field['value'], !empty($field['assoc'])); ?>
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
                    <?php reportRenderMultiSelect('project', 'project', 'ყველა', $projects, (array)$project); ?>
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
    $multiText = $lang === 'eng'
        ? ['all' => 'All', 'search' => 'Search...', 'noMatch' => 'No matches']
        : ['all' => 'ყველა', 'search' => 'ძებნა...', 'noMatch' => 'ვერ მოიძებნა'];
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

    // Multi-select filters: <select multiple data-multi> -> dropdown with checkboxes.
    (function () {
        var TEXT = <?= json_encode($multiText, JSON_UNESCAPED_UNICODE) ?>;
        var current = null;

        function close() {
            if (!current) return;
            current.panel.hidden = true;
            current.toggle.setAttribute('aria-expanded', 'false');
            current.root.classList.remove('is-open');
            current = null;
        }

        function makeRow(label) {
            var row = document.createElement('label');
            row.className = 'ms__option';
            var box = document.createElement('input');
            box.type = 'checkbox';
            var span = document.createElement('span');
            span.textContent = label;
            row.appendChild(box);
            row.appendChild(span);
            return { row: row, box: box, label: label };
        }

        function init(select) {
            var allLabel = select.getAttribute('data-all') || TEXT.all;
            var root = document.createElement('div');
            root.className = 'ms';

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'ms__toggle';
            toggle.setAttribute('aria-haspopup', 'true');
            toggle.setAttribute('aria-expanded', 'false');
            if (select.id) toggle.id = select.id + '__ms';
            var text = document.createElement('span');
            text.className = 'ms__text';
            var count = document.createElement('span');
            count.className = 'ms__count';
            toggle.appendChild(text);
            toggle.appendChild(count);

            var panel = document.createElement('div');
            panel.className = 'ms__panel';
            panel.hidden = true;

            var search = null;
            if (select.options.length > 8) {
                search = document.createElement('input');
                search.type = 'search';
                search.className = 'ms__search';
                search.placeholder = TEXT.search;
                panel.appendChild(search);
            }

            var list = document.createElement('div');
            list.className = 'ms__list';
            panel.appendChild(list);

            var allRow = makeRow(allLabel);
            allRow.row.classList.add('ms__option--all');
            list.appendChild(allRow.row);

            var rows = [];
            Array.prototype.forEach.call(select.options, function (option) {
                var r = makeRow(option.text.trim());
                r.option = option;
                r.box.addEventListener('change', function () {
                    option.selected = r.box.checked;
                    refresh();
                });
                rows.push(r);
                list.appendChild(r.row);
            });

            var empty = document.createElement('div');
            empty.className = 'ms__empty';
            empty.textContent = TEXT.noMatch;
            empty.hidden = true;
            list.appendChild(empty);

            // "ყველა" = nothing selected.
            allRow.box.addEventListener('change', function () {
                rows.forEach(function (r) { r.option.selected = false; });
                refresh();
            });

            function refresh() {
                var chosen = rows.filter(function (r) { return r.option.selected; });
                rows.forEach(function (r) { r.box.checked = r.option.selected; });
                allRow.box.checked = chosen.length === 0;
                text.textContent = chosen.length
                    ? chosen.map(function (r) { return r.label; }).join(', ')
                    : allLabel;
                count.textContent = chosen.length;
                count.hidden = chosen.length < 2;
                toggle.classList.toggle('has-value', chosen.length > 0);
                toggle.title = chosen.length ? text.textContent : '';
            }

            function applySearch() {
                var q = search ? search.value.trim().toLowerCase() : '';
                var visible = 0;
                rows.forEach(function (r) {
                    var show = q === '' || r.label.toLowerCase().indexOf(q) !== -1;
                    r.row.hidden = !show;
                    if (show) visible++;
                });
                allRow.row.hidden = q !== '';
                empty.hidden = visible > 0;
            }

            if (search) {
                search.addEventListener('input', applySearch);
                search.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') e.preventDefault();
                });
            }

            toggle.addEventListener('click', function () {
                if (current && current.root === root) {
                    close();
                    return;
                }
                close();
                panel.classList.remove('ms__panel--right');
                panel.hidden = false;
                if (panel.getBoundingClientRect().right > document.documentElement.clientWidth - 8) {
                    panel.classList.add('ms__panel--right');
                }
                toggle.setAttribute('aria-expanded', 'true');
                root.classList.add('is-open');
                current = { root: root, panel: panel, toggle: toggle };
                if (search) {
                    search.value = '';
                    applySearch();
                    search.focus();
                }
            });

            select.parentNode.insertBefore(root, select);
            root.appendChild(toggle);
            root.appendChild(panel);
            root.appendChild(select);
            select.classList.add('ms-native');
            if (select.id) {
                var label = document.querySelector('label[for="' + select.id + '"]');
                if (label) label.htmlFor = toggle.id;
            }
            refresh();
        }

        document.querySelectorAll('select[multiple][data-multi]').forEach(init);

        document.addEventListener('click', function (e) {
            if (current && !current.root.contains(e.target)) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && current) {
                var toggle = current.toggle;
                close();
                toggle.focus();
            }
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

        .ms { position: relative; }
        .ms-native { display: none !important; }

        .ms__toggle {
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 12px 34px 12px 14px;
            border: 1px solid var(--rp-border);
            border-radius: 4px;
            background: var(--rp-surface-2);
            color: var(--rp-text);
            font: inherit;
            text-align: left;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .ms__toggle::after {
            content: "";
            position: absolute;
            right: 14px;
            top: 50%;
            width: 6px;
            height: 6px;
            border-right: 2px solid var(--rp-muted);
            border-bottom: 2px solid var(--rp-muted);
            transform: translateY(-70%) rotate(45deg);
            transition: transform 0.15s ease;
        }

        .ms.is-open .ms__toggle::after { transform: translateY(-25%) rotate(-135deg); }

        .ms__toggle:focus,
        .ms.is-open .ms__toggle {
            outline: none;
            border-color: var(--rp-accent);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(114, 196, 177, 0.2);
        }

        .ms__toggle.has-value {
            background: #fff;
            border-color: #b8e4da;
        }

        .ms__text {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .ms__count {
            flex-shrink: 0;
            min-width: 20px;
            padding: 1px 6px;
            border-radius: 999px;
            background: var(--rp-primary);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
        }

        .ms__count[hidden],
        .ms__panel[hidden],
        .ms .ms__option[hidden],
        .ms__empty[hidden] { display: none; }

        .ms__panel {
            position: absolute;
            z-index: 60;
            top: calc(100% + 4px);
            left: 0;
            min-width: 100%;
            width: max-content;
            max-width: 340px;
            padding: 6px;
            border: 1px solid var(--rp-border);
            border-radius: 4px;
            background: #fff;
            box-shadow: 0 16px 40px rgba(0, 51, 91, 0.16);
        }

        .ms__panel--right {
            left: auto;
            right: 0;
        }

        .ms__search {
            width: 100%;
            margin-bottom: 6px;
            padding: 8px 10px;
            border: 1px solid var(--rp-border);
            border-radius: 4px;
            font: inherit;
            font-size: 13px;
            color: var(--rp-text);
        }

        .ms__search:focus {
            outline: none;
            border-color: var(--rp-accent);
        }

        .ms__list {
            max-height: 280px;
            overflow-y: auto;
        }

        .ms .ms__option {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            padding: 7px 8px;
            border-radius: 3px;
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 0;
            text-transform: none;
            color: var(--rp-text);
            cursor: pointer;
        }

        .ms .ms__option:hover { background: var(--rp-accent-soft); }

        .ms .ms__option--all {
            margin-bottom: 4px;
            border-bottom: 1px solid #eef1f4;
            border-radius: 0;
            font-weight: 700;
        }

        .ms__option input {
            flex-shrink: 0;
            width: 15px;
            height: 15px;
            margin: 0;
            accent-color: var(--rp-primary);
            cursor: pointer;
        }

        .ms__empty {
            padding: 8px;
            font-size: 12px;
            color: var(--rp-muted);
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
