<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Bitrix vars
 * @global CUser $USER
 * @global CMain $APPLICATION
 * @global CDatabase $DB
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponent $component
 * @var string $templateName
 * @var string $templateFile
 * @var string $templateFolder
 * @var string $componentPath
 */

use Bitrix\Crm;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Main;
use Bitrix\Main\Web\Json;

Main\UI\Extension::load("ui.label");
Main\UI\Extension::load("crm.entity-editor");
Main\UI\Extension::load("crm.entity-editor.field.requisite");
Main\UI\Extension::load("ui.icons.b24");

Bitrix\Main\Page\Asset::getInstance()->addJs('/bitrix/js/crm/activity.js');

if(Main\Loader::includeModule('calendar'))
{
	\Bitrix\Crm\Integration\Calendar::loadResourcebookingUserfieldExtention();
}

$guid = $arResult['GUID'];
$prefix = mb_strtolower($guid);
$containerID = "{$prefix}_container";
$buttonContainerID = "{$prefix}_buttons";
$createSectionButtonID = "{$prefix}_create_section";
$configMenuButtonID = '';
$configIconID = '';

if($arResult['REST_USE'])
{
	$restSectionButtonID = "{$prefix}_rest_section";
	$arResult['REST_PLACEMENT_TAB_CONFIG']['bottom_button_id'] = $restSectionButtonID;
}

$htmlEditorConfigs = [];
$htmlFieldNames = isset($arResult['ENTITY_HTML_FIELD_NAMES']) && is_array($arResult['ENTITY_HTML_FIELD_NAMES'])
	? $arResult['ENTITY_HTML_FIELD_NAMES']
	: []
;
$bbFieldNames = isset($arResult['ENTITY_BB_FIELD_NAMES']) && is_array($arResult['ENTITY_BB_FIELD_NAMES'])
	? $arResult['ENTITY_BB_FIELD_NAMES']
	: []
;

$hasBBCodeFields = isset($arResult['HAS_BBCODE_FIELDS']) && $arResult['HAS_BBCODE_FIELDS'] === true;
if ($hasBBCodeFields)
{
	Main\UI\Extension::load(['ui.text-editor', 'ui.bbcode.formatter.html-formatter']);
}

foreach ($htmlFieldNames as $fieldName)
{
	$fieldPrefix = $prefix.'_'.strtolower($fieldName);
	$htmlEditorConfigs[$fieldName] = [
		'id' => "{$fieldPrefix}_html_editor",
		'containerId' => "{$fieldPrefix}_html_editor_container",
		'bb' => false,
		'controlsMap' => [
			['id' => 'Bold',  'compact' => true, 'sort' => 10],
			['id' => 'Italic',  'compact' => true, 'sort' => 20],
			['id' => 'Underline',  'compact' => true, 'sort' => 30],
			['id' => 'Strikeout',  'compact' => true, 'sort' => 40],
			['id' => 'RemoveFormat',  'compact' => false, 'sort' => 50],
			['id' => 'Color',  'compact' => false, 'sort' => 60],
			['id' => 'FontSelector',  'compact' => false, 'sort' => 70],
			['id' => 'FontSize',  'compact' => true, 'sort' => 80],
			['separator' => true, 'compact' => false, 'sort' => 90],
			['id' => 'OrderedList',  'compact' => true, 'sort' => 100],
			['id' => 'UnorderedList',  'compact' => true, 'sort' => 110],
			['id' => 'AlignList', 'compact' => false, 'sort' => 120],
			['separator' => true, 'compact' => false, 'sort' => 130],
			['id' => 'InsertLink',  'compact' => true, 'sort' => 140],
			['id' => 'Code',  'compact' => false, 'sort' => 180],
			['id' => 'Quote',  'compact' => false, 'sort' => 190],
			['separator' => true, 'compact' => false, 'sort' => 200],
			['id' => 'Fullscreen',  'compact' => true, 'sort' => 210],
			['id' => 'More',  'compact' => true, 'sort' => 400]
		],
	];
}
foreach ($bbFieldNames as $fieldName)
{
	$fieldPrefix = $prefix.'_'.strtolower($fieldName);
	$htmlEditorConfigs[$fieldName] = [
		'id' => "{$fieldPrefix}_html_editor",
		'containerId' => "{$fieldPrefix}_html_editor_container",
		'bb' => true,
		// only allow tags that are supported in mobile app
		'controlsMap' => [
			['id' => 'Bold',  'compact' => true, 'sort' => 10],
			['id' => 'Italic',  'compact' => true, 'sort' => 20],
			['id' => 'Underline',  'compact' => true, 'sort' => 30],
			['id' => 'Strikeout',  'compact' => true, 'sort' => 40],
			['id' => 'RemoveFormat',  'compact' => false, 'sort' => 50],
			['separator' => true, 'compact' => false, 'sort' => 90],
			['id' => 'OrderedList',  'compact' => true, 'sort' => 100],
			['id' => 'UnorderedList',  'compact' => true, 'sort' => 110],
			['separator' => true, 'compact' => false, 'sort' => 130],
			['id' => 'InsertLink',  'compact' => true, 'sort' => 140],
			['separator' => true, 'compact' => false, 'sort' => 200],
			['id' => 'Fullscreen',  'compact' => true, 'sort' => 210],
		],
	];
}

// @todo: remove?
if (Main\Loader::includeModule('socialnetwork'))
{
	\CJSCore::init(array('socnetlogdest'));

	?><script>
		BX.ready(
			function()
			{
				BX.Crm.EntityEditorCrmSelector.contacts = {};
				BX.Crm.EntityEditorCrmSelector.contactsLast = {};

				BX.Crm.EntityEditorCrmSelector.companies = {};
				BX.Crm.EntityEditorCrmSelector.companiesLast = {};

				BX.Crm.EntityEditorCrmSelector.leads = {};
				BX.Crm.EntityEditorCrmSelector.leadsLast = {};

				BX.Crm.EntityEditorCrmSelector.deals = {};
				BX.Crm.EntityEditorCrmSelector.dealsLast = {};
			}
		);
	</script><?
}

?><div class="crm-entity-card-container-content" id="<?=htmlspecialcharsbx($containerID)?>"></div>
<div class="crm-entity-card-widget-add-btn-container" id="<?=htmlspecialcharsbx($buttonContainerID)?>" style="display:none;">
	<span id="<?=htmlspecialcharsbx($createSectionButtonID)?>" class="crm-entity-add-widget-link">
		<?=GetMessage('CRM_ENTITY_ED_CREATE_SECTION')?>
	</span><?
if($arResult['REST_USE'])
{
	?><span id="<?=htmlspecialcharsbx($restSectionButtonID)?>" class="crm-entity-add-app-link">
		<?=Crm\Integration\Market\Label::isRenamedMarket() ? GetMessage('CRM_ENTITY_ED_REST_SECTION_MSGVER_1') : GetMessage('CRM_ENTITY_ED_REST_SECTION_2')?>
	</span><?
}

if ($arResult['ENABLE_CONFIG_CONTROL'])
{
	$configMenuButtonID = "{$prefix}_config_menu";
	$configIconID = "{$prefix}_config_icon";

	$configIconClassName = $arResult['ENTITY_CONFIG_SCOPE'] === Crm\Entity\EntityEditorConfigScope::COMMON
		? 'crm-entity-card-common'
		: 'crm-entity-card-private';

	$configCaption = Crm\Entity\EntityEditorConfigScope::getCaption(
		$arResult['ENTITY_CONFIG_SCOPE'],
		$arResult['CONFIG_ID'],
		$arResult['USER_SCOPE_ID'],
		($arParams['MODULE_ID'] ?? null)
	);
	?><span id="<?=htmlspecialcharsbx($configIconID)?>" class="<?=$configIconClassName?>" title="<?=$configCaption?>">
	</span><?
	?><span id="<?=htmlspecialcharsbx($configMenuButtonID)?>" class="crm-entity-settings-link">
		<?=$configCaption?>
	</span><?php
}
?>
</div>
<?php
if (!empty($htmlEditorConfigs))
{
	foreach ($htmlEditorConfigs as $fieldName => $htmlEditorConfig)
	{
		$fieldInfo = $arResult['ENTITY_AVAILABLE_FIELDS_INFO'][$fieldName] ?? [];
		?>
		<div id="<?=htmlspecialcharsbx($htmlEditorConfig['containerId'])?>" style="display:none;">
			<?php
			$editorControlsMap = $htmlEditorConfig['controlsMap'];
			$parserList = [];

			if (is_array($arResult['DISABLED_HTML_CONTROLS']))
			{
				$editorControls = [];
				foreach ($editorControlsMap as $item)
				{
					$itemId = $item['id'] ?? false;
					$isSeparator = $item['separator'] ?? false;

					if ($itemId)
					{
						$parserList[] = $itemId;

						if (in_array($itemId, $arResult['DISABLED_HTML_CONTROLS'], true))
						{
							continue;
						}
					}

					if (
						$isSeparator
						&& isset($editorControls[array_key_last($editorControls)]['separator'])
					)
					{
						continue;
					}
					$editorControls[] = $item;
				}
			}
			else
			{
				$editorControls = $editorControlsMap;
			}

			$chtmlEditorParams = [
				'name' => $htmlEditorConfig['id'],
				'id' => $htmlEditorConfig['id'],
				'siteId' => SITE_ID,
				'width' => '100%',
				'minBodyWidth' => 230,
				'normalBodyWidth' => 530,
				'height' => 200,
				'minBodyHeight' => 200,
				'showTaskbars' => false,
				'showNodeNavi' => false,
				'autoResize' => true,
				'autoResizeOffset' => 10,
				'bbCode' => $htmlEditorConfig['bb'],
				'saveOnBlur' => false,
				'bAllowPhp' => false,
				'lazyLoad' => false,
				'limitPhpAccess' => false,
				'setFocusAfterShow' => false,
				'askBeforeUnloadPage' => false,
				'useFileDialogs' => false,
				'controlsMap' => $htmlEditorConfig['controlsMap'],
				'isMentionUnavailable' => $fieldInfo['copilotIntegrationParams']['isMentionUnavailable'] ?? false,
				'isCopilotTextEnabledBySettings' => AIManager::isEnabledInGlobalSettings(EventHandler::SETTINGS_FILL_CRM_TEXT_ENABLED_CODE),
				'copilotParams' => [
					'moduleId' => 'crm',
					'contextId' => 'crm_details_comment_editor_' . $htmlEditorConfig['id'],
					'category' => 'crm_comment_field',
					'autoHide' => true,
				],
			];

			$APPLICATION->IncludeComponent(
				'bitrix:main.post.form',
				'',
				[
					'PARSER' => $parserList,
					'BUTTONS' => $fieldInfo['buttons'] ?? [],
					'UPLOAD_FILE' => false,
					'LHE' => $chtmlEditorParams,
					'isAiImageEnabled' => $fieldInfo['postFormSettings']['isAiImageEnabled'] ?? false,
					'isDnDEnabled' => $fieldInfo['postFormSettings']['isDnDEnabled'] ?? false,
				],
				false,
				[
					"HIDE_ICONS" => "Y",
				]
			);
			?>
		</div>
		<?php
	}
}
?>

