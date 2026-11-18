<?php
/**
 * საიტის (monolith.ge) ფორმების მიღება - ყოველი განაცხადი CRM-ში დილად იქმნება.
 *
 * URL:     https://crm.monolith.ge/rest/public/addSiteLead.php
 * Method:  POST, Content-Type: application/json  (form-data / x-www-form-urlencoded-იც მიიღება)
 * Auth:    Authorization: Bearer <TOKEN>   ან   X-Api-Token: <TOKEN>
 *
 * ტოკენი git-ში არ ინახება (რეპო საჯაროა) - სერვერზეა:
 *   /local/php_interface/site_lead_secret.php   (ნიმუში: site_lead_secret.example.php)
 * წყარო "ვებგვერდი" და ლოგის სია SITE_LEAD_LOG ერთხელ იქმნება აქ:
 *   https://crm.monolith.ge/custom/setup/siteLeads.php
 *
 * რას აკეთებს:
 *   - კონტაქტი: ეძებს ნომრით, მერე ელფოსტით; თუ არ არის - ქმნის. არსებულს მხოლოდ ცარიელ ველებს უვსებს;
 *   - დილი: "Leads" ეტაპზე, წყარო "ვებგვერდი", ფორმის ტიპი SOURCE_DESCRIPTION-ში და სათაურში;
 *     ბინა/პარკინგი (product_id = ჩვენი პროდუქტის ID, იგივე რასაც საიტი getProducts.php-დან იღებს)
 *     იწერება "ბინის N: (საიტიდან)" ველში, ბმული - "ბინის ბმული" ველში;
 *   - BP 86 (ლიდების განაწილება) დილს მენეჯერებზე მორიგეობით ანაწილებს, ახალი კონტაქტი დილს მიჰყვება;
 *   - ყოველი მოთხოვნა იწერება სიაში SITE_LEAD_LOG.
 *
 * იგივე განაცხადის ხელახლა გამოგზავნა ახალ დილს არ ქმნის: lead_id-ით (საიტის ბაზის ID), ან მის
 * გარეშე - იგივე კონტაქტი + ფორმა + პროდუქტი SITE_LEAD_DEDUP_MINUTES წუთში.
 * "test": true - დილი იქმნება, მაგრამ არავისზე ნაწილდება (რჩება ინტეგრაციის მომხმარებელზე).
 *
 * ველები - ბრტყელი JSON, ან crm.lead.add-ის ფორმატი {"fields": {...}} (სახელები რეგისტრს არ აქცევს ყურადღებას):
 *   form_type     Requested Call | Apartment Booking | Parking Booking | Contact | Monolith Card
 *                 (ან SOURCE_DESCRIPTION)
 *   first_name, last_name   ან   name (სრული სახელი)
 *   phone, email  (ერთ-ერთი სავალდებულოა; crm.lead.add-ის [{"VALUE": "..."}] ფორმაც მიიღება)
 *   country       მაგ. "Georgia", "GE", "საქართველო"
 *   product_id    ბინის/პარკინგის ID (ჩვენი პროდუქტის ID);  link - ბმული საიტზე
 *   project, number - თუ product_id არ არის
 *   message, lead_id, created_at, page_url, utm_source / utm_medium / utm_campaign / utm_content / utm_term
 *
 * პასუხი: {"status": 200, "result": <dealId>, "dealId": ..., "contactId": ..., "created": true|false, ...}
 */

ob_start();
define("STOP_STATISTICS",       true);
define("NO_KEEP_STATISTIC",     "Y");
define("NO_AGENT_STATISTIC",    "Y");
define("NO_AGENT_CHECK",        true);
define("DisableEventsCheck",    true);
define("NOT_CHECK_PERMISSIONS", true);

// ── Configuration ───────────────────────────────────────────────────────

const SITE_LEAD_CATEGORY_ID    = 0;
const SITE_LEAD_STAGE_ID       = 'NEW';          // Leads
const SITE_LEAD_SOURCE_ID      = 'UC_WEBSITE';   // ვებგვერდი (siteLeads.php ქმნის)
const SITE_LEAD_ASSIGNED_BY_ID = 3;              // როგორც დაილოს ლიდებში: BP 86 ამ მომხმარებლის დილებს ანაწილებს
const SITE_LEAD_WORKFLOW_ID    = 86;             // ლიდების განაწილება მენეჯერებზე
const SITE_LEAD_LOG_CODE       = 'SITE_LEAD_LOG';
const SITE_LEAD_PRODUCT_IBLOCK = 14;
const SITE_LEAD_DEDUP_MINUTES  = 10;
const SITE_LEAD_TITLE_PREFIX   = 'ვებგვერდი - ';
const SITE_LEAD_MAX_BODY       = 65536;

