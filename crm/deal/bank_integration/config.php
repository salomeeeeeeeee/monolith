<?php
/**
 * Bank of Georgia (BOG) integration — Monolith / New Depot
 *
 * ამონაწერები იტვირთება ბანკის API-დან ცალკე სიაში (BOG_STATEMENTS), შემდეგ
 * ხელით/ნახევრად ავტომატურად ებმება დილებს და იქმნება გადახდა ლისტ 23-ში.
 *
 * სიის შესაქმნელად ერთხელ გაუშვი: /crm/deal/bank_integration/setup.php
 */

/** ამონაწერების სია — setup.php ამ CODE-ით ქმნის iblock-ს. */
if (!defined('BANK_BOG_STATEMENT_IBLOCK_CODE')) {
    define('BANK_BOG_STATEMENT_IBLOCK_CODE', 'BOG_STATEMENTS');
}
/** გადახდები */
if (!defined('BANK_BOG_PAYMENT_IBLOCK')) {
    define('BANK_BOG_PAYMENT_IBLOCK', 23);
}
/** გადახდის ტიპი (pay_type): "BOG integration" - ასე ჩანს, რომ გადახდა ბანკის ამონაწერიდან შეიქმნა */
if (!defined('BANK_BOG_PAY_TYPE_BOG')) {
    define('BANK_BOG_PAY_TYPE_BOG', 122);
}
/** გადახდის გრაფიკი (დარიცხვები) */
if (!defined('BANK_BOG_SCHEDULE_IBLOCK')) {
    define('BANK_BOG_SCHEDULE_IBLOCK', 22);
}

/**
 * კომპანიები. Business Online-ის წვდომა (client ID / secret) კომპანიაზეა გაცემული
 * და ხედავს ამ კომპანიის ყველა ანგარიშს.
 *
 * client_id / client_secret ინახება credentials.php-ში, რომელიც git-ში არ არის
 * (რეპო საჯაროა). ნიმუში: credentials.example.php
 */
function bankBogCompanies()
{
    return [
        'MONOLITH_GROUP_PLUS' => ['name' => 'შპს მონოლით ჯგუფი პლუსი', 'inn' => '204943357'],
        'NEW_DEPOT' => ['name' => 'სს ზამბის წართი', 'inn' => '200003085'],
    ];
}

/**
 * ანგარიშები ვალუტების მიხედვით: ბანკი ამონაწერს ანგარიში+ვალუტაზე აბრუნებს.
 * გასაღები = IBAN + ვალუტა, როგორც ბანკის ანგარიშების სიაში (GE44BG...780USD).
 */
function bankBogAccounts()
{
    static $accounts = null;
    if ($accounts !== null) {
        return $accounts;
    }

    // [კომპანია, პროექტი, IBAN, ვალუტა, ანგარიშის სახელი]
    $rows = [
        ['MONOLITH_GROUP_PLUS', 'Green City', 'GE74BG0000000539703792', 'EUR', 'გრინი'],
        ['MONOLITH_GROUP_PLUS', 'Green City', 'GE17BG0000000539702781', 'USD', 'გრინი'],
        ['MONOLITH_GROUP_PLUS', 'Green City', 'GE44BG0000000539702780', 'GEL', 'გრინი'],
        ['MONOLITH_GROUP_PLUS', 'Green City', 'GE44BG0000000539702780', 'USD', 'გრინი'],
        ['MONOLITH_GROUP_PLUS', 'Green City', 'GE44BG0000000539702780', 'EUR', 'გრინი'],
        ['MONOLITH_GROUP_PLUS', 'Green City', 'GE21BG0000000541155187', 'GEL', 'ქონსტრაქშენი'],
        ['MONOLITH_GROUP_PLUS', 'Green City', 'GE21BG0000000541155187', 'USD', 'ქონსტრაქშენი'],
        ['MONOLITH_GROUP_PLUS', 'Green City', 'GE39BG0000000541154281', 'USD', 'ქონსტრაქშენი'],
        ['MONOLITH_GROUP_PLUS', 'Green City', 'GE39BG0000000541154281', 'EUR', 'ქონსტრაქშენი'],
        ['MONOLITH_GROUP_PLUS', 'Dighomi', 'GE93BG0000000580627459', 'GEL', 'დიღომი'],
        ['MONOLITH_GROUP_PLUS', 'Dighomi', 'GE93BG0000000580627459', 'USD', 'დიღომი'],
        ['MONOLITH_GROUP_PLUS', 'Dighomi', 'GE93BG0000000580627459', 'EUR', 'დიღომი'],
        ['MONOLITH_GROUP_PLUS', 'Ethno city', 'GE19BG0000000553216688', 'GEL', 'ფაზა IV'],
        ['MONOLITH_GROUP_PLUS', 'Ethno city', 'GE19BG0000000553216688', 'USD', 'ეთნო'],
        ['MONOLITH_GROUP_PLUS', 'Ethno city', 'GE19BG0000000553216688', 'EUR', 'ეთნო'],
        ['MONOLITH_GROUP_PLUS', 'Ethno city', 'GE93BG0000000580624840', 'USD', 'ფაზა IV'],
        ['NEW_DEPOT', 'New Depot', 'GE55BG0000000482087201', 'GEL', 'New Depot'],
        ['NEW_DEPOT', 'New Depot', 'GE82BG0000000482087200', 'GEL', 'New Depot'],
        ['NEW_DEPOT', 'New Depot', 'GE82BG0000000482087200', 'USD', 'New Depot'],
    ];

    $accounts = [];
    foreach ($rows as $row) {
        list($company, $project, $iban, $currency, $name) = $row;
        $accounts[$iban . $currency] = [
            'key' => $iban . $currency,
            'company' => $company,
            'project' => $project,
            'iban' => $iban,
            'currency' => $currency,
            'name' => $name,
        ];
    }
    return $accounts;
}

