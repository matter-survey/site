<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DatabaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController extends AbstractController
{
    public function __construct(
        private DatabaseService $databaseService,
    ) {
    }

    #[Route('/health', name: 'health_check', methods: ['GET'])]
    public function check(): JsonResponse
    {
        $status = 'healthy';
        $checks = [];

        // Check database connectivity
        try {
            $db = $this->databaseService->getConnection();
            $db->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['database'] = 'error';
            $status = 'unhealthy';
        }

        return $this->json([
            'status' => $status,
            'checks' => $checks,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], 'healthy' === $status ? 200 : 503);
    }
}
