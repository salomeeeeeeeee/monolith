<?php
/**
 * გაყიდული (WON) დილი სტადიიდან ვეღარ გადადის - ცვლის მხოლოდ ადმინი (ჯგუფი 1).
 *
 * სტადია ბევრი ადგილიდან იცვლება: ბარათის progress bar, კანბანი, სია, REST, BP.
 * ყველა მათგანი გადის OnBeforeCrmDealUpdate-ზე (ძველი CCrmDeal::Update-ც და factory-ც),
 * ამიტომ აქ ვამოწმებთ: თუ ბაზაში დილი WON-ზეა და ახალი STAGE_ID სხვაა, ცვლილებას ვაუქმებთ.
 * ბარათზე progress bar-ის ვიზუალური ჩაკეტვა crm.entity.editor-ის template-შია.
 */

use Bitrix\Crm\DealTable;
use Bitrix\Main\Loader;

class MonolithWonStageLock
{
    const MESSAGE = 'გაყიდული დილის სტადიას ვერ შეცვლით. ცვლილება შეუძლია მხოლოდ ადმინისტრატორს.';

    public static function onBeforeDealUpdate(&$fields)
    {
        try {
            $dealId = (int)($fields['ID'] ?? 0);
            $newStage = (string)($fields['STAGE_ID'] ?? '');
            if ($dealId <= 0 || $newStage === '' || !Loader::includeModule('crm')) {
                return true;
            }

            $row = DealTable::getList([
                'select' => ['STAGE_ID'],
                'filter' => ['=ID' => $dealId],
            ])->fetch();
            $currentStage = (string)($row['STAGE_ID'] ?? '');

            if (!self::isWonStage($currentStage) || $newStage === $currentStage || self::canMoveWonDeal()) {
                return true;
            }

            $fields['RESULT_MESSAGE'] = self::MESSAGE;
            return false;
        } catch (\Throwable $e) {
            // შემოწმების შეცდომამ დილის შენახვა არ უნდა შეაჩეროს
            return true;
        }
    }

    /** WON default pipeline-ში, C1:WON / C2:WON ... სხვებში */
    public static function isWonStage($stageId)
    {
        return (bool)preg_match('/(^|:)WON$/', (string)$stageId);
    }

    public static function canMoveWonDeal()
    {
        global $USER;
        return is_object($USER) && $USER->IsAuthorized() && $USER->IsAdmin();
    }
}

AddEventHandler('crm', 'OnBeforeCrmDealUpdate', [MonolithWonStageLock::class, 'onBeforeDealUpdate']);
