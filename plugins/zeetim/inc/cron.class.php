<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/api.class.php';
require_once __DIR__ . '/config.class.php';

class PluginZeetimCron extends CommonDBTM
{
    protected static $notable = true;

    public static function getTypeName($nb = 0)
    {
        return 'ZeeTim WMC';
    }

    public static function cronInfo($name)
    {
        return $name === 'sync_zeetim'
            ? ['description' => 'Synchronize ZeeTim WMC endpoints into Computers']
            : [];
    }

    private static function logMessage(CronTask $task, string $message): void
    {
        $message = '[zeetim] ' . preg_replace('/[\r\n\x00-\x1f\x7f]+/', ' ', $message);
        // Both the action history and GLPI's standard cron.log receive failures.
        $task->log($message);
        Toolbox::logInFile('cron', $message . PHP_EOL);
    }

    private static function text($value, string $field, bool $required = false): string
    {
        // Serial numbers must be strings: never discard leading zeroes.
        if ($value === null && !$required) {
            return '';
        }
        if (!is_string($value)) {
            throw new RuntimeException('Endpoint ' . $field . ' must be a JSON string.');
        }
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value, 'UTF-8') > 255
            || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new RuntimeException('Endpoint ' . $field . ' is empty, too long or contains controls.');
        }
        // GLPI 10 stores HTML-encoded strings; enforce the stored field length too.
        if (mb_strlen(\Glpi\Toolbox\Sanitizer::sanitize($value, false), 'UTF-8') > 255) {
            throw new RuntimeException('Encoded endpoint ' . $field . ' exceeds 255 characters.');
        }
        return $value;
    }

    private static function normalize(array $records, array $config): array
    {
        $result = [];
        $seen = [];
        foreach ($records as $record) {
            $serial = self::text(
                PluginZeetimApi::value($record, $config['serial_path']), 'serial', true
            );
            $key = mb_strtolower($serial, 'UTF-8');
            if (in_array($key, ['unknown', 'n/a', 'none', 'null', 'to be filled by o.e.m.'], true)) {
                throw new RuntimeException('Inventory contains a non-identifying serial number.');
            }
            if (isset($seen[$key])) {
                throw new RuntimeException('Inventory contains duplicate serial numbers.');
            }
            $seen[$key] = true;
            $input = ['serial' => $serial];
            // Missing optional values never erase existing GLPI values.
            foreach (['name' => 'name_path', 'uuid' => 'uuid_path'] as $field => $option) {
                if ($config[$option] === '') {
                    continue;
                }
                try {
                    $value = PluginZeetimApi::value($record, $config[$option]);
                } catch (RuntimeException $exception) {
                    continue;
                }
                $value = self::text($value, $field);
                if ($value !== '') {
                    $input[$field] = $value;
                }
            }
            $result[] = $input;
        }
        return $result;
    }

    private static function upsert(array $values, array $config): string
    {
        global $DB;
        $computer = new Computer();
        // DB::request escapes criteria; only HTML encoding is needed here.
        $serial = \Glpi\Toolbox\Sanitizer::sanitize($values['serial'], false);
        $matches = iterator_to_array($DB->request([
            'SELECT' => ['id', 'is_deleted'],
            'FROM' => Computer::getTable(),
            'WHERE' => [
                'serial' => $serial,
                'entities_id' => $config['entities_id'],
                'is_template' => 0,
            ],
            'LIMIT' => 2,
        ]), false);
        if (count($matches) > 1) {
            throw new RuntimeException('Ambiguous serial: multiple GLPI computers in the target entity.');
        }
        if (!$matches) {
            $values['entities_id'] = $config['entities_id'];
            $values['is_template'] = 0;
            $values['name'] = $values['name'] ?? $values['serial'];
            if ($config['dry_run']) {
                return 'created';
            }
            $id = $computer->add(\Glpi\Toolbox\Sanitizer::sanitize($values));
            if (!$id) {
                throw new RuntimeException('Computer::add failed; consult GLPI application logs and rules.');
            }
            return 'created';
        }
        if ((int) $matches[0]['is_deleted'] !== 0) {
            // Do not silently restore a retired asset or create a replacement duplicate.
            return 'trashed';
        }
        $id = (int) $matches[0]['id'];
        if (!$computer->getFromDB($id)) {
            throw new RuntimeException('Cannot load matched Computer.');
        }
        $changes = [];
        foreach ($values as $field => $value) {
            $encoded = \Glpi\Toolbox\Sanitizer::sanitize($value, false);
            if ((string) ($computer->fields[$field] ?? '') !== $encoded) {
                $changes[$field] = $value;
            }
        }
        if (!$changes) {
            return 'unchanged';
        }
        if (!$config['dry_run']) {
            $changes['id'] = $id;
            if (!$computer->update(\Glpi\Toolbox\Sanitizer::sanitize($changes))) {
                throw new RuntimeException('Computer::update failed for ID ' . $id . '.');
            }
        }
        return 'updated';
    }

    public static function cronSync_zeetim($task = null)
    {
        global $DB;
        if (!($task instanceof CronTask)) {
            return 0;
        }
        $locked = false;
        try {
            $config = PluginZeetimConfig::get(false);
            if (!$config['enabled']) {
                self::logMessage($task, 'SKIP: synchronization is disabled in plugin settings.');
                return 0;
            }
            $config = PluginZeetimConfig::get(true);
            $entity = new Entity();
            if (!is_int($config['entities_id']) || $config['entities_id'] < 0
                || !$entity->getFromDB($config['entities_id'])) {
                throw new RuntimeException('Configured target entity does not exist.');
            }
            foreach (['connect_timeout', 'request_timeout', 'max_pages', 'max_records',
                'max_response_bytes', 'max_total_response_bytes', 'max_run_seconds'] as $option) {
                if (!is_int($config[$option]) || $config[$option] < 1) {
                    throw new RuntimeException('Invalid positive integer configuration: ' . $option);
                }
            }
            if (!is_bool($config['dry_run'])) {
                throw new RuntimeException('dry_run must be a boolean.');
            }
            $locked = $DB->getLock('plugin_zeetim_sync');
            if (!$locked) {
                self::logMessage($task, 'SKIP: another ZeeTim synchronization holds the database lock.');
                return 0;
            }
            $deadline = microtime(true) + $config['max_run_seconds'];
            $api = new PluginZeetimApi($config, $deadline);
            $records = self::normalize($api->fetchAll(), $config);
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'trashed' => 0];
            foreach ($records as $index => $values) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Write phase exceeded the synchronization time budget.');
                }
                try {
                    $outcome = self::upsert($values, $config);
                } catch (Throwable $exception) {
                    // Row index aids diagnosis without disclosing device or user information.
                    throw new RuntimeException('Endpoint row ' . ($index + 1) . ': ' . $exception->getMessage());
                }
                ++$counts[$outcome];
                if (!$config['dry_run'] && in_array($outcome, ['created', 'updated'], true)) {
                    $task->addVolume(1);
                }
            }
            self::logMessage($task, sprintf(
                '%s: fetched=%d created=%d updated=%d unchanged=%d trashed=%d',
                $config['dry_run'] ? 'DRY RUN' : 'OK',
                count($records), $counts['created'], $counts['updated'],
                $counts['unchanged'], $counts['trashed']
            ));
            return 1;
        } catch (Throwable $exception) {
            // Redact the configured secret even if a downstream hook echoed it in an exception.
            $message = $exception->getMessage();
            if (!empty($config['token']) && is_string($config['token'])) {
                $message = str_replace($config['token'], '[redacted]', $message);
            }
            self::logMessage($task, 'ERROR: ' . $message);
            // A negative result means "partially processed", not "error" in GLPI.
            // Explicitly close this run with its real error state instead.
            $task->end(null, CronTaskLog::STATE_ERROR);
            return 0;
        } finally {
            if ($locked) {
                $DB->releaseLock('plugin_zeetim_sync');
            }
        }
    }
}
