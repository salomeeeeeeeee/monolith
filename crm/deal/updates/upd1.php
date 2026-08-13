<?php
/**
 * WON + Dighomi + ბინა: UF_CRM_1661249856017 → UF_CRM_1779277644355 კოპირება
 * გაშვება: https://crm.monolith.ge/crm/deal/won-dighomi-bina.php
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

CModule::IncludeModule('crm');

define('FILTER_PROJECT', 'Dighomi');
define('FILTER_TYPE', 'ბინა');
define('SOURCE_FIELD', 'UF_CRM_1661249856017');
define('TARGET_FIELD', 'UF_CRM_1779277644355');

$arFilter = [
    'STAGE_ID' => 'WON',
    'UF_CRM_1779277729207' => FILTER_PROJECT,
    'UF_CRM_1779277898205' => FILTER_TYPE,
    'CHECK_PERMISSIONS' => 'N',
];

$updated = 0;
$skipped = 0;
$failed = 0;
$results = [];

$res = CCrmDeal::GetList(
    ['ID' => 'ASC'],
    $arFilter,
    ['ID', 'TITLE', SOURCE_FIELD, TARGET_FIELD]
);

while ($deal = $res->Fetch()) {
    $dealId = (int)$deal['ID'];
    $sourceValue = trim((string)($deal[SOURCE_FIELD] ?? ''));
    $targetValue = trim((string)($deal[TARGET_FIELD] ?? ''));

    if ($sourceValue === '') {
        $skipped++;
        $results[] = [
            'deal_id' => $dealId,
            'title' => $deal['TITLE'],
            'result' => 'skipped',
            'reason' => 'source_empty',
            'source' => $sourceValue,
            'target_before' => $targetValue,
        ];
        continue;
    }

    if ($sourceValue === $targetValue) {
        $skipped++;
        $results[] = [
            'deal_id' => $dealId,
            'title' => $deal['TITLE'],
            'result' => 'skipped',
            'reason' => 'already_same',
            'value' => $sourceValue,
        ];
        continue;
    }

    $crmDeal = new CCrmDeal(false);
    $arFields = [TARGET_FIELD => $sourceValue];
    $ok = $crmDeal->Update($dealId, $arFields);

    if ($ok) {
        $updated++;
        $results[] = [
            'deal_id' => $dealId,
            'title' => $deal['TITLE'],
            'result' => 'updated',
            'source' => $sourceValue,
            'target_before' => $targetValue,
            'target_after' => $sourceValue,
        ];
    } else {
        $failed++;
        $results[] = [
            'deal_id' => $dealId,
            'title' => $deal['TITLE'],
            'result' => 'failed',
            'source' => $sourceValue,
            'error' => $crmDeal->LAST_ERROR,
        ];
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'filter' => [
        'stage' => 'WON',
        'UF_CRM_1779277729207' => FILTER_PROJECT,
        'UF_CRM_1779277898205' => FILTER_TYPE,
    ],
    'copy' => SOURCE_FIELD . ' → ' . TARGET_FIELD,
    'summary' => [
        'total' => count($results),
        'updated' => $updated,
        'skipped' => $skipped,
        'failed' => $failed,
    ],
    'results' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
