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

/** სალაროს ექსელიდან ატვირთული ჩანაწერია (და არა ბანკის ამონაწერი)? */
if (!function_exists('bankBogIsCashStatement')) {
    function bankBogIsCashStatement(array $list)
    {
        return trim((string)($list['ACCOUNT_NUMBER'] ?? '')) === BANK_BOG_CASH_ACCOUNT;
    }
}

/**
 * სალაროს ექსელის თარიღი → Y-m-d; ცარიელი, თუ ვერ წაიკითხა.
 * ტექსტი დღე/თვე/წელი ფორმატშია (1/10/2026 = 1 ოქტომბერი), თარიღის უჯრას SimpleXLSX Y-m-d-ით აბრუნებს.
 */
if (!function_exists('bankBogCashParseDate')) {
    function bankBogCashParseDate($value)
    {
        $text = trim((string)$value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
            list(, $y, $mo, $d) = $m;
        } elseif (preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})$/', $text, $m)) {
            list(, $d, $mo, $y) = $m;
        } elseif (is_numeric($text) && $text > 20000 && $text < 80000) {
            return gmdate('Y-m-d', (int)round(((float)$text - 25569) * 86400));
        } else {
            return '';
        }
        return checkdate((int)$mo, (int)$d, (int)$y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }
}

/**
 * "ლევან ეგრისელაშვილი 01019069506", "ნათია გაგუა პ/ნ 29001006514", "ფათემი მეჰრან P492170DP"
 * → [სახელი, პირადი/პასპორტის ნომერი]. ნომრის გარეშე ნომერი ცარიელია.
 */
if (!function_exists('bankBogCashSplitPayer')) {
    function bankBogCashSplitPayer($text)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        if (preg_match('/^(.*?)[\s,]*(?:პ\/ნ\s*)?([A-Za-z]*\d[\dA-Za-z ]*)$/u', $text, $m) && trim($m[1]) !== '') {
            return [trim($m[1]), strtoupper(str_replace(' ', '', $m[2]))];
        }
        return [$text, ''];
    }
}

/**
 * სალაროს ექსელის სტრიქონები. სვეტები სათაურებით იძებნება:
 * თარიღი, N, გადამხდელი, მიმღები, თანხა, ვალუტა, საფუძველი.
 *
 * @return array|null null = ფაილი ან სვეტები ვერ წაიკითხა ($error)
 */
if (!function_exists('bankBogCashReadExcel')) {
    function bankBogCashReadExcel($filePath, &$error = null)
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/custom/simplexlsx/src/SimpleXLSX.php';
        $xlsx = \Shuchkin\SimpleXLSX::parse($filePath);
        if (!$xlsx) {
            $error = 'ფაილი ვერ წაიკითხა: ' . \Shuchkin\SimpleXLSX::parseError();
            return null;
        }

        $headerMatchers = [
            'date' => function ($h) { return mb_strpos($h, 'თარიღ') !== false; },
            'n' => function ($h) { return in_array($h, ['n', '#', '№', 'n°'], true); },
            'payer' => function ($h) { return mb_strpos($h, 'გადამხდ') !== false; },
            'recipient' => function ($h) { return mb_strpos($h, 'მიმღებ') !== false; },
            'amount' => function ($h) { return mb_strpos($h, 'თანხ') !== false; },
            'currency' => function ($h) { return mb_strpos($h, 'ვალუტ') !== false; },
            'basis' => function ($h) { return mb_strpos($h, 'საფუძ') !== false || mb_strpos($h, 'დანიშნ') !== false; },
        ];

        $cols = null;
        $out = [];
        foreach ($xlsx->rows() as $i => $cells) {
            if ($cols === null) {
                // სათაურის სტრიქონი: პირველი, სადაც თარიღი, გადამხდელი და თანხა ერთად წერია
                $found = [];
                foreach ($cells as $idx => $cell) {
                    $h = mb_strtolower(trim((string)$cell));
                    foreach ($headerMatchers as $key => $match) {
                        if (!isset($found[$key]) && $h !== '' && $match($h)) {
                            $found[$key] = $idx;
                            break;
                        }
                    }
                }
                if (isset($found['date'], $found['payer'], $found['amount'])) {
                    $cols = $found;
                }
                continue;
            }

            $get = function ($key) use ($cols, $cells) {
                return isset($cols[$key]) ? trim((string)($cells[$cols[$key]] ?? '')) : '';
            };
            if ($get('date') === '' && $get('payer') === '' && $get('amount') === '') {
                continue;
            }
            list($name, $personalId) = bankBogCashSplitPayer($get('payer'));
            $out[] = [
                'ROW' => $i + 1,
                'DATE_RAW' => $get('date'),
                'DATE' => bankBogCashParseDate($get('date')),
                'N' => $get('n'),
                'NAME' => $name,
                'PERSONAL_ID' => $personalId,
                'RECIPIENT' => $get('recipient'),
                'AMOUNT' => bankBogMoney(bankBogParseAmount($get('amount'))),
                'CURRENCY' => strtoupper($get('currency')) ?: 'GEL',
                'BASIS' => trim(preg_replace('/\s+/u', ' ', $get('basis'))),
            ];
        }

        if ($cols === null) {
            $error = 'სათაურები ვერ მოიძებნა: საჭიროა სვეტები "თარიღი", "გადამხდელი" და "თანხა"';
            return null;
        }
        return $out;
    }
}

