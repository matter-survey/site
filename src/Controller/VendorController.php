<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DeviceRepository;
use App\Repository\VendorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VendorController extends AbstractController
{
    public function __construct(
        private VendorRepository $vendorRepo,
        private DeviceRepository $deviceRepo,
    ) {}

    #[Route('/vendors', name: 'vendor_index', methods: ['GET'])]
    public function index(): Response
    {
        $vendors = $this->vendorRepo->findAllOrderedByDeviceCount();

        return $this->render('vendor/index.html.twig', [
            'vendors' => $vendors,
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

        return $this->render('vendor/show.html.twig', [
            'vendor' => $vendor,
            'devices' => $devices,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalDevices' => $totalDevices,
        ]);
    }
}
