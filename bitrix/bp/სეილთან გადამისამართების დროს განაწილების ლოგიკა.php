<?php

if (!\Bitrix\Main\Loader::includeModule('crm')) {
    $this->WriteToTrackingService('CRM module is not available');
    return;
}

$root = $this->GetRootActivity();

// BP ცვლადები ხშირადაა "user_12" / მასივი — ვიღებთ მხოლოდ რიცხვს
$parseId = static function ($value): int {
    if (is_array($value)) {
        $value = reset($value);
    }
    return (int)preg_replace('/\D+/', '', (string)$value);
};

$dealId  = $parseId($root->GetVariable('dealID'));
$groupId = $parseId($root->GetVariable('GroupID'));
$adminId = $parseId($root->GetVariable('AdminID')); // BP name: boloosVisacUndaDaewerosImIUzerisID
$targetStage = trim((string)$root->GetVariable('TargetStageID'));
if ($targetStage === '') {
    $targetStage = 'NEW';
}
// pipeline prefix მოშორება თუ არის (C12:NEW)
if (strpos($targetStage, ':') !== false) {
    $targetStage = explode(':', $targetStage)[1];
}

if ($dealId <= 0) {
    $documentId = $this->GetDocumentId();
    if (is_array($documentId) && !empty($documentId[2])) {
        $dealId = $parseId($documentId[2]);
    }
}

if ($dealId <= 0 || $groupId <= 0) {
    $root->SetVariable('StopLoop', 'Y');
    $root->WriteToTrackingService(
        'dealID ან GroupID არასწორია. dealID=' . $dealId . ' GroupID=' . $groupId
    );
    return;
}