/**
 * სალაროს ექსელის სტრიქონები ამონაწერების სიაში (ACCOUNT_NUMBER = სალარო), რომ მიბმის გვერდზე
 * ბანკის ჩანაწერებთან ერთად გამოჩნდეს. განმეორებით ატვირთვისას იგივე სტრიქონი (თარიღი, N, თანხა,
 * პირადი ნომერი) EntryId-ით გამოტოვდება; 0 თანხა არ იწერება.
 *
 * @return array{created:int,skipped_dup:int,skipped:array,errors:array}
 */
if (!function_exists('bankBogImportCashRows')) {
    function bankBogImportCashRows(array $rows, $project)
    {
        $stats = ['created' => 0, 'skipped_dup' => 0, 'skipped' => [], 'errors' => []];
        $iblockId = bankBogStatementIblockId();
        $existingIds = bankBogLoadExistingKeys();

        foreach ($rows as $row) {
            if ($row['DATE'] === '') {
                $stats['errors'][] = $row + ['REASON' => 'თარიღი ვერ წაიკითხა: ' . $row['DATE_RAW']];
                continue;
            }
            if ($row['AMOUNT'] <= 0) {
                $stats['skipped'][] = $row + ['REASON' => 'თანხა 0'];
                continue;
            }
            if (!in_array($row['CURRENCY'], ['GEL', 'USD', 'EUR'], true)) {
                $stats['errors'][] = $row + ['REASON' => 'უცნობი ვალუტა: ' . $row['CURRENCY']];
                continue;
            }

            $entryId = 'CASH-' . $row['DATE'] . '-' . $row['N'] . '-' . number_format($row['AMOUNT'], 2, '.', '') . '-' . $row['PERSONAL_ID'];
            if (isset($existingIds[$entryId])) {
                $stats['skipped_dup']++;
                continue;
            }

            $nbg = bankBogGetNbgRate($row['DATE'], $nbgErr);
            if ($nbg === null) {
                $stats['errors'][] = $row + ['REASON' => $nbgErr];
                continue;
            }
            $amounts = bankBogConvertEntryAmount($row['CURRENCY'], $row['AMOUNT'], 0, $nbg, $row['DATE']);

            $props = [
                'EntryDate' => $row['DATE'] . 'T00:00:00',
                'EntryDocumentNumber' => $row['N'],
                'EntryAmount' => $row['AMOUNT'],
                'EntryAmountCredit' => $row['AMOUNT'],
                // გადახდის კომენტარში გადადის: სალაროს ორდერის ნომერი + საფუძველი
                'EntryComment' => trim('სალარო' . ($row['N'] !== '' ? ' N' . $row['N'] : '') . ': ' . $row['BASIS']),
                'DocumentNomination' => $row['BASIS'],
                'SenderDetails_Name' => $row['NAME'],
                'SenderDetails_Inn' => $row['PERSONAL_ID'],
                'DocumentPayerInn' => $row['PERSONAL_ID'],
                'DocumentPayerName' => $row['NAME'],
                'BeneficiaryDetails_Name' => $row['RECIPIENT'],
                'EntryId' => $entryId,
                'istodayactivity' => 'false',
                'ACCOUNT_CURRENCY' => $row['CURRENCY'],
                'ACCOUNT_NUMBER' => BANK_BOG_CASH_ACCOUNT,
                'NBG_RATE' => $nbg,
                'AMOUNT_GEL' => $amounts['GEL'],
                'AMOUNT_USD' => $amounts['USD'],
                'SALE_TYPE' => 'CASH',
                'PROJECT' => $project,
                'REASON' => '',
            ];

            $res = bankBogAddElement([
                'IBLOCK_ID' => $iblockId,
                'NAME' => 'სალარო',
                'ACTIVE' => 'Y',
            ], $props);

            if (is_numeric($res) && (int)$res > 0) {
                $existingIds[$entryId] = true;
                $stats['created']++;
            } else {
                $stats['errors'][] = $row + ['REASON' => (string)$res];
            }
        }

        return $stats;
    }
}

/**
 * სალაროს საფუძვლიდან ფართის ნომერი: "... ბლოკი ბ ბინა 43   11675 დოლ ექ ლარში" →
 * ['UNIT' => '43', 'TYPE' => 'ბინა', 'BLOCK' => 'ბ']. ნომერია "ბინა/ბ./პარკ/სად/#" + რიცხვი, რომელსაც
 * უშუალოდ დოლარის თანხა მოსდევს ("ბინა 173 900 დოლ" - 173 ბინაა, 900 თანხა); ტიპი ცარიელია,
 * თუ სიტყვიდან არ ჩანს. null = ვერ ამოიცნო.
 */
