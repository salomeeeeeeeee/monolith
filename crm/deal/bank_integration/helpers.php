<?php
/**
 * Shared helpers for BOG import / merge (Monolith / New Depot).
 */

require_once __DIR__ . '/config.php';

use Bitrix\Main\Loader;

if (!function_exists('bankBogEnsureModules')) {
    function bankBogEnsureModules()
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $ok = Loader::includeModule('iblock') && Loader::includeModule('crm');
        return $ok;
    }
}

if (!function_exists('bankBogAddElement')) {
    function bankBogAddElement(array $fields, array $props = [])
    {
        $el = new CIBlockElement();
        $fields['PROPERTY_VALUES'] = $props;
        $fields['CHECK_PERMISSIONS'] = 'N';
        $id = $el->Add($fields);
        return $id ?: ('Error: ' . $el->LAST_ERROR);
    }
}

/**
 * ელემენტები თვისებებით. VALUE იკითხება GetProperties()-ით, ამიტომ List ტიპის
 * ველები (refund) ტექსტად მოდის და CRM-bind (DEAL) ცარიელი არ რჩება.
 */
if (!function_exists('bankBogGetElements')) {
    function bankBogGetElements(array $filter, array $select = null, array $sort = ['ID' => 'DESC'], $limit = false)
    {
        if ($select === null) {
            $select = ['ID', 'IBLOCK_ID', 'NAME', 'DATE_ACTIVE_FROM'];
        }
        if (!isset($filter['CHECK_PERMISSIONS'])) {
            $filter['CHECK_PERMISSIONS'] = 'N';
        }
        $nav = $limit ? ['nTopCount' => (int)$limit] : false;
        $out = [];
        $seen = [];
        $res = CIBlockElement::GetList($sort, $filter, false, $nav, $select);
        while ($ob = $res->GetNextElement()) {
            $row = $ob->GetFields();
            // multiple ველებზე GetList ერთ ელემენტს რამდენჯერმე აბრუნებს
            $id = $row['ID'] ?? null;
            if ($id !== null) {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
            }
            foreach ($ob->GetProperties() as $code => $prop) {
                $propCode = !empty($prop['CODE']) ? $prop['CODE'] : $code;
                $row[$propCode] = $prop['VALUE'];
            }
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('bankBogMoney')) {
    function bankBogMoney($value, $decimals = 2)
    {
        return floatval(str_replace(',', '.', number_format(floatval($value), $decimals, '.', '')));
    }
}

if (!function_exists('bankBogParseAmount')) {
    function bankBogParseAmount($raw)
    {
        if (is_array($raw)) {
            $raw = $raw['VALUE'] ?? ($raw[0] ?? '');
        }
        $text = trim((string)$raw);
        if ($text === '') {
            return 0.0;
        }
        // Bitrix money: "1234.56|USD"
        $text = preg_replace('/\|[A-Z]{3}$/i', '', $text);
        $text = str_replace([' ', "\xC2\xA0"], '', $text);
        if (strpos($text, ',') !== false && strpos($text, '.') !== false) {
            $text = str_replace(',', '', $text); // 1,234.56
        } else {
            $text = str_replace(',', '.', $text); // 1234,56
        }
        return floatval($text);
    }
}

if (!function_exists('bankBogNormalizeDealId')) {
    function bankBogNormalizeDealId($raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^D_\s*(\d+)/i', $raw, $m)) {
            return (int)$m[1];
        }
        $stripped = rtrim($raw, ".,; \t\n\r");
        if (preg_match('/^(\d+)/', $stripped, $m)) {
            return (int)$m[1];
        }
        return '';
    }
}

if (!function_exists('bankBogParseDate')) {
    function bankBogParseDate($value)
    {
        if ($value instanceof DateTime) {
            return $value;
        }
        if (is_array($value)) {
            $value = $value['VALUE'] ?? ($value[0] ?? '');
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (strpos($text, 'T') !== false) {
            $text = explode('T', $text)[0];
        }
        foreach (['d/m/Y', 'd.m.Y', 'Y-m-d', 'd/m/Y H:i:s', 'Y-m-d H:i:s'] as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $text);
            if ($dt instanceof DateTime) {
                $dt->setTime(0, 0, 0);
                return $dt;
            }
        }
        $ts = strtotime(str_replace('/', '.', $text));
        if ($ts) {
            $dt = new DateTime('@' . $ts);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $dt->setTime(0, 0, 0);
            return $dt;
        }
        return null;
    }
}

if (!function_exists('bankBogFormatDateDmy')) {
    function bankBogFormatDateDmy($value)
    {
        $dt = bankBogParseDate($value);
        return $dt ? $dt->format('d/m/Y') : '';
    }
}

if (!function_exists('bankBogUfValue')) {
    function bankBogUfValue($value)
    {
        if (is_array($value)) {
            $value = $value['VALUE'] ?? ($value[0] ?? '');
        }
        return trim((string)$value);
    }
}

if (!function_exists('bankBogDealCurrencyLabel')) {
    function bankBogDealCurrencyLabel($deal)
    {
        $raw = bankBogUfValue($deal[BANK_D_CURRENCY] ?? '');
        if ($raw === '322' || strtoupper($raw) === 'GEL') {
            return 'GEL';
        }
        if ($raw === '323' || strtoupper($raw) === 'USD') {
            return 'USD';
        }
        return 'USD';
    }
}

if (!function_exists('bankBogResolveContractDate')) {
    function bankBogResolveContractDate(array $deal)
    {
        return bankBogFormatDateDmy(bankBogUfValue($deal[BANK_D_CONTRACT_DATE] ?? ''));
    }
}

/**
 * HTTP helper — Bitrix სერვერზე ხშირად curl არაა; ვიყენებთ file_get_contents + stream context.
 *
 * @return array{ok:bool,body:?string,code:int,error:?string}
 */
if (!function_exists('bankBogHttpRequest')) {
    function bankBogHttpRequest($url, array $options = [])
    {
        $method = strtoupper((string)($options['method'] ?? 'GET'));
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;
        $timeout = (int)($options['timeout'] ?? 30);

        $headerLines = [];
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                $headerLines[] = $value;
            } else {
                $headerLines[] = $key . ': ' . $value;
            }
        }

        $http = [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'timeout' => $timeout,
            'ignore_errors' => true,
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }

        $context = stream_context_create(['http' => $http]);
        $response = @file_get_contents($url, false, $context);

        $code = 0;
        if (!empty($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                    $code = (int)$m[1];
                }
            }
        }

        if ($response === false) {
            return [
                'ok' => false,
                'body' => null,
                'code' => $code,
                'error' => 'request failed',
            ];
        }

        return [
            'ok' => ($code === 0 || ($code >= 200 && $code < 300)),
            'body' => $response,
            'code' => $code,
            'error' => null,
        ];
    }
}