try {
    $deal = \CCrmDeal::GetByID($dealId, false);
    if (!$deal) {
        $root->SetVariable('StopLoop', 'Y');
        $root->WriteToTrackingService('Deal not found: ' . $dealId);
        return;
    }

    $rawStage = (string)$deal['STAGE_ID'];
    $stageCode = (strpos($rawStage, ':') !== false)
        ? explode(':', $rawStage)[1]
        : $rawStage;

    if ($stageCode !== $targetStage) {
        $root->SetVariable('StopLoop', 'Y');
        $root->WriteToTrackingService(
            'Stage changed by manager. Loop stop. raw=' . $rawStage
            . ' code=' . $stageCode . ' target=' . $targetStage
        );
        return;
    }

    $historyRaw = (string)$root->GetVariable('ReassignHistory');
    $usedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $historyRaw)))));

    $currentResponsible = (int)$deal['ASSIGNED_BY_ID'];
    if ($currentResponsible > 0 && !in_array($currentResponsible, $usedIds, true)) {
        $usedIds[] = $currentResponsible;
    }

    // საიმედო გზა ჯგუფის წევრებისთვის (GetList + GROUPS_ID ხშირად ცარიელს აბრუნებს)
    $groupUserIds = [];
    $rawGroupUsers = \CGroup::GetGroupUser($groupId);
    if (is_array($rawGroupUsers)) {
        foreach ($rawGroupUsers as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0) {
                continue;
            }
            $user = \CUser::GetByID($uid)->Fetch();
            if ($user && ($user['ACTIVE'] ?? 'N') === 'Y') {
                $groupUserIds[] = $uid;
            }
        }
    }
    $groupUserIds = array_values(array_unique($groupUserIds));

    $candidateIds = array_values(array_diff($groupUserIds, $usedIds));

    $root->WriteToTrackingService(
        'Debug: deal=' . $dealId
        . ' group=' . $groupId
        . ' admin=' . $adminId
        . ' stage=' . $rawStage
        . ' current=' . $currentResponsible
        . ' groupUsers=[' . implode(',', $groupUserIds) . ']'
        . ' used=[' . implode(',', $usedIds) . ']'
        . ' candidates=[' . implode(',', $candidateIds) . ']'
    );

    if (empty($groupUserIds)) {
        $root->SetVariable('StopLoop', 'Y');
        $root->WriteToTrackingService('Group #' . $groupId . ' ცარიელია ან იუზერები არ არის აქტიური.');
        return;
    }

    // 8 იუზერი ამოიწურა → ადმინს კომენტარი/ნოტიფი და ლუპის გაჩერება
    if (empty($candidateIds)) {
        $root->SetVariable('ReassignHistory', implode(',', $usedIds));
        $root->SetVariable('StopLoop', 'Y');

        $commentText = 'ავტომატური გადანაწილება დასრულდა. ჯგუფის ყველა წევრი უკვე გამოყენებულია.'
            . ' History: ' . implode(',', $usedIds);

        if ($adminId > 0) {
            $commentId = 0;
            $notifyOk = false;
            $assignOk = false;
            $errors = [];

            // ბოლოს ადმინზე გადაწერა
            if ($currentResponsible !== $adminId) {
                $dealEntity = new \CCrmDeal(false);
                $assignFields = ['ASSIGNED_BY_ID' => $adminId];
                $assignOk = (bool)$dealEntity->Update(
                    $dealId,
                    $assignFields,
                    true,
                    true,
                    [
                        'REGISTER_SONET_EVENT' => true,
                        'ENABLE_SYSTEM_EVENT_ADD' => true,
                    ]
                );
                if (!$assignOk) {
                    $errors[] = 'Assign failed: ' . $dealEntity->LAST_ERROR;
                }
            } else {
                $assignOk = true;
            }

            // ტაიმლაინის კომენტარი + მენტშენი ადმინზე (ნოტიფისთვის)
            $adminName = 'Admin';
            $adminRow = \CUser::GetByID($adminId)->Fetch();
            if (is_array($adminRow)) {
                $adminName = trim(($adminRow['NAME'] ?? '') . ' ' . ($adminRow['LAST_NAME'] ?? ''));
                if ($adminName === '') {
                    $adminName = (string)($adminRow['LOGIN'] ?? 'Admin');
                }
            }
            $timelineText = '[USER=' . $adminId . ']' . $adminName . '[/USER], ' . $commentText;

            if (class_exists('\Bitrix\Crm\Timeline\CommentEntry')) {
                try {
                    $commentId = (int)\Bitrix\Crm\Timeline\CommentEntry::create([
                        'TEXT' => $timelineText,
                        'AUTHOR_ID' => $adminId,
                        'BINDINGS' => [
                            [
                                'ENTITY_TYPE_ID' => \CCrmOwnerType::Deal,
                                'ENTITY_ID' => $dealId,
                            ],
                        ],
                    ]);
                } catch (\Throwable $commentError) {
                    $errors[] = 'CommentEntry: ' . $commentError->getMessage();
                }
            } else {
                $errors[] = 'CommentEntry class missing';
            }

            // fallback: ძველი CRM event კომენტარი
            if ($commentId <= 0 && class_exists('\CCrmEvent')) {
                $event = new \CCrmEvent();
                $eventId = (int)$event->Add([
                    'ENTITY_TYPE' => \CCrmOwnerType::DealName,
                    'ENTITY_ID' => $dealId,
                    'EVENT_TYPE' => 1,
                    'EVENT_NAME' => 'Auto redistribution finished',
                    'EVENT_TEXT_1' => $commentText,
                    'USER_ID' => $adminId,
                ], false);
                if ($eventId > 0) {
                    $commentId = $eventId;
                } else {
                    $errors[] = 'CCrmEvent failed';
                }
            }

            // პირდაპირი IM შეტყობინება ადმინს
            if (\Bitrix\Main\Loader::includeModule('im')) {
                $dealUrl = '/crm/deal/details/' . $dealId . '/';
                $notifyId = \CIMNotify::Add([
                    'TO_USER_ID' => $adminId,
                    'FROM_USER_ID' => $adminId,
                    'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
                    'NOTIFY_MODULE' => 'crm',
                    'NOTIFY_EVENT' => 'activity',
                    'NOTIFY_TAG' => 'CRM_DEAL_REDIST_' . $dealId,
                    'NOTIFY_MESSAGE' => $commentText . ' <a href="' . $dealUrl . '">Deal #' . $dealId . '</a>',
                    'NOTIFY_MESSAGE_OUT' => $commentText . ' Deal #' . $dealId,
                ]);
                $notifyOk = (int)$notifyId > 0;
                if (!$notifyOk) {
                    $errors[] = 'CIMNotify failed';
                }
            } else {
                $errors[] = 'im module missing';
            }

            $root->WriteToTrackingService(
                'No candidates left. Admin #' . $adminId
                . ' assign=' . ($assignOk ? 'Y' : 'N')
                . ' comment=' . $commentId
                . ' notify=' . ($notifyOk ? 'Y' : 'N')
                . ($errors ? (' errors=' . implode('; ', $errors)) : '')
            );
        } else {
            $root->WriteToTrackingService('No candidates left. AdminID ცარიელია — კომენტარი ვერ დაიწერა.');
        }

        return;
    }

    $nextResponsible = $candidateIds[array_rand($candidateIds)];

    $dealEntity = new \CCrmDeal(false);
    $fields = ['ASSIGNED_BY_ID' => $nextResponsible];
    $ok = $dealEntity->Update(
        $dealId,
        $fields,
        true,
        true,
        [
            'REGISTER_SONET_EVENT' => false,
            'ENABLE_SYSTEM_EVENT_ADD' => false,
        ]
    );

    if (!$ok) {
        $root->SetVariable('StopLoop', 'Y');
        $root->WriteToTrackingService('Deal update failed: ' . $dealEntity->LAST_ERROR);
        return;
    }

    $usedIds[] = $nextResponsible;
    $usedIds = array_values(array_unique($usedIds));

    $root->SetVariable('ReassignHistory', implode(',', $usedIds));
    $root->SetVariable('StopLoop', 'N');

    $root->WriteToTrackingService(
        'Deal reassigned: ' . $currentResponsible . ' -> ' . $nextResponsible
        . '. History: ' . implode(',', $usedIds)
    );
} catch (\Throwable $e) {
    $root->SetVariable('StopLoop', 'Y');
    $root->WriteToTrackingService('PHP error: ' . $e->getMessage());
}
