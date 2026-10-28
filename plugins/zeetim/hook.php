<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

function plugin_zeetim_install()
{
    require_once __DIR__ . '/inc/cron.class.php';
    $task = new CronTask();
    // Preserve an administrator's settings when installation is repeated.
    if ($task->getFromDBbyName('PluginZeetimCron', 'sync_zeetim')) {
        return true;
    }
    return (bool) CronTask::register(
        'PluginZeetimCron',
        'sync_zeetim',
        900,
        [
            'state' => CronTask::STATE_WAITING,
            'mode' => CronTask::MODE_EXTERNAL,
            'allowmode' => CronTask::MODE_EXTERNAL | CronTask::MODE_INTERNAL,
            'hourmin' => 0,
            'hourmax' => 24,
            'logs_lifetime' => 30,
            'comment' => 'Synchronize ZeeTim WMC computers every 15 minutes.',
        ]
    );
}

function plugin_zeetim_uninstall()
{
    require_once __DIR__ . '/inc/config.class.php';
    Config::deleteConfigurationValues(
        'plugin:zeetim',
        array_keys(PluginZeetimConfig::defaults())
    );
    // GLPI unregisters plugin automatic actions during uninstallation.
    // Computers are intentionally preserved.
    return true;
}