if (!function_exists('bankBogCashUnitHint')) {
    function bankBogCashUnitHint($text)
    {
        $pattern = '/(?<!\p{L})(ბინა|ბპარკ|პარკ|პარტ|პარ|პ|სადგ|სად|ბ|#)[\s.]*(\d{1,4})(?!\d)[\s.;,]+\d[\d ]*(?:[.,]\d+)?\s*(?:დოლ|აშშ)/u';
        if (!preg_match_all($pattern, (string)$text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $last = end($all);
        $prefix = substr((string)$text, 0, $last[0][1]);
        $types = ['ბინა' => 'ბინა', 'ბ' => 'ბინა', '#' => ''];
        $block = preg_match('/ბლოკი\s*([აბგდ])(?!\p{L})/u', $prefix, $bm) ? $bm[1] : '';

        return [
            'UNIT' => ltrim($last[2][0], '0') ?: '0',
            'TYPE' => $types[$last[1][0]] ?? 'ავტოსადგომი',
            'BLOCK' => $block,
        ];
    }
}

/** ორ სახელში ერთი სიტყვა მაინც ემთხვევა (მაგ. გვარი)? სიტყვების რიგს მნიშვნელობა არ აქვს. */
if (!function_exists('bankBogNamesOverlap')) {
    function bankBogNamesOverlap($a, $b)
    {
        $words = function ($name) {
            return array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower((string)$name), -1, PREG_SPLIT_NO_EMPTY), function ($w) {
                return mb_strlen($w) >= 3;
            });
        };
        return (bool)array_intersect($words($a), $words($b));
    }
}

/** სტატემენტის პროექტი (New Depot) და დილის პროექტი (New Depo) ერთია? */
if (!function_exists('bankBogProjectMatches')) {
    function bankBogProjectMatches($statementProject, $dealProject)
    {
        $a = mb_strtolower(trim((string)$statementProject));
        $b = mb_strtolower(trim((string)$dealProject));
        return $a !== '' && $b !== '' && (strpos($a, $b) === 0 || strpos($b, $a) === 0);
    }
}

/**
 * დილი საფუძვლის ფართის ნომრით. $strict = false: ჯერ ნომერი, ტიპი და ბლოკი მხოლოდ მაშინ
 * ფილტრავს, როცა რამდენიმე დილი რჩება (გადამხდელის დილებში ძებნა). $strict = true: ყველა
 * ცნობილი ნიშანი ერთად (კონტაქტის გარეშე ძებნა პროექტის ყველა დილში).
 *
 * @return int დილის ID; 0, თუ ზუსტად ერთი არ დარჩა
 */
if (!function_exists('bankBogPickDealByUnitHint')) {
    function bankBogPickDealByUnitHint(array $deals, array $hint, $strict = false)
    {
        $digits = function ($unit) {
            return ltrim(preg_replace('/\D+/', '', bankBogUfValue($unit)), '0') ?: '0';
        };
        $deals = array_values(array_filter($deals, function ($d) use ($hint, $digits) {
            return bankBogUfValue($d[BANK_D_UNIT] ?? '') !== '' && $digits($d[BANK_D_UNIT]) === $hint['UNIT'];
        }));
        $filters = [
            BANK_D_TYPE => $hint['TYPE'],
            BANK_D_BLOCK => $hint['BLOCK'],
        ];
        foreach ($filters as $field => $want) {
            if ($want === '' || (!$strict && count($deals) <= 1)) {
                continue;
            }
            $deals = array_values(array_filter($deals, function ($d) use ($field, $want) {
                return bankBogUfValue($d[$field] ?? '') === $want;
            }));
        }
        return count($deals) === 1 ? (int)$deals[0]['ID'] : 0;
    }
}

/**
 * სალაროს ჩანაწერი, რომლის გადამხდელიც CRM-ში ვერ მოიძებნა (მაგ. უცხოელის პასპორტი სხვაგვარადაა
 * ჩაწერილი): დილი იძებნება პროექტის, ბლოკის, ტიპისა და ფართის ნომრით. ერთი და იგივე ნომერი
 * სხვადასხვა სექციაში მეორდება, ამიტომ დილის კლიენტის სახელიც უნდა ემთხვეოდეს გადამხდელისას.
 * იწერება SUGGESTED-ში - მიბმის გვერდზე ჩანს შემოთავაზებად, ავტომატურად არ ივსება.
 */