<?php if (!empty($arResult['BIZPROC_MANAGER_CONFIG'])):
	$arResult['BIZPROC_MANAGER_CONFIG']['containerId'] = "{$prefix}_bizproc_manager_container";
?><div id="<?= htmlspecialcharsbx($arResult['BIZPROC_MANAGER_CONFIG']['containerId']) ?>" style="display:none;"><?php
	\Bitrix\Main\UI\Extension::load(['bp_starter']);
	if (!class_exists(\Bitrix\Bizproc\Controller\Workflow\Starter::class))
	{
		$APPLICATION->IncludeComponent(
			"bitrix:bizproc.workflow.start",
			'modern',
			[
				'MODULE_ID' => $arResult['BIZPROC_MANAGER_CONFIG']['moduleId'],
				'ENTITY' => $arResult['BIZPROC_MANAGER_CONFIG']['entity'],
				'DOCUMENT_TYPE' => $arResult['BIZPROC_MANAGER_CONFIG']['documentType'],
				'AUTO_EXECUTE_TYPE' => $arResult['BIZPROC_MANAGER_CONFIG']['autoExecuteType'],
			],
		);
	}
	?></div>
<?php endif ?>
<script>
	BX.ready(
		function()
		{
			BX.message(<?=\Bitrix\Main\Web\Json::encode(Crm\Service\Container::getInstance()->getLocalization()->loadMessages())?>);

			BX.CrmEntityType.setCaptions(<?=CUtil::PhpToJSObject(CCrmOwnerType::GetJavascriptDescriptions())?>);
			BX.CrmEntityType.setNotFoundMessages(<?=CUtil::PhpToJSObject(CCrmOwnerType::GetNotFoundMessages())?>);
			<?php if (
				!empty($arResult['USERFIELD_TYPE_REST_CREATE_URL'])
				|| \Bitrix\Crm\Integration\Calendar::isResourceBookingAvailableForEntity($arResult['USER_FIELD_ENTITY_ID'])
			):?>
			BX.Event.EventEmitter.subscribe(
				'BX.UI.EntityUserFieldManager:getTypes',
				function(event)
				{
					var types = event.getData().types;
					if (!BX.Type.isArray(types))
					{
						return;
					}
					<?php if (\Bitrix\Crm\Integration\Calendar::isResourceBookingAvailableForEntity($arResult['USER_FIELD_ENTITY_ID'])):?>
						var index = 0;
						var length = types.length;
						for (; index < length; index++)
						{
							if (types[index].name === 'address')
							{
								break;
							}
						}
						types.splice(index, 0, {
							name: "resourcebooking",
							title: "<?=GetMessageJS('CRM_ENTITY_ED_UF_RESOURCEBOOKING_TITLE')?>",
							legend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_RESOURCEBOOKING_LEGEND')?>"
						});
					<?php endif;?>
					<?php if (!empty($arResult['USERFIELD_TYPE_REST_CREATE_URL'])):?>
						types.push({
							name: "rest_field_type",
							title: "<?=GetMessageJS('CRM_ENTITY_ED_UF_REST_TITLE')?>",
							legend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_REST_LEGEND')?>",
							callback: function()
							{
								BX.SidePanel.Instance.open(
									'<?=$arResult['USERFIELD_TYPE_REST_CREATE_URL']?>',
									{
										cacheable: false,
										allowChangeHistory: false
									}
								);
							}
						});
					<?php endif;?>
					event.setData({
						types: types
					});
				}
			);
			<?php endif;?>

			var contextParams = {
				CATEGORY_ID: <?=$arParams['EXTRAS']['CATEGORY_ID'] ?? 0?>
			};

			var userFieldManager = BX.UI.EntityUserFieldManager.create(
				"<?=CUtil::JSEscape($guid)?>",
				{
					entityId: <?=$arResult['ENTITY_ID']?>,
					enableCreation: <?=$arResult['ENABLE_USER_FIELD_CREATION'] ? 'true' : 'false'?>,
					fieldEntityId: "<?=CUtil::JSEscape($arResult['USER_FIELD_ENTITY_ID'])?>",
					creationSignature: "<?=CUtil::JSEscape($arResult['USER_FIELD_CREATE_SIGNATURE'])?>",
					creationPageUrl: "<?=CUtil::JSEscape($arResult['USER_FIELD_CREATE_PAGE_URL'])?>",
					languages: <?=CUtil::PhpToJSObject($arResult['LANGUAGES'])?>,
					fieldPrefix: "<?=CUtil::JSEscape($arResult['USER_FIELD_PREFIX'])?>",
					contextParams: contextParams,
				}
			);

			var config = BX.UI.EntityConfig.create(
				"<?=CUtil::JSEscape($arResult['CONFIG_ID'])?>",
				{
					data: <?=CUtil::PhpToJSObject($arResult['ENTITY_CONFIG'])?>,
					scope: "<?=CUtil::JSEscape($arResult['ENTITY_CONFIG_SCOPE'])?>",
					userScopes: <?=CUtil::PhpToJSObject($arResult['USER_SCOPES'])?>,
					userScopeId: "<?=CUtil::JSEscape($arResult['USER_SCOPE_ID'])?>",
					enableScopeToggle: <?=$arResult['ENABLE_CONFIG_SCOPE_TOGGLE'] ? 'true' : 'false'?>,
					canUpdatePersonalConfiguration: <?=$arResult['CAN_UPDATE_PERSONAL_CONFIGURATION'] ? 'true' : 'false'?>,
					canUpdateCommonConfiguration: <?=$arResult['CAN_UPDATE_COMMON_CONFIGURATION'] ? 'true' : 'false'?>,
					options: <?=CUtil::PhpToJSObject($arResult['ENTITY_CONFIG_OPTIONS'])?>,
					categoryName: "<?=CUtil::JSEscape($arResult['ENTITY_CONFIG_CATEGORY_NAME'])?>",
					signedParams: "<?=CUtil::JSEscape($arResult['ENTITY_CONFIG_SIGNED_PARAMS'])?>"
				}
			);

			var scheme = BX.UI.EntityScheme.create(
				"<?=CUtil::JSEscape($guid)?>",
				{
					current: <?=CUtil::PhpToJSObject($arResult['ENTITY_SCHEME'])?>,
					available: <?=CUtil::PhpToJSObject($arResult['ENTITY_AVAILABLE_FIELDS'])?>
				}
			);

			BX.UI.EntitySchemeElement.userFieldFileUrlTemplate = "<?=CUtil::JSEscape($arResult['USER_FIELD_FILE_URL_TEMPLATE'])?>";
			BX.UI.EntitySchemeElement.userFieldFileIsAllowSwitchView = "<?= CUtil::JSEscape(Crm\Service\Display\Field\UserField::FILE_IS_ALLOW_SWITCH_VIEW) ?>";

			var model = BX.Crm.EntityEditorModelFactory.create(
				<?=$arResult['ENTITY_TYPE_ID']?>,
				"",
				{ entityTypeId: <?=$arResult['ENTITY_TYPE_ID']?>, data: <?= Json::encode($arResult['PREPARED_ENTITY_DATA'])?> }
			);

			BX.CrmDuplicateSummaryPopup.messages =
			{
				title: "<?=GetMessageJS("CRM_ENTITY_ED_DUP_CTRL_SHORT_SUMMARY_TITLE")?>"
			};

			BX.CrmDuplicateWarningDialog.messages =
			{
				title: "<?=GetMessageJS("CRM_ENTITY_ED_DUP_CTRL_WARNING_DLG_TITLE")?>",
				acceptButtonTitle: "<?=GetMessageJS("CRM_ENTITY_ED_DUP_CTRL_WARNING_ACCEPT_BTN_TITLE")?>",
				cancelButtonTitle: "<?=GetMessageJS("CRM_ENTITY_ED_DUP_CTRL_WARNING_CANCEL_BTN_TITLE")?>"
			};

			BX.CrmEntityType.categoryCaptions = <?=CUtil::PhpToJSObject(\CCrmOwnerType::GetAllCategoryCaptions(true))?>;

			BX.Crm.EntityEditor.messages =
			{
				newSectionTitle: "<?=GetMessageJS('CRM_ENTITY_ED_NEW_SECTION_TITLE')?>",
				inlineEditHint: "<?=GetMessageJS('CRM_ENTITY_ED_INLINE_EDIT_HINT')?>",
				couldNotFindEntityIdError: "<?=GetMessageJS('CRM_ENTITY_ED_COULD_NOT_FIND_ENTITY_ID')?>",
				titleEdit: "<?=GetMessageJS('CRM_ENTITY_ED_TITLE_EDIT')?>",
				titleEditUnsavedChanges: "<?=GetMessageJS('CRM_ENTITY_ED_TITLE_EDIT_UNSAVED_CHANGES')?>",
				checkScope: "<?=GetMessageJS('CRM_ENTITY_ED_CHECK_SCOPE')?>",
				copyPageUrl: "<?= \CUtil::JSEscape($arResult['MESSAGES']['COPY_PAGE_URL'] ?? '') ?>",
				pageUrlCopied: "<?= \CUtil::JSEscape($arResult['MESSAGES']['PAGE_URL_COPIED'] ?? '') ?>",
			};

			BX.Crm.EntityEditorScopeConfig.messages =
			{
				scopeNamePlaceholder: "<?=GetMessageJS('CRM_ENTITY_ED_CONFIG_SCOPE_NAME_PLACEHOLDER')?>",
				scopeSave: "<?=GetMessageJS('CRM_ENTITY_ED_CONFIG_SCOPE_SAVE')?>",
				scopeCancel: "<?=GetMessageJS('CRM_ENTITY_ED_CONFIG_SCOPE_CANCEL')?>",
			};

			BX.UI.EntityUserFieldManager.messages =
			{
				stringLabel: "<?=GetMessageJS('CRM_ENTITY_ED_UF_STRING_LABEL')?>",
				doubleLabel: "<?=GetMessageJS('CRM_ENTITY_ED_UF_DOUBLE_LABEL')?>",
				moneyLabel: "<?=GetMessageJS('CRM_ENTITY_ED_UF_MONEY_LABEL')?>",
				datetimeLabel: "<?=GetMessageJS('CRM_ENTITY_ED_UF_DATETIME_LABEL')?>",
				enumerationLabel: "<?=GetMessageJS('CRM_ENTITY_ED_UF_ENUMERATION_LABEL')?>",
				fileLabel: "<?=GetMessageJS('CRM_ENTITY_ED_UF_FILE_LABEL')?>",
				label: "<?=GetMessageJS('CRM_ENTITY_ED_UF_LABEL')?>",
				stringTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_STRING_TITLE')?>",
				stringLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_STRING_LEGEND')?>",
				doubleTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_DOUBLE_TITLE')?>",
				doubleLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_DOUBLE_LEGEND')?>",
				moneyTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_MONEY_TITLE')?>",
				moneyLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_MONEY_LEGEND')?>",
				booleanTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_BOOLEAN_TITLE')?>",
				booleanLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_BOOLEAN_LEGEND')?>",
				datetimeTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_DATETIME_TITLE')?>",
				datetimeLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_DATETIME_LEGEND')?>",
				enumerationTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_ENUM_TITLE')?>",
				enumerationLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_ENUM_LEGEND')?>",
				urlTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_URL_TITLE')?>",
				urlLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_URL_LEGEND')?>",
				addressTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_ADDRESS_TITLE_2')?>",
				addressLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_ADDRESS_LEGEND_2')?>",
				resourcebookingTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_RESOURCEBOOKING_TITLE')?>",
				resourcebookingLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_RESOURCEBOOKING_LEGEND')?>",
				fileTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_FILE_TITLE')?>",
				fileLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_FILE_LEGEND')?>",
				customTitle: "<?=GetMessageJS('CRM_ENTITY_ED_UF_CUSTOM_TITLE')?>",
				customLegend: "<?=GetMessageJS('CRM_ENTITY_ED_UF_CUSTOM_LEGEND')?>"
			};

			BX.UI.EntityUserFieldManager.additionalTypeList = <?=\CUtil::PhpToJSObject($arResult['USERFIELD_TYPE_ADDITIONAL'])?>;

			BX.Crm.EntityEditorMoneyPay.messages =
			{
				popupItemTitle: "<?=GetMessageJS('CRM_ENTITY_EM_BUTTON_PAY_POPUP_ITEM_TITLE')?>",
				payButtonLabel: "<?=GetMessageJS('CRM_ENTITY_EM_BUTTON_PAY')?>",
				showPayButton: "<?=GetMessageJS('CRM_ENTITY_EM_SHOW_BUTTON_PAY_MSGVER_1')?>",
				hidePayButton: "<?=GetMessageJS('CRM_ENTITY_EM_HIDE_BUTTON_PAY_MSGVER_1')?>",
				showPaymentDocuments: "<?=GetMessageJS('CRM_ENTITY_EM_SHOW_PAYMENT_DOCUMENTS')?>",
				hidePaymentDocuments: "<?=GetMessageJS('CRM_ENTITY_EM_HIDE_PAYMENT_DOCUMENTS')?>",
			};

			BX.UI.EntityEditorFieldConfigurator.messages =
			{
				labelField: "<?=GetMessageJS('CRM_ENTITY_ED_FIELD_TITLE')?>",
				showAlways: "<?=GetMessageJS('CRM_ENTITY_ED_SHOW_ALWAYS')?>",
				useTimezone: "<?=GetMessageJS('CRM_ENTITY_ED_USE_TIMEZONE')?>",
			};

			BX.Crm.EntityFieldVisibilityConfigurator.messages =
			{
				titleField: "<?=GetMessageJS('CRM_VISIBILITY_ATTR_TITLE')?>",
				labelField: "<?=GetMessageJS('CRM_VISIBILITY_ATTR_LABEL')?>",
				addUserButton: "<?=GetMessageJS('CRM_VISIBILITY_ADD_USER_BUTTON')?>",
			};

			BX.Crm.EntityEditorUserFieldConfigurator.messages =
			{
				labelField: "<?=GetMessageJS('CRM_ENTITY_ED_FIELD_TITLE')?>",
				isRequiredField: "<?=GetMessageJS('CRM_ENTITY_ED_UF_REQUIRED_FIELD')?>",
				isMultipleField: "<?=GetMessageJS('CRM_ENTITY_ED_UF_MULTIPLE_FIELD')?>",
				showAlways: "<?=GetMessageJS('CRM_ENTITY_ED_SHOW_ALWAYS')?>",
				enableTime: "<?=GetMessageJS('CRM_ENTITY_ED_UF_ENABLE_TIME')?>",
				enumItems: "<?=GetMessageJS('CRM_ENTITY_ED_UF_ENUM_ITEMS')?>",
				add: "<?=GetMessageJS('CRM_ENTITY_ED_ADD')?>"
			};

			BX.UI.EntityEditorField.messages =
			{
				hideButtonHint: "<?=GetMessageJS('CRM_ENTITY_ED_HIDE_BUTTON_HINT')?>",
				hideButtonDisabledHint: "<?=GetMessageJS('CRM_ENTITY_ED_HIDE_BUTTON_DISABLED_HINT')?>",
				requiredFieldError: "<?=GetMessageJS('CRM_ENTITY_ED_REQUIRED_FIELD_ERROR')?>",
				add: "<?=GetMessageJS('CRM_ENTITY_ED_ADD')?>",
				hide: "<?=GetMessageJS('CRM_ENTITY_ED_HIDE')?>",
				showAlways: "<?=GetMessageJS('CRM_ENTITY_ED_SHOW_ALWAYS')?>",
				configure: "<?=GetMessageJS('CRM_ENTITY_ED_CONFIGURE')?>",
				isEmpty: "<?=GetMessageJS('CRM_ENTITY_ED_FIELD_EMPTY')?>",
				hideDeniedDlgTitle: "<?=GetMessageJS('CRM_ENTITY_ED_HIDE_TITLE')?>",
				hideDeniedDlgContent: "<?=GetMessageJS('CRM_ENTITY_ED_HIDE_DENIED')?>",
				hiddenInViewMode: "<?=GetMessageJS('CRM_ENTITY_ED_FIELD_HIDDEN_IN_VIEW_MODE')?>",
				isHiddenDueToShowAlwaysChanged: "<?=GetMessageJS('CRM_ENTITY_ED_FIELD_HIDDEN_DUE_TO_SHOW_ALWAYS_CHANGED')?>"
			};

			BX.Crm.EntityEditorUserField.messages =
			{
				moveAddrToRequisite: "<?=GetMessageJS('CRM_ENTITY_ED_MOVE_ADDR_TO_REQUISITE')?>",
				moveAddrToRequisiteHtml: "<?=GetMessageJS('CRM_ENTITY_ED_MOVE_ADDR_TO_REQUISITE_HTML')?>",
				moveAddrToRequisiteBtnStart: "<?=GetMessageJS('CRM_ENTITY_ED_MOVE_ADDR_TO_REQUISITE_BTN_START')?>",
				moveAddrToRequisiteBtnCancel: "<?=GetMessageJS('CRM_ENTITY_ED_MOVE_ADDR_TO_REQUISITE_BTN_CANCEL')?>",
				moveAddrToRequisiteStartSuccess: "<?=GetMessageJS('CRM_ENTITY_ED_MOVE_ADDR_TO_REQUISITE_START_SUCCESS')?>"
			};

			BX.Crm.EntityEditorSection.messages =
			{
				change: "<?=GetMessageJS('CRM_ENTITY_ED_CHANGE')?>",
				cancel: "<?=GetMessageJS('CRM_ENTITY_ED_CANCEL')?>",
				createField: "<?=GetMessageJS('CRM_ENTITY_ED_CREATE_FIELD')?>",
				selectField: "<?=GetMessageJS('CRM_ENTITY_ED_SELECT_FIELD')?>",
				deleteSection: "<?=GetMessageJS('CRM_ENTITY_ED_DELETE_SECTION')?>",
				deleteSectionConfirm: "<?=GetMessageJS('CRM_ENTITY_ED_DELETE_SECTION_CONFIRM')?>",
				selectFieldFromOtherSection: "<?=GetMessageJS('CRM_ENTITY_ED_SELECT_FIELD_FROM_OTHER_SECTION')?>",
				transferDialogTitle: "<?=GetMessageJS('CRM_ENTITY_ED_FIELD_TRANSFER_DIALOG_TITLE')?>",
				nothingSelected: "<?=GetMessageJS('CRM_ENTITY_ED_NOTHIG_SELECTED')?>",
				deleteSectionDenied: "<?=GetMessageJS('CRM_ENTITY_ED_DELETE_SECTION_DENIED')?>",
				openDetails: "<?=GetMessageJS('CRM_ENTITY_ED_SECTION_OPEN_DETAILS')?>"
			};

			BX.UI.EntityEditorBoolean.messages =
			{
				yes: "<?=GetMessageJS('MAIN_YES')?>",
				no: "<?=GetMessageJS('MAIN_NO')?>"
			};

			BX.Crm.EntityEditorUser.messages =
			{
				change: "<?=GetMessageJS('CRM_ENTITY_ED_CHANGE_USER')?>"
			};

			BX.Crm.EntityEditorMultipleUser.messages =
				{
					change: "<?=GetMessageJS('CRM_ENTITY_ED_CHANGE_USER')?>"
				};

			BX.Crm.EntityEditorFileStorage.messages =
			{
				diskAttachFiles: "<?=GetMessageJS('CRM_ENTITY_ED_DISK_ATTACH_FILE')?>",
				diskAttachedFiles: "<?=GetMessageJS('CRM_ENTITY_ED_DISK_ATTACHED_FILES')?>",
				diskSelectFile: "<?=GetMessageJS('CRM_ENTITY_ED_DISK_SELECT_FILE')?>",
				diskSelectFileLegend: "<?=GetMessageJS('CRM_ENTITY_ED_DISK_SELECT_FILE_LEGEND_MSGVER_1')?>",
				diskUploadFile: "<?=GetMessageJS('CRM_ENTITY_ED_DISK_UPLOAD_FILE')?>",
				diskUploadFileLegend: "<?=GetMessageJS('CRM_ENTITY_ED_DISK_UPLOAD_FILE_LEGEND')?>"
			};

			BX.UI.EntityEditorHtml.messages =
			{
				expand: "<?=GetMessageJS('CRM_ENTITY_ED_EXPAND_COMMENT')?>",
				collapse: "<?=GetMessageJS('CRM_ENTITY_ED_COLLAPSE_COMMENT')?>"
			};

			BX.Crm.PrimaryClientEditor.messages =
			{
				select: "<?=GetMessageJS('CRM_ENTITY_ED_SELECT')?>",
				bind: "<?=GetMessageJS('CRM_ENTITY_ED_BIND')?>",
				create: "<?=GetMessageJS('CRM_ENTITY_ED_CREATE')?>"
			};

			BX.Crm.SecondaryClientEditor.messages =
			{
				select: "<?=GetMessageJS('CRM_ENTITY_ED_SELECT')?>",
				create: "<?=GetMessageJS('CRM_ENTITY_ED_CREATE')?>",
				bind: "<?=GetMessageJS('CRM_ENTITY_ED_BIND')?>",
				addParticipant: "<?=GetMessageJS('CRM_ENTITY_ED_ADD_PARTICIPANT')?>"
			};

			BX.Crm.EntityEditorClientSearchBox.messages =
			{
				contactToCreateTag: "<?=GetMessageJS('CRM_ENTITY_ED_NEW_CONTACT')?>",
				companyToCreateTag: "<?=GetMessageJS('CRM_ENTITY_ED_NEW_COMPANY')?>",
				contactToCreateLegend: "<?=GetMessageJS('CRM_ENTITY_ED_NEW_CONTACT_LEGEND')?>",
				companyToCreateLegend: "<?=GetMessageJS('CRM_ENTITY_ED_NEW_COMPANY_LEGEND')?>",
				contactChangeButtonHint: "<?=GetMessageJS('CRM_ENTITY_ED_CONTACT_CHANGE_BUTTON_HINT')?>",
				companyChangeButtonHint: "<?=GetMessageJS('CRM_ENTITY_ED_COMPANY_CHANGE_BUTTON_HINT')?>",
				entityEditTag: "<?=GetMessageJS('CRM_ENTITY_ED_EDIT_TAG')?>",
				notFound: "<?=GetMessageJS('CRM_ENTITY_ED_NOT_FOUND')?>",
				unnamed: "<?=CUtil::JSEscape(\CCrmContact::GetDefaultName())?>",
				untitled: "<?=CUtil::JSEscape(\CCrmCompany::GetDefaultTitle())?>",
				companyChangeButtonHint: "<?=GetMessageJS('CRM_ENTITY_ED_COMPANY_CHANGE_BUTTON_HINT')?>",
				notifyContactToDeal: "<?=GetMessageJS('CRM_CLIENT_EDITOR_NOTIFY_CONTACT_TO_DEAL')?>",
				notifyCompanyToDeal: "<?=GetMessageJS('CRM_CLIENT_EDITOR_NOTIFY_COMPANY_TO_DEAL')?>",
				notifyContactToLead: "<?=GetMessageJS('CRM_CLIENT_EDITOR_NOTIFY_CONTACT_TO_LEAD')?>",
				notifyCompanyToLead: "<?=GetMessageJS('CRM_CLIENT_EDITOR_NOTIFY_COMPANY_TO_LEAD')?>",
				notifyContactToSmartInvoice: "<?=GetMessageJS('CRM_CLIENT_EDITOR_NOTIFY_CONTACT_TO_INVOICE')?>",
				notifyCompanyToSmartInvoice: "<?=GetMessageJS('CRM_CLIENT_EDITOR_NOTIFY_COMPANY_TO_INVOICE')?>",
				notifyContactToCompany: "<?=GetMessageJS('CRM_CLIENT_EDITOR_NOTIFY_CONTACT_TO_COMPANY')?>",
				notifyCompanyToContact: "<?=GetMessageJS('CRM_CLIENT_EDITOR_NOTIFY_COMPANY_TO_CONTACT')?>",
			};

			BX.Crm.ClientEditorCommunicationButton.messages =
			{
				telephonyNotSupported: "<?=GetMessageJS('CRM_ENTITY_ED_TELEPHONY_NOT_SUPPORTED')?>",
				messagingNotSupported: "<?=GetMessageJS('CRM_ENTITY_ED_MESSAGING_NOT_SUPPORTED')?>"
			};

			BX.Crm.EntityEditorEntity.messages =
			{
				select: "<?=GetMessageJS('CRM_ENTITY_ED_SELECT')?>"
			};

			BX.Crm.EntityEditorClientLight.messages =
			{
				addParticipant: "<?=GetMessageJS('CRM_ENTITY_ED_ADD_PARTICIPANT')?>",
				companySearchPlaceholder: "<?=GetMessageJS('CRM_ENTITY_ED_COMPANY_SEARCH_PLACEHOLDER_2')?>",
				contactSearchPlaceholder: "<?=GetMessageJS('CRM_ENTITY_ED_CONTACT_SEARCH_PLACEHOLDER_2')?>",
				enableCompany: "<?=GetMessageJS('CRM_ENTITY_ED_ENABLE_CLIENT_COMPANY')?>",
				disableCompany: "<?=GetMessageJS('CRM_ENTITY_ED_DISABLE_CLIENT_COMPANY')?>",
				enableContact: "<?=GetMessageJS('CRM_ENTITY_ED_ENABLE_CLIENT_CONTACT')?>",
				disableContact: "<?=GetMessageJS('CRM_ENTITY_ED_DISABLE_CLIENT_CONTACT')?>",
				enableAddress: "<?=GetMessageJS('CRM_ENTITY_ED_ENABLE_CLIENT_ADDRESS')?>",
				disableAddress: "<?=GetMessageJS('CRM_ENTITY_ED_DISABLE_CLIENT_ADDRESS')?>",
				enableRequisites: "<?=GetMessageJS('CRM_ENTITY_ED_ENABLE_CLIENT_REQUISITES')?>",
				disableRequisites: "<?=GetMessageJS('CRM_ENTITY_ED_DISABLE_CLIENT_REQUISITES')?>",
				displayContactAtFirst: "<?=GetMessageJS('CRM_ENTITY_ED_DISPLAY_CONTACT_AT_FIRST')?>",
				displayCompanyAtFirst: "<?=GetMessageJS('CRM_ENTITY_ED_DISPLAY_COMPANY_AT_FIRST')?>",
				enableQuickEdit: "<?=GetMessageJS('CRM_ENTITY_ED_ENABLE_QUICK_EDIT')?>",
				disableQuickEdit: "<?=GetMessageJS('CRM_ENTITY_ED_DISABLE_QUICK_EDIT')?>"
			};

			BX.Crm.EntityEditorMoney.messages =
			{
				manualOpportunitySetAutomatic: "<?=GetMessageJS('CRM_EDITOR_MANUAL_OPPORTUNITY_SET_TO_AUTOMATIC')?>"
			}

			BX.Crm.EntityEditorProductRowProxy.messages =
			{
				manualOpportunityConfirmationTitle: "<?=GetMessageJS('CRM_EDITOR_MANUAL_OPPORTUNITY_CONFIRMATION_TITLE')?>",
				manualOpportunityConfirmationText: "<?=GetMessageJS('CRM_EDITOR_MANUAL_OPPORTUNITY_CONFIRMATION_TEXT')?>",
				manualOpportunityConfirmationYes: "<?=GetMessageJS('MAIN_YES')?>",
				manualOpportunityConfirmationNo: "<?=GetMessageJS('MAIN_NO')?>",
				manualOpportunityChangeModeTitle: "<?=
					empty($arResult['MESSAGES']['MANUAL_OPPORTUNITY_CHANGE_MODE_TITLE'])
						? GetMessageJS('CRM_EDITOR_MANUAL_OPPORTUNITY_CHANGE_TITLE_' . $arResult['ENTITY_TYPE_ID'])
						: \CUtil::JSEscape($arResult['MESSAGES']['MANUAL_OPPORTUNITY_CHANGE_MODE_TITLE'])
					?>",
				manualOpportunityChangeModeText: "<?=
					empty($arResult['MESSAGES']['MANUAL_OPPORTUNITY_CHANGE_MODE_TEXT'])
						? GetMessageJS('CRM_EDITOR_MANUAL_OPPORTUNITY_CHANGE_TEXT_' . $arResult['ENTITY_TYPE_ID'])
						: \CUtil::JSEscape($arResult['MESSAGES']['MANUAL_OPPORTUNITY_CHANGE_MODE_TEXT'])
					?>",
				manualOpportunityChangeModeYes: "<?=GetMessageJS('CRM_EDITOR_MANUAL_OPPORTUNITY_CHANGE_VALUE_AUTO')?>",
				manualOpportunityChangeModeNo: "<?=GetMessageJS('CRM_EDITOR_MANUAL_OPPORTUNITY_CHANGE_VALUE_MANUAL')?>"
			};

			BX.Crm.EntityProductListController.messages = BX.Crm.EntityEditorProductRowProxy.messages;

			BX.Crm.ClientEditorEntityRequisitePanel.messages =
			{
				toggle: "<?=GetMessageJS('CRM_ENTITY_ED_TOGGLE_REQUISITES')?>"
			};

			BX.Crm.RequisiteNavigator.messages =
			{
				next: "<?=GetMessageJS('CRM_ENTITY_ED_NAVIGATION_NEXT')?>",
				toggle: "<?=GetMessageJS('CRM_ENTITY_ED_TOGGLE_REQUISITES')?>",
				legend: "<?=GetMessageJS('CRM_ENTITY_ED_NAVIGATION_LEGEND')?>",
				stub: "<?=GetMessageJS('CRM_ENTITY_ED_NO_REQUISITE_STUB')?>"
			};

			BX.Crm.EntityEditorRequisiteSelector.messages =
			{
				bankDetails: "<?=GetMessageJS('CRM_ENTITY_ED_BANK_DETAILS')?>"
			};

			BX.Crm.EntityEditorRequisiteListItem.messages =
			{
				deleteTitle: "<?=GetMessageJS("CRM_ENTITY_ED_REQUISITE_DELETE_DLG_TITLE")?>",
				deleteConfirm: "<?=GetMessageJS("CRM_ENTITY_ED_REQUISITE_DELETE_DLG_CONTENT")?>"
			};

			BX.Crm.EntityEditorRequisiteList.messages =
			{
				deleteTitle: "<?=GetMessageJS("CRM_ENTITY_ED_REQUISITE_DELETE_DLG_TITLE")?>",
				deleteConfirm: "<?=GetMessageJS("CRM_ENTITY_ED_REQUISITE_DELETE_DLG_CONTENT")?>"
			};

			BX.Crm.EntityEditorRecurringV2.messages =
			{
				notRepeat: "<?=GetMessageJS('CRM_ENTITY_ED_RECURRING_NOT_REPEAT')?>",
				modeTitle: "<?=GetMessageJS('CRM_ENTITY_ED_RECURRING_MODE_TITLE')?>",
				hide: "<?=GetMessageJS('CRM_ENTITY_ED_HIDE')?>"
			};

			BX.Crm.EntityEditorPayment.messages =
			{
				paymentWasPaid: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_WAS_PAID')?>",
				paymentWasNotPaid: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_WAS_NOT_PAID')?>",
				paymentCancel: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_CANCEL')?>",
				paymentReturn: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_RETURN')?>",
				documentTitle: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_DOCUMENT_TITLE')?>",
				addDocument: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_ADD_DOCUMENT')?>",
				sum: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_SUM')?>",
			};

			BX.Crm.EntityEditorOrderController.messages =
			{
				saveChanges: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_SAVE_CHANGES')?>",
				saveConfirm: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_SAVE_CONFIRM')?>",
				save: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_SAVE')?>",
				notSave: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_NOT_SAVE')?>"
			};

			BX.Crm.EntityEditorPaymentStatus.messages =
			{
				paymentWasPaid: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_WAS_PAID')?>",
				paymentWasNotPaid: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_WAS_NOT_PAID')?>",
				paymentCancel: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_CANCEL')?>",
				paymentReturn: "<?=GetMessageJS('CRM_ENTITY_ED_PAYMENT_RETURN')?>"
			};

			BX.Crm.EntityEditorShipment.messages =
			{
				deliveryAllowed: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DELIVERY_ALLOWED')?>",
				deducted: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DEDEUCTED')?>",
				trackingNumberTitle: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_TRACKING_NUMBER_TITLE')?>",
				documentTitle: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DOCUMENT_TITLE')?>",
				addDocument: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_ADD_DOCUMENT')?>",
				deliveryService: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DELIVERY_SERVICE')?>",
				deliveryProfile: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DELIVERY_PROFILE')?>",
				price: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DELIVERY_PRICE')?>",
				profile: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_PROFILE')?>",
				deliveryPriceCalculated: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DELIVERY_PRICE_CALCULATED')?>",
				deliveryPriceCalculatedHint: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DELIVERY_PRICE_CALCULATED_HINT')?>",
				deliveryStore: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DELIVERY_STORE')?>",
				refresh: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_REFRESH')?>",
			};

			BX.Crm.EntityEditorDeliverySelector.messages =
			{
				notSelected: "<?=GetMessageJS('CRM_ENTITY_ED_DELIVERY_SELECTOR_NOT_SELECTED')?>",
				deliveryStore: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DELIVERY_STORE')?>",
				deliveryProfile: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_DELIVERY_PROFILE')?>",
			};

			BX.Crm.EntityEditorPaySystemSelector.messages =
			{
				notSelected: "<?=GetMessageJS('CRM_ENTITY_ED_PAY_SYSTEM_SELECTOR_NOT_SELECTED')?>",
			};

			BX.Crm.EntityEditorOrderPropertySubsection.messages =
			{
				linkToSettings: "<?=GetMessageJS('CRM_ENTITY_ED_CHILD_ENTITY_MENU_SETTINGS_LINK')?>"
			};
			BX.Crm.EntityEditorOrderPropertyWrapper.messages =
			{
				createField: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PROPERTY_CREATE')?>",
				insertField: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PROPERTY_INSERT')?>",
				selectField: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PROPERTY_SELECT')?>",
				disabledBlockTitle: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PROPERTY_UNKNOWN_GROUP')?>"
			};

			BX.Crm.EntityEditorPaymentCheck.messages =
			{
				titleFieldSum: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PAYMENT_CHECK_SUM')?>",
				titleFieldDateCreate: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PAYMENT_CHECK_DATE_CREATE')?>",
				titleFieldType: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PAYMENT_CHECK_TYPE')?>",
				titleFieldCashBoxName: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PAYMENT_CHECK_CASHBOX_NAME')?>",
				titleFieldStatus: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PAYMENT_CHECK_STATUS')?>",
				titleFieldLink: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PAYMENT_CHECK_LINK')?>",
				emptyCheckList: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PAYMENT_CHECK_EMPTY')?>"
			};

			BX.Crm.EntityEditorOrderProductProperty.messages =
			{
				addProductProperty: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_ADD_PRODUCT_PROPERTY_LINK')?>",
				fieldBlockTitle: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PRODUCT_FIELD_BLOCK_TITLE')?>",
				fieldTitleName: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PRODUCT_FIELD_NAME')?>",
				fieldTitleValue: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PRODUCT_FIELD_VALUE')?>",
				fieldTitleCode: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PRODUCT_FIELD_CODE')?>",
				fieldTitleSort: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_PRODUCT_FIELD_SORT')?>"
			};
			BX.Crm.EntityEditorOrderUser.messages =
			{
				change: "<?=GetMessageJS('CRM_ENTITY_ED_CHANGE_USER')?>",
				searchPlaceholder: "<?=GetMessageJS('CRM_ENTITY_ED_ORDER_USER_SEARCH_PLACEHOLDER')?>",
			};

			BX.Crm.EntityEditorOrderClientSearchBox.messages =
			{
				notFound: "<?=GetMessageJS('CRM_ENTITY_ED_NOT_FOUND')?>"
			};

			BX.Crm.EntityEditorCalculatedDeliveryPrice.messages =
			{
				refresh: "<?=GetMessageJS('CRM_ENTITY_ED_SHIPMENT_REFRESH')?>",
			};

			BX.Crm.EntityEditorDocumentNumber.messages =
			{
				numeratorSettingsContextItem: "<?=GetMessageJS('CRM_ENTITY_ED_DOCUMENT_NUMBER_NUMERATOR_SETTINGS_CONTEXT_ITEM')?>",
			};

			BX.message(
				{
					"CRM_EDITOR_SAVE": "<?=GetMessageJS('CRM_ENTITY_ED_SAVE')?>",
					"CRM_EDITOR_CONTINUE": "<?=GetMessageJS('CRM_ENTITY_ED_CONTINUE')?>",
					"CRM_EDITOR_CANCEL": "<?=GetMessageJS('CRM_ENTITY_ED_CANCEL')?>",
					"CRM_EDITOR_DELETE": "<?=GetMessageJS('CRM_ENTITY_ED_DELETE')?>",
					"CRM_EDITOR_ADD": "<?=GetMessageJS('CRM_ENTITY_ED_ADD')?>",
					"CRM_EDITOR_ADD_REQUISITE": "<?=GetMessageJS('CRM_EDITOR_ADD_REQUISITE')?>",
					"CRM_EDITOR_ADD_BANK_DETAILS": "<?=GetMessageJS('CRM_EDITOR_ADD_BANK_DETAILS')?>",
					"CRM_EDITOR_CONFIRMATION": "<?=GetMessageJS('CRM_EDITOR_CONFIRMATION')?>",
					"CRM_EDITOR_CLOSE_CONFIRMATION": "<?=GetMessageJS('CRM_EDITOR_CLOSE_CONFIRMATION')?>",
					"CRM_EDITOR_SAVE_ERROR_TITLE": "<?=GetMessageJS('CRM_EDITOR_SAVE_ERROR_TITLE')?>",
					"CRM_EDITOR_SAVE_ERROR_CONTENT": "<?=GetMessageJS('CRM_EDITOR_SAVE_ERROR_CONTENT')?>",
					"CRM_EDITOR_PAYMENT_PAID": "<?=GetMessageJS('CRM_EDITOR_PAYMENT_PAID')?>",
					"CRM_EDITOR_PAYMENT_NOT_PAID": "<?=GetMessageJS('CRM_EDITOR_PAYMENT_NOT_PAID')?>",
					"CRM_EDITOR_CANCEL_CONFIRMATION": "<?=GetMessageJS('CRM_EDITOR_CANCEL_CONFIRMATION')?>",
					"CRM_EDITOR_YES": "<?=GetMessageJS('MAIN_YES')?>",
					"CRM_EDITOR_NO": "<?=GetMessageJS('MAIN_NO')?>",
					"CRM_EDITOR_PHONE": "<?=GetMessageJS('CRM_EDITOR_PHONE')?>",
					"CRM_EDITOR_EMAIL": "<?=GetMessageJS('CRM_EDITOR_EMAIL')?>",
					"CRM_EDITOR_ADDRESS": "<?=GetMessageJS('CRM_EDITOR_ADDRESS')?>",
					"CRM_EDITOR_REQUISITES": "<?=GetMessageJS('CRM_EDITOR_REQUISITES')?>",
					"CRM_EDITOR_PLACEMENT_CAUTION": "<?=GetMessageJS('CRM_EDITOR_PLACEMENT_CAUTION')?>",
				}
			);

			BX.Crm.EntityPhaseLayout.colors =
				{
					process: "<?=Bitrix\Crm\Color\PhaseColorScheme::PROCESS_COLOR?>",
					success: "<?=Bitrix\Crm\Color\PhaseColorScheme::SUCCESS_COLOR?>",
					failure: "<?=Bitrix\Crm\Color\PhaseColorScheme::FAILURE_COLOR?>",
					apology: "<?=Bitrix\Crm\Color\PhaseColorScheme::FAILURE_COLOR?>"
				};

			var bizprocManager = null;
			var restPlacementTabManager = null;
			<?if(!$arResult['IS_EMBEDDED']){?>
			bizprocManager = BX.Crm.EntityBizprocManager.create(
				"<?=CUtil::JSEscape($guid)?>",
				<?=\Bitrix\Main\Web\Json::encode($arResult['BIZPROC_MANAGER_CONFIG'])?>
			);

			restPlacementTabManager = BX.Crm.EntityRestPlacementManager.create(
				"<?=CUtil::JSEscape($guid)?>",
				<?=\CUtil::PhpToJSObject($arResult['REST_PLACEMENT_TAB_CONFIG'])?>
			);
			<?}?>

			BX.Crm.EntityEditor.setDefault(
				BX.Crm.EntityEditor.create(
					"<?=CUtil::JSEscape($guid)?>",
					{
						model,
						config,
						scheme,
						bizprocManager,
						restPlacementTabManager,
						userFieldManager,
						entityTypeName: "<?=CUtil::JSEscape($arResult['ENTITY_TYPE_NAME'])?>",
						entityTypeId: <?=$arResult['ENTITY_TYPE_ID']?>,
						entityTypeTitle: "<?= CUtil::JSEscape($arResult['ENTITY_TYPE_TITLE']) ?>",
						useFieldsSearch: true,
						useForceFieldsAdd: true,
						entityId: <?=$arResult['ENTITY_ID']?>,
						moduleId: 'crm',
						validators: <?=CUtil::PhpToJSObject($arResult['ENTITY_VALIDATORS'])?>,
						controllers: <?=CUtil::PhpToJSObject($arResult['ENTITY_CONTROLLERS'])?>,
						detailManagerId: "<?=CUtil::JSEscape($arResult['DETAIL_MANAGER_ID'])?>",
						additionalFieldsData: <?= CUtil::PhpToJSObject($arResult['ADDITIONAL_FIELDS_DATA']) ?>,
						canCreateContact: <?=CUtil::PhpToJSObject($arResult['CAN_CREATE_CONTACT'])?>,
						canCreateCompany: <?=CUtil::PhpToJSObject($arResult['CAN_CREATE_COMPANY'])?>,
						duplicateControl: <?=CUtil::PhpToJSObject($arResult['DUPLICATE_CONTROL'])?>,
						initialMode: "<?=CUtil::JSEscape($arResult['INITIAL_MODE'])?>",
						enableModeToggle: <?=$arResult['ENABLE_MODE_TOGGLE'] ? 'true' : 'false'?>,
						enableVisibilityPolicy: <?=$arResult['ENABLE_VISIBILITY_POLICY'] ? 'true' : 'false'?>,
						isToolPanelAlwaysVisible: <?=$arResult['IS_TOOL_PANEL_ALWAYS_VISIBLE'] ? 'true' : 'false'?>,
						enableToolPanel: <?=$arResult['ENABLE_TOOL_PANEL'] ? 'true' : 'false'?>,
						enableBottomPanel: <?=$arResult['ENABLE_BOTTOM_PANEL'] ? 'true' : 'false'?>,
						enableFieldsContextMenu: <?=$arResult['ENABLE_FIELDS_CONTEXT_MENU'] ? 'true' : 'false'?>,
						enablePageTitleControls: <?=$arResult['ENABLE_PAGE_TITLE_CONTROLS'] ? 'true' : 'false'?>,
						enablePageTitleControlsViaToolbar: <?=$arResult['ENABLE_PAGE_TITLE_CONTROLS_VIA_TOOLBAR'] ? 'true' : 'false'?>,
						enablePageTitleEdit: <?=$arResult['ENABLE_PAGE_TITLE_EDIT'] ? 'true' : 'false'?>,
						enableCommunicationControls: <?=$arResult['ENABLE_COMMUNICATION_CONTROLS'] ? 'true' : 'false'?>,
						enableExternalLayoutResolvers: <?=$arResult['ENABLE_EXTERNAL_LAYOUT_RESOLVERS'] ? 'true' : 'false'?>,
						readOnly: <?=$arResult['READ_ONLY'] ? 'true' : 'false'?>,
						enableAjaxForm: <?=$arResult['ENABLE_AJAX_FORM'] ? 'true' : 'false'?>,
						enableRequiredUserFieldCheck: <?=$arResult['ENABLE_REQUIRED_USER_FIELD_CHECK'] ? 'true' : 'false'?>,
						enableSectionEdit: <?=$arResult['ENABLE_SECTION_EDIT'] ? 'true' : 'false'?>,
						enableSectionCreation: <?=$arResult['ENABLE_SECTION_CREATION'] ? 'true' : 'false'?>,
						enableSettingsForAll: <?=$arResult['ENABLE_SETTINGS_FOR_ALL'] ? 'true' : 'false'?>,
						inlineEditLightingHint: "<?=CUtil::JSEscape($arResult['INLINE_EDIT_LIGHTING_HINT'] ?? null)?>",
						inlineEditSpotlightId: "<?=CUtil::JSEscape($arResult['INLINE_EDIT_SPOTLIGHT_ID'] ?? '')?>",
						enableInlineEditSpotlight: <?=$arResult['ENABLE_INLINE_EDIT_SPOTLIGHT'] ? 'true' : 'false'?>,
						containerId: "<?=CUtil::JSEscape($containerID)?>",
						buttonContainerId: "<?=CUtil::JSEscape($buttonContainerID)?>",
						createSectionButtonId: "<?=CUtil::JSEscape($createSectionButtonID)?>",
						configMenuButtonId: "<?=CUtil::JSEscape($configMenuButtonID)?>",
						configIconId: "<?=CUtil::JSEscape($configIconID)?>",
						htmlEditorConfigs: <?=CUtil::PhpToJSObject($htmlEditorConfigs)?>,
						serviceUrl: "<?=CUtil::JSEscape($arResult['SERVICE_URL'])?>",
						externalContextId: "<?=CUtil::JSEscape($arResult['EXTERNAL_CONTEXT_ID'])?>",
						contextId: "<?=CUtil::JSEscape($arResult['CONTEXT_ID'])?>",
						context: <?=CUtil::PhpToJSObject($arResult['CONTEXT'])?>,
						entityDetailsUrl: "<?=CUtil::JSEscape($arResult['PATH_TO_ENTITY_DETAILS'])?>",
						contactCreateUrl: "<?=CUtil::JSEscape($arResult['PATH_TO_CONTACT_CREATE'])?>",
						contactEditUrl: "<?=CUtil::JSEscape($arResult['PATH_TO_CONTACT_EDIT'])?>",
						contactRequisiteSelectUrl: "<?=CUtil::JSEscape($arResult['PATH_TO_CONTACT_REQUISITE_SELECT'])?>",
						companyCreateUrl: "<?=CUtil::JSEscape($arResult['PATH_TO_COMPANY_CREATE'])?>",
						companyEditUrl: "<?=CUtil::JSEscape($arResult['PATH_TO_COMPANY_EDIT'])?>",
						companyRequisiteSelectUrl: "<?=CUtil::JSEscape($arResult['PATH_TO_COMPANY_REQUISITE_SELECT'])?>",
						requisiteEditUrl: "<?=CUtil::JSEscape($arResult['PATH_TO_REQUISITE_EDIT'])?>",
						options: <?=CUtil::PhpToJSObject($arResult['EDITOR_OPTIONS'])?>,
						attributeConfig: <?=CUtil::PhpToJSObject($arResult['ATTRIBUTE_CONFIG'])?>,
						showEmptyFields: <?=$arResult['SHOW_EMPTY_FIELDS'] ? 'true' : 'false'?>,
                        ajaxData: <?=CUtil::PhpToJSObject($arResult['COMPONENT_AJAX_DATA'])?>,
						isEmbedded: <?=$arResult['IS_EMBEDDED'] ? 'true' : 'false'?>,
						customToolPanelButtons: <?=CUtil::PhpToJSObject($arResult['CUSTOM_TOOL_PANEL_BUTTONS'])?>,
						toolPanelButtonsOrder: <?=CUtil::PhpToJSObject($arResult['TOOL_PANEL_BUTTONS_ORDER'])?>,
						restrictions: <?=CUtil::PhpToJSObject($arResult['RESTRICTIONS'])?>,
						canUsePull: <?= CUtil::PhpToJSObject(($arResult['CAN_USE_PULL'] ?? 'N') === 'Y') ?>,
						pullTag: "<?= CUtil::JSEscape($arResult['PULL_TAG'] ?? '') ?>",
						pullModuleId: "<?= CUtil::JSEscape($arResult['PULL_MODULE_ID'] ?? '') ?>",
						analyticsConfig: <?= CUtil::PhpToJSObject($arResult['ANALYTICS_CONFIG'] ?? []) ?>,
						personalViewAllowed: <?= $arResult['PERSONAL_VIEW_ALLOWED'] ? 'true' : 'false'?>,
						mainCanHandleUfTooltips: <?= JSON::encode($arResult['MAIN_CAN_HANDLE_UF_TOOLTIPS']) ?>,
						hostColumnForQuickEditorId: <?= JSON::encode($arResult['HOST_COLUMN_FOR_QUICK_EDITOR_ID']) ?>,
					}
				)
			);
		}
	);
