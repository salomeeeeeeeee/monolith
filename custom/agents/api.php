<?php
/**
 * აგენტების მოდულის REST proxy: index.php-ის bitrixCall() აქ აგზავნის {method, params}-ს,
 * აქედან კი მოთხოვნა webhook-ზე მიდის. პასუხი უცვლელად ბრუნდება ({result} ან {error, error_description}).
 */
define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once __DIR__ . '/config.php';

use Bitrix\Main\Web\HttpClient;

// მხოლოდ ის მეთოდები, რასაც მოდული იძახებს
const AGENTS_MODULE_METHODS = [
    'lists.field.get',
    'lists.element.get',
    'lists.element.add',
    'lists.element.update',
    'lists.element.delete',
    'crm.deal.list',
    'crm.deal.update',
    'crm.deal.userfield.list',
    'crm.deal.userfield.add',
    'crm.contact.list',
    'crm.company.list',
];
// წაშლის ღილაკი მოდულში მხოლოდ ადმინს უჩანს
const AGENTS_MODULE_ADMIN_METHODS = ['lists.element.delete'];
// სიები: 24 სააგენტოები (წერაც), 23 გადახდები და 25 აგენტზე გადახდილი საკომისიო (მხოლოდ კითხვა)
const AGENTS_MODULE_READ_IBLOCKS  = [23, 24, 25];
const AGENTS_MODULE_WRITE_IBLOCKS = [24];

function agentsModuleRespond(array $payload, $httpCode = 200)
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function agentsModuleError($code, $description, $httpCode)
{
    agentsModuleRespond(['error' => $code, 'error_description' => $description], $httpCode);
}

global $USER;
if (!agentsModuleCanAccess()) {
    agentsModuleError('ACCESS_DENIED', 'წვდომა არ გაქვთ', 403);
}
if (!check_bitrix_sessid()) {
    agentsModuleError('INVALID_SESSID', 'სესია ამოიწურა - გადატვირთეთ გვერდი', 401);
}
$isAdmin = $USER->IsAdmin();
// 15 პარალელური მოთხოვნა ერთმანეთს სესიის ლოკზე რომ არ დაელოდოს
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
$method = is_array($input) ? (string)($input['method'] ?? '') : '';
$params = is_array($input) && is_array($input['params'] ?? null) ? $input['params'] : [];

if (!in_array($method, AGENTS_MODULE_METHODS, true)) {
    agentsModuleError('METHOD_NOT_ALLOWED', 'მეთოდი დაშვებული არ არის: ' . $method, 400);
}
if (in_array($method, AGENTS_MODULE_ADMIN_METHODS, true) && !$isAdmin) {
    agentsModuleError('ACCESS_DENIED', 'წაშლა მხოლოდ ადმინს შეუძლია', 403);
}
if (strpos($method, 'lists.') === 0) {
    $allowed = in_array($method, ['lists.field.get', 'lists.element.get'], true)
        ? AGENTS_MODULE_READ_IBLOCKS
        : AGENTS_MODULE_WRITE_IBLOCKS;
    if (!in_array((int)($params['IBLOCK_ID'] ?? 0), $allowed, true)) {
        agentsModuleError('ACCESS_DENIED', 'ეს სია მოდულისთვის დაშვებული არ არის', 403);
    }
}

$webhookUrl = agentsModuleWebhookUrl();
if ($webhookUrl === '') {
    agentsModuleError('NOT_CONFIGURED', 'webhook არ არის მითითებული (custom/agents/credentials.php)', 500);
}

$http = new HttpClient(['socketTimeout' => 30, 'streamTimeout' => 120]);
$http->setHeader('Content-Type', 'application/json');
$body = $http->post($webhookUrl . $method . '.json', json_encode($params, JSON_UNESCAPED_UNICODE));
$status = (int)$http->getStatus();

if ($body === false || $status === 0) {
    agentsModuleError('UPSTREAM_ERROR', 'Bitrix REST-თან კავშირი ვერ დამყარდა', 502);
}

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo $body;
exit;
