<?php
/**
 * აგენტების მოდული (/custom/agents/) - marketplace აპლიკაცია #1 "Agents Module"
 * (/local/localApps/agents-module/) ჩვეულებრივ გვერდად, აპლიკაციის გარეშე.
 *
 * წვდომა: ადმინები + AGENTS_MODULE_USER_IDS (აპლიკაციის ACCESS იყო U9, U12).
 * REST მოთხოვნები api.php-ით მიდის. webhook ბრაუზერში აღარ ჩანს და ინახება credentials.php-ში,
 * რომელიც git-ში არ არის (რეპო საჯაროა). ნიმუში: credentials.example.php
 */

const AGENTS_MODULE_USER_IDS = [9, 12]; // ნუკი პაიჭაძე, ლევან არქანია

function agentsModuleCanAccess()
{
    global $USER;
    if (!is_object($USER) || !$USER->IsAuthorized()) {
        return false;
    }
    return $USER->IsAdmin() || in_array((int)$USER->GetID(), AGENTS_MODULE_USER_IDS, true);
}

/** incoming webhook-ის URL ბოლო "/"-ით; ცარიელი სტრიქონი, თუ credentials.php არ არის. */
function agentsModuleWebhookUrl()
{
    $file = __DIR__ . '/credentials.php';
    if (!is_file($file)) {
        return '';
    }
    $credentials = include $file;
    $url = is_array($credentials) ? trim((string)($credentials['webhookUrl'] ?? '')) : '';
    return $url === '' ? '' : rtrim($url, '/') . '/';
}
