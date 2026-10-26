<?php
use Glpi\Kernel\Kernel;
use MailSignatureCleaner\Cleaner;
use MailSignatureCleaner\Settings;

// GLPI 12 boots and authenticates plugin PHP routes through public/index.php.
if (!class_exists(Kernel::class, false) || !defined('GLPI_ROOT')) {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/bootstrap.php';
Session::checkRight('config', UPDATE);
if (!Plugin::isPluginActive('mailsignaturecleaner')) {
    http_response_code(403);
    exit;
}
header('Cache-Control: no-store');

function msc_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$settings = Settings::load();
$sample = "<p>Bonjour, le problème est résolu. Merci pour votre intervention.</p>\n"
    . '<div class="gmail_signature">Cordialement, Alice</div>';
$result = null;
$message = '';
$error = '';
// Additional session token, independent of deprecated GLPI 10 CSRF APIs.
$_SESSION['msc_form_token'] ??= bin2hex(random_bytes(32));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!is_string($_POST['msc_token'] ?? null)
        || !hash_equals($_SESSION['msc_form_token'], $_POST['msc_token'])) {
        http_response_code(403);
        exit('Session expirée ou requête invalide. Rechargez la page.');
    }
    try {
        $candidate = Settings::validate($_POST);
        $settings = $candidate;
        if (($_POST['action'] ?? '') === 'save') {
            Config::setConfigurationValues(Settings::CONTEXT, $candidate);
            $message = 'Configuration enregistrée.';
        } elseif (($_POST['action'] ?? '') === 'test') {
            if (!is_string($_POST['sample'] ?? null) || strlen($_POST['sample']) > Cleaner::MAX_BYTES) {
                throw new InvalidArgumentException('Le HTML de test doit faire au maximum 1 Mio.');
            }
            $sample = $_POST['sample'];
            $result = (new Cleaner())->clean($sample, $candidate);
            $message = 'Test réalisé avec les règles affichées, sans enregistrer la configuration.';
        } else {
            throw new InvalidArgumentException('Action inconnue.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        // Keep submitted form fields for correction, without persisting invalid selectors.
        foreach (array_keys(Settings::defaults()) as $key) {
            if (is_string($_POST[$key] ?? null) && strlen($_POST[$key]) <= 26000) {
                $settings[$key] = $_POST[$key];
            }
        }
        if (is_string($_POST['sample'] ?? null) && strlen($_POST['sample']) <= Cleaner::MAX_BYTES) {
            $sample = $_POST['sample'];
        }
    }
}
Html::header('Mail Signature Cleaner', '', 'config', 'plugins');
echo '<div class="container-xl"><h1>Mail Signature Cleaner</h1>';
echo '<p>Nettoyage des nouveaux suivis de tickets issus des collecteurs mail. '
    . 'Les tickets initiaux et les suivis saisis dans GLPI sont exclus.</p>';
if ($message !== '') {
    echo '<div class="alert alert-success" role="status">' . msc_escape($message) . '</div>';
}
if ($error !== '') {
    echo '<div class="alert alert-danger" role="alert">' . msc_escape($error) . '</div>';
}
echo '<form method="post" action="' . msc_escape($CFG_GLPI['root_doc']
    . '/plugins/mailsignaturecleaner/front/config.php') . '">';
echo '<input type="hidden" name="msc_token" value="' . msc_escape($_SESSION['msc_form_token']) . '">';
foreach (['enabled' => 'Activer le nettoyage', 'dry_run' => 'Simulation : conserver le contenu des suivis',
    'log' => 'Journal technique sans contenu des messages'] as $key => $label) {
    echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="'
        . $key . '" id="' . $key . '" value="1"'
        . ($settings[$key] === '1' ? ' checked' : '') . '><label class="form-check-label" for="'
        . $key . '">' . $label . '</label></div>';
}
foreach (['selectors' => 'Sélecteurs CSS à supprimer — un par ligne',
    'cut_after_selectors' => 'Couper tout ce qui suit ce repère CSS — un par ligne (optionnel)',
    'protect_selectors' => 'Sélecteurs CSS à protéger — un par ligne',
    'markers' => 'Marqueurs textuels à préserver — un par ligne'] as $key => $label) {
    echo '<div class="mb-3"><label class="form-label" for="' . $key . '">' . $label . '</label>'
        . '<textarea class="form-control font-monospace" id="' . $key . '" name="' . $key
        . '" rows="5">' . msc_escape($settings[$key]) . '</textarea></div>';
}
echo '<p>Exemples : <code>div.signature &gt; table</code>, <code>[id^="Signature"]</code>, '
    . '<code>div.signature:not(.conserver)</code>, <code>table.signature tr:nth-child(2)</code>. '
    . 'Les pseudo-éléments, <code>:hover</code> et <code>:has()</code> ne sont pas pris en charge. '
    . 'Une ligne commençant par <code>//</code> est un commentaire. '
    . 'La suppression normale retire seulement le bloc ciblé. La coupure retire ce qui suit le repère '
    . 'dans tout le corps HTML, y compris hors de son parent ; le repère est conservé. '
    . 'Si un repère correspond plusieurs fois, le nettoyage est annulé. Les protections restent actives.</p>';
echo '<div class="mb-3"><label class="form-label" for="max_removed_percent">Part maximale supprimée (%)</label>'
    . '<input class="form-control" type="number" min="1" max="95" name="max_removed_percent" '
    . 'id="max_removed_percent" value="' . msc_escape($settings['max_removed_percent']) . '"></div>';
echo '<p>Les citations blockquote, les pièces jointes GLPI reconnues et les deux marqueurs GLPI sont toujours protégés. '
    . 'Ces garde-fous ne peuvent pas reconnaître tout contenu légitime : testez vos règles sur des messages réels.</p>';
echo '<button class="btn btn-primary mb-4" type="submit" name="action" value="save">Enregistrer</button>';
echo '<h2>Test avant / après</h2><p>Collez le corps HTML reçu par GLPI (après traitement du collecteur). '
    . 'Le test fonctionne même si le nettoyage est désactivé ou en simulation. Aucun suivi n’est créé.</p>';
echo '<label class="form-label" for="sample">HTML à tester</label><textarea class="form-control font-monospace" '
    . 'id="sample" name="sample" rows="9">' . msc_escape($sample) . '</textarea>';
echo '<button class="btn btn-secondary my-3" type="submit" name="action" value="test">Tester sans enregistrer</button></form>';
if ($result !== null) {
    $statuses = ['cleaned' => 'Nettoyage appliqué', 'no_match' => 'Aucune correspondance',
        'ambiguous_cut' => 'Refus : plusieurs points de coupure pour une même règle',
        'invalid_cut' => 'Refus : point de coupure hors du corps du message',
        'protected' => 'Correspondances protégées', 'empty_guard' => 'Refus : message vidé',
        'ratio_guard' => 'Refus : part supprimée trop importante', 'marker_guard' => 'Refus : marqueur altéré',
        'too_many_matches' => 'Refus : plus de 100 éléments ciblés', 'too_large' => 'Message trop volumineux',
        'plain_text' => 'Texte brut : inchangé', 'parse_failed' => 'HTML illisible',
        'unsafe_declaration' => 'Déclaration HTML/XML refusée', 'error' => 'Erreur de traitement'];
    echo '<div class="alert alert-info">' . msc_escape($statuses[$result['status']] ?? $result['status'])
        . ' — blocs supprimés : ' . $result['removed'] . ' ; correspondances protégées : ' . $result['protected'] . '</div>';
    echo '<table class="table"><thead><tr><th>Sélecteur</th><th>Correspondances</th></tr></thead><tbody>';
    foreach ($result['matches'] as $selector => $count) {
        echo '<tr><td><code>' . msc_escape($selector) . '</code></td><td>' . $count . '</td></tr>';
    }
    echo '</tbody></table><div class="row">';
    foreach (['Avant' => $sample, 'Après' => $result['html']] as $label => $content) {
        // Source displayed as text: no script, tracking pixels, CSS or external requests.
        echo '<section class="col-md-6"><h3>' . $label . '</h3><pre class="border rounded p-3 text-wrap">'
            . msc_escape($content) . '</pre></section>';
    }
    echo '</div><p>Le HTML est affiché comme texte pour permettre la comparaison sans exécuter le contenu du mail.</p>';
}
echo '</div>';
Html::footer();