const SITE_LEAD_F_PRODUCT       = 'UF_CRM_1711113684425'; // ბინის N: (saitidan wamosagebi)
const SITE_LEAD_F_LINK          = 'UF_CRM_1711114159';    // ბინის ბმული (url)
const SITE_LEAD_F_SITE_NAME     = 'UF_CRM_1709197999002'; // სახელი (საიტი)
const SITE_LEAD_F_COUNTRY       = 'UF_CRM_1750231289';    // ქვეყანა (სია)
const SITE_LEAD_F_COUNTRY_OTHER = 'UF_CRM_1750668146';    // ქვეყანა(სხვა)
const SITE_LEAD_F_REF_HOST      = 'UF_CRM_1690470112298'; // ref_host
const SITE_LEAD_COUNTRY_OTHER   = 335;                    // "სხვა"

$SITE_LEAD_UTM_FIELDS = [
    'utm_source'   => 'UF_CRM_1690470127412',
    'utm_medium'   => 'UF_CRM_1690470140779',
    'utm_campaign' => 'UF_CRM_1690470176162',
    'utm_content'  => 'UF_CRM_1690470161360',
    'utm_term'     => 'UF_CRM_1690470151468',
];

// ფორმის ტიპი: ნორმალიზებული სახელი (მხოლოდ ასოები, პატარა) => ქართული დასახელება
$SITE_LEAD_FORMS = [
    'requestedcall'   => 'ზარის მოთხოვნა',
    'requestcall'     => 'ზარის მოთხოვნა',
    'callrequest'     => 'ზარის მოთხოვნა',
    'call'            => 'ზარის მოთხოვნა',
    'callback'        => 'ზარის მოთხოვნა',
    'apartmentbooking' => 'ბინის ჯავშანი',
    'apartment'       => 'ბინის ჯავშანი',
    'flatbooking'     => 'ბინის ჯავშანი',
    'parkingbooking'  => 'პარკინგის ჯავშანი',
    'parking'         => 'პარკინგის ჯავშანი',
    'contact'         => 'საკონტაქტო ფორმა',
    'contactform'     => 'საკონტაქტო ფორმა',
    'contactus'       => 'საკონტაქტო ფორმა',
    'monolithcard'    => 'Monolith Card-ის განაცხადი',
    'card'            => 'Monolith Card-ის განაცხადი',
];

// ქვეყნის სიის მნიშვნელობა (UF_CRM_1750231289) => ISO კოდი, ინგლისური და ქართული სახელები
$SITE_LEAD_COUNTRIES = [
    303 => ['ge', 'geo', 'georgia', 'საქართველო', 'грузия'],
    304 => ['tr', 'tur', 'turkey', 'turkiye', 'türkiye', 'თურქეთი', 'турция'],
    305 => ['gr', 'grc', 'greece', 'საბერძნეთი', 'греция'],
    306 => ['es', 'esp', 'spain', 'ესპანეთი', 'испания'],
    307 => ['it', 'ita', 'italy', 'იტალია', 'италия'],
    308 => ['de', 'deu', 'germany', 'გერმანია', 'германия'],
    309 => ['ie', 'irl', 'ireland', 'ირლანდია', 'ирландия'],
    310 => ['gb', 'gbr', 'uk', 'united kingdom', 'great britain', 'england', 'ინგლისი', 'великобритания', 'англия'],
    311 => ['fr', 'fra', 'france', 'საფრანგეთი', 'франция'],
    312 => ['pl', 'pol', 'poland', 'პოლონეთი', 'польша'],
    313 => ['cy', 'cyp', 'cyprus', 'კვიპროსი', 'кипр'],
    314 => ['us', 'usa', 'united states', 'united states of america', 'america', 'აშშ', 'сша'],
    315 => ['ua', 'ukr', 'ukraine', 'უკრაინა', 'украина'],
    316 => ['ru', 'rus', 'russia', 'russian federation', 'რუსეთი', 'россия'],
    317 => ['by', 'blr', 'belarus', 'ბელარუსი', 'беларусь'],
    318 => ['az', 'aze', 'azerbaijan', 'აზერბაიჯანი', 'азербайджан'],
    319 => ['am', 'arm', 'armenia', 'სომხეთი', 'армения'],
    320 => ['kz', 'kaz', 'kazakhstan', 'ყაზახეთი', 'казахстан'],
    321 => ['il', 'isr', 'israel', 'ისრაელი', 'израиль'],
    322 => ['ir', 'irn', 'iran', 'ირანი', 'иран'],
    323 => ['sa', 'sau', 'saudi arabia', 'საუდის არაბეთი', 'саудовская аравия'],
    324 => ['ae', 'are', 'uae', 'united arab emirates', 'emirates', 'არაბთა გაერთიანებული საამიროები', 'оаэ'],
    325 => ['kw', 'kwt', 'kuwait', 'ქუვეითი', 'кувейт'],
    326 => ['qa', 'qat', 'qatar', 'ყატარი', 'катар'],
    327 => ['bh', 'bhr', 'bahrain', 'ბაჰრეინი', 'бахрейн'],
    328 => ['eg', 'egy', 'egypt', 'ეგვიპტე', 'египет'],
    329 => ['ly', 'lby', 'libya', 'ლიბია', 'ливия'],
    330 => ['ye', 'yem', 'yemen', 'იემენი', 'йемен'],
    331 => ['sd', 'sdn', 'sudan', 'სუდანი', 'судан'],
    332 => ['jo', 'jor', 'jordan', 'იორდანია', 'иордания'],
    333 => ['lb', 'lbn', 'lebanon', 'ლიბანი', 'ливан'],
    334 => ['om', 'omn', 'oman', 'ომანი', 'оман'],
];

