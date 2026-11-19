<?php
/**
 * SMS-ის გაგზავნა Inex-ით (smsservice.inexphone.ge).
 *
 * ტოკენი და გამგზავნის სახელი (subject) მხოლოდ სერვერზეა: inex_sms_secret.php
 * (git-ში არ ინახება, რეპო საჯაროა; ნიმუში - inex_sms_secret.example.php).
 * სერვერზე curl არ არის, ამიტომ HttpClient.
 *
 * იყენებს: BP "SMS-ის გაგზავნა" (დილზე ხელით გაშვება: ნომერი + ტექსტი), იხ. bitrix/bp/sms-send-template.php
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

if (!function_exists('monolithSendSms')) {
    // ბრუნდება: ok, phone (995...), httpCode, id (Inex-ის ID), error
    function monolithSendSms($phone, $text)
    {
        $result = ['ok' => false, 'phone' => monolithSmsPhone($phone), 'httpCode' => 0, 'id' => '', 'error' => null];
        $text = trim((string)$text);

        if ($result['phone'] === '') {
            $result['error'] = 'ნომერი არასწორია: ' . trim((string)$phone);
            return $result;
        }
        if ($text === '') {
            $result['error'] = 'ტექსტი ცარიელია';
            return $result;
        }

        $secretFile = __DIR__ . '/inex_sms_secret.php';
        $secret = is_file($secretFile) ? require $secretFile : null;
        if (!is_array($secret) || empty($secret['token'])) {
            $result['error'] = 'Inex-ის ტოკენი სერვერზე არ არის (inex_sms_secret.php)';
            return $result;
        }

        $http = new HttpClient(['socketTimeout' => 10, 'streamTimeout' => 20]);
        $http->setHeader('Accept', 'application/json');
        $http->setHeader('Authorization', 'Bearer ' . $secret['token']);
        $http->setHeader('Content-Type', 'application/json');
        $response = $http->post('https://smsservice.inexphone.ge/api/v1/sms/one', json_encode([
            'phone'            => $result['phone'],
            'subject'          => !empty($secret['subject']) ? $secret['subject'] : 'MONOLITH',
            'message'          => $text,
            'ignore_blacklist' => true,
        ], JSON_UNESCAPED_UNICODE));

        $result['httpCode'] = (int)$http->getStatus();
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
