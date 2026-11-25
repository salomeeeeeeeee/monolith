<?php
/**
 * ავტომატური SMS-ები კლიენტებს (2026-11-24). მხოლოდ New Depo, ნებისმიერი უძრავი ქონება.
 * წარსულში გასაგზავნი SMS უკან არ იგზავნება: დღიურები მხოლოდ იმ დღეს გროვდება, რომელ დღესაც უნდა წავიდეს.
 *
 *   reservation     დილი გადავიდა "დადასტურებულ რეზერვაციაზე"
 *   first_payment   დილზე პირველი გადახდა ჩაიწერა (სია 23; ბარტერი და უკუგატარება არ ითვლება)
 *   sold            დილი გადავიდა "გაყიდულზე"
 *   expiring        ჯავშნის ვადამდე 2 დღით ადრე
 *   reminder        გრაფიკის (სია 22) გადახდამდე 3 დღით ადრე, თუ ეს გადახდა წინასწარ დაფარული არ არის
 *   overdue         ვადაგადაცილების მომდევნო დღეს
 *   overdue_weekly  ვადაგადაცილებიდან მე-7, 14, 21, 28 დღეს, სანამ დაფარული არ არის
 *
 * ვალი = დღემდე დამდგარი გადახდები (სია 22) - გადახდილი (სია 23), სრული თანხა.
 * ენა: 1) კონტაქტის "საკომუნიკაციო ენა (მონოლითი)";  2) ცარიელია - დილის საკომუნიკაციო ენის ველი;
 *      3) ესეც ცარიელია - კლიენტის ნომრით: +995 ქართული, უცხოური ინგლისური. რუსული მხოლოდ მაშინ, თუ ველშია მითითებული.
 *
 * agent "monolithSmsAgent();" (ყოველ 5 წუთში, cron): მოვლენებს პოულობს და სია "SMS ისტორიაში" (sms_log.php) რიგში სვამს,
 * რიგს კი სამუშაო საათებში აგზავნის. დღიური SMS-ები (ვადები, გადახდები) დღეში ერთხელ გროვდება.
 *
 * რეჟიმი და პარამეტრები ბაზაშია (COption, მოდული monolith.sms):
 *   mode         off / test / live. test - ყველაფერი test_phone-ზე მიდის, კლიენტთან არაფერი. ცარიელი = off
 *   test_phone   სატესტო ნომერი ან რამდენიმე, მძიმით: ყველა SMS ყველა ამ ნომერზე მიდის (თითო ნომერი - ცალკე ჩანაწერი ლოგში)
 *   bank_tbc / bank_bog - ანგარიშები ტექსტში;  last_history_id, last_payment_id, daily_date - სად შეჩერდა
 */

const MONOLITH_SMS_OPTIONS      = 'monolith.sms';
const MONOLITH_SMS_PROJECT      = 'new depo';
const MONOLITH_SMS_BANK_BOG     = 'GE82BG0000000482087200';  // New Depo, როგორც ხელშეკრულებებშია
const MONOLITH_SMS_HOUR_FROM    = 10;       // გაგზავნის საათები (სერვერის დრო)
const MONOLITH_SMS_HOUR_TO      = 20;
const MONOLITH_SMS_DAILY_HOUR   = 11;       // ამ საათიდან გროვდება დღიური SMS-ები
const MONOLITH_SMS_BATCH        = 40;       // ერთ გაშვებაზე მაქსიმუმ ამდენი SMS
const MONOLITH_SMS_EXPIRE_DAYS  = 2;
const MONOLITH_SMS_REMIND_DAYS  = 3;
const MONOLITH_SMS_WEEKLY_DAYS  = [7, 14, 21, 28];
const MONOLITH_SMS_PAY_DAYS     = 7;        // ყოველკვირეულ SMS-ში "დაფაროთ XX/XX/XXXX" = გაგზავნიდან ამდენი დღე
const MONOLITH_SMS_PAID_STAGES  = ['EXECUTING', 'UC_NSTB3H', 'UC_NJ7A78', 'WON'];   // პირველი გადახდა
const MONOLITH_SMS_DEBT_STAGES  = ['UC_NJ7A78', 'WON'];                              // გადახდის შეხსენება და ვალი

const MONOLITH_SMS_SCHEDULE_IBLOCK = 22;
const MONOLITH_SMS_PAYMENT_IBLOCK  = 23;
const MONOLITH_SMS_REFUND_YES      = 94;    // სია 23 refund = YES
const MONOLITH_SMS_PAY_BARTER      = 125;   // სია 23 pay_type = ბარტერი

// დილის ველები
const MONOLITH_SMS_D_PROJECT    = 'UF_CRM_1779277729207';
const MONOLITH_SMS_D_TYPE       = 'UF_CRM_1779277898205';
const MONOLITH_SMS_D_UNIT       = 'UF_CRM_1779277613798';
const MONOLITH_SMS_D_CONTRACT   = 'UF_CRM_1791205458297';
const MONOLITH_SMS_D_RES_DATE   = 'UF_CRM_1779278567041';
// კონტაქტის "საკომუნიკაციო ენა (მონოლითი)": სიის ID => ენა
const MONOLITH_SMS_C_LANG       = 'UF_CRM_1795509844';
const MONOLITH_SMS_C_LANG_MAP   = [520 => 'ge', 521 => 'en', 522 => 'ru'];
// დილის საკომუნიკაციო ენა: ველი => [სიის ID => ენა]
const MONOLITH_SMS_D_LANGS      = [
    'UF_CRM_1767077782627' => [227 => 'ge', 228 => 'en', 229 => 'ru'],
    'UF_CRM_1774351227141' => [66 => 'ge', 67 => 'en', 68 => 'ru', 69 => 'en'],
    'UF_CRM_1688112524'    => [85 => 'ge', 87 => 'en', 86 => 'ru'],
];

