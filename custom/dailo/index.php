<?php
/**
 * Dailo - ზარების ანალიტიკის დეშბორდში შესვლა ბიტრიქსიდან, პაროლის გარეშე (SSO).
 *
 * URL: https://crm.monolith.ge/custom/dailo/   (მარცხენა მენიუს პუნქტი „ზარების ანალიტიკა“)
 *
 * ლოკალური REST აპლიკაციის ნაცვლად: გვერდი იღებს შესულ მომხმარებელს, ქმნის 60-წამიან
 * ხელმოწერილ JWT-ს (HS256, საერთო საიდუმლოთი) და POST-ით (ველი token) აგზავნის Dailo-ზე.
 * Dailo ბიტრიქსის ტოკენს და REST-ზე წვდომას არ იღებს. POST იმიტომ, რომ ტოკენი
 * URL-ში, ისტორიასა და ლოგებში არ მოხვდეს.
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
// ვის შეუძლია გახსნა: ცარიელი = ყველა შესული თანამშრომელი (საბოლოო წვდომას Dailo-ს ანგარიში წყვეტს);
// თუ ჯგუფებია მითითებული - მხოლოდ მათი წევრები. ადმინისტრატორი ყოველთვის.
const DAILO_SSO_GROUP_IDS = [];

if (!$USER->IsAuthorized()) {
    LocalRedirect('/auth/?backurl=' . urlencode($APPLICATION->GetCurPageParam()));
}

header('Content-Type: text/html; charset=UTF-8');
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

function dailoSsoPage($title, $bodyHtml)
{
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

if (DAILO_SSO_GROUP_IDS && !$USER->IsAdmin()
    && !array_intersect(array_map('intval', $USER->GetUserGroupArray()), DAILO_SSO_GROUP_IDS)) {
    dailoSsoPage('წვდომა არ გაქვთ', '<p>ზარების ანალიტიკაზე წვდომისთვის მიმართეთ ადმინისტრატორს.</p><p><a href="/">უკან</a></p>');
}

$secret = dailoSsoSecret();
if (strlen($secret) < 32) {
    dailoSsoPage('ჯერ არ არის გამართული', '<p>Dailo-სთან კავშირი ჯერ არ არის კონფიგურირებული.</p><p><a href="/">უკან</a></p>');
}

$now = time();
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
    dailoSsoPage('შეცდომა', '<p>ტოკენი ვერ შეიქმნა. სცადეთ თავიდან.</p><p><a href="/">უკან</a></p>');
}

// ავტომატური გაგზავნა; „უკან“ ღილაკით დაბრუნებისას თავიდან აღარ გადაჰყავს, რომ ბიტრიქსში დაბრუნება შეიძლებოდეს
dailoSsoPage(
    'ზარების ანალიტიკა',
    '<form id="dailo-sso" method="post" action="' . htmlspecialcharsbx(DAILO_SSO_ENDPOINT) . '">'
    . '<input type="hidden" name="token" value="' . htmlspecialcharsbx($token) . '">'
    . '<p>მიმდინარეობს გადასვლა Dailo-ს დეშბორდზე…</p>'
    . '<p><button type="submit">გაგრძელება</button></p>'
    . '</form><p><a href="/">ბიტრიქსში დაბრუნება</a></p>'
    . '<script>(function(){var n=window.performance&&performance.getEntriesByType'
    . '?performance.getEntriesByType("navigation")[0]:null;'
    . 'if(!n||n.type!=="back_forward"){document.getElementById("dailo-sso").submit();}})();</script>'
);