// ── Response / CORS (Bitrix-მდე) ────────────────────────────────────────

$siteLeadState = [
    'bitrix'     => false,  // prolog ჩაიტვირთა - ლოგი შესაძლებელია
    'authorized' => false,  // ჩვენ ავტორიზდით - ბოლოს გამოვდივართ
    'raw'        => '',
    'externalId' => '',
    'form'       => '',
    'contact'    => '',
];

function siteLeadCors()
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && preg_match('~^https://([a-z0-9-]+\.)*monolith\.ge$~i', $origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Token');
        header('Access-Control-Max-Age: 600');
    }
}

function siteLeadRespond(array $payload, $httpCode = 200, $dealId = 0)
{
    global $siteLeadState, $USER;

    if ($siteLeadState['bitrix']) {
        siteLeadLog($payload, $httpCode, $dealId);
        if ($siteLeadState['authorized'] && is_object($USER)) {
            $USER->Logout();
            $siteLeadState['authorized'] = false;
        }
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    siteLeadCors();
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Auth ────────────────────────────────────────────────────────────────

function siteLeadExpectedToken()
{
    $file = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/site_lead_secret.php';
    $token = is_file($file) ? include $file : '';

    return is_string($token) ? trim($token) : '';
}

function siteLeadRequestToken()
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['HTTP_X_API_TOKEN'] ?? '',
    ];

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            $key = strtolower((string)$name);
            if ($key === 'authorization' || $key === 'x-api-token') {
                $candidates[] = $value;
            }
        }
    }

    foreach ($candidates as $raw) {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            continue;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $raw, $m)) {
            return $m[1];
        }
        return $raw;
    }

    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    siteLeadRespond(['status' => 200, 'message' => 'OK']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    siteLeadRespond(['status' => 405, 'message' => 'Method not allowed - use POST'], 405);
}

$expectedToken = siteLeadExpectedToken();
if ($expectedToken === '') {
    siteLeadRespond(['status' => 500, 'message' => 'Endpoint is not configured'], 500);
}

if (!hash_equals($expectedToken, siteLeadRequestToken())) {
    siteLeadRespond(['status' => 401, 'message' => 'Unauthorized - Bearer token is required'], 401);
}

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

CModule::IncludeModule('crm');
CModule::IncludeModule('bizproc');
CModule::IncludeModule('iblock');

$siteLeadState['bitrix'] = true;

// ── Helpers ─────────────────────────────────────────────────────────────

function siteLeadReadInput()
{
    global $siteLeadState;

    $raw = file_get_contents('php://input');
    $raw = is_string($raw) ? $raw : '';
    if (strlen($raw) > SITE_LEAD_MAX_BODY) {
        siteLeadRespond(['status' => 413, 'message' => 'Request body is too large'], 413);
    }

    $data = [];
    if (trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        } elseif (empty($_POST)) {
            parse_str($raw, $parsed);
            $data = is_array($parsed) ? $parsed : [];
        }
    }
    if (empty($data) && !empty($_POST)) {
        $data = $_POST;
    }

    $siteLeadState['raw'] = $raw !== '' ? $raw : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // crm.lead.add-ის ფორმატი: {"fields": {...}, "params": {...}}
    if (isset($data['fields']) && is_array($data['fields'])) {
        $fields = $data['fields'];
        unset($data['fields'], $data['params']);
        $data = array_merge($data, $fields);
    }

    return array_change_key_case($data, CASE_LOWER);
}

/** მნიშვნელობა სტრიქონად; crm.lead.add-ის [{"VALUE": "..."}] / ["..."] - პირველი არაცარიელი. */
function siteLeadScalar($value)
{
    if (is_array($value)) {
        if (isset($value['VALUE']) || isset($value['value'])) {
            return siteLeadScalar($value['VALUE'] ?? $value['value']);
        }
        foreach ($value as $item) {
            $text = siteLeadScalar($item);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }
    if (is_bool($value)) {
        return $value ? '1' : '';
    }
    return trim((string)$value);
}

function siteLeadPick(array $input, array $keys)
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = siteLeadScalar($input[$key]);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function siteLeadIsTrue($value)
{
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'y', 'on'], true);
}

function siteLeadNormalizePhone($phone)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }
    if ($digits === '' || strlen($digits) < 6) {
        return '';
    }
    // საქართველოს ნომერი კოდის გარეშე (5XX XXX XXX)
    if (strlen($digits) === 9) {
        $digits = '995' . $digits;
    }
    return '+' . $digits;
}

function siteLeadPhoneSearchPart($phone)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    return strlen($digits) > 9 ? substr($digits, -9) : $digits;
}

