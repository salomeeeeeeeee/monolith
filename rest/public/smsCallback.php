<?php
/**
 * Inex-ის მეორე პასუხი (SMS-ის მიწოდება): /rest/public/smsCallback.php?id=<SMS ისტორიის ჩანაწერი>&k=<ხელმოწერა>
 *
 * მისამართს Inex-ს გაგზავნისას გადასცემს local/php_interface/sms_log.php (delivery_callback_url).
 * სტატუსი callback-ის ტექსტიდან არ აიღება - Inex-ის API-დან თავიდან იკითხება.
 */
define("STOP_STATISTICS",       true);
define("NO_KEEP_STATISTIC",     "Y");
define("NO_AGENT_STATISTIC",    "Y");
define("NO_AGENT_CHECK",        true);
define("DisableEventsCheck",    true);
define("NOT_CHECK_PERMISSIONS", true);

ob_start();
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/php_interface/sms_log.php");
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$logId = (int)($_GET['id'] ?? 0);
$expected = $logId > 0 ? monolithSmsCallbackKey($logId) : '';
if ($expected === '' || !hash_equals($expected, (string)($_GET['k'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    die();
}

$body = (string)file_get_contents('php://input', false, null, 0, 16384);
if ($body === '' && !empty($_POST)) {
    $body = http_build_query($_POST);
}

try {
    list($httpCode, $message) = monolithSmsRefreshDelivery($logId, $body);
} catch (\Throwable $e) {
    list($httpCode, $message) = [500, 'error'];
}

http_response_code($httpCode);
echo json_encode(['success' => $httpCode === 200, 'message' => $message], JSON_UNESCAPED_UNICODE);
die();
