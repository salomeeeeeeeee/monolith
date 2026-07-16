<?php

require_once __DIR__ . '/config.php';

function reportGetNbgRate($date = null)
{
    $date = $date ?: date('Y-m-d');
    $url = "https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=USD&date={$date}";
    $response = @file_get_contents($url);
    if ($response === false) {
        return 1;
    }
    $data = json_decode($response);
    return $data[0]->currencies[0]->rate ?? 1;
}

function reportGetUserName($id)
{
    if (empty($id)) {
        return '';
    }
    $res = CUser::GetByID($id)->Fetch();
    return trim(($res['NAME'] ?? '') . ' ' . ($res['LAST_NAME'] ?? ''));
}

function reportGetContactName($contactId)
{
    if (empty($contactId)) {
        return '';
    }
    $res = CCrmContact::GetList([], ['ID' => $contactId], ['ID', 'NAME', 'LAST_NAME']);
    if ($row = $res->Fetch()) {
        return trim($row['NAME'] . ' ' . $row['LAST_NAME']);
    }
    return '';
}

function reportGetDealsByFilter($arFilter, $arSelect = [], $arSort = ['ID' => 'ASC'])
{
    $resArr = [];
    $res = CCrmDeal::GetList($arSort, $arFilter, $arSelect);
    while ($arDeal = $res->Fetch()) {
        $resArr[$arDeal['ID']] = $arDeal;
    }
    return $resArr;
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
    $filter = array_merge(['IBLOCK_ID' => REPORT_PRODUCT_IBLOCK], $arFilter);
    $nbg = reportGetNbgRate(date('Y-m-d'));
    $elements = [];

    $res = CIBlockElement::GetList([], $filter, false, ['nPageSize' => 99999], []);
    while ($ob = $res->GetNextElement()) {
        $row = reportNormalizeProductRow($ob->GetFields(), $ob->GetProperties(), $nbg);
        $elements[$row['ID']] = $row;
    }

    return $elements;
}

function reportGetAllInventoryProducts()
{
    return reportGetProducts();
}

function reportGetSoldProducts()
{
    $products = reportGetProducts();
    return array_filter($products, function ($product) {
        return ($product[F_STATUS] ?? '') === 'გაყიდული';
    });
}

