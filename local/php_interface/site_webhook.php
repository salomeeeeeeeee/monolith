<?php
/**
 * საიტის (api.monolith.ge) მყისიერი განახლება პროდუქტის სტატუსის ცვლილებისას.
 *
 * სტატუსი (iblock 14, _P64GYD) ბევრი ადგილიდან იცვლება: statusChange BP, კატალოგი,
 * პროდუქტების მოდული, ხელით რედაქტირება. ამიტომ ვიჭერთ iblock-ის event-ებს: ჩაწერამდე
 * ვიმახსოვრებთ ძველ სტატუსს, ჰიტის ბოლოს ვადარებთ მიმდინარეს და შეცვლილი პროდუქტების
 * productId-ს ვუგზავნით საიტის webhook-ს. საიტი მონაცემებს getProducts.php?productId=...-ით
 * თავად კითხულობს. თუ გამოძახება ვერ შესრულდა, ცვლილება საიტზე მაინც აისახება მათი
 * 10-წუთიანი განახლებისას.
 *
 * ყოველი ცვლილება იწერება სიაში SITE_WEBHOOK_LOG (პროდუქტი, დილი, ძველი/ახალი სტატუსი,
 * რამ გამოიწვია, საიტის პასუხი). სია იქმნება /custom/setup/siteWebhookLog.php-ით.
 *
 * X-Webhook-Secret ინახება site_webhook_secret.php-ში, რომელიც git-ში არ არის (რეპო საჯაროა).
 * ნიმუში: site_webhook_secret.example.php
 */

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

const SITE_WEBHOOK_URL = 'https://api.monolith.ge/api/crm/webhook';
const SITE_WEBHOOK_IBLOCK_ID = 14;
const SITE_WEBHOOK_STATUS_CODE = '_P64GYD';
const SITE_WEBHOOK_LOG_CODE = 'SITE_WEBHOOK_LOG';
/** ერთ ჰიტზე ამაზე მეტი შეცვლილი პროდუქტი (მასობრივი განახლება) არ იგზავნება - საიტი 10-წუთიანი განახლებით აიღებს. */
const SITE_WEBHOOK_MAX_PER_HIT = 20;

class MonolithSiteWebhook
{
    /** @var array productId => ['status', 'deal', 'cause', 'bpDeal', 'url', 'user', 'time'] ჰიტში პირველი ჩაწერის წინ */
    private static $before = [];
    private static $scheduled = false;

    /** CIBlockElement::Update / SetPropertyValues / SetPropertyValueCode - ჩაწერამდე. */
    public static function onSetPropertyValues($elementId, $iblockId, $propertyValues, $propertyCode = false)
    {
        try {
            // კოდის გარეშე ყველა property გადაიწერება, ასე რომ სტატუსიც შეიძლება შეიცვალოს
            if ($propertyCode === false || self::isStatusKey($propertyCode)) {
                self::remember($elementId, $iblockId);
            }
        } catch (\Throwable $e) {
            // webhook-ის შეცდომამ პროდუქტის შენახვა არ უნდა შეაჩეროს
        }
    }

    /** CIBlockElement::SetPropertyValuesEx - ჩაწერამდე; იწერება მხოლოდ გადაცემული property-ები. */
    public static function onSetPropertyValuesEx($elementId, $iblockId, $propertyValues)
    {
        if ((int)$iblockId !== SITE_WEBHOOK_IBLOCK_ID || !is_array($propertyValues)) {
            return;
        }
        try {
            foreach (array_keys($propertyValues) as $key) {
                if (self::isStatusKey($key)) {
                    self::remember($elementId, $iblockId);
                    return;
                }
            }
        } catch (\Throwable $e) {
            // webhook-ის შეცდომამ პროდუქტის შენახვა არ უნდა შეაჩეროს
        }
    }