function monolithSmsOption($name, $default = '')
{
    return (string)COption::GetOptionString(MONOLITH_SMS_OPTIONS, $name, $default);
}

function monolithSmsSetOption($name, $value)
{
    COption::SetOptionString(MONOLITH_SMS_OPTIONS, $name, (string)$value);
}

/** off / test / live; ნებისმიერი სხვა მნიშვნელობა = off */
function monolithSmsMode()
{
    $mode = monolithSmsOption('mode', 'off');
    return in_array($mode, ['test', 'live'], true) ? $mode : 'off';
}

// ── ტექსტები ────────────────────────────────────────────────────────────

/** ტიპი => დასახელება (ლოგში) */
function monolithSmsTypes()
{
    return [
        'reservation'    => 'დაჯავშნის დროს',
        'first_payment'  => 'პირველი თანხის ჩარიცხვის შემდეგ',
        'sold'           => 'უძრავი ქონების გაფორმების შემდეგ',
        'expiring'       => 'ჯავშნის ვადის გასვლამდე 2 დღით ადრე',
        'reminder'       => 'გადახდის დღემდე 3 დღით ადრე',
        'overdue'        => 'ვადაგადაცილების მომდევნო დღეს',
        'overdue_weekly' => 'ვადაგადაცილება - ყოველკვირეული',
    ];
}

/** {manager} = " - სახელი ნომერი" ან ცარიელი; დანარჩენი - monolithSmsVars() */
function monolithSmsTemplates()
{
    return [
        'reservation' => [
            'ge' => 'მოგესალმებით, თქვენი ჯავშანი დადასტურებულია. უძრავი ქონების ტიპი: {type}. ნომერი: {unit}. ჯავშნის ვადა: {date}. მადლობა, რომ აირჩიეთ "Monolith Group".',
            'en' => 'Hello, your reservation has been confirmed. Property type: {type}, unit number: {unit}. Reservation deadline: {date}. Thank you for choosing "Monolith Group".',
            'ru' => 'Добро пожаловать, ваше бронирование подтверждено. Тип недвижимости: {type}. Номер объекта: {unit}. Срок бронирования: {date}. Спасибо, что выбрали «Монолит Групп».',
        ],
        'first_payment' => [
            'ge' => 'მოგესალმებით, თქვენი პირველი შენატანი წარმატებით აისახა. თქვენთან კავშირზეა თქვენი პირადი მენეჯერი{manager}',
            'en' => 'Your first payment has been successfully received. Your personal manager is in touch{manager}',
            'ru' => 'Добро пожаловать! Ваш первый депозит успешно зачислен. С вами свяжется ваш персональный менеджер{manager}',
        ],
        'sold' => [
            'ge' => 'გილოცავთ! თქვენ უკვე „მონოლით ჯგუფის“ მობინადრე ხართ. მოხარულები ვართ, რომ თქვენი ახალი სახლის ისტორიის ნაწილი ვართ!',
            'en' => 'Congratulations! You are now a resident of "Monolith Group". We are delighted to be part of your new home story!',
            'ru' => 'Поздравляем! Теперь вы - житель «Монолит Групп». Мы рады стать частью истории вашего нового дома!',
        ],
        'expiring' => [
            'ge' => 'მოგესალმებით, შეგახსენებთ, რომ თქვენი ჯავშნის ვადა იწურება 2 დღეში. გთხოვთ დროულად დაუკავშირდეთ თქვენს პირად გაყიდვების მენეჯერს{manager}',
            'en' => 'Hello, we would like to remind you that your reservation expires in 2 days. Please contact your personal sales manager on time{manager}',
            'ru' => 'Здравствуйте, напоминаем, что срок действия вашей брони истекает через 2 дня. Пожалуйста, вовремя свяжитесь со своим персональным менеджером по продажам{manager}',
        ],
        'reminder' => [
            'ge' => 'მოგესალმებით, შეგახსენებთ, ხელშეკრულების {contract} თანახმად, შემდეგი გადახდის თარიღია {date}. ანგარიშის ნომერი: {accounts}',
            'en' => 'Dear customer, we remind you that according to the contract {contract}, the next payment date is {date}. Bank Account Numbers: {accounts}',
            'ru' => 'Здравствуйте, напоминаем, что согласно договору {contract} дата следующего платежа - {date}. Номера банковских счетов: {accounts}',
        ],
        'overdue' => [
            'ge' => 'მოგესალმებით, გაცნობებთ, რომ ხელშეკრულების {contract} თანახმად {today} თარიღისთვის, გერიცხებათ დავალიანება {amount}$ ოდენობით. გთხოვთ, დროულად დაფაროთ დავალიანება. ანგარიშის ნომერი: {accounts}',
            'en' => 'Dear customer, We would like to inform you that according to your contract {contract}, you owe ${amount}. We ask you to pay the outstanding amount on time. Bank Account Numbers: {accounts}',
            'ru' => 'Здравствуйте, сообщаем, что согласно договору {contract} по состоянию на {today} у вас имеется задолженность в размере {amount}$. Просим своевременно погасить задолженность. Номера банковских счетов: {accounts}',
        ],
        'overdue_weekly' => [
            'ge' => 'მოგესალმებით, გაცნობებთ, რომ ხელშეკრულების {contract} თანახმად გერიცხებათ დავალიანება {amount}$ ოდენობით. გთხოვთ, დროულად დაფაროთ არსებული დავალიანება {payby} თარიღისთვის. წინააღმდეგ შემთხვევაში გამოყენებული იქნება ხელშეკრულებით გათვალისწინებული პირობები. ანგარიშის ნომერი: {accounts}',
            'en' => 'Dear customer, We would like to inform you that according to your contract {contract}, you owe ${amount}. We ask you to pay the outstanding amount on time. Otherwise, the contractual conditions will apply. Bank Account Numbers: {accounts}',
            'ru' => 'Здравствуйте, сообщаем, что согласно договору {contract} у вас имеется задолженность в размере {amount}$. Просим своевременно погасить существующую задолженность до {payby}. В противном случае будут применены условия, предусмотренные договором. Номера банковских счетов: {accounts}',
        ],
    ];
}