function bankBogAccountByKey($key)
{
    $accounts = bankBogAccounts();
    return $accounts[trim((string)$key)] ?? null;
}

/** [client_id, client_secret] კომპანიისთვის; ცარიელი სტრიქონები, თუ credentials.php-ში არ წერია. */
function bankBogCredentials($company)
{
    static $all = null;
    if ($all === null) {
        $file = __DIR__ . '/credentials.php';
        $loaded = is_file($file) ? include $file : [];
        $all = is_array($loaded) ? $loaded : [];
    }
    $pair = $all[$company] ?? [];
    return [
        trim((string)($pair['client_id'] ?? '')),
        trim((string)($pair['client_secret'] ?? '')),
    ];
}

/**
 * ამონაწერების სიის ID. setup.php-მდე 0-ს აბრუნებს.
 * BANK_BOG_STATEMENT_IBLOCK-ის ხელით განსაზღვრა ძებნას გამორთავს.
 */
function bankBogStatementIblockId()
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    if (defined('BANK_BOG_STATEMENT_IBLOCK')) {
        return $id = (int)BANK_BOG_STATEMENT_IBLOCK;
    }
    if (!class_exists('CIBlock')) {
        return $id = 0;
    }
    $row = CIBlock::GetList([], ['CODE' => BANK_BOG_STATEMENT_IBLOCK_CODE, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
    return $id = $row ? (int)$row['ID'] : 0;
}

/** Contact: პირადი ნომერი */
if (!defined('BANK_C_PERSONAL_ID')) {
    define('BANK_C_PERSONAL_ID', 'UF_CRM_1781244744534');
}
/** Contact: პასპორტის ნომერი */
if (!defined('BANK_C_PASSPORT')) {
    define('BANK_C_PASSPORT', 'UF_CRM_1790776088');
}
/** Company: საიდენტიფიკაციო კოდი */
if (!defined('BANK_CO_TAX_ID')) {
    define('BANK_CO_TAX_ID', 'UF_CRM_1790776120');
}

/** Deal UF fields */
if (!defined('BANK_D_PROJECT')) {
    define('BANK_D_PROJECT', 'UF_CRM_1779277729207');
}
if (!defined('BANK_D_BLOCK')) {
    define('BANK_D_BLOCK', 'UF_CRM_1779277644355');
}
if (!defined('BANK_D_TYPE')) {
    define('BANK_D_TYPE', 'UF_CRM_1779277898205');
}
if (!defined('BANK_D_FLOOR')) {
    define('BANK_D_FLOOR', 'UF_CRM_1779277828822');
}
if (!defined('BANK_D_UNIT')) {
    define('BANK_D_UNIT', 'UF_CRM_1779277613798');
}
if (!defined('BANK_D_CONTRACT_DATE')) {
    define('BANK_D_CONTRACT_DATE', 'UF_CRM_1779278774084');
}
if (!defined('BANK_D_CURRENCY')) {
    define('BANK_D_CURRENCY', 'UF_CRM_1702019032102');
}
/** ხელშეკრულების ნომერი (მონოლითი) → გადახდის xelshNum */
if (!defined('BANK_D_CONTRACT_NUM')) {
    define('BANK_D_CONTRACT_NUM', 'UF_CRM_1791205458297');
}

/** Merge მხოლოდ ამ სტეიჯებზე. */
function bankBogMergeStages()
{
    return ['EXECUTING', 'UC_NSTB3H', 'UC_NJ7A78', 'WON'];
}