/**
 * NBG rate (GEL per 1 unit, USD by default) for a Y-m-d date. Returns null on failure.
 */
if (!function_exists('bankBogGetNbgRate')) {
    function bankBogGetNbgRate($dateYmd, &$errorMsg = null, $currency = 'USD')
    {
        static $cache = [];
        $dateYmd = trim((string)$dateYmd);
        if ($dateYmd === '') {
            $dateYmd = date('Y-m-d');
        }
        $currency = strtoupper(trim((string)$currency)) ?: 'USD';
        $cacheKey = $currency . '|' . $dateYmd;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $url = 'https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies?Currencies=' . rawurlencode($currency)
            . '&date=' . rawurlencode($dateYmd);
        $res = bankBogHttpRequest($url, ['timeout' => 8]);

        if ($res['body'] === null) {
            $errorMsg = "ეროვნული ბანკის {$currency} კურსი მიუწვდომელია ({$dateYmd}).";
            error_log('NBG rate failed: ' . ($res['error'] ?? ''));
            return null;
        }
        if ($res['code'] && $res['code'] !== 200) {
            $errorMsg = "ეროვნული ბანკის {$currency} კურსი მიუწვდომელია ({$dateYmd}). HTTP {$res['code']}.";
            return null;
        }

        $decoded = json_decode($res['body']);
        $rate = $decoded[0]->currencies[0]->rate ?? null;
        $quantity = (int)($decoded[0]->currencies[0]->quantity ?? 1);
        if ($rate === null) {
            $errorMsg = "ეროვნული ბანკის {$currency} კურსი ვერ მოიძებნა ({$dateYmd}).";
            return null;
        }

        $cache[$cacheKey] = bankBogMoney($rate / max(1, $quantity), 4);
        return $cache[$cacheKey];
    }
}

/**
 * ამონაწერის თანხა ლარში და დოლარში.
 * $amountBase = ბანკის ლარის ეკვივალენტი (EntryAmountBase); EUR-ს ის ლარში გადაჰყავს,
 * დოლარი კი ლარიდან NBG USD კურსით გამოითვლება.
 *
 * @return array{GEL:float,USD:float}
 */
if (!function_exists('bankBogConvertEntryAmount')) {
    function bankBogConvertEntryAmount($currency, $amount, $amountBase, $nbgUsd, $dateYmd = '')
    {
        $currency = strtoupper(trim((string)$currency));
        $amount = bankBogMoney($amount);
        $amountBase = bankBogMoney($amountBase);
        $nbgUsd = floatval($nbgUsd);

        if ($currency === 'GEL') {
            $gel = $amount;
        } elseif ($currency === 'USD' && $nbgUsd > 0) {
            $gel = $amount * $nbgUsd;
        } elseif ($amountBase != 0.0) {
            $gel = $amountBase;
        } else {
            $rate = bankBogGetNbgRate($dateYmd, $ignored, $currency);
            $gel = $rate ? $amount * $rate : 0.0;
        }

        if ($currency === 'USD') {
            $usd = $amount;
        } else {
            $usd = $nbgUsd > 0 ? $gel / $nbgUsd : 0.0;
        }

        return ['GEL' => bankBogMoney($gel), 'USD' => bankBogMoney($usd)];
    }
}

/** API-ის EntryId (JSON-ში float) სტრიქონად, მაგ. 124628323429. */
if (!function_exists('bankBogEntryIdString')) {
    function bankBogEntryIdString($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_float($value) || is_int($value)) {
            return sprintf('%.0f', $value);
        }
        return trim((string)$value);
    }
}

