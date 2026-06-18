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

function getDealInfoByIDToolbar2($dealID) {
    $res = CCrmDeal::GetList(["ID" => "ASC"], ["ID" => $dealID], []);
    if ($arDeal = $res->Fetch()) return $arDeal;
    return [];
}

$deal2       = getDealInfoByIDToolbar2($dealId2);
$newStageId2 = $deal2["STAGE_ID"] ?? "";
?>

<script>
(function(){
    var dealId2 = <?php echo json_encode($dealId2); ?>;
    var stage2  = <?php echo json_encode($newStageId2); ?>;

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
        }

        if (stage2 === "EXECUTING") {
            div.dataset.rendered = "1";
            div.insertAdjacentHTML("beforeend",
                '<div onclick="openCalculatorPopup();" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;background:linear-gradient(135deg,#4f46e5,#3730a3);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(79,70,229,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(79,70,229,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(79,70,229,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2" stroke="#fff" stroke-width="1.4"/><line x1="5" y1="11" x2="5" y2="8" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/><line x1="8" y1="11" x2="8" y2="5" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/><line x1="11" y1="11" x2="11" y2="7" stroke="#fff" stroke-width="1.4" stroke-linecap="round"/></svg>კალკულატორი</div>'
            );
            div.insertAdjacentHTML("beforeend",
                '<div style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;margin-left:8px;background:linear-gradient(135deg,#0ca678,#28c7a9);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(12,166,120,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(12,166,120,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(12,166,120,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M2 8l4 4 8-9" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>გაყიდვა</div>'
            );
            div.insertAdjacentHTML("beforeend",
    '<div onclick="(function(){if(typeof BX!==\'undefined\'&&BX.SidePanel){BX.SidePanel.Instance.open(location.origin+\'/crm/deal/docs-generation.php?dealid=<?php echo (int)$dealId2; ?>\',{width:650,cacheable:false,allowChangeHistory:false,title:\'დოკუმენტები\'});}})();" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;margin-left:8px;background:linear-gradient(135deg,#e67700,#f08c00);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(230,119,0,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(230,119,0,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(230,119,0,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="2" y="1.5" width="12" height="13" rx="1.5" stroke="#fff" stroke-width="1.4"/><line x1="4.5" y1="5" x2="11.5" y2="5" stroke="#fff" stroke-width="1.1" stroke-linecap="round"/><line x1="4.5" y1="7.5" x2="11.5" y2="7.5" stroke="#fff" stroke-width="1.1" stroke-linecap="round"/><line x1="4.5" y1="10" x2="8.5" y2="10" stroke="#fff" stroke-width="1.1" stroke-linecap="round"/></svg>დოკუმენტები</div>'
);
}
    }

    if (stage2 === "FINAL_INVOICE" || stage2 === "EXECUTING") {
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

})();


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
</script>