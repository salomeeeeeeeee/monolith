<?php
/**
 * BP "ხელშეკრულების ვადის გაგრძელება" - შაბლონის სტრუქტურა, როგორც პორტალზე შეიქმნა (2026-10-15).
 * ტემპლეიტი DB-ში ცხოვრობს; ეს ფაილი დოკუმენტაციაა და ხელახლა შესაქმნელად php_command_line-ში
 * CBPWorkflowTemplateLoader::Add($fields, true) გამოიძახება (systemImport, თორემ IMNotify-ის Sender არ ვალიდირდება).
 *
 * ნაკადი: saveExtendContract.php -> BP (პარამეტრები: axaliTarigi, komentari, dzivelitarigi)
 *   1) timeline კომენტარი სეილის სახელით (მოთხოვნა გაგზავნილია)
 *   2) შეტყობინება ნუკის (user_9)
 *   3) ნუკის დასტური / უარყოფა
 *      დასტური -> UF_CRM_CONTRACT_DEADLINE = ახალი თარიღი, შეტყობინება სეილს, timeline კომენტარი
 *      უარყოფა -> შეტყობინება სეილს, timeline კომენტარი მიზეზით
 * ვადას agent-ი (local/php_interface/contract_deadline.php) ამ ველიდან კითხულობს.
 */

$dealLink = 'https://crm.monolith.ge/crm/deal/details/{=Document:ID}/';

$template = [[
    'Type'       => 'SequentialWorkflowActivity',
    'Name'       => 'Template',
    'Properties' => ['Title' => 'ხელშეკრულების ვადის გაგრძელება', 'Permission' => []],
    'Activated'  => 'Y',
    'Children'   => [
        [
            'Type'       => 'CrmTimelineCommentAdd',
            'Name'       => 'A91015_10',
            'Properties' => [
                'CommentText' => "გაგზავნილია ხელშეკრულების ვადის გაგრძელების მოთხოვნა\nმიმდინარე ვადა: {=Template:dzivelitarigi > printable}\nახალი ვადა: {=Template:axaliTarigi > printable}\nკომენტარი: {=Template:komentari > printable}",
                'CommentUser' => ['{=User:ID}'],
                'Title'       => 'Add comment to item',
            ],
            'Activated'  => 'Y',
        ],
        [
            'Type'       => 'IMNotifyActivity',
            'Name'       => 'A91015_20',
            'Properties' => [
                'MessageSite'     => "თქვენ მიიღეთ ხელშეკრულების ვადის გაგრძელების მოთხოვნა\nმიმდინარე ვადა: {=Template:dzivelitarigi > printable}\nახალი ვადა: {=Template:axaliTarigi > printable}\nკომენტარი: {=Template:komentari > printable}\nგარიგება: " . $dealLink,
                'MessageOut'      => '',
                'MessageType'     => 4,
                'MessageUserFrom' => ['{=Document:ASSIGNED_BY_ID}'],
                'MessageUserTo'   => ['user_9'],
                'Title'           => 'User notification',
            ],
            'Activated'  => 'Y',
        ],
        [
            'Type'       => 'ApproveActivity',
            'Name'       => 'A91015_30',
            'Properties' => [
                'ApproveType'         => 'any',
                'OverdueDate'         => '',
                'ApproveMinPercent'   => 50,
                'ApproveWaitForAll'   => 'N',
                'Name'                => 'გთხოვთ განიხილოთ ხელშეკრულების ვადის გაგრძელება',
                'Description'         => "მიმდინარე ვადა: {=Template:dzivelitarigi > printable}\nახალი ვადა: {=Template:axaliTarigi > printable}\nკომენტარი: {=Template:komentari > printable}\nგარიგება: " . $dealLink,
                'Parameters'          => '',
                'StatusMessage'       => 'Participated #PERCENT#% (#VOTED# of #TOTAL#)',
                'SetStatusMessage'    => 'Y',
                'TimeoutDuration'     => '',
                'TimeoutDurationType' => 's',
                'TaskButton1Message'  => 'დასტური',
                'TaskButton2Message'  => 'უარყოფა',
                'CommentLabelMessage' => 'კომენტარი',
                'ShowComment'         => 'Y',
                'CommentRequired'     => 'N',
                'AccessControl'       => 'N',
                'DelegationType'      => 0,
                'Users'               => ['user_9'],
                'Title'               => 'ხელშეკრულების ვადის გაგრძელება',
            ],
            'Activated'  => 'Y',
            'Children'   => [
                [
                    'Type'       => 'SequenceActivity',
                    'Name'       => 'A91015_31',
                    'Properties' => ['Title' => 'Sequence of actions'],
                    'Activated'  => 'Y',
                    'Children'   => [
                        [
                            'Type'       => 'SetFieldActivity',
                            'Name'       => 'A91015_32',
                            'Properties' => [
                                'FieldValue'          => ['UF_CRM_CONTRACT_DEADLINE' => '{=Template:axaliTarigi}'],
                                'ModifiedBy'          => [],
                                'MergeMultipleFields' => 'N',
                                'Title'               => 'Modify element',
                            ],
                            'Activated'  => 'Y',
                        ],
                        [
                            'Type'       => 'IMNotifyActivity',
                            'Name'       => 'A91015_33',
                            'Properties' => [
                                'MessageSite'     => "ხელშეკრულების ვადის გაგრძელება დადასტურებულია. ახალი ვადა: {=Template:axaliTarigi > printable}\nგარიგება: " . $dealLink,
                                'MessageOut'      => '',
                                'MessageType'     => 2,
                                'MessageUserFrom' => ['user_9'],
                                'MessageUserTo'   => ['{=Document:ASSIGNED_BY_ID}'],
                                'Title'           => 'User notification',
                            ],
                            'Activated'  => 'Y',
                        ],
                        [
                            'Type'       => 'CrmTimelineCommentAdd',
                            'Name'       => 'A91015_34',
                            'Properties' => [
                                'CommentText' => "სისტემური კომენტარი\nხელშეკრულების ვადის გაგრძელება დადასტურებულია\nახალი ვადა: {=Template:axaliTarigi > printable}\nკომენტარი: {=A91015_30:LastApproverComment}",
                                'CommentUser' => ['user_9'],
                                'Title'       => 'Add comment to item',
                            ],
                            'Activated'  => 'Y',
                        ],
                    ],
                ],
                [
                    'Type'       => 'SequenceActivity',
                    'Name'       => 'A91015_41',
                    'Properties' => ['Title' => 'Sequence of actions'],
                    'Activated'  => 'Y',
                    'Children'   => [
                        [
                            'Type'       => 'IMNotifyActivity',
                            'Name'       => 'A91015_42',
                            'Properties' => [
                                'MessageSite'     => "ხელშეკრულების ვადის გაგრძელება უარყოფილია.\nგარიგება: " . $dealLink,
                                'MessageOut'      => '',
                                'MessageType'     => 2,
                                'MessageUserFrom' => ['user_9'],
                                'MessageUserTo'   => ['{=Document:ASSIGNED_BY_ID}'],
                                'Title'           => 'User notification',
                            ],
                            'Activated'  => 'Y',
                        ],
                        [
                            'Type'       => 'CrmTimelineCommentAdd',
                            'Name'       => 'A91015_43',
                            'Properties' => [
                                'CommentText' => "სისტემური კომენტარი\nხელშეკრულების ვადის გაგრძელების მოთხოვნა უარყოფილია\nმიზეზი: {=A91015_30:LastApproverComment}",
                                'CommentUser' => ['user_9'],
                                'Title'       => 'Add comment to item',
                            ],
                            'Activated'  => 'Y',
                        ],
                    ],
                ],
            ],
        ],
    ],
]];