function monolithSmsText($type, $lang, array $vars)
{
    $templates = monolithSmsTemplates();
    $template = $templates[$type][$lang] ?? '';
    $replace = [];
    foreach ($vars as $name => $value) {
        $replace['{' . $name . '}'] = (string)$value;
    }
    return trim(strtr($template, $replace));
}

/** ფართის ტიპი დილიდან => SMS-ში */
function monolithSmsPropertyType($type, $lang)
{
    $map = [
        'ბინა'             => ['ge' => 'ბინა', 'en' => 'Apartment', 'ru' => 'Квартира'],
        'ავტოსადგომი'      => ['ge' => 'ავტოფარეხი', 'en' => 'Parking', 'ru' => 'Паркинг'],
        'კომერციული'       => ['ge' => 'კომერციული', 'en' => 'Commercial space', 'ru' => 'Коммерческое помещение'],
        'კომერციული ფართი' => ['ge' => 'კომერციული', 'en' => 'Commercial space', 'ru' => 'Коммерческое помещение'],
        'ოფისი'            => ['ge' => 'ოფისი', 'en' => 'Office', 'ru' => 'Офис'],
        'სასაწყობე ფართი'  => ['ge' => 'სასაწყობე ფართი', 'en' => 'Storage', 'ru' => 'Складское помещение'],
        'დამხმარე ფართი'   => ['ge' => 'დამხმარე ფართი', 'en' => 'Auxiliary space', 'ru' => 'Вспомогательное помещение'],
    ];
    $type = trim(explode('/', (string)$type)[0]);
    $fallback = ['ge' => $type !== '' ? $type : 'უძრავი ქონება', 'en' => 'Property', 'ru' => 'Недвижимость'];
    return $map[$type][$lang] ?? $fallback[$lang];
}

/** ქართული სახელი ლათინურად / კირილიცით */
function monolithSmsTransliterate($name, $lang)
{
    if ($lang === 'ge') {
        return $name;
    }
    $letters = ['ა', 'ბ', 'გ', 'დ', 'ე', 'ვ', 'ზ', 'თ', 'ი', 'კ', 'ლ', 'მ', 'ნ', 'ო', 'პ', 'ჟ', 'რ', 'ს', 'ტ', 'უ', 'ფ', 'ქ', 'ღ', 'ყ', 'შ', 'ჩ', 'ც', 'ძ', 'წ', 'ჭ', 'ხ', 'ჯ', 'ჰ'];
    $to = $lang === 'ru'
        ? ['а', 'б', 'г', 'д', 'е', 'в', 'з', 'т', 'и', 'к', 'л', 'м', 'н', 'о', 'п', 'ж', 'р', 'с', 'т', 'у', 'ф', 'к', 'г', 'к', 'ш', 'ч', 'ц', 'дз', 'ц', 'ч', 'х', 'дж', 'х']
        : ['a', 'b', 'g', 'd', 'e', 'v', 'z', 't', 'i', 'k', 'l', 'm', 'n', 'o', 'p', 'zh', 'r', 's', 't', 'u', 'p', 'k', 'gh', 'k', 'sh', 'ch', 'ts', 'dz', 'ts', 'ch', 'kh', 'j', 'h'];
    $latin = strtr($name, array_combine($letters, $to));
    return mb_convert_case($latin, MB_CASE_TITLE, 'UTF-8');
}

/** " - სახელი გვარი +995..." ან ცარიელი (სისტემური მომხმარებელი) */
function monolithSmsManager($userId, $lang)
{
    $userId = (int)$userId;
    if ($userId <= 0 || in_array($userId, [1, 3], true)) {
        return '';
    }
    $user = CUser::GetByID($userId)->Fetch();
    if (!$user) {
        return '';
    }
    $name = trim($user['NAME'] . ' ' . $user['LAST_NAME']);
    if ($name === '') {
        return '';
    }
    $phone = '';
    foreach (['PERSONAL_MOBILE', 'WORK_PHONE', 'PERSONAL_PHONE'] as $field) {
        $phone = monolithSmsPhone($user[$field] ?? '');
        if ($phone !== '') {
            break;
        }
    }
    return ' - ' . monolithSmsTransliterate($name, $lang) . ($phone !== '' ? ' +' . $phone : '');
}

function monolithSmsAccounts($lang)
{
    $tbc = monolithSmsOption('bank_tbc');
    $bog = monolithSmsOption('bank_bog') ?: MONOLITH_SMS_BANK_BOG;
    $parts = [];
    if ($tbc !== '') {
        $parts[] = ($lang === 'ge' ? 'TBC ' : 'TBC Bank: ') . $tbc;
    }
    if ($bog !== '') {
        $parts[] = ($lang === 'ge' ? 'BOG ' : 'Bank of Georgia (BOG): ') . $bog;
    }
    return implode(', ', $parts);
}

