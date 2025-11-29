<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DeviceRepository;
use App\Repository\ProductRepository;
use App\Repository\VendorRepository;
use App\Service\MatterRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VendorController extends AbstractController
{
    public function __construct(
        private VendorRepository $vendorRepo,
        private DeviceRepository $deviceRepo,
        private ProductRepository $productRepo,
        private MatterRegistry $matterRegistry,
    ) {}

    #[Route('/vendors', name: 'vendor_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $sort = $request->query->getString('sort', 'devices');

        $vendors = $this->vendorRepo->findAllOrderedByDeviceCount();

        // Build a map of vendorId (specId) => product count for efficient lookup
        $productCounts = $this->productRepo->getProductCountsByVendor();

        // Sort vendors based on request
        if ($sort === 'products') {
            usort($vendors, function ($a, $b) use ($productCounts) {
                $aCount = $productCounts[$a->getSpecId()] ?? 0;
                $bCount = $productCounts[$b->getSpecId()] ?? 0;
                return $bCount <=> $aCount ?: strcmp($a->getName(), $b->getName());
            });
        } elseif ($sort === 'name') {
            usort($vendors, fn ($a, $b) => strcmp($a->getName(), $b->getName()));
        }
        // 'devices' is already the default sort from findAllOrderedByDeviceCount()

        // Get top vendors (for featured section)
        $topVendors = $this->vendorRepo->findPopular(12);

        // Get device types per vendor for badges
        $deviceTypesByVendor = $this->deviceRepo->getTopDeviceTypesByVendor(4);

        // Enrich device types with metadata (icons, names)
        $deviceTypeMetadata = [];
        foreach ($deviceTypesByVendor as $vendorFk => $deviceTypeIds) {
            foreach ($deviceTypeIds as $dtId) {
                if (!isset($deviceTypeMetadata[$dtId])) {
                    $meta = $this->matterRegistry->getDeviceTypeMetadata($dtId);
                    $deviceTypeMetadata[$dtId] = [
                        'name' => $meta['name'] ?? "Device Type $dtId",
                        'icon' => $meta['icon'] ?? 'device',
                        'displayCategory' => $meta['displayCategory'] ?? 'Other',
                    ];
                }
            }
        }

        // Get market insights
        $insights = $this->deviceRepo->getVendorMarketInsights();

        return $this->render('vendor/index.html.twig', [
            'vendors' => $vendors,
            'productCounts' => $productCounts,
            'topVendors' => $topVendors,
            'deviceTypesByVendor' => $deviceTypesByVendor,
            'deviceTypeMetadata' => $deviceTypeMetadata,
            'insights' => $insights,
            'sort' => $sort,
        ]);
    }

    #[Route('/vendor/{slug}', name: 'vendor_show', methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $vendor = $this->vendorRepo->findBySlug($slug);

        if (!$vendor) {
            throw $this->createNotFoundException('Vendor not found');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $devices = $this->deviceRepo->getDevicesByVendor($vendor->getId(), $perPage, $offset);
        $totalDevices = $this->deviceRepo->getDeviceCountByVendor($vendor->getId());
        $totalPages = max(1, (int) ceil($totalDevices / $perPage));

        // Get products from DCL registry for this vendor (by specId)
        $products = $this->productRepo->findByVendorSpecId($vendor->getSpecId(), 100);
        $totalProducts = $this->productRepo->countByVendorSpecId($vendor->getSpecId());

        // Fetch analytics data
        $deviceTypeDistribution = $this->deviceRepo->getDeviceTypeDistributionByVendor($vendor->getId());
        $clusterCapabilities = $this->deviceRepo->getClusterCapabilitiesByVendor($vendor->getId());
        $bindingStats = $this->deviceRepo->getBindingSupportByVendor($vendor->getId());

        // Enrich device types with names from MatterRegistry
        $enrichedDeviceTypes = array_map(fn($dt) => [
            'id' => $dt['device_type_id'],
            'name' => $this->matterRegistry->getDeviceTypeMetadata((int) $dt['device_type_id'])['name'] ?? 'Unknown',
            'count' => $dt['product_count'],
        ], $deviceTypeDistribution);

        // Enrich clusters with names from MatterRegistry
        $enrichedClusters = array_map(fn($c) => [
            'id' => $c['cluster_id'],
            'name' => $this->matterRegistry->getClusterName((int) $c['cluster_id']),
            'type' => $c['type'],
            'count' => $c['count'],
        ], $clusterCapabilities);

        return $this->render('vendor/show.html.twig', [
            'vendor' => $vendor,
            'devices' => $devices,
            'products' => $products,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalDevices' => $totalDevices,
            'totalProducts' => $totalProducts,
            'deviceTypeDistribution' => $enrichedDeviceTypes,
            'clusterCapabilities' => $enrichedClusters,
            'bindingStats' => $bindingStats,
        ]);
    }
}
