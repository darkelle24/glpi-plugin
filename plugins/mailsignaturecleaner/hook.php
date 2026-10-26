<?php
use MailSignatureCleaner\Cleaner;
use MailSignatureCleaner\Settings;

function plugin_mailsignaturecleaner_install(): bool
{
    require_once __DIR__ . '/bootstrap.php';
    if (Config::getConfigurationValues(Settings::CONTEXT) === []) {
        Config::setConfigurationValues(Settings::CONTEXT, Settings::defaults());
    }
    return true;
}

function plugin_mailsignaturecleaner_uninstall(): bool
{
    require_once __DIR__ . '/bootstrap.php';
    Config::deleteConfigurationValues(Settings::CONTEXT, array_keys(Settings::defaults()));
    return true;
}

function plugin_mailsignaturecleaner_pre_item_add($item): void
{
    require_once __DIR__ . '/bootstrap.php';
    if (!$item instanceof ITILFollowup || !is_array($item->input)) {
        return;
    }
    $input = $item->input;
    // _mailgate + _auto_import are carried by MailCollector::buildTicket().
    // Request type alone also occurs in manually entered followups: do not use it.
    if (($input['itemtype'] ?? '') !== 'Ticket'
        || (int) ($input['_mailgate'] ?? 0) <= 0
        || empty($input['_auto_import'])
        || !is_string($input['content'] ?? null)) {
        return;
    }
    try {
        $settings = Settings::load();
        if ($settings['enabled'] !== '1') {
            return;
        }
        $result = (new Cleaner())->clean($input['content'], $settings);
        if ($settings['dry_run'] !== '1' && $result['changed']) {
            $item->input['content'] = $result['html'];
        }
        if ($settings['log'] === '1') {
            // Do not log selectors, sender, subject, or message content.
            Toolbox::logInFile('mailsignaturecleaner', sprintf(
                "collector=%d ticket=%d mode=%s status=%s removed=%d protected=%d\n",
                (int) $input['_mailgate'], (int) ($input['items_id'] ?? 0),
                $settings['dry_run'] === '1' ? 'simulation' : 'active',
                $result['status'], $result['removed'], $result['protected']
            ));
        }
    } catch (\Throwable $e) {
        // Fail open: collector and message must survive a plugin failure.
        Toolbox::logInFile('mailsignaturecleaner', "Cleaning skipped: internal error.\n");
    }
}
