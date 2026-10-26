<?php
/**
 * ხელშეკრულების ეტაპი — იწყებს ბიზნეს-პროცესს #98 დილზე.
 * მდებარეობა: /rest/local/api/projects/startContractStageWorkflow.php
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;

header('Content-Type: application/json; charset=utf-8');

const CONTRACT_STAGE_WORKFLOW_TEMPLATE_ID = 98;

function respond($status, $message = '', $extra = [])
{
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    die();
}

global $USER;

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

$dealId = (int)($_POST['deal_id'] ?? 0);
if ($dealId <= 0) {
    respond('error', 'დილის ID არასწორია');
}

$deal = CCrmDeal::GetList([], ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'], ['ID', 'STAGE_ID'])->Fetch();
if (!$deal) {
    respond('error', 'დილი ვერ მოიძებნა');
}

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
    respond('error', 'პროცესი ვერ გაეშვა: ' . implode('; ', array_filter($messages)));
}

respond('success', 'პროცესი გაეშვა', ['workflow_id' => $workflowId]);