    /**
     * ადარებს დამახსოვრებულ სტატუსებს მიმდინარეს, შეცვლილებს უგზავნის საიტს და წერს სიაში.
     * ჩვეულებრივ ჰიტზე ეშვება პასუხის გაგზავნის შემდეგ (Application::terminate), ხოლო
     * die()-ით დასრულებულ სკრიპტებზე, სადაც terminate არ ეშვება - shutdown function-იდან.
     */
    public static function flush()
    {
        if (empty(self::$before)) {
            return;
        }
        $before = self::$before;
        self::$before = [];
        self::$scheduled = false;

        try {
            $changes = [];
            foreach (self::readProducts(array_keys($before)) as $id => $product) {
                if ($product['status'] !== $before[$id]['status']) {
                    $changes[$id] = $before[$id] + ['newStatus' => $product['status'], 'product' => $product];
                }
            }
            if (empty($changes)) {
                return;
            }

            $skipReason = '';
            $secret = '';
            if (count($changes) > SITE_WEBHOOK_MAX_PER_HIT) {
                $skipReason = 'არ გაიგზავნა: ერთ ჯერზე ' . count($changes) . ' პროდუქტის სტატუსი შეიცვალა (საიტი აიღებს 10-წუთიანი განახლებით)';
            } elseif (($secret = self::secret()) === '') {
                $skipReason = 'არ გაიგზავნა: site_webhook_secret.php არ არის ან ცარიელია';
            }

            foreach ($changes as $id => $change) {
                $response = $skipReason === '' ? self::send($id, $secret) : ['code' => 0, 'body' => $skipReason];
                self::writeLog($id, $change, $response);
            }
        } catch (\Throwable $e) {
            self::eventLog($e->getMessage());
        }
    }

