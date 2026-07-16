<?php
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');
CJSCore::Init(['jquery']);
$APPLICATION->SetTitle('ფინანსური სტატისტიკები');
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    /* Hide Bitrix chrome for this page */
    #header,
    .bx-layout-header,
    #left-panel,
    .menu-items-block,
    #bx_left_menu_menu,
    .main-buttons,
    .pagetitle-wrap,
    .pagetitle-inner-container,
    .ui-toolbar,
    .bx-im-bar,
    #bx-im-bar,
    .air-header,
    .air-header-fixed,
    .intranet-header,
    .bitrix24-light-theme .main-top,
    .workarea-content-paddings > .pagetitle-wrap {
        display: none !important;
    }

    .app__page,
    .page-header,
    .workarea-content,
    #workarea,
    #workarea-content,
    .workarea-content-paddings,
    .start-page,
    .bx-layout-inner-inner,
    .bx-layout-inner-inner-top-row,
    .bx-layout-inner-inner-cont,
    #sidebar,
    .sidebar-panel-wrapper {
        margin: 0 !important;
        padding: 0 !important;
        max-width: none !important;
        width: 100% !important;
    }

    html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: #f4f5f7 !important;
        overflow-x: hidden;
    }

    :root {
        --hub-bg: #f4f5f7;
        --hub-panel: #ffffff;
        --hub-text: #00335b;
        --hub-muted: #6b7a8a;
        --hub-primary: #00335b;
        --hub-accent: #72c4b1;
        --hub-shadow: 0 10px 28px rgba(0, 51, 91, 0.08);
    }

    .reports-hub {
        font-family: "Montserrat", "Segoe UI", sans-serif;
        color: var(--hub-text);
        background: linear-gradient(180deg, #ffffff 0%, var(--hub-bg) 100%);
        min-height: 100vh;
        padding: 8px;
        box-sizing: border-box;
        width: 100%;
    }

    .reports-hub * { box-sizing: border-box; }

    .hub-shell {
        display: grid;
        grid-template-columns: 220px minmax(0, 1fr);
        gap: 8px;
        width: 100%;
        max-width: none;
        margin: 0;
        min-height: calc(100vh - 16px);
    }

    .hub-sidebar,
    .hub-viewer {
        background: var(--hub-panel);
        border-radius: 4px;
        box-shadow: var(--hub-shadow);
        border: 1px solid #dde2e8;
        overflow: hidden;
    }

    .hub-sidebar {
        padding: 14px 12px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        align-self: stretch;
        position: sticky;
        top: 8px;
        height: calc(100vh - 16px);
    }

    .hub-brand__eyebrow {
        margin: 0 0 4px;
        font-size: 10px;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--hub-accent);
        font-weight: 700;
    }

    .hub-brand__title {
        margin: 0;
        font-size: 20px;
        line-height: 1.15;
        color: var(--hub-primary);
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .hub-brand__text {
        margin: 6px 0 0;
        color: var(--hub-muted);
        font-size: 12px;
        line-height: 1.4;
    }

    .lang-switch {
        display: inline-flex;
        padding: 3px;
        border-radius: 2px;
        background: #f0f2f5;
        border: 1px solid #dde2e8;
        width: 100%;
    }

    .lang-switch button {
        border: none;
        background: transparent;
        padding: 7px 0;
        border-radius: 2px;
        cursor: pointer;
        font: inherit;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.06em;
        color: var(--hub-muted);
        flex: 1;
    }

    .lang-switch button.active {
        background: var(--hub-primary);
        color: #fff;
    }

    .report-nav {
        display: grid;
        gap: 8px;
    }

    .report-nav button {
        text-align: left;
        border: 1px solid #dde2e8;
        background: #fafbfc;
        border-radius: 2px;
        padding: 10px 11px;
        cursor: pointer;
        transition: transform 0.15s ease, border-color 0.15s ease, background 0.15s ease;
    }

    .report-nav button:hover {
        transform: translateY(-1px);
        border-color: #b8e4da;
        background: #f7faf9;
    }

    .report-nav button.active {
        background: linear-gradient(135deg, #e8eef4, #f7faf9);
        border-color: var(--hub-accent);
        box-shadow: inset 0 0 0 1px rgba(114, 196, 177, 0.25);
    }

    .report-nav__label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        color: var(--hub-text);
        line-height: 1.25;
    }

    .report-nav__hint {
        display: block;
        margin-top: 3px;
        font-size: 11px;
        color: var(--hub-muted);
        line-height: 1.3;
    }

    .hub-viewer {
        min-height: calc(100vh - 16px);
        display: flex;
        flex-direction: column;
        position: relative;
    }

    .hub-viewer__head {
        padding: 10px 14px;
        border-bottom: 1px solid #dde2e8;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
        background: #fff;
    }

    .hub-viewer__title {
        margin: 0;
        font-size: 14px;
        font-weight: 700;
        color: var(--hub-primary);
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .hub-viewer__meta {
        font-size: 11px;
        color: var(--hub-muted);
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .hub-viewer__body {
        position: relative;
        flex: 1;
        min-height: 0;
        background: #f4f5f7;
    }

    .hub-viewer iframe {
        width: 100%;
        height: 100%;
        min-height: calc(100vh - 58px);
        border: 0;
        background: #f4f5f7;
        display: block;
        opacity: 1;
        transition: opacity 0.2s ease;
    }

    .hub-viewer iframe.is-loading {
        opacity: 0.25;
        pointer-events: none;
    }

    .hub-empty {
        position: absolute;
        inset: 0;
        display: grid;
        place-items: center;
        color: var(--hub-muted);
        padding: 40px;
        text-align: center;
        background: #f4f5f7;
        z-index: 2;
    }

    .hub-loader {
        position: absolute;
        inset: 0;
        display: none;
        place-items: center;
        background: rgba(244, 245, 247, 0.82);
        backdrop-filter: blur(2px);
        z-index: 5;
    }

    .hub-loader.is-visible {
        display: grid;
    }

    .hub-loader__card {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 14px;
        padding: 28px 34px;
        border-radius: 4px;
        background: #fff;
        box-shadow: 0 16px 40px rgba(0, 51, 91, 0.12);
        border: 1px solid #dde2e8;
        min-width: 220px;
    }

    .hub-spinner {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        border: 3px solid #d5dde6;
        border-top-color: var(--hub-primary);
        animation: hub-spin 0.75s linear infinite;
    }

    .hub-loader__text {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
        color: var(--hub-text);
    }

    .hub-loader__sub {
        margin: 0;
        font-size: 12px;
        color: var(--hub-muted);
    }

    @keyframes hub-spin {
        to { transform: rotate(360deg); }
    }

    @media (max-width: 980px) {
        .hub-shell { grid-template-columns: 1fr; }
        .hub-sidebar { position: static; height: auto; }
        .hub-viewer { min-height: 70vh; }
        .hub-viewer iframe,
        .hub-viewer__body { min-height: 70vh; }
    }
</style>

<div class="reports-hub">
    <div class="hub-shell">
        <aside class="hub-sidebar">
            <div class="hub-brand">
                <p class="hub-brand__eyebrow">Monolith CRM</p>
                <h1 class="hub-brand__title" id="hubTitle">რეპორტები</h1>
                <p class="hub-brand__text" id="hubText">აირჩიეთ რეპორტის ტიპი და ნახეთ შედეგი მარჯვენა პანელში.</p>
            </div>

            <div class="lang-switch">
                <button type="button" id="langGe" class="active" onclick="selectLanguage('ge', this)">GEO</button>
                <button type="button" id="langEng" onclick="selectLanguage('eng', this)">ENG</button>
            </div>

            <div class="report-nav" id="reportNav">
                <button type="button" data-url="/crm/deal/reports/udzraviQonebisReport.php" data-key="product" onclick="selectReport(this)">
                    <span class="report-nav__label" id="nav-product-label">უძრავი ქონების რეპორტი</span>
                    <span class="report-nav__hint" id="nav-product-hint">სტატუსები, ფართი, ფასი</span>
                </button>
                <button type="button" data-url="/crm/deal/reports/soldReport.php" data-key="sold" onclick="selectReport(this)">
                    <span class="report-nav__label" id="nav-sold-label">გაყიდვების რეპორტი</span>
                    <span class="report-nav__hint" id="nav-sold-hint">გაყიდული ერთეულები და საშ. ფასი</span>
                </button>
                <button type="button" data-url="/crm/deal/reports/reservationReport.php" data-key="reservation" onclick="selectReport(this)">
                    <span class="report-nav__label" id="nav-reservation-label">რეზერვაციის რეპორტი</span>
                    <span class="report-nav__hint" id="nav-reservation-hint">დაჯავშნილი დილები</span>
                </button>
                <button type="button" data-url="/crm/deal/reports/davalianebisReport.php" data-key="debitors" onclick="selectReport(this)">
                    <span class="report-nav__label" id="nav-debitors-label">დავალიანების რეპორტი</span>
                    <span class="report-nav__hint" id="nav-debitors-hint">დარიცხვა vs გადახდა</span>
                </button>
                <button type="button" data-url="/crm/deal/reports/cashFlow.php" data-key="cashflow" onclick="selectReport(this)">
                    <span class="report-nav__label" id="nav-cashflow-label">ქეშფლოუ რეპორტი</span>
                    <span class="report-nav__hint" id="nav-cashflow-hint">ფინანსური მოძრაობა პერიოდით</span>
                </button>
            </div>
        </aside>

        <section class="hub-viewer">
            <div class="hub-viewer__head">
                <h2 class="hub-viewer__title" id="viewerTitle">აირჩიეთ რეპორტი</h2>
                <div class="hub-viewer__meta" id="viewerMeta">Reports / Dashboard</div>
            </div>
            <div class="hub-viewer__body">
                <div class="hub-empty" id="viewerEmpty">რეპორტის სანახავად აირჩიეთ ერთ-ერთი ღილაკი მარცხნივ.</div>
                <div class="hub-loader" id="viewerLoader" aria-live="polite" aria-busy="false">
                    <div class="hub-loader__card">
                        <div class="hub-spinner"></div>
                        <p class="hub-loader__text" id="loaderText">იტვირთება...</p>
                        <p class="hub-loader__sub" id="loaderSub">გთხოვთ, დაელოდოთ</p>
                    </div>
                </div>
                <iframe id="reportIframe" title="Report Viewer" sandbox="allow-scripts allow-same-origin allow-forms allow-downloads allow-popups" hidden></iframe>
            </div>
        </section>
    </div>
</div>

<script>
    const copy = {
        ge: {
            hubTitle: 'რეპორტები',
            hubText: 'აირჩიეთ რეპორტის ტიპი და ნახეთ შედეგი მარჯვენა პანელში.',
            viewerEmpty: 'რეპორტის სანახავად აირჩიეთ ერთ-ერთი ღილაკი მარცხნივ.',
            viewerPick: 'აირჩიეთ რეპორტი',
            loading: 'იტვირთება...',
            loadingSub: 'გთხოვთ, დაელოდოთ',
            filtering: 'იფილტრება...',
            filteringSub: 'გთხოვთ, დაელოდოთ',
            product: ['უძრავი ქონების რეპორტი', 'სტატუსები, ფართი, ფასი'],
            sold: ['გაყიდვების რეპორტი', 'გაყიდული ერთეულები და საშ. ფასი'],
            reservation: ['რეზერვაციის რეპორტი', 'დაჯავშნილი დილები'],
            debitors: ['დავალიანების რეპორტი', 'დარიცხვა vs გადახდა'],
            cashflow: ['ქეშფლოუ რეპორტი', 'ფინანსური მოძრაობა პერიოდით'],
        },
        eng: {
            hubTitle: 'Reports',
            hubText: 'Pick a report type and view the result in the right panel.',
            viewerEmpty: 'Select one of the report cards on the left to preview it here.',
            viewerPick: 'Choose a report',
            loading: 'Loading...',
            loadingSub: 'Please wait',
            filtering: 'Filtering...',
            filteringSub: 'Please wait',
            product: ['Property Report', 'Status, area and price'],
            sold: ['Sales Report', 'Sold units and average price'],
            reservation: ['Reservation Report', 'Reserved deals'],
            debitors: ['Debt Report', 'Scheduled vs paid amounts'],
            cashflow: ['Cashflow Report', 'Financial movement by period'],
        }
    };

    let currentLang = 'ge';
    let currentUrl = '';
    let loadToken = 0;

    function applyCopy(lang) {
        const t = copy[lang];
        document.getElementById('hubTitle').innerText = t.hubTitle;
        document.getElementById('hubText').innerText = t.hubText;
        document.getElementById('viewerEmpty').innerText = t.viewerEmpty;
        document.getElementById('loaderText').innerText = t.loading;
        document.getElementById('loaderSub').innerText = t.loadingSub;
        if (!currentUrl) document.getElementById('viewerTitle').innerText = t.viewerPick;
        ['product', 'sold', 'reservation', 'debitors', 'cashflow'].forEach(key => {
            document.getElementById(`nav-${key}-label`).innerText = t[key][0];
            document.getElementById(`nav-${key}-hint`).innerText = t[key][1];
        });
    }

    function setLoading(isLoading, mode) {
        const loader = document.getElementById('viewerLoader');
        const iframe = document.getElementById('reportIframe');
        const t = copy[currentLang];
        document.getElementById('loaderText').innerText = mode === 'filter' ? t.filtering : t.loading;
        document.getElementById('loaderSub').innerText = mode === 'filter' ? t.filteringSub : t.loadingSub;
        loader.classList.toggle('is-visible', isLoading);
        loader.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        iframe.classList.toggle('is-loading', isLoading);
    }

    function selectLanguage(language, button) {
        currentLang = language;
        document.querySelectorAll('.lang-switch button').forEach(btn => btn.classList.remove('active'));
        button.classList.add('active');
        localStorage.setItem('reportLang', language);
        applyCopy(language);
        if (currentUrl) {
            const activeBtn = document.querySelector('.report-nav button.active');
            if (activeBtn) loadReport(currentUrl, activeBtn);
        }
    }

    function selectReport(button) {
        document.querySelectorAll('.report-nav button').forEach(btn => btn.classList.remove('active'));
        button.classList.add('active');
        currentUrl = button.dataset.url;
        localStorage.setItem('reportSelected', currentUrl);
        loadReport(currentUrl, button);
    }

    function loadReport(url, button) {
        const iframe = document.getElementById('reportIframe');
        const empty = document.getElementById('viewerEmpty');
        const title = document.getElementById('viewerTitle');
        const meta = document.getElementById('viewerMeta');
        const label = button.querySelector('.report-nav__label').innerText;
        const token = ++loadToken;

        title.innerText = label;
        meta.innerText = `Reports / ${label}`;
        empty.style.display = 'none';
        iframe.hidden = false;
        setLoading(true, 'load');

        iframe.onload = function () {
            if (token !== loadToken) return;
            setLoading(false);
        };

        iframe.src = `${url}?lang=${currentLang}&_=${Date.now()}`;
    }

    window.addEventListener('message', (event) => {
        if (!event.data || event.data.type !== 'monolith-report-loading') return;
        if (event.data.loading) {
            setLoading(true, 'filter');
        }
    });

    window.addEventListener('DOMContentLoaded', () => {
        const savedLang = localStorage.getItem('reportLang');
        const savedReport = localStorage.getItem('reportSelected');
        if (savedLang === 'eng') selectLanguage('eng', document.getElementById('langEng'));
        else applyCopy('ge');

        if (savedReport) {
            const btn = document.querySelector(`.report-nav button[data-url="${savedReport}"]`);
            if (btn) selectReport(btn);
        }

        const iframe = document.getElementById('reportIframe');
        iframe.addEventListener('load', () => setLoading(false));
    });
</script>
