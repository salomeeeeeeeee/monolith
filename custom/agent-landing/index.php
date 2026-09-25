<?php
header('Content-Type: text/html; charset=utf-8');
$logoUrl = 'assets/logo.png';
$apiUrl  = '/rest/public/addAgentLead.php';
?>
<!DOCTYPE html>
<html lang="ka">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>მონოლით ჯგუფი — აგენტის ფორმა</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Georgian:wght@400;500;600;700&family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">
  <!-- ქვეყნების დროშები — იგივე sprite, რასაც ბიტრიქსის ტელეფონის ველი იყენებს -->
  <link href="/bitrix/js/main/phonenumber/css/phonenumber.css" rel="stylesheet">
  <style>
    :root {
      --navy: rgb(1, 53, 88);
      --steel: rgb(33, 71, 102);
      --navy-soft: rgba(1, 53, 88, 0.08);
      --steel-soft: rgba(33, 71, 102, 0.12);
      --ink: #0b2438;
      --muted: #5a7388;
      --line: rgba(1, 53, 88, 0.16);
      --white: #ffffff;
      --bg-a: #f4f7fa;
      --bg-b: #e8eef4;
      --danger: #9b2c2c;
      --ok: #1f6b4a;
      --info: #8a5a00;
      --radius: 14px;
      --shadow: 0 18px 50px rgba(1, 53, 88, 0.12);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      min-height: 100%;
    }

    body {
      font-family: "Noto Sans Georgian", "Manrope", sans-serif;
      color: var(--ink);
      background:
        radial-gradient(1200px 600px at 10% -10%, rgba(33, 71, 102, 0.18), transparent 55%),
        radial-gradient(900px 500px at 100% 0%, rgba(1, 53, 88, 0.14), transparent 50%),
        linear-gradient(165deg, var(--bg-a), var(--bg-b));
      line-height: 1.45;
    }

    .page {
      width: min(720px, calc(100% - 32px));
      margin: 0 auto;
      padding: 40px 0 64px;
    }

    .brand {
      text-align: center;
      margin-bottom: 28px;
      animation: rise 0.7s ease both;
    }

    .brand img {
      width: min(240px, 70vw);
      height: auto;
      display: inline-block;
    }

    .brand p {
      margin-top: 14px;
      color: var(--steel);
      font-size: 0.98rem;
      font-weight: 500;
      letter-spacing: 0.01em;
    }

    .panel {
      background: var(--white);
      border: 1px solid rgba(1, 53, 88, 0.08);
      border-radius: 22px;
      box-shadow: var(--shadow);
      padding: 28px 28px 24px;
      animation: rise 0.85s ease 0.08s both;
    }

    .section-label {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 22px 0 14px;
      color: var(--navy);
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .section-label:first-child {
      margin-top: 0;
    }

    .section-label::after {
      content: "";
      flex: 1;
      height: 1px;
      background: linear-gradient(90deg, var(--line), transparent);
    }

    .field {
      margin-bottom: 14px;
    }

    label {
      display: block;
      margin-bottom: 7px;
      font-size: 0.86rem;
      font-weight: 600;
      color: var(--steel);
    }

    label .req {
      color: #b42318;
      margin-left: 2px;
    }

    input[type="text"],
    input[type="tel"],
    textarea {
      width: 100%;
      border: 1px solid var(--line);
      background: #fbfcfd;
      color: var(--ink);
      border-radius: var(--radius);
      padding: 13px 14px;
      font: inherit;
      font-size: 0.98rem;
      outline: none;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    input:focus,
    textarea:focus {
      border-color: var(--steel);
      background: var(--white);
      box-shadow: 0 0 0 4px var(--navy-soft);
    }

    textarea {
      min-height: 96px;
      resize: vertical;
    }

    .phones {
      display: grid;
      gap: 10px;
    }

    .phone-row {
      display: grid;
      grid-template-columns: auto 1fr auto;
      gap: 8px;
      align-items: center;
      animation: fade-in 0.25s ease both;
    }

    .phone-row.menu-open {
      position: relative;
      z-index: 20;
    }

    .country {
      position: relative;
      align-self: stretch;
    }

    .country-btn {
      height: 100%;
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 0 12px;
      border: 1px solid var(--line);
      background: #fbfcfd;
      color: var(--ink);
      border-radius: var(--radius);
      font: inherit;
      font-size: 0.98rem;
      cursor: pointer;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .country-btn:hover,
    .country-btn[aria-expanded="true"] {
      border-color: var(--steel);
      background: var(--white);
    }

    .country-btn:focus-visible {
      outline: none;
      border-color: var(--steel);
      box-shadow: 0 0 0 4px var(--navy-soft);
    }

    .country-btn::after {
      content: "";
      border: 4px solid transparent;
      border-top-color: var(--muted);
      margin-top: 4px;
    }

    .country-flag,
    .country-item .bx-flag-16 {
      display: inline-block;
      flex: none;
    }

    .country-menu {
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      width: min(340px, calc(100vw - 40px));
      padding: 8px;
      background: var(--white);
      border: 1px solid rgba(1, 53, 88, 0.12);
      border-radius: 14px;
      box-shadow: var(--shadow);
      display: none;
    }

    .country-menu.open { display: block; animation: fade-in 0.15s ease both; }

    .country-menu input.country-search {
      padding: 10px 12px;
      font-size: 0.92rem;
      margin-bottom: 6px;
    }

    .country-list {
      max-height: 260px;
      overflow-y: auto;
    }

    .country-item {
      width: 100%;
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 10px;
      border: none;
      border-radius: 10px;
      background: transparent;
      color: var(--ink);
      font: inherit;
      font-size: 0.92rem;
      text-align: left;
      cursor: pointer;
    }

    .country-item:hover,
    .country-item:focus-visible,
    .country-item.current {
      outline: none;
      background: var(--navy-soft);
    }

    .country-item .code {
      margin-left: auto;
      color: var(--muted);
      font-variant-numeric: tabular-nums;
    }

    .country-sep {
      height: 1px;
      margin: 6px 4px;
      background: var(--line);
    }

    .country-empty {
      padding: 10px;
      color: var(--muted);
      font-size: 0.9rem;
    }

    .phone-hint {
      grid-column: 1 / -1;
      margin-top: -2px;
      font-size: 0.84rem;
      font-weight: 500;
      display: none;
    }

    .phone-hint.show { display: block; animation: fade-in 0.2s ease both; }
    .phone-hint.wait { color: var(--muted); }
    .phone-hint.ok { color: var(--ok); }
    .phone-hint.info { color: var(--info); }

    .field-note {
      margin: -4px 0 12px;
      color: var(--muted);
      font-size: 0.84rem;
    }

    .btn-ghost,
    .btn-add,
    .btn-submit {
      border: none;
      cursor: pointer;
      font: inherit;
      font-weight: 600;
      border-radius: 12px;
      transition: transform 0.15s ease, background 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
    }

    .btn-ghost {
      width: 44px;
      height: 44px;
      background: var(--steel-soft);
      color: var(--navy);
      font-size: 1.2rem;
      line-height: 1;
    }

    .btn-ghost:hover {
      background: rgba(1, 53, 88, 0.16);
    }

    .btn-ghost:disabled {
      opacity: 0.35;
      cursor: not-allowed;
    }

    .btn-add {
      margin-top: 4px;
      background: transparent;
      color: var(--steel);
      border: 1px dashed rgba(33, 71, 102, 0.35);
      padding: 10px 14px;
      width: 100%;
    }

    .btn-add:hover {
      background: var(--navy-soft);
      border-style: solid;
    }

    .grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .btn-submit {
      width: 100%;
      margin-top: 18px;
      padding: 15px 18px;
      background: linear-gradient(135deg, var(--navy), var(--steel));
      color: var(--white);
      font-size: 1rem;
      letter-spacing: 0.01em;
      box-shadow: 0 12px 28px rgba(1, 53, 88, 0.28);
    }

    .btn-submit:hover {
      transform: translateY(-1px);
      box-shadow: 0 16px 34px rgba(1, 53, 88, 0.34);
    }

    .btn-submit:active {
      transform: translateY(0);
    }

    .btn-submit:disabled {
      opacity: 0.7;
      cursor: wait;
      transform: none;
    }

    .status {
      margin-top: 14px;
      padding: 12px 14px;
      border-radius: 12px;
      font-size: 0.92rem;
      display: none;
    }

    .status.show { display: block; animation: fade-in 0.25s ease both; }
    .status.ok {
      background: rgba(31, 107, 74, 0.1);
      color: var(--ok);
      border: 1px solid rgba(31, 107, 74, 0.2);
    }
    .status.err {
      background: rgba(155, 44, 44, 0.08);
      color: var(--danger);
      border: 1px solid rgba(155, 44, 44, 0.18);
    }

    @keyframes rise {
      from { opacity: 0; transform: translateY(14px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes fade-in {
      from { opacity: 0; transform: translateY(4px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 640px) {
      .page { width: min(100% - 20px, 720px); padding: 24px 0 40px; }
      .panel { padding: 22px 18px 18px; border-radius: 18px; }
      .grid-2 { grid-template-columns: 1fr; gap: 0; }
    }
  </style>
</head>
<body>
  <main class="page">
    <header class="brand">
      <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="მონოლით ჯგუფი">
      <p>აგენტებისა და ბროკერებისთვის</p>
    </header>

    <section class="panel" aria-label="აგენტის ფორმა">
      <form id="agentLeadForm" novalidate>
        <div class="section-label">კლიენტი</div>

        <div class="field">
          <label for="clientName">კლიენტის სახელი / გვარი <span class="req">*</span></label>
          <input type="text" id="clientName" name="client_name" autocomplete="name" placeholder="მაგ. გიორგი ბერიძე" required>
        </div>

        <div class="field">
          <label>კლიენტის ტელეფონი <span class="req">*</span></label>
          <div class="phones" id="phonesList"></div>
          <button type="button" class="btn-add" id="addPhoneBtn">+ ტელეფონის დამატება</button>
        </div>

        <div class="section-label">სააგენტო / აგენტი</div>
        <p class="field-note">საკმარისია სააგენტოს ან აგენტის მითითება.</p>

        <div class="grid-2">
          <div class="field">
            <label for="agency">სააგენტო</label>
            <input type="text" id="agency" name="agency" autocomplete="off" placeholder="სააგენტოს დასახელება">
          </div>
          <div class="field">
            <label for="agent">აგენტი</label>
            <input type="text" id="agent" name="agent" autocomplete="off" placeholder="აგენტის სახელი">
          </div>
        </div>

        <div class="field">
          <label for="agencyComment">სააგენტოს კომენტარი</label>
          <textarea id="agencyComment" name="agency_comment" placeholder="დამატებითი ინფორმაცია კლიენტზე ან მოთხოვნაზე"></textarea>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn">დილის შექმნა</button>
        <div class="status" id="statusBox" role="status" aria-live="polite"></div>
      </form>
    </section>
  </main>

  <script>
    (function () {
      const API_URL = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;

      // ISO + ქვეყნის კოდი — ბიტრიქსის /bitrix/js/main/phonenumber/metadata.json-დან
      const COUNTRY_DATA = `
        AC247 AD376 AE971 AF93 AG1 AI1 AL355 AM374 AO244 AR54 AS1 AT43 AU61 AW297 AX358 AZ994
        BA387 BB1 BD880 BE32 BF226 BG359 BH973 BI257 BJ229 BL590 BM1 BN673 BO591 BQ599 BR55 BS1
        BT975 BW267 BY375 BZ501 CA1 CC61 CD243 CF236 CG242 CH41 CI225 CK682 CL56 CM237 CN86 CO57
        CR506 CU53 CV238 CW599 CX61 CY357 CZ420 DE49 DJ253 DK45 DM1 DO1 DZ213 EC593 EE372 EG20
        EH212 ER291 ES34 ET251 FI358 FJ679 FK500 FM691 FO298 FR33 GA241 GB44 GD1 GE995 GF594 GG44
        GH233 GI350 GL299 GM220 GN224 GP590 GQ240 GR30 GT502 GU1 GW245 GY592 HK852 HN504 HR385 HT509
        HU36 ID62 IE353 IL972 IM44 IN91 IO246 IQ964 IR98 IS354 IT39 JE44 JM1 JO962 JP81 KE254
        KG996 KH855 KI686 KM269 KN1 KP850 KR82 KW965 KY1 KZ7 LA856 LB961 LC1 LI423 LK94 LR231
        LS266 LT370 LU352 LV371 LY218 MA212 MC377 MD373 ME382 MF590 MG261 MH692 MK389 ML223 MM95 MN976
        MO853 MP1 MQ596 MR222 MS1 MT356 MU230 MV960 MW265 MX52 MY60 MZ258 NA264 NC687 NE227 NF672
        NG234 NI505 NL31 NO47 NP977 NR674 NU683 NZ64 OM968 PA507 PE51 PF689 PG675 PH63 PK92 PL48
        PM508 PR1 PS970 PT351 PW680 PY595 QA974 RE262 RO40 RS381 RU7 RW250 SA966 SB677 SC248 SD249
        SE46 SG65 SH290 SI386 SJ47 SK421 SL232 SM378 SN221 SO252 SR597 SS211 ST239 SV503 SX1 SY963
        SZ268 TA290 TC1 TD235 TG228 TH66 TJ992 TK690 TL670 TM993 TN216 TO676 TR90 TT1 TV688 TW886
        TZ255 UA380 UG256 US1 UY598 UZ998 VA39 VC1 VE58 VG1 VI1 VN84 VU678 WF681 WS685 XK383
        YE967 YT262 ZA27 ZM260 ZW263
      `;
      const DEFAULT_COUNTRY = 'GE';
      const TOP_COUNTRIES = ['GE', 'RU', 'UA', 'AM', 'AZ', 'TR', 'IL', 'KZ', 'BY', 'US', 'GB', 'DE'];

      // სახელები ბიტრიქსის მსგავსად ინგლისურადაა; ეს სიტყვები ქართულად ძებნისთვისაა
      const COUNTRY_SEARCH_KA = {
        GE: 'საქართველო', RU: 'რუსეთი', UA: 'უკრაინა', AM: 'სომხეთი', AZ: 'აზერბაიჯანი',
        TR: 'თურქეთი', IL: 'ისრაელი', KZ: 'ყაზახეთი', BY: 'ბელარუსი', US: 'აშშ ამერიკა',
        GB: 'დიდი ბრიტანეთი ინგლისი', DE: 'გერმანია', FR: 'საფრანგეთი', IT: 'იტალია',
        ES: 'ესპანეთი', GR: 'საბერძნეთი', PL: 'პოლონეთი', NL: 'ნიდერლანდები ჰოლანდია',
        BE: 'ბელგია', CH: 'შვეიცარია', AT: 'ავსტრია', SE: 'შვედეთი', NO: 'ნორვეგია',
        FI: 'ფინეთი', DK: 'დანია', LT: 'ლიტვა', LV: 'ლატვია', EE: 'ესტონეთი',
        MD: 'მოლდოვა', BG: 'ბულგარეთი', RO: 'რუმინეთი', CZ: 'ჩეხეთი', HU: 'უნგრეთი',
        CY: 'კვიპროსი', UZ: 'უზბეკეთი', KG: 'ყირგიზეთი', TM: 'თურქმენეთი', TJ: 'ტაჯიკეთი',
        IR: 'ირანი', IQ: 'ერაყი', AE: 'არაბეთის გაერთიანებული საამიროები ემირატები დუბაი',
        SA: 'საუდის არაბეთი', QA: 'კატარი', KW: 'ქუვეითი', LB: 'ლიბანი', JO: 'იორდანია',
        EG: 'ეგვიპტე', CN: 'ჩინეთი', IN: 'ინდოეთი', JP: 'იაპონია', KR: 'სამხრეთ კორეა',
        CA: 'კანადა', AU: 'ავსტრალია'
      };

      const form = document.getElementById('agentLeadForm');
      const phonesList = document.getElementById('phonesList');
      const addPhoneBtn = document.getElementById('addPhoneBtn');
      const submitBtn = document.getElementById('submitBtn');
      const statusBox = document.getElementById('statusBox');
      const agencyInput = document.getElementById('agency');
      const agentInput = document.getElementById('agent');

      // წინა ვერსია სააგენტოს/აგენტის ჩანაწერებს ბრაუზერში იმახსოვრებდა — ვშლით
      try {
        localStorage.removeItem('monolith_agent_landing_meta_v1');
      } catch (e) {}

      const COUNTRIES = buildCountries();
      const COUNTRY_BY_ISO = {};
      COUNTRIES.forEach(function (country) {
        COUNTRY_BY_ISO[country.iso] = country;
      });

      function buildCountries() {
        const names = regionNames(['en']);
        const kaNames = regionNames(['ka']);
        return COUNTRY_DATA.trim().split(/\s+/).map(function (item) {
          const iso = item.slice(0, 2);
          const name = regionName(names, iso);
          return {
            iso: iso,
            code: item.slice(2),
            name: name,
            search: [name, iso, COUNTRY_SEARCH_KA[iso] || '', regionName(kaNames, iso)].join(' ').toLowerCase()
          };
        }).sort(function (a, b) {
          return a.name.localeCompare(b.name, 'en');
        });
      }

      function regionNames(locales) {
        try {
          return new Intl.DisplayNames(locales, { type: 'region' });
        } catch (e) {
          return null;
        }
      }

      function regionName(names, iso) {
        try {
          return (names && names.of(iso)) || iso;
        } catch (e) {
          return iso;
        }
      }

      function showStatus(type, message) {
        statusBox.className = 'status show ' + type;
        statusBox.textContent = message;
      }

      function clearStatus() {
        statusBox.className = 'status';
        statusBox.textContent = '';
      }

      // ── ქვეყნის კოდის არჩევა ─────────────────────────────────────────────

      const countryMenu = document.createElement('div');
      countryMenu.className = 'country-menu';
      const countrySearch = document.createElement('input');
      countrySearch.type = 'text';
      countrySearch.className = 'country-search';
      countrySearch.placeholder = 'ქვეყნის ან კოდის ძებნა';
      countrySearch.autocomplete = 'off';
      const countryList = document.createElement('div');
      countryList.className = 'country-list';
      countryMenu.appendChild(countrySearch);
      countryMenu.appendChild(countryList);
      let countryMenuRow = null;

      function rowCountry(row) {
        return COUNTRY_BY_ISO[row.dataset.country] || COUNTRY_BY_ISO[DEFAULT_COUNTRY];
      }

      function setRowCountry(row, iso) {
        const country = COUNTRY_BY_ISO[iso] || COUNTRY_BY_ISO[DEFAULT_COUNTRY];
        const btn = row.querySelector('.country-btn');
        row.dataset.country = country.iso;
        btn.querySelector('.country-flag').className = 'country-flag bx-flag-24 ' + country.iso.toLowerCase();
        btn.querySelector('.country-dial').textContent = '+' + country.code;
        btn.title = country.name;
        btn.setAttribute('aria-label', 'ქვეყნის კოდი: ' + country.name + ' +' + country.code);
      }

      function countryItem(country, currentIso) {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'country-item' + (country.iso === currentIso ? ' current' : '');
        item.dataset.iso = country.iso;

        const flag = document.createElement('span');
        flag.className = 'bx-flag-16 ' + country.iso.toLowerCase();
        const name = document.createElement('span');
        name.textContent = country.name;
        const code = document.createElement('span');
        code.className = 'code';
        code.textContent = '+' + country.code;

        item.appendChild(flag);
        item.appendChild(name);
        item.appendChild(code);
        return item;
      }

      function renderCountries() {
        const query = countrySearch.value.trim().toLowerCase();
        const digits = onlyDigits(query);
        const currentIso = countryMenuRow ? rowCountry(countryMenuRow).iso : '';
        const matches = COUNTRIES.filter(function (country) {
          if (!query) return true;
          return digits ? country.code.indexOf(digits) === 0 : country.search.indexOf(query) !== -1;
        });

        countryList.innerHTML = '';
        if (!query) {
          TOP_COUNTRIES.forEach(function (iso) {
            if (COUNTRY_BY_ISO[iso]) countryList.appendChild(countryItem(COUNTRY_BY_ISO[iso], currentIso));
          });
          const separator = document.createElement('div');
          separator.className = 'country-sep';
          countryList.appendChild(separator);
        }
        matches.forEach(function (country) {
          countryList.appendChild(countryItem(country, currentIso));
        });
        if (!matches.length) {
          const empty = document.createElement('div');
          empty.className = 'country-empty';
          empty.textContent = 'ქვეყანა ვერ მოიძებნა';
          countryList.appendChild(empty);
        }
        countryList.scrollTop = 0;
      }

      function openCountryMenu(row) {
        closeCountryMenu();
        countryMenuRow = row;
        row.classList.add('menu-open');
        row.querySelector('.country').appendChild(countryMenu);
        row.querySelector('.country-btn').setAttribute('aria-expanded', 'true');
        countrySearch.value = '';
        renderCountries();
        countryMenu.classList.add('open');
        // მობილურზე კლავიატურა სიას რომ არ გადაფაროს
        if (window.matchMedia('(pointer: fine)').matches) countrySearch.focus();
      }

      function closeCountryMenu() {
        if (!countryMenuRow) return;
        countryMenuRow.classList.remove('menu-open');
        countryMenuRow.querySelector('.country-btn').setAttribute('aria-expanded', 'false');
        countryMenu.classList.remove('open');
        countryMenuRow = null;
      }

      function chooseCountry(iso) {
        const row = countryMenuRow;
        closeCountryMenu();
        if (!row) return;
        if (row.dataset.country !== iso) {
          setRowCountry(row, iso);
          row.dataset.checked = '';
          setPhoneState(row, '', '');
          checkPhone(row);
        }
        row.querySelector('input[type="tel"]').focus();
      }

      countrySearch.addEventListener('input', renderCountries);
      countrySearch.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        // Enter-მა ფორმა არ გააგზავნოს — ირჩევს პირველ ნაპოვნ ქვეყანას
        event.preventDefault();
        const first = countryList.querySelector('.country-item');
        if (first) chooseCountry(first.dataset.iso);
      });
      countryList.addEventListener('click', function (event) {
        const item = event.target.closest('.country-item');
        if (item) chooseCountry(item.dataset.iso);
      });
      document.addEventListener('click', function (event) {
        if (countryMenuRow && !countryMenuRow.querySelector('.country').contains(event.target)) {
          closeCountryMenu();
        }
      });
      document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !countryMenuRow) return;
        const btn = countryMenuRow.querySelector('.country-btn');
        closeCountryMenu();
        btn.focus();
      });

      // ── ტელეფონის ველები ─────────────────────────────────────────────────

      function createPhoneRow() {
        const row = document.createElement('div');
        row.className = 'phone-row';

        const country = document.createElement('div');
        country.className = 'country';
        const countryBtn = document.createElement('button');
        countryBtn.type = 'button';
        countryBtn.className = 'country-btn';
        countryBtn.setAttribute('aria-haspopup', 'true');
        countryBtn.setAttribute('aria-expanded', 'false');
        const flag = document.createElement('span');
        flag.className = 'country-flag';
        const dial = document.createElement('span');
        dial.className = 'country-dial';
        countryBtn.appendChild(flag);
        countryBtn.appendChild(dial);
        countryBtn.addEventListener('click', function () {
          if (countryMenuRow === row) {
            closeCountryMenu();
          } else {
            openCountryMenu(row);
          }
        });
        country.appendChild(countryBtn);

        const input = document.createElement('input');
        input.type = 'tel';
        input.name = 'phones[]';
        input.placeholder = '5XXXXXXXX';
        input.autocomplete = 'tel-national';
        input.inputMode = 'numeric';
        input.required = true;

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn-ghost';
        removeBtn.setAttribute('aria-label', 'ტელეფონის წაშლა');
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', function () {
          const rows = phonesList.querySelectorAll('.phone-row');
          if (rows.length <= 1) return;
          if (countryMenuRow === row) closeCountryMenu();
          row.remove();
          syncRemoveButtons();
        });

        const hint = document.createElement('div');
        hint.className = 'phone-hint';
        hint.setAttribute('aria-live', 'polite');

        input.addEventListener('input', function () {
          // მხოლოდ ციფრები — აკრეფისას და ჩასმისას (paste) სხვა სიმბოლოები იშლება
          const digits = onlyDigits(input.value);
          if (digits !== input.value) {
            const caret = onlyDigits(input.value.slice(0, input.selectionStart || 0)).length;
            input.value = digits;
            input.setSelectionRange(caret, caret);
          }
          row.dataset.checked = '';
          setPhoneState(row, '', '');
        });
        input.addEventListener('blur', function () {
          const digits = localDigits(row);
          if (digits !== input.value) input.value = digits;
          checkPhone(row);
        });

        row.appendChild(country);
        row.appendChild(input);
        row.appendChild(removeBtn);
        row.appendChild(hint);
        phonesList.appendChild(row);
        setRowCountry(row, DEFAULT_COUNTRY);
        syncRemoveButtons();
        return input;
      }

      function onlyDigits(value) {
        return String(value || '').replace(/\D+/g, '');
      }

      // ველში ინდექსიც თუ ჩაწერეს (995555123456), ორჯერ არ დაემატოს
      function localDigits(row) {
        const code = rowCountry(row).code;
        const digits = onlyDigits(row.querySelector('input[type="tel"]').value);
        if (digits.length >= 11 && digits.indexOf(code) === 0 && digits.length - code.length >= 8) {
          return digits.slice(code.length);
        }
        return digits;
      }

      function rowPhone(row) {
        const digits = localDigits(row);
        return digits ? '+' + rowCountry(row).code + digits : '';
      }

      // სერვერის agentLeadPhoneSearchPart-ის ანალოგი — ბოლო 9 ციფრი
      function phoneKey(value) {
        const digits = onlyDigits(value);
        return digits.length > 9 ? digits.slice(-9) : digits;
      }

      function setPhoneState(row, type, message) {
        const hint = row.querySelector('.phone-hint');
        hint.className = 'phone-hint' + (type ? ' show ' + type : '');
        hint.textContent = message;
      }

      // ნაპოვნი ნომერი მხოლოდ ინფორმაციაა — დილი მაინც იქმნება
      function markPhone(row, busy) {
        setPhoneState(
          row,
          busy ? 'info' : 'ok',
          busy ? 'ნომერი უკვე ფიქსირდება სისტემაში' : 'ნომერი თავისუფალია'
        );
      }

      async function checkPhone(row) {
        const phone = rowPhone(row);
        const key = phoneKey(phone);
        // საქართველოს ნომერი 9-ნიშნაა; სხვა ქვეყნებში სიგრძე განსხვავდება
        const minLength = rowCountry(row).iso === 'GE' ? 9 : 7;
        if (localDigits(row).length < minLength) {
          setPhoneState(row, '', '');
          return;
        }
        if (row.dataset.checked === key) return;
        row.dataset.checked = key;
        setPhoneState(row, 'wait', 'ნომერი მოწმდება...');

        try {
          const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify({ check_only: true, phones: [phone] })
          });
          const data = await response.json();
          if (phoneKey(rowPhone(row)) !== key) return;
          if (!response.ok || data.status !== 200) throw new Error();
          markPhone(row, (data.busyPhones || []).indexOf(key) !== -1);
        } catch (e) {
          if (phoneKey(rowPhone(row)) !== key) return;
          row.dataset.checked = '';
          setPhoneState(row, '', '');
        }
      }

      function syncRemoveButtons() {
        const rows = phonesList.querySelectorAll('.phone-row');
        rows.forEach(function (row) {
          const btn = row.querySelector('.btn-ghost');
          if (btn) btn.disabled = rows.length <= 1;
        });
      }

      addPhoneBtn.addEventListener('click', function () {
        const input = createPhoneRow();
        input.focus();
      });

      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        clearStatus();

        const clientName = document.getElementById('clientName').value.trim();
        const agency = agencyInput.value.trim();
        const agent = agentInput.value.trim();
        const agencyComment = document.getElementById('agencyComment').value.trim();
        const phones = Array.from(phonesList.querySelectorAll('.phone-row'))
          .map(function (row) { return rowPhone(row); })
          .filter(Boolean);

        if (!clientName) {
          showStatus('err', 'გთხოვთ მიუთითოთ კლიენტის სახელი/გვარი.');
          return;
        }
        if (!phones.length) {
          showStatus('err', 'გთხოვთ მიუთითოთ მინიმუმ ერთი ტელეფონი.');
          return;
        }
        if (!agency && !agent) {
          showStatus('err', 'გთხოვთ მიუთითოთ სააგენტო ან აგენტი.');
          return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'იგზავნება...';

        try {
          const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify({
              client_name: clientName,
              phones: phones,
              agency: agency,
              agent: agent,
              agency_comment: agencyComment
            })
          });

          const data = await response.json().catch(function () { return {}; });

          if (!response.ok || data.status !== 200) {
            throw new Error(data.message || 'შეცდომა დილის შექმნისას');
          }

          let message = 'დილი შეიქმნა წარმატებით' + (data.dealId ? ' (#' + data.dealId + ')' : '') + '.';
          const busyPhones = Array.isArray(data.busyPhones) ? data.busyPhones : [];
          if (busyPhones.length) {
            message += '' + (busyPhones.length > 1 ? 'ნომრები ' : 'ნომერი ')
              + busyPhones.join(', ') + ' უკვე ფიქსირდებოდა სისტემაში.';
          }
          showStatus('ok', message);
          closeCountryMenu();
          form.reset();
          phonesList.innerHTML = '';
          createPhoneRow();
        } catch (error) {
          showStatus('err', error.message || 'დილის შექმნა ვერ მოხერხდა.');
        } finally {
          submitBtn.disabled = false;
          submitBtn.textContent = 'დილის შექმნა';
        }
      });

      createPhoneRow();
    })();
  </script>
</body>
</html>
