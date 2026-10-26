<?php
/** Loaded by run.php: bounded capture I/O and hook regression checks. */
use MailSignatureCleaner\Capture;

$captureTemp = sys_get_temp_dir() . '/msc-tests-' . bin2hex(random_bytes(8));
mkdir($captureTemp, 0700);
define('GLPI_LOG_DIR', $captureTemp);
$captureSettings = array_replace(\MailSignatureCleaner\Settings::defaults(), ['debug_capture' => '0']);
$captureResult = run($body . $sig);
check(Capture::write($mail, $captureSettings, $captureResult, $mail['content']) === null
    && !is_dir($captureTemp . '/mailsignaturecleaner-debug'), 'capture disabled produces no file');
$captureSettings['debug_capture'] = '1';
$captureName = Capture::write($mail + ['password' => 'never-log-this', '_message' => 'not-a-body'],
    $captureSettings, $captureResult, $mail['content']);
check(is_string($captureName) && is_file($captureTemp . '/mailsignaturecleaner-debug/' . $captureName), 'capture creates unique JSON file');
$captureJson = file_get_contents($captureTemp . '/mailsignaturecleaner-debug/' . $captureName);
$captureData = json_decode($captureJson, true, flags: JSON_THROW_ON_ERROR);
check($captureData['before_html'] === $mail['content'], 'capture retains exact input HTML');
check(!str_contains($captureData['proposed_html'], 'Alice') && $captureData['effective_html'] === $mail['content'], 'capture distinguishes proposal and effective simulation body');
check(!str_contains($captureJson, 'never-log-this') && !str_contains($captureJson, 'not-a-body'), 'capture excludes arbitrary input fields and MIME object');
check($captureData['matches'] !== [] && isset($captureData['rules']['selectors']), 'capture includes rules and match diagnostics');
$oversizedInput = array_replace($mail, ['content' => str_repeat('x', Capture::MAX_FIELD_BYTES + 10)]);
$largeName = Capture::write($oversizedInput, $captureSettings, $captureResult, $mail['content']);
$largeData = json_decode(file_get_contents($captureTemp . '/mailsignaturecleaner-debug/' . $largeName), true, flags: JSON_THROW_ON_ERROR);
check(strlen($largeData['before_html']) === Capture::MAX_FIELD_BYTES
    && $largeData['body_limits']['before_html']['truncated'] === true, 'capture bounds body size and reports truncation');
Config::$values = array_replace(\MailSignatureCleaner\Settings::defaults(),
    ['enabled' => '0', 'dry_run' => '0', 'debug_capture' => '1', 'selectors' => '.signature']);
$item->input = $mail;
plugin_mailsignaturecleaner_pre_item_add($item);
check($item->input['content'] === $mail['content']
    && count(glob($captureTemp . '/mailsignaturecleaner-debug/capture-*.json')) === 3,
    'hook captures with cleaner disabled without applying cleanup');
for ($i = 3; $i < Capture::MAX_FILES; $i++) {
    Capture::write($mail, $captureSettings, $captureResult, $mail['content']);
}
check(Capture::write($mail, $captureSettings, $captureResult, $mail['content']) === null
    && count(glob($captureTemp . '/mailsignaturecleaner-debug/capture-*.json')) === Capture::MAX_FILES,
    'capture stops at twenty files');
Config::$values['enabled'] = '1';
$item->input = $mail;
plugin_mailsignaturecleaner_pre_item_add($item);
check(!str_contains($item->input['content'], 'Alice'), 'capture quota does not prevent cleaning');
// Remove only this test's known, randomly allocated files and directories.
foreach (glob($captureTemp . '/mailsignaturecleaner-debug/capture-*.json') as $captureFile) {
    unlink($captureFile);
}
unlink($captureTemp . '/mailsignaturecleaner-debug/.capture.lock');
rmdir($captureTemp . '/mailsignaturecleaner-debug');
rmdir($captureTemp);
