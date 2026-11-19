<?php
/**
 * BP "SMS-ის გაგზავნა" - შაბლონის სტრუქტურა, როგორც პორტალზე შეიქმნა (2026-11-19).
 * ტემპლეიტი DB-ში ცხოვრობს; ეს ფაილი დოკუმენტაციაა და ხელახლა შესაქმნელად php_command_line-ში
 * CBPWorkflowTemplateLoader::Add($fields, true) გამოიძახება (systemImport - PHP კოდის აქტივობის გამო).
 *
 * დილზე ხელით გაშვება: პარამეტრები ნომერი + ტექსტი
 *   1) PHP კოდი: monolithSendSms() (local/php_interface/inex_sms.php, Inex, გამგზავნი MONOLITH)
 *   2) timeline კომენტარი გამშვების სახელით: გაიგზავნა / ვერ გაიგზავნა + მიზეზი, ნომერი, ტექსტი
 */

$code = <<<'PHP'
require_once \Bitrix\Main\Application::getDocumentRoot() . '/local/php_interface/inex_sms.php';
$root = $this->GetRootActivity();
$phone = (string)$root->nomeri;
$sms = monolithSendSms($phone, (string)$root->teksti);
$root->SetVariable('smsNomeri', $sms['phone'] !== '' ? $sms['phone'] : $phone);
$root->SetVariable('smsShedegi', $sms['ok'] ? 'გაიგზავნა' : 'ვერ გაიგზავნა - ' . $sms['error']);
$this->WriteToTrackingService('Inex: ' . ($sms['ok'] ? 'OK ' . $sms['id'] : $sms['error']));
PHP;

$template = [[
    'Type'       => 'SequentialWorkflowActivity',
    'Name'       => 'Template',
    'Properties' => ['Title' => 'SMS-ის გაგზავნა', 'Permission' => []],
    'Activated'  => 'Y',
    'Children'   => [
        [
            'Type'       => 'CodeActivity',
            'Name'       => 'A11119_10',
            'Properties' => ['ExecuteCode' => $code, 'Title' => 'SMS-ის გაგზავნა (Inex)'],
            'Activated'  => 'Y',
        ],
        [
            'Type'       => 'CrmTimelineCommentAdd',
            'Name'       => 'A11119_20',
            'Properties' => [
                'CommentText' => "SMS {=Variable:smsShedegi}\nნომერი: {=Variable:smsNomeri}\nტექსტი: {=Template:teksti}",
                'CommentUser' => ['{=Template:TargetUser}'],
                'Title'       => 'Add comment to item',
            ],
            'Activated'  => 'Y',
        ],
    ],
]];

$parameters = [
    'nomeri' => ['Name' => 'ნომერი', 'Description' => 'მაგ. 555 12 34 56', 'Type' => 'string', 'Required' => '1', 'Multiple' => '0', 'Options' => '', 'Default' => ''],
    'teksti' => ['Name' => 'ტექსტი', 'Description' => '', 'Type' => 'text', 'Required' => '1', 'Multiple' => '0', 'Options' => '', 'Default' => ''],
];

$variables = [
    'smsShedegi' => ['Name' => 'შედეგი', 'Description' => '', 'Type' => 'string', 'Required' => '0', 'Multiple' => '0', 'Options' => '', 'Default' => ''],
    'smsNomeri'  => ['Name' => 'ნომერი (995...)', 'Description' => '', 'Type' => 'string', 'Required' => '0', 'Multiple' => '0', 'Options' => '', 'Default' => ''],
];

$fields = [
    'DOCUMENT_TYPE' => ['crm', 'CCrmDocumentDeal', 'DEAL'],
    'AUTO_EXECUTE'  => 0,
    'NAME'          => 'SMS-ის გაგზავნა',
    'DESCRIPTION'   => 'ჩაწერეთ ნომერი და ტექსტი - SMS გაიგზავნება გამგზავნით MONOLITH, შედეგი დილის ისტორიაში კომენტარად ჩაიწერება',
    'TEMPLATE'      => $template,
    'PARAMETERS'    => $parameters,
    'VARIABLES'     => $variables,
    'CONSTANTS'     => [],
    'USER_ID'       => 1,
];

return $fields;
