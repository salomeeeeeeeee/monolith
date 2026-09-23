# Broker field duplicate options

## 2026-09-23 — Hide duplicate broker names in entity editor

Deal field `UF_CRM_1785491867` (ბროკერი / სააგენტო) is a list-attached field. List elements can share the same NAME with different IDs.

**Behavior:** in `crm.entity.editor`, duplicate option labels are hidden visually. The currently selected value is kept if it is one of the duplicates; otherwise the first option with that name stays visible. Values are not deleted — only hidden in the dropdown.

**Source:** same logic as tetri-kvadrati field `UF_CRM_1768385598` in `bitrix/components/crm.entity.editor/template.php`.

**Implementation:** `bitrix/component/crm.entity.editor/template.php` — JS IIFE with `window.__dmgBrokerDupHideBound`. Covers native `<select>` and Bitrix `main-ui-select` popup items.
