<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ClusterRepository;
use App\Repository\DeviceRepository;
use App\Service\MatterRegistry;
use App\Service\TelemetryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class StatsController extends AbstractController
{
    public function __construct(
        private DeviceRepository $deviceRepo,
        private TelemetryService $telemetryService,
        private MatterRegistry $matterRegistry,
        private ClusterRepository $clusterRepo,
    ) {}

    #[Route('/dashboard', name: 'stats_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $stats = $this->telemetryService->getStats();
        $categoryDistribution = $this->deviceRepo->getCategoryDistribution($this->matterRegistry);
        $topVendors = $this->deviceRepo->getTopVendors(10);
        $specVersionDistribution = $this->deviceRepo->getSpecVersionDistribution($this->matterRegistry);
        $recentDevices = $this->deviceRepo->getRecentDevices(5);

        return $this->render('stats/dashboard.html.twig', [
            'stats' => $stats,
            'categoryDistribution' => $categoryDistribution,
            'topVendors' => $topVendors,
            'specVersionDistribution' => $specVersionDistribution,
            'recentDevices' => $recentDevices,
        ]);
    }

    #[Route('/clusters', name: 'stats_clusters', methods: ['GET'])]
    public function clusters(): Response
    {
        $stats = $this->telemetryService->getStats();
        $clusterStats = $this->deviceRepo->getClusterStats();
        $clusterCoOccurrence = $this->deviceRepo->getClusterCoOccurrence(15);

        // Enrich with cluster names
        foreach ($clusterStats as &$cluster) {
            $cluster['name'] = $this->matterRegistry->getClusterName((int) $cluster['cluster_id']);
        }

        return $this->render('stats/clusters.html.twig', [
            'stats' => $stats,
            'clusterStats' => $clusterStats,
            'clusterCoOccurrence' => $clusterCoOccurrence,
            'matterRegistry' => $this->matterRegistry,
        ]);
    }

    #[Route('/device-types', name: 'stats_device_types', methods: ['GET'])]
    public function deviceTypes(): Response
    {
        $stats = $this->telemetryService->getStats();
        $deviceTypeStats = $this->deviceRepo->getDeviceTypeStats();
        $allDeviceTypes = $this->matterRegistry->getAllDeviceTypeMetadata();
        $displayCategories = $this->matterRegistry->getAllDisplayCategories();

        // Group device types by display category and enrich with metadata
        $groupedDeviceTypes = [];
        foreach ($displayCategories as $category) {
            $groupedDeviceTypes[$category] = [];
        }

        foreach ($deviceTypeStats as $dt) {
            $metadata = $this->matterRegistry->getDeviceTypeMetadata((int) $dt['device_type_id']);
            $displayCategory = $metadata['displayCategory'] ?? 'System';
            $groupedDeviceTypes[$displayCategory][] = [
                'id' => $dt['device_type_id'],
                'name' => $metadata['name'] ?? "Device Type {$dt['device_type_id']}",
                'count' => $dt['product_count'],
                'specVersion' => $metadata['specVersion'] ?? null,
                'icon' => $metadata['icon'] ?? 'device',
                'description' => $metadata['description'] ?? '',
            ];
        }

        // Find device types in registry but not in survey, grouped by category
        $seenIds = array_column($deviceTypeStats, 'device_type_id');
        $missingDeviceTypes = [];
        $groupedMissingDeviceTypes = [];
        foreach ($displayCategories as $category) {
            $groupedMissingDeviceTypes[$category] = [];
        }

        foreach ($allDeviceTypes as $id => $meta) {
            if (!in_array($id, $seenIds, false)) {
                $deviceType = [
                    'id' => $id,
                    'name' => $meta['name'],
                    'specVersion' => $meta['specVersion'],
                    'displayCategory' => $meta['displayCategory'],
                    'icon' => $meta['icon'],
                    'description' => $meta['description'],
                ];
                $missingDeviceTypes[] = $deviceType;
                $displayCategory = $meta['displayCategory'] ?? 'System';
                $groupedMissingDeviceTypes[$displayCategory][] = $deviceType;
            }
        }

        // Collect all spec versions for filtering
        $allSpecVersions = $this->matterRegistry->getAllSpecVersions();

        return $this->render('stats/device_types.html.twig', [
            'stats' => $stats,
            'groupedDeviceTypes' => $groupedDeviceTypes,
            'missingDeviceTypes' => $missingDeviceTypes,
            'groupedMissingDeviceTypes' => $groupedMissingDeviceTypes,
            'displayCategories' => $displayCategories,
            'specVersions' => $allSpecVersions,
        ]);
    }

    #[Route('/device-types/{type}', name: 'stats_device_type_show', methods: ['GET'], requirements: ['type' => '\d+'])]
    public function deviceTypeShow(int $type, Request $request): Response
    {
        $metadata = $this->matterRegistry->getDeviceTypeMetadata($type);

        if ($metadata === null) {
            throw new NotFoundHttpException(sprintf('Device type %d not found', $type));
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 24;
        $offset = ($page - 1) * $limit;

        $devices = $this->deviceRepo->getDevicesByDeviceType($type, $limit, $offset);
        $totalDevices = $this->deviceRepo->countDevicesByDeviceType($type);
        $totalPages = (int) ceil($totalDevices / $limit);

        // Get extended data from YAML fixture
        $extendedData = $this->matterRegistry->getExtendedDeviceType($type);

        return $this->render('stats/device_type_show.html.twig', [
            'deviceType' => [
                'id' => $type,
                'hexId' => $this->matterRegistry->getDeviceTypeHexId($type),
                'name' => $metadata['name'],
                'description' => $extendedData['description'] ?? $metadata['description'] ?? '',
                'specVersion' => $metadata['specVersion'] ?? null,
                'icon' => $metadata['icon'] ?? 'device',
                'category' => $metadata['category'] ?? null,
                'displayCategory' => $metadata['displayCategory'] ?? 'System',
                'class' => $extendedData['class'] ?? null,
                'scope' => $extendedData['scope'] ?? null,
                'superset' => $extendedData['superset'] ?? null,
            ],
            'mandatoryServerClusters' => $this->matterRegistry->getMandatoryServerClusters($type),
            'optionalServerClusters' => $this->matterRegistry->getOptionalServerClusters($type),
            'mandatoryClientClusters' => $this->matterRegistry->getMandatoryClientClusters($type),
            'optionalClientClusters' => $this->matterRegistry->getOptionalClientClusters($type),
            'devices' => $devices,
            'totalDevices' => $totalDevices,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'matterRegistry' => $this->matterRegistry,
        ]);
    }

    #[Route('/binding', name: 'stats_binding', methods: ['GET'])]
    public function binding(): Response
    {
        $stats = $this->telemetryService->getStats();
        $bindingDevices = $this->deviceRepo->getBindingCapableDevices(50);
        $bindingByCategory = $this->deviceRepo->getBindingByCategory($this->matterRegistry);

        return $this->render('stats/binding.html.twig', [
            'stats' => $stats,
            'bindingDevices' => $bindingDevices,
            'bindingByCategory' => $bindingByCategory,
            'matterRegistry' => $this->matterRegistry,
        ]);
    }

    #[Route('/versions', name: 'stats_versions', methods: ['GET'])]
    public function versions(): Response
    {
        $stats = $this->telemetryService->getStats();
        $productsWithMultipleVersions = $this->deviceRepo->getProductsWithMultipleVersions(30);
        $versionStats = $this->deviceRepo->getVersionStats();

        return $this->render('stats/versions.html.twig', [
            'stats' => $stats,
            'productsWithMultipleVersions' => $productsWithMultipleVersions,
            'versionStats' => $versionStats,
        ]);
    }

    #[Route('/cluster/{hexId}', name: 'stats_cluster_show', methods: ['GET'], requirements: ['hexId' => '0x[0-9A-Fa-f]+'])]
    public function clusterShow(string $hexId, Request $request): Response
    {
        $cluster = $this->clusterRepo->findByHexId($hexId);

        if ($cluster === null) {
            throw new NotFoundHttpException(\sprintf('Cluster %s not found', $hexId));
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 24;
        $offset = ($page - 1) * $limit;

        // Get devices that implement this cluster (as server or client)
        $devices = $this->deviceRepo->getDevicesByCluster($cluster->getId(), $limit, $offset);
        $totalDevices = $this->deviceRepo->countDevicesByCluster($cluster->getId());
        $totalPages = (int) ceil($totalDevices / $limit);

        // Get device types that require this cluster
        $deviceTypesRequiring = $this->deviceRepo->getDeviceTypesRequiringCluster($cluster->getId());

        return $this->render('stats/cluster_show.html.twig', [
            'cluster' => $cluster,
            'devices' => $devices,
            'totalDevices' => $totalDevices,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'deviceTypesRequiring' => $deviceTypesRequiring,
            'matterRegistry' => $this->matterRegistry,
        ]);
    }

    #[Route('/pairings', name: 'stats_pairings', methods: ['GET'])]
    public function pairings(): Response
    {
        $stats = $this->telemetryService->getStats();
        $pairingStats = $this->deviceRepo->getPairingStats();
        $topPairings = $this->deviceRepo->getTopProductPairings(20);
        $mostConnectedProducts = $this->deviceRepo->getMostConnectedProducts(10);
        $vendorPairings = $this->deviceRepo->getVendorPairings(15);

        return $this->render('stats/pairings.html.twig', [
            'stats' => $stats,
            'pairingStats' => $pairingStats,
            'topPairings' => $topPairings,
            'mostConnectedProducts' => $mostConnectedProducts,
            'vendorPairings' => $vendorPairings,
        ]);
    }
}
