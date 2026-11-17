<?php
/**
 * ზარის ჩანაწერის მოსმენის შეზღუდვა.
 *
 * ჯგუფ 26-ში («ზარის მოსმენის შეზღუდვა») მყოფ თანამშრომელს მხოლოდ საკუთარი ზარის
 * ჩანაწერის მოსმენა / ჩამოტვირთვა შეუძლია. ადმინი (ჯგუფი 1) ყოველთვის ხედავს ყველას.
 *
 * CRM-ში ჩანაწერი (timeline-ის პლეერი, «ჩანაწერის ჩამოტვირთვა», ქმედების ბარათი, მობილური)
 * /bitrix/tools/crm_show_file.php-ით იტვირთება, ის კი მხოლოდ დილის/ლიდის ნახვის უფლებას
 * ამოწმებს. ამიტომ ამ სკრიპტზე, OnBeforeProlog-ში (მომხმარებელი უკვე ავტორიზებულია),
 * ვამოწმებთ ზარის პატრონს: b_voximplant_statistic.PORTAL_USER_ID (ფაილით), თუ არ არის -
 * ზარის ქმედების RESPONSIBLE_ID. სხვისი ჩანაწერია - 403.
 *
 * timeline-ში კი სხვისი ზარის პლეერის ნაცვლად ტექსტი ჩანს «ამ ზარის ჩანაწერზე წვდომა არ გაქვთ»
 * (call_record_timeline.php, ServiceLocator-ის crm.timeline.factory.activityItem).
 *
 * დანარჩენი გზები უკვე დახურულია: Disk-ის საქაღალდე «Telephony - recorded calls» DR1-ს
 * აკრძალული აქვს, ტელეფონიის ისტორიაში კი როლი «Manager»: ჩანაწერები - მხოლოდ საკუთარი.
 */

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class MonolithCallRecordAccess
{
    const GROUP_ID = 26;
    const ADMIN_GROUP_ID = 1;
    const SCRIPT = '/bitrix/tools/crm_show_file.php';
    const OWNER_TYPE_ACTIVITY = 6; // CCrmOwnerType::Activity
    const ACTIVITY_TYPE_CALL = 2;  // CCrmActivityType::Call
    const MESSAGE = 'ამ ზარის ჩანაწერის მოსმენა არ შეგიძლიათ. თქვენ მხოლოდ საკუთარი ზარების ჩანაწერებზე გაქვთ წვდომა.';

    public static function isTargetScript()
    {
        $script = preg_replace('#/+#', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        return $script === self::SCRIPT;
    }

    public static function onBeforeProlog()
    {
        $fileId = (int)($_REQUEST['fileId'] ?? 0);
        $ownerTypeId = (int)($_REQUEST['ownerTypeId'] ?? 0);
        $ownerId = (int)($_REQUEST['ownerId'] ?? 0);
        if ($fileId <= 0) {
            return;
        }

        try {
            $userId = self::currentUserId();
        } catch (\Throwable $e) {
            return; // ავტორიზაციას თვითონ crm_show_file.php ამოწმებს
        }

        if ($userId <= 0 || !self::isRestrictedUser($userId)) {
            return;
        }

        try {
            if (self::canListen($userId, $fileId, $ownerTypeId, $ownerId)) {
                return;
            }
        } catch (\Throwable $e) {
            // შეზღუდულ თანამშრომელს შემოწმების შეცდომისას ჩანაწერს არ ვაძლევთ
        }

        self::deny();
    }

    private static $restrictedCache = [];

    /** ჯგუფ 26-შია და ადმინი არ არის */
    public static function isRestrictedUser($userId)
    {
        $userId = (int)$userId;
        if (!isset(self::$restrictedCache[$userId])) {
            $groups = array_map('intval', (array)\CUser::GetUserGroup($userId));
            self::$restrictedCache[$userId] = in_array(self::GROUP_ID, $groups, true)
                && !in_array(self::ADMIN_GROUP_ID, $groups, true);
        }
        return self::$restrictedCache[$userId];
    }

    /**
     * timeline-ის ზარის ბლოკი: შეზღუდულ თანამშრომელს ჩანაწერი სხვის ზარზე აქვს?
     * $model - \Bitrix\Crm\Service\Timeline\Item\Model (ზარის ქმედება)
     */
    public static function isTimelineRecordDenied($userId, $model)
    {
        $userId = (int)$userId;
        if ($userId <= 0 || !self::isRestrictedUser($userId)) {
            return false;
        }

        $activity = $model->getAssociatedEntityModel();
        if (!$activity) {
            return false;
        }
        $activityId = (int)$activity->get('ID');

        $fileIds = [];
        $raw = $activity->get('STORAGE_ELEMENT_IDS');
        if (is_array($raw)) {
            $fileIds = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $ids = unserialize($raw, ['allowed_classes' => false]);
            $fileIds = is_array($ids) ? $ids : [];
        }
        $mediaInfo = $activity->get('MEDIA_FILE_INFO');
        $hasMedia = is_array($mediaInfo) && !empty($mediaInfo['URL']);

        if (empty($fileIds)) {
            if (!$hasMedia) {
                return false; // ზარს ჩანაწერი არ აქვს
            }
            $fileIds = [0]; // მხოლოდ MEDIA_FILE_INFO - პატრონი ქმედებით
        }

        foreach ($fileIds as $fileId) {
            $ownerId = self::recordOwnerId((int)$fileId, self::OWNER_TYPE_ACTIVITY, $activityId);
            if ($ownerId !== null && $ownerId !== $userId) {
                return true;
            }
        }
        return false;
    }

    /** ფაილი არ არის ზარის ჩანაწერი, ან ჩანაწერი ამ თანამშრომლის ზარისაა */
    public static function canListen($userId, $fileId, $ownerTypeId, $ownerId)
    {
        $recordOwnerId = self::recordOwnerId((int)$fileId, (int)$ownerTypeId, (int)$ownerId);
        return $recordOwnerId === null || $recordOwnerId === (int)$userId;
    }

    /**
     * ზარის თანამშრომელი (ვინც ისაუბრა). null - ფაილი ზარის ჩანაწერი არ არის.
     * $fileId - Disk-ის ობიექტის ID (crm_show_file.php-ის fileId).
     */
    public static function recordOwnerId($fileId, $ownerTypeId, $ownerId)
    {
        $connection = Application::getConnection();

        $call = false;
        if ($fileId > 0) {
            $call = $connection->query(
                'SELECT PORTAL_USER_ID FROM b_voximplant_statistic WHERE CALL_WEBDAV_ID = ' . (int)$fileId
                . ' ORDER BY ID DESC LIMIT 1'
            )->fetch();
        }
        if ($call && (int)$call['PORTAL_USER_ID'] > 0) {
            return (int)$call['PORTAL_USER_ID'];
        }

        if ($ownerTypeId === self::OWNER_TYPE_ACTIVITY && $ownerId > 0) {
            $activity = $connection->query(
                'SELECT TYPE_ID, PROVIDER_ID, RESPONSIBLE_ID FROM b_crm_act WHERE ID = ' . (int)$ownerId
            )->fetch();
            if ($activity
                && ((int)$activity['TYPE_ID'] === self::ACTIVITY_TYPE_CALL || $activity['PROVIDER_ID'] === 'VOXIMPLANT_CALL')
            ) {
                return (int)$activity['RESPONSIBLE_ID'];
            }
        }

        return $call ? 0 : null;
    }

    /** crm_show_file.php-ის მსგავსად: ?auth=<oauth token> (REST / მობილური) ან სესია */
    private static function currentUserId()
    {
        $authToken = (string)($_REQUEST['auth'] ?? '');
        if ($authToken !== '') {
            if (!Loader::includeModule('rest') || !Loader::includeModule('crm')) {
                return 0;
            }
            $authData = [];
            if (\CRestUtil::checkAuth($authToken, \CCrmRestService::SCOPE_NAME, $authData)) {
                return (int)($authData['user_id'] ?? 0);
            }
            return 0;
        }

        global $USER;
        return (is_object($USER) && $USER->IsAuthorized()) ? (int)$USER->GetID() : 0;
    }

    private static function deny()
    {
        \CHTTP::SetStatus('403 Forbidden');
        header('Content-Type: text/html; charset=UTF-8');
        echo self::MESSAGE;
        die();
    }
}

if (MonolithCallRecordAccess::isTargetScript()) {
    AddEventHandler('main', 'OnBeforeProlog', [MonolithCallRecordAccess::class, 'onBeforeProlog']);
}

// timeline-ის factory: crm მოდული თავის სერვისს აღარ დაარეგისტრირებს, თუ კოდი უკვე არსებობს
// (ServiceLocator::registerByModuleSettings). კლასები crm-ის მოთხოვნისას იტვირთება.
\Bitrix\Main\DI\ServiceLocator::getInstance()->addInstanceLazy('crm.timeline.factory.activityItem', [
    'constructor' => static function () {
        try {
            require_once __DIR__ . '/call_record_timeline.php';
            return new MonolithCallRecordTimelineFactory();
        } catch (\Throwable $e) {
            return new \Bitrix\Crm\Service\Timeline\Item\Factory\ConfigurableActivity();
        }
    },
]);