if (!function_exists('bankBogSuggestDealsForUnmatchedCash')) {
    function bankBogSuggestDealsForUnmatchedCash(array &$errors)
    {
        $units = [];
        foreach ($errors as $row) {
            if (!empty($row['UNIT_HINT'])) {
                $units[] = (string)$row['UNIT_HINT']['UNIT'];
            }
        }
        if (empty($units)) {
            return;
        }

        $dealsById = [];
        foreach (bankBogGetDealsByFilter(
            [BANK_D_UNIT => array_values(array_unique($units)), 'STAGE_ID' => bankBogMergeStages()],
            bankBogDealSelectFields()
        ) as $deal) {
            $dealsById[(int)$deal['ID']] = $deal;
        }

        foreach ($errors as &$row) {
            if (empty($row['UNIT_HINT'])) {
                continue;
            }
            $project = $row['PROJECT'] ?? '';
            $payerName = $row['NAME'] ?? '';
            $candidates = array_filter($dealsById, function ($d) use ($project, $payerName) {
                $client = trim((string)($d['CONTACT_FULL_NAME'] ?? '')) ?: (string)($d['COMPANY_TITLE'] ?? '');
                return bankBogProjectMatches($project, bankBogUfValue($d[BANK_D_PROJECT] ?? ''))
                    && bankBogNamesOverlap($payerName, $client);
            });
            $dealId = bankBogPickDealByUnitHint($candidates, $row['UNIT_HINT'], true);
            if ($dealId > 0) {
                $deal = $dealsById[$dealId];
                $row['SUGGESTED'] = [
                    'ID' => $dealId,
                    'CLIENT_NAME' => trim((string)($deal['CONTACT_FULL_NAME'] ?? '')) ?: trim((string)($deal['COMPANY_TITLE'] ?? '')),
                    'PROJECT' => bankBogUfValue($deal[BANK_D_PROJECT] ?? ''),
                    'TYPE' => bankBogUfValue($deal[BANK_D_TYPE] ?? ''),
                    'BLOCK' => bankBogUfValue($deal[BANK_D_BLOCK] ?? ''),
                    'UNIT' => bankBogUfValue($deal[BANK_D_UNIT] ?? ''),
                ];
            }
        }
        unset($row);
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

/**
 * ბანკიდან შექმნილი გადახდები: ამონაწერის ID => [დილის ID => true].
 * ერთი ამონაწერი შეიძლება რამდენიმე დილზე იყოს გადანაწილებული.
 */
if (!function_exists('bankBogLoadMergedPaymentDeals')) {
    function bankBogLoadMergedPaymentDeals()
    {
        $map = [];
        $res = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => BANK_BOG_PAYMENT_IBLOCK, '!PROPERTY_BANK_PAYMENT_ID' => false, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', 'PROPERTY_BANK_PAYMENT_ID', 'PROPERTY_DEAL']
        );
        while ($row = $res->Fetch()) {
            $pid = trim((string)($row['PROPERTY_BANK_PAYMENT_ID_VALUE'] ?? ''));
            if ($pid === '') {
                continue;
            }
            if (!isset($map[$pid])) {
                $map[$pid] = [];
            }
            $dealId = bankBogNormalizeDealId($row['PROPERTY_DEAL_VALUE'] ?? '');
            if ($dealId !== '') {
                $map[$pid][$dealId] = true;
            }
        }

        // აბონირების გადახდებიც: ამონაწერი შეიძლება მთლიანად აბონირებაზე იყოს გატარებული
        $subIblock = function_exists('subscriptionFeePaymentIblockId') ? subscriptionFeePaymentIblockId() : 0;
        if ($subIblock > 0) {
            $res = CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $subIblock, '!PROPERTY_BANK_PAYMENT_ID' => false, 'CHECK_PERMISSIONS' => 'N'],
                false,
                false,
                ['ID', 'PROPERTY_BANK_PAYMENT_ID', 'PROPERTY_DEAL']
            );
            while ($row = $res->Fetch()) {
                $pid = trim((string)($row['PROPERTY_BANK_PAYMENT_ID_VALUE'] ?? ''));
                $dealId = bankBogNormalizeDealId($row['PROPERTY_DEAL_VALUE'] ?? '');
                if ($pid !== '' && $dealId !== '') {
                    $map[$pid][$dealId] = true;
                }
            }
        }
        return $map;
    }
}

/**
 * დანიშნულებაში / კომენტარში აბონირება წერია - ბანკის გვერდი აბონირებას წინასწარ შეავსებს.
 * კლიენტები ხშირად წერენ "მრიცხველების საფასური" / "გამრიცხველიანება" / "za schetchiki".
 */
if (!function_exists('bankBogMentionsSubscription')) {
    function bankBogMentionsSubscription($text)
    {
        // "meter" არა: "square meters" ფართის აღწერაშიც წერია
        return (bool)preg_match('/აბონ|მრიცხვ|abon|subscr|schetchik|сч[её]тчик/iu', (string)$text);
    }
}

/**
 * INN-ის ადგილას ჩაწერილი ნულები (მაგ. 00000000000 უცხოელებზე) - ასეთი INN ბევრ სხვადასხვა გადამხდელს აქვს.
 */
if (!function_exists('bankBogIsPlaceholderInn')) {
    function bankBogIsPlaceholderInn($inn)
    {
        return (bool)preg_match('/^(\d)\1*$/', trim((string)$inn));
    }
}

/** გადამხდელის INN ამონაწერიდან; ცარიელი, თუ არ წერია ან ნულებია. */
if (!function_exists('bankBogStatementPayerInn')) {
    function bankBogStatementPayerInn(array $list)
    {
        $inn = trim((string)($list['DocumentPayerInn'] ?? ''));
        if ($inn === '') {
            $inn = trim((string)($list['SenderDetails_Inn'] ?? ''));
        }
        return bankBogIsPlaceholderInn($inn) ? '' : $inn;
    }
}