</script>

<?php
GLOBAL $USER;
$userID = $USER->GetID();
$userGroups = $USER->GetUserGroupArray();

$urlParts = explode('/', "//{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}");
$dealInfo = array();
$leadInfo = array();

function getDealInfoForDublTemplate($dealID) {
    $res = CCrmDeal::GetListEx(array("ID" => "ASC"), array("ID" => $dealID), false, false, array("ID","CATEGORY_ID","STAGE_ID"));
    if ($arDeal = $res->Fetch()) return $arDeal;
    return array();
}

function getLeadInfoForDublTemplate($leadID) {
    $res = CCrmLead::GetListEx(array("ID" => "ASC"), array("ID" => $leadID), false, false, array("ID"));
    if ($arLead = $res->Fetch()) return $arLead;
    return array();
}

if (isset($urlParts[4]) && $urlParts[4] == "deal" && isset($urlParts[6]) && is_numeric($urlParts[6]) && $urlParts[6] != 0) {
    $dealInfo = getDealInfoForDublTemplate($urlParts[6]);
}
if (isset($urlParts[4]) && $urlParts[4] == "lead" && isset($urlParts[6]) && is_numeric($urlParts[6]) && $urlParts[6] != 0) {
    $leadInfo = getLeadInfoForDublTemplate($urlParts[6]);
}