    private static function remember($elementId, $iblockId)
    {
        $elementId = (int)$elementId;
        if ($elementId <= 0 || array_key_exists($elementId, self::$before)) {
            return;
        }
        if ((int)$iblockId > 0 && (int)$iblockId !== SITE_WEBHOOK_IBLOCK_ID) {
            return;
        }

        $products = self::readProducts([$elementId]);
        if (!isset($products[$elementId])) {
            return;
        }
        self::$before[$elementId] = [
            'status' => $products[$elementId]['status'],
            'deal' => $products[$elementId]['deal'],
            'time' => time(),
        ] + self::cause();

        if (!self::$scheduled) {
            self::$scheduled = true;
            Application::getInstance()->addBackgroundJob([self::class, 'flush']);
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /**
     * რამ გამოიწვია ჩაწერა. BP-ის შიგნით (მაგ. statusChange დილის სტადიის ცვლილებისას)
     * call stack-ში დევს BP-ის activity, საიდანაც ვიღებთ შაბლონის სახელს და დილს.
     */
    private static function cause()
    {
        global $USER;

        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        // სიას ყველა თანამშრომელი ხედავს: sessid / ტოკენები და REST webhook-ის კოდი URL-ში იფარება
        $url = PHP_SAPI === 'cli' ? 'cli: ' . $script : (string)($_SERVER['REQUEST_URI'] ?? $script);
        $url = preg_replace('/([?&](?:sessid|auth|token|access_token)=)[^&]*/i', '$1***', $url);
        $url = preg_replace('#^/rest/(\d+)/[^/]+/#', '/rest/$1/***/', $url);
        $cause = [
            'cause' => '',
            'bpDeal' => 0,
            'url' => $url,
            'user' => is_object($USER) ? (int)$USER->GetID() : 0,
        ];

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
            if (isset($frame['object']) && $frame['object'] instanceof CBPActivity) {
                $root = $frame['object']->getRootActivity();
                $cause['cause'] = 'ბიზნეს-პროცესი: ' . self::templateName($root->getWorkflowTemplateId());
                $documentId = $root->getDocumentId();
                if (is_array($documentId) && preg_match('/^DEAL_(\d+)$/', (string)($documentId[2] ?? ''), $m)) {
                    $cause['bpDeal'] = (int)$m[1];
                }
                return $cause;
            }
        }

        if (PHP_SAPI === 'cli') {
            $cause['cause'] = 'cron / აგენტი';
        } elseif ($script === '/rest/local/api/product/status-change.php') {
            $cause['cause'] = 'პროდუქტების მოდული (სტატუსის ხელით შეცვლა)';
        } elseif (strpos($script, '/bitrix/admin/') === 0) {
            $cause['cause'] = 'ადმინკა (ხელით რედაქტირება)';
        } else {
            $action = is_string($_REQUEST['action'] ?? null) ? $_REQUEST['action'] : '';
            $cause['cause'] = 'გვერდი / API: ' . $script . ($action !== '' ? ' (' . $action . ')' : '');
        }
        return $cause;
    }

    private static function templateName($templateId)
    {
        static $names = [];
        $templateId = (int)$templateId;
        if (!isset($names[$templateId])) {
            $row = $templateId > 0 && class_exists('CBPWorkflowTemplateLoader')
                ? CBPWorkflowTemplateLoader::GetList([], ['ID' => $templateId], false, false, ['ID', 'NAME'])->Fetch()
                : false;
            $names[$templateId] = $row ? $row['NAME'] . ' (#' . $templateId . ')' : '#' . $templateId;
        }
        return $names[$templateId];
    }

    /** @return array productId => ['status', 'deal', 'title'] (მხოლოდ iblock 14-ის ელემენტები) */
    private static function readProducts(array $ids)
    {
        $status = 'PROPERTY_' . SITE_WEBHOOK_STATUS_CODE;
        $res = CIBlockElement::GetList(
            [],
            ['ID' => $ids, 'IBLOCK_ID' => SITE_WEBHOOK_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'],
            false,
            false,
            ['ID', $status, 'PROPERTY_OWNER_DEAL', 'PROPERTY_ownerDeal', 'PROPERTY___VO9RG4', 'PROPERTY___X1GCRZ', 'PROPERTY___6KWOWZ', 'PROPERTY__L24CUB', 'PROPERTY__FTRIDL']
        );
        $products = [];
        while ($row = $res->Fetch()) {
            // ownerDeal (ECrm) შეიძლება "D_13"-ად იყოს შენახული
            $deal = (int)preg_replace('/\D/', '', (string)($row['PROPERTY_OWNER_DEAL_VALUE'] ?: $row['PROPERTY_OWNERDEAL_VALUE']));
            $number = trim((string)$row['PROPERTY___6KWOWZ_VALUE']);
            $block = trim((string)$row['PROPERTY__L24CUB_VALUE']);
            $floor = trim((string)$row['PROPERTY__FTRIDL_VALUE']);
            $title = array_filter([
                trim((string)$row['PROPERTY___VO9RG4_VALUE']),
                trim($row['PROPERTY___X1GCRZ_VALUE'] . ($number !== '' ? ' N' . $number : '')),
                $block !== '' ? 'ბლოკი ' . $block : '',
                $floor !== '' ? 'სართული ' . $floor : '',
            ], 'strlen');
            $products[(int)$row['ID']] = [
                'status' => (string)$row[$status . '_VALUE'],
                'deal' => $deal,
                'title' => implode(', ', $title),
            ];
        }
        return $products;
    }

    /** property-ს გასაღები შეიძლება იყოს კოდი ან ID. */
    private static function isStatusKey($key)
    {
        if (is_numeric($key)) {
            return (int)$key === self::statusPropertyId();
        }
        return strtoupper((string)$key) === strtoupper(SITE_WEBHOOK_STATUS_CODE);
    }

    private static function statusPropertyId()
    {
        static $id = null;
        if ($id === null) {
            $row = CIBlockProperty::GetList([], ['IBLOCK_ID' => SITE_WEBHOOK_IBLOCK_ID, 'CODE' => SITE_WEBHOOK_STATUS_CODE])->Fetch();
            $id = $row ? (int)$row['ID'] : 0;
        }
        return $id;
    }

    /** @return array ['code' => HTTP კოდი ან 0, 'body' => საიტის პასუხი ან შეცდომა] */
    private static function send($productId, $secret)
    {
        $http = new HttpClient(['socketTimeout' => 3, 'streamTimeout' => 5]);
        $http->setHeader('Content-Type', 'application/json');
        $http->setHeader('X-Webhook-Secret', $secret);
        $body = $http->post(SITE_WEBHOOK_URL, Json::encode(['productId' => $productId]));

        $code = (int)$http->getStatus();
        return [
            'code' => $code,
            'body' => $code > 0 ? mb_substr((string)$body, 0, 1000) : 'კავშირის შეცდომა: ' . implode('; ', $http->getError()),
        ];
    }

    private static function writeLog($productId, array $change, array $response)
    {
        try {
            $iblockId = self::logIblockId();
            if ($iblockId <= 0) {
                return;
            }

            // BP-ის დილი; თუ BP არ არის - პროდუქტის მფლობელი დილი (გათავისუფლებისას ძველი)
            $dealId = $change['bpDeal'] ?: ($change['product']['deal'] ?: $change['deal']);
            $cause = $change['cause'];
            if ($change['bpDeal'] > 0 && ($stage = self::dealStageName($change['bpDeal'])) !== '') {
                $cause .= ', დილის სტადია: ' . $stage;
            }

            $el = new CIBlockElement();
            $el->Add([
                'IBLOCK_ID' => $iblockId,
                'NAME' => 'ProdID ' . $productId . ': ' . ($change['status'] !== '' ? $change['status'] : '-') . ' -> ' . ($change['newStatus'] !== '' ? $change['newStatus'] : '-'),
                'ACTIVE' => 'Y',
                'PROPERTY_VALUES' => [
                    'PRODUCT_ID' => $productId,
                    'PRODUCT' => $change['product']['title'],
                    'DEAL' => $dealId > 0 ? $dealId : false,
                    'OLD_STATUS' => $change['status'],
                    'NEW_STATUS' => $change['newStatus'],
                    'CAUSE' => $cause,
                    'SOURCE_URL' => mb_substr($change['url'], 0, 500),
                    'CHANGED_BY' => $change['user'] > 0 ? $change['user'] : false,
                    'HTTP_CODE' => $response['code'] > 0 ? $response['code'] : false,
                    'RESPONSE' => $response['body'],
                    'CHANGE_DATE' => ConvertTimeStamp($change['time'], 'FULL'),
                ],
            ]);
        } catch (\Throwable $e) {
            self::eventLog('ProdID ' . $productId . ': ' . $e->getMessage());
        }
    }

    private static function logIblockId()
    {
        static $id = null;
        if ($id === null) {
            $row = CIBlock::GetList([], ['CODE' => SITE_WEBHOOK_LOG_CODE, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
            $id = $row ? (int)$row['ID'] : 0;
        }
        return $id;
    }

    private static function dealStageName($dealId)
    {
        if (!Loader::includeModule('crm')) {
            return '';
        }
        $deal = CCrmDeal::GetListEx([], ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID', 'STAGE_ID', 'CATEGORY_ID'])->Fetch();
        return $deal ? (string)CCrmDeal::GetStageName($deal['STAGE_ID'], $deal['CATEGORY_ID']) : '';
    }

    private static function secret()
    {
        $file = __DIR__ . '/site_webhook_secret.php';
        $secret = is_file($file) ? include $file : '';
        return is_string($secret) ? trim($secret) : '';
    }

    /** სიაში ჩაწერა თვითონ თუ ჩავარდა - Event Log. */
    private static function eventLog($text)
    {
        try {
            CEventLog::Add([
                'SEVERITY' => 'WARNING',
                'AUDIT_TYPE_ID' => 'SITE_WEBHOOK_ERROR',
                'MODULE_ID' => 'iblock',
                'ITEM_ID' => '-',
                'DESCRIPTION' => $text,
            ]);
        } catch (\Throwable $e) {
        }
    }
}

AddEventHandler('iblock', 'OnIBlockElementSetPropertyValues', [MonolithSiteWebhook::class, 'onSetPropertyValues']);
AddEventHandler('iblock', 'OnIBlockElementSetPropertyValuesEx', [MonolithSiteWebhook::class, 'onSetPropertyValuesEx']);
