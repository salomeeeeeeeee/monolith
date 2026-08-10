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
      grid-template-columns: 1fr auto;
      gap: 8px;
      align-items: center;
      animation: fade-in 0.25s ease both;
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

        <div class="section-label">სააგენტო</div>

        <div class="grid-2">
          <div class="field">
            <label for="agency">სააგენტო <span class="req">*</span></label>
            <input type="text" id="agency" name="agency" list="agencySuggestions" placeholder="სააგენტოს დასახელება" required>
            <datalist id="agencySuggestions"></datalist>
          </div>
          <div class="field">
            <label for="agent">აგენტი <span class="req">*</span></label>
            <input type="text" id="agent" name="agent" list="agentSuggestions" placeholder="აგენტის სახელი" required>
            <datalist id="agentSuggestions"></datalist>
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
      const STORAGE_KEY = 'monolith_agent_landing_meta_v1';

      const form = document.getElementById('agentLeadForm');
      const phonesList = document.getElementById('phonesList');
      const addPhoneBtn = document.getElementById('addPhoneBtn');
      const submitBtn = document.getElementById('submitBtn');
      const statusBox = document.getElementById('statusBox');
      const agencyInput = document.getElementById('agency');
      const agentInput = document.getElementById('agent');
      const agencyList = document.getElementById('agencySuggestions');
      const agentList = document.getElementById('agentSuggestions');

      function showStatus(type, message) {
        statusBox.className = 'status show ' + type;
        statusBox.textContent = message;
      }

      function clearStatus() {
        statusBox.className = 'status';
        statusBox.textContent = '';
      }

      function createPhoneRow(value = '') {
        const row = document.createElement('div');
        row.className = 'phone-row';

        const input = document.createElement('input');
        input.type = 'tel';
        input.name = 'phones[]';
        input.placeholder = 'მაგ. 5XX XX XX XX';
        input.autocomplete = 'tel';
        input.required = true;
        input.value = value;

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn-ghost';
        removeBtn.setAttribute('aria-label', 'ტელეფონის წაშლა');
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', function () {
          const rows = phonesList.querySelectorAll('.phone-row');
          if (rows.length <= 1) return;
          row.remove();
          syncRemoveButtons();
        });

        row.appendChild(input);
        row.appendChild(removeBtn);
        phonesList.appendChild(row);
        syncRemoveButtons();
        return input;
      }

      function syncRemoveButtons() {
        const rows = phonesList.querySelectorAll('.phone-row');
        rows.forEach(function (row) {
          const btn = row.querySelector('.btn-ghost');
          if (btn) btn.disabled = rows.length <= 1;
        });
      }

      function loadSuggestions() {
        try {
          const raw = localStorage.getItem(STORAGE_KEY);
          const data = raw ? JSON.parse(raw) : { agencies: [], agents: [] };
          fillDatalist(agencyList, data.agencies || []);
          fillDatalist(agentList, data.agents || []);
        } catch (e) {}
      }

      function fillDatalist(listEl, values) {
        listEl.innerHTML = '';
        values.slice(0, 30).forEach(function (value) {
          const option = document.createElement('option');
          option.value = value;
          listEl.appendChild(option);
        });
      }

      function rememberMeta(agency, agent) {
        try {
          const raw = localStorage.getItem(STORAGE_KEY);
          const data = raw ? JSON.parse(raw) : { agencies: [], agents: [] };
          data.agencies = uniquePush(data.agencies || [], agency);
          data.agents = uniquePush(data.agents || [], agent);
          localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
          fillDatalist(agencyList, data.agencies);
          fillDatalist(agentList, data.agents);
        } catch (e) {}
      }

      function uniquePush(list, value) {
        const next = [value].concat(list.filter(function (item) {
          return item && item.toLowerCase() !== value.toLowerCase();
        }));
        return next.slice(0, 30);
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
        const phones = Array.from(phonesList.querySelectorAll('input[type="tel"]'))
          .map(function (el) { return el.value.trim(); })
          .filter(Boolean);

        if (!clientName) {
          showStatus('err', 'გთხოვთ მიუთითოთ კლიენტის სახელი/გვარი.');
          return;
        }
        if (!phones.length) {
          showStatus('err', 'გთხოვთ მიუთითოთ მინიმუმ ერთი ტელეფონი.');
          return;
        }
        if (!agency || !agent) {
          showStatus('err', 'გთხოვთ მიუთითოთ სააგენტო და აგენტი.');
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

          rememberMeta(agency, agent);
          showStatus('ok', 'დილი შეიქმნა წარმატებით' + (data.dealId ? ' (#' + data.dealId + ')' : '') + '.');
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
      loadSuggestions();
    })();
  </script>
</body>
</html>