?>
<style>
#crm-details-category-changer-container {
    display: none !important;
}

.ui-btn-split.intranet-binding-menu-btn,
button.ui-btn.ui-btn-icon-setting {
    display: none !important;
}

[id*="_convert_"] {
    display: none !important;
}

#crm_entity_bp_starter {
    display: none !important;
}


</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

<script>
BX.ready(function() {
    var lastStage = null;

    setInterval(function() {
        var editor = BX.Crm && BX.Crm.EntityEditor && BX.Crm.EntityEditor.getDefault && BX.Crm.EntityEditor.getDefault();
        var model = editor && editor.getModel && editor.getModel();
        var stage = model && model.getField('STAGE_ID');

        // Skip if nothing changed
        if (stage === lastStage) return;
        lastStage = stage;

        // user_lld2uqra
        var el = document.querySelector("[data-cid='user_lld2uqra']");
        if (el) {
            var hideLld = ['NEW', 'UC_WX29F1', 'PREPARATION'].indexOf(stage) !== -1;
            el.style.display = hideLld ? 'none' : '';
            if (!hideLld) {
                el.style.pointerEvents = 'none';
                el.style.userSelect = 'none';
            }
        }

        // OPPORTUNITY_WITH_CURRENCY
        var opp = document.querySelector("[data-cid='OPPORTUNITY_WITH_CURRENCY']");
        if (opp) {
            opp.style.pointerEvents = 'none';
            opp.style.userSelect = 'none';
        }

        // user_mb1jno9p
        var hiddenStages = ['NEW', 'UC_WX29F1', 'PREPARATION', 'PREPAYMENT_INVOICE', 'FINAL_INVOICE'];
        var mb = document.querySelector("[data-cid='user_mb1jno9p']");
        if (mb) mb.style.display = hiddenStages.indexOf(stage) !== -1 ? 'none' : '';

        // user_qztp1b6v
        var qz = document.querySelector("[data-cid='user_qztp1b6v']");
        if (qz) qz.style.display = (stage && stage.indexOf('LOSE') !== -1) ? '' : 'none';

        // catalog tab
        var catalog = document.getElementById('crm_scope_detail_c_deal__catalog');
        if (catalog) catalog.style.display = stage === 'NEW' ? 'none' : '';

        // more_button
        var hideMoreStages = ['NEW', 'UC_WX29F1', 'PREPARATION', 'PREPAYMENT_INVOICE', 'FINAL_INVOICE', 'EXECUTING', 'UC_NSTB3H', 'UC_NJ7A78'];
        var moreBtn = document.getElementById('crm_scope_detail_c_deal__more_button');
        if (moreBtn) moreBtn.style.display = hideMoreStages.indexOf(stage) !== -1 ? 'none' : '';

		// Hide document button
        var dealId = <?= isset($dealInfo['ID']) ? intval($dealInfo['ID']) : 0 ?>;
        if (dealId) {
            var docBtn = document.getElementById('toolbar_deal_details_' + dealId + '_document');
            if (docBtn) docBtn.style.display = 'none';
        }


        // main-buttons (hide all children except deal scope when stage is NEW)
        var box = document.querySelector('.main-buttons');
        if (box) {
            Array.from(box.children).forEach(function(child) {
                if (child.id === 'crm_scope_detail_c_deal_' || child.querySelector('#crm_scope_detail_c_deal__main')) {
                    child.style.display = '';
                    return;
                }
                child.style.display = stage === 'NEW' ? 'none' : '';
            });
        }

        // user_irhcfh47 (permission-gated field)
        var ih = document.querySelector("[data-cid='user_irhcfh47']");
        if (ih) {
            if (stage === 'NEW') {
                ih.style.display = 'none';
            } else {
                ih.style.display = '';
                if (userID != 1 && userID != 3) {
                    ih.style.pointerEvents = 'none';
                    ih.style.userSelect = 'none';
                    ih.style.opacity = '0.7';
                    ih.querySelectorAll('.ui-entity-editor-field-edit, .ui-entity-editor-field-edit-button').forEach(function(btn) {
                        btn.style.display = 'none';
                    });
                    if (!ih._lockBound) {
                        ih._lockBound = true;
                        ih.addEventListener('click', function(e) {
                            if (userID != 1 && userID != 3) {
                                e.stopPropagation();
                                e.preventDefault();
                            }
                        }, true);
                    }
                } else {
                    ih.style.pointerEvents = '';
                    ih.style.userSelect = '';
                    ih.style.opacity = '';
                    ih.querySelectorAll('.ui-entity-editor-field-edit, .ui-entity-editor-field-edit-button').forEach(function(btn) {
                        btn.style.display = '';
                    });
                }
            }
        }

    }, 500);

    // MutationObserver — scoped, not document.body
    function hideSystemCommentActions() {
        document.querySelectorAll('.crm-timeline__card').forEach(card => {
            var textBlock = card.querySelector('.crm-timeline__editable-text_text');
            if (textBlock && textBlock.innerText.includes("სისტემური კომენტარი:")) {
                var a1 = card.querySelector('.crm-timeline__card-action');
                var a2 = card.querySelector('.crm-timeline__editable-text_edit-icon');
                if (a1) a1.style.display = 'none';
                if (a2) a2.style.display = 'none';
            }
        });
    }

    hideSystemCommentActions();

    // Scope observer to timeline container only, not entire body
    var timelineContainer = document.querySelector('.crm-timeline') || document.body;
    var observer = new MutationObserver(hideSystemCommentActions);
    observer.observe(timelineContainer, { childList: true, subtree: true });
});
</script>

