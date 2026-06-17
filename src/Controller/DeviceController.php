<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DeviceRepository;
use App\Repository\ProductRepository;
use App\Service\CapabilityService;
use App\Service\DeviceScoreService;
use App\Service\MatterRegistry;
use App\Service\TelemetryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DeviceController extends AbstractController
{
    public function __construct(
        private readonly DeviceRepository $deviceRepo,
        private readonly ProductRepository $productRepo,
        private readonly TelemetryService $telemetryService,
        private readonly MatterRegistry $matterRegistry,
        private readonly DeviceScoreService $deviceScoreService,
        private readonly CapabilityService $capabilityService,
    ) {
    }

    #[Route('/', name: 'device_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        // Build filters from request
        $filters = $this->buildFiltersFromRequest($request);
        $hasFilters = $this->hasActiveFilters($filters);

        // Get filtered devices
        $devices = $this->deviceRepo->getFilteredDevices($filters, $perPage, $offset);
        $totalDevices = $this->deviceRepo->getFilteredDeviceCount($filters);

        $totalPages = max(1, (int) ceil($totalDevices / $perPage));
        $stats = $this->telemetryService->getStats();

        // Get facet data for filters
        $facets = [
            'connectivity' => $this->deviceRepo->getConnectivityFacets(),
            'coordination' => $this->deviceRepo->getCoordinationFacets(),
            'vendors' => $this->deviceRepo->getVendorFacets(15),
            'device_types' => $this->deviceRepo->getDeviceTypeFacets(15),
            'star_ratings' => $this->deviceRepo->getStarRatingFacets(),
            'capabilities' => $this->deviceRepo->getCapabilityFacets(),
        ];

        // Fetch cached device scores for display
        $deviceIds = array_column($devices, 'id');
        $deviceScores = $this->deviceScoreService->getCachedScoresForDevices($deviceIds);

        // Build device type names map for filtered types (may not be in facets)
        $deviceTypeNames = [];
        if (!empty($filters['device_types'])) {
            foreach ($filters['device_types'] as $typeId) {
                $deviceTypeNames[$typeId] = $this->matterRegistry->getDeviceTypeName((int) $typeId);
            }
        }

        return $this->render('device/index.html.twig', [
            'devices' => $devices,
            'stats' => $stats,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalDevices' => $totalDevices,
            'filters' => $filters,
            'facets' => $facets,
            'hasFilters' => $hasFilters,
            'deviceScores' => $deviceScores,
            'deviceTypeNames' => $deviceTypeNames,
        ]);
    }

    /**
     * Build filters array from request parameters.
     */
    private function buildFiltersFromRequest(Request $request): array
    {
        $filters = [];

        // Search query
        $search = trim($request->query->getString('q', ''));
        if ('' !== $search) {
            $filters['search'] = $search;
        }

        // Connectivity types (array)
        $connectivity = $request->query->all('connectivity');
        if ([] !== $connectivity) {
            $filters['connectivity'] = array_filter($connectivity, fn ($v): bool => \in_array($v, ['thread', 'wifi', 'ethernet'], true));
        }

        // Coordination filters (binding, groups, scenes) — independent toggles
        foreach (['binding', 'groups', 'scenes'] as $coordFeature) {
            $value = $request->query->get($coordFeature);
            if ('1' === $value) {
                $filters[$coordFeature] = true;
            } elseif ('0' === $value) {
                $filters[$coordFeature] = false;
            }
        }

        // Vendor filter (check for non-empty before getInt to avoid error on empty string)
        $vendorParam = $request->query->get('vendor', '');
        if ('' !== $vendorParam && is_numeric($vendorParam)) {
            $vendor = (int) $vendorParam;
            if ($vendor > 0) {
                $filters['vendor'] = $vendor;
            }
        }

        // Device type filter (array of IDs)
        $deviceTypes = $request->query->all('device_types');
        if ([] !== $deviceTypes) {
            $filters['device_types'] = array_filter(
                array_map(intval(...), $deviceTypes),
                fn ($v): bool => $v > 0
            );
        }

        // Minimum star rating filter
        $minRatingParam = $request->query->get('min_rating', '');
        if ('' !== $minRatingParam && is_numeric($minRatingParam)) {
            $minRating = (int) $minRatingParam;
            if ($minRating >= 1 && $minRating <= 5) {
                $filters['min_rating'] = $minRating;
            }
        }

        // Capability filters (array of capability keys)
        $capabilities = $request->query->all('capabilities');
        if ([] !== $capabilities) {
            // Validate against known capability keys
            $validKeys = array_keys(DeviceRepository::CAPABILITY_FILTERS);
            $filters['capabilities'] = array_values(array_filter(
                $capabilities,
                fn ($v): bool => \in_array($v, $validKeys, true)
            ));
        }

        return $filters;
    }

    /**
     * Check if any filters are active.
     */
    private function hasActiveFilters(array $filters): bool
    {
        return !empty($filters['connectivity'])
            || isset($filters['binding'])
            || isset($filters['groups'])
            || isset($filters['scenes'])
            || !empty($filters['vendor'])
            || !empty($filters['device_types'])
            || !empty($filters['search'])
            || !empty($filters['min_rating'])
            || !empty($filters['capabilities']);
    }

    /**
     * Search API endpoint for autocomplete.
     */
    #[Route('/api/search', name: 'api_search', methods: ['GET'])]
    public function searchAutocomplete(Request $request): JsonResponse
    {
        $query = trim($request->query->getString('q', ''));

        if (\strlen($query) < 2) {
            return $this->json(['results' => []]);
        }

        $devices = $this->deviceRepo->searchDevices($query, 8);

        return $this->json([
            'results' => array_map(function (array $d): array {
                $name = $d['product_name'];
                if (!empty($d['is_name_ambiguous']) && isset($d['product_id'])) {
                    $name .= sprintf(' (PID 0x%04X)', (int) $d['product_id']);
                }

                return [
                    'name' => $name,
                    'vendor' => $d['vendor_name'],
                    'url' => $this->generateUrl('device_show', ['slug' => $d['slug']]),
                ];
            }, $devices),
        ]);
    }

    /**
     * Redirect old ID-based URLs to new slug-based URLs.
     */
    #[Route('/device/{id}', name: 'device_show_legacy', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showLegacy(int $id): Response
    {
        $device = $this->deviceRepo->getDevice($id);

        if (!$device) {
            throw $this->createNotFoundException('Device not found');
        }

        // Redirect to slug-based URL
        if (!empty($device['slug'])) {
            return $this->redirectToRoute('device_show', ['slug' => $device['slug']], Response::HTTP_MOVED_PERMANENTLY);
        }

        // Fallback: generate slug and redirect
        $slug = \App\Entity\Product::generateSlug(
            $device['product_name'] ?? null,
            (int) $device['vendor_id'],
            (int) $device['product_id']
        );

        return $this->redirectToRoute('device_show', ['slug' => $slug], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/device/{slug}', name: 'device_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(string $slug): Response
    {
        $device = $this->deviceRepo->getDeviceBySlug($slug);

        if (!$device) {
            throw $this->createNotFoundException('Device not found');
        }

        $id = (int) $device['id'];

        // Get DCL Product data for additional info (URLs, commissioning hints, etc.)
        $product = $this->productRepo->findByVendorAndProductId(
            (int) $device['vendor_id'],
            (int) $device['product_id']
        );

        // If device has no product name, try to get it from DCL Product registry
        if ((empty($device['product_name']) || '-' === $device['product_name']) && ($product instanceof \App\Entity\Product && $product->getProductName())) {
            $device['product_name'] = $product->getProductName();
        }

        $endpoints = $this->deviceRepo->getDeviceEndpoints($id);
        $versions = $this->deviceRepo->getDeviceVersions($id);

        // Build device compatibility map based on client clusters (can control/read from)
        $compatibleDevices = $this->buildCompatibilityMap($id, $endpoints);

        // Build inverse compatibility map based on server clusters (can provide data to)
        $canProvideToDevices = $this->buildInverseCompatibilityMap($id, $endpoints);

        // Get frequently paired products (from installation data)
        $frequentlyPairedWith = $this->deviceRepo->getFrequentlyPairedProducts($id, 2, 8);
        $installationCount = $this->deviceRepo->getProductInstallationCount($id);

        // Analyze cluster gaps (what's missing vs spec)
        $clusterGapAnalysis = $this->matterRegistry->analyzeDeviceClusterGaps($endpoints);

        // Calculate device score based on latest version endpoints
        $latestEndpoints = $this->deviceScoreService->getLatestVersionEndpoints($id);
        $deviceScore = $this->deviceScoreService->calculateDeviceScore(
            [] === $latestEndpoints ? $endpoints : $latestEndpoints
        );

        // Analyze human-friendly capabilities (based on latest version)
        $capabilities = $this->capabilityService->analyzeCapabilities(
            [] === $latestEndpoints ? $endpoints : $latestEndpoints
        );

        // AEO: synthesize a Product entity from the telemetry row for JSON-LD
        // and lede generation. We always have device-row facts (vendor_id,
        // product_id, names) even when the DCL Product entity is absent.
        $aeoProduct = $this->buildAeoProduct($device, $product);
        $aeoDateModified = $this->parseSqliteDate($device['last_seen'] ?? null);
        $aeoBreadcrumbs = [
            ['name' => 'Home', 'url' => $this->generateUrl('device_index', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
        ];
        if (!empty($device['vendor_slug'])) {
            $aeoBreadcrumbs[] = [
                'name' => $device['vendor_name'] ?? 'Vendor',
                'url' => $this->generateUrl('vendor_show', ['slug' => $device['vendor_slug']], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }
        $aeoBreadcrumbs[] = [
            'name' => $device['product_name'] ?? 'Device',
            'url' => $this->generateUrl('device_show', ['slug' => $device['slug']], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        return $this->render('device/show.html.twig', [
            'device' => $device,
            'product' => $product,
            'endpoints' => $endpoints,
            'versions' => $versions,
            'compatibleDevices' => $compatibleDevices,
            'canProvideToDevices' => $canProvideToDevices,
            'frequentlyPairedWith' => $frequentlyPairedWith,
            'installationCount' => $installationCount,
            'clusterGapAnalysis' => $clusterGapAnalysis,
            'matterRegistry' => $this->matterRegistry,
            'deviceScore' => $deviceScore,
            'capabilities' => $capabilities,
            'aeoProduct' => $aeoProduct,
            'aeoEndpointCount' => \count($endpoints),
            'aeoDateModified' => $aeoDateModified,
            'aeoBreadcrumbs' => $aeoBreadcrumbs,
        ]);
    }

    /**
     * Materialize a Product entity for AEO services. Prefers the DCL Product
     * but falls back to a synthetic entity populated from the telemetry row,
     * so JSON-LD always has structured data even for products absent from DCL.
     *
     * @param array<string, mixed> $device device_summary row
     */
    private function buildAeoProduct(array $device, ?\App\Entity\Product $dclProduct): \App\Entity\Product
    {
        $product = $dclProduct ?? new \App\Entity\Product();
        $product->setVendorId((int) $device['vendor_id']);
        $product->setProductId((int) $device['product_id']);
        if (null !== ($device['vendor_name'] ?? null)) {
            $product->setVendorName((string) $device['vendor_name']);
        }
        if (null !== ($device['product_name'] ?? null) && '' !== $device['product_name']) {
            $product->setProductName((string) $device['product_name']);
        }
        if (null !== ($device['slug'] ?? null)) {
            $product->setSlug((string) $device['slug']);
        }

        return $product;
    }

    private function parseSqliteDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
            ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);

        return false === $dt ? null : $dt;
    }

    /**
     * Build a map of compatible devices based on client clusters.
     * For each client cluster on this device, find devices with matching server clusters.
     *
     * @return array<int, array{name: string, devices: array, total: int}>
     */
    private function buildCompatibilityMap(int $deviceId, array $endpoints): array
    {
        // System/infrastructure clusters to skip (not interesting for device-to-device communication)
        $skipClusters = [
            3,    // Identify
            4,    // Groups
            5,    // Scenes (legacy)
            29,   // Descriptor
            30,   // Binding (meta-cluster, not a capability)
            31,   // Access Control
            40,   // Basic Information
            41,   // OTA Software Update Provider
            42,   // OTA Software Update Requestor
            43,   // Localization Configuration
            44,   // Time Format Localization
            45,   // Unit Localization
            46,   // Power Source Configuration
            47,   // Power Source
            48,   // General Commissioning
            49,   // Network Commissioning
            50,   // Diagnostic Logs
            51,   // General Diagnostics
            52,   // Software Diagnostics
            53,   // Thread Network Diagnostics
            54,   // WiFi Network Diagnostics
            55,   // Ethernet Network Diagnostics
            56,   // Time Synchronization
            57,   // Bridged Device Basic Information
            60,   // Administrator Commissioning
            62,   // Node Operational Credentials
            63,   // Group Key Management
            64,   // Fixed Label
            65,   // User Label
            98,   // Scenes Management
        ];

        // Collect unique client clusters across all endpoints
        $clientClusters = [];
        foreach ($endpoints as $endpoint) {
            foreach ($endpoint['client_clusters'] ?? [] as $clusterId) {
                if (!\in_array($clusterId, $skipClusters, true)) {
                    $clientClusters[$clusterId] = true;
                }
            }
        }

        $compatibilityMap = [];
        foreach (array_keys($clientClusters) as $clusterId) {
            $devices = $this->deviceRepo->getDevicesWithServerCluster($clusterId, $deviceId, 10);
            $total = $this->deviceRepo->countDevicesWithServerCluster($clusterId, $deviceId);

            if ($total > 0) {
                $compatibilityMap[$clusterId] = [
                    'name' => $this->matterRegistry->getClusterName($clusterId),
                    'devices' => $devices,
                    'total' => $total,
                ];
            }
        }

        return $compatibilityMap;
    }

    /**
     * Build a map of devices that can consume this device's server clusters.
     * For each server cluster on this device, find devices with matching client clusters.
     *
     * @return array<int, array{name: string, devices: array, total: int}>
     */
    private function buildInverseCompatibilityMap(int $deviceId, array $endpoints): array
    {
        // System/infrastructure clusters to skip (not interesting for device-to-device communication)
        $skipClusters = [
            3,    // Identify
            4,    // Groups
            5,    // Scenes (legacy)
            29,   // Descriptor
            30,   // Binding (meta-cluster, not a capability)
            31,   // Access Control
            40,   // Basic Information
            41,   // OTA Software Update Provider
            42,   // OTA Software Update Requestor
            43,   // Localization Configuration
            44,   // Time Format Localization
            45,   // Unit Localization
            46,   // Power Source Configuration
            47,   // Power Source
            48,   // General Commissioning
            49,   // Network Commissioning
            50,   // Diagnostic Logs
            51,   // General Diagnostics
            52,   // Software Diagnostics
            53,   // Thread Network Diagnostics
            54,   // WiFi Network Diagnostics
            55,   // Ethernet Network Diagnostics
            56,   // Time Synchronization
            57,   // Bridged Device Basic Information
            60,   // Administrator Commissioning
            62,   // Node Operational Credentials
            63,   // Group Key Management
            64,   // Fixed Label
            65,   // User Label
            98,   // Scenes Management
        ];

        // Collect unique server clusters across all endpoints
        $serverClusters = [];
        foreach ($endpoints as $endpoint) {
            foreach ($endpoint['server_clusters'] ?? [] as $clusterId) {
                if (!\in_array($clusterId, $skipClusters, true)) {
                    $serverClusters[$clusterId] = true;
                }
            }
        }

        $compatibilityMap = [];
        foreach (array_keys($serverClusters) as $clusterId) {
            $devices = $this->deviceRepo->getDevicesWithClientCluster($clusterId, $deviceId, 10);
            $total = $this->deviceRepo->countDevicesWithClientCluster($clusterId, $deviceId);

            if ($total > 0) {
                $compatibilityMap[$clusterId] = [
                    'name' => $this->matterRegistry->getClusterName($clusterId),
                    'devices' => $devices,
                    'total' => $total,
                ];
            }
        }

        return $compatibilityMap;
    }
}
