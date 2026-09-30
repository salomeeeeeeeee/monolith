<?php
/**
 * AJAX: validate deal stage for BOG merge.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/crm/deal/bank_integration/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!bankBogEnsureModules()) {
    echo json_encode(['exists' => false, 'active' => false, 'error' => 'modules'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dealId = isset($_GET['deal_id']) ? (int)$_GET['deal_id'] : 0;
if ($dealId <= 0) {
    echo json_encode(['exists' => false, 'active' => false, 'error' => 'invalid_deal_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deal = bankBogLoadDealForPayment($dealId);
if (!$deal) {
    echo json_encode(['exists' => false, 'active' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

$stageId = (string)($deal['STAGE_ID'] ?? '');
echo json_encode([
    'exists' => true,
    'active' => bankBogIsActiveMergeStage($stageId),
    'stage_id' => $stageId,
], JSON_UNESCAPED_UNICODE);