function monolithSmsMoney($amount)
{
    return preg_replace('/\.00$/', '', number_format((float)$amount, 2, '.', ''));
}

function monolithSmsDate($ymd)
{
    return $ymd !== '' ? date('d/m/Y', strtotime($ymd)) : '';
}

/** ხელშეკრულების ნომერი; თუ არ აქვს - პროექტი და უძრავი ქონების ნომერი */
function monolithSmsContract(array $deal, $lang)
{
    $contract = trim((string)$deal['CONTRACT']);
    if ($contract !== '') {
        return $contract;
    }
    $label = ['ge' => 'უძრავი ქონება', 'en' => 'property', 'ru' => 'объект'];
    return trim('New Depo, ' . $label[$lang] . ' N' . $deal['UNIT']);
}

/** ტექსტის ცვლადები დილიდან; $extra - date, amount */
function monolithSmsVars(array $deal, $lang, array $extra)
{
    return [
        'type'     => monolithSmsPropertyType($deal['TYPE'], $lang),
        'unit'     => $deal['UNIT'],
        'contract' => monolithSmsContract($deal, $lang),
        'manager'  => monolithSmsManager($deal['ASSIGNED_BY_ID'], $lang),
        'accounts' => monolithSmsAccounts($lang),
        'today'    => date('d/m/Y'),
        'payby'    => date('d/m/Y', strtotime('+' . MONOLITH_SMS_PAY_DAYS . ' days')),
        'date'     => monolithSmsDate((string)($extra['date'] ?? '')),
        'amount'   => monolithSmsMoney($extra['amount'] ?? 0),
    ];
}

// ── მონაცემები ──────────────────────────────────────────────────────────

function monolithSmsParseDate($value)
{
    $value = substr(trim((string)$value), 0, 10);
    foreach (['!d/m/Y', '!d.m.Y', '!Y-m-d'] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }
    return '';
}

function monolithSmsParseAmount($value)
{
    $text = preg_replace('/\|.*$/', '', trim((string)$value));
    $text = str_replace([' ', "\xC2\xA0"], '', $text);
    $text = strpos($text, ',') !== false && strpos($text, '.') !== false
        ? str_replace(',', '', $text)
        : str_replace(',', '.', $text);
    return (float)$text;
}

/** New Depo-ს დილები: ID => [ID, STAGE_ID, CONTACT_ID, ASSIGNED_BY_ID, TYPE, UNIT, CONTRACT, RES_DATE, LANG]. $where - SQL პირობა (d / u) */
function monolithSmsDeals($where)
{
    global $DB;
    $langFields = '';
    foreach (array_keys(MONOLITH_SMS_D_LANGS) as $field) {
        $langFields .= ', u.' . $field;
    }
    $res = $DB->Query(
        'SELECT d.ID, d.STAGE_ID, d.CONTACT_ID, d.ASSIGNED_BY_ID, u.' . MONOLITH_SMS_D_TYPE . ' AS TYPE, u.' . MONOLITH_SMS_D_UNIT . ' AS UNIT,'
        . ' u.' . MONOLITH_SMS_D_CONTRACT . ' AS CONTRACT, u.' . MONOLITH_SMS_D_RES_DATE . ' AS RES_DATE, c.' . MONOLITH_SMS_C_LANG . ' AS CONTACT_LANG' . $langFields
        . ' FROM b_crm_deal d JOIN b_uts_crm_deal u ON u.VALUE_ID = d.ID'
        . ' LEFT JOIN b_uts_crm_contact c ON c.VALUE_ID = d.CONTACT_ID'
        . " WHERE d.CATEGORY_ID = 0 AND LOWER(u." . MONOLITH_SMS_D_PROJECT . ") LIKE '" . $DB->ForSql(MONOLITH_SMS_PROJECT) . "%' AND (" . $where . ')'
    );
    $deals = [];
    while ($row = $res->Fetch()) {
        $lang = MONOLITH_SMS_C_LANG_MAP[(int)$row['CONTACT_LANG']] ?? '';
        foreach (MONOLITH_SMS_D_LANGS as $field => $map) {
            $lang = $lang ?: ($map[(int)$row[$field]] ?? '');
        }
        $deals[(int)$row['ID']] = [
            'ID'             => (int)$row['ID'],
            'STAGE_ID'       => (string)$row['STAGE_ID'],
            'CONTACT_ID'     => (int)$row['CONTACT_ID'],
            'ASSIGNED_BY_ID' => (int)$row['ASSIGNED_BY_ID'],
            'TYPE'           => trim((string)$row['TYPE']),
            'UNIT'           => trim((string)$row['UNIT']),
            'CONTRACT'       => trim((string)$row['CONTRACT']),
            'RES_DATE'       => monolithSmsParseDate($row['RES_DATE']),
            'LANG'           => $lang,
        ];
    }
    return $deals;
}

function monolithSmsStageList(array $stages)
{
    return "'" . implode("','", $stages) . "'";
}

