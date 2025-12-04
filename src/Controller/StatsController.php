<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ClusterRepository;
use App\Repository\DeviceRepository;
use App\Repository\ProductRepository;
use App\Service\ChartFactory;
use App\Service\DeviceScoreService;
use App\Service\MatterRegistry;
use App\Service\TelemetryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class StatsController extends AbstractController
{
    public function __construct(
        private DeviceRepository $deviceRepo,
        private TelemetryService $telemetryService,
        private MatterRegistry $matterRegistry,
        private ClusterRepository $clusterRepo,
        private ProductRepository $productRepo,
        private ChartFactory $chartFactory,
        private DeviceScoreService $deviceScoreService,
    ) {
    }

    #[Route('/dashboard', name: 'stats_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $stats = $this->telemetryService->getStats();
        $categoryDistribution = $this->deviceRepo->getCategoryDistribution($this->matterRegistry);
        $topVendors = $this->deviceRepo->getTopVendors(10);
        $specVersionDistribution = $this->deviceRepo->getSpecVersionDistribution($this->matterRegistry);
        $recentDevices = $this->deviceRepo->getRecentDevices(5);
        $categoryHighlights = $this->deviceRepo->getTopProductsByCategory($this->matterRegistry, 3);

        return $this->render('stats/dashboard.html.twig', [
            'stats' => $stats,
            'categoryDistribution' => $categoryDistribution,
            'topVendors' => $topVendors,
            'specVersionDistribution' => $specVersionDistribution,
            'recentDevices' => $recentDevices,
            'categoryHighlights' => $categoryHighlights,
            // Charts
            'categoryChart' => $this->chartFactory->createCategoryChart($categoryDistribution),
            'vendorChart' => $this->chartFactory->createVendorChart($topVendors),
            'specChart' => $this->chartFactory->createSpecVersionChart($specVersionDistribution),
        ]);
    }

    #[Route('/clusters', name: 'stats_clusters', methods: ['GET'])]
    public function clusters(Request $request): Response
    {
        $stats = $this->telemetryService->getStats();
        $rawClusterStats = $this->deviceRepo->getClusterStats();
        $clusterCoOccurrence = $this->deviceRepo->getClusterCoOccurrence(20);

        // Combine server/client into unified cluster data and enrich with metadata
        $clusterMap = [];
        foreach ($rawClusterStats as $row) {
            $clusterId = (int) $row['cluster_id'];
            $type = $row['cluster_type'];
            $count = (int) $row['product_count'];

            if (!isset($clusterMap[$clusterId])) {
                $metadata = $this->matterRegistry->getClusterMetadata($clusterId);
                $clusterMap[$clusterId] = [
                    'id' => $clusterId,
                    'hexId' => sprintf('0x%04X', $clusterId),
                    'name' => $metadata['name'] ?? "Cluster $clusterId",
                    'description' => $metadata['description'] ?? '',
                    'category' => $metadata['category'] ?? 'utility',
                    'specVersion' => $metadata['specVersion'] ?? '1.0',
                    'isGlobal' => $metadata['isGlobal'] ?? false,
                    'serverCount' => 0,
                    'clientCount' => 0,
                    'totalCount' => 0,
                ];
            }

            if ('server' === $type) {
                $clusterMap[$clusterId]['serverCount'] = $count;
            } else {
                $clusterMap[$clusterId]['clientCount'] = $count;
            }
            $clusterMap[$clusterId]['totalCount'] = max(
                $clusterMap[$clusterId]['serverCount'],
                $clusterMap[$clusterId]['clientCount']
            );
        }

        // Sort by total count descending
        uasort($clusterMap, fn ($a, $b) => $b['totalCount'] <=> $a['totalCount']);
        $clusters = array_values($clusterMap);

        // Group by category
        $categories = [];
        foreach ($clusters as $cluster) {
            $cat = $cluster['category'];
            if (!isset($categories[$cat])) {
                $categories[$cat] = ['name' => $cat, 'clusters' => [], 'totalDevices' => 0];
            }
            $categories[$cat]['clusters'][] = $cluster;
            $categories[$cat]['totalDevices'] += $cluster['totalCount'];
        }

        // Sort categories by total devices
        uasort($categories, fn ($a, $b) => $b['totalDevices'] <=> $a['totalDevices']);

        // Calculate insights
        $totalClusters = \count($clusters);
        $avgClustersPerDevice = $stats['total_devices'] > 0
            ? round(array_sum(array_column($clusters, 'serverCount')) / $stats['total_devices'], 1)
            : 0;

        // Most common category
        $topCategory = !empty($categories) ? array_key_first($categories) : 'N/A';

        // Clusters only seen as client (interesting edge cases)
        $clientOnlyClusters = array_filter($clusters, fn ($c) => 0 === $c['serverCount'] && $c['clientCount'] > 0);

        $insights = [
            'totalClusters' => $totalClusters,
            'avgClustersPerDevice' => $avgClustersPerDevice,
            'topCategory' => ucfirst($topCategory),
            'clientOnlyCount' => \count($clientOnlyClusters),
        ];

        // Enrich co-occurrence with context
        $enrichedCoOccurrence = [];
        foreach ($clusterCoOccurrence as $pair) {
            $clusterA = $this->matterRegistry->getClusterMetadata((int) $pair['cluster_a']);
            $clusterB = $this->matterRegistry->getClusterMetadata((int) $pair['cluster_b']);

            $enrichedCoOccurrence[] = [
                'cluster_a' => (int) $pair['cluster_a'],
                'cluster_b' => (int) $pair['cluster_b'],
                'name_a' => $clusterA['name'] ?? "Cluster {$pair['cluster_a']}",
                'name_b' => $clusterB['name'] ?? "Cluster {$pair['cluster_b']}",
                'category_a' => $clusterA['category'] ?? 'utility',
                'category_b' => $clusterB['category'] ?? 'utility',
                'count' => (int) $pair['co_occurrence_count'],
            ];
        }

        // Get active filter
        $filterCategory = $request->query->getString('category', '');

        return $this->render('stats/clusters.html.twig', [
            'stats' => $stats,
            'clusters' => $clusters,
            'categories' => $categories,
            'insights' => $insights,
            'clusterCoOccurrence' => $enrichedCoOccurrence,
            'filterCategory' => $filterCategory,
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

        if (null === $metadata) {
            throw new NotFoundHttpException(\sprintf('Device type %d not found', $type));
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 24;
        $offset = ($page - 1) * $limit;

        // Get sort parameter (default to 'rating' for best experience)
        $sort = $request->query->getString('sort', 'rating');
        if (!\in_array($sort, ['rating', 'name', 'recent'], true)) {
            $sort = 'rating';
        }

        // Fetch devices based on sort method
        if ('rating' === $sort) {
            $devices = $this->deviceScoreService->getDevicesRankedByScore($type, $limit, $offset);
        } else {
            $devices = $this->deviceRepo->getDevicesByDeviceType($type, $limit, $offset, $sort);
        }

        $totalDevices = $this->deviceRepo->countDevicesByDeviceType($type);
        $totalPages = (int) ceil($totalDevices / $limit);

        // Fetch cached scores for the devices
        $deviceIds = array_column($devices, 'id');
        $deviceScores = $this->deviceScoreService->getCachedScoresForDevices($deviceIds);

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
            'deviceScores' => $deviceScores,
            'totalDevices' => $totalDevices,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'currentSort' => $sort,
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

    #[Route('/cluster/{hexId}', name: 'stats_cluster_show', methods: ['GET'], requirements: ['hexId' => '0x[0-9A-Fa-f]+'])]
    public function clusterShow(string $hexId, Request $request): Response
    {
        $cluster = $this->clusterRepo->findByHexId($hexId);

        if (null === $cluster) {
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

    #[Route('/commissioning', name: 'stats_commissioning', methods: ['GET'])]
    public function commissioning(): Response
    {
        $stats = $this->productRepo->getCommissioningStats();
        $productsWithInstructions = $this->productRepo->findWithCommissioningData(200);
        $productsByComplexity = $this->productRepo->findGroupedByComplexity();
        $icdProducts = $this->productRepo->findWithIcdData(50);

        // Complexity hint labels (from Matter spec)
        $complexityLabels = [
            0 => 'Standard',
            1 => 'Custom Instructions',
            2 => 'App Required',
            3 => 'Complex Setup',
        ];

        return $this->render('stats/commissioning.html.twig', [
            'stats' => $stats,
            'productsWithInstructions' => $productsWithInstructions,
            'productsByComplexity' => $productsByComplexity,
            'icdProducts' => $icdProducts,
            'complexityLabels' => $complexityLabels,
        ]);
    }

    #[Route('/market', name: 'stats_market', methods: ['GET'])]
    public function market(): Response
    {
        $stats = $this->telemetryService->getStats();
        $marketData = $this->deviceRepo->getMarketAnalysis($this->matterRegistry);
        $vendorInsights = $this->deviceRepo->getVendorMarketInsights();

        return $this->render('stats/market.html.twig', [
            'stats' => $stats,
            'marketData' => $marketData,
            'vendorInsights' => $vendorInsights,
        ]);
    }

    #[Route('/versions', name: 'stats_versions', methods: ['GET'])]
    public function versions(): Response
    {
        $stats = $this->telemetryService->getStats();
        $versionData = $this->deviceRepo->getVersionTimeline();
        $versionStats = $this->deviceRepo->getVersionStats();

        return $this->render('stats/versions.html.twig', [
            'stats' => $stats,
            'versionData' => $versionData,
            'versionStats' => $versionStats,
        ]);
    }
}
