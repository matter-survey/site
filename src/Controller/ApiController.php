<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TelemetryDevice;
use App\Dto\TelemetrySubmission;
use App\Service\TelemetryService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ApiController extends AbstractController
{
    public function __construct(
        private readonly TelemetryService $telemetryService,
        #[Target('api_submit')]
        private readonly RateLimiterFactoryInterface $apiSubmitLimiter,
        private readonly LoggerInterface $logger,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/', name: 'api_docs_redirect', methods: ['GET'])]
    public function docsRedirect(): Response
    {
        return $this->redirect('/api/docs.html');
    }

    #[Route('/api/submit', name: 'api_submit', methods: ['POST'])]
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

        try {
            $payload = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Read the message from the exception: json_last_error_msg() is reset
            // by any json_encode() in between (e.g. the logger's formatter).
            $this->logger->warning('Invalid JSON in API request', [
                'ip' => $clientIp,
                'error' => $e->getMessage(),
            ]);

            return $this->json(
                ['status' => 'error', 'error' => 'Invalid JSON: '.$e->getMessage()],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!is_array($payload) || (array_is_list($payload) && [] !== $payload)) {
            return $this->json(
                ['status' => 'error', 'error' => 'Request body must be a JSON object'],
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

    /**
     * @param array<mixed> $payload
     */
    private function mapToSubmission(array $payload): TelemetrySubmission
    {
        $submission = new TelemetrySubmission();
        $submission->installation_id = $payload['installation_id'] ?? null;
        $submission->devices = $payload['devices'] ?? null;

        if (is_array($payload['devices'] ?? null)) {
            // Non-object entries are kept as-is and rejected by the validator.
            $submission->devices = array_map(static function (mixed $deviceData): mixed {
                if (!is_array($deviceData)) {
                    return $deviceData;
                }

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
