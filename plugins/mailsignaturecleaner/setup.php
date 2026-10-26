<?php
/** Mail Signature Cleaner — GPL-3.0-or-later */
use Glpi\Plugin\Hooks;

function plugin_init_mailsignaturecleaner(): void
{
    global $PLUGIN_HOOKS;
    require_once __DIR__ . '/bootstrap.php';
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['mailsignaturecleaner'] = [
        ITILFollowup::class => 'plugin_mailsignaturecleaner_pre_item_add',
    ];
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['mailsignaturecleaner'] = 'front/config.php';
    }
}

function plugin_version_mailsignaturecleaner(): array
{
    return [
        'name' => 'Mail Signature Cleaner', 'version' => '1.1.2',
        'author' => 'Mail Signature Cleaner contributors', 'license' => 'GPLv3+',
        'requirements' => ['glpi' => ['min' => '12.0.0', 'max' => '12.1.0'],
            'php' => ['min' => '8.3']],
    ];
}

function plugin_mailsignaturecleaner_check_prerequisites(): bool
{
    return defined('GLPI_VERSION')
        && version_compare(GLPI_VERSION, '12.0.0', '>=')
        && version_compare(GLPI_VERSION, '12.1.0', '<')
        && PHP_VERSION_ID >= 80300 && extension_loaded('dom')
        && class_exists(\Symfony\Component\CssSelector\CssSelectorConverter::class);
}

function plugin_mailsignaturecleaner_check_config($verbose = false): bool
{
    return plugin_mailsignaturecleaner_check_prerequisites();
}
