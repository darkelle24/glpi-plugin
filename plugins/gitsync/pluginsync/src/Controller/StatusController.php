<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace GlpiPlugin\Pluginsync\Controller;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Pluginsync\Access;
use GlpiPlugin\Pluginsync\Client;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
final class StatusController extends AbstractController
{
    #[Route('/Statuses', name: 'statuses', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        Access::check();
        try {
            $data = (new Client())->jobs($request->query->getInt('page', 1));
            return new JsonResponse($data, 200, ['Cache-Control' => 'no-store, private']);
        } catch (RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 503, ['Cache-Control' => 'no-store']);
        }
    }
}
