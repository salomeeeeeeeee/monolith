<?php
/**
 * SMS-ის გაგზავნა ლოგით: სია "SMS ისტორია" (CODE SMS_LOG, ქმნის /custom/setup/smsLog.php), დილის ბარათზე ტაბად ჩანს.
 *
 * თითო SMS = ერთი ჩანაწერი. Inex-იდან ორი პასუხი იწერება:
 *   1) SEND_RESPONSE     - გაგზავნისას (HTTP კოდი + პასუხი), იქიდანვე INEX_ID;
 *   2) DELIVERY_RESPONSE - მიწოდებისას: Inex იძახებს /rest/public/smsCallback.php-ს, სტატუსს კი API-დან თავიდან
 *      ვკითხულობთ (callback-ის ტექსტს არ ვენდობით). თუ callback არ მოვიდა, agent-ი თვითონ ამოწმებს (sms_notifications.php).
 *
 * STATUS: რიგშია -> იგზავნება -> გაიგზავნა -> მიწოდებულია / ვერ მიეწოდა;  ან "ვერ გაიგზავნა", "არ გაიგზავნა" (მიზეზი NOTE-ში).
 * EVENT_KEY - ავტომატური SMS-ის გასაღები: ერთი მოვლენა ორჯერ არ იგზავნება.
 */

require_once __DIR__ . '/inex_sms.php';

const MONOLITH_SMS_LOG_CODE      = 'SMS_LOG';
const MONOLITH_SMS_CALLBACK_URL  = 'https://crm.monolith.ge/rest/public/smsCallback.php';

const MONOLITH_SMS_ST_QUEUED     = 'რიგშია';
const MONOLITH_SMS_ST_SENDING    = 'იგზავნება';
const MONOLITH_SMS_ST_SENT       = 'გაიგზავნა';
const MONOLITH_SMS_ST_DELIVERED  = 'მიწოდებულია';
const MONOLITH_SMS_ST_UNDELIVERED = 'ვერ მიეწოდა';
const MONOLITH_SMS_ST_FAILED     = 'ვერ გაიგზავნა';
const MONOLITH_SMS_ST_SKIPPED    = 'არ გაიგზავნა';

function monolithSmsLogIblockId()
{
    static $id = null;
    if (!$id) {
        $row = CModule::IncludeModule('iblock')
            ? CIBlock::GetList([], ['CODE' => MONOLITH_SMS_LOG_CODE, 'CHECK_PERMISSIONS' => 'N'])->Fetch()
            : false;
        $id = $row ? (int)$row['ID'] : 0;
    }
    return $id;
}

/** JSON წასაკითხად; თუ JSON არ არის - ტექსტი როგორც არის */
function monolithSmsPretty($value)
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return $value;
        }
        $value = $decoded;
    }
    return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * ლოგის ჩანაწერი. $data: dealId, type (დასახელება), lang, clientPhone, phone, text, mode, status, note, eventKey
 * ბრუნდება ID ან 0
 */
function monolithSmsLogAdd(array $data)
{
    $iblockId = monolithSmsLogIblockId();
    if (!$iblockId) {
        return 0;
    }
    $type = (string)($data['type'] ?? 'SMS');
    $phone = (string)($data['phone'] ?? '') ?: (string)($data['clientPhone'] ?? '');
    $el = new CIBlockElement();
    $id = $el->Add([
        'IBLOCK_ID'       => $iblockId,
        'NAME'            => trim($type . ' - ' . $phone, ' -'),
        'ACTIVE'          => 'Y',
        'CREATED_BY'      => (int)($data['userId'] ?? 0) ?: 1,
        'MODIFIED_BY'     => (int)($data['userId'] ?? 0) ?: 1,
        'PROPERTY_VALUES' => [
            'DEAL'         => (int)($data['dealId'] ?? 0) ?: '',
            'SMS_TYPE'     => $type,
            'STATUS'       => (string)($data['status'] ?? MONOLITH_SMS_ST_QUEUED),
            'CLIENT_PHONE' => (string)($data['clientPhone'] ?? ''),
            'PHONE'        => (string)($data['phone'] ?? ''),
            'SMS_TEXT'     => (string)($data['text'] ?? ''),
            'SMS_LANG'     => (string)($data['lang'] ?? ''),
            'SMS_MODE'     => (string)($data['mode'] ?? ''),
            'NOTE'         => (string)($data['note'] ?? ''),
            'EVENT_KEY'    => (string)($data['eventKey'] ?? ''),
        ],
    ]);
    return $id ? (int)$id : 0;
}

