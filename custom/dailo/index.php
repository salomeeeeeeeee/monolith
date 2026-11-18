<?php
/**
 * Dailo - ზარების ანალიტიკის დეშბორდი ბიტრიქსის შიგნით, პაროლის გარეშე (SSO).
 *
 * URL: https://crm.monolith.ge/custom/dailo/            - დეშბორდი ბიტრიქსის ჩარჩოში (iframe)
 *      https://crm.monolith.ge/custom/dailo/?open=tab   - იგივე ცალკე ჩანართში (Safari / სურვილისამებრ)
 * მენიუ: /.top.menu.php - „ზარების ანალიტიკა“ (ადმინი + ჯგუფი DAILO_SSO)
 *
 * ლოკალური REST აპლიკაციის ნაცვლად: გვერდი იღებს შესულ მომხმარებელს, ქმნის 60-წამიან
 * ხელმოწერილ JWT-ს (HS256, საერთო საიდუმლოთი) და POST-ით (ველი token) აგზავნის Dailo-ზე -
 * ჩარჩოში (target=iframe) ან, ?open=tab-ზე, მთელ ჩანართში. Dailo ბიტრიქსის ტოკენს და
 * REST-ზე წვდომას არ იღებს. POST იმიტომ, რომ ტოკენი URL-ში, ისტორიასა და ლოგებში არ მოხვდეს.
 * Dailo-ს CSP უკვე უშვებს ჩარჩოს: frame-ancestors 'self' https://crm.monolith.ge
 *
 * ტოკენის payload:
 *   iss         - "crm.monolith.ge"
 *   aud         - "monolith.dailo.ai"
 *   sub         - Bitrix user ID (სტრიქონი). Dailo მომხმარებელს ამით ცნობს და არა ელფოსტით:
 *                 ელფოსტას ბიტრიქსში თანამშრომელი თავად ცვლის.
 *   email, name - საინფორმაციოდ
 *   iat, exp    - exp = iat + 60
 *   jti         - ერთჯერადი ID (Dailo-მ ერთი და იგივე ორჯერ არ უნდა მიიღოს)
 *
 * საიდუმლო ინახება sso_secret.php-ში, რომელიც git-ში არ არის (რეპო საჯაროა).
 * ნიმუში: sso_secret.example.php
 */
define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

global $USER, $APPLICATION;

// Dailo-ს მიმღები მისამართი (POST, ველი token)
const DAILO_SSO_ENDPOINT = 'https://monolith.dailo.ai/bitrix/sso';
const DAILO_SSO_ISSUER   = 'crm.monolith.ge';
const DAILO_SSO_AUDIENCE = 'monolith.dailo.ai';
const DAILO_SSO_TTL      = 60;
const DAILO_SSO_TITLE    = 'ზარების ანალიტიკა';
// ვის შეუძლია გახსნა: ადმინისტრატორი + ამ ჯგუფის წევრები (ჯგუფი STRING_ID-ით, ინტერფეისიდან იმართება).
// ჯგუფი თუ არ არსებობს ან ცარიელია - მხოლოდ ადმინისტრატორი. საბოლოო წვდომას Dailo-ს ანგარიშიც წყვეტს.
const DAILO_SSO_GROUP_CODE = 'DAILO_SSO';

define('DAILO_SSO_IN_TAB', ($_GET['open'] ?? '') === 'tab');