/** კონტაქტები ნომრით: ბოლო 9 ციფრი, ციფრებს შორის ნებისმიერი სიმბოლო ("558 20 46 71", "5XX/5XX"). */
function siteLeadFindContactIdsByPhone($phone)
{
    global $DB;

    $search = siteLeadPhoneSearchPart($phone);
    if ($search === '') {
        return [];
    }

    $pattern = '%' . implode('%', str_split($search)) . '%';
    $res = $DB->Query(
        "SELECT ELEMENT_ID, VALUE FROM b_crm_field_multi
         WHERE ENTITY_ID = 'CONTACT' AND TYPE_ID = 'PHONE' AND VALUE LIKE '" . $DB->ForSql($pattern) . "'
         ORDER BY ELEMENT_ID"
    );

    $ids = [];
    while ($row = $res->Fetch()) {
        foreach (preg_split('/[\/,;]+/', (string)$row['VALUE']) as $part) {
            if (siteLeadPhoneSearchPart($part) === $search) {
                $ids[(int)$row['ELEMENT_ID']] = true;
                break;
            }
        }
    }

    return array_keys($ids);
}

function siteLeadFindContactIdByEmail($email)
{
    if ($email === '') {
        return 0;
    }
    $res = CCrmFieldMulti::GetList(
        ['ELEMENT_ID' => 'ASC'],
        ['ENTITY_ID' => 'CONTACT', 'TYPE_ID' => 'EMAIL', 'VALUE' => $email]
    );
    while ($row = $res->Fetch()) {
        if (strcasecmp(trim((string)$row['VALUE']), $email) === 0) {
            return (int)$row['ELEMENT_ID'];
        }
    }
    return 0;
}

function siteLeadHasMulti($contactId, $typeId)
{
    $res = CCrmFieldMulti::GetList([], ['ENTITY_ID' => 'CONTACT', 'ELEMENT_ID' => (int)$contactId, 'TYPE_ID' => $typeId]);
    while ($row = $res->Fetch()) {
        if (trim((string)$row['VALUE']) !== '') {
            return true;
        }
    }
    return false;
}

function siteLeadResolveForm($raw)
{
    global $SITE_LEAD_FORMS;

    $key = preg_replace('/[^a-z]+/', '', strtolower($raw));
    if ($key !== '' && isset($SITE_LEAD_FORMS[$key])) {
        return $SITE_LEAD_FORMS[$key];
    }
    if ($raw !== '') {
        return mb_substr($raw, 0, 100);
    }
    return 'საიტის ფორმა';
}

function siteLeadResolveCountry($country)
{
    global $SITE_LEAD_COUNTRIES;

    $key = mb_strtolower(trim($country));
    if ($key === '') {
        return 0;
    }
    foreach ($SITE_LEAD_COUNTRIES as $enumId => $aliases) {
        if (in_array($key, $aliases, true)) {
            return $enumId;
        }
    }
    return SITE_LEAD_COUNTRY_OTHER;
}

/** ჩვენი პროდუქტი (iblock 14): ['id', 'text'] ან null. */
function siteLeadProduct($productId)
{
    $productId = (int)$productId;
    if ($productId <= 0) {
        return null;
    }

    $res = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => SITE_LEAD_PRODUCT_IBLOCK, 'ID' => $productId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['nTopCount' => 1],
        ['ID', 'IBLOCK_ID', 'NAME', 'IBLOCK_SECTION_ID']
    );
    $ob = $res->GetNextElement();
    if (!$ob) {
        return null;
    }

    $fields = $ob->GetFields();
    $props = $ob->GetProperties();
    $prop = function ($code) use ($props) {
        $value = $props[$code]['VALUE'] ?? '';
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        return trim((string)$value);
    };

    $project = $prop('__VO9RG4');
    if ($project === '' && !empty($fields['IBLOCK_SECTION_ID'])) {
        $section = CIBlockSection::GetByID((int)$fields['IBLOCK_SECTION_ID'])->Fetch();
        $project = $section ? trim((string)$section['NAME']) : '';
    }
    $block  = $prop('_L24CUB') !== '' ? $prop('_L24CUB') : $prop('_MVA3NL');
    $floor  = $prop('_FTRIDL');
    $number = $prop('__6KWOWZ') !== '' ? $prop('__6KWOWZ') : trim((string)$fields['~NAME']);
    $type   = $prop('__X1GCRZ');
    $area   = $prop('__173JA5');

    $parts = array_filter([
        $project,
        $type,
        $block !== '' ? 'ბლოკი ' . $block : '',
        $floor !== '' ? 'სართული ' . $floor : '',
        $number !== '' ? 'N ' . $number : '',
        $area !== '' ? $area . ' მ²' : '',
    ], 'strlen');

    return [
        'id'   => (int)$fields['ID'],
        'text' => implode(', ', $parts) . ' (ID ' . (int)$fields['ID'] . ')',
    ];
}