<script>
var userID = <?php echo json_encode($userID, JSON_UNESCAPED_UNICODE); ?>;

(function() {
    var pathname = window.location.pathname.split("/");

    var userGroups = <?php echo json_encode($userGroups); ?>;
    var dealInfo = <?php echo json_encode($dealInfo); ?>;
    var leadInfo = <?php echo json_encode($leadInfo); ?>;

    if ((pathname[2] == "deal" || pathname[2] == "lead") && pathname[3] == "details") {
        var lastPhoneNumber = "";

        setInterval(function() {

			
            if (!document.getElementById("checkSamePhoneInDeals")) {
                var newDiv = document.createElement("div");
                newDiv.id = "checkSamePhoneInDeals";

                var clientElement = document.querySelector("[data-cid='CLIENT']");
                if (clientElement) {
                    clientElement.appendChild(newDiv);
                }
            }

            var phoneField1 = document.querySelector(".crm-entity-widget-client-contact-phone");
            var phoneField2 = document.querySelector(".crm-entity-widget-content-input-phone");
            var phoneField3 = document.querySelector("[data-cid='PHONE']");

            var phoneValue = "";

            if (phoneField1 && phoneField1.textContent) {
                phoneValue = phoneField1.textContent;
            } else if (phoneField2 && phoneField2.value) {
                phoneValue = phoneField2.value;
            } else if (phoneField3 && phoneField3.innerText) {
                phoneValue = phoneField3.innerText;
            }

            if (phoneValue) {
                phoneValue = phoneValue.replace(/\D/g, "");
                phoneValue = phoneValue.slice(-9);

                if (phoneValue.length == 9 && lastPhoneNumber != phoneValue) {
                    lastPhoneNumber = phoneValue;
                    getDealsByPhone(phoneValue, pathname[4]);
                }
            }
        }, 2000);
    }
	

    function getDealsByPhone(phone, id) {
        var type = "deal";
        if (pathname[2] == "lead") type = "lead";

        fetch(location.origin + "/rest/local/api/deal/getByPhone.php?id=" + id + "&phone=" + phone + "&type=" + type)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.status == 200) {
                    var drawDivs = "";

                    if (document.getElementById("checkSamePhoneInDeals")) {
                        document.getElementById("checkSamePhoneInDeals").innerHTML = "";
                        drawDivs += drawFields(data.res.DEALS, "deal");
                        drawDivs += drawFields(data.res.LEADS, "lead");
                    }

                    var total = data.res.DEALS.length + data.res.LEADS.length;
                    document.getElementById("checkSamePhoneInDeals").innerHTML =
                        '<div style="margin-top:10px; font-family: sans-serif;">' +
                            '<div style="' +
                                'display:inline-flex; align-items:center; gap:8px;' +
                                'background:#fff0f0; border:1.5px solid #e53935;' +
                                'border-radius:8px; padding:6px 12px;' +
                                'cursor:pointer; user-select:none;' +
                            '" onclick="(document.getElementById(\'dealsInfosBlock\').style.display===\'none\') ? showDealsInfos() : hideDealsInfos();">' +
                                '<span style="' +
                                    'background:#e53935; color:#fff;' +
                                    'font-size:11px; font-weight:700;' +
                                    'border-radius:50px; padding:2px 8px; letter-spacing:0.5px;' +
                                '">' + total + '</span>' +
                                '<span style="color:#c62828; font-weight:600; font-size:13px;">დუბლირება</span>' +
                                '<i id="showArrow" class="fa fa-chevron-down" style="color:#e53935; font-size:11px;"></i>' +
                                '<i id="hideArrow" class="fa fa-chevron-up" style="color:#e53935; font-size:11px; display:none;"></i>' +
                            '</div>' +
                            '<div id="dealsInfosBlock" style="display:none; margin-top:6px;">' +
                                drawDivs +
                            '</div>' +
                        '</div>';
                } else {
                    if (document.getElementById("checkSamePhoneInDeals")) {
                        document.getElementById("checkSamePhoneInDeals").innerHTML = "";
                    }
                }
            })
            .catch(function(error) { console.error('Error:', error); });
    }

    function drawFields(infos, type) {
        var drawDivs = "";
        for (var i = 0; i < infos.length; i++) {
            var info = infos[i];
            var detailUrl = type == "lead"
                ? location.origin + "/crm/lead/details/" + info["ID"] + "/"
                : location.origin + "/crm/deal/details/" + info["ID"] + "/";

            drawDivs += '<div style="' +
                'display:flex; align-items:center; gap:8px;' +
                'background:#fff; border:1px solid #ffcdd2;' +
                'border-left:3px solid #e53935;' +
                'border-radius:6px; padding:6px 10px; margin-bottom:4px;' +
                'font-family:sans-serif; font-size:12px; color:#333;' +
            '">' +
                '<a href="' + detailUrl + '" style="' +
                    'color:#e53935; font-weight:700; text-decoration:none;' +
                    'background:#ffebee; border-radius:4px; padding:2px 6px; white-space:nowrap;' +
                '">#' + info["ID"] + '</a>' +
                '<span style="color:#666;">📞 ' + info["PHONE"] + '</span>' +
                '<span style="color:#888; background:#f5f5f5; border-radius:4px; padding:1px 6px;">' + info["CATEGORY_NAME"] + '</span>' +
                '<span style="color:#555; margin-left:auto;">👤 ' + info["RESPONSIBLE_NAME"] + '</span>' +
            '</div>';
        }
        return drawDivs;
    }

    window.showDealsInfos = function() {
        var block = document.getElementById("dealsInfosBlock");
        var hideArrow = document.getElementById("hideArrow");
        var showArrow = document.getElementById("showArrow");
        if (block) block.style.display = "block";
        if (hideArrow) hideArrow.style.display = "";
        if (showArrow) showArrow.style.display = "none";
    };

    window.hideDealsInfos = function() {
        var block = document.getElementById("dealsInfosBlock");
        var hideArrow = document.getElementById("hideArrow");
        var showArrow = document.getElementById("showArrow");
        if (block) block.style.display = "none";
        if (hideArrow) hideArrow.style.display = "none";
        if (showArrow) showArrow.style.display = "";
    };
})();
</script>