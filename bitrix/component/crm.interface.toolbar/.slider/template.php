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

    // inject button
    var toolbar = document.getElementById("uiToolbarContainer");
    if (toolbar) {
        var wrapper = toolbar.parentElement.parentElement;
        if (!document.getElementById("myButtonsDiv2")) {
            var div = document.createElement("div");
            div.id = "myButtonsDiv2";
            div.style.paddingBottom = "10px";
            wrapper.appendChild(div);
        }
        var stage2 = <?php echo json_encode($newStageId2); ?>;

if (stage2 === "FINAL_INVOICE") {
    document.getElementById("myButtonsDiv2").insertAdjacentHTML("beforeend",
        '<div onclick="openJavshnisVadaPopup();" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;background:linear-gradient(135deg,#3b5bdb,#7048e8);color:#fff;font-size:12px;font-weight:600;border-radius:20px;cursor:pointer;box-shadow:0 2px 8px rgba(59,91,219,.35);letter-spacing:.3px;transition:all .2s;font-family:\'Noto Sans Georgian\',sans-serif;" onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 18px rgba(59,91,219,.45)\';" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(59,91,219,.35)\';"><svg width="13" height="13" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="#fff" stroke-width="1.4"/><path d="M8 5v3.5M8 11v.5" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/></svg>ჯავშნის ვადის ცვლილება</div>'
    );
}
    }

    // inject popup HTML
    document.body.insertAdjacentHTML("beforeend", `
        <div id="jvOverlay" style="display:none;position:fixed;inset:0;background:rgba(10,12,20,.55);backdrop-filter:blur(6px);z-index:4000;justify-content:center;align-items:center;">
          <div style="position:relative;width:520px;max-width:95vw;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 12px 48px rgba(0,0,0,.22);border:.5px solid #d0d0d0;font-family:'Noto Sans Georgian',sans-serif;">

            <div style="background:#3b5bdb;padding:11px 18px;display:flex;align-items:center;justify-content:space-between;">
              <span style="font-size:14px;font-weight:600;color:#fff;">რეზერვაციის ცვლილება</span>
              <span onclick="closeJavshnisVadaPopup()" style="cursor:pointer;opacity:.85;display:flex;">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><line x1="2" y1="2" x2="12" y2="12" stroke="#fff" stroke-width="2" stroke-linecap="round"/><line x1="12" y1="2" x2="2" y2="12" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>
              </span>
            </div>

            <div style="padding:18px;background:#f4f6f8;">
              <div style="background:#fff;border:.5px solid #e0e0e0;border-radius:10px;padding:18px;">

                <div style="margin-bottom:14px;">
                  <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;"><span style="color:#d85a30;">*</span> რეზერვაციის ტიპი</label>
                  <select id="jvUserSelect" onchange="jvToggleFields()" style="width:100%;height:34px;border-radius:6px;border:1px solid #d0d0d0;padding:0 10px;font-size:13px;background:#fff;color:#333;font-family:'Noto Sans Georgian',sans-serif;">
                    <option value="">აირჩიეთ რეზერვაციის ტიპი</option>
                    <option value="41">სტანდარტული</option>
                    <option value="42">არასტანდარტული</option>
                  </select>
                </div>

                <div id="jvDateWrap" style="margin-bottom:14px;display:none;">
                  <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;"><span style="color:#d85a30;">*</span> რეზერვაციის ვადა</label>
                  <input type="text" id="jvDate" style="width:100%;height:34px;border-radius:6px;border:1px solid #d0d0d0;padding:0 10px;font-size:13px;font-family:'Noto Sans Georgian',sans-serif;background:#f4f6f8;color:#555;cursor:default;" readonly />
                </div>

                <div id="jvPayTypeWrap" style="margin-bottom:14px;display:none;">
                  <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;"><span style="color:#d85a30;">*</span> სასურველი გადახდის ტიპი</label>
                  <select id="jvPayType" style="width:100%;height:34px;border-radius:6px;border:1px solid #d0d0d0;padding:0 10px;font-size:13px;background:#fff;color:#333;font-family:'Noto Sans Georgian',sans-serif;">
                    <option value="">აირჩიეთ გადახდის ტიპი</option>
                    <option value="ნაღდი">ნაღდი</option>
                    <option value="ჩარიცხვა">ჩარიცხვა</option>
                    <option value="ტერმინალი">ტერმინალი</option>
                  </select>
                </div>

                <div id="jvAmountWrap" style="margin-bottom:14px;display:none;">
                  <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;"><span style="color:#d85a30;">*</span> რეზერვაციის ფასი</label>
                  <input type="text" id="jvAmount" placeholder="0.00" style="width:100%;height:34px;border-radius:6px;border:1px solid #d0d0d0;padding:0 10px;font-size:13px;font-family:'Noto Sans Georgian',sans-serif;" />
                </div>

                <div style="margin-bottom:4px;">
                  <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;">ატვირთეთ პირადობა ან პასპორტი</label>
                  <input id="jvPassportInput" type="file" style="display:none;"
                    accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                    onchange="jvHandleFile()" />
                  <div id="jvDropZone" onclick="document.getElementById('jvPassportInput').click()"
                    style="border:1.5px dashed #4c6ef5;border-radius:7px;padding:14px 12px;text-align:center;cursor:pointer;background:#f0f4ff;">
                    <svg width="22" height="22" viewBox="0 0 28 28" fill="none" style="margin:0 auto 6px;display:block;">
                      <rect x="2" y="6" width="24" height="18" rx="3" stroke="#3b5bdb" stroke-width="1.5" fill="none"/>
                      <path d="M9 6V5a5 5 0 0 1 10 0v1" stroke="#3b5bdb" stroke-width="1.5" stroke-linecap="round"/>
                      <path d="M14 12v6M11 15l3-3 3 3" stroke="#3b5bdb" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <p style="margin:0;font-size:12px;color:#3b5bdb;font-weight:500;">Click to upload</p>
                    <p style="margin:3px 0 0;font-size:11px;color:#6b7280;">ID card or passport — JPG, PNG, PDF</p>
                  </div>
                  <div id="jvFileChosen" style="display:none;margin-top:6px;background:#e8edff;border:.5px solid #4c6ef5;border-radius:7px;padding:7px 10px;align-items:center;gap:8px;">
                    <span id="jvFileName" style="font-size:12px;color:#3b5bdb;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                  </div>
                </div>
              </div>

              <div id="jvMsgSuccess" style="display:none;color:#3b6d11;background:#eaf3de;border:.5px solid #97c459;border-radius:7px;text-align:center;padding:8px 14px;margin-top:12px;font-size:13px;">მოთხოვნა გაიგზავნა წარმატებით</div>
              <div id="jvMsgError"   style="display:none;color:#993c1d;background:#faece7;border:.5px solid #f0997b;border-radius:7px;text-align:center;padding:8px 14px;margin-top:12px;font-size:13px;">შეცდომა გაგზავნისას</div>
              <div id="jvMsgWarn"    style="display:none;color:#854f0b;background:#faeeda;border:.5px solid #ef9f27;border-radius:7px;text-align:center;padding:8px 14px;margin-top:12px;font-size:13px;">გთხოვთ შეავსოთ ყველა სავალდებულო ველი</div>
            </div>

            <div id="jvFooter" style="display:none;padding:10px 18px;background:#fff;border-top:.5px solid #e0e0e0;justify-content:flex-end;gap:8px;align-items:center;">
              <span onclick="closeJavshnisVadaPopup()" style="cursor:pointer;font-size:12px;padding:5px 14px;border-radius:6px;border:1px solid #f0997b;color:#f1361b;line-height:22px;">გაუქმება</span>
              <span id="jvSendBtn" onclick="submitJavshnisVada()" style="cursor:pointer;font-size:12px;padding:5px 14px;border-radius:6px;background:#3b5bdb;color:#fff;font-weight:500;line-height:22px;">გაგზავნა</span>
            </div>
          </div>
        </div>
    `);

    // ── close on overlay click ──
    document.getElementById("jvOverlay").addEventListener("click", function(e) {
        if (e.target === this) closeJavshnisVadaPopup();
    });

    // ── functions ──
    window.openJavshnisVadaPopup = function() {
        ["jvUserSelect","jvAmount","jvPayType"].forEach(function(id) {
            var el = document.getElementById(id); if (el) el.value = "";
        });
        ["jvDateWrap","jvPayTypeWrap","jvAmountWrap","jvFooter","jvMsgSuccess","jvMsgError","jvMsgWarn"].forEach(function(id) {
            document.getElementById(id).style.display = "none";
        });
        document.getElementById("jvDropZone").style.display   = "";
        document.getElementById("jvFileChosen").style.display = "none";
        document.getElementById("jvFileName").textContent     = "";
        document.getElementById("jvPassportInput").value      = "";

        var overlay = document.getElementById("jvOverlay");
        overlay.style.display = "flex";
        requestAnimationFrame(function() {
            overlay.style.opacity = "0";
            requestAnimationFrame(function() {
                overlay.style.transition = "opacity .25s";
                overlay.style.opacity    = "1";
            });
        });
    };

    window.closeJavshnisVadaPopup = function() {
        var overlay = document.getElementById("jvOverlay");
        overlay.style.opacity = "0";
        setTimeout(function() { overlay.style.display = "none"; }, 250);
    };

    window.jvToggleFields = function() {
        var choice = document.getElementById("jvUserSelect").value;
        var now    = new Date();

        ["jvDateWrap","jvPayTypeWrap","jvAmountWrap","jvFooter"].forEach(function(id) {
            document.getElementById(id).style.display = "none";
        });

        function addWorkingDays(date, days) {
            var r = new Date(date), added = 0;
            while (added < days) {
                r.setDate(r.getDate() + 1);
                var d = r.getDay();
                if (d !== 0 && d !== 6) added++;
            }
            return r;
        }

        function toDisplay(date) {
            var p = function(n) { return String(n).padStart(2, "0"); };
            return p(date.getDate()) + "/" + p(date.getMonth() + 1) + "/" + date.getFullYear();
        }

        if (choice === "41") {
            document.getElementById("jvDate").value           = toDisplay(new Date(now.getTime() + 24*60*60*1000));
            document.getElementById("jvDateWrap").style.display = "block";
            document.getElementById("jvFooter").style.display  = "flex";
        }
        if (choice === "42") {
            document.getElementById("jvDate").value              = toDisplay(addWorkingDays(now, 10));
            document.getElementById("jvDateWrap").style.display    = "block";
            document.getElementById("jvPayTypeWrap").style.display = "block";
            document.getElementById("jvAmountWrap").style.display  = "block";
            document.getElementById("jvFooter").style.display      = "flex";
        }
    };

    window.jvHandleFile = function() {
        var inp  = document.getElementById("jvPassportInput");
        var file = inp.files[0];
        if (!file) return;
        var allowed = ["image/jpeg","image/png","application/pdf"];
        if (!allowed.includes(file.type)) {
            alert("დაშვებულია მხოლოდ JPG, PNG ან PDF ფორმატი");
            inp.value = ""; return;
        }
        if (file.size > 10*1024*1024) {
            alert("ფაილის ზომა არ უნდა აღემატებოდეს 10MB-ს");
            inp.value = ""; return;
        }
        document.getElementById("jvFileName").textContent     = file.name;
        document.getElementById("jvDropZone").style.display   = "none";
        document.getElementById("jvFileChosen").style.display = "flex";
    };

    window.submitJavshnisVada = function() {
        var type    = document.getElementById("jvUserSelect").value;
        var date    = document.getElementById("jvDate").value;
        var payType = document.getElementById("jvPayType").value;
        var amount  = document.getElementById("jvAmount").value;
        var fileInp = document.getElementById("jvPassportInput");

        if (!type || !date) { jvShowMsg("warn"); return; }
        if (type === "42" && (!payType || !amount)) { jvShowMsg("warn"); return; }

        var formData = new FormData();
        formData.append("deal_id",          dealId2);
        formData.append("userSelect",       type);
        formData.append("reserveDate",      date);
        formData.append("reservationPrice", amount);
        formData.append("paymentType",      payType);
        if (fileInp.files[0]) formData.append("passport", fileInp.files[0]);

        var btn = document.getElementById("jvSendBtn");
        btn.style.opacity = ".6"; btn.style.pointerEvents = "none";

        fetch("/rest/local/api/projects/saveReservation.php", { method: "POST", body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 200) {
                    jvShowMsg("success");
                    setTimeout(closeJavshnisVadaPopup, 2000);
                } else {
                    jvShowMsg("error");
                    btn.style.opacity = "1"; btn.style.pointerEvents = "";
                }
            })
            .catch(function() {
                jvShowMsg("error");
                btn.style.opacity = "1"; btn.style.pointerEvents = "";
            });
    };

    window.jvShowMsg = function(type) {
        ["jvMsgSuccess","jvMsgError","jvMsgWarn"].forEach(function(id) {
            document.getElementById(id).style.display = "none";
        });
        var map = { success: "jvMsgSuccess", error: "jvMsgError", warn: "jvMsgWarn" };
        if (map[type]) document.getElementById(map[type]).style.display = "block";
    };

})();
</script>