function monolithSmsLogUpdate($logId, array $props)
{
    if ($logId && ($iblockId = monolithSmsLogIblockId())) {
        CIBlockElement::SetPropertyValuesEx((int)$logId, $iblockId, $props);
    }
}

/** ჩანაწერები: [ID, DATE_CREATE_TS, და ველები კოდებით]. $filter - CIBlockElement::GetList-ის ფილტრი */
function monolithSmsLogList(array $filter, $limit = 50, array $order = ['ID' => 'ASC'])
{
    $iblockId = monolithSmsLogIblockId();
    if (!$iblockId) {
        return [];
    }
    $codes = ['DEAL', 'SMS_TYPE', 'STATUS', 'CLIENT_PHONE', 'PHONE', 'SMS_TEXT', 'SMS_LANG', 'SMS_MODE', 'NOTE', 'EVENT_KEY', 'INEX_ID', 'DELIVERY_RESPONSE'];
    $select = ['ID', 'DATE_CREATE'];
    foreach ($codes as $code) {
        $select[] = 'PROPERTY_' . $code;
    }
    $rows = [];
    $res = CIBlockElement::GetList(
        $order,
        array_merge(['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'], $filter),
        false,
        $limit ? ['nTopCount' => (int)$limit] : false,
        $select
    );
    while ($row = $res->Fetch()) {
        $item = ['ID' => (int)$row['ID'], 'DATE_CREATE_TS' => (int)MakeTimeStamp($row['DATE_CREATE'])];
        foreach ($codes as $code) {
            $value = $row['PROPERTY_' . $code . '_VALUE'] ?? '';
            $item[$code] = (string)(is_array($value) ? reset($value) : $value);
        }
        $rows[$item['ID']] = $item;
    }
    return array_values($rows);
}

/** callback მისამართის ხელმოწერა, რომ უცხო მოთხოვნამ ჩანაწერი ვერ შეცვალოს */
function monolithSmsCallbackKey($logId)
{
    $secret = monolithSmsSecret();
    return $secret ? substr(hash_hmac('sha256', 'sms-callback-' . (int)$logId, $secret['token']), 0, 20) : '';
}

/** არსებული ჩანაწერის გაგზავნა: Inex-ის პასუხი ჩანაწერში იწერება. ბრუნდება monolithSendSms()-ის შედეგი */
function monolithSmsLogSend($logId, $phone, $text, $modeLabel = '')
{
    $sentPhone = monolithSmsPhone($phone) ?: $phone;
    monolithSmsLogUpdate($logId, [
        'STATUS'   => MONOLITH_SMS_ST_SENDING,
        'PHONE'    => $sentPhone,
        'SMS_MODE' => $modeLabel,
    ]);
    // ჩანაწერის სახელში ის ნომერია, სადაც SMS რეალურად წავიდა (სიაში ძებნა სახელით ხდება)
    $rows = $logId ? monolithSmsLogList(['ID' => (int)$logId], 1) : [];
    if ($rows) {
        (new CIBlockElement())->Update((int)$logId, ['NAME' => trim($rows[0]['SMS_TYPE'] . ' - ' . $sentPhone, ' -')]);
    }

    $callback = $logId ? MONOLITH_SMS_CALLBACK_URL . '?id=' . (int)$logId . '&k=' . monolithSmsCallbackKey($logId) : '';
    $sms = monolithSendSms($phone, $text, $callback);

    monolithSmsLogUpdate($logId, [
        'STATUS'        => $sms['ok'] ? MONOLITH_SMS_ST_SENT : MONOLITH_SMS_ST_FAILED,
        'NOTE'          => $sms['ok'] ? '' : (string)$sms['error'],
        'INEX_ID'       => $sms['id'],
        'SENT_AT'       => date('d/m/Y H:i:s'),
        'SEND_RESPONSE' => 'HTTP ' . $sms['httpCode'] . ($sms['error'] ? "\n" . $sms['error'] : '')
            . ($sms['response'] !== '' ? "\n" . monolithSmsPretty($sms['response']) : ''),
    ]);
    return $sms;
}

/**
 * SMS ახლავე + ლოგი (BP "SMS-ის გაგზავნა" და ხელით გაგზავნა).
 * $meta: dealId, type, lang, mode, userId, clientPhone. ბრუნდება monolithSendSms()-ის შედეგი + logId
 */
