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

use Bitrix\Main\Config\Option;
use Bitrix\Main\UserTable;
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
// პროექტების სია ყველა მომხმარებლისთვის საერთოა და b_option-ში ინახება (ადრე თითო ბრაუზრის localStorage-ში იყო)
const AGENTS_MODULE_PROJECTS_OPTION = 'agents_module_projects';

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

function agentsModuleText($value, $maxLength)
{
    return is_scalar($value) ? mb_substr(trim((string)$value), 0, $maxLength) : '';
}

/** პროექტების სია ბრაუზრიდან: მხოლოდ ცნობილი ველები, სახელი სავალდებულოა და არ მეორდება. */
function agentsModuleCleanProjects($list)
{
    if (!is_array($list)) {
        return null;
    }
    $result = [];
    $names = [];
    foreach ($list as $p) {
        if (!is_array($p)) {
            continue;
        }
        $name = agentsModuleText($p['name'] ?? '', 200);
        if ($name === '' || isset($names[$name])) {
            continue;
        }
        $names[$name] = true;
        $history = [];
        foreach (is_array($p['activeHistory'] ?? null) ? $p['activeHistory'] : [] as $h) {
            if (!is_array($h)) {
                continue;
            }
            $history[] = [
                'active'    => !empty($h['active']) && $h['active'] !== 'false',
                'comment'   => agentsModuleText($h['comment'] ?? '', 1000),
                'changedAt' => agentsModuleText($h['changedAt'] ?? '', 40),
            ];
        }
        $result[] = [
            'id'            => agentsModuleText($p['id'] ?? '', 64),
            'name'          => $name,
            'idCode'        => agentsModuleText($p['idCode'] ?? '', 50),
            'director'      => agentsModuleText($p['director'] ?? '', 200),
            'active'        => !(isset($p['active']) && ($p['active'] === false || $p['active'] === 'false')),
            'activeHistory' => $history,
        ];
    }
    return $result;
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

if ($method === 'agents.projects.get') {
    $stored = json_decode(Option::get('main', AGENTS_MODULE_PROJECTS_OPTION, ''), true);
    agentsModuleRespond(['result' => is_array($stored) ? $stored : []]);
}
if ($method === 'agents.projects.save') {
    $projects = agentsModuleCleanProjects($params['projects'] ?? null);
    if (!$projects) {
        agentsModuleError('INVALID_PROJECTS', 'პროექტების სია ცარიელია ან არასწორია', 400);
    }
    Option::set('main', AGENTS_MODULE_PROJECTS_OPTION, json_encode($projects, JSON_UNESCAPED_UNICODE));
    agentsModuleRespond(['result' => $projects]);
}
// დილების პასუხისმგებლების სახელები რეპორტისთვის: {ids: [..]} -> {id: "სახელი გვარი"}
if ($method === 'agents.users.names') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($params['ids'] ?? [])))));
    $names = [];
    if ($ids) {
        $users = UserTable::getList([
            'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN'],
            'filter' => ['@ID' => $ids],
        ]);
        while ($u = $users->fetch()) {
            $name = trim($u['NAME'] . ' ' . $u['LAST_NAME']);
            $names[(string)$u['ID']] = $name !== '' ? $name : $u['LOGIN'];
        }
    }
    agentsModuleRespond(['result' => $names]);
}

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
