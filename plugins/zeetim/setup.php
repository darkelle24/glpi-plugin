<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

define('PLUGIN_ZEETIM_VERSION', '1.1.0');

function plugin_init_zeetim()
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['zeetim'] = true;
    $PLUGIN_HOOKS['config_page']['zeetim'] = 'front/config.form.php';
    // Core encrypts this configuration and masks its history; key rotation
    // also discovers this declaration through the standard GLPI hook.
    $PLUGIN_HOOKS['secured_configs']['zeetim'] = ['token'];
}

function plugin_version_zeetim()
{
    return [
        'name' => 'ZeeTim WMC Inventory Sync',
        'version' => PLUGIN_ZEETIM_VERSION,
        'author' => 'Integration Team',
        'license' => 'GPLv3+',
        'requirements' => [
            'glpi' => ['min' => '10.0.0', 'max' => '10.0.99'],
            'php' => [
                'min' => '7.4',
                'exts' => [
                    'curl' => ['required' => true],
                    'json' => ['required' => true],
                    'mbstring' => ['required' => true],
                ],
            ],
        ],
    ];
}

function plugin_zeetim_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, '10.0.0', '<')
        || version_compare(GLPI_VERSION, '11.0.0', '>=')) {
        echo 'ZeeTim requires GLPI 10.0.x.';
        return false;
    }
    foreach (['curl', 'json', 'mbstring'] as $extension) {
        if (!extension_loaded($extension)) {
            echo 'ZeeTim requires PHP extension: ' . $extension;
            return false;
        }
    }
    return version_compare(PHP_VERSION, '7.4.0', '>=');
}

function plugin_zeetim_check_config($verbose = false)
{
    // Installation/activation is allowed before secrets are configured.
    // The automatic action validates the connection settings at each run.
    return true;
}
