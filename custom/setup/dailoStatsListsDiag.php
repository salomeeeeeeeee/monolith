<?php
/**
 * დიაგნოსტიკა: რატომ არ ჩანს ველები Lists-ის ინტერფეისში.
 *
 * URL: https://crm.monolith.ge/custom/setup/dailoStatsListsDiag.php
 *
 * ადარებს ახლად შექმნილ სიებს (28, 29) უკვე მომუშავე სიას (26) — თვისებების
 * სვეტებსა და თვით iblock-ის ველებს — და აჩვენებს, რას ხედავს Lists მოდული.
 * მხოლოდ კითხულობს; ერთადერთი ჩარევა — ქეშის გასუფთავება (?clearcache=1).
 */
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php');

@set_time_limit(0);

CModule::IncludeModule('iblock');
$listsModule = CModule::IncludeModule('lists');

global $USER, $APPLICATION;

$APPLICATION->SetTitle('Dailo სტატისტიკის სიები — დიაგნოსტიკა');

if (!is_object($USER) || !$USER->IsAdmin()) {
    echo '<p style="color:#c0392b">ეს გვერდი მხოლოდ ადმინისტრატორისთვისაა.</p>';
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php');
    die();
}

$IBLOCKS = [
    26 => 'ეტალონი — Dailo API log (მუშა სია)',
    28 => 'ახალი — Dailo დღიური სტატისტიკა',
    29 => 'ახალი — Dailo სტატისტიკა არხებით',
];

$clearCache = isset($_GET['clearcache']) && $_GET['clearcache'] === '1';

if ($clearCache) {
    foreach (array_keys($IBLOCKS) as $iblockId) {
        if (isset($GLOBALS['CACHE_MANAGER'])) {
            $GLOBALS['CACHE_MANAGER']->ClearByTag('iblock_id_' . $iblockId);
            $GLOBALS['CACHE_MANAGER']->ClearByTag('iblock_id_new');
        }
    }
    if (isset($GLOBALS['stackCacheManager'])) {
        $GLOBALS['stackCacheManager']->Clear('b_iblock_property');
        $GLOBALS['stackCacheManager']->Clear('b_iblock_element');
    }
    BXClearCache(true);
}

function diagIblockRow($iblockId)
{
    return CIBlock::GetList([], ['ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'])->Fetch() ?: [];
}

function diagProperties($iblockId)
{
    $rows = [];
    $res = CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N']);
    while ($row = $res->Fetch()) {
        $rows[] = $row;
    }
    return $rows;
}

function diagListsFields($iblockId)
{
    if (!class_exists('CList')) {
        return null;
    }
    try {
        $obList = new CList($iblockId);
        $fields = $obList->GetFields();
        return is_array($fields) ? array_keys($fields) : [];
    } catch (Throwable $e) {
        return ['ERROR: ' . $e->getMessage()];
    }
}

$iblockRows = [];
$propertyRows = [];
foreach (array_keys($IBLOCKS) as $iblockId) {
    $iblockRows[$iblockId]   = diagIblockRow($iblockId);
    $propertyRows[$iblockId] = diagProperties($iblockId);
}

// iblock-ის სვეტების შედარება ეტალონთან
$iblockKeys = [];
foreach ($iblockRows as $row) {
    $iblockKeys = array_merge($iblockKeys, array_keys($row));
}
$iblockKeys = array_values(array_unique($iblockKeys));
sort($iblockKeys);

