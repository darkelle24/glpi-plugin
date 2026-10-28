<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace GlpiPlugin\Pluginsync\Controller;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Pluginsync\Access;
use GlpiPlugin\Pluginsync\Client;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class AdminController extends AbstractController
{
    #[Route('/Admin', name: 'admin', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        Access::check();
        $client = new Client();
        $data = null;
        $edit = null;
        $log = null;
        $error = null;
        try {
            $data = $client->jobs(max(1, $request->query->getInt('page', 1)));
            if ($request->query->getInt('edit', 0) > 0) {
                $edit = $client->job($request->query->getInt('edit'));
            }
            if ($request->query->getInt('logs', 0) > 0) {
                $log = $client->logs($request->query->getInt('logs'));
            }
        } catch (RuntimeException $e) { $error = $e->getMessage(); }
        $notice = $_SESSION['pluginsync_notice'] ?? null;
        $draft = $_SESSION['pluginsync_draft'] ?? null;
        unset($_SESSION['pluginsync_notice'], $_SESSION['pluginsync_draft']);
        $response = $this->render('@pluginsync/admin.html.twig', [
            'title' => 'Git Sync', 'menu' => ['config', 'plugins'], 'data' => $data,
            'error' => $error, 'edit' => $draft ?? $edit, 'log' => $log, 'notice' => $notice,
            'sync_csrf' => Access::csrfToken(),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
