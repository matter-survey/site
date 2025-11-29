<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DeviceRepository;
use App\Repository\ProductRepository;
use App\Service\MatterRegistry;
use App\Service\TelemetryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DeviceController extends AbstractController
{
    public function __construct(
        private DeviceRepository $deviceRepo,
        private ProductRepository $productRepo,
        private TelemetryService $telemetryService,
        private MatterRegistry $matterRegistry,
    ) {}

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
            'binding' => $this->deviceRepo->getBindingFacets(),
            'vendors' => $this->deviceRepo->getVendorFacets(15),
            'device_types' => $this->deviceRepo->getDeviceTypeFacets(15),
        ];

        return $this->render('device/index.html.twig', [
            'devices' => $devices,
            'stats' => $stats,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalDevices' => $totalDevices,
            'filters' => $filters,
            'facets' => $facets,
            'hasFilters' => $hasFilters,
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
        if ($search !== '') {
            $filters['search'] = $search;
        }

        // Connectivity types (array)
        $connectivity = $request->query->all('connectivity');
        if (!empty($connectivity)) {
            $filters['connectivity'] = array_filter($connectivity, fn ($v) => \in_array($v, ['thread', 'wifi', 'ethernet'], true));
        }

        // Binding filter
        $binding = $request->query->get('binding');
        if ($binding === '1') {
            $filters['binding'] = true;
        } elseif ($binding === '0') {
            $filters['binding'] = false;
        }

        // Vendor filter (check for non-empty before getInt to avoid error on empty string)
        $vendorParam = $request->query->get('vendor', '');
        if ($vendorParam !== '' && is_numeric($vendorParam)) {
            $vendor = (int) $vendorParam;
            if ($vendor > 0) {
                $filters['vendor'] = $vendor;
            }
        }

        // Device type filter (array of IDs)
        $deviceTypes = $request->query->all('device_types');
        if (!empty($deviceTypes)) {
            $filters['device_types'] = array_filter(
                array_map('intval', $deviceTypes),
                fn ($v) => $v > 0
            );
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
            || !empty($filters['vendor'])
            || !empty($filters['device_types'])
            || !empty($filters['search']);
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
        if (empty($device['product_name']) || $device['product_name'] === '-') {
            if ($product && $product->getProductName()) {
                $device['product_name'] = $product->getProductName();
            }
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
        ]);
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