if (!function_exists('bankBogStatementSenderAccount')) {
    function bankBogStatementSenderAccount(array $list)
    {
        return strtoupper(preg_replace('/\s+/', '', (string)($list['SenderDetails_AccountNumber'] ?? '')));
    }
}

/**
 * ანგარიშები, საიდანაც სხვადასხვა INN-ით შემოდის (მაგ. ბანკის სალაროს ტრანზიტული ანგარიში) -
 * ასეთი ანგარიში გადამხდელს ვერ ამოიცნობს.
 */
if (!function_exists('bankBogSharedSenderAccounts')) {
    function bankBogSharedSenderAccounts(array $statements)
    {
        $innsByAccount = [];
        foreach ($statements as $list) {
            $account = bankBogStatementSenderAccount($list);
            $inn = bankBogStatementPayerInn($list);
            if ($account !== '' && $inn !== '') {
                $innsByAccount[$account][$inn] = true;
            }
        }
        $shared = [];
        foreach ($innsByAccount as $account => $inns) {
            if (count($inns) > 1) {
                $shared[$account] = true;
            }
        }
        return $shared;
    }
}

/**
 * გადამხდელის გასაღები წინა მიბმების მოსაძებნად: INN, ის თუ ცარიელია - გამგზავნის ანგარიში
 * (საერთო ანგარიშის გარდა). სახელით არ ვეძებთ - სახელები ხშირად ემთხვევა.
 */
if (!function_exists('bankBogStatementPayerKey')) {
    function bankBogStatementPayerKey(array $list, array $sharedAccounts = [])
    {
        $inn = bankBogStatementPayerInn($list);
        if ($inn !== '') {
            return 'INN:' . $inn;
        }
        $account = bankBogStatementSenderAccount($list);
        return ($account !== '' && !isset($sharedAccounts[$account])) ? 'ACC:' . $account : '';
    }
}

/**
 * Build matchable + unmatched + skipped statement models for merge UI.
 * Returns [$matched, $errors, $skipped]
 *
 * დილები იძებნება გადამხდელის INN-ით და წინა მიბმებით: თუ ამ გადამხდელის ჩარიცხვა
 * ადრე რომელიმე დილზე მიება, ის დილიც შემოგვთავაზდება (RECOGNIZED, თუ INN-ით ვერ მოიძებნებოდა).
 */
