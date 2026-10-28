<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use Glpi\Plugin\Hooks;
function plugin_init_pluginsync(): void
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['pluginsync'] = 'Admin';
}
function plugin_version_pluginsync(): array
{
    return [
        'name' => 'Git Sync', 'version' => '2.0.0',
        'author' => 'Git Sync contributors', 'license' => 'GPL-3.0-or-later', 'homepage' => '',
        'requirements' => ['glpi' => ['min' => '12.0.0', 'max' => '13.0.0'], 'php' => ['min' => '8.3']],
    ];
}
function plugin_pluginsync_check_prerequisites(): bool { return extension_loaded('curl'); }
function plugin_pluginsync_check_config(bool $verbose = false): bool { return true; }
