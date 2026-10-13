<?php
/**
 * ბინის გარეშე დილი რეზერვაციის, ხელშეკრულების და გაყიდულის სტადიებზე ვერ გადადის.
 *
 * ბინა დილს კატალოგიდან ემატება (deal product rows). სტადია ბევრი ადგილიდან იცვლება: ბარათი, კანბანი,
 * სია, REST, BP - ყველა გადის OnBeforeCrmDealUpdate-ზე, ამიტომ აქ ვამოწმებთ: თუ დილი ამ სტადიებიდან
 * ერთ-ერთზე გადადის და ბინა არ აქვს მიბმული, ცვლილებას ვაუქმებთ. უკან (დამუშავება, junk) გადაყვანა თავისუფალია.
 * ამ სტადიებზე გარედან შესვლისას ბინა სხვა დილს არ უნდა ეკავოს (apartmentsHeldByOthers).
 * იგივე შემოწმებებს იყენებს saveReservation.php (ჯავშანი) და saveApartment.php (ბინის მოხსნა / შენახვა).
 */

use Bitrix\Crm\DealTable;
use Bitrix\Main\Loader;

class MonolithApartmentStageGuard
{
    const MESSAGE = 'დილზე ბინა არ არის მიბმული. რეზერვაციაზე გადასაყვანად ჯერ კატალოგიდან მიაბით ბინა.';

    /** მოთხოვნილი რეზერვაცია, დადასტურებული რეზერვაცია, ხელშეკრულება, დადასტურებული ხელშეკრულება, გადახდის მოლოდინი, გაყიდული */
    const STAGES = ['PREPAYMENT_INVOICE', 'FINAL_INVOICE', 'EXECUTING', 'UC_NSTB3H', 'UC_NJ7A78', 'WON'];

    public static function onBeforeDealUpdate(&$fields)
    {
        try {
            $dealId = (int)($fields['ID'] ?? 0);
            $newStage = (string)($fields['STAGE_ID'] ?? '');
            if ($dealId <= 0 || !self::requiresApartment($newStage) || !Loader::includeModule('crm')) {
                return true;
            }

            $row = DealTable::getList([
                'select' => ['STAGE_ID'],
                'filter' => ['=ID' => $dealId],
            ])->fetch();
            if ((string)($row['STAGE_ID'] ?? '') === $newStage) {
                return true;
            }

            // ბინა შეიძლება იმავე შენახვაში მოდიოდეს (PRODUCT_ROWS)
            $incomingRows = (isset($fields['PRODUCT_ROWS']) && is_array($fields['PRODUCT_ROWS'])) ? $fields['PRODUCT_ROWS'] : [];
            $rows = self::rowsHaveApartment($incomingRows) ? $incomingRows : (\CCrmDeal::LoadProductRows($dealId) ?: []);
            if (!self::rowsHaveApartment($rows)) {
                $fields['RESULT_MESSAGE'] = self::MESSAGE;
                return false;
            }

            // დამუშავებიდან რეზერვაციაზე შესვლისას ბინა სხვა დილს არ უნდა ეკავოს
            if (!self::requiresApartment($row['STAGE_ID'] ?? '')) {
                $busy = self::apartmentsHeldByOthers($dealId, $rows);
                if ($busy) {
                    $fields['RESULT_MESSAGE'] = self::busyMessage($busy);
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            // შემოწმების შეცდომამ დილის შენახვა არ უნდა შეაჩეროს
            return true;
        }
    }

    public static function requiresApartment($stageId)
    {
        return in_array((string)$stageId, self::STAGES, true);
    }

    public static function dealHasApartment($dealId)
    {
        $dealId = (int)$dealId;
        if ($dealId <= 0 || !Loader::includeModule('crm')) {
            return false;
        }
        return self::rowsHaveApartment(\CCrmDeal::LoadProductRows($dealId) ?: []);
    }

    public static function rowsHaveApartment($rows)
    {
        foreach ($rows as $row) {
            if ((int)($row['PRODUCT_ID'] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * დილზე მიბმული ბინები, რომლებიც თავისუფალი აღარ არის და სხვა დილს უკავია: დილი დამუშავებაზე დაბრუნდა,
     * ბინა გათავისუფლდა, მაგრამ დილზე დარჩა და ამასობაში სხვამ დაჯავშნა. ასეთზე ჯავშანი არ უნდა წავიდეს -
     * BP 7 (statusChange) ბინის მფლობელს უბრალოდ გადააწერდა.
     */
    public static function apartmentsHeldByOthers($dealId, $rows = null)
    {
        $dealId = (int)$dealId;
        if ($dealId <= 0 || !Loader::includeModule('crm') || !Loader::includeModule('iblock')) {
            return [];
        }
        if ($rows === null) {
            $rows = \CCrmDeal::LoadProductRows($dealId) ?: [];
        }

        $busy = [];
        foreach ($rows as $row) {
            $productId = (int)($row['PRODUCT_ID'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $ob = \CIBlockElement::GetList([], ['ID' => $productId], false, false, ['ID', 'IBLOCK_ID', 'NAME'])->GetNextElement();
            if (!$ob) {
                continue;
            }
            $props = $ob->GetProperties();
            $status = (string)($props['_P64GYD']['VALUE'] ?? '');
            if ($status === 'თავისუფალი') {
                continue;
            }
            $owner = self::ownerDealId($props['OWNER_DEAL']['VALUE'] ?? '') ?: self::ownerDealId($props['ownerDeal']['VALUE'] ?? '');
            if ($owner === $dealId) {
                continue;
            }

            $number = (string)($props['__6KWOWZ']['VALUE'] ?? '');
            $busy[] = ($number !== '' ? 'N' . $number : $ob->GetFields()['NAME'])
                . ' (' . ($status !== '' ? $status : 'სტატუსი არ აქვს') . ($owner ? ', დილი #' . $owner : '') . ')';
        }
        return $busy;
    }

    public static function busyMessage($busy)
    {
        return 'ბინა უკვე სხვა დილზეა: ' . implode(', ', $busy) . '. ჯავშანი ვერ გაიგზავნება - ჯერ მოხსენით ეს ბინა დილიდან.';
    }

    /** OWNER_DEAL შეიძლება იყოს "123" ან "D_123" */
    private static function ownerDealId($value)
    {
        foreach ((array)$value as $v) {
            if (preg_match('/(\d+)\s*$/', (string)$v, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }
}

AddEventHandler('crm', 'OnBeforeCrmDealUpdate', [MonolithApartmentStageGuard::class, 'onBeforeDealUpdate']);
