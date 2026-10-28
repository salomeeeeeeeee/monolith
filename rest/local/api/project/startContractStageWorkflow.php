<?php
/**
 * ხელშეკრულების ეტაპი — ინახავს განაცხადის რეგ.ნომერს (სრეს) და იწყებს ბიზნეს-პროცესს #98 დილზე.
 * მდებარეობა: /rest/local/api/projects/startContractStageWorkflow.php
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;

header('Content-Type: application/json; charset=utf-8');

const CONTRACT_STAGE_WORKFLOW_TEMPLATE_ID = 98;
const CONTRACT_STAGE_REG_NUMBER_FIELD     = 'UF_CRM_1782206133733'; // განაცხადის რეგ.ნომერი (სრეს)

function respond($status, $message = '', $extra = [])
{
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    die();
}

function cswLog($msg)
{
    file_put_contents(__DIR__ . '/startContractStageWorkflow_log.txt', date('Y-m-d H:i:s') . ' | ' . $msg . PHP_EOL, FILE_APPEND);
}

function cswReadRegNumber($dealId)
{
    $row = CCrmDeal::GetListEx(
        [],
        ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'],
        false,
        false,
        ['ID', CONTRACT_STAGE_REG_NUMBER_FIELD]
    )->Fetch();

    return $row ? trim((string)($row[CONTRACT_STAGE_REG_NUMBER_FIELD] ?? '')) : null;
}

global $USER, $DB;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond('error', 'არასწორი მოთხოვნა');
}
if (!$USER->IsAuthorized()) {
    respond('error', 'ავტორიზაცია საჭიროა');
}
if (!check_bitrix_sessid()) {
    respond('error', 'სესიის ვადა ამოიწურა, განაახლეთ გვერდი');
}
if (!Loader::includeModule('crm') || !Loader::includeModule('bizproc')) {
    respond('error', 'CRM ან ბიზნეს-პროცესების მოდული ვერ ჩაიტვირთა');
}

$dealId    = (int)($_POST['deal_id'] ?? 0);
$regNumber = trim((string)($_POST['reg_number'] ?? ''));

if ($dealId <= 0) {
    respond('error', 'დილის ID არასწორია');
}
if ($regNumber === '') {
    respond('error', 'გთხოვთ შეავსოთ ველი „განაცხადის რეგ.ნომერი (სრეს)"');
}

$deal = CCrmDeal::GetList([], ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'], ['ID', 'STAGE_ID'])->Fetch();
if (!$deal) {
    respond('error', 'დილი ვერ მოიძებნა');
}

cswLog("REQUEST deal_id={$dealId} reg_number={$regNumber}");

// ── 1. რეგ.ნომრის შენახვა დილში (პროცესის გაშვებამდე) ──
$dealEntity = new CCrmDeal(false); // უფლებების შემოწმების გარეშე
$fields     = [CONTRACT_STAGE_REG_NUMBER_FIELD => $regNumber];

if (!$dealEntity->Update($dealId, $fields, true, true, ['DISABLE_USER_FIELD_CHECK' => true])) {
    cswLog('Update FAILED: ' . $dealEntity->LAST_ERROR);
}

$saved = cswReadRegNumber($dealId);
cswLog('after Update value=' . $saved);

// raw SQL fallback — თუ API-მ ჩუმად ვერ ჩაწერა
if ($saved !== $regNumber) {
    $field   = CONTRACT_STAGE_REG_NUMBER_FIELD;
    $safeVal = $DB->ForSql($regNumber);

    if ($DB->Query("SELECT VALUE_ID FROM b_uts_crm_deal WHERE VALUE_ID = {$dealId}")->Fetch()) {
        $DB->Query("UPDATE b_uts_crm_deal SET {$field} = '{$safeVal}' WHERE VALUE_ID = {$dealId}");
    } else {
        $DB->Query("INSERT INTO b_uts_crm_deal (VALUE_ID, {$field}) VALUES ({$dealId}, '{$safeVal}')");
    }

    $saved = cswReadRegNumber($dealId);
    cswLog('after SQL fallback value=' . $saved);
}

if ($saved !== $regNumber) {
    respond('error', 'რეგ.ნომრის შენახვა ვერ მოხერხდა, პროცესი არ გაეშვა');
}

// ── 2. ბიზნეს-პროცესის #98 გაშვება ──
$documentId = ['crm', 'CCrmDocumentDeal', 'DEAL_' . $dealId];
$errors = [];

$workflowId = CBPDocument::StartWorkflow(
    CONTRACT_STAGE_WORKFLOW_TEMPLATE_ID,
    $documentId,
    [CBPDocument::PARAM_TAGRET_USER => 'user_' . (int)$USER->GetID()],
    $errors
);

if (!empty($errors) || !$workflowId) {
    $messages = [];
    foreach ($errors as $err) {
        $messages[] = is_array($err) ? ($err['message'] ?? '') : (string)$err;
    }
    cswLog('BP errors: ' . implode('; ', array_filter($messages)));
    respond('error', 'რეგ.ნომერი შენახულია, მაგრამ პროცესი ვერ გაეშვა: ' . implode('; ', array_filter($messages)));
}

cswLog("BP started workflow_id={$workflowId}");
respond('success', 'პროცესი გაეშვა', ['workflow_id' => $workflowId]);