<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once dirname(__DIR__, 3) . '/inc/includes.php';
require_once dirname(__DIR__) . '/inc/config.class.php';

Session::checkRight('config', UPDATE);
if (!Plugin::isPluginActive('zeetim')) {
    Html::displayErrorAndDie('Le plugin ZeeTim doit etre active.');
}
$url = Plugin::getWebDir('zeetim') . '/front/config.form.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // inc/includes.php validates the GLPI CSRF token before this code executes.
    try {
        if (!isset($_POST['save']) || !is_string($_POST['save'])) {
            throw new RuntimeException('Action de configuration invalide.');
        }
        PluginZeetimConfig::save(\Glpi\Toolbox\Sanitizer::unsanitize($_POST));
        Session::addMessageAfterRedirect('Parametres ZeeTim enregistres.', false, INFO);
    } catch (Throwable $exception) {
        // Never store POST data or the secret in session messages or logs.
        // Validation errors are intentionally generic to avoid reflecting input.
        $message = $exception instanceof PluginZeetimConfigException
            ? $exception->getMessage() : 'Echec de l enregistrement. Consultez les journaux GLPI.';
        Session::addMessageAfterRedirect(
            htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false, ERROR
        );
    }
    Html::redirect($url);
    exit;
}
Html::header('ZeeTim WMC', $_SERVER['PHP_SELF'], 'config', 'plugins');
PluginZeetimConfig::showForm();
Html::footer();