$parameters = [
    'axaliTarigi'   => ['Name' => 'ახალი ვადა', 'Description' => '', 'Type' => 'UF:date', 'Required' => '0', 'Multiple' => '0', 'Options' => '', 'Default' => ''],
    'komentari'     => ['Name' => 'კომენტარი', 'Description' => '', 'Type' => 'string', 'Required' => '0', 'Multiple' => '0', 'Options' => '', 'Default' => ''],
    'dzivelitarigi' => ['Name' => 'მიმდინარე ვადა', 'Description' => '', 'Type' => 'string', 'Required' => '0', 'Multiple' => '0', 'Options' => '', 'Default' => ''],
];

$fields = [
    // Add() თვითონ შლის მასივს MODULE_ID / ENTITY / DOCUMENT_TYPE-ად; სტრიქონი "DEAL" შეცდომას იძლევა ("documentId is undefined")
    'DOCUMENT_TYPE' => ['crm', 'CCrmDocumentDeal', 'DEAL'],
    'AUTO_EXECUTE'  => 0,
    'NAME'          => 'ხელშეკრულების ვადის გაგრძელება',
    'DESCRIPTION'   => 'სეილის მოთხოვნა ხელშეკრულების ეტაპის ვადის გაგრძელებაზე; ადასტურებს ნუკი (user 9). იწყებს /rest/local/api/projects/saveExtendContract.php',
    'TEMPLATE'      => $template,
    'PARAMETERS'    => $parameters,
    'VARIABLES'     => [],
    'CONSTANTS'     => [],
    'USER_ID'       => 1,
];

return $fields;