function reportGetReservedProducts()
{
    $products = reportGetProducts();
    return array_filter($products, function ($product) {
        return ($product[F_STATUS] ?? '') === REPORT_RESERVED_STATUS;
    });
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

function reportGetUniqueValues($items, $field)
{
    $values = [];
    foreach ($items as $item) {
        if (!empty($item[$field]) && !in_array($item[$field], $values, true)) {
            $values[] = $item[$field];
        }
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
    $page = 1;
    $pageSize = 500;

    do {
        $pageCount = 0;
        $res = CIBlockElement::GetList(
            $sort,
            ['IBLOCK_ID' => (int)$iblockId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nPageSize' => $pageSize, 'iNumPage' => $page],
            ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_*']
        );

        while ($ob = $res->GetNextElement()) {
            $pageCount++;
            $fields = $ob->GetFields();
            $props = $ob->GetProperties();
            $row = [];
            foreach ($fields as $key => $val) {
                $row[$key] = $val;
            }
            foreach ($props as $key => $prop) {
                $code = !empty($prop['CODE']) ? $prop['CODE'] : $key;
                $row[$code] = $prop['VALUE'];
            }

            $dealId = reportExtractDealId($row['DEAL'] ?? '');
            if ($dealId === '' || !isset($dealIdSet[$dealId])) {
                continue;
            }
            $row['_DEAL_ID'] = $dealId;
            $rows[] = $row;
        }

        $page++;
    } while ($pageCount === $pageSize);

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

function reportResolveApartmentSubtype($product)
{
    if (($product[F_TYPE] ?? '') !== 'ბინა') {
        return null;
    }

    $bedrooms = (string)($product[F_BEDROOMS] ?? '');
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            --rp-bg: #ece8df;
            --rp-surface: #ffffff;
            --rp-surface-2: #f7f5f0;
            --rp-text: #1a2b27;
            --rp-muted: #5f726c;
            --rp-border: #d8e0dc;
            --rp-primary: #1f4d3a;
            --rp-primary-soft: #e6f2ec;
            --rp-accent: #b8892f;
            --rp-accent-soft: #faf3e3;
            --rp-shadow: 0 14px 40px rgba(26, 43, 39, 0.08);
            --rp-radius: 18px;
        }

        .report-page {
            font-family: "Plus Jakarta Sans", "Segoe UI", sans-serif;
            color: var(--rp-text);
            background:
                radial-gradient(circle at top right, rgba(184, 137, 47, 0.12), transparent 28%),
                linear-gradient(180deg, #f3efe7 0%, var(--rp-bg) 100%);
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
            border-radius: 14px;
            background: linear-gradient(135deg, #173529 0%, #245741 55%, #2f6b52 100%);
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
            background: rgba(255,255,255,0.08);
        }

        .report-hero__eyebrow {
            margin: 0 0 8px;
            font-size: 12px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            opacity: 0.72;
        }

        .report-hero__title {
            margin: 0;
            font-size: clamp(22px, 3vw, 28px);
            line-height: 1.15;
            font-weight: 700;
        }

        .report-hero__subtitle {
            margin: 6px 0 0;
            max-width: 720px;
            color: rgba(255,255,255,0.82);
            font-size: 13px;
        }

        .report-main { display: grid; gap: 10px; }

        .report-filter,
        .report-block {
            background: var(--rp-surface);
            border: 1px solid rgba(255,255,255,0.7);
            border-radius: 14px;
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
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--rp-muted);
        }

        .report-field select,
        .report-field input[type="date"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--rp-border);
            border-radius: 12px;
            background: var(--rp-surface-2);
            color: var(--rp-text);
            font: inherit;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .report-field select:focus,
        .report-field input[type="date"]:focus {
            outline: none;
            border-color: #7aa892;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(47, 107, 82, 0.12);
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
            border-radius: 12px;
            cursor: pointer;
            font: inherit;
            font-weight: 700;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        .btn:hover { transform: translateY(-1px); }

        .btn-primary {
            background: linear-gradient(135deg, #245741, #1f4d3a);
            color: #fff;
            box-shadow: 0 10px 24px rgba(31, 77, 58, 0.22);
        }

        .btn-ghost {
            background: #fff;
            color: var(--rp-text);
            border: 1px solid var(--rp-border);
        }

        .btn-export {
            background: var(--rp-accent-soft);
            color: #7a5a17;
            border: 1px solid #ecdab0;
        }

        .btn-icon {
            width: 22px;
            height: 22px;
            display: inline-grid;
            place-items: center;
            border-radius: 999px;
            background: rgba(255,255,255,0.72);
            font-size: 12px;
        }

        .report-block__head {
            padding: 12px 16px 0;
        }

        .report-block__title,
        .table-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
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
            background: #eef5f1;
            color: #234539;
            font-size: 12px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            border-bottom: 1px solid var(--rp-border);
        }

        .report-table tbody td,
        .sales-table tbody td,
        .cashflow-table tbody td,
        #table tbody td {
            padding: 13px 14px;
            border-bottom: 1px solid #edf1ef;
            background: #fff;
        }

        .report-table tbody tr:hover td,
        .sales-table tbody tr:hover td,
        .cashflow-table tbody tr:hover td,
        #table tbody tr:hover td {
            background: #f8fbf9;
        }

        .report-table tbody tr:last-child td,
        .sales-table tbody tr:last-child td,
        .cashflow-table tbody tr:last-child td,
        #table tbody tr:last-child td {
            border-bottom: none;
        }

        .total-row td {
            background: linear-gradient(180deg, #f3f8f5, #eaf2ed) !important;
            font-weight: 700;
            color: var(--rp-primary);
        }

        .sub-row td {
            background: #fcfdfc !important;
        }

        .row-main { font-weight: 600; }
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
            background: rgba(236, 232, 223, 0.78);
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
            border-radius: 16px;
            background: #fff;
            border: 1px solid #e6ece9;
            box-shadow: 0 16px 40px rgba(26, 43, 39, 0.12);
        }

        .report-page-loader__spinner {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 3px solid #d9e6df;
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
