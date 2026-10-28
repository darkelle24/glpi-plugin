<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

// Set the real HTTPS WMC base URL here, including any application prefix.
// Do not include /api/v1/endpoints, a query string, or credentials in this URL.
$fallbackBaseUrl = '';
// Paste the real service token here WITHOUT the "Bearer " prefix.
$fallbackToken = '';

$environmentUrl = getenv('ZEETIM_WMC_BASE_URL');
$environmentToken = getenv('ZEETIM_WMC_TOKEN');

return [
    'enabled' => false,
    'base_url' => rtrim(trim($environmentUrl !== false && $environmentUrl !== ''
        ? $environmentUrl : $fallbackBaseUrl), '/'),
    'token' => trim($environmentToken !== false && $environmentToken !== ''
        ? $environmentToken : $fallbackToken),
    'endpoint' => '/api/v1/endpoints',
    // Adjust these paths to the JSON contract documented for your WMC version.
    'items_path' => '',
    'serial_path' => 'serial_number',
    'name_path' => 'name',
    'uuid_path' => 'uuid',
    // Empty means a documented unpaginated response, not automatic detection.
    'next_path' => '',
    'total_path' => '',
    'entities_id' => 0,
    // Set true for a first rehearsal, then false for actual synchronization.
    'dry_run' => true,
    // Optional absolute path to a PEM CA bundle. Empty uses PHP's trust store.
    'ca_file' => '',
    'connect_timeout' => 10,
    'request_timeout' => 45,
    'max_pages' => 100,
    'max_records' => 50000,
    'max_response_bytes' => 16 * 1024 * 1024,
    'max_total_response_bytes' => 32 * 1024 * 1024,
    'max_run_seconds' => 600,
];