/** კონტაქტის პირველი სწორი ნომერი (995... ან უცხოური კოდით) ან '' */
function monolithSmsClientPhone($contactId)
{
    global $DB;
    if ($contactId <= 0) {
        return '';
    }
    $res = $DB->Query("SELECT VALUE FROM b_crm_field_multi WHERE ENTITY_ID = 'CONTACT' AND TYPE_ID = 'PHONE' AND ELEMENT_ID = " . (int)$contactId . ' ORDER BY ID');
    while ($row = $res->Fetch()) {
        $phone = monolithSmsPhone($row['VALUE']);
        if ($phone !== '') {
            return $phone;
        }
    }
    return '';
}

function monolithSmsLangByPhone($phone)
{
    return $phone === '' || strpos($phone, '995') === 0 ? 'ge' : 'en';
}

/** სიის (22 / 23) რიგები დილებით: dealId => [[ID, CODE => VALUE ...]] */
function monolithSmsListRows($iblockId, array $codes, array $dealIds)
{
    global $DB;
    $dealIds = array_values(array_unique(array_filter(array_map('intval', $dealIds))));
    if (!$dealIds) {
        return [];
    }
    $propertyIds = [];
    $res = $DB->Query('SELECT ID, CODE FROM b_iblock_property WHERE IBLOCK_ID = ' . (int)$iblockId . " AND CODE IN ('DEAL', '" . implode("', '", $codes) . "')");
    while ($row = $res->Fetch()) {
        $propertyIds[$row['CODE']] = (int)$row['ID'];
    }
    if (empty($propertyIds['DEAL'])) {
        return [];
    }

    $select = 'e.ID, pd.VALUE AS DEAL';
    $join = '';
    $aliases = [];
    foreach ($codes as $i => $code) {
        if (empty($propertyIds[$code])) {
            continue;
        }
        $aliases['V' . $i] = $code;
        $select .= ', p' . $i . '.VALUE AS V' . $i;
        $join .= ' LEFT JOIN b_iblock_element_property p' . $i . ' ON p' . $i . '.IBLOCK_ELEMENT_ID = e.ID AND p' . $i . '.IBLOCK_PROPERTY_ID = ' . $propertyIds[$code];
    }

    $rows = [];
    foreach (array_chunk($dealIds, 300) as $chunk) {
        $values = [];
        foreach ($chunk as $id) {
            $values[] = "'" . $id . "'";
            $values[] = "'D_" . $id . "'";
        }
        $res = $DB->Query(
            'SELECT ' . $select . ' FROM b_iblock_element_property pd'
            . ' JOIN b_iblock_element e ON e.ID = pd.IBLOCK_ELEMENT_ID AND e.IBLOCK_ID = ' . (int)$iblockId . ' AND e.WF_PARENT_ELEMENT_ID IS NULL' . $join
            . ' WHERE pd.IBLOCK_PROPERTY_ID = ' . $propertyIds['DEAL'] . ' AND pd.VALUE IN (' . implode(',', $values) . ')'
        );
        while ($row = $res->Fetch()) {
            $item = ['ID' => (int)$row['ID']];
            foreach ($aliases as $alias => $code) {
                $item[$code] = (string)$row[$alias];
            }
            $dealId = (int)preg_replace('/\D/', '', $row['DEAL']);
            $rows[$dealId][$item['ID']] = $item;
        }
    }
    return array_map('array_values', $rows);
}

/** გრაფიკი: dealId => [[DATE (Y-m-d), AMOUNT $]] */
function monolithSmsSchedules(array $dealIds)
{
    $result = [];
    foreach (monolithSmsListRows(MONOLITH_SMS_SCHEDULE_IBLOCK, ['TARIGI', 'TANXA', 'TANXA_NUMBR'], $dealIds) as $dealId => $rows) {
        foreach ($rows as $row) {
            $date = monolithSmsParseDate($row['TARIGI'] ?? '');
            $amount = monolithSmsParseAmount($row['TANXA'] ?? '') ?: monolithSmsParseAmount($row['TANXA_NUMBR'] ?? '');
            if ($date !== '' && $amount > 0) {
                $result[$dealId][] = ['DATE' => $date, 'AMOUNT' => $amount];
            }
        }
    }
    return $result;
}

/** გადახდები: dealId => [[ID, DATE, AMOUNT $ (უკუგატარება მინუსით), REFUND, BARTER]] */
function monolithSmsPayments(array $dealIds)
{
    $result = [];
    foreach (monolithSmsListRows(MONOLITH_SMS_PAYMENT_IBLOCK, ['date', 'TANXA', 'refund', 'pay_type'], $dealIds) as $dealId => $rows) {
        foreach ($rows as $row) {
            $refund = (int)($row['refund'] ?? 0) === MONOLITH_SMS_REFUND_YES;
            $amount = monolithSmsParseAmount($row['TANXA'] ?? '');
            $result[$dealId][] = [
                'ID'     => $row['ID'],
                'DATE'   => monolithSmsParseDate($row['date'] ?? ''),
                'AMOUNT' => $refund ? -abs($amount) : $amount,
                'REFUND' => $refund,
                'BARTER' => (int)($row['pay_type'] ?? 0) === MONOLITH_SMS_PAY_BARTER,
            ];
        }
    }
    return $result;
}

/** დავალიანება $-ში: $until-მდე (ეს დღე არ შედის) დამდგარი გადახდები მინუს ყველა გადახდილი */
function monolithSmsDebt(array $schedule, array $payments, $until)
{
    $debt = -array_sum(array_column($payments, 'AMOUNT'));
    foreach ($schedule as $row) {
        $debt += $row['DATE'] < $until ? $row['AMOUNT'] : 0;
    }
    return round($debt, 2);
}

// ── მოვლენების მოძებნა ──────────────────────────────────────────────────