if (!function_exists('bankBogBuildMergeModels')) {
    function bankBogBuildMergeModels()
    {
        $iblockId = bankBogStatementIblockId();
        if ($iblockId <= 0) {
            return [[], [], []];
        }

        $mergedDeals = bankBogLoadMergedPaymentDeals();
        $statements = bankBogGetElements(
            ['IBLOCK_ID' => $iblockId],
            null,
            ['ID' => 'DESC']
        );
        $sharedAccounts = bankBogSharedSenderAccounts($statements);

        $pending = [];
        $inns = [];
        $skipped = [];
        // გადამხდელის გასაღები => [დილის ID => ['COUNT' => მიბმული ამონაწერები, 'LAST' => Y-m-d]]
        $history = [];

        foreach ($statements as $list) {
            $id = (string)$list['ID'];

            // უკვე მიბმული - არ ვაჩვენებთ (არც მერჯზე, არც გამოტოვებულებში), მაგრამ ვიმახსოვრებთ, რომელ დილზე წავიდა
            if (isset($mergedDeals[$id])) {
                $payerKey = bankBogStatementPayerKey($list, $sharedAccounts);
                $date = explode('T', (string)($list['EntryDate'] ?? ''))[0];
                if ($payerKey !== '') {
                    foreach (array_keys($mergedDeals[$id]) as $dealId) {
                        $entry = $history[$payerKey][$dealId] ?? ['COUNT' => 0, 'LAST' => ''];
                        $entry['COUNT']++;
                        if ($date > $entry['LAST']) {
                            $entry['LAST'] = $date;
                        }
                        $history[$payerKey][$dealId] = $entry;
                    }
                }
                continue;
            }

            $reason = trim((string)($list['REASON'] ?? ''));
            if ($reason !== '') {
                $manual = bankBogIsManualSkip($reason);
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
                    // ფილტრში ავტომატური მიზეზი თავისი ტექსტით ჩანს, ხელით გამოტოვებულები ერთად
                    'REASON_CODE' => $manual ? 'manual' : $reason,
                    'MANUAL' => $manual,
                ];
                continue;
            }

            $senderInn = trim((string)($list['SenderDetails_Inn'] ?? ''));
            $inn = trim((string)($list['DocumentPayerInn'] ?? ''));
            if ($inn === '') {
                $inn = $senderInn;
            }
            $list['_RESOLVED_INN'] = $inn;
            $list['_PAYER_KEY'] = bankBogStatementPayerKey($list, $sharedAccounts);
            $pending[] = $list;
            // ნულოვანი INN კონტაქტს არ ებმება - ბევრი უცხოელი გადამხდელის საერთოა
            if ($inn !== '' && !bankBogIsPlaceholderInn($inn)) {
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

        // წინა მიბმების დილები, რომლებიც INN-ით არ ჩაიტვირთა; სტეიჯის ფილტრი არააქტიურ დილებს ტოვებს
        $historyDealIds = [];
        foreach ($pending as $list) {
            foreach (array_keys($history[$list['_PAYER_KEY']] ?? []) as $dealId) {
                if (!isset($dealsById[$dealId])) {
                    $historyDealIds[$dealId] = true;
                }
            }
        }
        if (!empty($historyDealIds)) {
            foreach (bankBogGetDealsByFilter(
                ['ID' => array_keys($historyDealIds), 'STAGE_ID' => bankBogMergeStages()],
                bankBogDealSelectFields()
            ) as $deal) {
                $dealsById[(int)$deal['ID']] = $deal;
            }
        }

        $allDealIds = array_keys($dealsById);
        $leftMap = bankBogComputeLeftToPayMap($allDealIds);
        $subscriptionMap = function_exists('subscriptionFeeStatusMap') ? subscriptionFeeStatusMap($dealsById) : [];

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
                'SENDER_ACCOUNT' => $list['SenderDetails_AccountNumber'] ?? '',
                // რითი ამოიცნობა გადამხდელი: INN ან გამგზავნის ანგარიში
                'PAYER' => preg_replace('/^(INN|ACC):/', '', $list['_PAYER_KEY']),
                'DATE' => $date,
                'list_id' => $list['ID'],
                'PAYMENT' => $list['ID'],
                'COMMENT' => $list['EntryComment'] ?? '',
                'CASH' => bankBogIsCashStatement($list),
                'SUB_HINT' => bankBogMentionsSubscription(($list['DocumentNomination'] ?? '') . ' ' . ($list['EntryComment'] ?? '')),
            ];
            // სალაროს საფუძველში ფართი წერია - მისით დილი წინასწარ შეირჩევა
            $unitHint = $base['CASH'] ? bankBogCashUnitHint($base['NOMINATION']) : null;

            $payer = ($inn !== '' && isset($payers[$inn])) ? $payers[$inn] : null;
            $dealIds = [];
            if ($payer && $payer['type'] === 'contact') {
                $dealIds = $dealsByContact[$payer['id']] ?? [];
            } elseif ($payer) {
                $dealIds = $dealsByCompany[$payer['id']] ?? [];
            }

            // წინა მიბმები, ბოლოს მიბმული პირველი; INN-ით ვერნაპოვნი დილი რიგს "ამოცნობილს" ხდის
            $payerHistory = $history[$list['_PAYER_KEY']] ?? [];
            uasort($payerHistory, function ($a, $b) {
                return strcmp($b['LAST'], $a['LAST']);
            });
            $innDealIds = $dealIds;
            foreach (array_keys($payerHistory) as $dealId) {
                if (isset($dealsById[$dealId]) && !in_array($dealId, $dealIds, true)) {
                    $dealIds[] = $dealId;
                }
            }
            $recognized = count($dealIds) > count($innDealIds);

            $modeled = [];
            foreach ($dealIds as $dealId) {
                $deal = $dealsById[$dealId] ?? null;
                if (!$deal) {
                    continue;
                }
                $clientName = trim((string)($deal['CONTACT_FULL_NAME'] ?? ''));
                if ($clientName === '') {
                    $clientName = trim((string)($deal['COMPANY_TITLE'] ?? ($payer ? $payer['name'] : '')));
                }
                $hist = $payerHistory[$dealId] ?? null;
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
                    'FROM_HISTORY' => !in_array($dealId, $innDealIds, true),
                    'HISTORY_COUNT' => $hist ? $hist['COUNT'] : 0,
                    'HISTORY_LAST' => $hist ? bankBogFormatDateDmy($hist['LAST']) : '',
                    // აბონირება (₾): AMOUNT / PAID / LEFT; null - დილს აბონირება არ აქვს
                    'SUB' => $subscriptionMap[$dealId] ?? null,
                ];
            }

            if (!empty($modeled)) {
                $suggested = 0;
                if ($unitHint) {
                    $candidates = [];
                    foreach ($modeled as $m) {
                        $candidates[] = $dealsById[$m['ID']];
                    }
                    $suggested = bankBogPickDealByUnitHint($candidates, $unitHint);
                }
                $matched[] = array_merge($base, [
                    'CLIENT_NAME' => ($payer ? $payer['name'] : '') ?: $name,
                    'CLIENT_ID' => $payer ? $payer['id'] : 0,
                    'STATUS' => $payer ? $payer['type'] : '',
                    'RECOGNIZED' => $recognized,
                    'MERGE_DEALS' => $modeled,
                    'UNIT_HINT' => $unitHint,
                    'SUGGESTED_DEAL' => $suggested,
                ]);
            } elseif ($payer) {
                $errors[] = array_merge($base, [
                    'CLIENT_ID' => $payer['id'],
                    'STATUS' => $payer['type'],
                    'UNIT_HINT' => $unitHint,
                ]);
            } else {
                $errors[] = $base + ['UNIT_HINT' => $unitHint];
            }
        }

        bankBogSuggestDealsForUnmatchedCash($errors);

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
            'pay_type' => bankBogIsCashStatement($list) ? BANK_BOG_PAY_TYPE_CASH : BANK_BOG_PAY_TYPE_BOG,
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

