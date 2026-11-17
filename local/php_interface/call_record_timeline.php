<?php
/**
 * ზარის ჩანაწერი CRM-ის timeline-ში (ვები და მობილური).
 *
 * ჯგუფ 26-ის თანამშრომელს სხვისი ზარის ბლოკში პლეერის, «ჩანაწერის ჩამოტვირთვის» და
 * ჩანაწერის ღილაკის ნაცვლად ეწერება «ამ ზარის ჩანაწერზე წვდომა არ გაქვთ».
 *
 * იტვირთება მხოლოდ მაშინ, როცა CRM timeline-ის factory-ს ითხოვს (crm მოდული უკვე ჩატვირთულია):
 * call_record_access.php ServiceLocator-ში crm.timeline.factory.activityItem-ს ამ factory-ით ანაცვლებს.
 * თავად ფაილს მაინც crm_show_file.php-ის შემოწმება იცავს (MonolithCallRecordAccess).
 */

use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item;
use Bitrix\Crm\Service\Timeline\Item\Activity\Call;
use Bitrix\Crm\Service\Timeline\Item\AssociatedEntityModel;
use Bitrix\Crm\Service\Timeline\Item\Factory\ConfigurableActivity;
use Bitrix\Crm\Service\Timeline\Item\Model;
use Bitrix\Crm\Service\Timeline\Layout;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\Text;

class MonolithCallRecordTimelineFactory extends ConfigurableActivity
{
    public static function create(int $typeId, string $providerId, Context $context, Model $model): ?Item
    {
        if ($typeId === \CCrmActivityType::Call) {
            try {
                if (MonolithCallRecordAccess::isTimelineRecordDenied($context->getUserId(), $model)) {
                    return new MonolithCallRecordDeniedCall($context, MonolithCallRecordDeniedCall::withoutRecord($model));
                }
            } catch (\Throwable $e) {
                // შემოწმების შეცდომისას ჩვეულებრივი ბლოკი - ფაილს მაინც crm_show_file.php იცავს
            }
        }

        return parent::create($typeId, $providerId, $context, $model);
    }
}

class MonolithCallRecordDeniedCall extends Call
{
    const NOTICE = 'ამ ზარის ჩანაწერზე წვდომა არ გაქვთ';
    const BLOCK_ID = 'monolithRecordDenied';

    /** მოდელის ასლი ჩანაწერის გარეშე: Call-ი აღარ ხატავს პლეერს, ჩამოტვირთვას და ჩანაწერის AI-ღილაკებს */
    public static function withoutRecord(Model $model): Model
    {
        $fields = $model->getAssociatedEntityModel()?->toArray() ?? [];
        unset($fields['MEDIA_FILE_INFO']);
        $fields['STORAGE_ELEMENT_IDS'] = '';

        $copy = clone $model;
        $copy->setAssociatedEntityModel(AssociatedEntityModel::createFromArray($fields));

        return $copy;
    }

    protected function buildLayout(): Layout
    {
        $layout = parent::buildLayout();

        $notice = (new Text())
            ->setValue(self::NOTICE)
            ->setColor(Text::COLOR_BASE_70)
            ->setFontSize(Text::FONT_SIZE_SM);

        $body = $layout->getBody();
        if (!$body) {
            $body = new Layout\Body();
            $layout->setBody($body);
        }

        // ტექსტი ზარის ინფოს (ვები: callGroupOfBlocks, მობილური: callInfo) ან კლიენტის მერე
        $blocks = $body->getContentBlocks();
        $anchor = null;
        foreach (['callGroupOfBlocks', 'callInfo', 'client'] as $key) {
            if (isset($blocks[$key])) {
                $anchor = $key;
                break;
            }
        }

        $result = [];
        foreach ($blocks as $id => $block) {
            $result[$id] = $block;
            if ($id === $anchor) {
                $result[self::BLOCK_ID] = $notice;
            }
        }
        if ($anchor === null) {
            $result[self::BLOCK_ID] = $notice;
        }
        $body->setContentBlocks($result);

        return $layout;
    }
}
