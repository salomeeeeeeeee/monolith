<?php
/**
 * SMS-ის გაგზავნა Inex-ით (smsservice.inexphone.ge).
 *
 * ტოკენი და გამგზავნის სახელი (subject) მხოლოდ სერვერზეა: inex_sms_secret.php
 * (git-ში არ ინახება, რეპო საჯაროა; ნიმუში - inex_sms_secret.example.php).
 * სერვერზე curl არ არის, ამიტომ HttpClient.
 *
 * აქ მხოლოდ Inex-თან კავშირია. პორტალის კოდი SMS-ს sms_log.php-ის monolithSmsSendLogged()-ით აგზავნის
 * (სია "SMS ისტორია", დილზე მიბმული; იწერება Inex-ის ორივე პასუხი - გაგზავნა და მიწოდება).
 */

use Bitrix\Main\Web\HttpClient;

if (!function_exists('monolithSmsPhone')) {
    // "551 52 82 07", "+995 551...", "00995551..." -> "995551528207"; არასწორი ნომერი -> ''
    function monolithSmsPhone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string)$phone);
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 9 && $digits[0] === '5') {
            $digits = '995' . $digits;
        }
        if (strpos($digits, '995') === 0) {
            return preg_match('/^9955\d{8}$/', $digits) ? $digits : '';
        }
        // უცხოური ნომერი ქვეყნის კოდით
        return preg_match('/^[1-9]\d{9,14}$/', $digits) ? $digits : '';
    }
}

if (!function_exists('monolithSmsSecret')) {
    /** ['token' => ..., 'subject' => ...] ან null, თუ სერვერზე inex_sms_secret.php არ არის */
    function monolithSmsSecret()
    {
        $secretFile = __DIR__ . '/inex_sms_secret.php';
        $secret = is_file($secretFile) ? require $secretFile : null;
        return is_array($secret) && !empty($secret['token']) ? $secret : null;
    }
}

if (!function_exists('monolithSendSms')) {
    // $callbackUrl - მისამართი, რომელსაც Inex მიწოდებისას იძახებს
    // ბრუნდება: ok, phone (995...), httpCode, id (Inex-ის ID), error, response (Inex-ის პასუხი ტექსტად)
    function monolithSendSms($phone, $text, $callbackUrl = '')
    {
        $result = ['ok' => false, 'phone' => monolithSmsPhone($phone), 'httpCode' => 0, 'id' => '', 'error' => null, 'response' => ''];
        $text = trim((string)$text);

        if ($result['phone'] === '') {
            $result['error'] = 'ნომერი არასწორია: ' . trim((string)$phone);
            return $result;
        }
        if ($text === '') {
            $result['error'] = 'ტექსტი ცარიელია';
            return $result;
        }

        $secret = monolithSmsSecret();
        if (!$secret) {
            $result['error'] = 'Inex-ის ტოკენი სერვერზე არ არის (inex_sms_secret.php)';
            return $result;
        }

        $http = new HttpClient(['socketTimeout' => 10, 'streamTimeout' => 20]);
        $http->setHeader('Accept', 'application/json');
        $http->setHeader('Authorization', 'Bearer ' . $secret['token']);
        $http->setHeader('Content-Type', 'application/json');
        $payload = [
            'phone'            => $result['phone'],
            'subject'          => !empty($secret['subject']) ? $secret['subject'] : 'MONOLITH',
            'message'          => $text,
            'ignore_blacklist' => true,
        ];
        if ($callbackUrl !== '') {
            $payload['delivery_callback_url'] = $callbackUrl;
        }
        $response = $http->post('https://smsservice.inexphone.ge/api/v1/sms/one', json_encode($payload, JSON_UNESCAPED_UNICODE));

        $result['httpCode'] = (int)$http->getStatus();
        $result['response'] = is_string($response) ? $response : '';
        $body = is_string($response) ? json_decode($response, true) : null;
        $result['id'] = (string)($body['data']['id'] ?? '');

        if ($response === false || $result['httpCode'] === 0) {
            $result['error'] = 'Inex-თან დაკავშირება ვერ მოხერხდა: ' . implode('; ', (array)$http->getError());
        } elseif ($result['httpCode'] < 200 || $result['httpCode'] >= 300) {
            $result['error'] = 'HTTP ' . $result['httpCode'] . ': ' . mb_substr((string)$response, 0, 300);
        } else {
            $result['ok'] = true;
        }
        return $result;
    }
}

if (!function_exists('monolithSmsStatus')) {
    // SMS-ის სტატუსი Inex-ში: ok, httpCode, deliveryStatus (რიცხვი ან null), response (ტექსტად), error
    function monolithSmsStatus($inexId)
    {
        $result = ['ok' => false, 'httpCode' => 0, 'deliveryStatus' => null, 'response' => '', 'error' => null];
        $inexId = trim((string)$inexId);
        $secret = monolithSmsSecret();
        if ($inexId === '' || !$secret) {
            $result['error'] = $inexId === '' ? 'Inex-ის ID ცარიელია' : 'Inex-ის ტოკენი სერვერზე არ არის (inex_sms_secret.php)';
            return $result;
        }

        $http = new HttpClient(['socketTimeout' => 10, 'streamTimeout' => 20]);
        $http->setHeader('Accept', 'application/json');
        $http->setHeader('Authorization', 'Bearer ' . $secret['token']);
        $response = $http->get('https://smsservice.inexphone.ge/api/v1/sms/' . rawurlencode($inexId));

        $result['httpCode'] = (int)$http->getStatus();
        $result['response'] = is_string($response) ? $response : '';
        $body = is_string($response) ? json_decode($response, true) : null;
        if (isset($body['data']['attributes']['deliveryStatus'])) {
            $result['deliveryStatus'] = (int)$body['data']['attributes']['deliveryStatus'];
        }
        $result['ok'] = $result['httpCode'] >= 200 && $result['httpCode'] < 300;
        if (!$result['ok']) {
            $result['error'] = $result['httpCode'] ? 'HTTP ' . $result['httpCode'] : 'Inex-თან დაკავშირება ვერ მოხერხდა';
        }
        return $result;
    }
}