/** ჩანაწერი რიგში. ერთი $eventKey მხოლოდ ერთხელ. ბრუნდება true, თუ ახალი ჩანაწერი შეიქმნა */
function monolithSmsQueue($type, array $deal, $eventKey, array $extra = [])
{
    if (monolithSmsLogList(['=PROPERTY_EVENT_KEY' => $eventKey], 1)) {
        return false;
    }

    $phone = monolithSmsClientPhone($deal['CONTACT_ID']);
    $lang = $deal['LANG'] !== '' ? $deal['LANG'] : monolithSmsLangByPhone($phone);
    $text = monolithSmsText($type, $lang, monolithSmsVars($deal, $lang, $extra));

    $status = MONOLITH_SMS_ST_QUEUED;
    $note = '';
    if ($phone === '') {
        $status = MONOLITH_SMS_ST_SKIPPED;
        $note = 'კლიენტს სწორი ტელეფონი არ აქვს';
    } else {
        // რამდენიმე უძრავი ქონების მქონე კლიენტს დღეში ერთი და იგივე ტექსტი ორჯერ არ მიუვა
        $same = monolithSmsLogList(['=PROPERTY_CLIENT_PHONE' => $phone, '>=DATE_CREATE' => ConvertTimeStamp(strtotime('today'), 'FULL')], 100);
        foreach ($same as $row) {
            if ($row['SMS_TEXT'] === $text && $row['STATUS'] !== MONOLITH_SMS_ST_SKIPPED) {
                $status = MONOLITH_SMS_ST_SKIPPED;
                $note = 'იგივე ტექსტი ამ ნომერზე დღეს უკვე არის (დილი ' . preg_replace('/\D/', '', $row['DEAL']) . ')';
                break;
            }
        }
    }

    return monolithSmsLogAdd([
        'dealId'      => $deal['ID'],
        'type'        => monolithSmsTypes()[$type],
        'lang'        => $lang,
        'clientPhone' => $phone,
        'text'        => $text,
        'status'      => $status,
        'note'        => $note,
        'eventKey'    => $eventKey,
    ]) > 0;
}

/** სად შეჩერდა: თუ ჯერ არ არის დაყენებული - ახლანდელი მომენტი (ძველი მოვლენები არ იგზავნება) */
function monolithSmsCursor($option, $currentMaxSql)
{
    global $DB;
    $last = monolithSmsOption($option);
    if ($last === '') {
        $last = (string)(int)($DB->Query($currentMaxSql)->Fetch()['M'] ?? 0);
        monolithSmsSetOption($option, $last);
    }
    return (int)$last;
}

/** დადასტურებული რეზერვაცია და გაყიდვა: სტადიის ისტორიიდან, 3 წუთის დაგვიანებით (BP-ებმა ველები რომ შეავსონ) */
function monolithSmsCollectStages($now)
{
    global $DB;
    $last = monolithSmsCursor('last_history_id', 'SELECT MAX(ID) AS M FROM b_crm_deal_stage_history');
    $upper = (int)($DB->Query("SELECT MAX(ID) AS M FROM b_crm_deal_stage_history WHERE CREATED_TIME <= '" . date('Y-m-d H:i:s', $now - 180) . "'")->Fetch()['M'] ?? 0);
    if ($upper <= $last) {
        return;
    }

    $events = [];
    $res = $DB->Query('SELECT OWNER_ID, STAGE_ID FROM b_crm_deal_stage_history WHERE ID > ' . $last . ' AND ID <= ' . $upper
        . " AND STAGE_ID IN ('FINAL_INVOICE', 'WON') ORDER BY ID");
    while ($row = $res->Fetch()) {
        $events[(int)$row['OWNER_ID']][(string)$row['STAGE_ID']] = true;
    }
    if ($events) {
        foreach (monolithSmsDeals('d.ID IN (' . implode(',', array_keys($events)) . ')') as $dealId => $deal) {
            if (empty($events[$dealId][$deal['STAGE_ID']])) {
                continue;   // დილი ამ სტადიიდან უკვე გავიდა
            }
            if ($deal['STAGE_ID'] === 'WON') {
                monolithSmsQueue('sold', $deal, 'sold:' . $dealId);
            } elseif ($deal['RES_DATE'] !== '') {
                monolithSmsQueue('reservation', $deal, 'reservation:' . $dealId . ':' . $deal['RES_DATE'], ['date' => $deal['RES_DATE']]);
            }
        }
    }
    monolithSmsSetOption('last_history_id', $upper);
}

