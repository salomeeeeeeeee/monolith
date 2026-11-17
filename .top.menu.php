<?
$aMenuLinks = Array(
	Array(
		"CRM", 
		"/crm/", 
		Array(), 
		Array(), 
		"CBXFeatures::IsFeatureEnabled('crm') && CModule::IncludeModule('crm') && CCrmPerms::IsAccessEnabled()" 
	),
	// Dailo-ს ზარების ანალიტიკა (custom/dailo/index.php): ადმინისტრატორს + ჯგუფ 27-ის
	// „Dailo – ზარების ანალიტიკა“ (STRING_ID DAILO_SSO) წევრებს უჩანს
	Array(
		"ზარების ანალიტიკა",
		"/custom/dailo/",
		Array(),
		Array("menu_item_id" => "menu_dailo_calls"),
		'$GLOBALS["USER"]->IsAuthorized() && ($GLOBALS["USER"]->IsAdmin() || CSite::InGroup(array(27)))'
	)
);
?>
