<?
// BP #84 "გადახდები" (ლისტი 23), ეშვება ელემენტის შექმნისას: CodeActivity "შევსება ბანკის გატარების ველებით".
// შაბლონი DB-შია; ეს ფაილი = CodeActivity-ის კოდი გახსნილი "<?"-ის გარეშე.
// ხელით შექმნილ გადახდას ივსება დილის ველები, NBG კურსი, თანხა ლარში და სახელი - როგორც bog_merge.php-ის გატარებას.

try {
    require_once \Bitrix\Main\Application::getDocumentRoot() . '/crm/deal/bank_integration/helpers.php';
    $documentId = $this->GetDocumentId();
    bankBogFillPaymentFromDeal((int)$documentId[2], true);
} catch (\Throwable $e) {
    $this->WriteToTrackingService('payment fill: ' . $e->getMessage());
}
