<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (!defined('GLPI_ROOT')) {
    http_response_code(403);
    exit;
}

class PluginZeetimConfigException extends RuntimeException
{
}

class PluginZeetimConfig
{
    public static function defaults(): array
    {
        return require dirname(__DIR__) . '/config.php';
    }

    // label, kind, minimum, maximum; all defaults except the secret have a control.
    public static function fields(): array
    {
        return [
            'enabled' => ['Synchronisation active', 'bool'],
            'base_url' => ['URL HTTPS de WMC', 'url'],
            'entities_id' => ['Entité GLPI cible', 'entity', 0, PHP_INT_MAX],
            'dry_run' => ['Mode simulation (aucune écriture)', 'bool'],
            'endpoint' => ['Route API', 'route'],
            'items_path' => ['Chemin de la liste JSON (vide = racine)', 'path'],
            'serial_path' => ['Champ numéro de série', 'required_path'],
            'name_path' => ['Champ nom (facultatif)', 'path'],
            'uuid_path' => ['Champ UUID (facultatif)', 'path'],
            'next_path' => ['Champ URL de page suivante (facultatif)', 'path'],
            'total_path' => ['Champ nombre total (facultatif)', 'path'],
            'ca_file' => ['Fichier PEM de certification sur le serveur (facultatif)', 'file'],
            'connect_timeout' => ['Délai de connexion, en secondes', 'int', 1, 120],
            'request_timeout' => ['Délai par requete, en secondes', 'int', 1, 300],
            'max_pages' => ['Nombre maximal de pages', 'int', 1, 10000],
            'max_records' => ['Nombre maximal de terminaux', 'int', 1, 1000000],
            'max_response_bytes' => ['Taille maximale par page, en octets', 'int', 1024, 67108864],
            'max_total_response_bytes' => ['Taille maximale totale, en octets', 'int', 1024, 268435456],
            'max_run_seconds' => ['Durée maximale du passage, en secondes', 'int', 1, 3600],
        ];
    }

    public static function get(bool $withToken = false): array
    {
        $config = self::defaults();
        $saved = Config::getConfigurationValues('plugin:zeetim');
        foreach (self::fields() as $name => $definition) {
            if (!array_key_exists($name, $saved)) {
                continue;
            }
            $value = \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars((string) $saved[$name]);
            $kind = $definition[1];
            $config[$name] = $kind === 'bool' ? $value === '1'
                : (in_array($kind, ['int', 'entity'], true) ? (int) $value : $value);
        }
        // Saved values, including an explicitly erased token, override env/defaults.
        $hasSavedToken = array_key_exists('token', $saved);
        $config['_has_token'] = $hasSavedToken ? (string) $saved['token'] !== ''
            : $config['token'] !== '';
        if (!$withToken) {
            unset($config['token']);
            return $config;
        }
        if ($hasSavedToken) {
            $config['token'] = '';
            if ($saved['token'] !== '') {
                $keyService = new GLPIKey();
                $key = $keyService->get();
                if ($key === null) {
                    throw new PluginZeetimConfigException('Clé de chiffrement GLPI indisponible.');
                }
                $token = $keyService->decrypt((string) $saved['token'], $key);
                if (!is_string($token) || $token === '') {
                    throw new PluginZeetimConfigException('Impossible de déchiffrer le jeton WMC enregistré.');
                }
                $config['token'] = $token;
            }
        }
        return $config;
    }

