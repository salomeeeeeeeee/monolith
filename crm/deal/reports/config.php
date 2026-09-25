<?php

define('REPORT_PRODUCT_IBLOCK', 14);
define('REPORT_SCHEDULE_IBLOCK', 22);
define('REPORT_PAYMENT_IBLOCK', 23);

define('F_PROJECT', '__VO9RG4');
define('F_TYPE', '__X1GCRZ');
define('F_STATUS', '_P64GYD');
define('F_BLOCK', '_L24CUB');
define('F_SECTOR', '_3BU0JH');
define('F_TOTAL_AREA', '__173JA5');
define('F_BEDROOMS', '__KYRP1L');
define('F_KVM_PRICE', '__6ZWTER');
define('F_UNIT_NO', '__6KWOWZ');
define('F_FLOOR', '_FTRIDL');

define('D_PROJECT', 'UF_CRM_1779277729207');
define('D_BLOCK', 'UF_CRM_1779277644355');
define('D_TYPE', 'UF_CRM_1779277898205');
define('D_BEDROOMS', 'UF_CRM_1779277838333');
define('D_CONTRACT_DATE', 'UF_CRM_1779278774084');
define('D_BARTER', 'UF_CRM_1774878761');
define('D_KVM_PRICE', 'UF_CRM_1779277671391');
define('D_BARTER_YES', '420');
define('D_BARTER_NO', '421');

define('REPORT_WON_STAGE', 'WON');
define('REPORT_CASHFLOW_STAGES', ['EXECUTING', 'UC_NSTB3H', 'UC_NJ7A78', 'WON']);
define('REPORT_RESERVED_STATUS', 'დაჯავშნილი');
define('REPORT_RESERVATION_STAGES', ['PREPAYMENT_INVOICE', 'FINAL_INVOICE']);
define('D_RESERVATION_DATE', 'UF_CRM_1779278567041');

define('D_LOSS_REASON_SALES', 'UF_CRM_1775227151');
define('D_LOSS_REASON_DETAIL', 'UF_CRM_1775227164');
define('D_LOSS_REASON_CC', 'UF_CRM_1780473790937');

define('REPORT_MARKETING_COST_IBLOCK', 27);
define('REPORT_DAILO_DAILY_CODE', 'DAILO_STATS_DAILY');
define('REPORT_DAILO_CHANNEL_CODE', 'DAILO_STATS_CHANNEL');

define('REPORT_TYPE_ORDER', [
    'ბინა'               => 0,
    'ბინა (1 საძ.)'      => 1,
    'ბინა (2 საძ.)'      => 2,
    'ბინა (3 საძ.)'      => 3,
    'სტუდიო'             => 4,
    'დუპლექსი'           => 5,
    'შიდა ავტოსადგომი'   => 6,
    'გარე ავტოსადგომი'   => 7,
    'დამხმარე'           => 8,
]);

define('REPORT_APARTMENT_SUBTYPES', ['ბინა (1 საძ.)', 'ბინა (2 საძ.)', 'ბინა (3 საძ.)']);

define('REPORT_STATUSES', ['თავისუფალი', 'დაჯავშნილი', 'გაყიდული', 'NFS']);

// Filter options: one label per value that CRM spells differently (lowercase spelling => label).
// Case and "X /X /X" repeats are merged anyway; list here names that differ otherwise.
define('REPORT_FILTER_LABELS', [
    'dighomi'          => 'Dighomi',
    'ethno city'       => 'Ethno city',
    'green city'       => 'Green city',
    'new depo'         => 'New Depo',
    'მონოლით ნიუ დეპო' => 'New Depo',
]);
