<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TelemetryDevice;
use App\Dto\TelemetrySubmission;
use App\Service\TelemetryService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class ApiController extends AbstractController
{
    public function __construct(
        private TelemetryService $telemetryService,
        private RateLimiterFactoryInterface $apiSubmitLimiter,
        private LoggerInterface $logger,
        private ValidatorInterface $validator,
    ) {}

    #[Route('/', name: 'api_docs_redirect', methods: ['GET'])]
    public function docsRedirect(): Response
    {
        return $this->redirect('/api/docs.html');
    }

    #[Route('/submit', name: 'api_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        // Rate limiting
        $clientIp = $request->getClientIp() ?? 'unknown';
        $limiter = $this->apiSubmitLimiter->create($clientIp);
        if (!$limiter->consume()->isAccepted()) {
            $this->logger->warning('API rate limit exceeded', ['ip' => $clientIp]);
            return $this->json(
                ['status' => 'error', 'error' => 'Rate limit exceeded. Please try again later.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        // Parse JSON
        $content = $request->getContent();
        if (empty($content)) {
            return $this->json(
                ['status' => 'error', 'error' => 'Empty request body'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $payload = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Invalid JSON in API request', [
                'ip' => $clientIp,
                'error' => json_last_error_msg(),
            ]);
            return $this->json(
                ['status' => 'error', 'error' => 'Invalid JSON: ' . json_last_error_msg()],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Map to DTO and validate
        $submission = $this->mapToSubmission($payload);
        $errors = $this->validator->validate($submission);

        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            $this->logger->warning('Validation failed for API request', [
                'ip' => $clientIp,
                'errors' => $errorMessages,
            ]);
            return $this->json(
                ['status' => 'error', 'error' => $errorMessages[0]],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Process submission
        $ipHash = hash('sha256', $clientIp);
        $result = $this->telemetryService->processSubmission($payload, $ipHash);

        if ($result['success']) {
            return $this->json([
                'status' => 'ok',
                'message' => $result['message'],
                'devices_processed' => $result['devices_processed'] ?? 0,
            ]);
        }

        return $this->json(
            ['status' => 'error', 'error' => $result['error']],
            Response::HTTP_BAD_REQUEST
        );
    }

    private function mapToSubmission(array $payload): TelemetrySubmission
    {
        $submission = new TelemetrySubmission();
        $submission->installation_id = $payload['installation_id'] ?? null;

        if (isset($payload['devices']) && is_array($payload['devices'])) {
            $submission->devices = array_map(function ($deviceData) {
                $device = new TelemetryDevice();
                $device->vendor_id = $deviceData['vendor_id'] ?? null;
                $device->vendor_name = $deviceData['vendor_name'] ?? null;
                $device->product_id = $deviceData['product_id'] ?? null;
                $device->product_name = $deviceData['product_name'] ?? null;
                $device->hardware_version = $deviceData['hardware_version'] ?? null;
                $device->software_version = $deviceData['software_version'] ?? null;
                $device->endpoints = $deviceData['endpoints'] ?? null;
                return $device;
            }, $payload['devices']);
        }

        return $submission;
    }
}
