<?php
/** Standalone regression tests; no database or GLPI session needed. */
$autoload = getenv('GLPI_AUTOLOAD');
if ($autoload && is_file($autoload)) {
    require_once $autoload;
} else {
    $source = getenv('CSS_SELECTOR_SOURCE');
    if (!$source || !is_dir($source)) {
        fwrite(STDERR, "Set GLPI_AUTOLOAD to GLPI vendor/autoload.php, or CSS_SELECTOR_SOURCE to Symfony CssSelector sources.\n");
        exit(2);
    }
    spl_autoload_register(static function (string $class) use ($source): void {
        $prefix = 'Symfony\\Component\\CssSelector\\';
        if (str_starts_with($class, $prefix)) {
            $path = $source . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
    });
}
require_once dirname(__DIR__) . '/bootstrap.php';
use MailSignatureCleaner\Cleaner;
use MailSignatureCleaner\Settings;

$passed = 0;
function check(bool $condition, string $name): void
{
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $name\n");
        exit(1);
    }
    $passed++;
    echo "OK: $name\n";
}
function run(string $html, string $selectors = '.signature', array $overrides = []): array
{
    return (new Cleaner())->clean($html, array_replace(Settings::defaults(),
        ['selectors' => $selectors, 'max_removed_percent' => '95'], $overrides));
}
$body = '<p>Bonjour, voici une réponse utile qui doit absolument rester dans le suivi.</p>';
$sig = '<div class="signature">Alice</div>';
$r = run($body . $sig);
check($r['changed'] && !str_contains($r['html'], 'Alice') && str_contains($r['html'], 'réponse utile'), 'signature removed, UTF-8 reply preserved');
check($r['removed'] === 1, 'one subtree removed');
check(run($r['html'])['html'] === $r['html'], 'idempotence');
check(run($body . $sig, '.unknown')['html'] === $body . $sig, 'no match is byte-identical');
check(run("Réponse\n-- \nAlice")['status'] === 'plain_text', 'plain text untouched');
check(run($body . '<p>&lt;div class="signature"&gt;code&lt;/div&gt;</p>')['changed'] === false, 'escaped HTML code is not decoded');
check(run($sig)['status'] === 'empty_guard', 'signature-only message retained');
check(run('<p>x</p><div class="signature">' . str_repeat('s', 500) . '</div>')['status'] === 'ratio_guard', 'excessive removal rejected');
$quote = '<blockquote><div class="signature">Ancienne signature</div></blockquote>';
check(run($body . $sig . $quote)['html'] === $body . $quote, 'quoted signatures protected');
check(run($body . '<div class="signature">Alice' . $quote . '</div>')['status'] === 'protected', 'ancestor of quotation protected');
check(run($body . '<div class="signature">=-=-=-= réponse =-=-=-=</div>')['status'] === 'protected', 'GLPI header marker protected');
check(run($body . '<div class="signature"><!-- =_=_=_= --></div>')['status'] === 'protected', 'marker in comment protected');
check(run($body . $sig . '<p>=_=_=_=</p>')['changed'], 'separate marker does not prevent signature removal');
check(run($body . '<div class="signature">CUSTOM</div>', '.signature', ['markers' => 'CUSTOM'])['status'] === 'protected', 'custom marker protected');
check(run($body . '<div class="signature"><a href="/front/document.send.php?docid=42">PJ</a></div>')['status'] === 'protected', 'GLPI attachment protected');
check(run($body . '<div class="signature"><img src="/front/document.send.php?docid=42"></div>')['status'] === 'protected', 'GLPI embedded image protected');
check(run($body . '<div class="signature"><img id="abc-uuid-123"></div>')['status'] === 'protected', 'GLPI collector image placeholder protected');
check(run($body . $sig, 'body')['status'] === 'protected', 'body cannot be removed');
check(run($body . '<div class="signature"><b>Alice</b></div>', '.signature, .signature b')['removed'] === 1, 'overlapping targets counted once');
check(run($body . '<div class="signature"><b>Alice</b></div>', 'div.signature > b:first-child')['changed'], 'child combinator and pseudo-class');
check(run($body . '<div id="Signature-1">Alice</div>', '[id^="Signature"]')['changed'], 'attribute prefix selector');
check(run($body . '<div class="signature keep">Alice</div>', 'div.signature:not(.keep)')['changed'] === false, 'negation selector');
check(run($body . $sig, '.signature:has(b)')['status'] === 'error', 'unsupported selector fails open');
check(run($body . $sig, 'div[')['status'] === 'error', 'invalid selector fails open');
check(run($body . str_repeat('<i class="signature">a</i>', 101))['status'] === 'too_many_matches', 'match count limit');
check(run(str_repeat('a', Cleaner::MAX_BYTES + 1))['status'] === 'too_large', 'message size limit');
check(run('<!DOCTYPE html [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $body)['status'] === 'unsafe_declaration', 'entity declarations rejected');
check(run($body . '<div class="signature">Alice')['changed'], 'recoverable malformed email HTML');
check(run('<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $body . $sig . '</body></html>')['changed'], 'full HTML document accepted');
check(run('<p>texte identique</p>', '.signature')['html'] === '<p>texte identique</p>', 'unmatched fragment unchanged');
check(run($body . '<div class="signature"><img src="cid:logo"></div>')['changed'], 'standalone signature image removed');
try {
    Settings::validate(array_replace(Settings::defaults(), ['max_removed_percent' => '101']));
    check(false, 'invalid percentage rejected');
} catch (InvalidArgumentException $e) {
    check(true, 'invalid percentage rejected');
}
$longSignature = '<p>x</p><p id="cut">&nbsp;</p><div>' . str_repeat('s', 5000) . '</div>';
$completeCut = run($longSignature, '', ['cut_after_selectors' => '#cut', 'max_removed_percent' => '100']);
check($completeCut['changed'] && str_contains($completeCut['html'], '<p>x</p>')
    && !str_contains($completeCut['html'], 'ssss'), '100 percent limit allows long signature behind short reply');
check(run($sig, '.signature', ['max_removed_percent' => '100'])['status'] === 'empty_guard',
    '100 percent limit still refuses an empty message');
$outlookVariant = $body . '<div><p class="MsoNormal" style="margin-bottom:6.0pt;line-height:12.0pt"><span>&nbsp;</span></p>'
    . $sig . '</div><div><b>De :</b> Support</div>';
$outlookSelector = 'p[style*="margin-bottom:6.0pt"][style*="line-height:12.0pt"]';
$outlookCleaned = run($outlookVariant, '', ['cut_after_selectors' => $outlookSelector, 'max_removed_percent' => '100']);
check($outlookCleaned['changed'] && !str_contains($outlookCleaned['html'], 'Support')
    && !str_contains($outlookCleaned['html'], 'Alice'), 'actual Outlook style variant cuts signature and trailing header');
$cutOptions = ['cut_after_selectors' => '#cut'];
$cutHtml = $body . '<div><p id="cut">&nbsp;</p><div>Signature</div></div><p>English disclaimer</p>Trailing text';
$cut = run($cutHtml, '', $cutOptions);
check($cut['changed'] && !str_contains($cut['html'], 'Signature')
    && !str_contains($cut['html'], 'English disclaimer') && !str_contains($cut['html'], 'Trailing text'),
    'cut removes following content across ancestors and trailing text');
check(str_contains($cut['html'], 'id="cut"') && str_contains($cut['html'], 'réponse utile'), 'cut keeps anchor and earlier reply');
check(run($cutHtml, '', ['cut_after_selectors' => '#missing'])['html'] === $cutHtml, 'missing cut anchor leaves exact original');
check(run($body . '<p id="cut">a</p><p id="cut">b</p>', '', $cutOptions)['status'] === 'ambiguous_cut', 'duplicate cut anchors rejected');
check(run($body . '<blockquote><p id="cut">repère</p></blockquote>' . $sig, '', $cutOptions)['status'] === 'protected', 'cut inside citation rejected');
$cutProtected = run($body . '<p id="cut">&nbsp;</p><p>Signature</p><blockquote>Citation</blockquote><p>=_=_=_=</p>', '', $cutOptions);
check(str_contains($cutProtected['html'], 'Citation') && str_contains($cutProtected['html'], '=_=_=_='), 'cut retains protected quotes and reply markers');
check(run($cutHtml, '', ['cut_after_selectors' => 'body'])['status'] === 'invalid_cut', 'body cannot serve as cutoff anchor');
check(run($body . '<p id="cut">Fin</p>', '', $cutOptions)['changed'] === false, 'anchor at end has no effect');
$replyTail = $body . '<p id="cut">&nbsp;</p><div>Signature</div>'
    . '<div id="divRplyFwdMsg"><hr><b>De :</b> Support<br><b>Objet :</b> Test</div>'
    . '<blockquote>Ancien message</blockquote>';
$retainedHeader = run($replyTail, '', $cutOptions);
check(str_contains($retainedHeader['html'], 'divRplyFwdMsg') && !str_contains($retainedHeader['html'], 'Signature'),
    'reproduce header left visible by protected cut');
$strictOptions = ['cut_after_selectors' => '#cut', 'cut_remove_quotes' => '1'];
$removedHeader = run($replyTail, '', $strictOptions);
check($removedHeader['changed'] && !str_contains($removedHeader['html'], 'divRplyFwdMsg')
    && !str_contains($removedHeader['html'], 'Ancien message'), 'explicit history removal clears header and quote');
check(str_contains($removedHeader['html'], 'réponse utile'), 'history removal retains current reply');
check(run($body . '<blockquote><p id="cut">repère</p></blockquote>' . $sig, '', $strictOptions)['status'] === 'protected',
    'historical anchor still cannot trigger a cut');
$strictMarker = run($replyTail . '<p>=_=_=_=</p>', '', $strictOptions);
check(str_contains($strictMarker['html'], '=_=_=_='), 'history option still preserves GLPI marker');
$strictAttachment = run($replyTail . '<p><a href="/front/document.send.php?docid=42">Attachment</a></p>', '', $strictOptions);
check(str_contains($strictAttachment['html'], 'document.send.php'), 'history option still preserves attachment');
$normalQuote = run($body . '<blockquote class="signature">Ancien message</blockquote>', '.signature', ['cut_remove_quotes' => '1']);
check($normalQuote['changed'] === false, 'history option does not weaken normal selector protection');
// Lightweight hook contract doubles. This is not a GLPI database integration test.
class ITILFollowup { public array $input = []; }
class Config {
    public static array $values = [];
    public static function getConfigurationValues($context): array { return self::$values; }
    public static function setConfigurationValues($context, array $values): void { self::$values = $values; }
    public static function deleteConfigurationValues($context, array $keys): void {
        foreach ($keys as $key) { unset(self::$values[$key]); }
    }
}
class Toolbox {
    public static array $logs = [];
    public static function logInFile($file, $line): void { self::$logs[] = $line; }
}
Config::$values = array_replace(Settings::defaults(), ['enabled' => '1', 'dry_run' => '0', 'selectors' => '.signature', 'log' => '1']);
$mail = ['itemtype' => 'Ticket', 'items_id' => 42, '_mailgate' => 1, '_auto_import' => 1, 'content' => $body . $sig];
$item = new ITILFollowup();
$item->input = $mail;
plugin_mailsignaturecleaner_pre_item_add($item);
check(!str_contains($item->input['content'], 'Alice'), 'mail followup is cleaned by hook');
check($item->input['_mailgate'] === 1 && $item->input['items_id'] === 42, 'hook preserves other fields');
check(!str_contains(implode('', Toolbox::$logs), 'Alice'), 'log has no message body');
$item->input = $mail;
unset($item->input['_mailgate']);
plugin_mailsignaturecleaner_pre_item_add($item);
check($item->input['content'] === $mail['content'], 'manual followup excluded');
$item->input = $mail;
unset($item->input['_auto_import']);
plugin_mailsignaturecleaner_pre_item_add($item);
check($item->input['content'] === $mail['content'], 'mailgate alone insufficient');
$item->input = array_replace($mail, ['itemtype' => 'Change']);
plugin_mailsignaturecleaner_pre_item_add($item);
check($item->input['content'] === $mail['content'], 'other item type excluded');
Config::$values['dry_run'] = '1';
$item->input = $mail;
plugin_mailsignaturecleaner_pre_item_add($item);
check($item->input['content'] === $mail['content'], 'simulation does not alter followup');
Config::$values['dry_run'] = '0';
Config::$values['enabled'] = '0';
plugin_mailsignaturecleaner_pre_item_add($item);
check($item->input['content'] === $mail['content'], 'disabled cleaner has no effect');
plugin_mailsignaturecleaner_uninstall();
check(Config::$values === [], 'uninstall removes only plugin configuration');
plugin_mailsignaturecleaner_install();
check(Config::$values['enabled'] === '0' && Config::$values['dry_run'] === '1', 'install starts disabled in simulation mode');
Config::$values['selectors'] = '#custom';
plugin_mailsignaturecleaner_install();
check(Config::$values['selectors'] === '#custom', 'reinstall preserves settings');
require __DIR__ . '/capture.php';
echo "PASS: $passed checks\n";
