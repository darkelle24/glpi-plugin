<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace GlpiPlugin\Pluginsync\Controller;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Pluginsync\Access;
use GlpiPlugin\Pluginsync\Client;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
final class JobController extends AbstractController
{
    public function __construct(private readonly UrlGeneratorInterface $router) {}
    #[Route('/Job', name: 'job', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        Access::check();
        Access::consumeCsrf($request->request->getString('sync_csrf'));
        $values = null;
        try {
            $client = new Client();
            $action = $request->request->getString('action');
            $id = $request->request->getInt('id', 0);
            if ($action === 'save') {
                $values = [
                    'id' => $id, 'name' => $request->request->getString('name'),
                    'url' => $request->request->getString('url'),
                    'branch' => $request->request->getString('branch', 'main'),
                    'path' => $request->request->getString('path'),
                    'script' => $request->request->getString('script'),
                    'auto_pull' => $request->request->getBoolean('auto_pull'),
                    'interval_minutes' => $request->request->getInt('interval_minutes', 15),
                ];
                $client->save($values);
                $message = 'Dépôt enregistré.';
            } elseif ($action === 'toggle' && $id > 0) {
                $current = $client->job($id);
                $fields = array_intersect_key($current, array_flip([
                    'id', 'name', 'url', 'branch', 'path', 'script', 'interval_minutes',
                ]));
                $fields['auto_pull'] = $request->request->getBoolean('auto_pull');
                $client->save($fields);
                $message = $fields['auto_pull'] ? 'Synchronisation automatique activée.' : 'Synchronisation automatique désactivée.';
            } elseif ($action === 'run' && $id > 0) {
                $client->run($id);
                $message = 'Synchronisation mise en attente. Le statut se met à jour automatiquement.';
            } elseif ($action === 'delete' && $id > 0 && $request->request->getBoolean('confirm_delete')) {
                $client->delete($id);
                $message = 'Dépôt retiré de la liste. Ses fichiers clonés sont conservés.';
            } else { throw new RuntimeException('Action invalide ou confirmation manquante.'); }
            $_SESSION['pluginsync_notice'] = ['ok' => true, 'text' => $message];
        } catch (RuntimeException $e) {
            $_SESSION['pluginsync_notice'] = ['ok' => false, 'text' => $e->getMessage()];
            if ($values !== null) {
                // Preserve only bounded non-secret form values after validation errors.
                $_SESSION['pluginsync_draft'] = array_map(
                    static fn($v) => is_string($v) ? mb_substr($v, 0, 2048) : $v, $values
                );
            }
        }
        $page = max(1, min(999999999, $request->request->getInt('page', 1)));
        return new RedirectResponse($this->router->generate('@pluginsync:admin', ['page' => $page]), 303,
                                    ['Cache-Control' => 'no-store']);
    }
}