/** პირველი გადახდა: სია 23-ის ახალი ჩანაწერები, 5 წუთის დაგვიანებით (BP 84 ველებს ავსებს) */
function monolithSmsCollectFirstPayments($now)
{
    global $DB;
    $last = monolithSmsCursor('last_payment_id', 'SELECT MAX(ID) AS M FROM b_iblock_element WHERE IBLOCK_ID = ' . MONOLITH_SMS_PAYMENT_IBLOCK);
    $upper = (int)($DB->Query('SELECT MAX(ID) AS M FROM b_iblock_element WHERE IBLOCK_ID = ' . MONOLITH_SMS_PAYMENT_IBLOCK
        . " AND DATE_CREATE <= '" . date('Y-m-d H:i:s', $now - 300) . "'")->Fetch()['M'] ?? 0);
    if ($upper <= $last) {
        return;
    }

    $dealIds = [];
    $res = $DB->Query('SELECT p.VALUE FROM b_iblock_element e'
        . ' JOIN b_iblock_property ip ON ip.IBLOCK_ID = e.IBLOCK_ID AND ip.CODE = \'DEAL\''
        . ' JOIN b_iblock_element_property p ON p.IBLOCK_ELEMENT_ID = e.ID AND p.IBLOCK_PROPERTY_ID = ip.ID'
        . ' WHERE e.IBLOCK_ID = ' . MONOLITH_SMS_PAYMENT_IBLOCK . ' AND e.ID > ' . $last . ' AND e.ID <= ' . $upper);
    while ($row = $res->Fetch()) {
        $dealIds[(int)preg_replace('/\D/', '', $row['VALUE'])] = true;
    }
    unset($dealIds[0]);

    if ($dealIds) {
        $deals = monolithSmsDeals('d.ID IN (' . implode(',', array_keys($dealIds)) . ') AND d.STAGE_ID IN (' . monolithSmsStageList(MONOLITH_SMS_PAID_STAGES) . ')');
        $payments = monolithSmsPayments(array_keys($deals));
        foreach ($deals as $dealId => $deal) {
            $first = null;
            foreach ($payments[$dealId] ?? [] as $row) {
                if ($row['REFUND'] || $row['BARTER'] || $row['AMOUNT'] <= 0) {
                    continue;
                }
                if ($first === null || $row['ID'] < $first['ID']) {
                    $first = $row;
                }
            }
            // დილის პირველი გადახდა სწორედ ახლა ჩაიწერა
            if ($first && $first['ID'] > $last && $first['ID'] <= $upper) {
                monolithSmsQueue('first_payment', $deal, 'firstpay:' . $dealId);
            }
        }
    }
    monolithSmsSetOption('last_payment_id', $upper);
}

/** დღიური SMS-ები: ჯავშნის ვადა, გადახდის შეხსენება, ვადაგადაცილება */
function monolithSmsCollectDaily($now)
{
    $today = date('Y-m-d', $now);

    $expires = date('Y-m-d', strtotime($today . ' +' . MONOLITH_SMS_EXPIRE_DAYS . ' days'));
    foreach (monolithSmsDeals("d.STAGE_ID = 'FINAL_INVOICE' AND u." . MONOLITH_SMS_D_RES_DATE . " = '" . $expires . "'") as $dealId => $deal) {
        monolithSmsQueue('expiring', $deal, 'expiring:' . $dealId . ':' . $expires, ['date' => $expires]);
    }

    $deals = monolithSmsDeals('d.STAGE_ID IN (' . monolithSmsStageList(MONOLITH_SMS_DEBT_STAGES) . ')');
    $schedules = monolithSmsSchedules(array_keys($deals));
    $payments = monolithSmsPayments(array_keys($deals));
    $remindDate = date('Y-m-d', strtotime($today . ' +' . MONOLITH_SMS_REMIND_DAYS . ' days'));
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));

    foreach ($deals as $dealId => $deal) {
        $schedule = $schedules[$dealId] ?? [];
        if (!$schedule) {
            continue;
        }
        $dealPayments = $payments[$dealId] ?? [];
        $dates = array_unique(array_column($schedule, 'DATE'));
        // $date-ის ჩათვლით დამდგარი გადახდებიდან რამდენია დაუფარავი (გადახდები ძველიდან ახლისკენ იფარება)
        $unpaidThrough = function ($date) use ($schedule, $dealPayments) {
            return monolithSmsDebt($schedule, $dealPayments, date('Y-m-d', strtotime($date . ' +1 day')));
        };

        if (in_array($remindDate, $dates, true) && $unpaidThrough($remindDate) > 0) {
            monolithSmsQueue('reminder', $deal, 'reminder:' . $dealId . ':' . $remindDate, ['date' => $remindDate]);
        }

        $debt = monolithSmsDebt($schedule, $dealPayments, $today);
        if ($debt <= 0) {
            continue;
        }
        if (in_array($yesterday, $dates, true)) {
            monolithSmsQueue('overdue', $deal, 'overdue:' . $dealId . ':' . $yesterday, ['amount' => $debt]);
            continue;   // ერთ დღეს ერთ დილზე ვალის ერთი SMS
        }
        foreach (MONOLITH_SMS_WEEKLY_DAYS as $days) {
            $due = date('Y-m-d', strtotime($today . ' -' . $days . ' days'));
            if (in_array($due, $dates, true) && $unpaidThrough($due) > 0) {
                monolithSmsQueue('overdue_weekly', $deal, 'weekly:' . $dealId . ':' . $due . ':' . $days, ['amount' => $debt]);
                break;
            }
        }
    }
}

// ── გაგზავნა ────────────────────────────────────────────────────────────

/** სატესტო ნომრები: test_phone, მძიმით გამოყოფილი */
function monolithSmsTestPhones()
{
    $phones = [];
    foreach (explode(',', monolithSmsOption('test_phone')) as $value) {
        $phone = monolithSmsPhone($value);
        if ($phone !== '' && !in_array($phone, $phones, true)) {
            $phones[] = $phone;
        }
    }
    return $phones;
}

