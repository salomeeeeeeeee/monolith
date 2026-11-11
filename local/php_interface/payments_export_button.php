<?php
// გადახდების სია (23): ღილაკი «Excel ჩამოტვირთვა» სიის თავში, პარამეტრების ღილაკის გვერდით.
// ფაილს ქმნის /crm/deal/reports/paymentsExport.php?download=Y
AddEventHandler('main', 'OnProlog', function () {
	if (defined('ADMIN_SECTION') || !preg_match('#/lists/23/view/#', (string)($_SERVER['REQUEST_URI'] ?? ''))) {
		return;
	}
	\Bitrix\Main\Page\Asset::getInstance()->addString(<<<'HTML'
<script>
(function () {
	function addStyle() {
		if (document.getElementById('payments-export-style')) return;
		var s = document.createElement('style');
		s.id = 'payments-export-style';
		s.textContent =
			'.pay-xl-btn{display:inline-flex;align-items:center;gap:9px;height:38px;padding:0 18px 0 12px;margin-right:10px;' +
			'border-radius:10px;background:linear-gradient(180deg,#22a45d 0%,#17834a 100%);color:#fff!important;text-decoration:none!important;' +
			'font:600 14px/1 "Open Sans","Helvetica Neue",Arial,sans-serif;letter-spacing:.2px;white-space:nowrap;vertical-align:middle;' +
			'box-shadow:0 2px 6px rgba(15,90,50,.35),inset 0 1px 0 rgba(255,255,255,.18);transition:transform .12s,box-shadow .12s,filter .12s}' +
			'.pay-xl-btn:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 5px 14px rgba(15,90,50,.4),inset 0 1px 0 rgba(255,255,255,.2)}' +
			'.pay-xl-btn:active{transform:translateY(0);box-shadow:0 1px 3px rgba(15,90,50,.35)}' +
			'.pay-xl-btn .pay-xl-ico{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:6px;background:#fff}';
		document.head.appendChild(s);
	}
	function addBtn() {
		var box = document.querySelector('.ui-toolbar-right-buttons');
		if (!box || box.querySelector('[data-payments-export]')) return !!box;
		addStyle();
		var a = document.createElement('a');
		a.href = '/crm/deal/reports/paymentsExport.php?download=Y';
		a.className = 'pay-xl-btn';
		a.setAttribute('data-payments-export', '1');
		a.title = 'ყველა გადახდა Excel-ში: თანხები, კურსი, ID-ები რიცხვებად, თარიღები თარიღებად';
		a.innerHTML =
			'<span class="pay-xl-ico"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">' +
			'<path d="M3 1.5h6.5L13 5v9.5H3z" fill="#e8f5ee" stroke="#17834a" stroke-width="1.2" stroke-linejoin="round"/>' +
			'<path d="M9.5 1.5V5H13" fill="none" stroke="#17834a" stroke-width="1.2" stroke-linejoin="round"/>' +
			'<path d="M5.3 7.6l3.4 4.6M8.7 7.6l-3.4 4.6" stroke="#17834a" stroke-width="1.5" stroke-linecap="round"/></svg></span>' +
			'<span>Excel ჩამოტვირთვა</span>';
		box.insertBefore(a, box.firstChild);
		return true;
	}
	function run() { if (!addBtn()) { var n = 0, t = setInterval(function () { if (addBtn() || ++n > 40) clearInterval(t); }, 250); } }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
</script>
HTML
	);
});