function siteLeadDealExists($dealId)
{
    $dealId = (int)$dealId;
    if ($dealId <= 0) {
        return false;
    }
    $row = CCrmDeal::GetListEx([], ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'], false, ['nTopCount' => 1], ['ID'])->Fetch();
    return (bool)$row;
}

function siteLeadLogIblockId()
{
    static $id = null;
    if ($id === null) {
        $row = CIBlock::GetList([], ['CODE' => SITE_LEAD_LOG_CODE, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
        $id = $row ? (int)$row['ID'] : 0;
    }
    return $id;
}

/** უკვე მიღებული განაცხადი საიტის ID-ით (lead_id) - ლოგიდან. */
function siteLeadDealByExternalId($externalId)
{
    $iblockId = siteLeadLogIblockId();
    if ($externalId === '' || $iblockId <= 0) {
        return 0;
    }

    $res = CIBlockElement::GetList(
        ['ID' => 'DESC'],
        ['IBLOCK_ID' => $iblockId, '=PROPERTY_EXTERNAL_ID' => $externalId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        ['nTopCount' => 20],
        ['ID', 'PROPERTY_DEAL']
    );
    while ($row = $res->Fetch()) {
        // crm-ველის მნიშვნელობა "123" ან "D_123"
        $dealId = (int)preg_replace('/\D+/', '', (string)($row['PROPERTY_DEAL_VALUE'] ?? ''));
        if (siteLeadDealExists($dealId)) {
            return $dealId;
        }
    }
    return 0;
}

/** იგივე კონტაქტის იგივე ფორმა + პროდუქტი ბოლო SITE_LEAD_DEDUP_MINUTES წუთში (ორჯერ დაჭერილი ღილაკი, ხელახლა გაგზავნა). */
function siteLeadRecentDuplicate($contactId, $sourceDescription, $productText)
{
    if ((int)$contactId <= 0) {
        return 0;
    }

    $res = CCrmDeal::GetListEx(
        ['ID' => 'DESC'],
        [
            'CONTACT_ID'      => (int)$contactId,
            'SOURCE_ID'       => SITE_LEAD_SOURCE_ID,
            '>=DATE_CREATE'   => ConvertTimeStamp(time() - SITE_LEAD_DEDUP_MINUTES * 60, 'FULL'),
            'CHECK_PERMISSIONS' => 'N',
        ],
        false,
        ['nTopCount' => 10],
        ['ID', 'SOURCE_DESCRIPTION', SITE_LEAD_F_PRODUCT]
    );
    while ($row = $res->Fetch()) {
        if ((string)$row['SOURCE_DESCRIPTION'] === $sourceDescription
            && (string)($row[SITE_LEAD_F_PRODUCT] ?? '') === $productText) {
            return (int)$row['ID'];
        }
    }
    return 0;
}

/** ახლად შექმნილი კონტაქტი დილის პასუხისმგებელზე (BP 86-ის შემდეგ); აბრუნებს დილის პასუხისმგებელს. */
function siteLeadSyncContactAssigned($dealId, $contactId, $syncContact)
{
    $deal = CCrmDeal::GetListEx([], ['ID' => (int)$dealId, 'CHECK_PERMISSIONS' => 'N'], false, ['nTopCount' => 1], ['ID', 'ASSIGNED_BY_ID'])->Fetch();
    $assignedById = (int)($deal['ASSIGNED_BY_ID'] ?? 0);
    if ($assignedById <= 0 || (int)$contactId <= 0 || !$syncContact) {
        return $assignedById;
    }

    $contact = CCrmContact::GetListEx([], ['ID' => (int)$contactId, 'CHECK_PERMISSIONS' => 'N'], false, ['nTopCount' => 1], ['ID', 'ASSIGNED_BY_ID'])->Fetch();
    if ((int)($contact['ASSIGNED_BY_ID'] ?? 0) === $assignedById) {
        return $assignedById;
    }

    // Update() ველებს reference-ით იღებს - ლიტერალი მასივი არ შეიძლება
    $assignFields = ['ASSIGNED_BY_ID' => $assignedById];
    $updater = new CCrmContact(false);
    $updater->Update((int)$contactId, $assignFields, true, true, [
        'DISABLE_USER_FIELD_CHECK' => true,
        'CURRENT_USER'             => SITE_LEAD_ASSIGNED_BY_ID,
    ]);

    return $assignedById;
}

function siteLeadAddTimelineComment($dealId, $text)
{
    if ((int)$dealId <= 0 || trim($text) === '' || !class_exists('\Bitrix\Crm\Timeline\CommentEntry')) {
        return;
    }
    try {
        \Bitrix\Crm\Timeline\CommentEntry::create([
            'TEXT'      => $text,
            'AUTHOR_ID' => SITE_LEAD_ASSIGNED_BY_ID,
            'BINDINGS'  => [['ENTITY_TYPE_ID' => \CCrmOwnerType::Deal, 'ENTITY_ID' => (int)$dealId]],
        ]);
    } catch (\Throwable $e) {
        // კომენტარის შეცდომამ დილის შექმნა არ უნდა ჩააგდოს
    }
}

function siteLeadLog(array $payload, $httpCode, $dealId)
{
    global $siteLeadState;

    try {
        $iblockId = siteLeadLogIblockId();
        if ($iblockId <= 0) {
            return;
        }

        $name = 'Site - ' . ($siteLeadState['form'] !== '' ? $siteLeadState['form'] : '-')
            . ' - ' . ($siteLeadState['contact'] !== '' ? $siteLeadState['contact'] : '-');

        $el = new CIBlockElement();
        $el->Add([
            'IBLOCK_ID'       => $iblockId,
            'NAME'            => mb_substr($name, 0, 250),
            'ACTIVE'          => 'Y',
            'PROPERTY_VALUES' => [
                'EXTERNAL_ID' => $siteLeadState['externalId'],
                'FORM_TYPE'   => $siteLeadState['form'],
                'CONTACT'     => $siteLeadState['contact'],
                'DEAL'        => (int)$dealId > 0 ? (int)$dealId : false,
                'HTTP_CODE'   => (int)$httpCode,
                'REQUEST'     => mb_substr((string)$siteLeadState['raw'], 0, 60000),
                'RESPONSE'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ]);
    } catch (\Throwable $e) {
        // ლოგის შეცდომამ პასუხი არ უნდა შეცვალოს
    }
}

// ── Request ─────────────────────────────────────────────────────────────

$input = siteLeadReadInput();
if (empty($input)) {
    siteLeadRespond(['status' => 400, 'message' => 'Empty or invalid request body'], 400);
}

$formRaw    = siteLeadPick($input, ['form_type', 'formtype', 'form', 'type', 'source_description']);
$firstName  = siteLeadPick($input, ['first_name', 'firstname']);
$lastName   = siteLeadPick($input, ['last_name', 'lastname', 'surname']);
$fullName   = siteLeadPick($input, ['name', 'full_name', 'fullname']);
$phoneRaw   = siteLeadPick($input, ['phone', 'phone_number', 'phonenumber', 'mobile', 'tel']);
$emailRaw   = siteLeadPick($input, ['email', 'mail', 'e_mail']);
$country    = siteLeadPick($input, ['country', 'address_country', 'country_name']);
$productRaw = siteLeadPick($input, ['product_id', 'productid', 'apartment_id', 'apartmentid', 'parking_id', 'parkingid', 'item_id', 'itemid']);
$link       = siteLeadPick($input, ['link', 'url', 'product_url', 'producturl', 'apartment_url', 'apartmenturl']);
$project    = siteLeadPick($input, ['project', 'project_name', 'projectname']);
$number     = siteLeadPick($input, ['number', 'apartment_number', 'apartmentnumber', 'parking_number', 'parkingnumber']);
$message    = siteLeadPick($input, ['message', 'comment', 'comments', 'text']);
$externalId = siteLeadPick($input, ['lead_id', 'leadid', 'external_id', 'externalid', 'origin_id']);
$createdAt  = siteLeadPick($input, ['created_at', 'createdat', 'date']);
$pageUrl    = siteLeadPick($input, ['page_url', 'pageurl', 'source_url', 'referrer', 'ref']);
$isTest     = siteLeadIsTrue(siteLeadPick($input, ['test', 'is_test']));

$utm = [];
foreach (array_keys($SITE_LEAD_UTM_FIELDS) as $utmKey) {
    $utmValue = siteLeadPick($input, [$utmKey]);
    if ($utmValue !== '') {
        $utm[$utmKey] = mb_substr($utmValue, 0, 250);
    }
}

// სრული სახელი: თუ ცალკე არ მოვიდა, პირველი სიტყვა - სახელი, დანარჩენი - გვარი
if ($firstName === '' && $lastName === '' && $fullName !== '') {
    $nameParts = preg_split('/\s+/u', $fullName, 2);
    $firstName = $nameParts[0];
    $lastName  = $nameParts[1] ?? '';
} elseif ($firstName === '' && $fullName !== '') {
    $firstName = $fullName;
}
$displayName = trim($firstName . ' ' . $lastName);

$phone = siteLeadNormalizePhone($phoneRaw);
$email = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? mb_strtolower($emailRaw) : '';

$formLabel = siteLeadResolveForm($formRaw);
$sourceDescription = $formLabel . ($formRaw !== '' && $formRaw !== $formLabel ? ' (' . mb_substr($formRaw, 0, 100) . ')' : '');

$siteLeadState['externalId'] = mb_substr($externalId, 0, 100);
$siteLeadState['form']       = $formLabel;
$siteLeadState['contact']    = $phone !== '' ? $phone : $email;

if ($phone === '' && $email === '') {
    siteLeadRespond(['status' => 400, 'message' => 'phone or email is required'], 400);
}

// ── Already received (site lead_id) ─────────────────────────────────────

if ($externalId !== '') {
    $existingDealId = siteLeadDealByExternalId($siteLeadState['externalId']);
    if ($existingDealId > 0) {
        siteLeadRespond([
            'status'  => 200,
            'message' => 'Already received, deal exists',
            'result'  => $existingDealId,
            'dealId'  => $existingDealId,
            'created' => false,
        ], 200, $existingDealId);
    }
}

// ── Product ─────────────────────────────────────────────────────────────

$product = ctype_digit($productRaw) ? siteLeadProduct($productRaw) : null;
if ($product) {
    $productText = $product['text'];
} else {
    $productText = implode(', ', array_filter([
        $project,
        $number !== '' ? 'N ' . $number : '',
        $productRaw !== '' ? 'ID ' . $productRaw : '',
    ], 'strlen'));
}
$productText = mb_substr($productText, 0, 250);

$countryEnum = siteLeadResolveCountry($country);

global $USER;
if (!$USER->IsAuthorized()) {
    $USER->Authorize(SITE_LEAD_ASSIGNED_BY_ID);
    $siteLeadState['authorized'] = true;
}

// ── Contact: by phone, then by email; otherwise create ──────────────────

$contactCreated = false;
$contactId = 0;
if ($phone !== '') {
    $phoneMatches = siteLeadFindContactIdsByPhone($phone);
    $contactId = $phoneMatches ? $phoneMatches[0] : 0;
}
if ($contactId <= 0 && $email !== '') {
    $contactId = siteLeadFindContactIdByEmail($email);
}

if ($contactId > 0) {
    $duplicateDealId = siteLeadRecentDuplicate($contactId, $sourceDescription, $productText);
    if ($duplicateDealId > 0) {
        siteLeadRespond([
            'status'    => 200,
            'message'   => 'Duplicate of a lead received in the last ' . SITE_LEAD_DEDUP_MINUTES . ' minutes',
            'result'    => $duplicateDealId,
            'dealId'    => $duplicateDealId,
            'contactId' => $contactId,
            'created'   => false,
        ], 200, $duplicateDealId);
    }

    // არსებულ კონტაქტს მხოლოდ ცარიელ ველებს ვუვსებთ
    $existing = CCrmContact::GetListEx([], ['ID' => $contactId, 'CHECK_PERMISSIONS' => 'N'], false, ['nTopCount' => 1], ['ID', 'NAME', 'LAST_NAME'])->Fetch();
    $contactFields = [];
    if ($firstName !== '' && trim((string)($existing['NAME'] ?? '')) === '') {
        $contactFields['NAME'] = $firstName;
    }
    if ($lastName !== '' && trim((string)($existing['LAST_NAME'] ?? '')) === '') {
        $contactFields['LAST_NAME'] = $lastName;
    }
    if (!empty($contactFields)) {
        $contactUpdater = new CCrmContact(false);
        $contactUpdater->Update($contactId, $contactFields, true, true, [
            'DISABLE_USER_FIELD_CHECK' => true,
            'CURRENT_USER'             => SITE_LEAD_ASSIGNED_BY_ID,
        ]);
    }

    $fieldMulti = new CCrmFieldMulti();
    if ($email !== '' && !siteLeadHasMulti($contactId, 'EMAIL')) {
        $fieldMulti->Add(['ENTITY_ID' => 'CONTACT', 'ELEMENT_ID' => $contactId, 'TYPE_ID' => 'EMAIL', 'VALUE_TYPE' => 'WORK', 'VALUE' => $email]);
    }
    if ($phone !== '' && !siteLeadHasMulti($contactId, 'PHONE')) {
        $fieldMulti->Add(['ENTITY_ID' => 'CONTACT', 'ELEMENT_ID' => $contactId, 'TYPE_ID' => 'PHONE', 'VALUE_TYPE' => 'WORK', 'VALUE' => $phone]);
    }
} else {
    $contactFields = [
        'NAME'           => $firstName !== '' ? $firstName : ($phone !== '' ? $phone : $email),
        'LAST_NAME'      => $lastName,
        'ASSIGNED_BY_ID' => SITE_LEAD_ASSIGNED_BY_ID,
        'SOURCE_ID'      => SITE_LEAD_SOURCE_ID,
        'OPENED'         => 'Y',
        'FM'             => [],
    ];
    if ($phone !== '') {
        $contactFields['FM']['PHONE'] = ['n0' => ['VALUE' => $phone, 'VALUE_TYPE' => 'WORK']];
    }
    if ($email !== '') {
        $contactFields['FM']['EMAIL'] = ['n0' => ['VALUE' => $email, 'VALUE_TYPE' => 'WORK']];
    }

    $contact = new CCrmContact(false);
    $contactId = (int)$contact->Add($contactFields, true, [
        'DISABLE_USER_FIELD_CHECK' => true,
        'REGISTER_SONET_EVENT'     => true,
        'CURRENT_USER'             => SITE_LEAD_ASSIGNED_BY_ID,
    ]);

    if ($contactId <= 0) {
        siteLeadRespond(['status' => 500, 'message' => 'Failed to create contact: ' . $contact->LAST_ERROR], 500);
    }
    $contactCreated = true;
}

// ── Deal ────────────────────────────────────────────────────────────────

$utmText = [];
foreach ($utm as $utmKey => $utmValue) {
    $utmText[] = $utmKey . '=' . $utmValue;
}

$commentLines = array_filter([
    'საიტის ფორმა: ' . $sourceDescription,
    $displayName !== '' ? 'სახელი: ' . $displayName : '',
    $phone !== '' ? 'ტელეფონი: ' . $phone : '',
    $email !== '' ? 'ელფოსტა: ' . $email : ($emailRaw !== '' ? 'ელფოსტა (არასწორი ფორმატი): ' . $emailRaw : ''),
    $country !== '' ? 'ქვეყანა: ' . $country : '',
    $productText !== '' ? 'ბინა/პარკინგი: ' . $productText : '',
    $link !== '' ? 'ბმული: ' . $link : '',
    $message !== '' ? 'შეტყობინება: ' . $message : '',
    $pageUrl !== '' ? 'გვერდი: ' . $pageUrl : '',
    !empty($utmText) ? 'UTM: ' . implode(', ', $utmText) : '',
    $externalId !== '' ? 'საიტის ID: ' . $externalId : '',
    $createdAt !== '' ? 'გაგზავნის დრო საიტზე: ' . $createdAt : '',
], 'strlen');
$commentsText = implode("\n", $commentLines);

$dealTitle = ($isTest ? 'TEST - ' : '') . SITE_LEAD_TITLE_PREFIX . $formLabel . ' - '
    . ($displayName !== '' ? $displayName : ($phone !== '' ? $phone : $email));

$dealFields = [
    'TITLE'               => mb_substr($dealTitle, 0, 250),
    'CATEGORY_ID'         => SITE_LEAD_CATEGORY_ID,
    'STAGE_ID'            => SITE_LEAD_STAGE_ID,
    'SOURCE_ID'           => SITE_LEAD_SOURCE_ID,
    'SOURCE_DESCRIPTION'  => $sourceDescription,
    'CONTACT_ID'          => $contactId,
    'ASSIGNED_BY_ID'      => SITE_LEAD_ASSIGNED_BY_ID,
    'OPENED'              => 'Y',
    // nl2br-ით "\n" რჩება და Bitrix ცარიელ ხაზს ამატებს - მხოლოდ <br>
    'COMMENTS'            => str_replace("\n", '<br>', htmlspecialchars($commentsText, ENT_QUOTES, 'UTF-8')),
    SITE_LEAD_F_SITE_NAME => mb_substr($displayName, 0, 250),
    SITE_LEAD_F_PRODUCT   => $productText,
];
if ($link !== '' && preg_match('~^https?://~i', $link)) {
    $dealFields[SITE_LEAD_F_LINK] = mb_substr($link, 0, 500);
}
if ($countryEnum > 0) {
    $dealFields[SITE_LEAD_F_COUNTRY] = $countryEnum;
    if ($countryEnum === SITE_LEAD_COUNTRY_OTHER) {
        $dealFields[SITE_LEAD_F_COUNTRY_OTHER] = mb_substr($country, 0, 100);
    }
}
foreach ($utm as $utmKey => $utmValue) {
    $dealFields[$SITE_LEAD_UTM_FIELDS[$utmKey]] = $utmValue;
}
if ($pageUrl !== '') {
    $host = parse_url($pageUrl, PHP_URL_HOST);
    $dealFields[SITE_LEAD_F_REF_HOST] = mb_substr(is_string($host) && $host !== '' ? $host : $pageUrl, 0, 250);
}

$deal = new CCrmDeal(false);
$dealId = (int)$deal->Add($dealFields, true, [
    'DISABLE_USER_FIELD_CHECK' => true,
    'REGISTER_SONET_EVENT'     => true,
    'CURRENT_USER'             => SITE_LEAD_ASSIGNED_BY_ID,
]);

if ($dealId <= 0) {
    siteLeadRespond([
        'status'    => 500,
        'message'   => 'Failed to create deal: ' . $deal->LAST_ERROR,
        'contactId' => $contactId,
    ], 500);
}

siteLeadAddTimelineComment($dealId, $commentsText);

// ── Distribution (BP 86); test leads stay on the integration user ───────

$workflowStarted = false;
if (!$isTest) {
    $workflowErrors = [];
    try {
        $workflowStarted = (bool)CBPDocument::StartWorkflow(
            SITE_LEAD_WORKFLOW_ID,
            ['crm', 'CCrmDocumentDeal', 'DEAL_' . $dealId],
            [],
            $workflowErrors
        );
    } catch (\Throwable $e) {
        $workflowStarted = false;
    }
}

// ახალი კონტაქტი იმ მენეჯერზე, ვისაც BP-მ დილი მიაწერა (BP სინქრონულად სრულდება);
// ადრე არსებული კონტაქტი თავის პასუხისმგებელზე რჩება
$assignedById = siteLeadSyncContactAssigned($dealId, $contactId, $contactCreated && !$isTest);

siteLeadRespond([
    'status'         => 200,
    'message'        => 'OK',
    'result'         => $dealId,
    'dealId'         => $dealId,
    'contactId'      => $contactId,
    'created'        => true,
    'contactCreated' => $contactCreated,
    'assignedById'   => $assignedById,
    'distributed'    => $workflowStarted,
    'test'           => $isTest,
], 200, $dealId);
