<?php
/**
 * ბინის გარეშე დილი რეზერვაციის, ხელშეკრულების და გაყიდულის სტადიებზე ვერ გადადის.
 *
 * ბინა დილს კატალოგიდან ემატება (deal product rows). სტადია ბევრი ადგილიდან იცვლება: ბარათი, კანბანი,
 * სია, REST, BP - ყველა გადის OnBeforeCrmDealUpdate-ზე, ამიტომ აქ ვამოწმებთ: თუ დილი ამ სტადიებიდან
 * ერთ-ერთზე გადადის და ბინა არ აქვს მიბმული, ცვლილებას ვაუქმებთ. უკან (დამუშავება, junk) გადაყვანა თავისუფალია.
 * იგივე შემოწმებას იყენებს saveReservation.php (ჯავშანი) და saveApartment.php (ბინის მოხსნა).
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
            if (self::rowsHaveApartment($incomingRows) || self::dealHasApartment($dealId)) {
                return true;
            }

            $fields['RESULT_MESSAGE'] = self::MESSAGE;
            return false;
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
}

AddEventHandler('crm', 'OnBeforeCrmDealUpdate', [MonolithApartmentStageGuard::class, 'onBeforeDealUpdate']);
