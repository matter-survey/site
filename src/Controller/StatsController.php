<?php

declare(strict_types=1);

namespace App\Controller;

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

        // Find device types in registry but not in survey
        $seenIds = array_column($deviceTypeStats, 'device_type_id');
        $missingDeviceTypes = [];
        foreach ($allDeviceTypes as $id => $meta) {
            if (!in_array($id, $seenIds, false)) {
                $missingDeviceTypes[] = [
                    'id' => $id,
                    'name' => $meta['name'],
                    'specVersion' => $meta['specVersion'],
                    'displayCategory' => $meta['displayCategory'],
                    'icon' => $meta['icon'],
                    'description' => $meta['description'],
                ];
            }
        }

        return $this->render('stats/device_types.html.twig', [
            'stats' => $stats,
            'groupedDeviceTypes' => $groupedDeviceTypes,
            'missingDeviceTypes' => $missingDeviceTypes,
            'displayCategories' => $displayCategories,
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

        return $this->render('stats/device_type_show.html.twig', [
            'deviceType' => [
                'id' => $type,
                'name' => $metadata['name'],
                'description' => $metadata['description'] ?? '',
                'specVersion' => $metadata['specVersion'] ?? null,
                'icon' => $metadata['icon'] ?? 'device',
                'category' => $metadata['category'] ?? null,
                'displayCategory' => $metadata['displayCategory'] ?? 'System',
            ],
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
}