if (!function_exists('bankBogFetchAccessToken')) {
    function bankBogFetchAccessToken($company, &$error = null)
    {
        static $tokens = [];
        if (isset($tokens[$company])) {
            return $tokens[$company];
        }

        list($clientId, $clientSecret) = bankBogCredentials($company);
        if ($clientId === '' || $clientSecret === '') {
            $error = 'client ID / secret არ წერია credentials.php-ში (' . $company . ')';
            return null;
        }
        $url = 'https://account.bog.ge/auth/realms/bog/protocol/openid-connect/token';
        $data = http_build_query(['grant_type' => 'client_credentials']);
        $res = bankBogHttpRequest($url, [
            'method' => 'POST',
            'body' => $data,
            'timeout' => 20,
            'headers' => [
                'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        if ($res['body'] === null || ($res['code'] && $res['code'] >= 400)) {
            $error = $res['error'] ?: ('Auth HTTP ' . $res['code']);
            return null;
        }
        $json = json_decode($res['body'], true);
        $token = $json['access_token'] ?? null;
        if (!$token) {
            $error = 'access_token missing';
            return null;
        }
        // ტოკენი 30 წუთი მოქმედებს - ერთი იმპორტისთვის საკმარისია
        return $tokens[$company] = $token;
    }
}

if (!function_exists('bankBogApiGet')) {
    function bankBogApiGet($url, $accessToken, &$error = null)
    {
        $res = bankBogHttpRequest($url, [
            'timeout' => 60,
            'headers' => [
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);

        if ($res['body'] === null) {
            $error = $res['error'] ?: 'request failed';
            return null;
        }
        if ($res['code'] && $res['code'] >= 400) {
            $error = "API HTTP {$res['code']}: " . substr((string)$res['body'], 0, 200);
            return null;
        }
        return json_decode($res['body']);
    }
}

/**
 * უკვე ჩატვირთული EntryId-ები დუბლიკატების გამოსატოვებლად.
 * DocumentKey უნიკალური არ არის: ერთ საბუთს (მაგ. ხელფასი) რამდენიმე ჩანაწერი აქვს,
 * EntryId კი თითო ჩანაწერზე ბანკის მასშტაბით უნიკალურია.
 */
if (!function_exists('bankBogLoadExistingKeys')) {
    function bankBogLoadExistingKeys()
    {
        $entryIds = [];
        $iblockId = bankBogStatementIblockId();
        if ($iblockId <= 0) {
            return $entryIds;
        }
        $res = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'PROPERTY_EntryId']
        );
        while ($row = $res->Fetch()) {
            $entryId = trim((string)($row['PROPERTY_ENTRYID_VALUE'] ?? ''));
            if ($entryId !== '') {
                $entryIds[$entryId] = true;
            }
        }
        return $entryIds;
    }
}

if (!function_exists('bankBogBuildStatementPropsFromRecord')) {
    function bankBogBuildStatementPropsFromRecord($record, array $account, $nbgRate)
    {
        $currency = $account['currency'];
        $entryDate = explode('T', (string)($record->EntryDate ?? ''))[0];
        $amounts = bankBogConvertEntryAmount(
            $currency,
            $record->EntryAmount ?? 0,
            $record->EntryAmountBase ?? 0,
            $nbgRate,
            $entryDate
        );
        $props = [
            'AMOUNT_GEL' => $amounts['GEL'],
            'AMOUNT_USD' => $amounts['USD'],
        ];

        $props['EntryDate'] = $record->EntryDate ?? '';
        $props['EntryDocumentNumber'] = $record->EntryDocumentNumber ?? '';
        $props['EntryAccountNumber'] = $record->EntryAccountNumber ?? '';
        $props['EntryAmountDebit'] = $record->EntryAmountDebit ?? '';
        $props['EntryAmountDebitBase'] = $record->EntryAmountDebitBase ?? '';
        $props['EntryAmountCredit'] = $record->EntryAmountCredit ?? '';
        $props['EntryAmountCreditBase'] = $record->EntryAmountCreditBase ?? '';
        $props['EntryAmountBase'] = $record->EntryAmountBase ?? '';
        $props['EntryAmount'] = $record->EntryAmount ?? '';
        $props['EntryComment'] = $record->EntryComment ?? '';
        $props['EntryDepartment'] = $record->EntryDepartment ?? '';
        $props['EntryAccountPoint'] = $record->EntryAccountPoint ?? '';
        $props['DocumentProductGroup'] = $record->DocumentProductGroup ?? '';
        $props['DocumentValueDate'] = $record->DocumentValueDate ?? '';

        $sender = $record->SenderDetails ?? null;
        $props['SenderDetails_Name'] = $sender->Name ?? '';
        $props['SenderDetails_Inn'] = $sender->Inn ?? '';
        $props['SenderDetails_AccountNumber'] = $sender->AccountNumber ?? '';
        $props['SenderDetails_BankCode'] = $sender->BankCode ?? '';
        $props['SenderDetails_BankName'] = $sender->BankName ?? ($sender->Name ?? '');

        $ben = $record->BeneficiaryDetails ?? null;
        $props['BeneficiaryDetails_Name'] = $ben->Name ?? '';
        $props['BeneficiaryDetails_Inn'] = $ben->Inn ?? '';
        $props['BeneficiaryDetails_AccountNumber'] = $ben->AccountNumber ?? '';
        $props['BeneficiaryDetails_BankCode'] = $ben->BankCode ?? '';
        $props['BeneficiaryDetails_BankName'] = $ben->BankName ?? '';

        $props['DocumentTreasuryCode'] = $record->DocumentTreasuryCode ?? '';
        $props['DocumentNomination'] = $record->DocumentNomination ?? '';
        $props['DocumentInformation'] = $record->DocumentInformation ?? '';
        $props['DocumentSourceAmount'] = $record->DocumentSourceAmount ?? '';
        $props['DocumentSourceCurrency'] = $record->DocumentSourceCurrency ?? '';
        $props['DocumentDestinationAmount'] = $record->DocumentDestinationAmount ?? '';
        $props['DocumentDestinationCurrency'] = $record->DocumentDestinationCurrency ?? '';
        $props['DocumentReceiveDate'] = $record->DocumentReceiveDate ?? '';
        $props['DocumentBranch'] = $record->DocumentBranch ?? '';
        $props['DocumentDepartment'] = $record->DocumentDepartment ?? '';
        $props['DocumentActualDate'] = $record->DocumentActualDate ?? '';
        $props['DocumentExpiryDate'] = $record->DocumentExpiryDate ?? '';
        $props['DocumentRateLimit'] = $record->DocumentRateLimit ?? '';
        $props['DocumentRate'] = $record->DocumentRate ?? '';
        $props['DocumentRegistrationRate'] = $record->DocumentRegistrationRate ?? '';
        $props['DocumentSenderInstitution'] = $record->DocumentSenderInstitution ?? '';
        $props['DocumentIntermediaryInstitution'] = $record->DocumentIntermediaryInstitution ?? '';
        $props['DocumentBeneficiaryInstitution'] = $record->DocumentBeneficiaryInstitution ?? '';
        $props['DocumentPayee'] = $record->DocumentPayee ?? '';
        $props['DocumentCorrespondentAccountNumber'] = $record->DocumentCorrespondentAccountNumber ?? '';
        $props['DocumentCorrespondentBankCode'] = $record->DocumentCorrespondentBankCode ?? '';
        $props['DocumentCorrespondentBankName'] = $record->DocumentCorrespondentBankName ?? '';
        $props['DocumentKey'] = $record->DocumentKey ?? '';
        $props['EntryId'] = bankBogEntryIdString($record->EntryId ?? null);
        $props['DocComment'] = $record->DocComment ?? '';
        $props['DocumentPayerInn'] = $record->DocumentPayerInn ?? '';
        $props['DocumentPayerName'] = $record->DocumentPayerName ?? '';
        $props['istodayactivity'] = 'false';
        $props['ACCOUNT_CURRENCY'] = $currency;
        $props['ACCOUNT_NUMBER'] = $account['iban'];
        $props['NBG_RATE'] = $nbgRate;
        $props['SALE_TYPE'] = 'SALE';
        $props['PROJECT'] = $account['project'];

        return $props;
    }
}

/**
 * თუ ჩანაწერი მერჯზე არ უნდა გამოჩნდეს — აბრუნებს მიზეზის ტექსტს, სხვა შემთხვევაში ცარიელს.
 */
if (!function_exists('bankBogSkipReasonForRecord')) {
    function bankBogSkipReasonForRecord($record)
    {
        $nomination = trim((string)($record->DocumentNomination ?? ''));
        if ($nomination === 'საკუთარ ანგარიშზე გადარიცხვა') {
            return 'დანიშნულება: საკუთარ ანგარიშზე გადარიცხვა';
        }
        if ($nomination === 'კონვერტაცია') {
            return 'დანიშნულება: კონვერტაცია';
        }

        $senderInn = trim((string)($record->SenderDetails->Inn ?? ''));
        $benInn = trim((string)($record->BeneficiaryDetails->Inn ?? ''));
        if ($senderInn !== '' && $senderInn === $benInn) {
            return 'გამგზავნისა და მიმღების INN იდენტურია (შიდა გადარიცხვა)';
        }
        if ($senderInn !== '' && in_array($senderInn, array_column(bankBogCompanies(), 'inn'), true)) {
            return 'გადარიცხვა ჩვენი კომპანიიდან (შიდა გადარიცხვა)';
        }

        $amountStr = (string)($record->EntryAmount ?? '');
        if ($amountStr !== '' && $amountStr[0] === '-') {
            return 'უარყოფითი თანხა (დებეტი / გასვლა)';
        }

        $senderName = trim((string)($record->SenderDetails->Name ?? ''));
        if ($senderName === 'სახელმწიფო ხაზინა') {
            return 'გამგზავნი: სახელმწიფო ხაზინა';
        }

        return '';
    }
}

/**
 * ერთი ანგარიში+ვალუტის ამონაწერის ჩანაწერები.
 * ბანკი ერთ პასუხში მაქსიმუმ 1000 ჩანაწერს აბრუნებს (Count = სრული რაოდენობა);
 * დანარჩენი გვერდებად მოდის: statement/{iban}/{ccy}/{Id}/{page}. გვერდების რიგი
 * პირველ პასუხს ზუსტად არ ემთხვევა, ამიტომ ყველა გვერდი იკითხება და დუბლიკატებს
 * იმპორტი EntryId-ით ტოვებს.
 *
 * @return array|null null = ამონაწერი ვერ მოვიდა; $error შეიძლება შეივსოს ნაწილობრივ მიღებისასაც
 */
if (!function_exists('bankBogFetchStatementRecords')) {
    function bankBogFetchStatementRecords(array $account, $fromDate, $toDate, &$error = null)
    {
        $token = bankBogFetchAccessToken($account['company'], $authErr);
        if (!$token) {
            $error = 'ავტორიზაცია ვერ მოხერხდა: ' . ($authErr ?: 'unknown');
            return null;
        }

        $base = 'https://api.businessonline.ge/api/statement/'
            . rawurlencode($account['iban']) . '/' . rawurlencode($account['currency']) . '/';
        $payload = bankBogApiGet($base . rawurlencode($fromDate) . '/' . rawurlencode($toDate), $token, $apiErr);
        if ($payload === null) {
            $error = 'ამონაწერის მიღება ვერ მოხერხდა: ' . ($apiErr ?: 'unknown');
            return null;
        }

        $records = is_array($payload->Records ?? null) ? $payload->Records : [];
        $total = (int)($payload->Count ?? 0);
        $statementId = bankBogEntryIdString($payload->Id ?? null);

        if ($statementId !== '' && $total > count($records)) {
            $pages = (int)ceil($total / 1000);
            for ($page = 1; $page <= $pages; $page++) {
                $more = bankBogApiGet($base . rawurlencode($statementId) . '/' . $page, $token, $apiErr);
                if (!is_array($more)) {
                    $error = "ამონაწერის გვერდი {$page} ვერ მოვიდა: " . ($apiErr ?: 'unknown');
                    break;
                }
                $records = array_merge($records, $more);
            }
        }

        return $records;
    }
}

/**
 * ერთი ანგარიში+ვალუტის იმპორტი. $existingIds (EntryId => true) ივსება ახალი ჩანაწერებით.
 *
 * გასავალი (უარყოფითი თანხა) არ ინახება - კლიენტის გადახდა არ არის.
 * შემოსავალი, რომელიც ფილტრს არ გადის (შიდა გადარიცხვა, ხაზინა...), ინახება REASON ველით.
 * EntryId-ის გარეშე ჩანაწერი ბანკში ჯერ გატარებული არ არის და შემდეგ იმპორტზე ჩაიტვირთება.
 */
if (!function_exists('bankBogImportAccount')) {
    function bankBogImportAccount(array $account, $fromDate, $toDate, array &$existingIds)
    {
        $stats = [
            'fetched' => 0,
            'created' => 0,
            'skipped_dup' => 0,
            'skipped_filter' => 0,
            'debit' => 0,
            'pending' => 0,
            'errors' => [],
        ];

        $records = bankBogFetchStatementRecords($account, $fromDate, $toDate, $fetchErr);
        if ($fetchErr) {
            $stats['errors'][] = $fetchErr;
        }
        if ($records === null) {
            return $stats;
        }

        $iblockId = bankBogStatementIblockId();
        $seen = [];

        foreach ($records as $record) {
            $entryId = bankBogEntryIdString($record->EntryId ?? null);
            if ($entryId === '') {
                $stats['pending']++;
                continue;
            }
            // გვერდებს შორის გამეორებული ჩანაწერი ერთხელ ითვლება
            if (isset($seen[$entryId])) {
                continue;
            }
            $seen[$entryId] = true;
            $stats['fetched']++;

            if (isset($existingIds[$entryId])) {
                $stats['skipped_dup']++;
                continue;
            }
            if (bankBogMoney($record->EntryAmount ?? 0) < 0) {
                $stats['debit']++;
                continue;
            }

            $skipReason = bankBogSkipReasonForRecord($record);

            $entryDate = explode('T', (string)($record->EntryDate ?? ''))[0];
            if ($entryDate === '') {
                $entryDate = date('Y-m-d');
            }
            $nbg = bankBogGetNbgRate($entryDate, $nbgErr);
            if ($nbg === null) {
                $stats['errors'][] = $nbgErr;
                break;
            }

            $props = bankBogBuildStatementPropsFromRecord($record, $account, $nbg);
            $props['REASON'] = $skipReason;

            $name = $skipReason !== '' ? 'ამონაწერი (გამოტოვებული)' : 'ამონაწერი';
            $res = bankBogAddElement([
                'IBLOCK_ID' => $iblockId,
                'NAME' => $name,
                'ACTIVE' => 'Y',
            ], $props);

            if (is_numeric($res) && (int)$res > 0) {
                $existingIds[$entryId] = true;
                if ($skipReason !== '') {
                    $stats['skipped_filter']++;
                } else {
                    $stats['created']++;
                }
            } else {
                $stats['errors'][] = (string)$res;
            }
        }

        return $stats;
    }
}

/**
 * ამონაწერების იმპორტი თარიღების შუალედში მითითებული ანგარიშებისთვის.
 *
 * @param string[] $accountKeys bankBogAccounts()-ის გასაღებები
 * @return array key => bankBogImportAccount()-ის სტატისტიკა
 */
if (!function_exists('bankBogImportStatements')) {
    function bankBogImportStatements($fromDate, $toDate, array $accountKeys, &$errorMsg = null)
    {
        if (bankBogStatementIblockId() <= 0) {
            $errorMsg = 'ამონაწერების სია არ არსებობს - გაუშვი /crm/deal/bank_integration/setup.php';
            return [];
        }

        $existingIds = bankBogLoadExistingKeys();
        $results = [];
        foreach ($accountKeys as $key) {
            $account = bankBogAccountByKey($key);
            if ($account) {
                $results[$account['key']] = bankBogImportAccount($account, $fromDate, $toDate, $existingIds);
            }
        }
        return $results;
    }
}

if (!function_exists('bankBogGetDealsByFilter')) {
    function bankBogGetDealsByFilter(array $filter, array $select = [], array $sort = ['ID' => 'ASC'])
    {
        $filter['CHECK_PERMISSIONS'] = 'N';
        $out = [];
        $res = CCrmDeal::GetList($sort, $filter, $select);
        while ($row = $res->Fetch()) {
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('bankBogGetContactsByFilter')) {
    function bankBogGetContactsByFilter(array $filter, array $select = [])
    {
        $filter['CHECK_PERMISSIONS'] = 'N';
        $out = [];
        $res = CCrmContact::GetList(['ID' => 'ASC'], $filter, $select);
        while ($row = $res->Fetch()) {
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('bankBogGetCompaniesByFilter')) {
    function bankBogGetCompaniesByFilter(array $filter, array $select = [])
    {
        $filter['CHECK_PERMISSIONS'] = 'N';
        $out = [];
        $res = CCrmCompany::GetList(['ID' => 'ASC'], $filter, $select);
        while ($row = $res->Fetch()) {
            $out[] = $row;
        }
        return $out;
    }
}

/**
 * Resolve payer INN → contact/company IDs (batched for many INNs).
 * პასპორტისა და კომპანიის საიდ. კოდის ველები ცარიელი კონფიგით გამოტოვდება.
 *
 * Returns [
 *   inn => ['type'=>'contact'|'company', 'id'=>int, 'name'=>string],
 * ]
 */
if (!function_exists('bankBogResolvePayersByInn')) {
    function bankBogResolvePayersByInn(array $inns)
    {
        $inns = array_values(array_unique(array_filter(array_map('trim', $inns))));
        $map = [];
        if (empty($inns)) {
            return $map;
        }

        $contactName = function ($c) {
            $full = trim((string)($c['FULL_NAME'] ?? ''));
            return $full !== '' ? $full : trim(($c['NAME'] ?? '') . ' ' . ($c['LAST_NAME'] ?? ''));
        };

        // Contacts: personal ID
        $contacts = bankBogGetContactsByFilter(
            [BANK_C_PERSONAL_ID => $inns],
            ['ID', 'NAME', 'LAST_NAME', 'FULL_NAME', BANK_C_PERSONAL_ID]
        );
        foreach ($contacts as $c) {
            $inn = bankBogUfValue($c[BANK_C_PERSONAL_ID] ?? '');
            if ($inn !== '' && !isset($map[$inn])) {
                $map[$inn] = [
                    'type' => 'contact',
                    'id' => (int)$c['ID'],
                    'name' => $contactName($c),
                ];
            }
        }

        // Contacts: passport (only missing INNs)
        $missing = array_values(array_diff($inns, array_keys($map)));
        if (!empty($missing) && BANK_C_PASSPORT !== '') {
            $contacts = bankBogGetContactsByFilter(
                [BANK_C_PASSPORT => $missing],
                ['ID', 'NAME', 'LAST_NAME', 'FULL_NAME', BANK_C_PASSPORT]
            );
            foreach ($contacts as $c) {
                $inn = bankBogUfValue($c[BANK_C_PASSPORT] ?? '');
                if ($inn !== '' && !isset($map[$inn])) {
                    $map[$inn] = [
                        'type' => 'contact',
                        'id' => (int)$c['ID'],
                        'name' => $contactName($c),
                    ];
                }
            }
        }

        // Companies: tax ID
        $missing = array_values(array_diff($inns, array_keys($map)));
        if (!empty($missing) && BANK_CO_TAX_ID !== '') {
            $companies = bankBogGetCompaniesByFilter(
                [BANK_CO_TAX_ID => $missing],
                ['ID', 'TITLE', BANK_CO_TAX_ID]
            );
            foreach ($companies as $co) {
                $inn = bankBogUfValue($co[BANK_CO_TAX_ID] ?? '');
                if ($inn !== '' && !isset($map[$inn])) {
                    $map[$inn] = [
                        'type' => 'company',
                        'id' => (int)$co['ID'],
                        'name' => trim((string)($co['TITLE'] ?? '')),
                    ];
                }
            }
        }

        return $map;
    }
}

if (!function_exists('bankBogDealSelectFields')) {
    function bankBogDealSelectFields()
    {
        return [
            'ID', 'TITLE', 'OPPORTUNITY', 'CURRENCY_ID', 'STAGE_ID', 'CONTACT_ID', 'COMPANY_ID',
            BANK_D_PROJECT, BANK_D_BLOCK, BANK_D_TYPE, BANK_D_FLOOR, BANK_D_UNIT,
            BANK_D_CONTRACT_DATE, BANK_D_CURRENCY, BANK_D_CONTRACT_NUM,
            'CONTACT_FULL_NAME', 'COMPANY_TITLE',
        ];
    }
}

/**
 * Load deals for contact/company IDs on merge stages. Returns deals keyed by ID.
 */
if (!function_exists('bankBogLoadMergeDealsForEntities')) {
    function bankBogLoadMergeDealsForEntities(array $contactIds, array $companyIds)
    {
        $stages = bankBogMergeStages();
        $select = bankBogDealSelectFields();
        $byId = [];

        $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));

        if (!empty($contactIds)) {
            foreach (bankBogGetDealsByFilter(
                ['CONTACT_ID' => $contactIds, 'STAGE_ID' => $stages],
                $select
            ) as $deal) {
                $byId[(int)$deal['ID']] = $deal;
            }
        }
        if (!empty($companyIds)) {
            foreach (bankBogGetDealsByFilter(
                ['COMPANY_ID' => $companyIds, 'STAGE_ID' => $stages],
                $select
            ) as $deal) {
                $byId[(int)$deal['ID']] = $deal;
            }
        }

        return $byId;
    }
}

if (!function_exists('bankBogIsRefund')) {
    function bankBogIsRefund($value)
    {
        if ($value === null || $value === '' || $value === false) {
            return false;
        }
        if (is_array($value)) {
            $value = $value['VALUE'] ?? ($value[0] ?? '');
        }

        $v = mb_strtoupper(trim((string)$value), 'UTF-8');
        return in_array($v, ['YES', 'Y', '1', 'კი', 'TRUE'], true);
    }
}

/**
 * Batch load schedule + payments for deal IDs → left-to-pay map ($-ში, დღემდე).
 */
if (!function_exists('bankBogComputeLeftToPayMap')) {
    function bankBogComputeLeftToPayMap(array $dealIds)
    {
        $dealIds = array_values(array_unique(array_filter(array_map('intval', $dealIds))));
        $result = [];
        foreach ($dealIds as $id) {
            $result[$id] = 0.0;
        }
        if (empty($dealIds)) {
            return $result;
        }

        $financeByDeal = [];
        foreach ($dealIds as $id) {
            $financeByDeal[$id] = [];
        }

        // გრაფიკი (ლისტი 22)
        foreach ($dealIds as $dealId) {
            foreach (bankBogGetElements([
                'IBLOCK_ID' => BANK_BOG_SCHEDULE_IBLOCK,
                'PROPERTY_DEAL' => $dealId,
            ], null, ['ID' => 'ASC']) as $row) {
                $financeByDeal[$dealId][] = [
                    'DATE' => $row['TARIGI'] ?? '',
                    'AMOUNT' => bankBogParseAmount($row['TANXA'] ?? 0),
                    'TYPE' => 'PLAN',
                ];
            }
        }

        // გადახდები (ლისტი 23)
        foreach ($dealIds as $dealId) {
            foreach (bankBogGetElements([
                'IBLOCK_ID' => BANK_BOG_PAYMENT_IBLOCK,
                'PROPERTY_DEAL' => $dealId,
            ], null, ['ID' => 'ASC']) as $row) {
                $amount = bankBogParseAmount($row['TANXA'] ?? 0);
                if (bankBogIsRefund($row['refund'] ?? '')) {
                    $amount = -abs($amount);
                }
                $financeByDeal[$dealId][] = [
                    'DATE' => $row['date'] ?? '',
                    'AMOUNT' => $amount,
                    'TYPE' => 'PAYMENT',
                ];
            }
        }

        $today = new DateTime('today');
        foreach ($financeByDeal as $dealId => $rows) {
            usort($rows, function ($a, $b) {
                $da = bankBogParseDate($a['DATE']) ?: new DateTime('1970-01-01');
                $db = bankBogParseDate($b['DATE']) ?: new DateTime('1970-01-01');
                return $da <=> $db;
            });

            $left = 0.0;
            $lastPast = null;
            foreach ($rows as $row) {
                $dt = bankBogParseDate($row['DATE']);
                if ($row['TYPE'] === 'PLAN') {
                    $left = bankBogMoney($left + $row['AMOUNT']);
                } else {
                    $left = bankBogMoney($left - $row['AMOUNT']);
                }
                if ($dt && $dt <= $today) {
                    $lastPast = $left;
                }
            }
            $result[$dealId] = $lastPast !== null ? $lastPast : $left;
        }

        return $result;
    }
}

/**
 * თუ ამონაწერზე NBG_RATE ცარიელია — იღებს კურსს EntryDate-ით და წერს ლისტში.
 * ასევე ავსებს AMOUNT_USD / AMOUNT_GEL თუ ცარიელია.
 *
 * @param array $list statement row (by ref)
 * @return float NBG rate (0 თუ ვერ მოიძებნა)
 */
if (!function_exists('bankBogEnsureStatementNbgRate')) {
    function bankBogEnsureStatementNbgRate(array &$list)
    {
        $nbg = floatval($list['NBG_RATE'] ?? 0);
        $elementId = (int)($list['ID'] ?? 0);
        $needsPersist = false;

        if ($nbg <= 0) {
            $entryDate = explode('T', (string)($list['EntryDate'] ?? ''))[0];
            if ($entryDate === '') {
                $entryDate = date('Y-m-d');
            }
            $err = null;
            $fetched = bankBogGetNbgRate($entryDate, $err);
            if ($fetched !== null && $fetched > 0) {
                $nbg = $fetched;
                $list['NBG_RATE'] = $nbg;
                $needsPersist = true;
            }
        }

        $missingUsd = empty($list['AMOUNT_USD']) || floatval($list['AMOUNT_USD']) <= 0;
        $missingGel = empty($list['AMOUNT_GEL']) || floatval($list['AMOUNT_GEL']) <= 0;

        if ($nbg > 0 && ($missingUsd || $missingGel)) {
            $amounts = bankBogConvertEntryAmount(
                $list['ACCOUNT_CURRENCY'] ?? 'GEL',
                $list['EntryAmount'] ?? 0,
                $list['EntryAmountBase'] ?? 0,
                $nbg,
                explode('T', (string)($list['EntryDate'] ?? ''))[0]
            );
            if ($missingUsd) {
                $list['AMOUNT_USD'] = $amounts['USD'];
            }
            if ($missingGel) {
                $list['AMOUNT_GEL'] = $amounts['GEL'];
            }
            $needsPersist = true;
        }

        if ($needsPersist && $elementId > 0 && bankBogStatementIblockId() > 0) {
            $props = ['NBG_RATE' => $nbg];
            if (isset($list['AMOUNT_USD'])) {
                $props['AMOUNT_USD'] = $list['AMOUNT_USD'];
            }
            if (isset($list['AMOUNT_GEL'])) {
                $props['AMOUNT_GEL'] = $list['AMOUNT_GEL'];
            }
            $el = new CIBlockElement();
            $el->SetPropertyValuesEx($elementId, bankBogStatementIblockId(), $props);
        }

        return $nbg;
    }
}

if (!function_exists('bankBogStatementAmounts')) {
    function bankBogStatementAmounts(array &$list)
    {
        $nbg = bankBogEnsureStatementNbgRate($list);
        $currency = strtoupper(trim((string)($list['ACCOUNT_CURRENCY'] ?? 'GEL')));
        $gel = bankBogMoney($list['AMOUNT_GEL'] ?? 0);
        $usd = bankBogMoney($list['AMOUNT_USD'] ?? 0);

        // ანგარიშის ვალუტაში მოსული თანხა ზუსტად ბანკისაა
        if ($currency === 'GEL') {
            $gel = bankBogMoney($list['EntryAmount'] ?? 0);
        } elseif ($currency === 'USD') {
            $usd = bankBogMoney($list['EntryAmount'] ?? 0);
        }

        return [
            'CURRENCY' => $currency,
            'AMOUNT' => bankBogMoney($list['EntryAmount'] ?? 0),
            'GEL' => $gel,
            'USD' => $usd,
            'NBG' => $nbg,
        ];
    }
}

if (!function_exists('bankBogLoadMergedPaymentIds')) {
    function bankBogLoadMergedPaymentIds()
    {
        $set = [];
        $res = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => BANK_BOG_PAYMENT_IBLOCK, '!PROPERTY_BANK_PAYMENT_ID' => false, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'PROPERTY_BANK_PAYMENT_ID']
        );
        while ($row = $res->Fetch()) {
            $pid = trim((string)($row['PROPERTY_BANK_PAYMENT_ID_VALUE'] ?? ''));
            if ($pid !== '') {
                $set[$pid] = true;
            }
        }
        return $set;
    }
}

/**
 * Build matchable + unmatched + skipped statement models for merge UI.
 * Returns [$matched, $errors, $skipped]
 */
if (!function_exists('bankBogBuildMergeModels')) {
    function bankBogBuildMergeModels()
    {
        $iblockId = bankBogStatementIblockId();
        if ($iblockId <= 0) {
            return [[], [], []];
        }

        $mergedIds = bankBogLoadMergedPaymentIds();
        $statements = bankBogGetElements(
            ['IBLOCK_ID' => $iblockId],
            null,
            ['ID' => 'DESC']
        );

        $pending = [];
        $inns = [];
        $skipped = [];

        foreach ($statements as $list) {
            $id = (string)$list['ID'];

            // უკვე მიბმული — საერთოდ არ ვაჩვენებთ (არც მერჯზე, არც გამოტოვებულებში)
            if (isset($mergedIds[$id])) {
                continue;
            }

            $reason = trim((string)($list['REASON'] ?? ''));
            if ($reason !== '') {
                $amounts = bankBogStatementAmounts($list);
                $date = explode('T', (string)($list['EntryDate'] ?? ''))[0];
                $name = trim((string)($list['SenderDetails_Name'] ?? ($list['DocumentPayerName'] ?? '')));
                $skipped[] = [
                    'list_id' => $list['ID'],
                    'NAME' => $name,
                    'INN' => trim((string)($list['DocumentPayerInn'] ?? ($list['SenderDetails_Inn'] ?? ''))),
                    'DATE' => $date,
                    'NOMINATION' => $list['DocumentNomination'] ?? '',
                    'BENEFICIARY' => $list['BeneficiaryDetails_Name'] ?? '',
                    'PROJECT' => $list['PROJECT'] ?? '',
                    'ACCOUNT' => $list['ACCOUNT_NUMBER'] ?? '',
                    'CURRENCY' => $amounts['CURRENCY'],
                    'AMOUNT' => $amounts['AMOUNT'],
                    'AMOUNT_GEL' => $amounts['GEL'],
                    'AMOUNT_USD' => $amounts['USD'],
                    'COMMENT' => $list['EntryComment'] ?? '',
                    'REASON' => $reason,
                    'REASON_CODE' => 'stored_reason',
                ];
                continue;
            }

            $senderInn = trim((string)($list['SenderDetails_Inn'] ?? ''));
            $inn = trim((string)($list['DocumentPayerInn'] ?? ''));
            if ($inn === '') {
                $inn = $senderInn;
            }
            $list['_RESOLVED_INN'] = $inn;
            $pending[] = $list;
            if ($inn !== '') {
                $inns[] = $inn;
            }
        }

        $payers = bankBogResolvePayersByInn($inns);
        $contactIds = [];
        $companyIds = [];
        foreach ($payers as $p) {
            if ($p['type'] === 'contact') {
                $contactIds[] = $p['id'];
            } else {
                $companyIds[] = $p['id'];
            }
        }

        $dealsById = bankBogLoadMergeDealsForEntities($contactIds, $companyIds);

        $dealsByContact = [];
        $dealsByCompany = [];
        foreach ($dealsById as $deal) {
            $cid = (int)($deal['CONTACT_ID'] ?? 0);
            $coid = (int)($deal['COMPANY_ID'] ?? 0);
            if ($cid > 0) {
                $dealsByContact[$cid][] = (int)$deal['ID'];
            }
            if ($coid > 0) {
                $dealsByCompany[$coid][] = (int)$deal['ID'];
            }
        }

        $allDealIds = array_keys($dealsById);
        $leftMap = bankBogComputeLeftToPayMap($allDealIds);

        $matched = [];
        $errors = [];

        foreach ($pending as $list) {
            $amounts = bankBogStatementAmounts($list);
            $inn = $list['_RESOLVED_INN'];
            $date = explode('T', (string)($list['EntryDate'] ?? ''))[0];
            $name = trim((string)($list['SenderDetails_Name'] ?? ($list['DocumentPayerName'] ?? '')));
            $base = [
                'INN' => $inn,
                'NAME' => $name,
                'AMOUNT_GEL' => $amounts['GEL'],
                'AMOUNT_USD' => $amounts['USD'],
                'BANK_AMOUNT_GEL' => $amounts['GEL'],
                'BANK_AMOUNT_USD' => $amounts['USD'],
                'NBG_RATE' => $amounts['NBG'],
                'CURRENCY' => $amounts['CURRENCY'],
                'AMOUNT' => $amounts['AMOUNT'],
                'NOMINATION' => $list['DocumentNomination'] ?? '',
                'BENEFICIARY' => $list['BeneficiaryDetails_Name'] ?? '',
                'PROJECT' => $list['PROJECT'] ?? '',
                'ACCOUNT' => $list['ACCOUNT_NUMBER'] ?? '',
                'DATE' => $date,
                'list_id' => $list['ID'],
                'PAYMENT' => $list['ID'],
                'COMMENT' => $list['EntryComment'] ?? '',
            ];

            if ($inn === '' || !isset($payers[$inn])) {
                $errors[] = $base;
                continue;
            }

            $payer = $payers[$inn];
            $dealIds = [];
            if ($payer['type'] === 'contact') {
                $dealIds = $dealsByContact[$payer['id']] ?? [];
            } else {
                $dealIds = $dealsByCompany[$payer['id']] ?? [];
            }

            $modeled = [];
            foreach ($dealIds as $dealId) {
                $deal = $dealsById[$dealId] ?? null;
                if (!$deal) {
                    continue;
                }
                $clientName = trim((string)($deal['CONTACT_FULL_NAME'] ?? ''));
                if ($clientName === '') {
                    $clientName = trim((string)($deal['COMPANY_TITLE'] ?? $payer['name']));
                }
                $modeled[] = [
                    'ID' => (int)$deal['ID'],
                    'NAME' => $deal['TITLE'] ?? '',
                    'OPPORTUNITY' => $deal['OPPORTUNITY'] ?? 0,
                    'PROJECT' => bankBogUfValue($deal[BANK_D_PROJECT] ?? ''),
                    'STAGE_ID' => $deal['STAGE_ID'] ?? '',
                    'LEFT_TO_PAY' => $leftMap[$dealId] ?? 0,
                    'CLIENT_NAME' => $clientName,
                    'UNIT' => bankBogUfValue($deal[BANK_D_UNIT] ?? ''),
                    'BLOCK' => bankBogUfValue($deal[BANK_D_BLOCK] ?? ''),
                    'TYPE' => bankBogUfValue($deal[BANK_D_TYPE] ?? ''),
                ];
            }

            if (!empty($modeled)) {
                $matched[] = array_merge($base, [
                    'CLIENT_NAME' => $payer['name'] ?: $name,
                    'CLIENT_ID' => $payer['id'],
                    'STATUS' => $payer['type'],
                    'MERGE_DEALS' => $modeled,
                ]);
            } else {
                $errors[] = array_merge($base, [
                    'CLIENT_ID' => $payer['id'],
                    'STATUS' => $payer['type'],
                ]);
            }
        }

        return [$matched, $errors, $skipped];
    }
}

if (!function_exists('bankBogLoadDealForPayment')) {
    function bankBogLoadDealForPayment($dealId)
    {
        $dealId = (int)$dealId;
        if ($dealId <= 0) {
            return null;
        }
        $rows = bankBogGetDealsByFilter(['ID' => $dealId], bankBogDealSelectFields());
        return $rows[0] ?? null;
    }
}

/** merge სტეიჯების ქართული დასახელებები UI-სთვის. */
if (!function_exists('bankBogMergeStageLabels')) {
    function bankBogMergeStageLabels()
    {
        $names = class_exists('CCrmStatus') ? CCrmStatus::GetStatusList('DEAL_STAGE') : [];
        $labels = [];
        foreach (bankBogMergeStages() as $stage) {
            $labels[] = trim((string)($names[$stage] ?? '')) ?: $stage;
        }
        return $labels;
    }
}

if (!function_exists('bankBogIsActiveMergeStage')) {
    function bankBogIsActiveMergeStage($stageId)
    {
        return in_array((string)$stageId, bankBogMergeStages(), true);
    }
}

/**
 * Create payment element from merge POST row.
 */
if (!function_exists('bankBogCreatePaymentFromMerge')) {
    function bankBogCreatePaymentFromMerge($dealId, $paymentId, $valueUsd)
    {
        $dealId = (int)$dealId;
        $paymentId = (int)$paymentId;
        $valueUsd = bankBogMoney($valueUsd);

        if ($dealId <= 0 || $paymentId <= 0 || $valueUsd == 0.0) {
            return ['ok' => false, 'error' => 'invalid_input'];
        }

        $statementIblock = bankBogStatementIblockId();
        if ($statementIblock <= 0) {
            return ['ok' => false, 'error' => 'statement_iblock_missing'];
        }

        $dup = bankBogGetElements([
            'IBLOCK_ID' => BANK_BOG_PAYMENT_IBLOCK,
            'PROPERTY_DEAL_ID' => $dealId,
            'PROPERTY_BANK_PAYMENT_ID' => $paymentId,
        ], ['ID'], ['ID' => 'DESC'], 1);
        if (!empty($dup)) {
            return ['ok' => false, 'error' => 'already_merged'];
        }

        $listRows = bankBogGetElements([
            'IBLOCK_ID' => $statementIblock,
            'ID' => $paymentId,
        ], null, ['ID' => 'DESC'], 1);
        if (empty($listRows)) {
            return ['ok' => false, 'error' => 'statement_not_found'];
        }
        $list = $listRows[0];

        $deal = bankBogLoadDealForPayment($dealId);
        if (!$deal) {
            return ['ok' => false, 'error' => 'deal_not_found'];
        }
        if (!bankBogIsActiveMergeStage($deal['STAGE_ID'] ?? '')) {
            return ['ok' => false, 'error' => 'inactive_stage', 'stage' => $deal['STAGE_ID'] ?? ''];
        }

        $amounts = bankBogStatementAmounts($list);
        $nbg = floatval($amounts['NBG']);
        if ($nbg <= 0) {
            $nbg = floatval($list['NBG_RATE'] ?? 0);
        }

        // Prefer exact GEL from statement (GEL / EUR) when USD matches statement USD
        if ($amounts['CURRENCY'] !== 'USD' && abs($valueUsd - floatval($amounts['USD'])) < 0.02) {
            $tanxaGel = bankBogMoney($amounts['GEL']);
        } elseif ($nbg > 0) {
            $tanxaGel = bankBogMoney($valueUsd * $nbg);
        } else {
            $tanxaGel = bankBogMoney($amounts['GEL']);
        }

        $entryDate = explode('T', (string)($list['EntryDate'] ?? ''))[0];
        $formTarigi = bankBogFormatDateDmy($entryDate);
        if ($formTarigi === '') {
            $formTarigi = date('d/m/Y');
        }

        $fullName = trim((string)($deal['CONTACT_FULL_NAME'] ?? ''));
        if ($fullName === '') {
            $fullName = trim((string)($deal['COMPANY_TITLE'] ?? ($list['SenderDetails_Name'] ?? '')));
        }

        $props = [
            'DEAL' => $dealId,
            'DEAL_ID' => $dealId,
            'date' => $formTarigi,
            'comment' => $list['EntryComment'] ?? '',
            'TANXA' => $valueUsd . '|USD',
            'tanxa_gel' => (string)$tanxaGel,
            'BANK_PAYMENT_ID' => (string)$paymentId,
            'PROJECT' => bankBogUfValue($deal[BANK_D_PROJECT] ?? ''),
            'KORPUSI' => bankBogUfValue($deal[BANK_D_BLOCK] ?? ''),
            'BINIS_NOMERI' => bankBogUfValue($deal[BANK_D_UNIT] ?? ''),
            'ZETIPI' => bankBogUfValue($deal[BANK_D_TYPE] ?? ''),
            'floor' => bankBogUfValue($deal[BANK_D_FLOOR] ?? ''),
            'KONTRAKT_DATE' => bankBogResolveContractDate($deal),
            // დანარჩენი გადახდების მსგავსად დილის ვალუტა იწერება; ბანკის ვალუტა ამონაწერშია
            'CURRENCY' => trim((string)($deal['CURRENCY_ID'] ?? '')) ?: bankBogDealCurrencyLabel($deal),
            'NBG' => (string)$nbg,
            'FULL_NAME' => $fullName,
            'xelshNum' => bankBogUfValue($deal[BANK_D_CONTRACT_NUM] ?? ''),
            'pay_type' => BANK_BOG_PAY_TYPE_BOG,
        ];

        $res = bankBogAddElement([
            'IBLOCK_ID' => BANK_BOG_PAYMENT_IBLOCK,
            'NAME' => trim(($list['SenderDetails_Name'] ?? 'Payment') . ' ' . ($list['EntryDate'] ?? '')),
            'ACTIVE' => 'Y',
        ], $props);

        if (!is_numeric($res) || (int)$res <= 0) {
            return ['ok' => false, 'error' => (string)$res];
        }

        return ['ok' => true, 'payment_id' => (int)$res];
    }
}

if (!function_exists('bankBogProcessMergePost')) {
    function bankBogProcessMergePost(array $post)
    {
        $results = ['saved' => 0, 'errors' => []];
        $dealKeys = array_filter(array_keys($post), function ($key) {
            return strpos($key, 'DEAL_') === 0;
        });

        foreach ($dealKeys as $dealKey) {
            $index = str_replace('DEAL_', '', $dealKey);
            $dealId = bankBogNormalizeDealId($post['DEAL_' . $index] ?? '');
            $value = isset($post['VALUE_' . $index]) ? trim((string)$post['VALUE_' . $index]) : '';
            $payment = isset($post['PAYMENT_' . $index]) ? trim((string)$post['PAYMENT_' . $index]) : '';

            if ($value === '' || $value === '0' || $dealId === '' || $payment === '') {
                continue;
            }

            $out = bankBogCreatePaymentFromMerge($dealId, $payment, $value);
            if (!empty($out['ok'])) {
                $results['saved']++;
            } else {
                $results['errors'][] = [
                    'deal' => $dealId,
                    'payment' => $payment,
                    'error' => $out['error'] ?? 'unknown',
                ];
            }
        }

        return $results;
    }
}
