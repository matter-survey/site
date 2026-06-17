<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ClusterRepository;
use App\Repository\ClusterVersionRepository;
use App\Repository\DeviceRepository;
use App\Repository\DeviceTypeRepository;
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
        private readonly DeviceRepository $deviceRepo,
        private readonly TelemetryService $telemetryService,
        private readonly MatterRegistry $matterRegistry,
        private readonly ClusterRepository $clusterRepo,
        private readonly ClusterVersionRepository $clusterVersionRepo,
        private readonly ProductRepository $productRepo,
        private readonly ChartFactory $chartFactory,
        private readonly DeviceScoreService $deviceScoreService,
        private readonly DeviceTypeRepository $deviceTypeRepo,
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
            'aeoDataset' => $this->datasetDescriptor(
                'Matter Smart Home Device Adoption Statistics',
                'Aggregate statistics on Matter device adoption, vendor distribution, supported spec versions, and category coverage from the Matter Survey public registry.',
            ),
        ]);
    }

    /**
     * Descriptor block for the Dataset JSON-LD on aggregate stats pages.
     *
     * @return array{name: string, description: string, dateModified: \DateTimeImmutable, coverageStart: \DateTimeImmutable, coverageEnd: null}
     */
    private function datasetDescriptor(string $name, string $description): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'dateModified' => new \DateTimeImmutable('today'),
            'coverageStart' => new \DateTimeImmutable('2024-01-01'),
            'coverageEnd' => null,
        ];
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
        uasort($clusterMap, fn ($a, $b): int => $b['totalCount'] <=> $a['totalCount']);
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
        uasort($categories, fn ($a, $b): int => $b['totalDevices'] <=> $a['totalDevices']);

        // Calculate insights
        $totalClusters = \count($clusters);
        $avgClustersPerDevice = $stats['total_devices'] > 0
            ? round(array_sum(array_column($clusters, 'serverCount')) / $stats['total_devices'], 1)
            : 0;

        // Most common category
        $topCategory = [] === $categories ? 'N/A' : array_key_first($categories);

        // Clusters only seen as client (interesting edge cases)
        $clientOnlyClusters = array_filter($clusters, fn (array $c): bool => 0 === $c['serverCount'] && $c['clientCount'] > 0);

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
            'aeoDataset' => $this->datasetDescriptor(
                'Matter Cluster Implementation Statistics',
                'Aggregate usage statistics for every Matter cluster across submitted devices, including server/client splits and cluster co-occurrence pairs.',
            ),
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
            'aeoDataset' => $this->datasetDescriptor(
                'Matter Device Type Coverage Statistics',
                'Device-type coverage statistics across submitted Matter devices, grouped by display category and spec version, including which device types are not yet observed in the field.',
            ),
        ]);
    }

    #[Route('/device-types/{type}', name: 'stats_device_type_show', requirements: ['type' => '\d+'], methods: ['GET'])]
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

        // Fetch the actual DeviceType entity for AEO lede/JSON-LD generation.
        // The existing $deviceType array continues to drive the rest of the
        // template; the entity is only used by aeo_* / structured_data_*.
        $deviceTypeEntity = $this->deviceTypeRepo->find($type);

        $aeoBreadcrumbs = [
            ['name' => 'Home', 'url' => $this->generateUrl('device_index', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
            ['name' => 'Device Types', 'url' => $this->generateUrl('stats_device_types', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
            ['name' => $metadata['name'], 'url' => $this->generateUrl('stats_device_type_show', ['type' => $type], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
        ];

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
            'deviceTypeEntity' => $deviceTypeEntity,
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
            'aeoDateModified' => $deviceTypeEntity?->getUpdatedAt(),
            'aeoBreadcrumbs' => $aeoBreadcrumbs,
        ]);
    }

    #[Route('/coordination', name: 'stats_coordination', methods: ['GET'])]
    public function coordination(): Response
    {
        $stats = $this->telemetryService->getStats();
        $byCategory = $this->deviceRepo->getCoordinationByCategory($this->matterRegistry);

        return $this->render('stats/coordination.html.twig', [
            'stats' => $stats,
            'byCategory' => $byCategory,
            'features' => [
                'binding' => [
                    'label' => 'Binding',
                    'role' => 'Controls other devices directly (no hub required)',
                    'devices' => $this->deviceRepo->getCoordinationCapableDevices('binding', 50),
                    'count' => $stats['bindable_devices'] ?? 0,
                ],
                'groups' => [
                    'label' => 'Group control',
                    'role' => 'Can be addressed in bulk as part of a group',
                    'devices' => $this->deviceRepo->getCoordinationCapableDevices('groups', 50),
                    'count' => $stats['groups_devices'] ?? 0,
                ],
                'scenes' => [
                    'label' => 'Scene control',
                    'role' => 'Can save and recall preset states as scenes',
                    'devices' => $this->deviceRepo->getCoordinationCapableDevices('scenes', 50),
                    'count' => $stats['scenes_devices'] ?? 0,
                ],
            ],
            'matterRegistry' => $this->matterRegistry,
            'aeoDataset' => $this->datasetDescriptor(
                'Matter Coordination Feature Support Statistics',
                'Aggregate statistics on Matter device support for the multi-device coordination features — Binding, Groups, and Scenes — broken down by device category.',
            ),
        ]);
    }

    #[Route('/binding', name: 'stats_binding', methods: ['GET'])]
    public function binding(): Response
    {
        // Binding folded into the unified coordination page; preserve inbound links.
        return $this->redirectToRoute('stats_coordination', [], Response::HTTP_MOVED_PERMANENTLY)
            ->setTargetUrl($this->generateUrl('stats_coordination').'#binding');
    }

    #[Route('/cluster/{hexId}', name: 'stats_cluster_show', requirements: ['hexId' => '0x[0-9A-Fa-f]+'], methods: ['GET'])]
    public function clusterShow(string $hexId, Request $request): Response
    {
        $cluster = $this->clusterRepo->findByHexId($hexId);

        if (!$cluster instanceof \App\Entity\Cluster) {
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

        // Per-Matter-version history (one row per release the cluster appears in)
        $versionHistory = $this->clusterVersionRepo->findByClusterId($cluster->getId());
        $latestSnapshot = [] !== $versionHistory ? end($versionHistory) : null;
        $aeoCommandCount = $latestSnapshot ? \count($latestSnapshot->getCommands() ?? []) : 0;
        $aeoAttributeCount = $latestSnapshot ? \count($latestSnapshot->getAttributes() ?? []) : 0;

        $aeoBreadcrumbs = [
            ['name' => 'Home', 'url' => $this->generateUrl('device_index', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
            ['name' => 'Clusters', 'url' => $this->generateUrl('stats_clusters', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
            ['name' => $cluster->getName(), 'url' => $this->generateUrl('stats_cluster_show', ['hexId' => $cluster->getHexId()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
        ];

        return $this->render('stats/cluster_show.html.twig', [
            'cluster' => $cluster,
            'devices' => $devices,
            'totalDevices' => $totalDevices,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'deviceTypesRequiring' => $deviceTypesRequiring,
            'versionHistory' => $versionHistory,
            'matterRegistry' => $this->matterRegistry,
            'aeoMandatoryForCount' => \count($deviceTypesRequiring),
            'aeoCommandCount' => $aeoCommandCount,
            'aeoAttributeCount' => $aeoAttributeCount,
            'aeoDateModified' => $cluster->getUpdatedAt(),
            'aeoBreadcrumbs' => $aeoBreadcrumbs,
        ]);
    }

    #[Route(
        '/cluster/{hexId}/version/{matterVersion}',
        name: 'stats_cluster_version_show',
        requirements: ['hexId' => '0x[0-9A-Fa-f]+', 'matterVersion' => '\d+\.\d+|master'],
        methods: ['GET'],
    )]
    public function clusterVersionShow(string $hexId, string $matterVersion): Response
    {
        $cluster = $this->clusterRepo->findByHexId($hexId);
        if (!$cluster instanceof \App\Entity\Cluster) {
            throw new NotFoundHttpException(\sprintf('Cluster %s not found', $hexId));
        }

        $snapshot = $this->clusterVersionRepo->find([
            'clusterId' => $cluster->getId(),
            'matterVersion' => $matterVersion,
        ]);
        if (!$snapshot instanceof \App\Entity\ClusterVersion) {
            throw new NotFoundHttpException(\sprintf('Cluster %s did not exist in Matter %s', $hexId, $matterVersion));
        }

        $versionHistory = $this->clusterVersionRepo->findByClusterId($cluster->getId());

        $aeoBreadcrumbs = [
            ['name' => 'Home', 'url' => $this->generateUrl('device_index', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
            ['name' => 'Clusters', 'url' => $this->generateUrl('stats_clusters', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
            ['name' => $cluster->getName(), 'url' => $this->generateUrl('stats_cluster_show', ['hexId' => $cluster->getHexId()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
            ['name' => \sprintf('Matter %s', $matterVersion), 'url' => $this->generateUrl('stats_cluster_version_show', ['hexId' => $cluster->getHexId(), 'matterVersion' => $matterVersion], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)],
        ];

        return $this->render('stats/cluster_version_show.html.twig', [
            'cluster' => $cluster,
            'snapshot' => $snapshot,
            'versionHistory' => $versionHistory,
            'matterVersion' => $matterVersion,
            'aeoDateModified' => $cluster->getUpdatedAt(),
            'aeoBreadcrumbs' => $aeoBreadcrumbs,
        ]);
    }

    #[Route('/matter', name: 'matter_hub', methods: ['GET'])]
    public function matterHub(): Response
    {
        // Bulk-load every ClusterVersion row once and aggregate in PHP — way
        // cheaper than one query per version.
        $allRows = $this->clusterVersionRepo->findAll();

        $clustersByVersion = [];      // matter_version => list<int clusterId>
        $clustersByVersionDetailed = []; // matter_version => list<ClusterVersion>
        foreach ($allRows as $row) {
            $clustersByVersion[$row->getMatterVersion()][] = $row->getClusterId();
            $clustersByVersionDetailed[$row->getMatterVersion()][] = $row;
        }
        ksort($clustersByVersion);

        // Build the timeline: each released version + a "new clusters since
        // the prior version" delta. Skip "master" — it's surfaced separately
        // as "in development".
        $releasedVersions = array_values(array_filter(
            array_keys($clustersByVersion),
            fn (string $v): bool => 'master' !== $v,
        ));
        sort($releasedVersions);

        $releases = [];
        $prevIds = [];
        foreach ($releasedVersions as $version) {
            $currentIds = $clustersByVersion[$version] ?? [];
            $added = array_values(array_diff($currentIds, $prevIds));

            $releases[] = [
                'version' => $version,
                'totalClusters' => \count($currentIds),
                'newCount' => \count($added),
                'newSample' => $this->pickClusterSample($clustersByVersionDetailed[$version] ?? [], $added, 3),
            ];

            $prevIds = $currentIds;
        }

        $latestReleased = [] !== $releasedVersions ? end($releasedVersions) : null;
        $masterRows = $clustersByVersionDetailed['master'] ?? [];
        $masterCount = \count($masterRows);
        $latestReleasedCount = null !== $latestReleased ? \count($clustersByVersion[$latestReleased] ?? []) : 0;

        return $this->render('matter/hub.html.twig', [
            'releases' => $releases,
            'latestReleased' => $latestReleased,
            'latestReleasedCount' => $latestReleasedCount,
            'masterCount' => $masterCount,
            'pendingClustersDelta' => $masterCount - $latestReleasedCount,
            'totalDeviceTypes' => \count($this->deviceTypeRepo->findAll()),
        ]);
    }

    /**
     * Picks up to $limit named samples from $clusterRows whose id is in $ids.
     *
     * @param list<\App\Entity\ClusterVersion> $clusterRows
     * @param list<int>                        $ids
     *
     * @return list<array{id: int, hexId: string, name: string}>
     */
    private function pickClusterSample(array $clusterRows, array $ids, int $limit): array
    {
        $byId = [];
        foreach ($clusterRows as $row) {
            $byId[$row->getClusterId()] = $row;
        }

        $sample = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $sample[] = [
                'id' => $id,
                'hexId' => \sprintf('0x%04X', $id),
                'name' => $byId[$id]->getName(),
            ];
            if (\count($sample) >= $limit) {
                break;
            }
        }

        return $sample;
    }

    #[Route('/clusters/next', name: 'stats_clusters_next', methods: ['GET'])]
    public function clustersNext(): Response
    {
        $masterVersion = 'master';
        $releasedVersion = $this->clusterVersionRepo->findLatestReleasedMatterVersion();

        if (null === $releasedVersion) {
            throw new NotFoundHttpException('No released Matter snapshots loaded yet');
        }

        $masterRows = $this->clusterVersionRepo->findByMatterVersion($masterVersion);
        $releasedRows = $this->clusterVersionRepo->findByMatterVersion($releasedVersion);

        if ([] === $masterRows) {
            throw new NotFoundHttpException('No master snapshot loaded yet');
        }

        $releasedById = [];
        foreach ($releasedRows as $row) {
            $releasedById[$row->getClusterId()] = $row;
        }

        $newClusters = [];
        $revisionBumps = [];
        foreach ($masterRows as $row) {
            $id = $row->getClusterId();
            if (!isset($releasedById[$id])) {
                $newClusters[] = $row;
                continue;
            }

            $masterRev = $row->getClusterRevision();
            $releasedRev = $releasedById[$id]->getClusterRevision();
            if (null !== $masterRev && null !== $releasedRev && $masterRev !== $releasedRev) {
                $revisionBumps[] = [
                    'clusterId' => $id,
                    'hexId' => \sprintf('0x%04X', $id),
                    'name' => $row->getName(),
                    'fromRevision' => $releasedRev,
                    'toRevision' => $masterRev,
                ];
            }
        }

        usort($newClusters, fn ($a, $b): int => $a->getClusterId() <=> $b->getClusterId());
        usort($revisionBumps, fn (array $a, array $b): int => $a['clusterId'] <=> $b['clusterId']);

        return $this->render('stats/clusters_next.html.twig', [
            'releasedVersion' => $releasedVersion,
            'masterVersion' => $masterVersion,
            'newClusters' => $newClusters,
            'revisionBumps' => $revisionBumps,
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
            'aeoDataset' => $this->datasetDescriptor(
                'Matter Device Pairing Statistics',
                'Statistics on how Matter devices are paired in real-world installations, including top product pairings, most-connected products, and vendor pairing patterns.',
            ),
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
            'aeoDataset' => $this->datasetDescriptor(
                'Matter Device Commissioning Statistics',
                'Aggregate statistics on Matter device commissioning complexity, custom-flow URLs, ICD support, and setup-instruction coverage from the DCL registry.',
            ),
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
            'aeoDataset' => $this->datasetDescriptor(
                'Matter Market Analysis Statistics',
                'Market-share statistics for Matter device vendors and product categories, including vendor concentration, category coverage, and adoption trends.',
            ),
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
            'aeoDataset' => $this->datasetDescriptor(
                'Matter Spec Version Adoption Statistics',
                'Time-series statistics on which Matter specification versions are observed in submitted devices, including hardware and software version timelines.',
            ),
        ]);
    }
}
