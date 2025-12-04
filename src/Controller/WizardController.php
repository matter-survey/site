<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DeviceRepository;
use App\Repository\DeviceTypeRepository;
use App\Service\WizardAnalyticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WizardController extends AbstractController
{
    private const SESSION_COOKIE_NAME = 'wizard_session';
    private const TOTAL_STEPS = 3;

    /**
     * Category metadata for display.
     *
     * @var array<string, array{icon: string, desc: string}>
     */
    private const CATEGORY_META = [
        'Lights' => ['icon' => 'lightbulb', 'desc' => 'Smart bulbs, dimmers, and light strips'],
        'Climate' => ['icon' => 'thermometer', 'desc' => 'Thermostats, fans, and air quality'],
        'Sensors' => ['icon' => 'sensor', 'desc' => 'Motion, contact, and environmental sensors'],
        'Security' => ['icon' => 'shield', 'desc' => 'Locks, cameras, and alarm systems'],
        'Appliances' => ['icon' => 'appliance', 'desc' => 'Kitchen and household appliances'],
        'Entertainment' => ['icon' => 'tv', 'desc' => 'Speakers, TVs, and media players'],
        'Energy' => ['icon' => 'bolt', 'desc' => 'Plugs, meters, and energy management'],
    ];

    public function __construct(
        private readonly DeviceTypeRepository $deviceTypeRepo,
        private readonly DeviceRepository $deviceRepo,
        private readonly WizardAnalyticsService $analyticsService,
    ) {
    }

    #[Route('/wizard', name: 'wizard_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $step = max(1, min(self::TOTAL_STEPS, $request->query->getInt('step', 1)));
        $wizardState = $this->buildWizardState($request);

        // Validate navigation (can't skip steps)
        $maxAllowedStep = $this->getMaxAllowedStep($wizardState);
        if ($step > $maxAllowedStep) {
            return $this->redirectToRoute('wizard_index', ['step' => $maxAllowedStep]);
        }

        // Get or create session ID for analytics
        $sessionId = $this->getOrCreateSessionId($request);

        // Record analytics if advancing to a new step
        if ($step > 1) {
            $this->analyticsService->recordStep($sessionId, $step, $wizardState);
        }

        // Get step-specific data
        $stepData = match ($step) {
            1 => $this->getStep1Data(),
            2 => $this->getStep2Data(),
            default => $this->getStep3Data($wizardState),
        };

        $response = $this->render('wizard/index.html.twig', [
            'step' => $step,
            'wizardState' => $wizardState,
            'stepData' => $stepData,
            'totalSteps' => self::TOTAL_STEPS,
            'categoryMeta' => self::CATEGORY_META,
        ]);

        // Set session cookie if new
        if (!$request->cookies->has(self::SESSION_COOKIE_NAME)) {
            $response->headers->setCookie(
                Cookie::create(self::SESSION_COOKIE_NAME)
                    ->withValue($sessionId)
                    ->withExpires(new \DateTime('+1 hour'))
                    ->withPath('/')
                    ->withSameSite('lax')
            );
        }

        return $response;
    }

    #[Route('/wizard/results', name: 'wizard_results', methods: ['GET'])]
    public function results(Request $request): Response
    {
        $wizardState = $this->buildWizardState($request);

        // Record completion
        $sessionId = $request->cookies->get(self::SESSION_COOKIE_NAME);
        if (null !== $sessionId) {
            $this->analyticsService->recordStep($sessionId, self::TOTAL_STEPS + 1, $wizardState);
            $this->analyticsService->recordCompletion($sessionId);
        }

        // Build filters for device index
        $filters = $this->buildFiltersFromWizardState($wizardState);

        return $this->redirectToRoute('device_index', $filters);
    }

    #[Route('/wizard/device-search', name: 'wizard_device_search', methods: ['GET'])]
    public function deviceSearch(Request $request): JsonResponse
    {
        $query = trim($request->query->getString('q', ''));

        if (\strlen($query) < 2) {
            return $this->json(['results' => []]);
        }

        $devices = $this->deviceRepo->searchDevices($query, 10);

        return $this->json([
            'results' => array_map(fn (array $d) => [
                'id' => $d['id'],
                'name' => $d['product_name'],
                'vendor' => $d['vendor_name'],
            ], $devices),
        ]);
    }

    /**
     * Build wizard state from request parameters.
     *
     * @return array<string, mixed>
     */
    private function buildWizardState(Request $request): array
    {
        return [
            'category' => $request->query->getString('category', ''),
            'connectivity' => $request->query->all('connectivity'),
            'min_rating' => $request->query->getInt('min_rating', 0),
            'binding' => $request->query->get('binding'),
            'owned' => array_filter(array_map('intval', $request->query->all('owned'))),
        ];
    }

    /**
     * Get the maximum step allowed based on wizard state.
     */
    private function getMaxAllowedStep(array $state): int
    {
        if (empty($state['category'])) {
            return 1;
        }

        return self::TOTAL_STEPS;
    }

    /**
     * Get or create session ID for analytics tracking.
     */
    private function getOrCreateSessionId(Request $request): string
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE_NAME);

        if (null === $sessionId) {
            $sessionId = $this->analyticsService->generateSessionId();
        }

        return $sessionId;
    }

    /**
     * Get data for step 1 (category selection).
     *
     * @return array<string, mixed>
     */
    private function getStep1Data(): array
    {
        $categories = $this->deviceTypeRepo->findAllDisplayCategories();

        // Filter out System category
        $categories = array_filter($categories, fn ($c) => 'System' !== $c);

        return [
            'categories' => array_values($categories),
        ];
    }

    /**
     * Get data for step 2 (feature preferences).
     *
     * @return array<string, mixed>
     */
    private function getStep2Data(): array
    {
        return [
            'connectivityOptions' => $this->deviceRepo->getConnectivityFacets(),
            'ratingOptions' => $this->deviceRepo->getStarRatingFacets(),
            'bindingOptions' => $this->deviceRepo->getBindingFacets(),
        ];
    }

    /**
     * Get data for step 3 (compatibility).
     *
     * @return array<string, mixed>
     */
    private function getStep3Data(array $state): array
    {
        $ownedDevices = [];

        foreach ($state['owned'] as $deviceId) {
            $device = $this->deviceRepo->getDevice($deviceId);
            if (null !== $device) {
                $ownedDevices[] = $device;
            }
        }

        return [
            'ownedDevices' => $ownedDevices,
        ];
    }

    /**
     * Convert wizard state to device listing filters.
     *
     * @return array<string, mixed>
     */
    private function buildFiltersFromWizardState(array $state): array
    {
        $filters = [];

        // Map category to device type IDs
        if (!empty($state['category'])) {
            $deviceTypes = $this->deviceTypeRepo->findByDisplayCategory($state['category']);
            if (!empty($deviceTypes)) {
                $filters['device_types'] = array_map(fn ($dt) => $dt->getId(), $deviceTypes);
            }
        }

        // Pass through connectivity filter
        if (!empty($state['connectivity'])) {
            $filters['connectivity'] = $state['connectivity'];
        }

        // Pass through rating filter
        if ($state['min_rating'] > 0) {
            $filters['min_rating'] = $state['min_rating'];
        }

        // Pass through binding filter
        if (null !== $state['binding'] && '' !== $state['binding']) {
            $filters['binding'] = $state['binding'];
        }

        // Compatibility with owned devices
        if (!empty($state['owned'])) {
            $filters['compatible_with'] = $state['owned'];
        }

        return $filters;
    }
}