    public static function save(array $input): void
    {
        Session::checkRight('config', UPDATE);
        $values = [];
        foreach (self::fields() as $name => $definition) {
            if (!array_key_exists($name, $input) || !is_string($input[$name])) {
                throw new PluginZeetimConfigException('Champ manquant ou invalide : ' . $definition[0]);
            }
            $value = trim($input[$name]);
            $kind = $definition[1];
            if ($kind === 'bool') {
                if (!in_array($value, ['0', '1'], true)) {
                    throw new PluginZeetimConfigException('Valeur oui/non invalide.');
                }
            } elseif (in_array($kind, ['int', 'entity'], true)) {
                if (filter_var($value, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => $definition[2], 'max_range' => $definition[3]],
                ]) === false) {
                    throw new PluginZeetimConfigException('Nombre invalide : ' . $definition[0]);
                }
            } elseif (strlen($value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new PluginZeetimConfigException('Texte trop long ou invalide : ' . $definition[0]);
            }
            if (in_array($kind, ['path', 'required_path'], true)) {
                if (($kind === 'required_path' && $value === '')
                    || ($value !== '' && !preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/D', $value))) {
                    throw new PluginZeetimConfigException('Chemin JSON invalide : ' . $definition[0]);
                }
            }
            $values[$name] = $value;
        }
        $values['base_url'] = rtrim($values['base_url'], '/');
        $url = parse_url($values['base_url']);
        if (!$url || strtolower($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query'])
            || isset($url['fragment']) || preg_match('/\s/', $values['base_url'])) {
            throw new PluginZeetimConfigException('Saisissez une URL HTTPS sans identifiants, requete ni fragment.');
        }
        if (!preg_match('#^/(?!/)[A-Za-z0-9/_-]+$#D', $values['endpoint'])) {
            throw new PluginZeetimConfigException('La route API doit être un chemin absolu sans requete.');
        }
        $entity = new Entity();
        $entityId = (int) $values['entities_id'];
        if (!$entity->getFromDB($entityId) || !Session::haveAccessToEntity($entityId)) {
            throw new PluginZeetimConfigException('Entité inexistante ou hors de votre périmètre actif.');
        }
        if ($values['ca_file'] !== '' && !is_readable($values['ca_file'])) {
            throw new PluginZeetimConfigException('Le fichier PEM indiqué est illisible pour PHP.');
        }
        if ((int) $values['max_total_response_bytes'] < (int) $values['max_response_bytes']) {
            throw new PluginZeetimConfigException('La taille totale doit être supérieure ou égale à celle par page.');
        }
        if (!isset($input['token']) || !is_string($input['token'])
            || (isset($input['erase_token']) && !is_string($input['erase_token']))) {
            throw new PluginZeetimConfigException('Champ jeton invalide.');
        }
        $token = trim($input['token']);
        $erase = ($input['erase_token'] ?? '') === '1';
        if ($erase && $token !== '') {
            throw new PluginZeetimConfigException('Choisissez de remplacer ou de supprimer le jeton.');
        }
        if ($token === '0' || strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new PluginZeetimConfigException('Le jeton est trop long ou contient des espaces.');
        }
        $current = self::get(false);
        if ($values['enabled'] === '1' && ($erase || ($token === '' && !$current['_has_token']))) {
            throw new PluginZeetimConfigException('Un jeton est requis pour activer la synchronisation.');
        }
        $stored = [];
        foreach ($values as $name => $value) {
            $stored[$name] = \Glpi\Toolbox\Sanitizer::sanitize($value);
        }
        if ($token !== '') {
            $keyService = new GLPIKey();
            if (!$keyService->isConfigSecured('plugin:zeetim', 'token') || $keyService->get() === null) {
                throw new PluginZeetimConfigException('Le chiffrement GLPI du jeton est indisponible.');
            }
            // Config::setConfigurationValues encrypts this raw secret itself.
            $stored['token'] = $token;
        } elseif ($erase) {
            $stored['token'] = '';
        }
        Config::setConfigurationValues('plugin:zeetim', $stored);
        // Core's setter returns void: verify persisted values before reporting success.
        $persisted = Config::getConfigurationValues('plugin:zeetim');
        foreach ($values as $name => $value) {
            if (!array_key_exists($name, $persisted)
                || \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars((string) $persisted[$name]) !== $value) {
                throw new PluginZeetimConfigException('Enregistrement incomplet. Verifiez les journaux GLPI puis reessayez.');
            }
        }
        if ($token !== '' && !hash_equals($token, self::get(true)['token'])) {
            throw new PluginZeetimConfigException('Le jeton enregistre ne correspond pas au jeton saisi.');
        }
        if ($erase && (!array_key_exists('token', $persisted) || $persisted['token'] !== '')) {
            throw new PluginZeetimConfigException('La suppression du jeton a echoue.');
        }
    }

    private static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function row(string $name, array $definition, array $config): void
    {
        $label = self::escape($definition[0]);
        echo '<tr><th scope="row">' . $label . '</th><td>';
        if ($definition[1] === 'bool') {
            Dropdown::showYesNo($name, (int) $config[$name]);
        } elseif ($definition[1] === 'entity') {
            Dropdown::show('Entity', [
                'name' => $name,
                'value' => $config[$name],
                'entity' => $_SESSION['glpiactiveentities'],
                'display_emptychoice' => false,
            ]);
        } else {
            $type = $definition[1] === 'int' ? 'number' : ($definition[1] === 'url' ? 'url' : 'text');
            echo '<input class="form-control" aria-label="' . $label . '" type="' . $type
                . '" name="' . self::escape($name) . '" value="' . self::escape($config[$name]) . '"';
            if ($type === 'number') {
                echo ' min="' . $definition[2] . '" max="' . $definition[3] . '" step="1" required';
            } else {
                echo ' maxlength="2048"';
            }
            echo '>';
        }
        echo '</td></tr>';
    }

    public static function showForm(): void
    {
        Session::checkRight('config', UPDATE);
        $config = self::get(false);
        $url = Plugin::getWebDir('zeetim') . '/front/config.form.php';
        echo '<div class="center"><form method="post" action="' . self::escape($url) . '">';
        echo '<table class="tab_cadre_fixe"><tr><th colspan="2">Paramètres ZeeTim WMC</th></tr>';
        $fields = self::fields();
        foreach (['enabled', 'base_url', 'entities_id', 'dry_run'] as $name) {
            self::row($name, $fields[$name], $config);
        }
        echo '<tr><th scope="row"><label for="zeetim-token">Jeton Bearer</label></th><td>';
        // Never echo the saved token, ciphertext, or an artificial masked value.
        echo '<input class="form-control" id="zeetim-token" type="password" name="token"'
            . ' value="" maxlength="8192" autocomplete="new-password">';
        echo '<p>' . ($config['_has_token'] ? 'Un jeton est configuré.' : 'Aucun jeton configuré.')
            . ' Laissez vide pour le conserver. Saisissez sa valeur brute, sans Bearer.</p>';
        echo '<label><input type="checkbox" name="erase_token" value="1"> Supprimer le jeton'
            . ' (désactivez aussi la synchronisation)</label></td></tr></table>';
        echo '<details class="mt-3"><summary>Paramètres avancés : API, champs JSON et limites</summary>';
        echo '<p>Adaptez les chemins JSON au contrat WMC. Un champ de pagination vide suppose'
            . ' une réponse complete sans pagination.</p><table class="tab_cadre_fixe">';
        foreach ($fields as $name => $definition) {
            if (!in_array($name, ['enabled', 'base_url', 'entities_id', 'dry_run'], true)) {
                self::row($name, $definition, $config);
            }
        }
        echo '</table></details>';
        echo '<input type="hidden" name="_glpi_csrf_token" value="'
            . self::escape(Session::getNewCSRFToken()) . '">';
        echo '<p><button class="btn btn-primary" type="submit" name="save" value="1">Enregistrer</button></p>';
        echo '</form><p>La fréquence et l’exécution se règlent dans '
            . '<a href="' . self::escape(CronTask::getSearchURL()) . '">Actions automatiques</a>'
            . ', tâche sync_zeetim (15 minutes par défaut).</p></div>';
    }
}
