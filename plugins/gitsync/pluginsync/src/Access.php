<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace GlpiPlugin\Pluginsync;

use Config;
use Plugin;
use Session;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class Access
{
    public static function check(): void
    {
        Session::checkCentralAccess();
        if (!Plugin::isPluginActive('pluginsync') || !Session::haveRight('config', UPDATE)) {
            throw new AccessDeniedHttpException();
        }
        $profiles = trim((string) getenv('PLUGINSYNC_ALLOWED_PROFILES'));
        if ($profiles !== '') {
            // A malformed allowlist fails closed. It can never grant a GLPI right.
            if (!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $profiles)
                || !in_array((string) ($_SESSION['glpiactiveprofile']['id'] ?? ''), explode(',', $profiles), true)) {
                throw new AccessDeniedHttpException();
            }
        }
        // GLPI 12: checks rights AND its native sudo / re-authentication window.
        (new Config())->checkGlobal(UPDATE);
    }

    public static function csrfToken(): string
    {
        $stored = $_SESSION['pluginsync_csrf'] ?? null;
        if (!is_array($stored) || ($stored['expires'] ?? 0) < time()) {
            $_SESSION['pluginsync_csrf'] = ['token' => bin2hex(random_bytes(32)), 'expires' => time() + 1800];
        }
        return $_SESSION['pluginsync_csrf']['token'];
    }

    public static function consumeCsrf(string $token): void
    {
        $stored = $_SESSION['pluginsync_csrf'] ?? null;
        if (!is_array($stored) || ($stored['expires'] ?? 0) < time()
            || !is_string($stored['token'] ?? null) || !hash_equals($stored['token'], $token)) {
            throw new AccessDeniedHttpException('Invalid Plugin Sync CSRF token');
        }
        unset($_SESSION['pluginsync_csrf']);
    }
}
