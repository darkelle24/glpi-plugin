<?php
namespace MailSignatureCleaner;

/** Temporary diagnostics at the hook boundary; not the original MIME/IMAP mail. */
final class Capture
{
    public const MAX_FILES = 20;
    public const MAX_FIELD_BYTES = 262144;

    public static function write(array $input, array $settings, array $result, string $effective): ?string
    {
        if (($settings['debug_capture'] ?? '0') !== '1') {
            return null;
        }
        if (!defined('GLPI_LOG_DIR')) {
            throw new \RuntimeException('GLPI log directory unavailable.');
        }
        $directory = GLPI_LOG_DIR . '/mailsignaturecleaner-debug';
        if (is_link($directory)) {
            throw new \RuntimeException('Refusing linked capture directory.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create capture directory.');
        }
        // Exclusive lock bounds the number of files even with parallel workers.
        $lockPath = $directory . '/.capture.lock';
        if (is_link($lockPath)) {
            throw new \RuntimeException('Refusing linked capture lock.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open capture lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock capture directory.');
            }
            $files = glob($directory . '/capture-*.json');
            if ($files === false || count($files) >= self::MAX_FILES) {
                return null;
            }
            $fields = ['before_html' => (string) $input['content'],
                'proposed_html' => (string) $result['html'], 'effective_html' => $effective];
            $sizes = [];
            foreach ($fields as $key => $value) {
                $sizes[$key] = ['original_bytes' => strlen($value),
                    'truncated' => strlen($value) > self::MAX_FIELD_BYTES];
                $fields[$key] = substr($value, 0, self::MAX_FIELD_BYTES);
            }
            $record = [
                'captured_at_utc' => gmdate('c'), 'plugin_version' => '1.1.2',
                'stage' => 'ITILFollowup pre_item_add; after native MailCollector processing',
                'ticket_id' => (int) ($input['items_id'] ?? 0),
                'collector_id' => (int) ($input['_mailgate'] ?? 0),
                'enabled' => $settings['enabled'] === '1', 'dry_run' => $settings['dry_run'] === '1',
                'status' => $result['status'], 'removed' => $result['removed'],
                'protected' => $result['protected'], 'matches' => $result['matches'],
                'warnings' => $result['warnings'],
                'rules' => array_intersect_key($settings, array_flip(['selectors',
                    'cut_after_selectors', 'cut_remove_quotes', 'protect_selectors',
                    'markers', 'max_removed_percent'])),
                'body_limits' => $sizes,
            ] + $fields;
            $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $name = 'capture-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.json';
            $path = $directory . '/' . $name;
            $previousMask = umask(0077);
            try {
                $file = fopen($path, 'x');
            } finally {
                umask($previousMask);
            }
            if ($file === false) {
                throw new \RuntimeException('Cannot create capture file.');
            }
            try {
                $data = $json . "\n";
                $offset = 0;
                while ($offset < strlen($data)) {
                    $bytes = fwrite($file, substr($data, $offset));
                    if ($bytes === false || $bytes === 0) {
                        throw new \RuntimeException('Incomplete capture write.');
                    }
                    $offset += $bytes;
                }
            } finally {
                fclose($file);
            }
            return $name;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