/**
 * ამონაწერის ნაწილი აბონირებაზე: გადახდა "აბონირების გადახდების" სიაში (₾), ლისტ 23-ში არა.
 * დარჩენილ აბონირებაზე მეტის შემოწმება bankBogProcessMergePost-შია (ამონაწერის ყველა ხაზი ერთად).
 */
if (!function_exists('bankBogCreateSubscriptionPaymentFromMerge')) {
    function bankBogCreateSubscriptionPaymentFromMerge($dealId, $paymentId, $valueGel)
    {
        $dealId = (int)$dealId;
        $paymentId = (int)$paymentId;
        $valueGel = bankBogMoney($valueGel);
        $iblockId = function_exists('subscriptionFeePaymentIblockId') ? subscriptionFeePaymentIblockId() : 0;

        if ($dealId <= 0 || $paymentId <= 0 || $valueGel <= 0) {
            return ['ok' => false, 'error' => 'invalid_input'];
        }
        if ($iblockId <= 0) {
            return ['ok' => false, 'error' => 'subscription_iblock_missing'];
        }

        $dup = bankBogGetElements([
            'IBLOCK_ID' => $iblockId,
            'PROPERTY_DEAL' => $dealId,
            'PROPERTY_BANK_PAYMENT_ID' => $paymentId,
        ], ['ID'], ['ID' => 'DESC'], 1);
        if (!empty($dup)) {
            return ['ok' => false, 'error' => 'already_merged'];
        }

        $listRows = bankBogGetElements(['IBLOCK_ID' => bankBogStatementIblockId(), 'ID' => $paymentId], null, ['ID' => 'DESC'], 1);
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
        // ძველ დილს დარიცხვა პირველი გადახდისას ექმნება
        if (subscriptionFeeEnsureCharge($dealId, $deal) <= 0) {
            return ['ok' => false, 'error' => 'დილს აბონირება არ აქვს (მხოლოდ Dighomi / Green City ბინები)'];
        }

        $amounts = bankBogStatementAmounts($list);
        $nbg = floatval($amounts['NBG']) ?: floatval($list['NBG_RATE'] ?? 0);
        $date = bankBogFormatDateDmy(explode('T', (string)($list['EntryDate'] ?? ''))[0]) ?: date('d/m/Y');
        $cash = bankBogIsCashStatement($list);

        $props = subscriptionFeeDealProps($deal) + [
            'date' => $date,
            'TANXA' => $valueGel,
            'TANXA_USD' => $nbg > 0 ? bankBogMoney($valueGel / $nbg) : '',
            'NBG' => $nbg > 0 ? $nbg : '',
            'pay_type' => subscriptionFeePayTypeId($cash ? 'cash' : 'bank') ?: '',
            'BANK_PAYMENT_ID' => (string)$paymentId,
            'comment' => $list['EntryComment'] ?? '',
        ];

        $res = bankBogAddElement([
            'IBLOCK_ID' => $iblockId,
            'NAME' => trim('აბონირება ' . ($list['SenderDetails_Name'] ?? '') . ' ' . $date),
            'ACTIVE' => 'Y',
        ], $props);

        if (!is_numeric($res) || (int)$res <= 0) {
            return ['ok' => false, 'error' => (string)$res];
        }
        return ['ok' => true, 'payment_id' => (int)$res];
    }
}

/**
 * POST ხაზები: DEAL_i, PAYMENT_i (ამონაწერი), VALUE_i, KIND_i.
 * KIND = sub - აბონირება, VALUE ₾-ში; სხვა შემთხვევაში ბინის გადახდა ლისტ 23-ში, VALUE $-ში.
 */
