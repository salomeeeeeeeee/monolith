<?php
/**
 * აგენტების მოდული - marketplace აპლიკაცია #1 "Agents Module" (/local/localApps/agents-module/index.html)
 * ჩვეულებრივ გვერდად. BX24 აღარ გამოიყენება: ადმინი PHP-დან მოდის, REST მოთხოვნები api.php-ით მიდის.
 */
define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
require_once __DIR__ . '/config.php';

global $USER, $APPLICATION;
if (!$USER->IsAuthorized()) {
    LocalRedirect('/auth/?backurl=' . urlencode($APPLICATION->GetCurPageParam()));
}
header('Content-Type: text/html; charset=utf-8');
if (!agentsModuleCanAccess()) {
    http_response_code(403);
    echo 'აგენტების მოდულზე წვდომა არ გაქვთ.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ka">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>აგენტების მოდული — მონოლითი</title>
  <style>
    :root {
      --bg: #e7eef3;
      --surface: #fffdfe;
      --surface-2: #e8eef3;
      --surface-3: #d5e0e8;
      --border: #a8bbc8;
      --border-focus: #2f574e;
      --text: #003557;
      --muted: #5a7382;
      --accent: #003557;
      --accent-hover: #214767;
      --success: #2f574e;
      --danger: #dc2626;
      --warning: #d97706;
      --radius: 10px;
      --font: "Segoe UI", system-ui, sans-serif;
      --report-primary: #003557;
      --report-primary-mid: #214767;
      --report-secondary: #2f574e;
      --report-surface: #fffdfe;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: var(--font);
      background: var(--bg);
      color: var(--text);
      line-height: 1.5;
      min-height: 100vh;
    }

    .app { max-width: 1720px; width: 100%; margin: 0 auto; padding: 28px 24px 56px; }

    /* ── Header ── */
    .top-bar {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 22px;
      flex-wrap: wrap;
    }
    .top-bar-brand {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .brand-logo-link {
      display: flex;
      flex-shrink: 0;
      line-height: 0;
    }
    .brand-logo {
      height: 42px;
      width: auto;
      display: block;
    }
    .top-bar h1 { font-size: 1.55rem; font-weight: 600; }
    .top-bar p { color: var(--muted); font-size: 0.88rem; margin-top: 4px; max-width: 520px; }
    .top-bar-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .bitrix-status {
      font-size: 0.72rem;
      padding: 4px 10px;
      border-radius: 12px;
      border: 1px solid var(--border);
      background: var(--surface-2);
      color: var(--muted);
      white-space: nowrap;
    }
    .bitrix-status.ok { background: #dcfce7; border-color: #86efac; color: #166534; }
    .bitrix-status.err { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }
    .bitrix-status.load { background: #fef9c3; border-color: #fde047; color: #854d0e; }

    /* ── ჩანართების ნავიგაცია ── */
    .module-nav {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 10px;
      margin-bottom: 22px;
    }
    @media (max-width: 800px) { .module-nav { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 420px) { .module-nav { grid-template-columns: 1fr; } }

    .nav-card {
      background: var(--surface);
      border: 2px solid var(--border);
      border-radius: 10px;
      padding: 13px 10px;
      text-align: center;
      cursor: pointer;
      font-family: inherit;
      color: var(--text);
      transition: border-color 0.15s, background 0.15s;
    }
    .nav-card:hover { background: var(--surface-2); }
    .nav-card b { display: block; font-size: 0.86rem; margin-bottom: 3px; }
    .nav-card small { display: block; color: var(--muted); font-size: 0.71rem; line-height: 1.35; }
    .nav-card.active { background: var(--surface-2); }
    .nav-card[data-tab="register"].active { border-color: #a78bfa; }
    .nav-card[data-tab="register"] b { color: #7c3aed; }
    .nav-card[data-tab="registry"].active { border-color: #f59e0b; }
    .nav-card[data-tab="registry"] b { color: #b45309; }
    .nav-card[data-tab="projects"].active { border-color: #34d399; }
    .nav-card[data-tab="projects"] b { color: #047857; }
    .nav-card[data-tab="stats"].active {
      border-color: #003557;
      background: linear-gradient(180deg, #fffdfe 0%, #e8eef3 100%);
    }
    .nav-card[data-tab="stats"] b { color: #003557; }

    /* ── სტატისტიკა / რეპორტი — მონოლითის ფერები ── */
    .tab-panel-stats {
      --bg: #e7eef3;
      --surface: #fffdfe;
      --surface-2: #e8eef3;
      --surface-3: #d5e0e8;
      --border: #a8bbc8;
      --text: #003557;
      --muted: #5a7382;
      --accent: #003557;
      --accent-hover: #214767;
      --border-focus: #2f574e;
      --success: #2f574e;
      --report-primary: #003557;
      --report-primary-mid: #214767;
      --report-secondary: #2f574e;
      --report-surface: #fffdfe;
    }

    .tab-panel { display: none; }
    .tab-panel.active { display: block; }
    .tab-panel-stats,
    .tab-panel-registry,
    .tab-panel-register,
    .tab-panel-projects {
      width: 100%;
    }
    .tab-panel-register .card,
    .tab-panel-projects .card {
      max-width: none;
      width: 100%;
    }
    .tab-panel-projects .card + .card { margin-top: 14px; }

    .app-loading {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 72px;
      padding: 16px 12px;
      text-align: center;
    }
    .app-loading.hidden { display: none; }
    .app-loading p {
      margin: 0;
      font-size: 0.88rem;
      color: var(--muted);
      line-height: 1.4;
    }

    .project-register-form { padding: 14px 16px; }
    .project-register-form .register-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 8px 10px;
    }
    .project-register-form .field { margin-bottom: 0; }
    .project-register-form .field label {
      margin-bottom: 3px;
      font-size: 0.72rem;
    }
    .project-register-form .field input {
      padding: 6px 10px;
      font-size: 0.82rem;
      border-radius: 6px;
      width: 100%;
      background: var(--bg);
      border: 1px solid var(--border);
      color: var(--text);
      font: inherit;
    }
    .project-register-form .field input:focus {
      outline: none;
      border-color: var(--border-focus);
    }
    .project-register-form .field input.error {
      border-color: var(--danger);
    }
    .project-register-form .form-actions { margin-top: 12px; }
    @media (max-width: 720px) {
      .project-register-form .register-grid { grid-template-columns: 1fr; }
    }

    /* ── აგენტების რეპორტი (სტატისტიკა) ── */
    .report-section {
      margin-bottom: 12px;
      padding: 14px;
      background: linear-gradient(165deg, #fffdfe 0%, #e8eef3 55%, #dce8e4 100%);
      border: 1px solid #a8bbc8;
      border-radius: 12px;
      box-shadow: 0 8px 28px rgba(0, 53, 87, 0.08);
    }
    .report-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      margin-bottom: 10px;
      flex-wrap: wrap;
    }
    .report-head h2 {
      font-size: 0.98rem;
      font-weight: 700;
      color: #003557;
      letter-spacing: 0.01em;
    }
    .report-head .badge {
      background: #003557;
      border-color: #003557;
      color: #fffdfe;
    }
    .report-toolbar {
      display: flex;
      align-items: flex-end;
      gap: 6px;
      flex-wrap: nowrap;
      margin-bottom: 10px;
      padding: 12px;
      background: #fffdfe;
      border: 1px solid #a8bbc8;
      border-left: 4px solid #2f574e;
      border-radius: var(--radius);
      box-shadow: 0 2px 10px rgba(0, 53, 87, 0.05);
    }
    .report-filter-field {
      flex: 1 1 0;
      min-width: 0;
    }
    .report-filter-field label {
      display: block;
      font-size: 0.64rem;
      color: #214767;
      font-weight: 600;
      margin-bottom: 2px;
      line-height: 1.2;
    }
    .report-filter-field input,
    .report-filter-field select {
      width: 100%;
      padding: 4px 7px;
      background: #e7eef3;
      border: 1px solid #a8bbc8;
      border-radius: 6px;
      color: #003557;
      font: inherit;
      font-size: 0.74rem;
    }
    .report-filter-field input[type="date"] { color-scheme: light; }
    .dmy-date {
      position: relative;
    }
    .dmy-date-text {
      width: 100%;
      padding-right: 26px !important;
    }
    .dmy-date-native {
      position: absolute;
      top: 0;
      right: 0;
      width: 28px;
      height: 100%;
      margin: 0;
      padding: 0;
      border: 0;
      opacity: 0;
      cursor: pointer;
      color-scheme: light;
    }
    .dmy-date::after {
      content: '';
      position: absolute;
      right: 8px;
      top: 50%;
      width: 12px;
      height: 12px;
      transform: translateY(-50%);
      pointer-events: none;
      opacity: 0.55;
      background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%23214767' stroke-width='1.4'%3E%3Crect x='2' y='3.5' width='12' height='11' rx='1.5'/%3E%3Cpath d='M2 7h12M5.5 2v3M10.5 2v3'/%3E%3C/svg%3E") center / contain no-repeat;
    }
    .report-filter-field input:focus,
    .report-filter-field select:focus {
      outline: none;
      border-color: #2f574e;
      box-shadow: 0 0 0 2px rgba(47, 87, 78, 0.18);
    }
    .report-filter-field.autocomplete-wrap { position: relative; }
    .suggest-dropdown {
      display: none;
      position: absolute;
      left: 0;
      right: 0;
      top: calc(100% + 2px);
      z-index: 40;
      max-height: 220px;
      overflow-y: auto;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 8px;
      box-shadow: 0 8px 24px rgba(26, 43, 34, 0.12);
    }
    .suggest-dropdown.open { display: block; }
    .suggest-item {
      display: block;
      width: 100%;
      text-align: left;
      padding: 7px 10px;
      border: 0;
      background: transparent;
      color: var(--text);
      font: inherit;
      font-size: 0.78rem;
      cursor: pointer;
    }
    .suggest-item:hover,
    .suggest-item.active { background: var(--surface-2); }
    .suggest-empty {
      padding: 8px 10px;
      font-size: 0.72rem;
      color: var(--muted);
    }
    .doc-type-options {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 14px;
      margin-top: 2px;
    }
    .doc-type-option {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.82rem;
      color: var(--text);
      cursor: pointer;
      user-select: none;
    }
    .doc-type-option input { accent-color: var(--accent); }
    .report-settle-cell {
      text-align: center;
      white-space: nowrap;
    }
    .report-settle-label {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 0.7rem;
      color: #214767;
      cursor: pointer;
      user-select: none;
    }
    .report-settle-label input { accent-color: #2f574e; }
    .report-toolbar-actions {
      display: flex;
      gap: 6px;
      flex-shrink: 0;
      align-items: flex-end;
    }
    .report-toolbar-actions .btn-sm {
      padding: 4px 10px;
      font-size: 0.72rem;
      white-space: nowrap;
    }
    .report-toolbar .btn-primary {
      background: #003557;
      border: 1px solid #003557;
      color: #fffdfe;
    }
    .report-toolbar .btn-primary:hover:not(:disabled) {
      background: #214767;
      border-color: #214767;
    }
    .report-toolbar .btn-ghost {
      background: #fffdfe;
      border: 1px solid #a8bbc8;
      color: #003557;
    }
    .report-toolbar .btn-ghost:hover:not(:disabled) {
      background: #e8eef3;
      border-color: #2f574e;
      color: #2f574e;
    }
    @media (max-width: 900px) {
      .report-toolbar { flex-wrap: wrap; }
      .report-filter-field { flex: 1 1 calc(50% - 6px); min-width: 120px; }
      .report-toolbar-actions { width: 100%; justify-content: flex-end; }
    }
    .report-table-wrap {
      overflow-x: auto;
      overflow-y: hidden;
      border: 1px solid #003557;
      border-radius: var(--radius);
      width: 100%;
      background: #fffdfe;
      box-shadow: 0 4px 18px rgba(0, 53, 87, 0.1);
    }
    .report-table {
      width: 100%;
      min-width: 1280px;
      table-layout: auto;
      border-collapse: separate;
      border-spacing: 0;
      font-size: 0.72rem;
      background: #fffdfe;
      color: #003557;
    }
    .report-table thead th {
      background: #003557;
      color: #fffdfe;
      font-weight: 600;
      font-size: 0.68rem;
      padding: 7px 6px;
      text-align: left;
      white-space: normal;
      line-height: 1.2;
      word-break: break-word;
      border-bottom: 1px solid #214767;
      border-right: 1px solid #214767;
      vertical-align: bottom;
    }
    .report-table thead th.sortable {
      cursor: pointer;
      user-select: none;
      transition: color 0.12s ease, background 0.12s ease;
    }
    .report-table thead th.sortable:hover {
      color: #fffdfe;
      background: #214767;
    }
    .report-table thead th.sortable .sort-icon {
      display: inline-block;
      margin-left: 2px;
      opacity: 0.35;
      font-size: 0.52rem;
      vertical-align: middle;
    }
    .report-table thead th.sortable.sort-asc .sort-icon,
    .report-table thead th.sortable.sort-desc .sort-icon {
      opacity: 1;
      color: #fffdfe;
    }
    .report-table thead th:last-child {
      border-right: none;
    }
    .report-table tbody td {
      padding: 5px 6px;
      border-bottom: 1px solid #d5e0e8;
      border-right: 1px solid #d5e0e8;
      vertical-align: middle;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      font-size: 0.72rem;
      background: #fffdfe;
      color: #003557;
      transition: background 0.12s ease;
    }
    .report-table tbody td:last-child {
      border-right: none;
    }
    .report-table tbody tr:nth-child(even) td {
      background: #f3f7f9;
    }
    .report-table tbody tr.row-hover td {
      background: #dce8e4 !important;
      box-shadow: inset 0 0 0 1px rgba(47, 87, 78, 0.28);
    }
    .report-table .num { text-align: right; font-variant-numeric: tabular-nums; }
    .deal-id-link,
    .deal-id-link:visited {
      color: #003557;
      font-weight: 600;
      text-decoration: underline;
      text-underline-offset: 2px;
    }
    .deal-id-link:hover { color: #214767; }
    .deal-client-link {
      color: #003557;
      font-weight: 500;
      text-decoration: underline;
      text-underline-offset: 2px;
    }
    .deal-client-link:hover { color: #2f574e; }
    .deal-detail-section { margin-bottom: 20px; }
    .deal-detail-head {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }
    .deal-detail-head h2 { font-size: 1.05rem; font-weight: 600; flex: 1; }
    .deal-detail-bitrix {
      font-size: 0.82rem;
      color: var(--accent);
      text-decoration: none;
    }
    .deal-detail-bitrix:hover { text-decoration: underline; }
    .deal-detail-block {
      margin-bottom: 16px;
      padding: 14px;
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
    }
    .deal-detail-block h3 {
      font-size: 0.82rem;
      color: var(--muted);
      margin-bottom: 10px;
      font-weight: 600;
    }
    .deal-detail-block .record-detail-grid { margin: 0; }
    .deal-amt-ok .val { color: #15803d; font-weight: 600; }
    .deal-amt-due .val { color: #b91c1c; font-weight: 600; }
    .report-table tbody td.col-r-client,
    .report-table tbody td.col-r-project,
    .report-table tbody td.col-r-comment,
    .report-table thead th.col-r-client,
    .report-table thead th.col-r-project,
    .report-table thead th.col-r-comment {
      white-space: normal;
      overflow: hidden;
      text-overflow: clip;
      word-break: break-word;
      line-height: 1.35;
    }
    .report-table .col-r-id {
      width: auto;
      min-width: 4.75rem;
      white-space: nowrap;
    }
    .report-table .col-r-num {
      width: 2.6rem;
      min-width: 2.6rem;
      max-width: 2.6rem;
      box-sizing: border-box;
      text-align: center;
      font-variant-numeric: tabular-nums;
      color: #214767;
    }
    .report-table thead th.col-r-num,
    .report-table tbody td.col-r-num,
    .report-table thead th.col-r-id,
    .report-table tbody td.cell-id {
      position: sticky;
      z-index: 4;
      background-clip: padding-box;
    }
    .report-table thead th.col-r-num,
    .report-table tbody td.col-r-num {
      left: 0;
    }
    .report-table thead th.col-r-id,
    .report-table tbody td.cell-id {
      left: 2.6rem;
      box-shadow: 4px 0 8px rgba(0, 53, 87, 0.14);
    }
    .report-table thead th.col-r-num,
    .report-table thead th.col-r-id {
      z-index: 6;
      background: #003557;
      color: #fffdfe;
    }
    .report-table thead th.col-r-id.sortable:hover,
    .report-table thead th.col-r-num.sortable:hover {
      background: #214767;
    }
    .report-table tbody td:not(.col-r-num):not(.cell-id) {
      position: relative;
      z-index: 0;
    }
    .report-table tbody td.col-r-num,
    .report-table tbody td.cell-id {
      background: #fffdfe;
      overflow: hidden;
    }
    .report-table tbody tr:nth-child(even) td.col-r-num,
    .report-table tbody tr:nth-child(even) td.cell-id {
      background: #f3f7f9;
    }
    .report-table tbody tr.row-hover td.col-r-num,
    .report-table tbody tr.row-hover td.cell-id {
      background: #dce8e4 !important;
    }
    .report-table .col-r-client {
      min-width: 9.5rem;
      width: auto;
    }
    .report-table .col-r-date { width: 6.5%; white-space: nowrap; }
    .report-table .col-r-project {
      min-width: 10.5rem;
      width: auto;
    }
    .report-table .col-r-type { width: 6%; white-space: nowrap; }
    .report-table .col-r-no { width: 4%; white-space: nowrap; }
    .report-table .col-r-val { width: 6.5%; white-space: nowrap; }
    .report-table .col-r-paid { width: 6%; white-space: nowrap; }
    .report-table .col-r-pct { width: 4.5%; white-space: nowrap; }
    .report-table .col-r-agency { width: 8%; white-space: nowrap; }
    .report-table .col-r-comm { width: 3%; white-space: nowrap; }
    .report-table .col-r-credit { width: 4%; white-space: nowrap; }
    .report-table .col-r-pay { width: 5%; white-space: nowrap; }
    .report-table .col-r-paidc { width: 5.5%; white-space: nowrap; }
    .report-table .col-r-diff { width: 5%; white-space: nowrap; }
    .report-table .col-r-comment {
      min-width: 12rem;
      width: auto;
      font-size: 0.78rem;
      color: var(--text);
      padding: 6px 10px;
      vertical-align: top;
    }
    .report-table thead th.col-r-comment {
      font-size: 0.7rem;
      color: #fffdfe;
      vertical-align: bottom;
    }
    .report-table .num.report-amt-ok {
      background: rgba(47, 87, 78, 0.16);
      color: #2f574e;
      font-weight: 600;
    }
    .report-table .num.report-amt-due {
      background: rgba(220, 38, 38, 0.12);
      color: #b91c1c;
      font-weight: 600;
    }
    .report-table thead tr.report-totals-row th {
      background: #2f574e;
      font-weight: 600;
      font-size: 0.8rem;
      color: #fffdfe;
      border-bottom: 1px solid #24453e;
      border-right: 1px solid #24453e;
      padding: 7px 6px;
      vertical-align: middle;
    }
    .report-table thead tr.report-totals-row th.report-totals-empty {
      font-weight: 400;
      color: transparent;
      user-select: none;
    }
    .report-table thead tr.report-totals-row th.num {
      text-align: right;
      font-variant-numeric: tabular-nums;
    }
    .report-table thead tr.report-totals-row th.col-r-num,
    .report-table thead tr.report-totals-row th.col-r-id {
      z-index: 6;
      background: #2f574e;
    }

    /* ── Stats (legacy helpers) ── */
    .stats-section { margin-bottom: 20px; }

    /* ── Layout ── */
    .main-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-bottom: 22px;
      align-items: start;
    }
    @media (max-width: 920px) { .main-grid { grid-template-columns: 1fr; } }

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
    }
    .card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 13px 18px;
      background: var(--surface-2);
      border-bottom: 1px solid var(--border);
      font-weight: 600;
      font-size: 0.92rem;
    }
    .card-head .tag {
      font-size: 0.72rem;
      font-weight: 500;
      padding: 2px 8px;
      border-radius: 10px;
      background: rgba(77, 142, 247, 0.15);
      color: #1d4ed8;
    }
    .card-body { padding: 18px; }

    /* ── Form ── */
    .field { margin-bottom: 13px; }
    .field label {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.78rem;
      color: var(--muted);
      margin-bottom: 5px;
      font-weight: 500;
    }
    .field input, .field select, .field textarea {
      width: 100%;
      padding: 9px 12px;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 7px;
      color: var(--text);
      font: inherit;
      font-size: 0.88rem;
      transition: border-color 0.15s;
    }
    .field input:focus, .field select:focus, .field textarea:focus {
      outline: none;
      border-color: var(--border-focus);
    }
    .field input.error, .field select.error, .field textarea.error {
      border-color: var(--danger);
    }
    .field-error { font-size: 0.74rem; color: var(--danger); margin-top: 4px; display: none; }
    .field-error.show { display: block; }
    .field textarea { resize: vertical; min-height: 68px; }
    .field input[type="date"] { color-scheme: light; cursor: pointer; }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    @media (max-width: 520px) { .field-row { grid-template-columns: 1fr; } }

    .sub-block {
      margin-top: 14px;
      padding-top: 14px;
      border-top: 1px solid var(--border);
    }
    .sub-block h3 { font-size: 0.88rem; margin-bottom: 3px; }
    .sub-block p { font-size: 0.78rem; color: var(--muted); margin-bottom: 12px; }

    .autofill-hint {
      display: none;
      font-size: 0.76rem;
      color: #34d399;
      margin-top: 4px;
    }
    .autofill-hint.show { display: block; }

    .preview-pill {
      display: none;
      margin-top: 10px;
      padding: 7px 12px;
      background: rgba(77, 142, 247, 0.1);
      border: 1px solid rgba(77, 142, 247, 0.25);
      border-radius: 8px;
      font-size: 0.8rem;
      color: #1d4ed8;
    }
    .preview-pill.show { display: block; }

    .form-actions { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; }

    .phones-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 6px; }
    .phone-row { display: flex; gap: 6px; align-items: center; }
    .phone-row .phone-input { flex: 1; min-width: 0; }
    .phone-row .phone-remove {
      flex-shrink: 0;
      padding: 4px 10px;
      font-size: 1rem;
      line-height: 1;
      min-width: 36px;
    }
    .phones-actions { margin-top: 2px; }

    /* ── რეგისტრაციის ფორმა (კომპაქტური) ── */
    .register-form { padding: 14px 16px; }
    .register-form .register-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 10px;
    }
    @media (min-width: 901px) {
      .register-form .register-grid {
        grid-template-columns: repeat(3, 1fr);
      }
    }
    .register-form .field { margin-bottom: 0; }
    .register-form .field.span-full { grid-column: 1 / -1; }
    .register-form .field label {
      margin-bottom: 3px;
      font-size: 0.72rem;
    }
    .register-form .field input,
    .register-form .field select,
    .register-form .field textarea {
      padding: 6px 10px;
      font-size: 0.82rem;
      border-radius: 6px;
    }
    .register-form .field textarea { min-height: 50px; }
    .register-form .phones-list { gap: 4px; margin-bottom: 4px; }
    .register-form .phone-row .phone-remove { padding: 3px 8px; min-width: 30px; }
    .register-form .sub-block {
      margin-top: 10px;
      padding-top: 10px;
    }
    .register-form .sub-block h3 {
      font-size: 0.82rem;
      margin-bottom: 8px;
    }
    .register-form .field-row { gap: 8px; }
    .register-form .field-row .field { margin-bottom: 0; }
    .register-form .contract-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 10px;
    }
    .register-form .contract-grid .field { margin-bottom: 0; }
    @media (min-width: 901px) {
      .register-form .contract-grid {
        grid-template-columns: 2fr 1fr 1fr 1fr 1fr;
      }
    }
    .register-form .form-actions { margin-top: 12px; }
    .register-form .autofill-hint { font-size: 0.7rem; margin-top: 2px; }
    .register-form .field-error { font-size: 0.68rem; margin-top: 2px; }
    .register-form .preview-pill { margin-top: 8px; padding: 5px 10px; font-size: 0.76rem; }
    @media (max-width: 600px) {
      .register-form .register-grid { grid-template-columns: 1fr; }
      .register-form .field.span-full { grid-column: auto; }
      .register-form .contract-grid { grid-template-columns: 1fr; }
    }

    /* ── Buttons ── */
    .btn {
      padding: 8px 16px;
      border-radius: 7px;
      border: none;
      font: inherit;
      font-size: 0.84rem;
      font-weight: 500;
      cursor: pointer;
      transition: background 0.15s, opacity 0.15s;
    }
    .btn:disabled { opacity: 0.45; cursor: not-allowed; }
    .btn-primary { background: var(--accent); color: #fff; }
    .btn-primary:hover:not(:disabled) { background: var(--accent-hover); }
    .btn-ghost {
      background: transparent;
      color: var(--muted);
      border: 1px solid var(--border);
    }
    .btn-ghost:hover:not(:disabled) { color: var(--text); border-color: var(--muted); }
    .btn-sm { padding: 5px 10px; font-size: 0.76rem; }
    .btn-danger { background: rgba(220,38,38,0.1); color: #b91c1c; border: 1px solid rgba(220,38,38,0.35); }
    .btn-danger:hover { background: rgba(220,38,38,0.18); }
    .btn-link {
      background: none; border: none; color: var(--accent);
      padding: 0; font-size: 0.78rem; cursor: pointer;
    }
    .btn-link:hover { text-decoration: underline; }
    .btn-link.danger { color: #dc2626; }

    /* ── რეესტრის სვეტები ── */
    .cell-center { text-align: center; }
    .row-inactive { opacity: 0.55; }
    .registry-group-row.row-expiring td {
      background: color-mix(in srgb, #fecaca 70%, var(--surface)) !important;
    }
    .registry-group-row.row-expiring.row-inactive {
      opacity: 0.88;
    }
    .registry-group-row.row-expiring.row-inactive td {
      background: color-mix(in srgb, #fecaca 55%, var(--surface)) !important;
    }
    .active-label {
      display: inline-block;
      font-size: 0.74rem;
      margin-left: 6px;
      color: var(--muted);
      min-width: 28px;
    }
    .active-label.yes { color: #15803d; }
    .active-label.no { color: #dc2626; }
    .gen-meta { display: block; font-size: 0.7rem; color: var(--muted); margin-top: 3px; }
    .signed-cell { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .signed-name {
      font-size: 0.78rem;
      color: var(--accent);
      cursor: pointer;
      text-decoration: underline;
      max-width: 120px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .signed-docs-list { display: flex; flex-direction: column; gap: 4px; }
    .signed-doc-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .signed-doc-date { font-size: 0.72rem; color: var(--muted); }

    .active-history-list { display: flex; flex-direction: column; gap: 8px; }
    .active-history-row {
      display: grid;
      grid-template-columns: auto auto 1fr;
      gap: 8px 10px;
      align-items: start;
      padding: 8px 10px;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 6px;
      font-size: 0.78rem;
    }
    .active-history-date { color: var(--muted); white-space: nowrap; }
    .active-history-comment { word-break: break-word; }

    .modal .field { margin-bottom: 0; }
    .modal .field label {
      display: block;
      font-size: 0.78rem;
      color: var(--muted);
      margin-bottom: 4px;
      font-weight: 500;
    }
    .modal .field textarea {
      width: 100%;
      padding: 8px 10px;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 6px;
      color: var(--text);
      font: inherit;
      font-size: 0.84rem;
      resize: vertical;
      min-height: 72px;
    }
    .modal .field textarea:focus {
      outline: none;
      border-color: var(--border-focus);
    }

    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 36px;
      height: 20px;
      vertical-align: middle;
      cursor: pointer;
    }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute;
      inset: 0;
      background: var(--surface-3);
      border: 1px solid var(--border);
      border-radius: 20px;
      transition: background 0.2s;
    }
    .toggle-slider::before {
      content: '';
      position: absolute;
      width: 14px;
      height: 14px;
      left: 2px;
      top: 2px;
      background: var(--muted);
      border-radius: 50%;
      transition: transform 0.2s, background 0.2s;
    }
    .toggle-switch input:checked + .toggle-slider {
      background: rgba(34, 197, 94, 0.25);
      border-color: rgba(34, 197, 94, 0.5);
    }
    .toggle-switch input:checked + .toggle-slider::before {
      transform: translateX(16px);
      background: #22c55e;
    }

    /* ── Tables ── */
    .section-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
      flex-wrap: wrap;
    }
    .section-head h2 { font-size: 1.05rem; font-weight: 600; }

    .filter-panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 8px 12px 10px;
      margin-bottom: 10px;
    }
    .filter-panel-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 6px;
      gap: 8px;
    }
    .filter-panel-head span {
      font-size: 0.68rem;
      color: var(--muted);
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .filter-panel-head .btn-sm {
      padding: 3px 8px;
      font-size: 0.7rem;
    }
    .filter-clear-btn {
      padding: 3px 10px;
      font-size: 0.7rem;
      white-space: nowrap;
    }
    .filter-grid {
      display: flex;
      flex-wrap: nowrap;
      gap: 6px;
      align-items: flex-end;
    }
    .filter-field {
      flex: 1 1 0;
      min-width: 0;
    }
    .filter-panel-footer {
      display: flex;
      justify-content: flex-end;
      margin-top: 6px;
    }
    .registry-toolbar {
      display: flex;
      justify-content: flex-end;
      margin-bottom: 8px;
    }
    .registry-toolbar-actions {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
    }
    @media (max-width: 768px) {
      .filter-grid { flex-wrap: wrap; }
      .filter-field { flex: 1 1 calc(50% - 6px); min-width: 120px; }
    }
    @media (max-width: 480px) {
      .filter-field { flex: 1 1 100%; }
    }
    .filter-field label {
      display: block;
      font-size: 0.66rem;
      color: var(--muted);
      margin-bottom: 2px;
      font-weight: 500;
      line-height: 1.2;
    }
    .filter-field input,
    .filter-field select {
      width: 100%;
      padding: 4px 7px;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 6px;
      color: var(--text);
      font: inherit;
      font-size: 0.76rem;
    }
    .filter-field input[type="date"] { color-scheme: light; }
    .filter-field input:focus,
    .filter-field select:focus {
      outline: none;
      border-color: var(--border-focus);
    }

    .table-wrap {
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: auto;
      overflow-anchor: none;
    }
    .table-wrap-projects {
      overflow-x: hidden;
    }
    .table-wrap-wide {
      overflow-x: auto;
    }
    .table-wrap-wide:has(.registry-table) {
      overflow-x: hidden;
    }
    table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
    .table-wrap-wide table { min-width: 1200px; }
    .table-wrap-wide table.registry-table {
      min-width: 0;
      width: 100%;
      table-layout: fixed;
    }
    .registry-table thead th {
      white-space: normal;
      line-height: 1.25;
      padding: 6px 5px;
      font-size: 0.65rem;
      letter-spacing: 0;
      text-transform: none;
      vertical-align: bottom;
      word-break: break-word;
      border-bottom: 1px solid var(--border);
      border-right: 1px solid var(--border);
    }
    .registry-table thead th:last-child {
      border-right: none;
    }
    .registry-table tbody td {
      padding: 8px 5px;
      font-size: 0.78rem;
      word-break: break-word;
      overflow-wrap: anywhere;
      border-bottom: 1px solid var(--border);
      border-right: 1px solid var(--border);
    }
    .registry-table tbody td:last-child {
      border-right: none;
    }
    .registry-table .col-agency { width: 14%; }
    .registry-table .col-project { width: 14%; }
    .registry-table .col-commission { width: 7%; }
    .registry-table .col-credit { width: 9%; }
    .registry-table .col-gen { width: 9%; }
    .registry-table .col-active { width: 8%; }
    .registry-table .col-signed { width: 14%; }
    .registry-table .col-actions { width: 14%; }

    .registry-group-row.registry-group-alt td {
      background: color-mix(in srgb, var(--surface-2, var(--surface)) 55%, transparent);
    }
    .registry-agency-cell {
      vertical-align: top;
      border-right: 2px solid color-mix(in srgb, var(--border) 85%, #64748b);
      background: inherit;
    }
    .registry-agency-wrap {
      display: flex;
      flex-direction: column;
      gap: 4px;
      min-height: 100%;
    }
    .registry-agency-name {
      font-weight: 600;
      font-size: 0.8rem;
      line-height: 1.25;
    }
    .registry-agency-meta {
      font-size: 0.68rem;
      color: var(--muted);
    }
    .registry-add-project {
      align-self: flex-start;
      margin-top: 2px;
      font-size: 0.72rem;
    }
    .registry-project-cell {
      font-weight: 500;
    }
    .registry-group-multi .registry-group-next .registry-project-cell {
      color: var(--text);
      font-weight: 400;
    }
    .registry-group-multi .registry-group-next .registry-project-cell::before {
      content: '↳ ';
      color: var(--muted);
      font-size: 0.72rem;
    }
    .registry-group-first td {
      border-top: 2px solid color-mix(in srgb, var(--border) 90%, #94a3b8);
    }
    .registry-group-next td:not(.registry-agency-cell) {
      padding-top: 5px;
      padding-bottom: 5px;
      border-top: 1px dashed color-mix(in srgb, var(--border) 75%, transparent);
    }
    .registry-active-cell {
      vertical-align: middle;
      text-align: center;
    }
    .registry-active-cell .active-label {
      display: inline-block;
      margin-left: 6px;
    }
    .table-wrap-projects table {
      min-width: 0;
      table-layout: fixed;
    }
    .table-wrap-projects .col-pr-name { width: 24%; word-break: break-word; white-space: normal; }
    .table-wrap-projects .col-pr-code { width: 16%; word-break: break-word; white-space: normal; }
    .table-wrap-projects .col-pr-director { width: 16%; word-break: break-word; white-space: normal; }
    .table-wrap-projects .col-pr-contracts { width: 12%; text-align: center; }
    .table-wrap-projects .col-pr-active { width: 20%; text-align: center; }
    .table-wrap-projects .col-pr-actions { width: 12%; text-align: center; white-space: nowrap; }
    .table-wrap-projects .col-pr-active .toggle-switch { margin-right: 4px; }
    thead th {
      text-align: left;
      padding: 9px 12px;
      background: var(--surface-2);
      color: var(--muted);
      font-weight: 500;
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      border-bottom: 1px solid var(--border);
      white-space: nowrap;
    }
    tbody td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
    }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover { background: rgba(0,0,0,0.03); }
    tbody tr.editing { background: rgba(37, 99, 235, 0.08); }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .actions-cell { white-space: nowrap; }
    .actions-cell button + button { margin-left: 6px; }

    .badge {
      font-size: 0.72rem;
      padding: 2px 9px;
      border-radius: 10px;
      background: var(--surface-3);
      border: 1px solid var(--border);
      color: var(--muted);
    }

    .info-box {
      margin-top: 12px;
      padding: 10px 12px;
      background: var(--surface-2);
      border-radius: 7px;
      font-size: 0.78rem;
      color: var(--muted);
    }

    .empty-row td {
      text-align: center;
      color: var(--muted);
      padding: 28px;
      font-size: 0.88rem;
    }

    /* ── Toast ── */
    .toast-wrap {
      position: fixed;
      bottom: 20px;
      right: 20px;
      z-index: 100;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .toast {
      padding: 10px 16px;
      border-radius: 8px;
      font-size: 0.84rem;
      background: var(--surface-2);
      border: 1px solid var(--border);
      animation: slideIn 0.25s ease;
      max-width: 320px;
    }
    .toast.success { border-color: rgba(22,163,74,0.45); color: #15803d; }
    .toast.error { border-color: rgba(220,38,38,0.45); color: #b91c1c; }
    @keyframes slideIn {
      from { transform: translateX(20px); opacity: 0; }
      to { transform: none; opacity: 1; }
    }

    /* ── Modal ── */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.55);
      z-index: 200;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .modal-overlay.open { display: flex; }
    .modal {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 22px;
      max-width: 400px;
      width: 100%;
      max-height: calc(100vh - 40px);
      overflow-y: auto;
      overflow-x: hidden;
    }
    .modal h3 { font-size: 1rem; margin-bottom: 8px; }
    .modal p { font-size: 0.86rem; color: var(--muted); margin-bottom: 18px; }
    .modal-actions { display: flex; gap: 8px; justify-content: flex-end; }
    .modal-wide { max-width: 520px; }
    .modal-agencies { max-width: 440px; }

    .contract-count-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 32px;
      padding: 4px 12px;
      border: 1px solid var(--border);
      border-radius: 8px;
      background: rgba(77, 142, 247, 0.12);
      color: #1d4ed8;
      font: inherit;
      font-size: 0.88rem;
      font-weight: 600;
      font-variant-numeric: tabular-nums;
      cursor: pointer;
      transition: background 0.15s, border-color 0.15s;
    }
    .contract-count-btn:hover {
      background: rgba(77, 142, 247, 0.22);
      border-color: var(--accent);
    }
    .contract-count-zero {
      color: var(--muted);
      font-variant-numeric: tabular-nums;
    }
    .agency-cards-scroll {
      max-height: 50vh;
      overflow-y: auto;
      overflow-x: hidden;
      margin-bottom: 4px;
    }
    .agency-cards-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .agency-card {
      background: var(--surface-2);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px 14px;
    }
    .agency-card-name {
      font-weight: 600;
      font-size: 0.9rem;
      margin-bottom: 8px;
      line-height: 1.35;
      word-break: break-word;
    }
    .agency-card-meta {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 14px;
    }
    .agency-card-meta .lbl {
      display: block;
      font-size: 0.68rem;
      color: var(--muted);
      margin-bottom: 2px;
    }
    .agency-card-meta .val {
      font-size: 0.82rem;
      font-weight: 500;
    }
    .project-agencies-empty {
      text-align: center;
      color: var(--muted);
      padding: 20px;
      font-size: 0.86rem;
    }

    .registry-table tbody tr[data-id] {
      cursor: pointer;
    }
    .registry-table tbody tr[data-id]:hover {
      background: rgba(37, 99, 235, 0.06);
    }
    .record-detail-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px 18px;
      background: var(--surface-2);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 14px 16px;
    }
    .record-detail-grid .detail-item .lbl {
      display: block;
      font-size: 0.72rem;
      color: var(--muted);
      margin-bottom: 3px;
    }
    .record-detail-grid .detail-item .val {
      display: block;
      font-size: 0.88rem;
      font-weight: 500;
      word-break: break-word;
    }
    .record-detail-grid .detail-item.full { grid-column: 1 / -1; }
    @media (max-width: 520px) {
      .record-detail-grid { grid-template-columns: 1fr; }
    }

    .agent-preview {
      background: var(--surface-2);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px 14px;
      margin-bottom: 14px;
      font-size: 0.84rem;
    }
    .agent-preview dt { color: var(--muted); font-size: 0.72rem; margin-top: 6px; }
    .agent-preview dt:first-child { margin-top: 0; }
    .agent-preview dd { margin: 2px 0 0; font-weight: 500; }
    .existing-projects { margin-bottom: 14px; }
    .copy-projects-panel {
      margin-bottom: 14px;
      padding: 10px 12px;
      background: var(--surface-2, var(--bg));
      border: 1px dashed var(--border);
      border-radius: var(--radius);
    }
    .copy-projects-panel .lbl { font-size: 0.74rem; color: var(--muted); margin-bottom: 8px; }
    .copy-projects-row {
      display: flex;
      align-items: flex-end;
      gap: 8px;
      flex-wrap: wrap;
    }
    .copy-projects-row .field { flex: 1; min-width: 160px; margin-bottom: 0; }
    .existing-projects .lbl { font-size: 0.74rem; color: var(--muted); margin-bottom: 6px; }
    .project-tags { display: flex; flex-wrap: wrap; gap: 6px; }
    .project-tag {
      font-size: 0.72rem;
      padding: 3px 9px;
      border-radius: 10px;
      background: rgba(77, 142, 247, 0.12);
      border: 1px solid rgba(77, 142, 247, 0.25);
      color: #1d4ed8;
    }
    .btn-link.accent { color: #1d4ed8; }

    .runtime-warning {
      display: none;
      margin-bottom: 14px;
      padding: 12px 14px;
      border-radius: 8px;
      border: 1px solid #f59e0b;
      background: #fffbeb;
      color: #92400e;
      font-size: 0.84rem;
      line-height: 1.45;
    }
    .runtime-warning.show { display: block; }
    .runtime-warning b { display: block; margin-bottom: 4px; }
  </style>
</head>
<body>
  <div class="app">
    <div class="runtime-warning" id="runtime-warning">
      <b>Bitrix API არ იმუშავებს ამ გახსნის ტიპიდან</b>
      გახსენით მოდული HTTP სერვერით: ორჯერ დააჭირეთ <code>start.bat</code>
      (ან <code>start-ps.bat</code>) და გადადით <code>http://localhost:8765</code>-ზე.
    </div>
    <div class="top-bar">
      <div class="top-bar-brand">
        <a href="https://monolith.ge/" target="_blank" rel="noopener noreferrer" class="brand-logo-link" title="მონოლითი">
          <img src="logo.jpg" alt="მონოლითი" class="brand-logo" />
        </a>
        <div>
          <h1>აგენტების მოდული — მონოლითი</h1>
        </div>
      </div>
      <div class="top-bar-actions">
        <span class="bitrix-status load" id="bitrix-status" title="Bitrix24 კავშირი">Bitrix…</span>
      </div>
    </div>

    <nav class="module-nav" aria-label="მოდულის ჩანართები">
      <button type="button" class="nav-card" data-tab="stats">
        <b>① აგენტების გაყიდვების რეპორტი</b>
        <small>აგენტების რეპორტი</small>
      </button>
      <button type="button" class="nav-card" data-tab="register">
        <b>② აგენტის რეგისტრაცია</b>
        <small>აგენტი + პროექტი + %</small>
      </button>
      <button type="button" class="nav-card" data-tab="registry">
        <b>③ აგენტების რეესტრი</b>
        <small>შენახული ჩანაწერები</small>
      </button>
      <button type="button" class="nav-card" data-tab="projects">
        <b>④ პროექტების დამატება</b>
        <small>დამატება, რედაქტირება, სია</small>
      </button>
    </nav>

    <div id="app-loading" class="app-loading" aria-live="polite">
      <p>იტვირთება...</p>
    </div>

    <div id="tab-stats" class="tab-panel">
      <div class="report-section">
        <div class="report-head">
          <h2>აგენტების რეპორტი</h2>
          <span class="badge" id="report-count">ნაპოვნია: 0 ჩანაწერი</span>
        </div>
        <div class="report-toolbar">
          <div class="report-filter-field">
            <label for="report-filter-project">პროექტი</label>
            <select id="report-filter-project"><option value="">ყველა</option></select>
          </div>
          <div class="report-filter-field">
            <label for="report-filter-agency">სააგენტო</label>
            <input type="text" id="report-filter-agency" placeholder="ძებნა…" />
          </div>
          <div class="report-filter-field autocomplete-wrap">
            <label for="report-filter-client">კლიენტი</label>
            <input type="text" id="report-filter-client" placeholder="მინ. 2 ასო…" autocomplete="off" />
            <div class="suggest-dropdown" id="report-client-suggest" role="listbox" hidden></div>
          </div>
          <div class="report-filter-field">
            <label for="report-date-from-display">ხელშ. გაფ. თარიღიდან</label>
            <div class="dmy-date">
              <input type="text" id="report-date-from-display" class="dmy-date-text" inputmode="numeric" placeholder="dd/mm/yyyy" maxlength="10" autocomplete="off" />
              <input type="date" id="report-date-from" class="dmy-date-native" tabindex="-1" aria-label="თარიღიდან" />
            </div>
          </div>
          <div class="report-filter-field">
            <label for="report-date-to-display">ხელშ. გაფ. თარიღამდე</label>
            <div class="dmy-date">
              <input type="text" id="report-date-to-display" class="dmy-date-text" inputmode="numeric" placeholder="dd/mm/yyyy" maxlength="10" autocomplete="off" />
              <input type="date" id="report-date-to" class="dmy-date-native" tabindex="-1" aria-label="თარიღამდე" />
            </div>
          </div>
          <div class="report-toolbar-actions">
            <button type="button" class="btn btn-primary btn-sm" id="report-search">ძიება</button>
            <button type="button" class="btn btn-ghost btn-sm" id="report-reload">განახლება</button>
            <button type="button" class="btn btn-primary btn-sm" id="report-export">ექსელში გატანა</button>
          </div>
        </div>
        <div class="table-wrap report-table-wrap">
          <table class="report-table">
            <thead>
              <tr class="report-totals-row" id="report-totals-row">
                <th class="col-r-num report-totals-empty"></th>
                <th class="col-r-id cell-id report-totals-empty"></th>
                <th class="report-totals-empty col-r-client"></th>
                <th class="report-totals-empty col-r-date"></th>
                <th class="report-totals-empty col-r-project"></th>
                <th class="report-totals-empty"></th>
                <th class="report-totals-empty"></th>
                <th class="num col-r-val" data-total="propertyValue">—</th>
                <th class="num col-r-paid" data-total="paidUsd">—</th>
                <th class="report-totals-empty"></th>
                <th class="report-totals-empty"></th>
                <th class="report-totals-empty"></th>
                <th class="report-totals-empty"></th>
                <th class="num col-r-pay" data-total="payable">—</th>
                <th class="num col-r-paidc" data-total="paidCommission">—</th>
                <th class="num col-r-diff" data-total="difference">—</th>
                <th class="report-totals-empty report-settle-cell"></th>
                <th class="report-totals-empty col-r-comment"></th>
              </tr>
              <tr>
                <th class="col-r-num" title="რიგითობა">#</th>
                <th class="col-r-id sortable" data-sort="leadId" data-sort-type="number" title="ლიდის ID">ID</th>
                <th class="col-r-client sortable" data-sort="client" data-sort-type="string" title="კლიენტი">კლიენტი</th>
                <th class="col-r-date sortable" data-sort="signedDate" data-sort-type="date" title="ხელშეკრულების გაფორმების თარიღი">გაფორმება</th>
                <th class="col-r-project sortable" data-sort="project" data-sort-type="string" title="პროექტი">პროექტი</th>
                <th class="col-r-type sortable" data-sort="propertyType" data-sort-type="string" title="უძრავი ქონების ტიპი">უძ. ტიპი</th>
                <th class="col-r-no sortable" data-sort="propertyNo" data-sort-type="string" title="უძრავი ქონების #">#</th>
                <th class="col-r-val num sortable" data-sort="propertyValue" data-sort-type="number" title="უძრავი ქონების ღირებულება $">ღირებულება $</th>
                <th class="col-r-paid num sortable" data-sort="paidUsd" data-sort-type="number" title="კლიენტის გადახდილი თანხა $">გადახდილი $</th>
                <th class="col-r-pct num sortable" data-sort="paidPct" data-sort-type="number" title="კლიენტის გადახდილი %">გადახდილი %</th>
                <th class="col-r-agency sortable" data-sort="agency" data-sort-type="string" title="სააგენტო">სააგენტო</th>
                <th class="col-r-comm num sortable" data-sort="commission" data-sort-type="number" title="საკომისიო">საკომ.</th>
                <th class="col-r-credit num sortable" data-sort="creditPct" data-sort-type="number" title="ჩასარიცხი თანხა (%)">ჩასარიცხი %</th>
                <th class="col-r-pay num sortable" data-sort="payable" data-sort-type="number" title="გადასახდელი $">გადასახ. $</th>
                <th class="col-r-paidc num sortable" data-sort="paidCommission" data-sort-type="number" title="აგენტზე გადახდილი საკომისიო $">გადახ. საკ. $</th>
                <th class="col-r-diff num sortable" data-sort="difference" data-sort-type="number" title="სხვაობა $">სხვაობა $</th>
                <th class="report-settle-cell" title="გადახდილად / დახურული — აღარ გამოჩნდება დავალიანებაში">გადახდილი</th>
                <th class="col-r-comment sortable" data-sort="commissionComment" data-sort-type="string" title="კომენტარი აგენტის საკომისიოს გადახდაზე">კომენტარი</th>
              </tr>
            </thead>
            <tbody id="report-table"></tbody>
          </table>
        </div>
      </div>
    </div>

    <div id="tab-deal" class="tab-panel tab-panel-deal">
      <div class="deal-detail-section">
        <div class="deal-detail-head">
          <button type="button" class="btn btn-ghost btn-sm" id="deal-back-btn">← სტატისტიკა</button>
          <h2 id="deal-detail-title">Deal</h2>
          <a class="deal-detail-bitrix" id="deal-bitrix-link" href="#" target="_blank" rel="noopener noreferrer">Bitrix-ში გახსნა ↗</a>
        </div>
        <div id="deal-detail-body"></div>
      </div>
    </div>

    <div id="tab-register" class="tab-panel tab-panel-register">
    <div class="main-grid" style="grid-template-columns:1fr">
      <div class="card">
        <div class="card-head">
          <span id="form-title">აგენტის რეგისტრაცია</span>
          <span class="tag" id="form-mode-tag">ახალი</span>
        </div>
        <div class="card-body register-form">
          <form id="agent-form" novalidate>
            <input type="hidden" id="edit-id" value="" />

            <div class="register-grid">
              <div class="field">
                <label for="agency">დასახელება *</label>
                <input id="agency" type="text" placeholder="მაგ: თბილისის უძრავი ქონება" autocomplete="organization" />
                <div class="field-error" id="err-agency">შეიყვანეთ დასახელება</div>
              </div>

              <div class="field">
                <label for="idCode">ს/კ</label>
                <input id="idCode" type="text" placeholder="9 ან 11 ციფრი" inputmode="numeric" maxlength="11" />
                <div class="autofill-hint" id="autofill-hint">არსებული აგენტი — მონაცემები ავტომატურად შეივსო</div>
                <div class="field-error" id="err-idCode">შეიყვანეთ სწორი ს/კ (9 ან 11 ციფრი)</div>
              </div>

              <div class="field">
                <label for="contactPerson">საკონტაქტო სახელი / გვარი</label>
                <input id="contactPerson" type="text" placeholder="მაგ: გიორგი ბერიძე" autocomplete="name" />
              </div>

              <div class="field">
                <label>მობ. ტელეფონი</label>
                <div id="phones-list" class="phones-list"></div>
                <div class="phones-actions">
                  <button type="button" class="btn btn-ghost btn-sm" id="add-phone">+ ტელეფონი</button>
                </div>
                <div class="field-error" id="err-phones">შეიყვანეთ მინიმუმ ერთი ტელეფონი</div>
              </div>

              <div class="field">
                <label for="email">მეილი</label>
                <input id="email" type="email" placeholder="მაგ: info@agency.ge" autocomplete="email" />
                <div class="field-error" id="err-email">შეიყვანეთ სწორი ელფოსტის მისამართი</div>
              </div>

              <div class="field span-full">
                <label for="bank">საბანკო რეკვიზიტები</label>
                <textarea id="bank" placeholder="ბანკი · IBAN · მიმღების სახელი" rows="2"></textarea>
                <div class="field-error" id="err-bank">შეიყვანეთ საბანკო რეკვიზიტები</div>
              </div>
            </div>

            <div class="sub-block">
              <h3>ხელშეკრულება პროექტზე</h3>
              <div class="contract-grid">
                <div class="field span-full">
                  <label>დოკუმენტის ტიპი *</label>
                  <div class="doc-type-options">
                    <label class="doc-type-option">
                      <input type="radio" name="docType" value="contract" checked />
                      ხელშეკრულება
                    </label>
                    <label class="doc-type-option">
                      <input type="radio" name="docType" value="one_time" />
                      ერთჯერადი შეთანხმება
                    </label>
                  </div>
                </div>
                <div class="field">
                  <label for="project">პროექტი</label>
                  <select id="project">
                    <option value="">აირჩიეთ პროექტი…</option>
                  </select>
                  <div class="field-error" id="err-project">აირჩიეთ პროექტი</div>
                </div>
                <div class="field" id="commission-pct-field">
                  <label for="commission">საკომისიო (%) *</label>
                  <input id="commission" type="number" step="0.1" min="0.1" max="100" placeholder="მაგ: 3.5" />
                  <div class="field-error" id="err-commission">შეიყვანეთ 0.1–100 შორის</div>
                </div>
                <div class="field" id="commission-amount-field" hidden>
                  <label for="commissionAmount">საკომისიო ($) *</label>
                  <input id="commissionAmount" type="number" step="1" min="1" placeholder="მაგ: 5000" />
                  <div class="field-error" id="err-commissionAmount">შეიყვანეთ ფიქსირებული თანხა ($)</div>
                </div>
                <div class="field">
                  <label for="creditPct">ჩასარიცხი თანხა (%) *</label>
                  <input id="creditPct" type="number" step="0.1" min="0.1" max="100" placeholder="მაგ: 10" />
                  <div class="field-error" id="err-creditPct">შეიყვანეთ 0.1–100 შორის</div>
                </div>
                <div class="field">
                  <label for="signedDate">გაფორმების თარიღი</label>
                  <input id="signedDate" type="date" />
                  <div class="field-error" id="err-signedDate">აირჩიეთ გაფორმების თარიღი</div>
                </div>
                <div class="field">
                  <label for="validUntil">მოქმედების ვადა</label>
                  <input id="validUntil" type="date" />
                  <div class="field-error" id="err-validUntil">აირჩიეთ მოქმედების ვადა</div>
                </div>
              </div>
              <div class="preview-pill" id="preview"></div>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn btn-primary" id="submit-btn">აგენტის რეგისტრაცია</button>
              <button type="button" class="btn btn-ghost" id="cancel-edit" style="display:none">გაუქმება</button>
              <button type="reset" class="btn btn-ghost">გასუფთავება</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    </div>

    <div id="tab-projects" class="tab-panel tab-panel-projects">
      <div class="card">
        <div class="card-head">
          <span id="project-form-title">პროექტის რეგისტრაცია</span>
          <span class="tag" id="project-form-mode-tag">ახალი</span>
        </div>
        <form id="project-register-form" class="project-register-form" novalidate>
          <input type="hidden" id="pr-edit-id" value="" />
          <div class="register-grid">
            <div class="field">
              <label for="pr-name">პროექტი *</label>
              <input type="text" id="pr-name" placeholder="მაგ: ჯიქია" autocomplete="off" />
              <div class="field-error" id="err-pr-name">შეიყვანეთ პროექტის სახელი</div>
            </div>
            <div class="field">
              <label for="pr-idCode">საიდენტიფიკაციო კოდი *</label>
              <input type="text" id="pr-idCode" placeholder="მაგ: 405123456" inputmode="numeric" autocomplete="off" />
              <div class="field-error" id="err-pr-idCode">შეიყვანეთ საიდენტიფიკაციო კოდი</div>
            </div>
            <div class="field">
              <label for="pr-director">დირექტორი *</label>
              <input type="text" id="pr-director" placeholder="სახელი გვარი" autocomplete="off" />
              <div class="field-error" id="err-pr-director">შეიყვანეთ დირექტორი</div>
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="pr-submit-btn">რეგისტრაცია</button>
            <button type="button" class="btn btn-ghost" id="pr-cancel-edit" style="display:none">გაუქმება</button>
            <button type="button" class="btn btn-ghost" id="pr-reset">გასუფთავება</button>
          </div>
        </form>
      </div>
      <div class="card">
        <div class="card-head">
          <span>პროექტები</span>
          <span class="badge" id="projects-count">0 პროექტი</span>
        </div>
        <div class="card-body">
          <div class="filter-panel">
            <div class="filter-panel-head">
              <span>ფილტრი</span>
            </div>
            <div class="filter-grid">
              <div class="filter-field">
                <label for="filter-project-name">პროექტი</label>
                <input type="text" id="filter-project-name" placeholder="ძებნა…" />
              </div>
              <div class="filter-field">
                <label for="filter-project-idCode">ს/კ</label>
                <input type="text" id="filter-project-idCode" placeholder="ძებნა…" inputmode="numeric" />
              </div>
              <div class="filter-field">
                <label for="filter-project-director">დირექტორი</label>
                <input type="text" id="filter-project-director" placeholder="ძებნა…" />
              </div>
              <div class="filter-field">
                <label for="filter-project-active">აქტიური</label>
                <select id="filter-project-active">
                  <option value="">ყველა</option>
                  <option value="yes">დიახ</option>
                  <option value="no">არა</option>
                </select>
              </div>
            </div>
            <div class="filter-panel-footer">
              <button type="button" class="btn btn-ghost btn-sm filter-clear-btn" id="project-filter-clear">ფილტრის გასუფთავება</button>
            </div>
          </div>
          <div class="table-wrap table-wrap-projects">
            <table>
              <thead>
                <tr>
                  <th class="col-pr-name">პროექტი</th>
                  <th class="col-pr-code">საიდენტიფიკაციო კოდი</th>
                  <th class="col-pr-director">დირექტორი</th>
                  <th class="col-pr-contracts">ხელშეკრულება</th>
                  <th class="col-pr-active cell-center" title="აქტიური">აქტ.</th>
                  <th class="col-pr-actions"></th>
                </tr>
              </thead>
              <tbody id="projects-table"></tbody>
            </table>
          </div>
          <div class="info-box">
            ერთ აგენტზე შესაძლებელია რამდენიმე პროექტის ხელშეკრულება სხვადასხვა საკომისიო პროცენტით.
          </div>
        </div>
      </div>
    </div>

    <div id="tab-registry" class="tab-panel" data-registry-version="2026-06-22">
    <div class="section-head">
      <h2>აგენტების რეესტრი</h2>
      <span class="badge" id="record-count">0 ჩანაწერი</span>
      <span class="badge" style="font-size:0.65rem;opacity:0.7" title="განახლების თარიღი">v22.06</span>
    </div>

    <div class="filter-panel">
      <div class="filter-panel-head">
        <span>ფილტრი</span>
      </div>
      <div class="filter-grid">
        <div class="filter-field">
          <label for="filter-agency">სააგენტო</label>
          <input type="text" id="filter-agency" placeholder="ძებნა…" />
        </div>
        <div class="filter-field">
          <label for="filter-idCode">ს/კ</label>
          <input type="text" id="filter-idCode" placeholder="ძებნა…" inputmode="numeric" />
        </div>
        <div class="filter-field">
          <label for="filter-project">პროექტი</label>
          <select id="filter-project">
            <option value="">ყველა</option>
          </select>
        </div>
        <div class="filter-field">
          <label for="filter-commission">საკომისიო</label>
          <input type="number" id="filter-commission" step="0.1" min="0" placeholder="მაგ: 3.5" />
        </div>
        <div class="filter-field">
          <label for="filter-active">აქტიური</label>
          <select id="filter-active">
            <option value="">ყველა</option>
            <option value="yes" selected>დიახ</option>
            <option value="no">არა</option>
          </select>
        </div>
      </div>
      <div class="filter-panel-footer">
        <button type="button" class="btn btn-ghost btn-sm filter-clear-btn" id="filter-clear">ფილტრის გასუფთავება</button>
      </div>
    </div>

    <div class="registry-toolbar">
      <div class="registry-toolbar-actions">
        <button type="button" class="btn btn-primary btn-sm" id="registry-export">ექსელში გატანა</button>
      </div>
    </div>

    <div class="table-wrap table-wrap-wide">
      <table class="registry-table">
        <thead>
          <tr>
            <th class="col-agency" title="სააგენტო">სააგენტო</th>
            <th class="col-project" title="პროექტი">პროექტი</th>
            <th class="col-commission num" title="საკომისიო">საკომ.</th>
            <th class="col-credit num" title="ჩასარიცხი თანხა (%)">ჩასარიცხი თანხა (%)</th>
            <th class="col-gen cell-center" title="ხელშეკრულების გენერაცია">ხელშ.გენერაცია</th>
            <th class="col-active cell-center" title="აქტიური">აქტ.</th>
            <th class="col-signed" title="ხელმოწერილი ხელშეკრულება">ხელმოწ.</th>
            <th class="col-actions"></th>
          </tr>
        </thead>
        <tbody id="agents-table"></tbody>
      </table>
    </div>
    </div>

  </div>

  <input type="file" id="signed-file-input" accept=".pdf,.doc,.docx,image/*" hidden />

  <div class="toast-wrap" id="toasts"></div>

  <div class="modal-overlay" id="delete-modal">
    <div class="modal">
      <h3>წაშლის დადასტურება</h3>
      <p id="delete-modal-text">დარწმუნებული ხართ?</p>
      <div class="modal-actions">
        <button class="btn btn-ghost" id="delete-cancel">გაუქმება</button>
        <button class="btn btn-danger" id="delete-confirm">წაშლა</button>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="add-project-modal">
    <div class="modal modal-wide">
      <h3>სხვა პროექტის დამატება</h3>
      <p>იგივე აგენტისთვის ახალი ხელშეკრულება სხვა პროექტზე</p>
      <dl class="agent-preview" id="add-project-preview"></dl>
      <div class="existing-projects" id="add-project-existing-wrap" style="display:none">
        <div class="lbl">უკვე მინიჭებული პროექტები:</div>
        <div class="project-tags" id="add-project-existing"></div>
      </div>
      <div class="copy-projects-panel" id="add-project-copy-wrap" style="display:none">
        <div class="lbl">ან პროექტების კოპირება სხვა აგენტიდან</div>
        <div class="copy-projects-row">
          <div class="field">
            <label for="ap-copy-from">წყარო აგენტი</label>
            <select id="ap-copy-from"></select>
          </div>
          <button type="button" class="btn btn-primary btn-sm" id="ap-copy-btn">კოპირება</button>
        </div>
      </div>
      <form id="add-project-form" novalidate>
        <div class="field">
          <label>დოკუმენტის ტიპი *</label>
          <div class="doc-type-options">
            <label class="doc-type-option">
              <input type="radio" name="apDocType" value="contract" checked />
              ხელშეკრულება
            </label>
            <label class="doc-type-option">
              <input type="radio" name="apDocType" value="one_time" />
              ერთჯერადი შეთანხმება
            </label>
          </div>
        </div>
        <div class="field">
          <label for="ap-project">პროექტი *</label>
          <select id="ap-project"><option value="">აირჩიეთ პროექტი…</option></select>
          <div class="field-error" id="err-ap-project">აირჩიეთ პროექტი</div>
        </div>
        <div class="field-row">
          <div class="field" id="ap-commission-pct-field">
            <label for="ap-commission">საკომისიო (%) *</label>
            <input id="ap-commission" type="number" step="0.1" min="0.1" max="100" placeholder="მაგ: 3.5" />
            <div class="field-error" id="err-ap-commission">შეიყვანეთ 0.1–100 შორის</div>
          </div>
          <div class="field" id="ap-commission-amount-field" hidden>
            <label for="ap-commissionAmount">საკომისიო ($) *</label>
            <input id="ap-commissionAmount" type="number" step="1" min="1" placeholder="მაგ: 5000" />
            <div class="field-error" id="err-ap-commissionAmount">შეიყვანეთ ფიქსირებული თანხა ($)</div>
          </div>
          <div class="field">
            <label for="ap-creditPct">ჩასარიცხი თანხა (%) *</label>
            <input id="ap-creditPct" type="number" step="0.1" min="0.1" max="100" placeholder="მაგ: 10" />
            <div class="field-error" id="err-ap-creditPct">შეიყვანეთ 0.1–100 შორის</div>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label for="ap-signedDate">გაფორმების თარიღი</label>
            <input id="ap-signedDate" type="date" />
            <div class="field-error" id="err-ap-signedDate">აირჩიეთ თარიღი</div>
          </div>
          <div class="field">
            <label for="ap-validUntil">მოქმედების ვადა</label>
            <input id="ap-validUntil" type="date" />
            <div class="field-error" id="err-ap-validUntil">აირჩიეთ ვადა</div>
          </div>
        </div>
        <div class="modal-actions" style="margin-top:16px">
          <button type="button" class="btn btn-ghost" id="add-project-cancel">გაუქმება</button>
          <button type="submit" class="btn btn-primary">დამატება</button>
        </div>
      </form>
    </div>
  </div>

  <div class="modal-overlay" id="project-agencies-modal">
    <div class="modal modal-agencies">
      <h3 id="project-agencies-title">ხელშეკრულებები</h3>
      <p id="project-agencies-sub">სააგენტოები ამ პროექტზე</p>
      <div class="agency-cards-scroll">
        <div id="project-agencies-list" class="agency-cards-list"></div>
      </div>
      <div class="modal-actions" style="margin-top:14px">
        <button type="button" class="btn btn-ghost" id="project-agencies-close">დახურვა</button>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="active-status-modal">
    <div class="modal">
      <h3 id="active-status-title">სტატუსის შეცვლა</h3>
      <p id="active-status-sub"></p>
      <div class="field">
        <label for="active-status-comment">კომენტარი *</label>
        <textarea id="active-status-comment" placeholder="მიუთითეთ მიზეზი…" rows="3"></textarea>
        <div class="field-error" id="err-active-status-comment">შეიყვანეთ კომენტარი</div>
      </div>
      <div class="modal-actions" style="margin-top:14px">
        <button type="button" class="btn btn-ghost" id="active-status-cancel">გაუქმება</button>
        <button type="button" class="btn btn-primary" id="active-status-confirm">დადასტურება</button>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="record-detail-modal">
    <div class="modal modal-agencies">
      <h3 id="record-detail-title">აგენტის ჩანაწერი</h3>
      <p id="record-detail-sub" style="margin-bottom:12px"></p>
      <div class="record-detail-grid" id="record-detail-body"></div>
      <div class="modal-actions" style="margin-top:14px">
        <button type="button" class="btn btn-ghost" id="record-detail-close">დახურვა</button>
        <button type="button" class="btn btn-primary" id="record-detail-edit">რედაქტირება</button>
      </div>
    </div>
  </div>

  <script>
    window.AGENTS_MODULE = <?= \Bitrix\Main\Web\Json::encode([
      'apiUrl'  => 'api.php?sessid=' . bitrix_sessid(),
      'isAdmin' => $USER->IsAdmin(),
    ]) ?>;
  </script>
  <script src="jszip.min.js"></script>
  <script>
    const PROJECTS_STORAGE_KEY = 'monolith-agents-projects-v1';

    const DEFAULT_PROJECT_NAMES = [
      'Monolith Green City',
      'Monolith Ethno City',
      'Monolith Dighomi City',
    ];

    const REMOVED_PROJECT_NAMES = new Set([
      'Monolith City',
      'Monolith Bagebi',
    ]);

    function buildDefaultProjects() {
      return DEFAULT_PROJECT_NAMES.map((name, i) => ({
        id: String(i + 1),
        name,
        idCode: '',
        director: '',
        active: true,
        activeHistory: [],
      }));
    }

    let projects = loadProjects();

    const STORAGE_KEY = 'monolith-agents-data-v1';
    const LEGACY_CLEANUP_KEY = 'monolith-agents-legacy-cleaned-v1';
    const BITRIX_MAP_STORAGE_KEY = 'monolith-agents-bitrix-field-map-v1';
    const SETTLED_STORAGE_KEY = 'monolith-agents-commission-settled-v1';
    const SETTLED_MIGRATED_KEY = 'monolith-agents-commission-settled-migrated-v1';
    const LEGACY_DEFAULT_IDS = new Set(['1', '2', '3', '4']);
    const CONTRACT_TEMPLATE_URL = 'templates/contract-template.docx';
    const COMPANY_LEGAL_NAME = 'შპს მონოლით ჯგუფი';

    /* ═══════════════════════════════════════════════════════════════
       MONOLITH — Bitrix კონფიგი (შეცვალე პორტალის მნიშვნელობებით)
       დეტალები: CONFIG-MONOLITH.md
       ═══════════════════════════════════════════════════════════════ */
    const BITRIX_CONFIG = {
      // REST მოთხოვნები სერვერის proxy-ით (api.php), webhook ბრაუზერში აღარ ჩანს
      apiUrl: window.AGENTS_MODULE.apiUrl,
      iblockTypeId: 'lists',
      // სააგენტოები — https://crm.monolith.ge/services/lists/24/
      iblockId: 24,
      fieldMap: {
        agency: 'NAME',                 // საბროკერო კომპანიის დასახელება
        idCode: 'PROPERTY_172',         // საიდენტიფიკაციო კოდი (_1)
        contactPerson: 'PROPERTY_173',  // საკონტაქტოს სახელი / გვარი (_2)
        phones: 'PROPERTY_174',         // მობ. ნომერი (MOBILE)
        email: 'PROPERTY_175',          // ელ.ფოსტა (_4)
        bank: 'PROPERTY_176',           // საბანკო რეკვიზიტები (_7)
        project: 'PROPERTY_186',        // პროექტი (_proj)
        commission: 'PROPERTY_179',     // საკომისიო (%) (_15)
        creditPct: 'PROPERTY_180',      // ჩასარიცხი თანხა (%) (_20)
        active: 'PROPERTY_181',         // აქტიური (_active)
        signedDate: 'PROPERTY_183',     // გაფორმების თარიღი
        validUntil: 'PROPERTY_184',     // მოქმედების ვადა (_13)
      },
      // აქტიური: 98=დიახ, 99=არა
      activeEnum: { yes: '98', no: '99' },
    };

    const BITRIX_CRM_CONFIG = {
      categoryId: 0,
      // რეპორტი იტვირთება მხოლოდ ამ ეტაპის deal-ებს: გაყიდული
      stageId: 'WON',
      // წყარო: სააგენტო/აგენტი
      sourceId: 'UC_HN9W32',
      // გადახდები — https://crm.monolith.ge/services/lists/23/
      paymentList: {
        iblockTypeId: 'lists',
        iblockId: 23,
        dealLink: 'PROPERTY_153', // DEAL — ხელშეკრულება (CRM bind)
        amount: 'PROPERTY_156',   // TANXA — თანხა
      },
      // აგენტზე გადახდილი საკომისიო — https://crm.monolith.ge/services/lists/25/
      agentCommissionList: {
        iblockTypeId: 'lists',
        iblockId: 25,
        dealLink: 'PROPERTY_189', // NEW_FIELD — CRM bind
        amount: 'PROPERTY_188',   // _1 — თანხა
        comment: 'PROPERTY_190',  // _12 — კომენტარი
      },
      // გადახდების ვალუტა (Money ველი: "1234|GEL")
      paymentCurrencies: ['USD', 'GEL'],
      dealFields: {
        project: 'UF_CRM_1779277729207',
        propertyType: 'UF_CRM_1779277898205',
        propertyNo: 'UF_CRM_1779277613798',
        propertyNoAlt: '', // ალტ. ბინის № — თუ არ გაქვს, ცარიელი დატოვე
        signedDate: 'UF_CRM_1779278774084', // ხელშეკრულების გაფორმების თარიღი
        paidPct: '', // არაა საჭირო თუ გადახდები სიიდან ითვლება (იბლოკი 23)
        agentLink: 'UF_CRM_1785491867', // ბმა სააგენტოების სიასთან (24)
        formAgency: 'UF_CRM_1790340288', // სააგენტო / აგენტი აგენტის ფორმიდან (addAgentLead.php)
        agencyName: '', // ოპციონალური — agentLink საკმარისია
        clientName: '', // ცარიელი = დილზე მიბმული Contact / Company სახელი
        // გადახდილი/დახურული — საერთო ყველა იუზერისთვის (ავტომატურად იქმნება თუ ცარიელია)
        commissionSettled: '',
      },
      select: [
        'ID', 'OPPORTUNITY', 'CONTACT_ID', 'COMPANY_ID', 'STAGE_ID', 'SOURCE_ID', 'COMMENTS',
        'UF_CRM_1779277729207',
        'UF_CRM_1779277898205',
        'UF_CRM_1779277613798',
        'UF_CRM_1779278774084',
        'UF_CRM_1785491867',
        'UF_CRM_1790340288',
      ],
    };

    const BITRIX_FIELD_LABELS = {
      agency: ['საბროკერო', 'სააგენტო', 'აგენტი', 'სააგენტოს სახელი'],
      idCode: ['ს/კ', 'საიდენტიფიკაციო კოდი', 'იდენტიფიკაციო კოდი'],
      contactPerson: ['საკონტაქტო', 'საკონტაქტოს', 'სახელი / გვარი'],
      phones: ['ტელეფონი', 'მობ. ტელეფონი', 'მობ. ნომერი', 'მობილური'],
      email: ['მეილი', 'ელფოსტა', 'ელ.ფოსტა', 'e-mail', 'email'],
      bank: ['რეკვიზიტები', 'საბანკო რეკვიზიტები', 'ბანკი', 'საბანკო'],
      project: ['პროექტი', 'კომენტარი'],
      commission: ['საკომისიო', 'საკომისიო %', 'საკომისიო (%)'],
      creditPct: ['ჩასარიცხი', 'ჩასარიცხი თანხა', 'ჩასარიცხი %', 'ჩასარიცხი თანხა (%)'],
      signedDate: ['გაფორმების თარიღი', 'ხელშეკრულების გაფორმების თარიღი'],
      validUntil: ['მოქმედების ვადა', 'ხელშეკრულების მოქმედების ვადა', 'ვადა'],
      active: ['აქტიური', 'სტატუსი'],
    };

    let bitrixReady = false;
    let bitrixFieldMap = {};
    let bitrixFieldMeta = {};
    let currentUserIsAdmin = false;

    const DEFAULT_DATA = [];

    let reportDeals = [];
    let reportSort = { key: 'signedDate', dir: 'desc' };
    let reportClientSuggestTimer = null;
    let reportClientSuggestSeq = 0;

    let contracts = [];
    let deleteTargetId = null;
    let editingId = null;
    let uploadTargetId = null;
    let addProjectSource = null;
    let suppressFormResetHandler = false;
    let editingProjectId = null;
    let activeTab = 'stats';
    let selectedDealId = null;
    let recordDetailId = null;
    let activeStatusTarget = null;

    const $ = (id) => document.getElementById(id);

    function switchTab(tab) {
      activeTab = tab;
      document.querySelectorAll('.tab-panel').forEach(el => el.classList.remove('active'));
      document.querySelectorAll('.nav-card').forEach(el => el.classList.remove('active'));
      const panel = document.getElementById('tab-' + tab);
      const btn = document.querySelector('.nav-card[data-tab="' + tab + '"]');
      if (panel) panel.classList.add('active');
      if (btn) btn.classList.add('active');
      if (tab === 'stats') renderReportTable();
      if (tab === 'projects') renderProjectsTable();
      if (tab === 'deal') renderDealDetail();
    }

    function initTabs() {
      document.querySelectorAll('.nav-card').forEach(btn => {
        btn.addEventListener('click', function () {
          switchTab(this.getAttribute('data-tab'));
        });
      });
      switchTab('stats');
    }

    function saveData() {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(contracts));
      } catch {
        memoryStore = [...contracts];
      }
    }

    function setAppLoading(loading) {
      const el = $('app-loading');
      if (el) el.classList.toggle('hidden', !loading);
    }

    function setBitrixStatus(state, message) {
      const el = $('bitrix-status');
      if (!el) return;
      el.className = 'bitrix-status ' + (state || '');
      const labels = { ok: 'Bitrix ✓', err: 'Bitrix ✗', load: 'Bitrix…' };
      el.textContent = labels[state] || 'Bitrix';
      el.title = message || 'Bitrix24 კავშირი';
    }

    function normalizeBitrixLabel(value) {
      return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function bitrixPropertyKey(field) {
      if (!field) return '';
      if (field.FIELD_ID) return field.FIELD_ID;
      if (field.ID) return `PROPERTY_${field.ID}`;
      return '';
    }

    function normalizeBitrixFieldsList(fields) {
      if (Array.isArray(fields)) return fields;
      if (!fields || typeof fields !== 'object') return [];
      return Object.values(fields);
    }

    function loadStoredBitrixFieldMap() {
      try {
        const raw = localStorage.getItem(BITRIX_MAP_STORAGE_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
      } catch {
        return null;
      }
    }

    function storeBitrixFieldMap(map) {
      try {
        localStorage.setItem(BITRIX_MAP_STORAGE_KEY, JSON.stringify(map));
      } catch { /* ignore */ }
    }

    function buildBitrixFieldMap(fields) {
      const map = {};
      const meta = {};
      const list = normalizeBitrixFieldsList(fields);
      Object.entries(BITRIX_FIELD_LABELS).forEach(([key, labels]) => {
        const field = list.find(f => {
          const name = normalizeBitrixLabel(f.NAME);
          return labels.some(label => name.includes(normalizeBitrixLabel(label)));
        });
        if (field) {
          const propKey = bitrixPropertyKey(field);
          map[key] = propKey;
          meta[key] = field;
        }
      });
      return { map, meta };
    }

    function buildBitrixFieldMetaFromMap(fields, map) {
      const meta = {};
      const list = normalizeBitrixFieldsList(fields);
      Object.entries(map).forEach(([key, propKey]) => {
        if (propKey === 'NAME') {
          meta[key] = { TYPE: 'NAME' };
          return;
        }
        const field = list.find(f => bitrixPropertyKey(f) === propKey);
        if (field) meta[key] = field;
      });
      return meta;
    }

    function appendBitrixFormValue(form, key, value) {
      if (value === undefined || value === null) return;
      if (Array.isArray(value)) {
        value.forEach(item => {
          if (item !== undefined && item !== null && item !== '') form.append(`${key}[]`, item);
        });
        return;
      }
      if (typeof value === 'object') {
        Object.entries(value).forEach(([childKey, childValue]) => {
          appendBitrixFormValue(form, `${key}[${childKey}]`, childValue);
        });
        return;
      }
      form.append(key, value);
    }

    async function bitrixCall(method, params = {}) {
      if (location.protocol === 'file:') {
        throw new Error('გახსენით start.bat → http://localhost:8765 (file://-დან Bitrix API ვერ მუშაობს)');
      }
      let response;
      try {
        response = await fetch(BITRIX_CONFIG.apiUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ method, params }),
        });
      } catch (error) {
        throw new Error(error.message === 'Failed to fetch'
          ? 'ქსელის შეცდომა — გახსენით start.bat → http://localhost:8765'
          : error.message);
      }
      const text = await response.text();
      let json;
      try {
        json = JSON.parse(text);
      } catch {
        throw new Error(`Bitrix API პასუხი არასწორია (${response.status})`);
      }
      if (json.error) {
        throw new Error(json.error_description || json.error);
      }
      return json.result;
    }

    function isLocalDevHost() {
      return location.protocol === 'file:'
        || location.hostname === 'localhost'
        || location.hostname === '127.0.0.1';
    }

    function isCurrentUserAdmin() {
      return currentUserIsAdmin === true;
    }

    async function detectCurrentUserIsAdmin() {
      if (isLocalDevHost()) return true;
      return window.AGENTS_MODULE.isAdmin === true;
    }

    async function bitrixInit() {
      setBitrixStatus('load', 'Bitrix24-თან დაკავშირება…');
      const fields = await bitrixCall('lists.field.get', {
        IBLOCK_TYPE_ID: BITRIX_CONFIG.iblockTypeId,
        IBLOCK_ID: BITRIX_CONFIG.iblockId,
      });
      if (BITRIX_CONFIG.fieldMap) {
        bitrixFieldMap = { ...BITRIX_CONFIG.fieldMap };
        bitrixFieldMeta = buildBitrixFieldMetaFromMap(fields, bitrixFieldMap);
      } else {
        const stored = loadStoredBitrixFieldMap();
        const built = buildBitrixFieldMap(fields);
        bitrixFieldMap = stored ? { ...built.map, ...stored } : built.map;
        bitrixFieldMeta = built.meta;
        storeBitrixFieldMap(bitrixFieldMap);
      }
      bitrixReady = true;
      await ensureCommissionSettledField();
      const mapped = Object.keys(bitrixFieldMap).length;
      setBitrixStatus('ok', `Bitrix24 დაკავშირებულია · ${mapped} ველი`);
    }

    function formatBitrixDate(iso) {
      if (!iso) return '';
      const [y, m, d] = String(iso).slice(0, 10).split('-');
      if (!y || !m || !d) return String(iso).trim();
      return `${d.padStart(2, '0')}/${m.padStart(2, '0')}/${y}`;
    }

    function parseBitrixDate(value) {
      if (!value) return '';
      const raw = String(value).trim();
      if (/^\d{4}-\d{2}-\d{2}/.test(raw)) return raw.slice(0, 10);
      const match = raw.match(/^(\d{1,2})[./](\d{1,2})[./](\d{4})/);
      if (match) {
        const day = match[1].padStart(2, '0');
        const month = match[2].padStart(2, '0');
        return `${match[3]}-${month}-${day}`;
      }
      return '';
    }

    function formatBitrixFieldValue(key, value) {
      if (value === undefined || value === null || value === '') return '';
      if (key === 'phones') {
        const phones = Array.isArray(value) ? value.filter(Boolean) : [value];
        const first = phones.find(Boolean);
        if (!first) return '';
        return String(first).replace(/\D/g, '');
      }
      if (key === 'active') {
        return value === false
          ? (BITRIX_CONFIG.activeEnum?.no || '278')
          : (BITRIX_CONFIG.activeEnum?.yes || '277');
      }
      if (key === 'commission' || key === 'creditPct') return Number(value);
      if (key === 'signedDate' || key === 'validUntil') {
        return formatBitrixDate(String(value).slice(0, 10));
      }
      return String(value);
    }

    function contractToBitrixFields(contract) {
      const fields = {};
      if (contract.agency) fields.NAME = contract.agency;
      const payload = {
        idCode: contract.idCode,
        contactPerson: contract.contactPerson,
        phones: contract.phones,
        email: contract.email,
        bank: contract.bank,
        project: contract.project,
        commission: contract.commission,
        creditPct: contract.creditPct,
        signedDate: contract.signedDate,
        validUntil: contract.validUntil,
        active: contract.active,
      };
      Object.entries(payload).forEach(([key, value]) => {
        const propKey = bitrixFieldMap[key];
        if (!propKey || propKey === 'NAME') return;
        const formatted = formatBitrixFieldValue(key, value);
        if (formatted !== '' && formatted !== null && formatted !== undefined) {
          fields[propKey] = formatted;
        }
      });
      if (contract.signedDate && bitrixFieldMap.signedDate) {
        fields[bitrixFieldMap.signedDate] = formatBitrixDate(contract.signedDate);
      }
      if (contract.validUntil && bitrixFieldMap.validUntil) {
        fields[bitrixFieldMap.validUntil] = formatBitrixDate(contract.validUntil);
      }
      return fields;
    }

    function unwrapBitrixValue(value) {
      if (value === undefined || value === null) return value;
      if (Array.isArray(value)) {
        if (!value.length) return '';
        return value.map(item => unwrapBitrixValue(item)).filter(v => v !== '' && v != null);
      }
      if (typeof value === 'object') {
        if ('VALUE' in value) return value.VALUE;
        const vals = Object.values(value).map(item => unwrapBitrixValue(item)).filter(v => v !== '' && v != null);
        if (!vals.length) return '';
        return vals.length === 1 ? vals[0] : vals;
      }
      return value;
    }

    function getBitrixPropertyValue(element, propKey) {
      if (!propKey || !element) return undefined;
      if (propKey === 'NAME') return element.NAME;
      let value = element[propKey];
      if (value === undefined) value = element[`${propKey}_VALUE`];
      return unwrapBitrixValue(value);
    }

    function parseBitrixActive(value) {
      if (value === undefined || value === null || value === '') return true;
      const raw = String(value).toLowerCase();
      if (raw === String(BITRIX_CONFIG.activeEnum?.no || '278')) return false;
      if (raw === String(BITRIX_CONFIG.activeEnum?.yes || '277')) return true;
      if (['n', 'no', 'false', '0', 'არა', 'არააქტიური', '278'].includes(raw)) return false;
      if (['y', 'yes', 'true', '1', 'დიახ', 'აქტიური', '277'].includes(raw)) return true;
      return true;
    }

    function bitrixElementToContract(element) {
      const read = key => getBitrixPropertyValue(element, bitrixFieldMap[key]);
      const phonesRaw = read('phones');
      const phones = Array.isArray(phonesRaw)
        ? phonesRaw.map(String)
        : String(phonesRaw || '').split(/[,;]/).map(s => s.trim()).filter(Boolean);
      const commission = Number(read('commission'));
      const creditPct = Number(read('creditPct'));
      const signedDate = parseBitrixDate(read('signedDate'));
      const validUntil = parseBitrixDate(read('validUntil'));
      const agency = String(element.NAME || read('agency') || '').trim();
      let project = String(read('project') || '').trim();
      if (!project) {
        const legacyProject = null; // White Square legacy PROPERTY_609 — მონოლითში არ გამოიყენება
        project = String(legacyProject || '').trim();
      }
      const commissionNum = Number.isFinite(commission) ? commission : '';
      const docType = Number.isFinite(commissionNum) && commissionNum > 100 ? 'one_time' : 'contract';
      return {
        id: String(element.ID),
        bitrixId: String(element.ID),
        agency,
        idCode: String(read('idCode') || '').trim(),
        contactPerson: String(read('contactPerson') || '').trim(),
        phones,
        email: String(read('email') || '').trim(),
        bank: String(read('bank') || '').trim(),
        project,
        commission: commissionNum,
        creditPct: Number.isFinite(creditPct) ? creditPct : '',
        signedDate,
        validUntil,
        active: parseBitrixActive(read('active')),
        docType,
        contractGeneratedAt: '',
        signedContracts: [],
        activeHistory: [],
      };
    }

    async function bitrixLoadContracts() {
      const items = [];
      let start = 0;
      while (true) {
        const batch = await bitrixCall('lists.element.get', {
          IBLOCK_TYPE_ID: BITRIX_CONFIG.iblockTypeId,
          IBLOCK_ID: BITRIX_CONFIG.iblockId,
          start,
        });
        if (!Array.isArray(batch) || !batch.length) break;
        items.push(...batch);
        if (batch.length < 50) break;
        start += batch.length;
      }
      return items.map(bitrixElementToContract);
    }

    async function bitrixAddContract(contract) {
      const elementId = await bitrixCall('lists.element.add', {
        IBLOCK_TYPE_ID: BITRIX_CONFIG.iblockTypeId,
        IBLOCK_ID: BITRIX_CONFIG.iblockId,
        ELEMENT_CODE: `agent_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`,
        FIELDS: contractToBitrixFields(contract),
      });
      return String(elementId);
    }

    async function bitrixUpdateContract(contract) {
      if (!contract.bitrixId) throw new Error('Bitrix ჩანაწერის ID არ არის');
      await bitrixCall('lists.element.update', {
        IBLOCK_TYPE_ID: BITRIX_CONFIG.iblockTypeId,
        IBLOCK_ID: BITRIX_CONFIG.iblockId,
        ELEMENT_ID: contract.bitrixId,
        FIELDS: contractToBitrixFields(contract),
      });
    }

    async function bitrixDeleteContract(bitrixId) {
      if (!bitrixId) return;
      await bitrixCall('lists.element.delete', {
        IBLOCK_TYPE_ID: BITRIX_CONFIG.iblockTypeId,
        IBLOCK_ID: BITRIX_CONFIG.iblockId,
        ELEMENT_ID: bitrixId,
      });
    }

    function normalizeStoredContract(c) {
      const signedContracts = migrateSignedContracts(c);
      const commission = c.commission != null && c.commission !== '' ? Number(c.commission) : '';
      let docType = c.docType === 'one_time' || c.docType === 'contract' ? c.docType : '';
      if (!docType && Number.isFinite(commission) && commission > 100) docType = 'one_time';
      if (!docType) docType = 'contract';
      return {
        ...c,
        signedDate: c.signedDate || c.date || '',
        validUntil: c.validUntil || '',
        active: c.active !== false,
        contractGeneratedAt: c.contractGeneratedAt || '',
        signedContracts,
        activeHistory: Array.isArray(c.activeHistory) ? c.activeHistory : [],
        creditPct: c.creditPct != null && c.creditPct !== '' ? Number(c.creditPct) : '',
        phones: Array.isArray(c.phones) ? c.phones : (c.phone ? [c.phone] : []),
        email: c.email || '',
        contactPerson: c.contactPerson || '',
        commission,
        docType,
      };
    }

    function isLegacyLocalContract(c) {
      if (!c || !c.agency) return true;
      if (c.bitrixId) return false;
      if (LEGACY_DEFAULT_IDS.has(String(c.id))) return true;
      return true;
    }

    function loadLocalOverlayContracts() {
      try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return [];
        return parsed
          .filter(c => !isLegacyLocalContract(c))
          .map(normalizeStoredContract);
      } catch {
        return [];
      }
    }

    function purgeLegacyLocalContracts() {
      try {
        if (localStorage.getItem(LEGACY_CLEANUP_KEY)) return false;
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) {
          localStorage.setItem(LEGACY_CLEANUP_KEY, '1');
          return false;
        }
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
          localStorage.removeItem(STORAGE_KEY);
          localStorage.setItem(LEGACY_CLEANUP_KEY, '1');
          return true;
        }
        const kept = parsed.filter(c => !isLegacyLocalContract(c));
        const removed = parsed.length - kept.length;
        if (kept.length) {
          localStorage.setItem(STORAGE_KEY, JSON.stringify(kept));
        } else {
          localStorage.removeItem(STORAGE_KEY);
        }
        localStorage.setItem(LEGACY_CLEANUP_KEY, '1');
        return removed > 0;
      } catch {
        localStorage.setItem(LEGACY_CLEANUP_KEY, '1');
        return false;
      }
    }

    function mergeLocalContractOverlay(remoteContracts) {
      const local = loadLocalOverlayContracts();
      const overlay = new Map(
        local.map(c => [String(c.bitrixId || c.id), c])
      );
      const merged = remoteContracts.map(contract => {
        const localItem = overlay.get(String(contract.bitrixId || contract.id));
        if (!localItem) return contract;
        return {
          ...contract,
          signedContracts: Array.isArray(localItem.signedContracts) ? localItem.signedContracts : [],
          activeHistory: Array.isArray(localItem.activeHistory) ? localItem.activeHistory : contract.activeHistory,
          contractGeneratedAt: localItem.contractGeneratedAt || contract.contractGeneratedAt,
          docType: localItem.docType || contract.docType || 'contract',
        };
      });
      // API რეჟიმში ვტოვებთ მხოლოდ Bitrix-დან მოსულ ჩანაწერებს;
      // ლოკალური მონაცემებიდან ვინარჩუნებთ მხოლოდ UI-overlay ველებს.
      return merged;
    }

    async function syncContractToBitrix(contract, isNew) {
      if (!bitrixReady) await bitrixInit();
      if (isNew) {
        const bitrixId = await bitrixAddContract(contract);
        contract.bitrixId = bitrixId;
        contract.id = bitrixId;
        return bitrixId;
      }
      await bitrixUpdateContract(contract);
      return contract.bitrixId;
    }

    async function initApp() {
      setAppLoading(true);
      initReportDateFilter();
      const adminPromise = detectCurrentUserIsAdmin();
      const runtimeWarning = $('runtime-warning');
      if (location.protocol === 'file:') {
        if (runtimeWarning) runtimeWarning.classList.add('show');
        contracts = loadData();
        setBitrixStatus('err', 'გახსენით start.bat → http://localhost:8765');
        currentUserIsAdmin = await adminPromise;
        refresh();
        initTabs();
        setAppLoading(false);
        return;
      }
      if (runtimeWarning) runtimeWarning.classList.remove('show');
      purgeLegacyLocalContracts();
      try {
        await bitrixInit();
        const remoteContracts = await bitrixLoadContracts();
        contracts = remoteContracts.length
          ? mergeLocalContractOverlay(remoteContracts)
          : [];
        saveData();
        reportDeals = await bitrixLoadReportDeals();
        populateReportProjectFilter();
      } catch (error) {
        console.warn('Bitrix init/load failed:', error);
        contracts = loadLocalOverlayContracts();
        reportDeals = [];
        setBitrixStatus('err', error.message || 'Bitrix24 კავშირი ვერ დამყარდა');
      }
      currentUserIsAdmin = await adminPromise;
      refresh();
      initTabs();
      setAppLoading(false);
    }

    function loadProjects() {
      try {
        const raw = localStorage.getItem(PROJECTS_STORAGE_KEY);
        if (!raw) return buildDefaultProjects();
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed) || !parsed.length) return buildDefaultProjects();
        const list = parsed.map(p => ({
          id: p.id || uid(),
          name: (p.name || '').trim(),
          idCode: (p.idCode || '').trim(),
          director: (p.director || '').trim(),
          active: p.active === false || p.active === 'false' ? false : true,
          activeHistory: Array.isArray(p.activeHistory) ? p.activeHistory : [],
        })).filter(p => p.name && !REMOVED_PROJECT_NAMES.has(p.name));
        if (list.length !== parsed.length) {
          try { localStorage.setItem(PROJECTS_STORAGE_KEY, JSON.stringify(list)); } catch { /* ignore */ }
        }
        return list.length ? list : buildDefaultProjects();
      } catch {
        return buildDefaultProjects();
      }
    }

    function saveProjects() {
      try {
        localStorage.setItem(PROJECTS_STORAGE_KEY, JSON.stringify(projects));
      } catch { /* ignore */ }
    }

    function getProjectNames() {
      return projects.map(p => p.name);
    }

    function isProjectActive(p) {
      return p.active !== false && p.active !== 'false';
    }

    function getActiveProjectNames() {
      return projects.filter(isProjectActive).map(p => p.name);
    }

    function canGenerateContract(c) {
      if (!c || c.active === false) return false;
      const project = projects.find(p => p.name === c.project);
      if (project && !isProjectActive(project)) return false;
      return true;
    }

    function renderGenerateCell(c) {
      const dateMeta = c.contractGeneratedAt
        ? `<span class="gen-meta">${formatDate(c.contractGeneratedAt.slice(0, 10))}</span>`
        : '';
      if (!canGenerateContract(c)) return dateMeta || '<span class="gen-meta">—</span>';
      return `<button type="button" class="btn btn-sm btn-ghost" data-generate="${c.id}" title="ხელშეკრულების გენერაცია">გენ.</button>${dateMeta}`;
    }

    let memoryStore = null;

    function loadData() {
      try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [...DEFAULT_DATA];
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return [...DEFAULT_DATA];
        return parsed.map(normalizeStoredContract);
      } catch {
        if (Array.isArray(memoryStore)) return [...memoryStore];
        return [...DEFAULT_DATA];
      }
    }

    function getBitrixPortalUrl() {
      return location.origin;
    }

    function getDealBitrixUrl(leadId) {
      const id = String(leadId || '').trim();
      if (!id) return '#';
      return `${getBitrixPortalUrl()}/crm/deal/details/${encodeURIComponent(id)}/`;
    }

    function formatDate(iso) {
      if (!iso) return '—';
      const [y, m, d] = iso.split('-');
      return `${d}/${m}/${y}`;
    }

    function parseLocalDate(iso) {
      if (!iso) return null;
      const [y, m, d] = String(iso).trim().slice(0, 10).split('-').map(Number);
      if (!y || !m || !d) return null;
      return new Date(y, m - 1, d);
    }

    function isContractExpiringWithinMonth(c) {
      const until = parseLocalDate(c?.validUntil);
      if (!until) return false;
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      if (until < today) return true;
      const limit = new Date(today);
      limit.setMonth(limit.getMonth() + 1);
      return until <= limit;
    }

    function escapeXmlText(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&apos;');
    }

    function parseBankAccount(bank) {
      const text = String(bank || '').trim();
      if (!text) return '—';
      const match = text.match(/GE\d{2}[A-Z0-9]{16,}/i);
      return match ? match[0] : text;
    }

    function sanitizeFilePart(value) {
      return String(value || 'unknown')
        .replace(/[\\/:*?"<>|]/g, '-')
        .replace(/\s+/g, '_')
        .slice(0, 60);
    }

    function buildContractReplacements(c) {
      const project = projects.find(p => p.name === c.project) || {};
      const phone = (c.phones || []).filter(Boolean)[0] || '—';
      const commission = c.commission != null && c.commission !== '' ? String(c.commission) : '—';
      const creditPct = c.creditPct != null && c.creditPct !== '' ? String(c.creditPct) : commission;
      const companyName = COMPANY_LEGAL_NAME;
      const brokerName = c.agency || '—';
      const director = project.director || '—';
      const commissionText = isOneTimeDoc(c)
        ? (commission === '—' ? '—' : `${commission} $`)
        : (commission === '—' ? '—' : `${commission}%`);

      return {
        '[[CONTRACT_DATE]]': escapeXmlText(formatDate(c.signedDate)),
        '[[COMPANY_NAME]]': escapeXmlText(companyName),
        '[[COMPANY_ID]]': escapeXmlText(project.idCode || '—'),
        '[[COMPANY_DIRECTOR]]': escapeXmlText(director),
        '[[BROKER_NAME]]': escapeXmlText(brokerName),
        '[[BROKER_ID]]': escapeXmlText(c.idCode || '—'),
        '[[VALID_UNTIL]]': escapeXmlText(formatDate(c.validUntil)),
        '[[COMPANY_NAME_FOOTER]]': escapeXmlText(companyName),
        '[[BROKER_NAME_FOOTER]]': escapeXmlText(brokerName),
        '[[BROKER_ACCOUNT]]': escapeXmlText(parseBankAccount(c.bank)),
        '[[BROKER_REPRESENTATIVE]]': escapeXmlText(c.contactPerson || brokerName),
        '[[PROJECT_NAME]]': escapeXmlText(c.project || '—'),
        '[[COMMISSION_PCT]]': escapeXmlText(commission),
        '[[COMMISSION_TEXT]]': escapeXmlText(commissionText),
        '[[CREDIT_PCT]]': escapeXmlText(creditPct),
        '[[BROKER_PHONE]]': escapeXmlText(phone),
        '[[BROKER_ADDRESS]]': escapeXmlText('—'),
      };
    }

    function fillContractTemplate(xml, replacements) {
      let out = xml;
      Object.entries(replacements).forEach(([token, value]) => {
        out = out.split(token).join(value);
      });
      return out;
    }

    async function downloadContractDocx(c) {
      if (location.protocol === 'file:') {
        throw new Error('გახსენით start.bat → http://localhost:8765 (file://-დან შაბლონი ვერ ჩაიტვირთება)');
      }
      if (typeof JSZip === 'undefined') {
        throw new Error('JSZip ბიბლიოთეკა ვერ ჩაიტვირთა — გადატვირთეთ გვერდი ან გაუშვით npm install');
      }
      let response;
      try {
        response = await fetch(CONTRACT_TEMPLATE_URL);
      } catch (error) {
        throw new Error(error.message === 'Failed to fetch'
          ? 'შაბლონი ვერ ჩაიტვირთა — გაუშვით start.bat და გადადით http://localhost:8765-ზე'
          : (error.message || 'შაბლონის ჩატვირთვა ვერ მოხერხდა'));
      }
      if (!response.ok) {
        throw new Error(response.status === 404
          ? 'შაბლონი არ მოიძებნა (templates/contract-template.docx) — გაუშვით: node create-contract-template.js'
          : `ხელშეკრულების შაბლონი ვერ ჩაიტვირთა (${response.status})`);
      }
      const zip = await JSZip.loadAsync(await response.arrayBuffer());
      const docPath = 'word/document.xml';
      if (!zip.file(docPath)) throw new Error('შაბლონში document.xml ვერ მოიძებნა');

      let xml = await zip.file(docPath).async('string');
      const replacements = buildContractReplacements(c);
      xml = fillContractTemplate(xml, replacements);
      if (isOneTimeDoc(c) && c.commission != null && c.commission !== '') {
        const num = escapeXmlText(String(c.commission));
        xml = xml.split(`${num}%`).join(`${num} $`);
      }
      zip.file(docPath, xml);

      const blob = await zip.generateAsync({
        type: 'blob',
        mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        compression: 'DEFLATE',
      });

      const fileName = `საბროკერო_ხელშეკრულება_${sanitizeFilePart(c.agency)}_${sanitizeFilePart(c.project)}.docx`;
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = fileName;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 0);
    }

    function toInputDate(date) {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    }

    function getCurrentMonthRange() {
      const now = new Date();
      const from = new Date(now.getFullYear(), now.getMonth(), 1);
      const to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
      return { from: toInputDate(from), to: toInputDate(to) };
    }

    function isoToDmy(iso) {
      if (!iso) return '';
      const [y, m, d] = String(iso).slice(0, 10).split('-');
      if (!y || !m || !d) return '';
      return `${d}/${m}/${y}`;
    }

    function dmyToIso(value) {
      const match = String(value || '').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
      if (!match) return '';
      const day = Number(match[1]);
      const month = Number(match[2]);
      const year = Number(match[3]);
      const dt = new Date(year, month - 1, day);
      if (dt.getFullYear() !== year || dt.getMonth() !== month - 1 || dt.getDate() !== day) return '';
      return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }

    function maskDmyInput(raw) {
      const digits = String(raw || '').replace(/\D/g, '').slice(0, 8);
      if (digits.length <= 2) return digits;
      if (digits.length <= 4) return `${digits.slice(0, 2)}/${digits.slice(2)}`;
      return `${digits.slice(0, 2)}/${digits.slice(2, 4)}/${digits.slice(4)}`;
    }

    function bindDmyDateInput(dateId) {
      const input = $(dateId);
      const display = $(`${dateId}-display`);
      if (!input || !display || display.dataset.dmyBound) return;
      display.dataset.dmyBound = '1';
      const syncDisplay = () => {
        display.value = isoToDmy(input.value);
      };
      const applyDisplay = () => {
        const typed = display.value.trim();
        if (!typed) {
          if (input.value) {
            input.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
          }
          return;
        }
        const iso = dmyToIso(typed);
        if (!iso) {
          syncDisplay();
          return;
        }
        if (input.value !== iso) {
          input.value = iso;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
          syncDisplay();
        }
      };
      input.addEventListener('input', syncDisplay);
      input.addEventListener('change', syncDisplay);
      display.addEventListener('input', () => {
        display.value = maskDmyInput(display.value);
      });
      display.addEventListener('blur', applyDisplay);
      display.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          e.preventDefault();
          applyDisplay();
        }
      });
      syncDisplay();
    }

    function initReportDateFilter() {
      const fromEl = $('report-date-from');
      const toEl = $('report-date-to');
      if (!fromEl || !toEl) return;
      const { from, to } = getCurrentMonthRange();
      fromEl.value = from;
      toEl.value = to;
      bindDmyDateInput('report-date-from');
      bindDmyDateInput('report-date-to');
    }

    function formatDateTime(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      if (isNaN(d.getTime())) return formatDate(String(iso).slice(0, 10));
      const pad = n => String(n).padStart(2, '0');
      return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    function uid() {
      return Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
    }

    function findContractById(id) {
      const key = String(id);
      return contracts.find(c => String(c.id) === key);
    }

    function toast(msg, type = 'success') {
      const el = document.createElement('div');
      el.className = `toast ${type}`;
      el.textContent = msg;
      $('toasts').appendChild(el);
      setTimeout(() => el.remove(), 3200);
    }

    function isValidIdCode(v) {
      return /^\d{9}$|^\d{11}$/.test(v.trim());
    }

    function isValidIdCode(v) {
      return /^\d{9}$|^\d{11}$/.test(v.trim());
    }

    function isValidEmail(v) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim());
    }

    function getPhonesFromForm() {
      return [...document.querySelectorAll('#phones-list .phone-input')]
        .map(inp => inp.value.trim())
        .filter(Boolean);
    }

    function updatePhoneRemoveButtons() {
      const rows = $('phones-list').querySelectorAll('.phone-row');
      rows.forEach(row => {
        const btn = row.querySelector('.phone-remove');
        if (btn) btn.disabled = rows.length <= 1;
      });
    }

    function bindPhoneRowEvents() {
      $('phones-list').querySelectorAll('.phone-remove').forEach(btn => {
        btn.onclick = () => {
          btn.closest('.phone-row')?.remove();
          if (!$('phones-list').querySelectorAll('.phone-row').length) renderPhoneFields(['']);
          updatePhoneRemoveButtons();
        };
      });
      updatePhoneRemoveButtons();
    }

    function renderPhoneFields(phones = ['']) {
      const values = phones.length ? phones : [''];
      $('phones-list').innerHTML = values.map(p => `
        <div class="phone-row">
          <input type="tel" class="phone-input" placeholder="5XX XX XX XX" value="${esc(p)}" inputmode="tel" />
          <button type="button" class="btn btn-ghost btn-sm phone-remove" title="წაშლა">×</button>
        </div>
      `).join('');
      bindPhoneRowEvents();
    }

    function populateProjectSelects() {
      const allOpts = getProjectNames().map(p => `<option value="${esc(p)}">${esc(p)}</option>`).join('');
      const activeOpts = getActiveProjectNames().map(p => `<option value="${esc(p)}">${esc(p)}</option>`).join('');
      $('project').innerHTML = `<option value="">აირჩიეთ პროექტი…</option>${activeOpts}`;
      $('filter-project').innerHTML = `<option value="">ყველა</option>${allOpts}`;
      populateReportProjectFilter();
    }

    function populateReportProjectFilter() {
      const el = $('report-filter-project');
      if (!el) return;
      const current = el.value;
      const fromDeals = [...new Set(reportDeals.map(d => d.project).filter(Boolean))];
      const fromProjects = getProjectNames();
      const projects = [...new Set([...fromDeals, ...fromProjects])].sort((a, b) => a.localeCompare(b, 'ka'));
      el.innerHTML = `<option value="">ყველა</option>${projects.map(p => `<option value="${esc(p)}">${esc(p)}</option>`).join('')}`;
      if (current && projects.includes(current)) el.value = current;
    }

    function unwrapCrmValue(value) {
      if (value == null || value === '') return '';
      if (Array.isArray(value)) return value.length ? unwrapCrmValue(value[0]) : '';
      if (typeof value === 'object' && 'VALUE' in value) return value.VALUE;
      return value;
    }

    function parseCrmDate(value) {
      if (!value) return '';
      const raw = String(value).trim();
      if (/^\d{4}-\d{2}-\d{2}/.test(raw)) return raw.slice(0, 10);
      const match = raw.match(/^(\d{1,2})[./](\d{1,2})[./](\d{4})/);
      if (match) {
        const day = match[1].padStart(2, '0');
        const month = match[2].padStart(2, '0');
        return `${match[3]}-${month}-${day}`;
      }
      return '';
    }

    function buildAgentLookup() {
      const map = new Map();
      contracts.forEach(c => {
        if (c.bitrixId) map.set(String(c.bitrixId), c);
      });
      return map;
    }

    function normalizeAgencyName(value) {
      return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function agencyNamesMatch(a, b) {
      const left = normalizeAgencyName(a);
      const right = normalizeAgencyName(b);
      if (!left || !right) return false;
      return left === right || left.includes(right) || right.includes(left);
    }

    function getContractsForProject(project, { activeOnly = true } = {}) {
      const key = String(project || '').trim();
      if (!key) return [];
      const active = contracts.filter(c => c.project === key && c.active !== false);
      if (active.length || activeOnly) return active;
      return contracts.filter(c => c.project === key);
    }

    function resolveAgentContract(deal, agentLookup) {
      const F = BITRIX_CRM_CONFIG.dealFields;
      const agentId = String(unwrapCrmValue(deal[F.agentLink]) || '').trim();
      if (agentId && agentLookup.has(agentId)) return agentLookup.get(agentId);

      // ბროკერის ველი ცარიელია — პროექტით ავტომატურად არ ვარჩევთ
      if (!agentId) return null;

      const project = String(deal[F.project] || '').trim();
      if (!project) return null;

      const matches = getContractsForProject(project);
      if (!matches.length) return null;
      if (matches.length === 1) return matches[0];

      const agencyHint = String(deal[F.agencyName] || '').trim();
      if (agencyHint) {
        const byName = matches.find(c => agencyNamesMatch(c.agency, agencyHint));
        if (byName) return byName;
      }

      return matches.slice().sort((a, b) => String(a.bitrixId || a.id).localeCompare(String(b.bitrixId || b.id), 'en', { numeric: true }))[0];
    }

    function readAgentPct(value) {
      if (value === '' || value == null) return '';
      const num = Number(value);
      return Number.isFinite(num) ? num : '';
    }

    function isOneTimeDoc(c) {
      if (!c) return false;
      if (c.docType === 'one_time' || c.commissionType === 'fixed') return true;
      if (c.docType === 'contract' || c.commissionType === 'percent') return false;
      const n = Number(c.commission);
      return Number.isFinite(n) && n > 100;
    }

    function formatCommissionLabel(cOrValue, docType) {
      const value = (cOrValue && typeof cOrValue === 'object')
        ? cOrValue.commission
        : cOrValue;
      if (value === '' || value == null) return '—';
      const oneTime = (cOrValue && typeof cOrValue === 'object')
        ? isOneTimeDoc(cOrValue)
        : docType === 'one_time' || docType === 'fixed';
      return oneTime ? formatUsd(value) : `${value}%`;
    }

    const SETTLED_FIELD_CODE = 'AGT_SETTLED';
    const SETTLED_FIELD_LABEL = 'აგენტის საკომისიო დახურული';
    let settledDealsCache = null;

    function ensureSelectIncludes(field) {
      if (!field) return;
      if (!BITRIX_CRM_CONFIG.select.includes(field)) {
        BITRIX_CRM_CONFIG.select.push(field);
      }
    }

    function collectUserfieldLabels(field) {
      return [field?.EDIT_FORM_LABEL, field?.LIST_COLUMN_LABEL, field?.LIST_FILTER_LABEL]
        .flatMap(label => {
          if (!label) return [];
          if (typeof label === 'string') return [label];
          return Object.values(label);
        })
        .map(v => String(v || '').trim().toLowerCase())
        .filter(Boolean);
    }

    async function ensureCommissionSettledField() {
      const configured = String(BITRIX_CRM_CONFIG.dealFields.commissionSettled || '').trim();
      if (configured) {
        ensureSelectIncludes(configured);
        return configured;
      }
      try {
        const list = await bitrixCall('crm.deal.userfield.list', {
          order: { ID: 'ASC' },
          filter: {},
        });
        const fields = Array.isArray(list) ? list : [];
        const found = fields.find(f => {
          const name = String(f.FIELD_NAME || '').toUpperCase();
          if (name === `UF_CRM_${SETTLED_FIELD_CODE}` || name.endsWith(`_${SETTLED_FIELD_CODE}`)) return true;
          if (String(f.XML_ID || '').toUpperCase() === 'MONOLITH_AGT_SETTLED') return true;
          const labels = collectUserfieldLabels(f);
          return labels.some(l =>
            l.includes('აგენტის საკომისიო დახურული')
            || l.includes('commission settled')
            || l.includes('agt_settled')
          );
        });
        if (found?.FIELD_NAME) {
          const fieldName = String(found.FIELD_NAME).startsWith('UF_')
            ? String(found.FIELD_NAME)
            : `UF_CRM_${found.FIELD_NAME}`;
          BITRIX_CRM_CONFIG.dealFields.commissionSettled = fieldName;
          ensureSelectIncludes(fieldName);
          return fieldName;
        }
        await bitrixCall('crm.deal.userfield.add', {
          fields: {
            FIELD_NAME: SETTLED_FIELD_CODE,
            USER_TYPE_ID: 'boolean',
            XML_ID: 'MONOLITH_AGT_SETTLED',
            EDIT_FORM_LABEL: { ka: SETTLED_FIELD_LABEL, en: 'Agent commission settled' },
            LIST_COLUMN_LABEL: { ka: SETTLED_FIELD_LABEL, en: 'Agent commission settled' },
            LIST_FILTER_LABEL: { ka: SETTLED_FIELD_LABEL, en: 'Agent commission settled' },
            SHOW_FILTER: 'Y',
            SHOW_IN_LIST: 'N',
            SETTINGS: { DEFAULT_VALUE: 0, DISPLAY: 'CHECKBOX' },
          },
        });
        const fieldName = `UF_CRM_${SETTLED_FIELD_CODE}`;
        BITRIX_CRM_CONFIG.dealFields.commissionSettled = fieldName;
        ensureSelectIncludes(fieldName);
        return fieldName;
      } catch (error) {
        console.warn('Commission settled field setup failed:', error);
        return '';
      }
    }

    function parseSettledFlag(value) {
      const raw = unwrapCrmValue(value);
      if (raw === '' || raw == null) return null;
      const s = String(raw).trim().toLowerCase();
      if (['1', 'y', 'yes', 'true'].includes(s)) return true;
      if (['0', 'n', 'no', 'false'].includes(s)) return false;
      return Boolean(raw);
    }

    function loadSettledDeals() {
      if (settledDealsCache) return settledDealsCache;
      try {
        const raw = localStorage.getItem(SETTLED_STORAGE_KEY);
        if (!raw) {
          settledDealsCache = {};
          return settledDealsCache;
        }
        const parsed = JSON.parse(raw);
        settledDealsCache = parsed && typeof parsed === 'object' ? parsed : {};
        return settledDealsCache;
      } catch {
        settledDealsCache = {};
        return settledDealsCache;
      }
    }

    function isDealCommissionSettled(dealId, dealRaw) {
      const field = BITRIX_CRM_CONFIG.dealFields.commissionSettled;
      if (dealRaw && field) {
        const parsed = parseSettledFlag(dealRaw[field]);
        if (parsed != null) return parsed;
      }
      return !!loadSettledDeals()[String(dealId)];
    }

    async function setDealCommissionSettled(dealId, settled) {
      const map = loadSettledDeals();
      const key = String(dealId);
      if (settled) map[key] = true;
      else delete map[key];
      settledDealsCache = map;
      localStorage.setItem(SETTLED_STORAGE_KEY, JSON.stringify(map));

      const field = BITRIX_CRM_CONFIG.dealFields.commissionSettled;
      if (!field || !bitrixReady || location.protocol === 'file:') return;
      try {
        await bitrixCall('crm.deal.update', {
          id: dealId,
          fields: { [field]: settled ? '1' : '0' },
        });
      } catch (error) {
        console.warn('Failed to persist settled flag to Bitrix', error);
        toast('მონიშვნა შენახულია ლოკალურად; Bitrix სინქი ვერ მოხერხდა', 'error');
      }
    }

    async function migrateLocalSettledToBitrix(dealIds) {
      const field = BITRIX_CRM_CONFIG.dealFields.commissionSettled;
      if (!field || !bitrixReady) return;
      if (localStorage.getItem(SETTLED_MIGRATED_KEY) === '1') return;
      const local = loadSettledDeals();
      const pending = dealIds
        .map(String)
        .filter(id => local[id]);
      if (!pending.length) {
        localStorage.setItem(SETTLED_MIGRATED_KEY, '1');
        return;
      }
      const chunkSize = 10;
      for (let i = 0; i < pending.length; i += chunkSize) {
        const chunk = pending.slice(i, i + chunkSize);
        await Promise.all(chunk.map(async dealId => {
          try {
            await bitrixCall('crm.deal.update', {
              id: dealId,
              fields: { [field]: '1' },
            });
          } catch (error) {
            console.warn('Settled migration failed for deal', dealId, error);
          }
        }));
      }
      localStorage.setItem(SETTLED_MIGRATED_KEY, '1');
    }

    function resolveAgentTerms(deal, agentLookup) {
      const agent = resolveAgentContract(deal, agentLookup);
      const oneTime = isOneTimeDoc(agent);
      return {
        agency: agent?.agency || '',
        commission: readAgentPct(agent?.commission),
        creditPct: readAgentPct(agent?.creditPct),
        docType: oneTime ? 'one_time' : 'contract',
        commissionType: oneTime ? 'fixed' : 'percent',
      };
    }

    function resolveAgencyName(deal, agentLookup) {
      const agent = resolveAgentContract(deal, agentLookup);
      if (agent) return agent.agency;

      const F = BITRIX_CRM_CONFIG.dealFields;
      const agentId = String(unwrapCrmValue(deal[F.agentLink]) || '').trim();
      if (!agentId) return 'არ არის არჩეული';
      return `აგენტი #${agentId}`;
    }

    function resolveClientName(deal, contactMap, companyMap = {}) {
      const F = BITRIX_CRM_CONFIG.dealFields;
      const fromField = String(deal[F.clientName] || '').trim();
      if (fromField) return fromField;
      const contactId = String(deal.CONTACT_ID || '').trim();
      if (contactId && contactMap[contactId]) return contactMap[contactId];
      const companyId = String(deal.COMPANY_ID || '').trim();
      if (companyId && companyMap[companyId]) return companyMap[companyId];
      return '—';
    }

    function parseBitrixListDate(value) {
      if (!value) return 0;
      const raw = String(value).trim().toLowerCase()
        .replace(/\s*am$/i, ' AM')
        .replace(/\s*pm$/i, ' PM');
      const parsed = Date.parse(raw);
      return Number.isNaN(parsed) ? 0 : parsed;
    }

    async function bitrixFetchDealPaymentSum(dealId) {
      const cfg = BITRIX_CRM_CONFIG.paymentList;
      const elements = await bitrixCall('lists.element.get', {
        IBLOCK_TYPE_ID: cfg.iblockTypeId,
        IBLOCK_ID: cfg.iblockId,
        FILTER: { [cfg.dealLink]: String(dealId) },
      });
      if (!Array.isArray(elements)) return null;
      if (!elements.length) return 0;
      let total = 0;
      elements.forEach(el => {
        total += parseBitrixMoneyAmount(getBitrixPropertyValue(el, cfg.amount));
      });
      return total;
    }

    async function bitrixLoadDealPaymentTotals(dealIds) {
      const unique = [...new Set(dealIds.map(String).filter(Boolean))];
      const map = new Map();
      const chunkSize = 15;
      for (let i = 0; i < unique.length; i += chunkSize) {
        const chunk = unique.slice(i, i + chunkSize);
        const pairs = await Promise.all(chunk.map(async dealId => {
          try {
            const sum = await bitrixFetchDealPaymentSum(dealId);
            return [dealId, sum];
          } catch (error) {
            console.warn('Payment list load failed for deal', dealId, error);
            return [dealId, null];
          }
        }));
        pairs.forEach(([dealId, sum]) => {
          if (sum != null && !Number.isNaN(sum)) map.set(dealId, sum);
        });
      }
      return map;
    }

    function parseBitrixMoneyAmount(value) {
      const raw = unwrapBitrixValue(value);
      if (raw == null || raw === '') return 0;
      if (typeof raw === 'number') return raw;
      const str = String(raw).trim();
      const parts = str.split('|');
      const num = Number(parts[0].replace(/[^\d.-]/g, ''));
      if (!Number.isFinite(num)) return 0;
      const currency = (parts[1] || '').trim().toUpperCase();
      const allowed = BITRIX_CRM_CONFIG.paymentCurrencies || ['USD', 'GEL'];
      if (currency && !allowed.includes(currency)) return 0;
      return num;
    }

    function readBitrixListText(value) {
      const raw = unwrapBitrixValue(value);
      if (raw == null || raw === '') return '';
      if (Array.isArray(raw)) return raw.map(v => String(v).trim()).filter(Boolean).join(' · ');
      return String(raw).trim();
    }

    async function bitrixFetchDealAgentCommissionInfo(dealId) {
      const cfg = BITRIX_CRM_CONFIG.agentCommissionList;
      const elements = await bitrixCall('lists.element.get', {
        IBLOCK_TYPE_ID: cfg.iblockTypeId,
        IBLOCK_ID: cfg.iblockId,
        FILTER: { [cfg.dealLink]: String(dealId) },
      });
      if (!Array.isArray(elements) || !elements.length) return { paid: 0, comment: '' };
      let total = 0;
      const comments = [];
      elements.forEach(el => {
        total += parseBitrixMoneyAmount(getBitrixPropertyValue(el, cfg.amount));
        const text = readBitrixListText(getBitrixPropertyValue(el, cfg.comment));
        if (text) comments.push(text);
      });
      return {
        paid: Math.round(total),
        comment: [...new Set(comments)].join(' · '),
      };
    }

    async function bitrixLoadDealAgentCommissionPaid(dealIds) {
      const unique = [...new Set(dealIds.map(String).filter(Boolean))];
      const map = new Map();
      const chunkSize = 15;
      for (let i = 0; i < unique.length; i += chunkSize) {
        const chunk = unique.slice(i, i + chunkSize);
        const pairs = await Promise.all(chunk.map(async dealId => {
          try {
            const info = await bitrixFetchDealAgentCommissionInfo(dealId);
            return [dealId, info];
          } catch (error) {
            console.warn('Agent commission list load failed for deal', dealId, error);
            return [dealId, { paid: 0, comment: '' }];
          }
        }));
        pairs.forEach(([dealId, info]) => map.set(dealId, info));
      }
      return map;
    }

    function resolveDealPaidUsd(deal, paymentTotals) {
      if (paymentTotals.has(String(deal.ID))) {
        return Math.round(paymentTotals.get(String(deal.ID)));
      }
      return null;
    }

    function calculateReportAmounts(deal, creditPct, paidUsdOverride, commissionOverride, paidCommissionOverride, commissionType) {
      const F = BITRIX_CRM_CONFIG.dealFields;
      const propertyValue = Number(deal.OPPORTUNITY) || 0;
      const crmPaidPct = Number(deal[F.paidPct]) || 0;
      const commission = commissionOverride !== '' && commissionOverride != null
        ? Number(commissionOverride) || 0
        : 0;
      const credit = creditPct !== '' && creditPct != null ? Number(creditPct) || 0 : 0;
      const paidUsd = paidUsdOverride != null && !Number.isNaN(paidUsdOverride)
        ? Math.round(paidUsdOverride)
        : Math.round(propertyValue * crmPaidPct / 100);
      const paidPct = propertyValue > 0
        ? Math.round((paidUsd / propertyValue) * 10000) / 100
        : 0;
      const meetsCreditThreshold = credit > 0 && paidPct >= credit;
      const payableRaw = meetsCreditThreshold
        ? (commissionType === 'fixed' ? commission : propertyValue * (commission / 100))
        : 0;
      const payable = Math.round(payableRaw);
      const paidCommission = paidCommissionOverride != null && !Number.isNaN(paidCommissionOverride)
        ? Math.round(paidCommissionOverride)
        : 0;
      const difference = payable - paidCommission;
      return { propertyValue, paidPct, paidUsd, commission, payable, paidCommission, difference };
    }

    function bitrixDealToReportDeal(deal, contactMap, agentLookup, paymentTotals, agentCommissionPaid, companyMap = {}) {
      const F = BITRIX_CRM_CONFIG.dealFields;
      const project = String(deal[F.project] || '').trim();
      const agentTerms = resolveAgentTerms(deal, agentLookup);
      const agency = agentTerms.agency || resolveAgencyName(deal, agentLookup);
      const commission = agentTerms.commission;
      const creditPct = agentTerms.creditPct;
      const paidUsdOverride = resolveDealPaidUsd(deal, paymentTotals);
      const agentPayment = agentCommissionPaid.get(String(deal.ID)) || { paid: 0, comment: '' };
      const paidCommissionOverride = agentPayment.paid;
      const amounts = calculateReportAmounts(
        deal, creditPct, paidUsdOverride, commission, paidCommissionOverride, agentTerms.commissionType
      );
      const commissionSettled = isDealCommissionSettled(deal.ID, deal);
      const difference = commissionSettled ? 0 : amounts.difference;
      const propertyNo = String(deal[F.propertyNo] || deal[F.propertyNoAlt] || '').trim();
      return {
        leadId: String(deal.ID),
        client: resolveClientName(deal, contactMap, companyMap),
        signedDate: parseCrmDate(deal[F.signedDate]),
        project,
        propertyType: String(deal[F.propertyType] || '').trim(),
        propertyNo,
        propertyValue: amounts.propertyValue,
        paidUsd: amounts.paidUsd,
        paidPct: amounts.paidPct,
        agency,
        formAgency: String(deal[F.formAgency] || '').trim(),
        commission,
        creditPct,
        docType: agentTerms.docType,
        commissionType: agentTerms.commissionType,
        payable: amounts.payable,
        paidCommission: amounts.paidCommission,
        difference,
        commissionSettled,
        commissionComment: agentPayment.comment || '',
        agentBitrixId: String(unwrapCrmValue(deal[F.agentLink]) || ''),
      };
    }

    async function bitrixFetchContacts(ids) {
      const map = {};
      const unique = [...new Set(ids.map(String).filter(Boolean))];
      for (let i = 0; i < unique.length; i += 50) {
        const chunk = unique.slice(i, i + 50);
        const batch = await bitrixCall('crm.contact.list', {
          filter: { '@ID': chunk },
          select: ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
        });
        (batch || []).forEach(contact => {
          const name = [contact.NAME, contact.LAST_NAME].filter(Boolean).join(' ').trim();
          map[String(contact.ID)] = name || '—';
        });
      }
      return map;
    }

    async function bitrixFetchCompanies(ids) {
      const map = {};
      const unique = [...new Set(ids.map(String).filter(Boolean))];
      for (let i = 0; i < unique.length; i += 50) {
        const chunk = unique.slice(i, i + 50);
        const batch = await bitrixCall('crm.company.list', {
          filter: { '@ID': chunk },
          select: ['ID', 'TITLE'],
        });
        (batch || []).forEach(company => {
          map[String(company.ID)] = String(company.TITLE || '').trim() || '—';
        });
      }
      return map;
    }

    function formatContactName(contact) {
      return [contact.NAME, contact.SECOND_NAME, contact.LAST_NAME]
        .map(v => String(v || '').trim())
        .filter(Boolean)
        .join(' ')
        .trim();
    }

    async function bitrixSearchContacts(query) {
      const q = String(query || '').trim();
      if (q.length < 2) return [];
      const select = ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME'];
      const searches = [
        { '%NAME': q },
        { '%LAST_NAME': q },
        { '%SECOND_NAME': q },
      ];
      const [contactChunks, companyBatch] = await Promise.all([
        Promise.all(searches.map(filter =>
          bitrixCall('crm.contact.list', {
            filter,
            select,
            order: { LAST_NAME: 'ASC', NAME: 'ASC' },
            start: 0,
          }).catch(() => [])
        )),
        bitrixCall('crm.company.list', {
          filter: { '%TITLE': q },
          select: ['ID', 'TITLE'],
          order: { TITLE: 'ASC' },
          start: 0,
        }).catch(() => []),
      ]);
      const byId = new Map();
      contactChunks.flat().forEach(contact => {
        if (!contact || contact.ID == null) return;
        const name = formatContactName(contact);
        if (!name) return;
        byId.set(`c:${contact.ID}`, { id: String(contact.ID), name });
      });
      (companyBatch || []).forEach(company => {
        if (!company || company.ID == null) return;
        const name = String(company.TITLE || '').trim();
        if (!name) return;
        byId.set(`co:${company.ID}`, { id: String(company.ID), name });
      });
      const lower = q.toLowerCase();
      return [...byId.values()]
        .filter(c => c.name.toLowerCase().includes(lower))
        .sort((a, b) => a.name.localeCompare(b.name, 'ka'))
        .slice(0, 12);
    }

    async function reloadReportDeals() {
      const btn = $('report-reload');
      const label = btn?.textContent || 'განახლება';
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'იტვირთება…';
      }
      try {
        if (!bitrixReady) await bitrixInit();
        setBitrixStatus('load', 'რეპორტი იტვირთება…');
        reportDeals = await bitrixLoadReportDeals();
        populateReportProjectFilter();
        renderReportTable();
        setBitrixStatus('ok', `Bitrix24 · ${reportDeals.length} deal`);
        toast('რეპორტი განახლდა');
      } catch (error) {
        console.warn('Report reload failed:', error);
        setBitrixStatus('err', error.message || 'Bitrix24 კავშირი ვერ დამყარდა');
        toast(`რეპორტის ჩატვირთვა ვერ მოხერხდა: ${error.message}`, 'error');
      } finally {
        if (btn) {
          btn.disabled = false;
          btn.textContent = label;
        }
      }
    }

    async function bitrixLoadReportDeals() {
      if (!bitrixReady) await bitrixInit();
      const items = [];
      let start = 0;
      while (true) {
        const batch = await bitrixCall('crm.deal.list', {
          filter: {
            STAGE_ID: BITRIX_CRM_CONFIG.stageId,
            SOURCE_ID: BITRIX_CRM_CONFIG.sourceId,
            CATEGORY_ID: BITRIX_CRM_CONFIG.categoryId,
          },
          select: BITRIX_CRM_CONFIG.select,
          order: { [BITRIX_CRM_CONFIG.dealFields.signedDate]: 'DESC' },
          start,
        });
        if (!Array.isArray(batch) || !batch.length) break;
        items.push(...batch);
        if (batch.length < 50) break;
        start += batch.length;
      }
      const contactIds = items.map(d => d.CONTACT_ID).filter(Boolean);
      const companyIds = items.map(d => d.COMPANY_ID).filter(Boolean);
      const dealIds = items.map(d => d.ID).filter(Boolean);
      const [contactMap, companyMap, paymentTotals, agentCommissionPaid] = await Promise.all([
        bitrixFetchContacts(contactIds),
        bitrixFetchCompanies(companyIds),
        bitrixLoadDealPaymentTotals(dealIds),
        bitrixLoadDealAgentCommissionPaid(dealIds),
      ]);
      await migrateLocalSettledToBitrix(dealIds);
      const agentLookup = buildAgentLookup();
      return items.map(deal =>
        bitrixDealToReportDeal(deal, contactMap, agentLookup, paymentTotals, agentCommissionPaid, companyMap)
      );
    }

    function esc(s) {
      const d = document.createElement('div');
      d.textContent = s;
      return d.innerHTML;
    }

    function migrateSignedContracts(c) {
      if (Array.isArray(c.signedContracts) && c.signedContracts.length) {
        return c.signedContracts
          .filter(doc => doc && doc.name && doc.data)
          .map(doc => ({
            id: doc.id || uid(),
            name: doc.name,
            data: doc.data,
            uploadedAt: doc.uploadedAt || '',
          }));
      }
      if (c.signedContractName && c.signedContractData) {
        return [{
          id: uid(),
          name: c.signedContractName,
          data: c.signedContractData,
          uploadedAt: '',
        }];
      }
      return [];
    }

    function getSignedContracts(c) {
      const fromArray = Array.isArray(c.signedContracts)
        ? c.signedContracts.filter(doc => doc && doc.name && doc.data)
        : [];
      if (fromArray.length) return fromArray;
      return migrateSignedContracts(c);
    }

    function hasSignedContracts(c) {
      return getSignedContracts(c).length > 0;
    }

    function renderSignedContractsCell(c) {
      const docs = getSignedContracts(c);
      const links = docs.map(doc =>
        `<span class="signed-name" data-view-signed="${c.id}" data-doc-id="${esc(doc.id)}" title="${esc(doc.name)}">${esc(doc.name)}</span>`
      ).join('');
      const uploadLabel = docs.length ? '+ ატვირთვა' : 'ატვირთვა';
      return `<div class="signed-cell">${links}<button type="button" class="btn btn-sm btn-ghost" data-upload-signed="${c.id}">${uploadLabel}</button></div>`;
    }

    function renderSignedContractsDetail(c) {
      const docs = getSignedContracts(c);
      if (!docs.length) return '—';
      return `<div class="signed-docs-list">${docs.map(doc => `
        <div class="signed-doc-row">
          <span class="signed-name" data-detail-view-signed="${c.id}" data-doc-id="${esc(doc.id)}">${esc(doc.name)}</span>
          ${doc.uploadedAt ? `<span class="signed-doc-date">${formatDate(doc.uploadedAt.slice(0, 10))}</span>` : ''}
        </div>
      `).join('')}</div>`;
    }

    function renderActiveHistory(c) {
      const history = Array.isArray(c.activeHistory) ? c.activeHistory : [];
      if (!history.length) return '—';
      return `<div class="active-history-list">${[...history].reverse().map(h => `
        <div class="active-history-row">
          <span class="active-label ${h.active ? 'yes' : 'no'}">${h.active ? 'აქტიური' : 'არააქტიური'}</span>
          <span class="active-history-date">${formatDateTime(h.changedAt)}</span>
          <span class="active-history-comment">${esc(h.comment)}</span>
        </div>
      `).join('')}</div>`;
    }

    function getAgentDisplayName(c) {
      const agency = String(c?.agency || '').trim();
      const idCode = String(c?.idCode || '').trim();
      return idCode ? `${agency} (${idCode})` : agency;
    }

    function listAgentsWithProjects(excludeKey) {
      const seen = new Map();
      contracts.forEach(c => {
        if (!c.project) return;
        const key = getAgentKey(c);
        if (key === excludeKey) return;
        if (!seen.has(key)) {
          seen.set(key, { key, name: getAgentDisplayName(c), count: 0 });
        }
        seen.get(key).count += 1;
      });
      return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name, 'ka'));
    }

    function buildContractFromAgentBase(base, source) {
      return {
        id: uid(),
        agency: base.agency,
        idCode: base.idCode,
        bank: base.bank,
        phones: [...(base.phones || [])],
        email: base.email || '',
        contactPerson: base.contactPerson || '',
        project: source.project,
        commission: source.commission,
        creditPct: source.creditPct,
        docType: source.docType === 'one_time' || isOneTimeDoc(source) ? 'one_time' : 'contract',
        signedDate: source.signedDate || '',
        validUntil: source.validUntil || '',
        active: true,
        contractGeneratedAt: '',
        signedContracts: [],
        activeHistory: [],
      };
    }

    async function copyProjectsFromAgent(sourceKey) {
      if (!addProjectSource) return 0;
      const targetKey = getAgentKey(addProjectSource);
      const sourceByProject = new Map();
      contracts.forEach(c => {
        if (getAgentKey(c) !== sourceKey || !c.project) return;
        if (!sourceByProject.has(c.project)) sourceByProject.set(c.project, c);
      });
      const targetProjects = new Set(getAgentContractsFor(addProjectSource).map(c => c.project).filter(Boolean));
      const toAdd = [...sourceByProject.values()].filter(c => !targetProjects.has(c.project));
      if (!toAdd.length) return 0;

      for (const src of toAdd) {
        const contract = buildContractFromAgentBase(addProjectSource, src);
        if (bitrixReady) {
          await syncContractToBitrix(contract, true);
        }
        contracts.unshift(contract);
      }
      saveData();
      return toAdd.length;
    }

    function getAgentKey(c) {
      const idCode = String(c?.idCode ?? '').trim();
      if (idCode) return `id:${idCode}`;
      return `name:${String(c?.agency ?? '').trim().toLowerCase()}`;
    }

    function getAgentContractsFor(c) {
      const key = getAgentKey(c);
      return contracts.filter(x => getAgentKey(x) === key);
    }

    function getAvailableProjectsForAgent(c) {
      const taken = new Set(getAgentContractsFor(c).map(x => x.project).filter(Boolean));
      return getActiveProjectNames().filter(p => !taken.has(p));
    }

    function getFiltered() {
      const agency = $('filter-agency').value.trim().toLowerCase();
      const idCode = $('filter-idCode').value.trim();
      const project = $('filter-project').value;
      const commission = $('filter-commission').value.trim();
      const active = $('filter-active').value;

      return contracts.filter(c => {
        if (agency && !c.agency.toLowerCase().includes(agency)) return false;
        if (idCode && !c.idCode.includes(idCode)) return false;
        if (project && c.project !== project) return false;
        if (commission && Number(c.commission) !== Number(commission)) return false;
        if (active === 'yes' && c.active === false) return false;
        if (active === 'no' && c.active !== false) return false;
        return true;
      });
    }

    function resetFilterValues() {
      $('filter-agency').value = '';
      $('filter-idCode').value = '';
      $('filter-project').value = '';
      $('filter-commission').value = '';
      $('filter-active').value = 'yes';
    }

    function clearFilters() {
      resetFilterValues();
      refresh();
    }

    function formatUsd(n) {
      if (n == null || n === '') return '—';
      return '$' + new Intl.NumberFormat('en-US').format(n);
    }

    function formatPct(n) {
      if (n == null || n === '') return '—';
      return Number(n).toFixed(2) + '%';
    }

    function formatMoney(n) {
      return formatUsd(n);
    }

    function formatReportTotal(n) {
      if (n == null || n === '' || !Number.isFinite(Number(n))) return '—';
      return new Intl.NumberFormat('en-US').format(Math.round(Number(n)));
    }

    function sumReportRows(rows, key) {
      return rows.reduce((sum, d) => sum + (Number(d[key]) || 0), 0);
    }

    function renderReportTotals(rows) {
      const totalRow = $('report-totals-row');
      if (!totalRow) return;
      ['propertyValue', 'paidUsd', 'payable', 'paidCommission', 'difference'].forEach(key => {
        const cell = totalRow.querySelector(`[data-total="${key}"]`);
        if (!cell) return;
        cell.textContent = rows.length ? formatReportTotal(sumReportRows(rows, key)) : '—';
        cell.classList.remove('report-amt-ok', 'report-amt-due');
        if (key === 'difference' && rows.length) {
          const diff = sumReportRows(rows, key);
          cell.classList.add(diff <= 0 ? 'report-amt-ok' : 'report-amt-due');
        }
      });
    }

    function getDealCreditPct(d) {
      return d.creditPct != null && d.creditPct !== '' ? d.creditPct : '';
    }

    function formatAgentPct(v) {
      return v !== '' && v != null ? `${v}%` : '—';
    }

    function formatCreditPct(v) {
      return formatAgentPct(v);
    }

    function getReportAmountStatusClass(d) {
      const diff = Number(d.difference);
      if (!Number.isFinite(diff)) return '';
      return diff <= 0 ? 'report-amt-ok' : 'report-amt-due';
    }

    function hideReportClientSuggest() {
      const box = $('report-client-suggest');
      if (!box) return;
      box.classList.remove('open');
      box.hidden = true;
      box.innerHTML = '';
    }

    function showReportClientSuggest(items, query) {
      const box = $('report-client-suggest');
      if (!box) return;
      if (!items.length) {
        box.innerHTML = `<div class="suggest-empty">კლიენტი ვერ მოიძებნა „${esc(query)}”</div>`;
      } else {
        box.innerHTML = items.map(item =>
          `<button type="button" class="suggest-item" role="option" data-client-name="${esc(item.name)}">${esc(item.name)}</button>`
        ).join('');
      }
      box.hidden = false;
      box.classList.add('open');
    }

    function scheduleReportClientSuggest() {
      const input = $('report-filter-client');
      if (!input) return;
      const query = input.value.trim();
      clearTimeout(reportClientSuggestTimer);
      if (query.length < 2) {
        hideReportClientSuggest();
        return;
      }
      reportClientSuggestTimer = setTimeout(() => {
        runReportClientSuggest(query);
      }, 280);
    }

    async function runReportClientSuggest(query) {
      const seq = ++reportClientSuggestSeq;
      const input = $('report-filter-client');
      if (!input || input.value.trim() !== query) return;
      if (location.protocol === 'file:' || !bitrixReady) {
        showReportClientSuggest([], query);
        return;
      }
      try {
        const items = await bitrixSearchContacts(query);
        if (seq !== reportClientSuggestSeq) return;
        if (input.value.trim() !== query) return;
        showReportClientSuggest(items, query);
      } catch (error) {
        console.warn('Contact suggest failed:', error);
        if (seq !== reportClientSuggestSeq) return;
        showReportClientSuggest([], query);
      }
    }

    function initReportClientSuggest() {
      const input = $('report-filter-client');
      const box = $('report-client-suggest');
      if (!input || !box || box.dataset.ready) return;
      box.dataset.ready = '1';
      box.addEventListener('mousedown', e => {
        const btn = e.target.closest('[data-client-name]');
        if (!btn) return;
        e.preventDefault();
        input.value = btn.dataset.clientName || '';
        hideReportClientSuggest();
        renderReportTable();
      });
      input.addEventListener('keydown', e => {
        if (e.key === 'Escape') hideReportClientSuggest();
      });
      input.addEventListener('blur', () => {
        setTimeout(hideReportClientSuggest, 150);
      });
      document.addEventListener('click', e => {
        if (e.target.closest('.autocomplete-wrap')) return;
        hideReportClientSuggest();
      });
    }

    function getReportFiltered() {
      const from = $('report-date-from').value;
      const to = $('report-date-to').value;
      const project = $('report-filter-project').value;
      const agency = $('report-filter-agency').value.trim().toLowerCase();
      const client = ($('report-filter-client')?.value || '').trim().toLowerCase();
      return reportDeals.filter(d => {
        if (from && d.signedDate < from) return false;
        if (to && d.signedDate > to) return false;
        if (project && d.project !== project) return false;
        if (agency && !d.agency.toLowerCase().includes(agency)) return false;
        if (client && !(d.client || '').toLowerCase().includes(client)) return false;
        return true;
      });
    }

    function getReportSortValue(d, key) {
      if (key === 'creditPct') return getDealCreditPct(d);
      return d[key];
    }

    function compareReportRows(a, b, key, type, dir) {
      const mul = dir === 'asc' ? 1 : -1;
      let va = getReportSortValue(a, key);
      let vb = getReportSortValue(b, key);

      if (type === 'number') {
        const na = va !== '' && va != null && Number.isFinite(Number(va));
        const nb = vb !== '' && vb != null && Number.isFinite(Number(vb));
        if (!na && !nb) return 0;
        if (!na) return 1;
        if (!nb) return -1;
        return (Number(va) - Number(vb)) * mul;
      }

      if (type === 'date') {
        return String(va || '').localeCompare(String(vb || ''), 'en') * mul;
      }

      return String(va || '').localeCompare(String(vb || ''), 'ka', { numeric: true, sensitivity: 'base' }) * mul;
    }

    function sortReportRows(rows) {
      if (!reportSort.key) return rows;
      const th = document.querySelector(`.report-table thead th[data-sort="${reportSort.key}"]`);
      const type = th?.dataset.sortType || 'string';
      return [...rows].sort((a, b) => compareReportRows(a, b, reportSort.key, type, reportSort.dir));
    }

    function updateReportSortHeaders() {
      document.querySelectorAll('.report-table thead th[data-sort]').forEach(th => {
        th.classList.add('sortable');
        th.classList.remove('sort-asc', 'sort-desc');
        let icon = th.querySelector('.sort-icon');
        if (!icon) {
          icon = document.createElement('span');
          icon.className = 'sort-icon';
          icon.setAttribute('aria-hidden', 'true');
          th.appendChild(icon);
        }
        if (th.dataset.sort === reportSort.key) {
          th.classList.add(reportSort.dir === 'asc' ? 'sort-asc' : 'sort-desc');
          icon.textContent = reportSort.dir === 'asc' ? '▲' : '▼';
        } else {
          icon.textContent = '↕';
        }
      });
    }

    function initReportSort() {
      const thead = document.querySelector('.report-table thead');
      if (!thead || thead.dataset.sortReady) return;
      thead.dataset.sortReady = '1';
      thead.addEventListener('click', e => {
        const th = e.target.closest('th[data-sort]');
        if (!th) return;
        const key = th.dataset.sort;
        if (reportSort.key === key) {
          reportSort.dir = reportSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
          reportSort.key = key;
          const type = th.dataset.sortType || 'string';
          reportSort.dir = (type === 'number' || type === 'date') ? 'desc' : 'asc';
        }
        renderReportTable();
      });
    }

    function getReportDealById(dealId) {
      return reportDeals.find(d => String(d.leadId) === String(dealId));
    }

    function openDealTab(dealId) {
      selectedDealId = String(dealId);
      switchTab('deal');
    }

    function renderDealDetail() {
      const body = $('deal-detail-body');
      const title = $('deal-detail-title');
      const link = $('deal-bitrix-link');
      if (!body || !selectedDealId) {
        if (body) body.innerHTML = '<p style="color:var(--muted)">Deal ვერ მოიძებნა</p>';
        return;
      }
      const d = getReportDealById(selectedDealId);
      if (!d) {
        body.innerHTML = '<p style="color:var(--muted)">Deal ვერ მოიძებნა</p>';
        return;
      }
      const amtStatus = getReportAmountStatusClass(d);
      const amtBlockClass = amtStatus === 'report-amt-ok' ? 'deal-amt-ok'
        : amtStatus === 'report-amt-due' ? 'deal-amt-due' : '';
      const bitrixUrl = getDealBitrixUrl(d.leadId);
      title.textContent = `Deal #${d.leadId}`;
      link.href = bitrixUrl;
      body.innerHTML = `
        <div class="deal-detail-block">
          <h3>კლიენტი და უძრავი ქონება</h3>
          <div class="record-detail-grid">
            <div class="detail-item"><span class="lbl">ID</span><span class="val">${esc(d.leadId)}</span></div>
            <div class="detail-item"><span class="lbl">კლიენტი</span><span class="val">${esc(d.client)}</span></div>
            <div class="detail-item"><span class="lbl">გაფორმება</span><span class="val">${formatDate(d.signedDate)}</span></div>
            <div class="detail-item"><span class="lbl">პროექტი</span><span class="val">${esc(d.project)}</span></div>
            <div class="detail-item"><span class="lbl">უძ. ტიპი</span><span class="val">${esc(d.propertyType)}</span></div>
            <div class="detail-item"><span class="lbl">#</span><span class="val">${esc(d.propertyNo)}</span></div>
            <div class="detail-item"><span class="lbl">ღირებულება $</span><span class="val">${formatUsd(d.propertyValue)}</span></div>
          </div>
        </div>
        <div class="deal-detail-block">
          <h3>კლიენტის გადახდები</h3>
          <div class="record-detail-grid">
            <div class="detail-item"><span class="lbl">გადახდილი $</span><span class="val">${formatUsd(d.paidUsd)}</span></div>
            <div class="detail-item"><span class="lbl">გადახდილი %</span><span class="val">${formatPct(d.paidPct)}</span></div>
          </div>
        </div>
        <div class="deal-detail-block">
          <h3>სააგენტო</h3>
          <div class="record-detail-grid">
            <div class="detail-item full"><span class="lbl">სააგენტო</span><span class="val">${esc(d.agency)}</span></div>
            <div class="detail-item"><span class="lbl">საკომისიო</span><span class="val">${formatCommissionLabel(d, d.commissionType)}</span></div>
            <div class="detail-item"><span class="lbl">ჩასარიცხი %</span><span class="val">${formatCreditPct(getDealCreditPct(d))}</span></div>
          </div>
        </div>
        <div class="deal-detail-block ${amtBlockClass}">
          <h3>საკომისიო</h3>
          <div class="record-detail-grid">
            <div class="detail-item"><span class="lbl">გადასახ. $</span><span class="val">${formatMoney(d.payable)}</span></div>
            <div class="detail-item"><span class="lbl">გადახ. საკ. $</span><span class="val">${formatMoney(d.paidCommission)}</span></div>
            <div class="detail-item"><span class="lbl">სხვაობა $</span><span class="val">${formatMoney(d.difference)}</span></div>
            <div class="detail-item"><span class="lbl">გადახდილი / დახურული</span><span class="val">${d.commissionSettled ? 'დიახ' : 'არა'}</span></div>
            <div class="detail-item full"><span class="lbl">კომენტარი</span><span class="val">${d.commissionComment ? esc(d.commissionComment) : '—'}</span></div>
          </div>
        </div>
      `;
    }

    function renderReportTable() {
      const rows = sortReportRows(getReportFiltered());
      const tbody = $('report-table');
      if (!tbody) return;

      $('report-count').textContent = `ნაპოვნია: ${rows.length} ჩანაწერი`;
      updateReportSortHeaders();
      renderReportTotals(rows);

      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="18" style="text-align:center;color:var(--muted);padding:24px">ჩანაწერი ვერ მოიძებნა</td></tr>';
        clearReportRowHighlight();
        return;
      }

      tbody.innerHTML = rows.map((d, index) => {
        const amtClass = getReportAmountStatusClass(d);
        return `
        <tr>
          <td class="col-r-num">${index + 1}</td>
          <td class="col-r-id cell-id"><a class="deal-id-link" href="${esc(getDealBitrixUrl(d.leadId))}" target="_blank" rel="noopener noreferrer">${esc(d.leadId)}</a></td>
          <td class="col-r-client">${d.client && d.client !== '—'
            ? `<a class="deal-client-link" href="${esc(getDealBitrixUrl(d.leadId))}" target="_blank" rel="noopener noreferrer">${esc(d.client)}</a>`
            : '—'}</td>
          <td class="col-r-date">${formatDate(d.signedDate)}</td>
          <td class="col-r-project">${esc(d.project)}</td>
          <td>${esc(d.propertyType)}</td>
          <td>${esc(d.propertyNo)}</td>
          <td class="num">${formatUsd(d.propertyValue)}</td>
          <td class="num">${formatUsd(d.paidUsd)}</td>
          <td class="num">${formatPct(d.paidPct)}</td>
          <td>${d.agency === 'არ არის არჩეული'
            ? `<span style="color:var(--muted)">${esc(d.agency)}</span>`
            : esc(d.agency)}</td>
          <td class="num">${formatCommissionLabel(d, d.commissionType)}</td>
          <td class="num">${formatCreditPct(getDealCreditPct(d))}</td>
          <td class="num ${amtClass}">${formatMoney(d.payable)}</td>
          <td class="num ${amtClass}">${formatMoney(d.paidCommission)}</td>
          <td class="num ${amtClass}">${formatMoney(d.difference)}</td>
          <td class="report-settle-cell">
            <label class="report-settle-label" title="მონიშვნა გადახდილად / დახურულად">
              <input type="checkbox" data-settle-deal="${esc(d.leadId)}" ${d.commissionSettled ? 'checked' : ''} />
              <span>დახურული</span>
            </label>
          </td>
          <td class="col-r-comment">${d.commissionComment ? esc(d.commissionComment) : '—'}</td>
        </tr>
      `;
      }).join('');
    }

    let reportRowHighlightEl = null;

    function clearReportRowHighlight() {
      if (reportRowHighlightEl) {
        reportRowHighlightEl.classList.remove('row-hover');
        reportRowHighlightEl = null;
      }
    }

    function setReportRowHighlight(row) {
      if (row === reportRowHighlightEl) return;
      clearReportRowHighlight();
      if (row && row.closest('tbody')) {
        row.classList.add('row-hover');
        reportRowHighlightEl = row;
      }
    }

    function initReportRowHighlight() {
      const table = document.querySelector('.report-table');
      if (!table || table.dataset.rowHighlightReady) return;
      table.dataset.rowHighlightReady = '1';
      table.addEventListener('mouseover', e => {
        const row = e.target.closest('tbody tr');
        if (row && table.contains(row)) setReportRowHighlight(row);
      });
      table.addEventListener('mouseleave', e => {
        if (!table.contains(e.relatedTarget)) clearReportRowHighlight();
      });
    }

    function escapeXml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function excelCellXml(value, type = 'String') {
      if (type === 'Number') {
        const num = Number(value);
        if (value === '' || value == null || !Number.isFinite(num)) {
          return '<Cell><Data ss:Type="String"></Data></Cell>';
        }
        return `<Cell><Data ss:Type="Number">${num}</Data></Cell>`;
      }
      return `<Cell><Data ss:Type="String">${escapeXml(value ?? '')}</Data></Cell>`;
    }

    function downloadExcel(filename, headers, rows, numericCols = []) {
      const numericSet = new Set(numericCols);
      const headerRow = headers.map(h => excelCellXml(h, 'String')).join('');
      const dataRows = rows.map(row => {
        const cells = row.map((v, i) => excelCellXml(v, numericSet.has(i) ? 'Number' : 'String')).join('');
        return `<Row>${cells}</Row>`;
      }).join('');
      const xml = `<\?xml version="1.0" encoding="UTF-8"?>
<\?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Worksheet ss:Name="Sheet1">
<Table>
<Row>${headerRow}</Row>
${dataRows}
</Table>
</Worksheet>
</Workbook>`;
      const blob = new Blob(['\uFEFF' + xml], { type: 'application/vnd.ms-excel;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = filename.endsWith('.xls') ? filename : `${filename}.xls`;
      a.click();
      URL.revokeObjectURL(a.href);
    }

    function exportReportExcel() {
      const headers = [
        '#', 'ლიდის ID', 'კლიენტი', 'ხელშ. გაფორმების თარიღი', 'პროექტი', 'უძ.ქონების ტიპი', 'უძ.ქონების #',
        'ღირებულება $', 'გადახდილი $', 'გადახდილი %', 'სააგენტო', 'სააგენტო / აგენტი (ფორმიდან)', 'საკომისიო', 'ჩასარიცხი %',
        'გადასახ. $', 'გადახ. საკ. $', 'სხვაობა $', 'გადახდილი/დახურული', 'კომენტარი',
      ];
      const rows = sortReportRows(getReportFiltered()).map((d, index) => [
        index + 1, d.leadId, d.client, formatDate(d.signedDate), d.project, d.propertyType, d.propertyNo,
        d.propertyValue, d.paidUsd, d.paidPct, d.agency, d.formAgency || '', formatCommissionLabel(d, d.commissionType), getDealCreditPct(d),
        d.payable, d.paidCommission, d.difference, d.commissionSettled ? 'დიახ' : 'არა', d.commissionComment || '',
      ]);
      downloadExcel(`agents-report-${new Date().toISOString().slice(0, 10)}.xls`, headers, rows, [7, 8, 9, 13, 14, 15, 16]);
      toast('Excel ფაილი ჩამოიტვირთა');
    }

    function updateStats() {
      const filtered = getFiltered();
      $('record-count').textContent = filtered.length === contracts.length
        ? `${contracts.length} ჩანაწერი`
        : `${filtered.length} / ${contracts.length} ჩანაწერი`;
      renderReportTable();
    }

    function captureScrollState() {
      return {
        winX: window.scrollX,
        winY: window.scrollY,
        containers: [...document.querySelectorAll('.table-wrap')].map(el => ({
          el,
          top: el.scrollTop,
          left: el.scrollLeft,
        })),
      };
    }

    function restoreScrollState(state) {
      if (!state) return;
      const apply = () => {
        window.scrollTo(state.winX, state.winY);
        state.containers.forEach(({ el, top, left }) => {
          if (el.isConnected) {
            el.scrollTop = top;
            el.scrollLeft = left;
          }
        });
      };
      apply();
      requestAnimationFrame(() => {
        apply();
        requestAnimationFrame(apply);
      });
    }

    function updateEditingHighlight() {
      document.querySelectorAll('#agents-table tr[data-id]').forEach(row => {
        row.classList.toggle('editing', row.dataset.id === editingId);
      });
    }

    function updateRegistryRow(id) {
      const c = contracts.find(x => x.id === id);
      const row = document.querySelector(`#agents-table tr[data-id="${id}"]`);
      if (!c || !row) return false;

      const isActive = c.active !== false;
      row.classList.toggle('row-inactive', !isActive);
      row.classList.toggle('row-expiring', isContractExpiringWithinMonth(c));
      const label = row.querySelector('.active-label');
      if (label) {
        label.textContent = isActive ? 'დიახ' : 'არა';
        label.classList.remove('yes', 'no');
        label.classList.add(isActive ? 'yes' : 'no');
      }
      const toggle = row.querySelector('[data-toggle-active]');
      if (toggle) toggle.checked = isActive;

      const genCell = row.querySelector('.registry-gen-cell');
      if (genCell) {
        genCell.innerHTML = renderGenerateCell(c);
        const genBtn = genCell.querySelector('[data-generate]');
        if (genBtn) {
          genBtn.addEventListener('click', () => generateContract(c.id));
        }
      }
      return true;
    }

    function getProjectFilterValues() {
      return {
        name: ($('filter-project-name')?.value || '').trim().toLowerCase(),
        idCode: ($('filter-project-idCode')?.value || '').trim(),
        director: ($('filter-project-director')?.value || '').trim().toLowerCase(),
        active: $('filter-project-active')?.value || '',
      };
    }

    function getFilteredProjects() {
      const f = getProjectFilterValues();
      return projects.filter(p => {
        if (f.active === 'yes' && !isProjectActive(p)) return false;
        if (f.active === 'no' && isProjectActive(p)) return false;
        if (f.name && !p.name.toLowerCase().includes(f.name)) return false;
        if (f.idCode && !(p.idCode || '').includes(f.idCode)) return false;
        if (f.director && !(p.director || '').toLowerCase().includes(f.director)) return false;
        return true;
      });
    }

    function clearProjectFilters() {
      ['filter-project-name', 'filter-project-idCode', 'filter-project-director', 'filter-project-active'].forEach(id => {
        const el = $(id);
        if (el) el.value = '';
      });
      renderProjectsTable();
    }

    function initProjectFilters() {
      ['filter-project-name', 'filter-project-idCode', 'filter-project-director'].forEach(id => {
        const el = $(id);
        if (el) el.addEventListener('input', renderProjectsTable);
      });
      const activeEl = $('filter-project-active');
      if (activeEl) activeEl.addEventListener('change', renderProjectsTable);
      const clearBtn = $('project-filter-clear');
      if (clearBtn) clearBtn.addEventListener('click', clearProjectFilters);
    }

    function renderProjectsTable() {
      const tbody = $('projects-table');
      if (!tbody) return;

      const filtered = getFilteredProjects();
      const countEl = $('projects-count');
      if (countEl) {
        countEl.textContent = filtered.length === projects.length
          ? `${projects.length} პროექტი`
          : `${filtered.length} / ${projects.length} პროექტი`;
      }

      if (!projects.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-row">პროექტი ჯერ არ არის დარეგისტრირებული</td></tr>';
        return;
      }

      if (!filtered.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-row">ფილტრმა პროექტი ვერ მოიძებნა</td></tr>';
        return;
      }

      tbody.innerHTML = filtered.map(p => {
        const isActive = isProjectActive(p);
        const on = contracts.filter(c => c.project === p.name);
        const countCell = on.length
          ? `<button type="button" class="contract-count-btn" data-project-id="${p.id}" title="სააგენტოების ნახვა">${on.length}</button>`
          : `<span class="contract-count-zero">0</span>`;
        return `<tr class="${editingProjectId === p.id ? 'editing' : ''} ${!isActive ? 'row-inactive' : ''}">
          <td class="col-pr-name">${esc(p.name)}</td>
          <td class="col-pr-code">${p.idCode ? esc(p.idCode) : '—'}</td>
          <td class="col-pr-director">${p.director ? esc(p.director) : '—'}</td>
          <td class="col-pr-contracts">${countCell}</td>
          <td class="col-pr-active">
            <label class="toggle-switch" title="აქტიური / არააქტიური">
              <input type="checkbox" data-toggle-project-active="${p.id}" ${isActive ? 'checked' : ''} />
              <span class="toggle-slider"></span>
            </label>
            <span class="active-label ${isActive ? 'yes' : 'no'}">${isActive ? 'დიახ' : 'არა'}</span>
          </td>
          <td class="col-pr-actions">
            <button type="button" class="btn-link" data-edit-project="${p.id}">რედაქტირება</button>
          </td>
        </tr>`;
      }).join('');

      tbody.querySelectorAll('[data-project-id]').forEach(btn => {
        btn.addEventListener('click', () => {
          const p = projects.find(x => x.id === btn.dataset.projectId);
          if (p) openProjectAgenciesModal(p.name);
        });
      });
      tbody.querySelectorAll('[data-edit-project]').forEach(btn => {
        btn.addEventListener('click', () => startProjectEdit(btn.dataset.editProject));
      });
      tbody.querySelectorAll('[data-toggle-project-active]').forEach(inp => {
        inp.addEventListener('change', () => {
          const id = inp.dataset.toggleProjectActive;
          const p = projects.find(x => x.id === id);
          if (!p) return;
          const currentActive = isProjectActive(p);
          const newActive = inp.checked;
          if (newActive === currentActive) return;
          inp.checked = currentActive;
          openActiveStatusModal(id, newActive, 'project');
        });
      });
    }

    function validateProjectRegister() {
      clearErrors();
      let ok = true;

      const name = $('pr-name').value.trim();
      const idCode = $('pr-idCode').value.trim();
      const director = $('pr-director').value.trim();

      if (!name) {
        showError('pr-name', 'err-pr-name');
        ok = false;
      } else if (projects.some(p => p.name.toLowerCase() === name.toLowerCase() && p.id !== editingProjectId)) {
        $('pr-name').classList.add('error');
        $('err-pr-name').textContent = 'ეს პროექტი უკვე არსებობს';
        $('err-pr-name').classList.add('show');
        ok = false;
      } else {
        $('err-pr-name').textContent = 'შეიყვანეთ პროექტის სახელი';
      }

      if (!idCode) { showError('pr-idCode', 'err-pr-idCode'); ok = false; }
      if (!director) { showError('pr-director', 'err-pr-director'); ok = false; }

      return ok ? { name, idCode, director } : null;
    }

    function resetProjectForm() {
      const wasEditing = editingProjectId !== null;
      editingProjectId = null;
      $('pr-edit-id').value = '';
      $('project-register-form').reset();
      clearErrors();
      $('err-pr-name').textContent = 'შეიყვანეთ პროექტის სახელი';
      $('project-form-title').textContent = 'პროექტის რეგისტრაცია';
      $('project-form-mode-tag').textContent = 'ახალი';
      $('pr-submit-btn').textContent = 'რეგისტრაცია';
      $('pr-cancel-edit').style.display = 'none';
      if (wasEditing) renderProjectsTable();
    }

    function startProjectEdit(id) {
      const p = projects.find(x => x.id === id);
      if (!p) return;

      editingProjectId = id;
      $('pr-edit-id').value = id;
      $('pr-name').value = p.name;
      $('pr-idCode').value = p.idCode;
      $('pr-director').value = p.director;
      $('project-form-title').textContent = 'პროექტის რედაქტირება';
      $('project-form-mode-tag').textContent = 'რედაქტირება';
      $('pr-submit-btn').textContent = 'შენახვა';
      $('pr-cancel-edit').style.display = 'inline-block';
      switchTab('projects');
      renderProjectsTable();
      $('project-register-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function openProjectAgenciesModal(project) {
      const list = contracts.filter(c => c.project === project);
      $('project-agencies-title').textContent = project;
      $('project-agencies-sub').textContent = `სააგენტოები ამ პროექტზე · ${list.length} ხელშეკრულება`;

      const container = $('project-agencies-list');
      if (!list.length) {
        container.innerHTML = '<div class="project-agencies-empty">ამ პროექტზე დარეგისტრირებული აგენტი არ არის</div>';
      } else {
        container.innerHTML = list.map(c => {
          const isActive = c.active !== false;
          return `<div class="agency-card ${!isActive ? 'row-inactive' : ''}">
            <div class="agency-card-name">${esc(c.agency)}</div>
            <div class="agency-card-meta">
              <div>
                <span class="lbl">ს/კ</span>
                <span class="val">${esc(c.idCode)}</span>
              </div>
              <div>
                <span class="lbl">საკომისიო</span>
                <span class="val">${formatCommissionLabel(c)}</span>
              </div>
              <div>
                <span class="lbl">აქტიური</span>
                <span class="val active-label ${isActive ? 'yes' : 'no'}">${isActive ? 'დიახ' : 'არა'}</span>
              </div>
              <div>
                <span class="lbl">გაფორმების თარიღი</span>
                <span class="val">${formatDate(c.signedDate)}</span>
              </div>
            </div>
          </div>`;
        }).join('');
      }

      $('project-agencies-modal').classList.add('open');
    }

    function closeProjectAgenciesModal() {
      $('project-agencies-modal').classList.remove('open');
    }

    function openRecordDetailModal(id) {
      const c = contracts.find(x => x.id === id);
      if (!c) return;

      recordDetailId = id;
      const isActive = c.active !== false;
      $('record-detail-title').textContent = c.agency;
      $('record-detail-sub').textContent = `${c.project} · ${formatCommissionLabel(c)}`;

      $('record-detail-body').innerHTML = `
        <div class="detail-item">
          <span class="lbl">სააგენტო</span>
          <span class="val">${esc(c.agency)}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">საიდენტიფიკაციო კოდი</span>
          <span class="val">${esc(c.idCode)}</span>
        </div>
        <div class="detail-item full">
          <span class="lbl">საბანკო რეკვიზიტები</span>
          <span class="val">${esc(c.bank)}</span>
        </div>
        <div class="detail-item full">
          <span class="lbl">მობ. ტელეფონი</span>
          <span class="val">${c.phones?.length ? esc(c.phones.join(', ')) : '—'}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">საკონტაქტო</span>
          <span class="val">${c.contactPerson ? esc(c.contactPerson) : '—'}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">მეილი</span>
          <span class="val">${c.email ? esc(c.email) : '—'}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">პროექტი</span>
          <span class="val">${esc(c.project)}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">დოკუმენტის ტიპი</span>
          <span class="val">${isOneTimeDoc(c) ? 'ერთჯერადი შეთანხმება' : 'ხელშეკრულება'}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">საკომისიო</span>
          <span class="val">${formatCommissionLabel(c)}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">ჩასარიცხი თანხა</span>
          <span class="val">${c.creditPct !== '' && c.creditPct != null ? `${c.creditPct}%` : '—'}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">ხელშეკრულების გაფორმების თარიღი</span>
          <span class="val">${formatDate(c.signedDate)}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">ხელშეკრულების მოქმედების ვადა</span>
          <span class="val">${formatDate(c.validUntil)}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">აქტიური</span>
          <span class="val"><span class="active-label ${isActive ? 'yes' : 'no'}">${isActive ? 'დიახ' : 'არა'}</span></span>
        </div>
        <div class="detail-item full">
          <span class="lbl">აქტიურობის ისტორია</span>
          <span class="val">${renderActiveHistory(c)}</span>
        </div>
        <div class="detail-item">
          <span class="lbl">ხელშეკრულების გენერაცია</span>
          <span class="val">${c.contractGeneratedAt ? formatDate(c.contractGeneratedAt.slice(0, 10)) : '—'}</span>
        </div>
        <div class="detail-item full">
          <span class="lbl">ხელმოწერილი ხელშეკრულება</span>
          <span class="val">${renderSignedContractsDetail(c)}</span>
        </div>
      `;

      $('record-detail-body').querySelectorAll('[data-detail-view-signed]').forEach(btn => {
        btn.addEventListener('click', e => {
          e.stopPropagation();
          viewSignedContract(btn.dataset.detailViewSigned, btn.dataset.docId);
        });
      });

      $('record-detail-modal').classList.add('open');
    }

    function closeRecordDetailModal() {
      recordDetailId = null;
      $('record-detail-modal').classList.remove('open');
    }

    function getRegistryGroupKey(c) {
      return getAgentKey(c);
    }

    function groupRegistryContracts(items) {
      const groups = new Map();
      const order = [];
      items.forEach(c => {
        const key = getRegistryGroupKey(c);
        if (!groups.has(key)) {
          groups.set(key, { key, items: [] });
          order.push(key);
        }
        groups.get(key).items.push(c);
      });
      return order.map(key => {
        const group = groups.get(key);
        group.items.sort((a, b) => String(a.project || '').localeCompare(String(b.project || ''), 'ka'));
        return group;
      });
    }

    function getAgentActiveState(items) {
      const activeCount = items.filter(c => c.active !== false).length;
      if (activeCount === items.length) {
        return { checked: true, indeterminate: false, label: 'დიახ', labelClass: 'yes' };
      }
      if (activeCount === 0) {
        return { checked: false, indeterminate: false, label: 'არა', labelClass: 'no' };
      }
      return { checked: false, indeterminate: true, label: 'შერეული', labelClass: '' };
    }

    function renderRegistryActiveCell(c, opts, groupItems) {
      const { isFirstInGroup, groupSize } = opts;
      if (groupSize > 1) {
        if (!isFirstInGroup) return '';
        const state = getAgentActiveState(groupItems);
        const agentKey = getAgentKey(c);
        return `
          <td class="cell-center registry-active-cell" rowspan="${groupSize}">
            <label class="toggle-switch" title="აქტიური / არააქტიური (ყველა პროექტი)">
              <input type="checkbox" data-toggle-agent="${esc(agentKey)}" ${state.checked ? 'checked' : ''} />
              <span class="toggle-slider"></span>
            </label>
            <span class="active-label ${state.labelClass}">${state.label}</span>
          </td>`;
      }
      const isActive = c.active !== false;
      return `
          <td class="cell-center">
            <label class="toggle-switch" title="აქტიური / არააქტიური">
              <input type="checkbox" data-toggle-active="${c.id}" ${isActive ? 'checked' : ''} />
              <span class="toggle-slider"></span>
            </label>
            <span class="active-label ${isActive ? 'yes' : 'no'}">${isActive ? 'დიახ' : 'არა'}</span>
          </td>`;
    }

    function renderRegistryAgencyCell(c, groupSize) {
      const meta = groupSize > 1
        ? `<div class="registry-agency-meta">${groupSize} პროექტი</div>`
        : '';
      const rowspan = groupSize > 1 ? ` rowspan="${groupSize}"` : '';
      return `
        <td class="registry-agency-cell"${rowspan}>
          <div class="registry-agency-wrap">
            <div class="registry-agency-name">${esc(c.agency)}</div>
            ${meta}
          </div>
        </td>`;
    }

    function renderRegistryProjectRow(c, opts, groupItems) {
      const isActive = c.active !== false;
      const { isFirstInGroup, groupSize, groupAlt } = opts;
      const rowClass = [
        'registry-group-row',
        isFirstInGroup ? 'registry-group-first' : 'registry-group-next',
        groupSize > 1 ? 'registry-group-multi' : '',
        groupAlt ? 'registry-group-alt' : '',
        editingId === c.id ? 'editing' : '',
        !isActive ? 'row-inactive' : '',
        isContractExpiringWithinMonth(c) ? 'row-expiring' : '',
      ].filter(Boolean).join(' ');

      return `
        <tr class="${rowClass}" data-id="${c.id}">
          ${isFirstInGroup ? renderRegistryAgencyCell(c, groupSize) : ''}
          <td class="registry-project-cell">${esc(c.project) || '—'}</td>
          <td class="num">${formatCommissionLabel(c)}</td>
          <td class="num">${formatCreditPct(c.creditPct)}</td>
          <td class="cell-center registry-gen-cell">${renderGenerateCell(c)}</td>
          ${renderRegistryActiveCell(c, opts, groupItems)}
          <td>${renderSignedContractsCell(c)}</td>
          <td class="actions-cell">
            <button class="btn-link accent" data-add-project="${c.id}" title="სხვა პროექტის დამატება">+ პროექტი</button>
            <button class="btn-link" data-edit="${c.id}">რედაქტირება</button>
            ${isCurrentUserAdmin()
              ? `<button class="btn-link danger" data-delete="${esc(c.id)}" title="ამ ჩანაწერის წაშლა">წაშლა</button>`
              : ''}
          </td>
        </tr>`;
    }

    function renderAgentsTable() {
      const filtered = getFiltered();
      const tbody = $('agents-table');

      if (!filtered.length) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="8">${contracts.length ? 'ფილტრმა ჩანაწერი ვერ მოიძებნა' : 'ჯერ არ არის დარეგისტრირებული აგენტი'}</td></tr>`;
        return;
      }

      const groups = groupRegistryContracts(filtered);
      tbody.innerHTML = groups.map((group, groupIndex) => {
        const groupSize = group.items.length;
        const groupAlt = groupIndex % 2 === 1;
        return group.items.map((c, index) =>
          renderRegistryProjectRow(c, {
            isFirstInGroup: index === 0,
            groupSize,
            groupAlt,
          }, group.items)
        ).join('');
      }).join('');

      tbody.querySelectorAll('[data-add-project]').forEach(btn => {
        btn.addEventListener('click', () => openAddProjectModal(btn.dataset.addProject));
      });
      tbody.querySelectorAll('[data-edit]').forEach(btn => {
        btn.addEventListener('click', () => startEdit(btn.dataset.edit));
      });
      tbody.querySelectorAll('[data-delete]').forEach(btn => {
        btn.addEventListener('click', () => openDeleteModal(btn.dataset.delete));
      });
      tbody.querySelectorAll('[data-generate]').forEach(btn => {
        btn.addEventListener('click', () => generateContract(btn.dataset.generate));
      });
      tbody.querySelectorAll('[data-toggle-active]').forEach(inp => {
        inp.addEventListener('change', () => {
          const id = inp.dataset.toggleActive;
          const c = contracts.find(x => x.id === id);
          if (!c) return;
          const currentActive = c.active !== false;
          const newActive = inp.checked;
          if (newActive === currentActive) return;
          inp.checked = currentActive;
          openActiveStatusModal(id, newActive);
        });
      });
      tbody.querySelectorAll('[data-toggle-agent]').forEach(inp => {
        const agentKey = inp.dataset.toggleAgent;
        const items = contracts.filter(c => getAgentKey(c) === agentKey);
        const state = getAgentActiveState(items);
        inp.indeterminate = state.indeterminate;
        inp.addEventListener('change', () => {
          const newActive = inp.checked;
          inp.checked = state.checked;
          inp.indeterminate = state.indeterminate;
          if (state.indeterminate || newActive !== state.checked) {
            openActiveStatusModalForAgent(items[0], newActive);
          }
        });
      });
      tbody.querySelectorAll('[data-upload-signed]').forEach(btn => {
        btn.addEventListener('click', () => openSignedUpload(btn.dataset.uploadSigned));
      });
      tbody.querySelectorAll('[data-view-signed]').forEach(btn => {
        btn.addEventListener('click', e => {
          e.stopPropagation();
          viewSignedContract(btn.dataset.viewSigned, btn.dataset.docId);
        });
      });
      tbody.querySelectorAll('tr[data-id]').forEach(row => {
        row.addEventListener('click', e => {
          if (e.target.closest('button, a, label, input, .btn-link, .signed-name')) return;
          openRecordDetailModal(row.dataset.id);
        });
      });
    }

    function openAddProjectModal(id) {
      const c = findContractById(id);
      if (!c) {
        toast('აგენტის ჩანაწერი ვერ მოიძებნა — სცადეთ გვერდის განახლება', 'error');
        return;
      }

      addProjectSource = { agency: c.agency, idCode: c.idCode, bank: c.bank, phones: [...(c.phones || [])], email: c.email || '', contactPerson: c.contactPerson || '' };
      const existing = getAgentContractsFor(c);

      $('add-project-preview').innerHTML = `
        <dt>სააგენტო</dt><dd>${esc(c.agency)}</dd>
        <dt>საიდენტიფიკაციო კოდი</dt><dd>${esc(c.idCode)}</dd>
        <dt>საბანკო რეკვიზიტები</dt><dd>${esc(c.bank)}</dd>
        <dt>მობ. ტელეფონი</dt><dd>${c.phones?.length ? esc(c.phones.join(', ')) : '—'}</dd>
        <dt>საკონტაქტო</dt><dd>${c.contactPerson ? esc(c.contactPerson) : '—'}</dd>
        <dt>მეილი</dt><dd>${c.email ? esc(c.email) : '—'}</dd>
      `;

      const existingWrap = $('add-project-existing-wrap');
      const existingEl = $('add-project-existing');
      if (existing.length) {
        existingWrap.style.display = 'block';
        existingEl.innerHTML = existing.map(x =>
          `<span class="project-tag">${esc(x.project)} · ${formatCommissionLabel(x)}</span>`
        ).join('');
      } else {
        existingWrap.style.display = 'none';
      }

      const available = getAvailableProjectsForAgent(c);
      const select = $('ap-project');
      if (!available.length) {
        select.innerHTML = '<option value="">ყველა პროექტი უკვე მინიჭებულია</option>';
        select.disabled = true;
      } else {
        select.disabled = false;
        select.innerHTML = `<option value="">აირჩიეთ პროექტი…</option>${available.map(p =>
          `<option value="${esc(p)}">${esc(p)}</option>`
        ).join('')}`;
      }

      const copyWrap = $('add-project-copy-wrap');
      const copySelect = $('ap-copy-from');
      const copyBtn = $('ap-copy-btn');
      const sources = listAgentsWithProjects(getAgentKey(c));
      if (sources.length) {
        copyWrap.style.display = 'block';
        copySelect.innerHTML = sources.map(s =>
          `<option value="${esc(s.key)}">${esc(s.name)} — ${s.count} პროექტი</option>`
        ).join('');
        copyBtn.textContent = 'პროექტების კოპირება';
        copyBtn.disabled = false;
      } else {
        copyWrap.style.display = 'none';
      }

      $('add-project-form').reset();
      setSelectedDocType('contract', 'apDocType');
      syncApDocTypeFields();
      clearAddProjectErrors();
      $('add-project-modal').classList.add('open');
    }

    function closeAddProjectModal() {
      addProjectSource = null;
      $('add-project-modal').classList.remove('open');
      $('ap-project').disabled = false;
    }

    function clearAddProjectErrors() {
      ['ap-project', 'ap-commission', 'ap-commissionAmount', 'ap-creditPct', 'ap-signedDate', 'ap-validUntil'].forEach(id => {
        const el = $(id);
        if (el) el.classList.remove('error');
      });
      ['err-ap-project', 'err-ap-commission', 'err-ap-commissionAmount', 'err-ap-creditPct', 'err-ap-signedDate', 'err-ap-validUntil'].forEach(id => {
        const el = $(id);
        if (el) el.classList.remove('show');
      });
      $('err-ap-validUntil').textContent = 'აირჩიეთ ვადა';
    }

    function validateAddProject() {
      clearAddProjectErrors();
      if (!addProjectSource) return null;

      let ok = true;
      const project = $('ap-project').value;
      const docType = getSelectedDocType('apDocType');
      const creditPct = parseFloat($('ap-creditPct').value);
      const signedDate = $('ap-signedDate').value;
      const validUntil = $('ap-validUntil').value;
      let commission;

      if (!project) {
        $('ap-project').classList.add('error');
        $('err-ap-project').classList.add('show');
        ok = false;
      }
      if (docType === 'one_time') {
        commission = parseFloat($('ap-commissionAmount').value);
        if (isNaN(commission) || commission <= 0) {
          $('ap-commissionAmount').classList.add('error');
          $('err-ap-commissionAmount').classList.add('show');
          ok = false;
        }
      } else {
        commission = parseFloat($('ap-commission').value);
        if (isNaN(commission) || commission < 0.1 || commission > 100) {
          $('ap-commission').classList.add('error');
          $('err-ap-commission').classList.add('show');
          ok = false;
        }
      }
      if (isNaN(creditPct) || creditPct < 0.1 || creditPct > 100) {
        $('ap-creditPct').classList.add('error');
        $('err-ap-creditPct').classList.add('show');
        ok = false;
      }
      if (signedDate && validUntil && validUntil < signedDate) {
        $('ap-validUntil').classList.add('error');
        $('err-ap-validUntil').textContent = 'ვადა უნდა იყოს გაფორმების შემდეგ';
        $('err-ap-validUntil').classList.add('show');
        ok = false;
      }
      const agentKey = getAgentKey(addProjectSource);
      if (project && contracts.some(c => getAgentKey(c) === agentKey && c.project === project)) {
        $('ap-project').classList.add('error');
        $('err-ap-project').textContent = 'ამ აგენტს უკვე აქვს ხელშეკრულება ამ პროექტზე';
        $('err-ap-project').classList.add('show');
        ok = false;
      } else {
        $('err-ap-project').textContent = 'აირჩიეთ პროექტი';
      }

      if (!ok) return null;
      return { ...addProjectSource, project, commission, creditPct, signedDate, validUntil, docType };
    }

    async function generateContract(id) {
      const c = contracts.find(x => x.id === id);
      if (!c) return;
      if (!canGenerateContract(c)) {
        toast('არააქტიურ ჩანაწერზე ხელშეკრულების გენერაცია შეუძლებელია', 'error');
        return;
      }

      try {
        await downloadContractDocx(c);
      } catch (error) {
        console.error('Contract generation failed:', error);
        toast(error?.message || String(error) || 'ხელშეკრულების გენერაცია ვერ მოხერხდა', 'error');
        return;
      }

      c.contractGeneratedAt = new Date().toISOString();
      saveData();
      if (bitrixReady && c.bitrixId) {
        bitrixUpdateContract(c).catch(error => {
          toast(`Bitrix განახლება ვერ მოხერხდა: ${error.message}`, 'error');
        });
      }
      const scroll = captureScrollState();
      updateStats();
      if (!updateRegistryRow(id)) renderAgentsTable();
      restoreScrollState(scroll);
      toast('ხელშეკრულება გენერირდა და ჩამოიტვირთა');
    }

    function openActiveStatusModal(id, newActive, kind = 'contract') {
      activeStatusTarget = { kind, id, active: newActive };
      if (kind === 'project') {
        const p = projects.find(x => x.id === id);
        if (!p) return;
        $('active-status-title').textContent = newActive ? 'აქტიურზე დაბრუნება' : 'არააქტიურზე გადატანა';
        $('active-status-sub').textContent = `პროექტი: ${p.name}`;
      } else {
        const c = contracts.find(x => x.id === id);
        if (!c) return;
        $('active-status-title').textContent = newActive ? 'აქტიურზე დაბრუნება' : 'არააქტიურზე გადატანა';
        $('active-status-sub').textContent = `${c.agency} · ${c.project}`;
      }
      $('active-status-comment').value = '';
      $('err-active-status-comment').classList.remove('show');
      $('active-status-modal').classList.add('open');
      $('active-status-comment').focus();
    }

    function openActiveStatusModalForAgent(c, newActive) {
      const items = getAgentContractsFor(c);
      if (!items.length) return;
      activeStatusTarget = {
        kind: 'agent',
        agentKey: getAgentKey(c),
        ids: items.map(x => x.id),
        active: newActive,
      };
      $('active-status-title').textContent = newActive ? 'აქტიურზე დაბრუნება' : 'არააქტიურზე გადატანა';
      $('active-status-sub').textContent = `${c.agency} · ${items.length} პროექტი`;
      $('active-status-comment').value = '';
      $('err-active-status-comment').classList.remove('show');
      $('active-status-modal').classList.add('open');
      $('active-status-comment').focus();
    }

    function closeActiveStatusModal() {
      activeStatusTarget = null;
      $('active-status-modal').classList.remove('open');
    }

    function confirmActiveStatusChange() {
      if (!activeStatusTarget) return;
      const comment = $('active-status-comment').value.trim();
      if (!comment) {
        $('err-active-status-comment').classList.add('show');
        return;
      }

      if (activeStatusTarget.kind === 'project') {
        const p = projects.find(x => x.id === activeStatusTarget.id);
        if (!p) return;
        p.active = activeStatusTarget.active;
        if (!Array.isArray(p.activeHistory)) p.activeHistory = [];
        p.activeHistory.push({
          active: activeStatusTarget.active,
          comment,
          changedAt: new Date().toISOString(),
        });
        saveProjects();
        const becameActive = activeStatusTarget.active;
        closeActiveStatusModal();
        refresh();
        toast(becameActive ? 'პროექტი აქტიურია' : 'პროექტი არააქტიურია');
        return;
      }

      if (activeStatusTarget.kind === 'agent') {
        const ids = activeStatusTarget.ids || [];
        const becameActive = activeStatusTarget.active;
        ids.forEach(id => {
          const c = contracts.find(x => x.id === id);
          if (!c) return;
          c.active = activeStatusTarget.active;
          if (!Array.isArray(c.activeHistory)) c.activeHistory = [];
          c.activeHistory.push({
            active: activeStatusTarget.active,
            comment,
            changedAt: new Date().toISOString(),
          });
          if (bitrixReady && c.bitrixId) {
            bitrixUpdateContract(c).catch(error => {
              toast(`Bitrix განახლება ვერ მოხერხდა: ${error.message}`, 'error');
            });
          }
        });
        saveData();
        closeActiveStatusModal();
        const scroll = captureScrollState();
        updateStats();
        renderAgentsTable();
        restoreScrollState(scroll);
        toast(becameActive ? 'აგენტის ყველა პროექტი აქტიურია' : 'აგენტის ყველა პროექტი არააქტიურია');
        return;
      }

      const c = contracts.find(x => x.id === activeStatusTarget.id);
      if (!c) return;
      c.active = activeStatusTarget.active;
      if (!Array.isArray(c.activeHistory)) c.activeHistory = [];
      c.activeHistory.push({
        active: activeStatusTarget.active,
        comment,
        changedAt: new Date().toISOString(),
      });
      saveData();
      if (bitrixReady && c.bitrixId) {
        bitrixUpdateContract(c).catch(error => {
          toast(`Bitrix განახლება ვერ მოხერხდა: ${error.message}`, 'error');
        });
      }
      const id = activeStatusTarget.id;
      const becameActive = activeStatusTarget.active;
      closeActiveStatusModal();
      const scroll = captureScrollState();
      updateStats();
      if (!updateRegistryRow(id)) renderAgentsTable();
      restoreScrollState(scroll);
      toast(becameActive ? 'ჩანაწერი აქტიურია' : 'ჩანაწერი არააქტიურია');
    }

    function openSignedUpload(id) {
      uploadTargetId = id;
      $('signed-file-input').value = '';
      $('signed-file-input').click();
    }

    function viewSignedContract(contractId, docId) {
      const c = contracts.find(x => x.id === contractId);
      if (!c) return;
      const docs = getSignedContracts(c);
      const doc = docId ? docs.find(d => d.id === docId) : docs[docs.length - 1];
      if (!doc?.data) {
        toast('ფაილი ვერ მოიძებნა', 'error');
        return;
      }
      const w = window.open();
      if (doc.data.startsWith('data:application/pdf')) {
        w.document.write(`<iframe src="${doc.data}" style="width:100%;height:100%;border:none"></iframe>`);
      } else if (doc.data.startsWith('data:image')) {
        w.document.write(`<img src="${doc.data}" style="max-width:100%">`);
      } else {
        const a = document.createElement('a');
        a.href = doc.data;
        a.download = doc.name || 'ხელმოწერილი_ხელშეკრულება';
        a.click();
      }
    }

    function refresh(preserveScroll = true) {
      const scroll = preserveScroll ? captureScrollState() : null;
      updateStats();
      populateProjectSelects();
      renderProjectsTable();
      renderAgentsTable();
      restoreScrollState(scroll);
    }

    function clearErrors() {
      document.querySelectorAll('.field-error').forEach(e => e.classList.remove('show'));
      document.querySelectorAll('.field input, .field select, .field textarea').forEach(e => e.classList.remove('error'));
    }

    function showError(fieldId, errId) {
      $(fieldId).classList.add('error');
      $(errId).classList.add('show');
    }

    function getSelectedDocType(radioName = 'docType') {
      const el = document.querySelector(`input[name="${radioName}"]:checked`);
      return el?.value === 'one_time' ? 'one_time' : 'contract';
    }

    function setSelectedDocType(value, radioName = 'docType') {
      const target = value === 'one_time' ? 'one_time' : 'contract';
      document.querySelectorAll(`input[name="${radioName}"]`).forEach(input => {
        input.checked = input.value === target;
      });
    }

    function syncDocTypeFields() {
      const oneTime = getSelectedDocType('docType') === 'one_time';
      const pctField = $('commission-pct-field');
      const amountField = $('commission-amount-field');
      if (pctField) pctField.hidden = oneTime;
      if (amountField) amountField.hidden = !oneTime;
      updatePreview();
    }

    function syncApDocTypeFields() {
      const oneTime = getSelectedDocType('apDocType') === 'one_time';
      const pctField = $('ap-commission-pct-field');
      const amountField = $('ap-commission-amount-field');
      if (pctField) pctField.hidden = oneTime;
      if (amountField) amountField.hidden = !oneTime;
    }

    function validate() {
      clearErrors();
      let ok = true;

      const agency = $('agency').value.trim();
      const idCode = $('idCode').value.trim();
      const bank = $('bank').value.trim();
      const email = $('email').value.trim();
      const contactPerson = $('contactPerson').value.trim();
      const phones = getPhonesFromForm();
      const project = $('project').value;
      const docType = getSelectedDocType('docType');
      const creditPct = parseFloat($('creditPct').value);
      const signedDate = $('signedDate').value;
      const validUntil = $('validUntil').value;
      let commission;

      if (!agency) { showError('agency', 'err-agency'); ok = false; }
      if (idCode && !isValidIdCode(idCode)) { showError('idCode', 'err-idCode'); ok = false; }
      if (email && !isValidEmail(email)) { showError('email', 'err-email'); ok = false; }

      if (docType === 'one_time') {
        commission = parseFloat($('commissionAmount').value);
        if (isNaN(commission) || commission <= 0) {
          showError('commissionAmount', 'err-commissionAmount'); ok = false;
        }
      } else {
        commission = parseFloat($('commission').value);
        if (isNaN(commission) || commission < 0.1 || commission > 100) {
          showError('commission', 'err-commission'); ok = false;
        }
      }

      if (isNaN(creditPct) || creditPct < 0.1 || creditPct > 100) {
        showError('creditPct', 'err-creditPct'); ok = false;
      }
      if (!project) {
        showError('project', 'err-project');
        $('err-project').textContent = 'აირჩიეთ პროექტი';
        ok = false;
      }
      if (signedDate && validUntil && validUntil < signedDate) {
        showError('validUntil', 'err-validUntil');
        $('err-validUntil').textContent = 'მოქმედების ვადა უნდა იყოს გაფორმების თარიღის შემდეგ';
        ok = false;
      } else {
        $('err-validUntil').textContent = 'მოქმედების ვადა უნდა იყოს გაფორმების თარიღის შემდეგ';
      }

      const duplicate = project
        ? contracts.find(c =>
          getAgentKey(c) === getAgentKey({ idCode, agency }) &&
          c.project === project &&
          c.id !== editingId
        )
        : null;
      if (duplicate) {
        showError('project', 'err-project');
        $('err-project').textContent = 'ამ აგენტს უკვე აქვს ხელშეკრულება ამ პროექტზე';
        ok = false;
      }

      return ok ? { agency, idCode, bank, phones, email, contactPerson, project, commission, creditPct, signedDate, validUntil, docType } : null;
    }

    function resetForm() {
      const wasEditing = editingId !== null;
      suppressFormResetHandler = true;
      $('agent-form').reset();
      suppressFormResetHandler = false;
      editingId = null;
      $('edit-id').value = '';
      $('form-title').textContent = 'აგენტის რეგისტრაცია';
      $('form-mode-tag').textContent = 'ახალი';
      $('submit-btn').textContent = 'აგენტის რეგისტრაცია';
      $('cancel-edit').style.display = 'none';
      $('autofill-hint').classList.remove('show');
      $('preview').classList.remove('show');
      renderPhoneFields(['']);
      $('email').value = '';
      $('contactPerson').value = '';
      $('commissionAmount').value = '';
      setSelectedDocType('contract', 'docType');
      syncDocTypeFields();
      clearErrors();
      if (wasEditing) updateEditingHighlight();
    }

    function startEdit(id) {
      const c = findContractById(id);
      if (!c) return;

      editingId = id;
      $('edit-id').value = id;
      $('agency').value = c.agency;
      $('idCode').value = c.idCode;
      $('bank').value = c.bank;
      renderPhoneFields(c.phones?.length ? c.phones : ['']);
      $('email').value = c.email || '';
      $('contactPerson').value = c.contactPerson || '';
      $('project').value = c.project;
      const oneTime = isOneTimeDoc(c);
      setSelectedDocType(oneTime ? 'one_time' : 'contract', 'docType');
      if (oneTime) {
        $('commissionAmount').value = c.commission;
        $('commission').value = '';
      } else {
        $('commission').value = c.commission;
        $('commissionAmount').value = '';
      }
      $('creditPct').value = c.creditPct !== '' && c.creditPct != null ? c.creditPct : '';
      $('signedDate').value = c.signedDate || '';
      $('validUntil').value = c.validUntil || '';
      syncDocTypeFields();

      $('form-title').textContent = 'ხელშეკრულების რედაქტირება';
      $('form-mode-tag').textContent = 'რედაქტირება';
      $('submit-btn').textContent = 'შენახვა';
      $('cancel-edit').style.display = 'inline-block';
      updatePreview();
      switchTab('register');
      updateEditingHighlight();
    }

    function tryAutofill() {
      const idCode = $('idCode').value.trim();
      if (!isValidIdCode(idCode)) {
        $('autofill-hint').classList.remove('show');
        return;
      }
      const existing = contracts.find(c => c.idCode === idCode);
      if (existing && !editingId) {
        $('agency').value = existing.agency;
        $('bank').value = existing.bank;
        renderPhoneFields(existing.phones?.length ? [...existing.phones] : ['']);
        $('email').value = existing.email || '';
        $('contactPerson').value = existing.contactPerson || '';
        $('autofill-hint').classList.add('show');
      } else {
        $('autofill-hint').classList.remove('show');
      }
    }

    function updatePreview() {
      const project = $('project').value;
      const docType = getSelectedDocType('docType');
      const commission = docType === 'one_time'
        ? $('commissionAmount').value
        : $('commission').value;
      const creditPct = $('creditPct').value;
      const preview = $('preview');
      if (project && commission && creditPct) {
        const commissionLabel = docType === 'one_time'
          ? `საკომისიო: $${commission}`
          : `საკომისიო: ${commission}%`;
        const typeLabel = docType === 'one_time' ? 'ერთჯერადი' : 'ხელშეკრულება';
        preview.textContent = `${project} · ${typeLabel} · ${commissionLabel} · ჩასარიცხი: ${creditPct}%`;
        preview.classList.add('show');
      } else {
        preview.classList.remove('show');
      }
    }

    function openDeleteModal(id) {
      if (!isCurrentUserAdmin()) return;
      const c = contracts.find(x => x.id === id);
      if (!c) return;
      deleteTargetId = id;
      $('delete-modal-text').textContent =
        `ნამდვილად გსურთ ამ ჩანაწერის წაშლა: „${c.agency}" — ${c.project}?`;
      $('delete-modal').classList.add('open');
    }

    function closeDeleteModal() {
      deleteTargetId = null;
      $('delete-modal').classList.remove('open');
    }

    function exportExcel() {
      const headers = ['სააგენტო', 'ს/კ', 'საკონტაქტო', 'ტელეფონი', 'მეილი', 'პროექტი', 'დოკ. ტიპი', 'საკომისიო', 'ჩასარიცხი თანხა', 'რეკვიზიტები', 'გაფორმების თარიღი', 'მოქმედების ვადა', 'აქტიური', 'გენერაციის თარიღი', 'ხელმოწერილი ფაილი'];
      const rows = getFiltered().map(c => [
        c.agency, c.idCode, c.contactPerson || '', (c.phones || []).join('; '), c.email || '',
        c.project,
        isOneTimeDoc(c) ? 'ერთჯერადი შეთანხმება' : 'ხელშეკრულება',
        formatCommissionLabel(c),
        c.creditPct !== '' && c.creditPct != null ? c.creditPct : '', c.bank,
        formatDate(c.signedDate), formatDate(c.validUntil),
        c.active !== false ? 'დიახ' : 'არა',
        c.contractGeneratedAt ? formatDate(c.contractGeneratedAt.slice(0, 10)) : '',
        getSignedContracts(c).map(d => d.name).join('; '),
      ]);
      downloadExcel(`agents-registry-${new Date().toISOString().slice(0, 10)}.xls`, headers, rows, [8]);
      toast('Excel ფაილი ჩამოიტვირთა');
    }

    // ── Events ──
    populateProjectSelects();
    renderPhoneFields(['']);

    $('add-phone').addEventListener('click', () => {
      const row = document.createElement('div');
      row.className = 'phone-row';
      row.innerHTML = `
        <input type="tel" class="phone-input" placeholder="5XX XX XX XX" inputmode="tel" />
        <button type="button" class="btn btn-ghost btn-sm phone-remove" title="წაშლა">×</button>
      `;
      $('phones-list').appendChild(row);
      bindPhoneRowEvents();
    });

    $('agent-form').addEventListener('submit', async e => {
      e.preventDefault();
      const data = validate();
      if (!data) {
        toast('შეამოწმეთ ფორმის ველები — რეგისტრაცია ვერ მოხერხდა', 'error');
        return;
      }

      const wasEditing = !!editingId;
      const submitBtn = $('submit-btn');
      const submitLabel = submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = wasEditing ? 'ინახება…' : 'ინახება Bitrix-ში…';

      try {
        if (editingId) {
          const idx = contracts.findIndex(c => c.id === editingId);
          if (idx !== -1) {
            const updated = { ...contracts[idx], ...data };
            await syncContractToBitrix(updated, false);
            contracts[idx] = updated;
            toast('ცვლილებები შენახულია');
          }
        } else {
          const contract = {
            id: uid(),
            ...data,
            active: true,
            contractGeneratedAt: '',
            signedContracts: [],
            activeHistory: [],
          };
          await syncContractToBitrix(contract, true);
          contracts.unshift(contract);
          toast('აგენტი დარეგისტრირდა და Bitrix-ში შეინახა');
        }

        saveData();
        if (document.activeElement instanceof HTMLElement) {
          document.activeElement.blur();
        }
        resetForm();
        if (!wasEditing) resetFilterValues();
        refresh();
        if (wasEditing) {
          switchTab('registry');
        } else {
          switchTab('register');
        }
      } catch (error) {
        toast(`Bitrix შენახვა ვერ მოხერხდა: ${error.message}`, 'error');
      } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = submitLabel;
      }
    });

    $('agent-form').addEventListener('reset', () => {
      if (suppressFormResetHandler) return;
      setTimeout(resetForm, 0);
    });

    $('cancel-edit').addEventListener('click', resetForm);
    $('idCode').addEventListener('input', tryAutofill);
    $('project').addEventListener('change', updatePreview);
    $('commission').addEventListener('input', updatePreview);
    $('commissionAmount').addEventListener('input', updatePreview);
    $('creditPct').addEventListener('input', updatePreview);
    document.querySelectorAll('input[name="docType"]').forEach(input => {
      input.addEventListener('change', syncDocTypeFields);
    });
    document.querySelectorAll('input[name="apDocType"]').forEach(input => {
      input.addEventListener('change', syncApDocTypeFields);
    });
    syncDocTypeFields();
    syncApDocTypeFields();
    ['filter-agency', 'filter-idCode', 'filter-commission'].forEach(id => {
      $(id).addEventListener('input', refresh);
    });
    ['filter-project', 'filter-active'].forEach(id => {
      $(id).addEventListener('change', refresh);
    });
    $('filter-clear').addEventListener('click', clearFilters);
    initProjectFilters();
    $('registry-export').addEventListener('click', exportExcel);
    $('report-search').addEventListener('click', () => renderReportTable());
    $('report-reload').addEventListener('click', () => reloadReportDeals());
    $('report-export').addEventListener('click', exportReportExcel);
    initReportRowHighlight();
    initReportSort();
    initReportClientSuggest();
    $('report-table').addEventListener('change', async e => {
      const settle = e.target.closest('[data-settle-deal]');
      if (!settle) return;
      const dealId = settle.dataset.settleDeal;
      const settled = !!settle.checked;
      settle.disabled = true;
      try {
        await setDealCommissionSettled(dealId, settled);
        const deal = reportDeals.find(d => String(d.leadId) === String(dealId));
        if (deal) {
          deal.commissionSettled = settled;
          const rawDiff = Math.round(Number(deal.payable) || 0) - Math.round(Number(deal.paidCommission) || 0);
          deal.difference = settled ? 0 : rawDiff;
        }
        renderReportTable();
      } finally {
        settle.disabled = false;
      }
    });
    $('deal-back-btn').addEventListener('click', () => switchTab('stats'));
    ['report-date-from', 'report-date-to', 'report-filter-project'].forEach(id => {
      $(id).addEventListener('change', renderReportTable);
    });
    $('report-filter-agency').addEventListener('input', renderReportTable);
    $('report-filter-client').addEventListener('input', () => {
      scheduleReportClientSuggest();
      renderReportTable();
    });

    $('signed-file-input').addEventListener('change', e => {
      const file = e.target.files[0];
      if (!file || !uploadTargetId) return;
      if (file.size > 3 * 1024 * 1024) {
        toast('ფაილი ძალიან დიდია (მაქს. 3 MB)', 'error');
        uploadTargetId = null;
        return;
      }
      const reader = new FileReader();
      reader.onload = () => {
        const c = contracts.find(x => x.id === uploadTargetId);
        if (c) {
          if (!Array.isArray(c.signedContracts)) c.signedContracts = migrateSignedContracts(c);
          c.signedContracts.push({
            id: uid(),
            name: file.name,
            data: reader.result,
            uploadedAt: new Date().toISOString(),
          });
          saveData();
          const scroll = captureScrollState();
          updateStats();
          renderAgentsTable();
          restoreScrollState(scroll);
          toast('დოკუმენტი ატვირთულია');
        }
        uploadTargetId = null;
      };
      reader.readAsDataURL(file);
    });

    $('add-project-cancel').addEventListener('click', closeAddProjectModal);
    $('ap-copy-btn').addEventListener('click', async () => {
      const sourceKey = $('ap-copy-from').value;
      if (!sourceKey || !addProjectSource) return;
      const btn = $('ap-copy-btn');
      const label = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'ინახება…';
      try {
        const count = await copyProjectsFromAgent(sourceKey);
        if (!count) {
          toast('ყველა პროექტი უკვე მინიჭებულია', 'error');
          return;
        }
        closeAddProjectModal();
        if (document.activeElement instanceof HTMLElement) {
          document.activeElement.blur();
        }
        refresh();
        toast(`${count} პროექტი დაემატა აგენტს`);
      } catch (error) {
        toast(`Bitrix შენახვა ვერ მოხერხდა: ${error.message}`, 'error');
      } finally {
        btn.disabled = false;
        btn.textContent = label;
      }
    });
    let addProjectOverlayPointerDown = false;
    $('add-project-modal').addEventListener('mousedown', e => {
      addProjectOverlayPointerDown = e.target === $('add-project-modal');
    });
    $('add-project-modal').addEventListener('click', e => {
      if (addProjectOverlayPointerDown && e.target === $('add-project-modal')) {
        closeAddProjectModal();
      }
      addProjectOverlayPointerDown = false;
    });
    $('project-agencies-close').addEventListener('click', closeProjectAgenciesModal);
    $('project-agencies-modal').addEventListener('click', e => {
      if (e.target === $('project-agencies-modal')) closeProjectAgenciesModal();
    });
    $('record-detail-close').addEventListener('click', closeRecordDetailModal);
    $('active-status-cancel').addEventListener('click', closeActiveStatusModal);
    $('active-status-confirm').addEventListener('click', confirmActiveStatusChange);
    $('active-status-modal').addEventListener('click', e => {
      if (e.target === $('active-status-modal')) closeActiveStatusModal();
    });
    $('record-detail-modal').addEventListener('click', e => {
      if (e.target === $('record-detail-modal')) closeRecordDetailModal();
    });
    $('record-detail-edit').addEventListener('click', () => {
      if (recordDetailId) {
        const id = recordDetailId;
        closeRecordDetailModal();
        startEdit(id);
      }
    });

    $('project-register-form').addEventListener('submit', e => {
      e.preventDefault();
      const data = validateProjectRegister();
      if (!data) return;

      if (editingProjectId) {
        const p = projects.find(x => x.id === editingProjectId);
        if (!p) return;
        const oldName = p.name;
        p.name = data.name;
        p.idCode = data.idCode;
        p.director = data.director;
        if (oldName !== data.name) {
          contracts.forEach(c => {
            if (c.project === oldName) c.project = data.name;
          });
          reportDeals.forEach(d => {
            if (d.project === oldName) d.project = data.name;
          });
          saveData();
        }
        saveProjects();
        resetProjectForm();
        refresh();
        toast(`პროექტი „${data.name}" განახლდა`);
        return;
      }

      projects.push({
        id: uid(),
        name: data.name,
        idCode: data.idCode,
        director: data.director,
        active: true,
        activeHistory: [],
      });
      saveProjects();
      resetProjectForm();
      refresh();
      toast(`პროექტი „${data.name}" დარეგისტრირდა`);
    });
    $('pr-reset').addEventListener('click', resetProjectForm);
    $('pr-cancel-edit').addEventListener('click', resetProjectForm);

    $('add-project-form').addEventListener('submit', async e => {
      e.preventDefault();
      const data = validateAddProject();
      if (!data) {
        toast('შეამოწმეთ პროექტის ველები', 'error');
        return;
      }

      const submitBtn = e.submitter || e.target.querySelector('[type="submit"]');
      const submitLabel = submitBtn?.textContent || 'დამატება';
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'ინახება…';
      }

      try {
        const contract = {
          id: uid(),
          ...data,
          active: true,
          contractGeneratedAt: '',
          signedContracts: [],
          activeHistory: [],
        };
        if (bitrixReady) {
          await syncContractToBitrix(contract, true);
        }
        contracts.unshift(contract);
        saveData();
        closeAddProjectModal();
        if (document.activeElement instanceof HTMLElement) {
          document.activeElement.blur();
        }
        refresh();
        toast(`პროექტი „${data.project}" დაემატა აგენტს`);
      } catch (error) {
        toast(`Bitrix შენახვა ვერ მოხერხდა: ${error.message}`, 'error');
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = submitLabel;
        }
      }
    });

    $('delete-cancel').addEventListener('click', closeDeleteModal);
    $('delete-confirm').addEventListener('click', async () => {
      if (!deleteTargetId || !isCurrentUserAdmin()) return;
      const target = contracts.find(c => c.id === deleteTargetId);
      if (!target) {
        closeDeleteModal();
        return;
      }
      try {
        if (bitrixReady && target.bitrixId) {
          await bitrixDeleteContract(target.bitrixId);
        }
        contracts = contracts.filter(c => c.id !== deleteTargetId);
        saveData();
        if (editingId === deleteTargetId) resetForm();
        toast('ჩანაწერი წაიშალა');
        closeDeleteModal();
        refresh();
      } catch (error) {
        toast(`Bitrix წაშლა ვერ მოხერხდა: ${error.message}`, 'error');
      }
    });
    $('delete-modal').addEventListener('click', e => {
      if (e.target === $('delete-modal')) closeDeleteModal();
    });

    initApp();
  </script>
</body>
</html>
