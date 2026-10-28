<?php
/** Reader theme inheritance, sender CSS parsing, and mail-only hook scope. */
$colored = '<p style="color:#000000;background-color:#ffffff;font-weight:bold">Bonjour sombre</p>';
$normal = run($colored, '', ['inherit_mail_colors' => '1']);
check($normal['changed'] && $normal['colors_normalized'] && $normal['status'] === 'colors_normalized',
    'mail colors normalize even without signature match');
check(!str_contains($normal['html'], '#000000') && !str_contains($normal['html'], '#ffffff')
    && str_contains($normal['html'], 'font-weight:bold'), 'remove foreground and background while keeping bold');
check(str_contains($normal['html'], 'Bonjour sombre') && !str_contains($normal['html'], 'color:'),
    'text inherits reader theme instead of a stored fixed color');
check(run($colored, '')['html'] === $colored, 'color option defaults off and preserves exact original');
check(run($normal['html'], '', ['inherit_mail_colors' => '1'])['html'] === $normal['html'], 'color normalization is idempotent');
$legacy = '<table bgcolor="#ffffff"><tr><td><font color="black">Texte</font></td></tr></table>';
$legacyResult = run($legacy, '', ['inherit_mail_colors' => '1']);
check(!str_contains($legacyResult['html'], 'bgcolor=') && !str_contains($legacyResult['html'], 'color='), 'remove legacy font color and table background attributes');
$full = '<html><head><style>p {color:black!important;background:white}</style><link rel="stylesheet" href="https://example.invalid/mail.css"></head>'
    . '<body text="black" bgcolor="white"><p>Lisible</p></body></html>';
$fullResult = run($full, '', ['inherit_mail_colors' => '1']);
check(!str_contains($fullResult['html'], '<style') && !str_contains($fullResult['html'], 'mail.css')
    && !str_contains($fullResult['html'], 'text="black"'), 'strip embedded stylesheets and full body color attributes');
$quotedColor = run('<blockquote><span style="color:black">Citation utile</span></blockquote>', '', ['inherit_mail_colors' => '1']);
check(str_contains($quotedColor['html'], 'Citation utile') && str_contains($quotedColor['html'], '<blockquote>')
    && !str_contains($quotedColor['html'], 'color:black'), 'quote contents kept while their colors inherit theme');
$imageHtml = '<p style="color:black"><a href="/front/document.send.php?docid=42">Document</a><img src="cid:logo" width="25"></p>';
$imageResult = run($imageHtml, '', ['inherit_mail_colors' => '1']);
check(str_contains($imageResult['html'], 'document.send.php?docid=42') && str_contains($imageResult['html'], 'src="cid:logo"')
    && str_contains($imageResult['html'], 'width="25"'), 'color normalization preserves attachment URLs and images');
$specialStyle = '<span style="COLOR: rgb(0,0,0) !important; background:linear-gradient(black,white);-webkit-text-fill-color:black; margin:2pt">T</span>';
$special = run($specialStyle, '', ['inherit_mail_colors' => '1']);
check(!str_contains($special['html'], 'black') && !str_contains($special['html'], 'COLOR')
    && str_contains($special['html'], 'margin:2pt'), 'important foreground, gradients and webkit text fill removed');
$semicolon = run('<p style="font-family:&quot;Example; Font&quot;;color:black;margin:1pt">Texte</p>', '', ['inherit_mail_colors' => '1']);
check(str_contains($semicolon['html'], 'Example; Font') && str_contains($semicolon['html'], 'margin:1pt')
    && !str_contains($semicolon['html'], 'color:black'), 'CSS tokenizer preserves semicolon inside quoted font family');
$escapedCss = run('<p style="co/**/lor:black;c\\6f lor:red;margin:2pt">Texte</p>', '', ['inherit_mail_colors' => '1']);
check(!str_contains($escapedCss['html'], 'black') && !str_contains($escapedCss['html'], 'red')
    && str_contains($escapedCss['html'], 'margin:2pt'), 'CSS comments and escaped color property handled');
$custom = run('<p style="--tblr-body-color:black;all:initial;color:var(--tblr-body-color)">Texte</p>', '', ['inherit_mail_colors' => '1']);
check(!str_contains($custom['html'], 'tblr-body-color') && !str_contains($custom['html'], 'all:'), 'sender CSS variables and reset cannot override inherited reader color');
check(run('Texte brut', '', ['inherit_mail_colors' => '1'])['html'] === 'Texte brut', 'plain text stays unchanged in color mode');
check(run('<p>&lt;span style="color:black"&gt;code&lt;/span&gt;</p>', '', ['inherit_mail_colors' => '1'])['changed'] === false,
    'escaped code sample is not interpreted as colored markup');
$markerColor = run('<p style="color:black">Réponse =_=_=_=</p>', '', ['inherit_mail_colors' => '1']);
check(str_contains($markerColor['html'], '=_=_=_='), 'theme normalization preserves GLPI delimiters');
Config::$values = array_replace(\MailSignatureCleaner\Settings::defaults(),
    ['enabled' => '0', 'dry_run' => '0', 'inherit_mail_colors' => '1']);
$item->input = array_replace($mail, ['content' => $colored . '<div class="gmail_signature">Signature conservée</div>']);
plugin_mailsignaturecleaner_pre_item_add($item);
check(!str_contains($item->input['content'], '#000000') && str_contains($item->input['content'], 'Signature conservée'),
    'color option applies independently without activating signature removal');
$ticketItem = new Ticket();
$ticketItem->input = ['content' => $colored . '<div class="gmail_signature">Signature initiale</div>', '_mailgate' => 1, '_auto_import' => 1];
Config::$values['enabled'] = '1';
plugin_mailsignaturecleaner_pre_item_add($ticketItem);
check(!str_contains($ticketItem->input['content'], '#000000') && str_contains($ticketItem->input['content'], 'Signature initiale'),
    'initial mail ticket gets theme colors without signature deletion');
$manualTicket = new Ticket();
$manualTicket->input = ['content' => $colored];
plugin_mailsignaturecleaner_pre_item_add($manualTicket);
check($manualTicket->input['content'] === $colored, 'manual ticket colors remain untouched');
$item->input = ['itemtype' => 'Ticket', 'items_id' => 42, 'content' => $colored];
plugin_mailsignaturecleaner_pre_item_add($item);
check($item->input['content'] === $colored, 'manual followup colors remain untouched');
Config::$values['dry_run'] = '1';
$ticketItem->input = ['content' => $colored, '_mailgate' => 1, '_auto_import' => 1];
plugin_mailsignaturecleaner_pre_item_add($ticketItem);
check($ticketItem->input['content'] === $colored, 'simulation prevents initial mail ticket color writes');
Config::$values['dry_run'] = '0';
Config::$values['inherit_mail_colors'] = '0';
$ticketItem->input = ['content' => $colored, '_mailgate' => 1, '_auto_import' => 1];
plugin_mailsignaturecleaner_pre_item_add($ticketItem);
check($ticketItem->input['content'] === $colored, 'initial ticket unchanged with color option off');
