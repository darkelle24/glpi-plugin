<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace GlpiPlugin\Pluginsync\Controller;
use Glpi\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class AssetsController extends AbstractController
{
    #[Route('/Assets/{file}', name: 'assets', requirements: ['file' => 'app\.(css|js)'], methods: ['GET'])]
    public function __invoke(string $file): Response
    {
        // Fixed allowlist, even when invoked outside the router by a test.
        if (!in_array($file, ['app.css', 'app.js'], true)) {
            return new Response('', 404);
        }
        return new Response(file_get_contents(__DIR__ . '/../../public/' . $file), 200, [
            'Content-Type' => $file === 'app.css' ? 'text/css; charset=utf-8' : 'application/javascript; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