// თვისების სვეტების შედარება: პირველი თვისება თითოეული სიიდან
$propKeys = [];
foreach ($propertyRows as $rows) {
    if (!empty($rows[0])) {
        $propKeys = array_merge($propKeys, array_keys($rows[0]));
    }
}
$propKeys = array_values(array_unique($propKeys));
sort($propKeys);
?>
<style>
    .diag { font: 13px/1.5 "Helvetica Neue", Arial, sans-serif; max-width: 1100px; padding: 16px 0; }
    .diag h2 { margin: 24px 0 8px; font-size: 17px; }
    .diag table { border-collapse: collapse; width: 100%; margin-bottom: 12px; }
    .diag th, .diag td { border: 1px solid #dfe3e7; padding: 4px 8px; text-align: left; vertical-align: top; }
    .diag th { background: #f4f6f8; }
    .diag td.diff { background: #fff3cd; font-weight: 600; }
    .diag code { background: #f4f6f8; padding: 1px 4px; }
</style>

<div class="diag">
    <p>
        <b>lists მოდული:</b> <?= $listsModule ? 'ჩატვირთულია' : 'არ ჩაიტვირთა' ?> ·
        <a href="?clearcache=1">ქეშის გასუფთავება</a>
        <?= $clearCache ? ' <b>(გასუფთავდა)</b>' : '' ?>
    </p>

    <h2>1. რას ხედავს Lists მოდული (<code>CList::GetFields()</code>)</h2>
    <table>
        <tr><th>IBLOCK</th><th>ველები</th></tr>
        <?php foreach ($IBLOCKS as $iblockId => $label): ?>
            <?php $fields = diagListsFields($iblockId); ?>
            <tr>
                <td><?= $iblockId ?> — <?= htmlspecialcharsbx($label) ?></td>
                <td><?= $fields === null ? 'CList კლასი მიუწვდომელია' : htmlspecialcharsbx(implode(', ', $fields)) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>2. iblock-ის პარამეტრები (განსხვავებები ეტალონთან ყვითელია)</h2>
    <table>
        <tr>
            <th>ველი</th>
            <?php foreach ($IBLOCKS as $iblockId => $label): ?>
                <th><?= $iblockId ?></th>
            <?php endforeach; ?>
        </tr>
        <?php foreach ($iblockKeys as $key): ?>
            <?php $reference = isset($iblockRows[26][$key]) ? (string)$iblockRows[26][$key] : ''; ?>
            <tr>
                <th><?= htmlspecialcharsbx($key) ?></th>
                <?php foreach (array_keys($IBLOCKS) as $iblockId): ?>
                    <?php
                    $value = isset($iblockRows[$iblockId][$key]) ? (string)$iblockRows[$iblockId][$key] : '';
                    $isDiff = $iblockId !== 26 && $value !== $reference;
                    ?>
                    <td class="<?= $isDiff ? 'diff' : '' ?>"><?= htmlspecialcharsbx($value) ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>3. თვისებების სვეტები — პირველი თვისება თითოეული სიიდან</h2>
    <table>
        <tr>
            <th>სვეტი</th>
            <?php foreach (array_keys($IBLOCKS) as $iblockId): ?>
                <th><?= $iblockId ?></th>
            <?php endforeach; ?>
        </tr>
        <?php foreach ($propKeys as $key): ?>
            <?php $reference = isset($propertyRows[26][0][$key]) ? (string)$propertyRows[26][0][$key] : ''; ?>
            <tr>
                <th><?= htmlspecialcharsbx($key) ?></th>
                <?php foreach (array_keys($IBLOCKS) as $iblockId): ?>
                    <?php
                    $value = isset($propertyRows[$iblockId][0][$key]) ? (string)$propertyRows[$iblockId][0][$key] : '';
                    $isDiff = $iblockId !== 26 && $value !== $reference && !in_array($key, ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'TIMESTAMP_X', 'SORT'], true);
                    ?>
                    <td class="<?= $isDiff ? 'diff' : '' ?>"><?= htmlspecialcharsbx($value) ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </table>

    <h2>4. თვისებების სია</h2>
    <?php foreach ($IBLOCKS as $iblockId => $label): ?>
        <p><b><?= $iblockId ?> — <?= htmlspecialcharsbx($label) ?></b> (<?= count($propertyRows[$iblockId]) ?> ცალი)</p>
        <table>
            <tr><th>ID</th><th>CODE</th><th>NAME</th><th>TYPE</th><th>ACTIVE</th><th>SORT</th><th>MULTIPLE</th><th>USER_TYPE</th></tr>
            <?php foreach ($propertyRows[$iblockId] as $row): ?>
                <tr>
                    <td><?= (int)$row['ID'] ?></td>
                    <td><?= htmlspecialcharsbx((string)$row['CODE']) ?></td>
                    <td><?= htmlspecialcharsbx((string)$row['NAME']) ?></td>
                    <td><?= htmlspecialcharsbx((string)$row['PROPERTY_TYPE']) ?></td>
                    <td><?= htmlspecialcharsbx((string)$row['ACTIVE']) ?></td>
                    <td><?= htmlspecialcharsbx((string)$row['SORT']) ?></td>
                    <td><?= htmlspecialcharsbx((string)$row['MULTIPLE']) ?></td>
                    <td><?= htmlspecialcharsbx((string)($row['USER_TYPE'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endforeach; ?>
</div>

<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php'); ?>