if (!function_exists('bankBogProcessMergePost')) {
    function bankBogProcessMergePost(array $post)
    {
        $results = ['saved' => 0, 'errors' => []];
        $dealKeys = array_filter(array_keys($post), function ($key) {
            return strpos($key, 'DEAL_') === 0;
        });

        $rows = [];
        foreach ($dealKeys as $dealKey) {
            $index = str_replace('DEAL_', '', $dealKey);
            $dealId = bankBogNormalizeDealId($post['DEAL_' . $index] ?? '');
            $value = isset($post['VALUE_' . $index]) ? trim((string)$post['VALUE_' . $index]) : '';
            $payment = isset($post['PAYMENT_' . $index]) ? trim((string)$post['PAYMENT_' . $index]) : '';

            if ($value === '' || (float)$value == 0.0 || $dealId === '' || $payment === '') {
                continue;
            }
            $rows[] = [
                'deal' => $dealId,
                'payment' => $payment,
                'value' => $value,
                'sub' => ($post['KIND_' . $index] ?? '') === 'sub',
            ];
        }

        // აბონირების ხაზები შენახვამდე მოწმდება: არასწორი ხაზის მქონე ამონაწერი საერთოდ არ ინახება,
        // რომ ნახევრად მიბმული (და გვერდიდან გამქრალი) არ დარჩეს
        $subRequested = [];
        foreach ($rows as $row) {
            if ($row['sub']) {
                $subRequested[$row['deal']] = ($subRequested[$row['deal']] ?? 0) + bankBogMoney($row['value']);
            }
        }
        $subStatus = ($subRequested && function_exists('subscriptionFeeStatusMap'))
            ? subscriptionFeeStatusMap(array_fill_keys(array_keys($subRequested), null))
            : [];
        $blocked = [];
        foreach ($rows as $row) {
            if (!$row['sub']) {
                continue;
            }
            $status = $subStatus[$row['deal']] ?? null;
            $error = '';
            if (!$status) {
                $error = 'დილს აბონირება არ აქვს (მხოლოდ Dighomi / Green City ბინები)';
            } elseif ($subRequested[$row['deal']] > $status['LEFT'] + 0.01) {
                $error = 'აბონირების თანხა დარჩენილზე მეტია (დარჩენილი ' . number_format($status['LEFT'], 2) . ' ₾)';
            }
            if ($error !== '') {
                $blocked[$row['payment']] = true;
                $results['errors'][] = ['deal' => $row['deal'], 'payment' => $row['payment'], 'error' => $error];
            }
        }

        foreach ($rows as $row) {
            if (isset($blocked[$row['payment']])) {
                continue;
            }
            $out = $row['sub']
                ? bankBogCreateSubscriptionPaymentFromMerge($row['deal'], $row['payment'], $row['value'])
                : bankBogCreatePaymentFromMerge($row['deal'], $row['payment'], $row['value']);
            if (!empty($out['ok'])) {
                $results['saved']++;
            } else {
                $results['errors'][] = [
                    'deal' => $row['deal'],
                    'payment' => $row['payment'],
                    'error' => $out['error'] ?? 'unknown',
                ];
            }
        }

        return $results;
    }
}

/** ხელით გამოტოვებული ამონაწერის მიზეზი BANK_BOG_MANUAL_SKIP_PREFIX-ით იწყება. */
if (!function_exists('bankBogIsManualSkip')) {
    function bankBogIsManualSkip($reason)
    {
        return strpos((string)$reason, BANK_BOG_MANUAL_SKIP_PREFIX) === 0;
    }
}

if (!function_exists('bankBogLoadStatement')) {
    function bankBogLoadStatement($statementId)
    {
        $statementId = (int)$statementId;
        $iblockId = bankBogStatementIblockId();
        if ($statementId <= 0 || $iblockId <= 0) {
            return null;
        }
        $rows = bankBogGetElements(['IBLOCK_ID' => $iblockId, 'ID' => $statementId], null, ['ID' => 'DESC'], 1);
        return $rows[0] ?? null;
    }
}

/**
 * ამონაწერი "დამუშავებას არ საჭიროებს": გადადის გამოტოვებულებში, მიზეზში იწერება
 * კომენტარი, ვინ და როდის გადაყარა. მიბმული ან უკვე გამოტოვებული ამონაწერი არ იცვლება.
 */
if (!function_exists('bankBogManualSkip')) {
    function bankBogManualSkip($statementId, $comment, $userName)
    {
        $list = bankBogLoadStatement($statementId);
        if (!$list) {
            return ['ok' => false, 'error' => 'statement_not_found'];
        }
        if (trim((string)($list['REASON'] ?? '')) !== '') {
            return ['ok' => false, 'error' => 'already_skipped'];
        }
        $merged = bankBogGetElements([
            'IBLOCK_ID' => BANK_BOG_PAYMENT_IBLOCK,
            'PROPERTY_BANK_PAYMENT_ID' => (int)$list['ID'],
        ], ['ID'], ['ID' => 'DESC'], 1);
        if (!empty($merged)) {
            return ['ok' => false, 'error' => 'already_merged'];
        }

        $comment = trim((string)preg_replace('/\s+/u', ' ', (string)$comment));
        $reason = BANK_BOG_MANUAL_SKIP_PREFIX . ($comment !== '' ? $comment : 'კომენტარის გარეშე')
            . ' (' . trim((string)$userName) . ', ' . date('d/m/Y H:i') . ')';
        CIBlockElement::SetPropertyValuesEx((int)$list['ID'], bankBogStatementIblockId(), ['REASON' => $reason]);
        return ['ok' => true];
    }
}

/** ხელით გამოტოვებული ამონაწერი ბრუნდება მერჯზე; ავტომატურად გამოტოვებული - არა. */
if (!function_exists('bankBogRestoreSkipped')) {
    function bankBogRestoreSkipped($statementId)
    {
        $list = bankBogLoadStatement($statementId);
        if (!$list) {
            return ['ok' => false, 'error' => 'statement_not_found'];
        }
        if (!bankBogIsManualSkip($list['REASON'] ?? '')) {
            return ['ok' => false, 'error' => 'not_manual'];
        }
        CIBlockElement::SetPropertyValuesEx((int)$list['ID'], bankBogStatementIblockId(), ['REASON' => '']);
        return ['ok' => true];
    }
}
