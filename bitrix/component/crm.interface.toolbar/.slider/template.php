<?php

if(!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\UI\Extension;
use Bitrix\UI\Toolbar\ButtonLocation as ButtonLocation;
use Bitrix\UI\Buttons;

/** @var array $arParams */
/** @global \CMain $APPLICATION */
/** @var \CBitrixComponent $component */
/** @var \CBitrixComponentTemplate $this */

if (!\Bitrix\Main\Loader::includeModule('ui'))
{
	return;
}

CJSCore::RegisterExt('popup_menu', [
	'js' => [
		'/bitrix/js/main/popup_menu.js',
	],
]);
Extension::load('crm.client-selector');
Extension::load('ui.buttons');
Extension::load('ui.buttons.icons');
Extension::load('main.popup');

$toolbarID = $arParams['TOOLBAR_ID'];
$prefix =  $toolbarID.'_';

$items = [];
$moreItems = [];
$restAppButtons = [];
$communicationPanel = null;
$documentButton = null;
$enableMoreButton = false;

foreach($arParams['BUTTONS'] as $item)
{
	if(!$enableMoreButton && isset($item['NEWBAR']) && $item['NEWBAR'] === true)
	{
		$enableMoreButton = true;
		continue;
	}

	if(isset($item['TYPE']) && $item['TYPE'] === 'crm-communication-panel')
	{
		$communicationPanel = $item;
		continue;
	}

	if(isset($item['TYPE']) && $item['TYPE'] === 'crm-document-button')
	{
		$documentButton = $item;
		continue;
	}

	if(isset($item['TYPE']) && $item['TYPE'] === 'rest-app-toolbar')
	{
		$restAppButtons[] = $item;
		continue;
	}

	if($enableMoreButton)
	{
		$moreItems[] = $item;
	}
	else
	{
		$items[] = $item;
	}
}

$buttons = [];

$bindingMenuMask = '/(lead|deal|invoice|quote|company|contact).*?([\d]+)/i';
if (preg_match($bindingMenuMask, $arParams['TOOLBAR_ID'], $bindingMenuMatches) && Buttons\IntranetBindingMenu::isAvailable())
{
	Extension::load('bizproc.script');

	$buttons[ButtonLocation::RIGHT][] = Buttons\IntranetBindingMenu::createByComponentParameters([
		'SECTION_CODE' => \Bitrix\Crm\Integration\Intranet\BindingMenu\SectionCode::DETAIL,
		'MENU_CODE' => $bindingMenuMatches[1],
		'CONTEXT' => [
			'ID' => $bindingMenuMatches[2],
		],
	]);
}

$communications = [];

if ($communicationPanel)
{
	$data = isset($communicationPanel['DATA']) && is_array($communicationPanel['DATA']) ? $communicationPanel['DATA'] : [];
	$multifields = isset($data['MULTIFIELDS']) && is_array($data['MULTIFIELDS']) ? $data['MULTIFIELDS'] : [];

	$ownerInfo = isset($data['OWNER_INFO']) && is_array($data['OWNER_INFO']) ? $data['OWNER_INFO'] : [];

	$communications = [
		'ownerInfo' => $ownerInfo,
		'arrangedMultiFields' => $multifields,
	];
}

if($enableMoreButton)
{
	$buttons[ButtonLocation::RIGHT][] = new Buttons\SettingsButton();

	?><script>
		BX.ready(
			function()
			{
				BX.InterfaceToolBar.create(
					"<?=CUtil::JSEscape($toolbarID)?>",
					BX.CrmParamBag.create(
						{
							'containerId': 'uiToolbarContainer',
							'items': <?=CUtil::PhpToJSObject($moreItems)?>,
							"moreButtonClassName": "<?= Buttons\Icon::SETTING ?>"
						}
					)
				);
			}
		);
	</script><?php
}

if ($documentButton)
{
	$buttons[ButtonLocation::RIGHT][] = new Buttons\DocumentButton([
		'domId' => $toolbarID.'_document',
		'documentButtonConfig' => $documentButton['PARAMS'],
	]);
}

foreach($items as $item)
{
	$type = isset($item['TYPE']) ? $item['TYPE'] : '';
	$code = isset($item['CODE']) ? $item['CODE'] : '';
	$visible = isset($item['VISIBLE']) ? (bool)$item['VISIBLE'] : true;
	$text = isset($item['TEXT']) ? htmlspecialcharsbx(strip_tags($item['TEXT'])) : '';
	$title = isset($item['TITLE']) ? htmlspecialcharsbx(strip_tags($item['TITLE'])) : '';
	$link = isset($item['LINK']) ? htmlspecialcharsbx($item['LINK']) : '#';
	$icon = isset($item['ICON']) ? htmlspecialcharsbx($item['ICON']) : '';
	$onClick = isset($item['ONCLICK']) ? htmlspecialcharsbx($item['ONCLICK']) : '';

	// this button is very likely dead, but for consistecy with other templates leave it be
	if($type === 'crm-context-menu')
	{
		$menuItems = isset($item['ITEMS']) && is_array($item['ITEMS']) ? $item['ITEMS'] : [];

		$contextMenuButton = new Buttons\Split\Button([
			'text' => $text,
			'color' => Buttons\Color::PRIMARY,
			'className' => 'crm-btn-toolbar-menu', // for js
		]);
		if (!empty($onClick))
		{
			$contextMenuButton->bindEvent('click', new Buttons\JsCode($onClick));
		}
		if (!empty($menuItems))
		{
			?><script>
				BX.ready(
					function()
					{
						BX.InterfaceToolBar.create(
							"<?=CUtil::JSEscape($toolbarID)?>",
							BX.CrmParamBag.create(
								{
									'containerId': "uiToolbarContainer",
									'prefix': '',
									'menuButtonClassName': 'crm-btn-toolbar-menu',
									'items': <?=CUtil::PhpToJSObject($menuItems)?>
								}
							)
						);
					}
				);
			</script><?php
		}

		$buttons[ButtonLocation::RIGHT][] = $contextMenuButton;
	}
	elseif($type == 'toolbar-conv-scheme')
	{
		$params = isset($item['PARAMS']) ? $item['PARAMS'] : [];

		// $containerID = $params['CONTAINER_ID'] ?? null; //not used now, but can be useful later
		$labelID = $params['LABEL_ID'] ?? null;
		$buttonID = $params['BUTTON_ID'] ?? null;
		$schemeDescr = isset($params['SCHEME_DESCRIPTION']) ? $params['SCHEME_DESCRIPTION'] : null;

		$labelID = empty($labelID) ? "{$prefix}{$code}_label" : $labelID;
		$buttonID = empty($buttonID) ? "{$prefix}{$code}_button" : $buttonID;

		$convButton = new Buttons\Split\Button([
			'text' => $schemeDescr,
		]);
		if (isset($item['PRIMARY']) && $item['PRIMARY'] === true)
		{
			$convButton->setColor(Buttons\Color::PRIMARY);
		}
		else
		{
			$convButton->setColor(Buttons\Color::LIGHT_BORDER);
		}

		$convButton->setStyle(Buttons\AirButtonStyle::FILLED);
		$convButton->getMainButton()->addAttribute('id', $labelID);
		$convButton->getMenuButton()->addAttribute('id', $buttonID);

		$buttons[ButtonLocation::RIGHT][] = $convButton;
	}
	else
	{
		$fallbackButton = new Buttons\Button([
			'color' => Buttons\Color::PRIMARY,
			'link' => $link,
			'title' => $title,
			'text' => $text,
		]);

		if (!empty($icon))
		{
			$fallbackButton->addClass($icon);
		}

		if (!empty($onClick))
		{
			$fallbackButton->bindEvent('click', new Buttons\JsCode($onClick));
		}

		$buttons[ButtonLocation::RIGHT][] = $fallbackButton;
	}
}

/** @see \Bitrix\Crm\Component\Base::addToolbar - copy-paste */

$bodyClass = $APPLICATION->GetPageProperty('BodyClass');
$APPLICATION->SetPageProperty('BodyClass', ($bodyClass ? $bodyClass . ' ' : '') . 'crm-pagetitle-view');

$this->SetViewTarget('below_pagetitle', 100);
$APPLICATION->IncludeComponent(
	'bitrix:crm.toolbar',
	'',
	[
		'buttons' => $buttons, //ui.toolbar buttons
		'filter' => [], //filter options
		'views' => [],
		'communications' => $communications,
		'isWithFavoriteStar' => false,
		'spotlight' => null,
		'afterTitleHtml' => null,
	],
	$component,
);
$this->EndViewTarget();

// ── ჯავშნის ვადის ცვლილება ──────────────────────────────────────────
$url2        = explode('/', "//{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}");
$dealId2     = $url2[6] ?? 0;

// განაცხადის რეგ.ნომერი (სრეს) — სავალდებულო ველი ხელშეკრულების ეტაპისთვის
$regNumberField2 = 'UF_CRM_1782206133733';

function getDealInfoByIDToolbar2($dealID, $regField) {
    $res = CCrmDeal::GetList(
        ["ID" => "ASC"],
        ["ID" => $dealID],
        ["ID", "STAGE_ID", $regField]
    );
    if ($arDeal = $res->Fetch()) return $arDeal;
    return [];
}

$deal2       = getDealInfoByIDToolbar2($dealId2, $regNumberField2);
$newStageId2 = $deal2["STAGE_ID"] ?? "";
$regNumber2  = trim((string)($deal2[$regNumberField2] ?? ""));
?>

<script>
(function(){
    var dealId2    = <?php echo json_encode($dealId2); ?>;
    var stage2     = <?php echo json_encode($newStageId2); ?>;
    var regNumber2 = <?php echo json_encode($regNumber2, JSON_UNESCAPED_UNICODE); ?>;

    // WON in default pipeline, or C1:WON / C2:WON ... in other pipelines
    var isWon = /(^|:)WON$/.test(stage2 || '');

    var financialBtnHtml =
        '<div onclick="openFinancialCardPopup();" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;background:linear-gradient(135deg,#1c7ed6,#15aabf);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(28,126,214,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(28,126,214,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(28,126,214,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="1.5" y="3.5" width="13" height="9" rx="1.5" stroke="#fff" stroke-width="1.4"/><line x1="1.5" y1="6.5" x2="14.5" y2="6.5" stroke="#fff" stroke-width="1.4"/><line x1="4" y1="10" x2="7" y2="10" stroke="#fff" stroke-width="1.3" stroke-linecap="round"/></svg>Financial card</div>';

    function ensureDiv() {
        var existing = document.getElementById("myButtonsDiv2");
        if (existing) return existing;
        var toolbar = document.getElementById("uiToolbarContainer");
        if (!toolbar) return null;
        var wrapper = toolbar.parentElement.parentElement;
        var div = document.createElement("div");
        div.id = "myButtonsDiv2";
        div.style.paddingBottom = "10px";
        wrapper.appendChild(div);
        return div;
    }

    function renderButtons() {
        var div = ensureDiv();
        if (!div || div.dataset.rendered) return;

        if (stage2 === "FINAL_INVOICE") {
            div.dataset.rendered = "1";
            div.insertAdjacentHTML("beforeend",
                '<div onclick="openCalculatorPopup();" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;margin-right:8px;background:linear-gradient(135deg,#4f46e5,#3730a3);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(79,70,229,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(79,70,229,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(79,70,229,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2" stroke="#fff" stroke-width="1.4"/><line x1="5" y1="11" x2="5" y2="8" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/><line x1="8" y1="11" x2="8" y2="5" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/><line x1="11" y1="11" x2="11" y2="7" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/></svg>კალკულატორი</div>'
            );
            div.insertAdjacentHTML("beforeend",
                '<div onclick="openJavshnisVadaPopup();" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;background:linear-gradient(135deg,#3b5bdb,#7048e8);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(59,91,219,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(59,91,219,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(59,91,219,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="#fff" stroke-width="1.4"/><path d="M8 5v3.5M8 11v.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>რეზერვაციის ცვლილება</div>'
            );
            // ხელშეკრულების ეტაპი — ბიზნეს-პროცესი #98
            div.insertAdjacentHTML("beforeend",
                '<div onclick="openContractStagePopup();" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;margin-left:8px;background:linear-gradient(135deg,#0b7285,#1098ad);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(11,114,133,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(11,114,133,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(11,114,133,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M3 2.5h7l3 3v8a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-10a1 1 0 0 1 1-1z" stroke="#fff" stroke-width="1.4" stroke-linejoin="round"/><path d="M5 10.5c1-1.5 1.8-1.5 2.3-.4.4.9 1 .9 1.7-.1" stroke="#fff" stroke-width="1.2" stroke-linecap="round" fill="none"/><line x1="5" y1="6.5" x2="9" y2="6.5" stroke="#fff" stroke-width="1.1" stroke-linecap="round"/></svg>ხელშეკრულების ეტაპი</div>'
            );
        }

        if (stage2 === "EXECUTING") {
            div.dataset.rendered = "1";
            div.insertAdjacentHTML("beforeend",
                '<div onclick="openCalculatorPopup();" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;background:linear-gradient(135deg,#4f46e5,#3730a3);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(79,70,229,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(79,70,229,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(79,70,229,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2" stroke="#fff" stroke-width="1.4"/><line x1="5" y1="11" x2="5" y2="8" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/><line x1="8" y1="11" x2="8" y2="5" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/><line x1="11" y1="11" x2="11" y2="7" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/></svg>კალკულატორი</div>'
            );
            div.insertAdjacentHTML("beforeend",
                '<div onclick="(function(){if(typeof BX!==\'undefined\'&&BX.SidePanel){BX.SidePanel.Instance.open(location.origin+\'/crm/deal/saveSellFlat.php?deal_id=<?php echo (int)$dealId2; ?>\',{width:650,cacheable:false,allowChangeHistory:false,title:\'გაყიდვა\'});}})()" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;margin-left:8px;background:linear-gradient(135deg,#0ca678,#28c7a9);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(12,166,120,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(12,166,120,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(12,166,120,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M2 8l4 4 8-9" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>გაყიდვა</div>'
            );
            div.insertAdjacentHTML("beforeend",
                '<div onclick="(function(){if(typeof BX!==\'undefined\'&&BX.SidePanel){BX.SidePanel.Instance.open(location.origin+\'/crm/deal/docs-generation.php?dealid=<?php echo (int)$dealId2; ?>\',{width:650,cacheable:false,allowChangeHistory:false,title:\'დოკუმენტები\'});}})();" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;margin-left:8px;background:linear-gradient(135deg,#e67700,#f08c00);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(230,119,0,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(230,119,0,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(230,119,0,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="2" y="1.5" width="12" height="13" rx="1.5" stroke="#fff" stroke-width="1.4"/><line x1="4.5" y1="5" x2="11.5" y2="5" stroke="#fff" stroke-width="1.1" stroke-linecap="round"/><line x1="4.5" y1="7.5" x2="11.5" y2="7.5" stroke="#fff" stroke-width="1.1" stroke-linecap="round"/><line x1="4.5" y1="10" x2="8.5" y2="10" stroke="#fff" stroke-width="1.1" stroke-linecap="round"/></svg>დოკუმენტები</div>'
            );
            // ხელშეკრულების ვადის გაგრძელება (5 სამუშაო დღე; ნუკის დასტურით, კომენტარი სავალდებულო)
            div.insertAdjacentHTML("beforeend",
                '<div onclick="openExtendContractPopup();" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;margin-left:8px;background:linear-gradient(135deg,#3b5bdb,#7048e8);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(59,91,219,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(59,91,219,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(59,91,219,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="#fff" stroke-width="1.4"/><path d="M8 4.5V8l2.5 1.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>ვადის გაგრძელება</div>'
            );
        }

        if (isWon) {
            div.dataset.rendered = "1";
            div.insertAdjacentHTML("beforeend", financialBtnHtml);
        }
    }

    if (stage2 === "FINAL_INVOICE" || stage2 === "EXECUTING" || isWon) {
        var tries = 0;
        var iv = setInterval(function() {
            tries++;
            if (ensureDiv()) {
                renderButtons();
                clearInterval(iv);
            }
            if (tries > 40) clearInterval(iv);
        }, 500);
    }

    window.openJavshnisVadaPopup = function() {
        if (typeof BX !== 'undefined' && BX.SidePanel) {
            BX.SidePanel.Instance.open(
                location.origin + '/rest/local/api/projects/updateReservation.php?deal_id=' + dealId2,
                {
                    width: 800,
                    cacheable: false,
                    allowChangeHistory: false,
                    title: 'რეზერვაციის ვადის ცვლილება'
                }
            );
        }
    };

    window.openExtendContractPopup = function() {
        if (typeof BX !== 'undefined' && BX.SidePanel) {
            BX.SidePanel.Instance.open(
                location.origin + '/rest/local/api/projects/extendContract.php?deal_id=' + dealId2,
                {
                    width: 800,
                    cacheable: false,
                    allowChangeHistory: false,
                    title: 'ხელშეკრულების ვადის გაგრძელება'
                }
            );
        }
    };

    // ── ხელშეკრულების ეტაპი: დადასტურების popup → ბიზნეს-პროცესი #98 ──
    // სავალდებულო ველი: განაცხადის რეგ.ნომერი (სრეს) — UF_CRM_1782206133733
    window.openContractStagePopup = function() {
        if (typeof BX === 'undefined' || !BX.PopupWindow) {
            alert('Popup library is not loaded');
            return;
        }

        var popupId = 'contractStagePopup_' + dealId2;
        if (BX.PopupWindowManager && BX.PopupWindowManager.getPopupById) {
            var old = BX.PopupWindowManager.getPopupById(popupId);
            if (old) old.destroy();
        }

        var inputId = 'contractStageRegNumber_' + dealId2;
        var errId   = 'contractStageError_' + dealId2;

        var content = document.createElement('div');
        content.style.cssText = 'padding:6px 4px 2px;max-width:360px;font-family:\'Noto Sans Georgian\',sans-serif;';
        content.innerHTML =
            '<div style="display:flex;gap:10px;align-items:flex-start;padding:12px 14px;background:#fff4e6;border:1px solid #ffd8a8;border-radius:10px;color:#a14a00;font-size:13px;line-height:1.55;">' +
                '<svg width="18" height="18" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;margin-top:1px;"><path d="M8 1.8l6.5 11.4H1.5L8 1.8z" stroke="#e67700" stroke-width="1.4" stroke-linejoin="round"/><path d="M8 6.5v3M8 11.3v.4" stroke="#e67700" stroke-width="1.5" stroke-linecap="round"/></svg>' +
                '<span>პროცესის გაშვებით დილი გადავა ხელშეკრულების ეტაპზე</span>' +
            '</div>' +
            '<div style="margin-top:14px;">' +
                '<label for="' + inputId + '" style="display:block;margin-bottom:6px;font-size:12px;font-weight:600;color:#333;">' +
                    'განაცხადის რეგ.ნომერი (სრეს) <span style="color:#e03131;">*</span>' +
                '</label>' +
                '<input type="text" id="' + inputId + '" autocomplete="off" ' +
                    'style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #ced4da;border-radius:8px;font-size:13px;font-family:inherit;outline:none;transition:border-color .15s;" ' +
                    'placeholder="შეიყვანეთ რეგისტრაციის ნომერი">' +
            '</div>' +
            '<div id="' + errId + '" style="display:none;margin-top:10px;padding:8px 12px;background:#fff5f5;border:1px solid #ffc9c9;border-radius:8px;color:#c92a2a;font-size:12px;"></div>';

        // value set via DOM (not innerHTML) so it is never interpreted as HTML
        var regInput = content.querySelector('#' + inputId);
        regInput.value = regNumber2 || '';
        regInput.addEventListener('input', function() {
            regInput.style.borderColor = '#ced4da';
            var e = document.getElementById(errId);
            if (e) e.style.display = 'none';
        });
        regInput.addEventListener('focus', function() { regInput.style.borderColor = '#1098ad'; });
        regInput.addEventListener('blur',  function() { regInput.style.borderColor = '#ced4da'; });

        var popup = new BX.PopupWindow(popupId, null, {
            content: content,
            titleBar: 'ხელშეკრულების ეტაპი',
            closeIcon: true,
            closeByEsc: true,
            autoHide: false,
            draggable: true,
            overlay: { opacity: 30 },
            minWidth: 380,
            events: {
                onPopupShow: function() {
                    setTimeout(function() { if (!regInput.value) regInput.focus(); }, 50);
                },
                onPopupClose: function() { this.destroy(); }
            },
            buttons: [
                new BX.PopupWindowButton({
                    text: 'პროცესის გაშვება',
                    className: 'ui-btn ui-btn-success',
                    events: {
                        click: function() {
                            var btn = this;
                            var node = btn.buttonNode;
                            if (node.dataset.busy) return;

                            var errBox = document.getElementById(errId);
                            errBox.style.display = 'none';

                            // ── სავალდებულო ველის შემოწმება ──
                            var regValue = (regInput.value || '').trim();
                            if (regValue === '') {
                                regInput.style.borderColor = '#e03131';
                                regInput.focus();
                                errBox.textContent = 'გთხოვთ შეავსოთ ველი „განაცხადის რეგ.ნომერი (სრეს)"';
                                errBox.style.display = 'block';
                                return;
                            }

                            node.dataset.busy = '1';
                            BX.addClass(node, 'ui-btn-wait');

                            BX.ajax({
                                url: '/rest/local/api/projects/startContractStageWorkflow.php',
                                method: 'POST',
                                dataType: 'json',
                                data: {
                                    deal_id: dealId2,
                                    reg_number: regValue,
                                    sessid: BX.bitrix_sessid()
                                },
                                onsuccess: function(res) {
                                    if (res && res.status === 'success') {
                                        btn.popupWindow.close();
                                        if (BX.UI && BX.UI.Notification) {
                                            BX.UI.Notification.Center.notify({ content: 'პროცესი წარმატებით გაეშვა', autoHideDelay: 2500 });
                                        }
                                        setTimeout(function() { location.reload(); }, 1200);
                                    } else {
                                        delete node.dataset.busy;
                                        BX.removeClass(node, 'ui-btn-wait');
                                        errBox.textContent = (res && res.message) ? res.message : 'შეცდომა პროცესის გაშვებისას';
                                        errBox.style.display = 'block';
                                    }
                                },
                                onfailure: function() {
                                    delete node.dataset.busy;
                                    BX.removeClass(node, 'ui-btn-wait');
                                    errBox.textContent = 'სერვერთან კავშირის შეცდომა';
                                    errBox.style.display = 'block';
                                }
                            });
                        }
                    }
                }),
                new BX.PopupWindowButtonLink({
                    text: 'გაუქმება',
                    className: 'popup-window-button-link-cancel',
                    events: {
                        click: function() { this.popupWindow.close(); }
                    }
                })
            ]
        });

        popup.show();
    };

    window.openCalculatorPopup = function() {
        if (typeof BX !== 'undefined' && BX.SidePanel) {
            BX.SidePanel.Instance.open(
                location.origin + '/custom/calculator/?dealid=' + dealId2,
                {
                    width: 1100,
                    cacheable: false,
                    allowChangeHistory: false,
                    title: 'განვადების კალკულატორი'
                }
            );
        }
    };

    window.openFinancialCardPopup = function() {
        window.open('/custom/reports/dealFinancialReport.php?dealid=' + dealId2);
    };

    window.openDocsGenerationPopup = function() {
        if (typeof BX !== 'undefined' && BX.SidePanel) {
            BX.SidePanel.Instance.open(
                location.origin + '/crm/deal/docs-generation.php?dealid=' + dealId2,
                {
                    width: 650,
                    cacheable: false,
                    allowChangeHistory: false,
                    title: 'დოკუმენტები'
                }
            );
        }
    };

})();
</script>