if (!$USER->IsAuthorized()) {
    LocalRedirect('/auth/?backurl=' . urlencode($APPLICATION->GetCurPageParam()));
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

function dailoSsoSecret()
{
    $file = __DIR__ . '/sso_secret.php';
    $secret = is_file($file) ? include $file : '';
    return is_string($secret) ? trim($secret) : '';
}

function dailoSsoBase64Url($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function dailoSsoToken(array $claims, $secret)
{
    $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
    $payload = json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return '';
    }
    $signingInput = dailoSsoBase64Url($header) . '.' . dailoSsoBase64Url($payload);
    return $signingInput . '.' . dailoSsoBase64Url(hash_hmac('sha256', $signingInput, $secret, true));
}

function dailoSsoAllowed()
{
    global $USER;
    if ($USER->IsAdmin()) {
        return true;
    }
    $group = \Bitrix\Main\GroupTable::getList([
        'select' => ['ID'],
        'filter' => ['=STRING_ID' => DAILO_SSO_GROUP_CODE, '=ACTIVE' => 'Y'],
    ])->fetch();
    return $group && in_array((int)$group['ID'], array_map('intval', $USER->GetUserGroupArray()), true);
}

/** ცალკე ჩანართის მარტივი გვერდი (Bitrix-ის შაბლონის გარეშე). */
function dailoSsoStandalone($title, $bodyHtml)
{
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ka"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialcharsbx($title) . '</title>'
        . '<style>body{font-family:Arial,sans-serif;background:#f5f7f8;color:#333;margin:0;padding:60px 16px;text-align:center}'
        . '.box{max-width:420px;margin:0 auto;background:#fff;border-radius:8px;padding:32px 24px;box-shadow:0 1px 3px rgba(0,0,0,.08)}'
        . 'h1{font-size:18px;margin:0 0 12px}p{font-size:14px;line-height:1.5;margin:8px 0}'
        . 'button{background:#2fc6f6;color:#fff;border:0;border-radius:6px;padding:10px 20px;font-size:14px;cursor:pointer}'
        . 'a{color:#2067b0}</style></head><body><div class="box">'
        . '<h1>' . htmlspecialcharsbx($title) . '</h1>' . $bodyHtml
        . '</div></body></html>';
    exit;
}

/**
 * „ახლა“ Dailo-ს საათით (unix time).
 *
 * დროებითი: 2026-11-18-ს სერვერის საათი ~1ს52წთ-ით წინ იყო (chrony: "System clock synchronized: no";
 * გამოსწორება root-ით: sudo chronyc makestep). Dailo საშვის iat/exp-ს თავისი საათით ამოწმებს,
 * ამიტომ სხვაობას მისი სერვერის Date სათაურიდან ვითვლით და 5 წუთით ვინახავთ ქეშში.
 * საათი როცა გასწორდება, სხვაობა 0 გახდება; საათის უკან გადაწევისას ქეში თავისით უქმდება.
 * ვერ დაუკავშირდა - სერვერის საკუთარი დრო.
 */
function dailoSsoNow()
{
    $now = time();
    $cache = \Bitrix\Main\Data\Cache::createInstance();
    $cacheId = 'clock_offset';
    $cacheDir = '/dailo_sso';
    if ($cache->initCache(300, $cacheId, $cacheDir)) {
        $data = $cache->getVars();
        if (is_array($data) && $now >= (int)$data['at']) {
            return $now + (int)$data['offset'];
        }
        $cache->clean($cacheId, $cacheDir);
    }

    $url = parse_url(DAILO_SSO_ENDPOINT, PHP_URL_SCHEME) . '://' . parse_url(DAILO_SSO_ENDPOINT, PHP_URL_HOST) . '/';
    $http = new \Bitrix\Main\Web\HttpClient(['socketTimeout' => 3, 'streamTimeout' => 3, 'redirect' => false]);
    $http->head($url);
    $remote = strtotime((string)$http->getHeaders()->get('Date'));
    if (!$remote) {
        return $now;
    }
    $offset = $remote - time();
    if (abs($offset) < 3) {
        $offset = 0;
    }
    if ($cache->startDataCache(300, $cacheId, $cacheDir)) {
        $cache->endDataCache(['at' => time(), 'offset' => $offset]);
    }
    return time() + $offset;
}

/** შეცდომის/შეტყობინების ჩვენება - ჩანართში მარტივი გვერდით, ჩარჩოს რეჟიმში Bitrix-ის შაბლონში. */
function dailoSsoMessage($title, $text)
{
    global $APPLICATION;
    $html = '<p>' . htmlspecialcharsbx($text) . '</p>';
    if (DAILO_SSO_IN_TAB) {
        dailoSsoStandalone($title, $html . '<p><a href="/">ბიტრიქსში დაბრუნება</a></p>');
    }
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_after.php');
    $APPLICATION->SetTitle(DAILO_SSO_TITLE);
    echo '<div style="max-width:520px;margin:40px auto;background:#fff;border-radius:8px;padding:28px 24px;text-align:center">'
        . '<h2 style="font-size:18px;margin:0 0 10px">' . htmlspecialcharsbx($title) . '</h2>' . $html . '</div>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    exit;
}

if (!dailoSsoAllowed()) {
    dailoSsoMessage('წვდომა არ გაქვთ', 'ზარების ანალიტიკაზე წვდომისთვის მიმართეთ ადმინისტრატორს.');
}

$secret = dailoSsoSecret();
if (strlen($secret) < 32) {
    dailoSsoMessage('ჯერ არ არის გამართული', 'Dailo-სთან კავშირი ჯერ არ არის კონფიგურირებული.');
}

$now = dailoSsoNow();
$token = dailoSsoToken([
    'iss'   => DAILO_SSO_ISSUER,
    'aud'   => DAILO_SSO_AUDIENCE,
    'sub'   => (string)(int)$USER->GetID(),
    'email' => (string)$USER->GetEmail(),
    'name'  => trim((string)$USER->GetFullName()),
    'iat'   => $now,
    'exp'   => $now + DAILO_SSO_TTL,
    'jti'   => bin2hex(random_bytes(16)),
], $secret);

if ($token === '') {
    dailoSsoMessage('შეცდომა', 'ტოკენი ვერ შეიქმნა. სცადეთ თავიდან.');
}

$formFields = '<input type="hidden" name="token" value="' . htmlspecialcharsbx($token) . '">';
$endpoint = htmlspecialcharsbx(DAILO_SSO_ENDPOINT);

if (DAILO_SSO_IN_TAB) {
    // ავტომატური გაგზავნა; „უკან“ ღილაკით დაბრუნებისას თავიდან აღარ გადაჰყავს, რომ ბიტრიქსში დაბრუნება შეიძლებოდეს
    dailoSsoStandalone(
        DAILO_SSO_TITLE,
        '<form id="dailo-sso" method="post" action="' . $endpoint . '">' . $formFields
        . '<p>მიმდინარეობს გადასვლა Dailo-ს დეშბორდზე…</p>'
        . '<p><button type="submit">გაგრძელება</button></p>'
        . '</form><p><a href="/">ბიტრიქსში დაბრუნება</a></p>'
        . '<script>(function(){var n=window.performance&&performance.getEntriesByType'
        . '?performance.getEntriesByType("navigation")[0]:null;'
        . 'if(!n||n.type!=="back_forward"){document.getElementById("dailo-sso").submit();}})();</script>'
    );
}

// ჩარჩოს რეჟიმი: Bitrix-ის შაბლონი (მენიუ, ჰედერი) + Dailo-ს დეშბორდი iframe-ში
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_after.php');
$APPLICATION->SetTitle(DAILO_SSO_TITLE);
?>
<style>
    .dailo-bar { display: flex; justify-content: flex-end; margin: 0 0 8px; font-size: 13px; }
    .dailo-bar a { color: #2067b0; text-decoration: none; }
    .dailo-bar a:hover { text-decoration: underline; }
    .dailo-frame { display: block; width: 100%; height: calc(100vh - 160px); min-height: 480px;
        border: 0; border-radius: 8px; background: #fff; }
</style>
<div class="dailo-bar">
    <a href="/custom/dailo/?open=tab" target="_blank" rel="noopener">ახალ ჩანართში გახსნა ↗</a>
</div>
<iframe name="dailo-frame" id="dailo-frame" class="dailo-frame" title="<?= htmlspecialcharsbx(DAILO_SSO_TITLE) ?>"
        allow="clipboard-write; fullscreen" allowfullscreen></iframe>
<form id="dailo-sso" method="post" action="<?= $endpoint ?>" target="dailo-frame" style="display:none"><?= $formFields ?></form>
<script>
    (function () {
        var frame = document.getElementById('dailo-frame');
        function fit() {
            var top = frame.getBoundingClientRect().top + (window.pageYOffset || 0);
            frame.style.height = Math.max(480, window.innerHeight - top - 24) + 'px';
        }
        fit();
        window.addEventListener('resize', fit);
        document.getElementById('dailo-sso').submit();
    })();
</script>
<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