function monolithSmsSendQueue($now, $mode)
{
    $hour = (int)date('G', $now);
    if ($hour < MONOLITH_SMS_HOUR_FROM || $hour >= MONOLITH_SMS_HOUR_TO || (int)monolithSmsOption('pause_until', '0') > $now) {
        return;
    }
    $testPhones = monolithSmsTestPhones();
    $failures = 0;

    foreach (monolithSmsLogList(['=PROPERTY_STATUS' => MONOLITH_SMS_ST_QUEUED], MONOLITH_SMS_BATCH) as $log) {
        if ($log['DATE_CREATE_TS'] && $now - $log['DATE_CREATE_TS'] > 86400) {
            monolithSmsLogUpdate($log['ID'], ['STATUS' => MONOLITH_SMS_ST_SKIPPED, 'NOTE' => 'დრო გაუვიდა (24 საათი)']);
            continue;
        }

        if ($mode === 'live') {
            $phones = [$log['CLIENT_PHONE']];
            $label = 'კლიენტი';
        } else {
            $phones = $testPhones;
            $label = 'ტესტი';
            if (!$phones) {
                monolithSmsLogUpdate($log['ID'], ['STATUS' => MONOLITH_SMS_ST_SKIPPED, 'SMS_MODE' => $label, 'NOTE' => 'სატესტო ნომერი არ არის']);
                continue;
            }
        }

        foreach ($phones as $i => $phone) {
            // მეორე და შემდეგ ნომერს თავისი ჩანაწერი აქვს: Inex-ის პასუხები ცალკეა
            $logId = $i === 0 ? $log['ID'] : monolithSmsLogAdd([
                'dealId'      => (int)preg_replace('/\D/', '', $log['DEAL']),
                'type'        => $log['SMS_TYPE'],
                'lang'        => $log['SMS_LANG'],
                'clientPhone' => $log['CLIENT_PHONE'],
                'phone'       => $phone,
                'text'        => $log['SMS_TEXT'],
                'mode'        => $label,
                'status'      => MONOLITH_SMS_ST_SENDING,
            ]);
            $sms = monolithSmsLogSend($logId, $phone, $log['SMS_TEXT'], $label);
            $failures = !$sms['ok'] && $sms['httpCode'] === 0 ? $failures + 1 : 0;
        }
        if ($failures >= 3) {
            // Inex არ პასუხობს - ნახევარი საათით ვჩერდებით, რიგი ადგილზე რჩება
            monolithSmsSetOption('pause_until', $now + 1800);
            break;
        }
    }
}

/** callback რომ არ მოვიდეს: ნახევარ საათში ერთხელ ვამოწმებთ ბოლო 2 დღის "გაიგზავნა" ჩანაწერებს */
function monolithSmsPollDelivery($now)
{
    if ($now - (int)monolithSmsOption('last_poll', '0') < 1800) {
        return;
    }
    monolithSmsSetOption('last_poll', $now);
    $rows = monolithSmsLogList([
        '=PROPERTY_STATUS'  => MONOLITH_SMS_ST_SENT,
        '!PROPERTY_INEX_ID' => false,
        '>=DATE_CREATE'     => ConvertTimeStamp($now - 2 * 86400, 'FULL'),
        '<=DATE_CREATE'     => ConvertTimeStamp($now - 600, 'FULL'),
    ], 60);
    foreach ($rows as $row) {
        monolithSmsRefreshDelivery($row['ID']);
    }
}

function monolithSmsRun($now = null)
{
    global $DB;
    if (!CModule::IncludeModule('crm') || !CModule::IncludeModule('iblock')) {
        return;
    }
    require_once __DIR__ . '/sms_log.php';
    if (!monolithSmsLogIblockId()) {
        return;
    }

    $now = $now ?: time();
    $mode = monolithSmsMode();
    if ($mode === 'off') {
        // გამორთულია: მოვლენები არ გროვდება, ჩართვისას ახლიდან დაიწყება
        foreach (['last_history_id', 'last_payment_id'] as $option) {
            if (monolithSmsOption($option) !== '') {
                monolithSmsSetOption($option, '');
            }
        }
        return;
    }

    $lock = $DB->Query("SELECT GET_LOCK('monolith_sms', 0) AS L")->Fetch();
    if ((int)($lock['L'] ?? 0) !== 1) {
        return;
    }
    $today = date('Y-m-d', $now);
    $hour = (int)date('G', $now);

    $steps = [
        'stages'   => function () use ($now) { monolithSmsCollectStages($now); },
        'payments' => function () use ($now) { monolithSmsCollectFirstPayments($now); },
        'daily'    => function () use ($now, $today, $hour) {
            if ($hour >= MONOLITH_SMS_DAILY_HOUR && $hour < MONOLITH_SMS_HOUR_TO && monolithSmsOption('daily_date') !== $today) {
                monolithSmsCollectDaily($now);
                monolithSmsSetOption('daily_date', $today);
            }
        },
        'send'     => function () use ($now, $mode) { monolithSmsSendQueue($now, $mode); },
        'poll'     => function () use ($now) { monolithSmsPollDelivery($now); },
    ];
    try {
        foreach ($steps as $name => $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                // ერთი ნაბიჯის შეცდომამ დანარჩენი არ უნდა შეაჩეროს
                CEventLog::Add([
                    'SEVERITY'      => 'ERROR',
                    'AUDIT_TYPE_ID' => 'MONOLITH_SMS',
                    'MODULE_ID'     => 'main',
                    'ITEM_ID'       => $name,
                    'DESCRIPTION'   => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
                ]);
            }
        }
    } finally {
        $DB->Query("SELECT RELEASE_LOCK('monolith_sms')");
    }
}

/** agent: CAgent "monolithSmsAgent();" (ყოველ 5 წუთში) */
function monolithSmsAgent()
{
    try {
        monolithSmsRun();
    } catch (\Throwable $e) {
        // agent-ი არ უნდა გაჩერდეს
    }
    return 'monolithSmsAgent();';
}