function monolithSmsSendLogged($phone, $text, array $meta = [])
{
    $logId = monolithSmsLogAdd([
        'dealId'      => (int)($meta['dealId'] ?? 0),
        'type'        => (string)($meta['type'] ?? 'ხელით გაგზავნილი'),
        'lang'        => (string)($meta['lang'] ?? ''),
        'clientPhone' => (string)($meta['clientPhone'] ?? ''),
        'phone'       => monolithSmsPhone($phone) ?: trim((string)$phone),
        'text'        => trim((string)$text),
        'mode'        => (string)($meta['mode'] ?? ''),
        'status'      => MONOLITH_SMS_ST_SENDING,
        'userId'      => (int)($meta['userId'] ?? 0),
    ]);
    $sms = monolithSmsLogSend($logId, $phone, $text, (string)($meta['mode'] ?? ''));
    $sms['logId'] = $logId;
    return $sms;
}

/** Inex deliveryStatus (SMPP message_state) -> [STATUS, განმარტება] */
function monolithSmsDeliveryStatus($code)
{
    $map = [
        0 => [MONOLITH_SMS_ST_SENT, 'მოლოდინში'],
        1 => [MONOLITH_SMS_ST_SENT, 'გზაშია'],
        2 => [MONOLITH_SMS_ST_DELIVERED, ''],
        3 => [MONOLITH_SMS_ST_UNDELIVERED, 'ვადა გაუვიდა'],
        4 => [MONOLITH_SMS_ST_UNDELIVERED, 'წაიშალა'],
        5 => [MONOLITH_SMS_ST_UNDELIVERED, 'ვერ მიეწოდა'],
        6 => [MONOLITH_SMS_ST_SENT, 'მიღებულია'],
        7 => [MONOLITH_SMS_ST_SENT, 'უცნობი სტატუსი'],
        8 => [MONOLITH_SMS_ST_UNDELIVERED, 'უარყოფილია'],
    ];
    return $map[$code] ?? [MONOLITH_SMS_ST_SENT, 'სტატუსი ' . $code];
}

/**
 * მეორე პასუხი: სტატუსი Inex-იდან ჩანაწერში. $callbackBody - რაც Inex-მა გამოაგზავნა (ინახება).
 * ბრუნდება [HTTP კოდი, შეტყობინება]
 */
function monolithSmsRefreshDelivery($logId, $callbackBody = '')
{
    $rows = monolithSmsLogList(['ID' => (int)$logId], 1);
    if (!$rows) {
        return [404, 'ჩანაწერი ვერ მოიძებნა'];
    }
    $log = $rows[0];

    // Inex-ის callback form-encoded არის: uuid=..&message_status=2&event_type=delivery&result=OK
    $callback = json_decode((string)$callbackBody, true);
    if (!is_array($callback)) {
        $callback = [];
        parse_str((string)$callbackBody, $callback);
    }
    $inexId = $log['INEX_ID'] !== '' ? $log['INEX_ID'] : (string)($callback['uuid'] ?? '');
    if ($inexId === '') {
        return [409, 'INEX_ID ცარიელია'];
    }

    $status = monolithSmsStatus($inexId);
    if (!$callback) {
        // agent-ის შემოწმება: ადრე მოსული callback ჩანაწერში რჩება
        $previous = json_decode($log['DELIVERY_RESPONSE'], true);
        $callback = is_array($previous) && !empty($previous['callback']) ? $previous['callback'] : [];
    }
    $props = [
        'DELIVERY_RESPONSE' => monolithSmsPretty([
            'received_at' => date('d/m/Y H:i:s'),
            'callback'    => $callback ?: null,
            'status'      => json_decode($status['response'], true) ?: ($status['error'] ?: $status['response']),
        ]),
    ];
    if ($log['INEX_ID'] === '') {
        $props['INEX_ID'] = $inexId;
    }
    if ($status['deliveryStatus'] !== null) {
        list($label, $note) = monolithSmsDeliveryStatus($status['deliveryStatus']);
        $props['STATUS'] = $label;
        $props['NOTE'] = $note;
        if ($label === MONOLITH_SMS_ST_DELIVERED && $log['STATUS'] !== MONOLITH_SMS_ST_DELIVERED) {
            $props['DELIVERED_AT'] = date('d/m/Y H:i:s');
        }
    }
    monolithSmsLogUpdate($log['ID'], $props);

    return $status['ok'] ? [200, $props['STATUS'] ?? 'სტატუსი უცნობია'] : [502, (string)$status['error']];
}
