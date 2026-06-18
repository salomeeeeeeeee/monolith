<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
use Bitrix\Main\Loader;
Loader::includeModule('crm');

header('Content-Type: application/json');

$dealId = intval($_GET['deal_id'] ?? 0);
if (!$dealId) {
    echo json_encode(['status' => 400, 'error' => 'No deal_id']);
    die();
}

// Get deal → find bound contact
$dealRes = CCrmDeal::GetList([], ['ID' => $dealId], ['ID', 'CONTACT_ID']);
$deal = $dealRes->Fetch();
if (!$deal || empty($deal['CONTACT_ID'])) {
    echo json_encode(['status' => 404, 'error' => 'No contact on deal']);
    die();
}

$contactId = intval($deal['CONTACT_ID']);
$contactRes = CCrmContact::GetList([], ['ID' => $contactId], [
    'ID', 'NAME', 'LAST_NAME', 'UF_CRM_1781244744534'
]);
$contact = $contactRes->Fetch();
if (!$contact) {
    echo json_encode(['status' => 404, 'error' => 'Contact not found']);
    die();
}

// Get phone
$phone = '';
$multiRes = CCrmFieldMulti::GetList(
    ['ID' => 'ASC'],
    ['ENTITY_ID' => 'CONTACT', 'ELEMENT_ID' => $contactId, 'TYPE_ID' => 'PHONE']
);
if ($row = $multiRes->Fetch()) {
    $phone = $row['VALUE'];
}
$contact['PHONE'] = $phone;

echo json_encode(['status' => 200, 'contact' => $contact]);
die();