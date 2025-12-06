<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DeviceRepository;
use App\Service\CapabilityService;
use App\Service\DeviceScoreService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CompareController extends AbstractController
{
    private const MAX_DEVICES = 5;

    public function __construct(
        private DeviceRepository $deviceRepo,
        private CapabilityService $capabilityService,
        private DeviceScoreService $deviceScoreService,
    ) {
    }

    #[Route('/compare', name: 'compare_empty', methods: ['GET'])]
    public function empty(): Response
    {
        return $this->render('compare/index.html.twig', [
            'devices' => [],
            'deviceCapabilities' => [],
            'deviceScores' => [],
            'aggregatedCapabilities' => [],
            'slugString' => '',
            'categories' => $this->capabilityService->getCategories(),
        ]);
    }

    #[Route('/compare/{slugs}', name: 'compare_devices', methods: ['GET'], requirements: ['slugs' => '[a-z0-9,-]+'])]
    public function compare(string $slugs): Response
    {
        $slugArray = array_filter(array_map('trim', explode(',', $slugs)));
        $slugArray = array_slice(array_unique($slugArray), 0, self::MAX_DEVICES);

        if (0 === \count($slugArray)) {
            return $this->redirectToRoute('compare_empty');
        }

        // Fetch devices and their data
        $devices = [];
        $deviceCapabilities = [];
        $deviceScores = [];

        foreach ($slugArray as $slug) {
            $device = $this->deviceRepo->getDeviceBySlug($slug);
            if (!$device) {
                continue;
            }

            $id = (int) $device['id'];
            $endpoints = $this->deviceScoreService->getLatestVersionEndpoints($id);
            if (empty($endpoints)) {
                $endpoints = $this->deviceRepo->getDeviceEndpoints($id);
            }

            $capabilities = $this->capabilityService->analyzeCapabilities($endpoints);
            $score = $this->deviceScoreService->calculateDeviceScore($endpoints);

            $devices[] = [
                'data' => $device,
                'endpoints' => $endpoints,
                'slug' => $slug,
            ];
            $deviceCapabilities[$slug] = $capabilities;
            $deviceScores[$slug] = $score;
        }

        if (empty($devices)) {
            throw $this->createNotFoundException('No valid devices found');
        }

        // Aggregate all capabilities across devices
        $aggregatedCapabilities = $this->aggregateCapabilities($deviceCapabilities);

        return $this->render('compare/index.html.twig', [
            'devices' => $devices,
            'deviceCapabilities' => $deviceCapabilities,
            'deviceScores' => $deviceScores,
            'aggregatedCapabilities' => $aggregatedCapabilities,
            'slugString' => implode(',', array_column($devices, 'slug')),
            'categories' => $this->capabilityService->getCategories(),
        ]);
    }

    #[Route('/api/compare/search', name: 'compare_search', methods: ['GET'])]
    public function searchApi(Request $request): JsonResponse
    {
        $query = trim($request->query->getString('q', ''));
        $exclude = array_filter(explode(',', $request->query->getString('exclude', '')));

        if (\strlen($query) < 2) {
            return $this->json(['results' => []]);
        }

        $devices = $this->deviceRepo->searchDevices($query, 10);

        // Filter out already-selected devices
        $devices = array_filter($devices, fn (array $d): bool => !\in_array($d['slug'], $exclude, true));

        return $this->json([
            'results' => array_values(array_map(fn (array $d): array => [
                'id' => $d['id'],
                'slug' => $d['slug'],
                'name' => $d['product_name'] ?? 'Unknown',
                'vendor' => $d['vendor_name'] ?? 'Unknown',
            ], $devices)),
        ]);
    }

    #[Route('/compare/add/{slug}', name: 'compare_add', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function addDevice(string $slug, Request $request): Response
    {
        $current = $request->query->getString('current', '');
        $currentSlugs = array_values(array_filter(explode(',', $current)));

        // Add the new slug if not already present and under the limit
        if (!\in_array($slug, $currentSlugs, true) && \count($currentSlugs) < self::MAX_DEVICES) {
            $currentSlugs[] = $slug;
        }

        // At this point we always have at least one slug (the one being added)
        return $this->redirectToRoute('compare_devices', ['slugs' => implode(',', $currentSlugs)]);
    }

    /**
     * Aggregate capabilities from multiple devices into a unified structure.
     *
     * @param array<string, array<string, mixed>> $deviceCapabilities Map of slug => capabilities
     *
     * @return array<string, array<string, mixed>> Aggregated by category, then capability key
     */
    private function aggregateCapabilities(array $deviceCapabilities): array
    {
        $allCapabilities = [];
        $allCategories = [];

        // Collect all unique capabilities and their categories
        foreach ($deviceCapabilities as $caps) {
            foreach ($caps['byCategory'] ?? [] as $catKey => $category) {
                if (!isset($allCategories[$catKey])) {
                    $allCategories[$catKey] = $category['label'];
                }

                foreach ($category['supported'] ?? [] as $capKey => $cap) {
                    if (!isset($allCapabilities[$capKey])) {
                        $allCapabilities[$capKey] = [
                            'key' => $capKey,
                            'label' => $cap['label'],
                            'emoji' => $cap['emoji'] ?? '',
                            'category' => $cap['category'],
                            'specVersion' => $cap['specVersion'] ?? null,
                            'hasDetails' => isset($cap['details']),
                        ];
                    }
                }

                foreach ($category['unsupported'] ?? [] as $capKey => $cap) {
                    if (!isset($allCapabilities[$capKey])) {
                        $allCapabilities[$capKey] = [
                            'key' => $capKey,
                            'label' => $cap['label'],
                            'emoji' => $cap['emoji'] ?? '',
                            'category' => $cap['category'],
                            'specVersion' => $cap['specVersion'] ?? null,
                            'hasDetails' => false,
                        ];
                    }
                }
            }
        }

        // Group by category
        $byCategory = [];
        foreach ($allCapabilities as $capKey => $cap) {
            $catKey = $cap['category'];
            if (!isset($byCategory[$catKey])) {
                $byCategory[$catKey] = [
                    'label' => $allCategories[$catKey] ?? ucfirst($catKey),
                    'capabilities' => [],
                ];
            }
            $byCategory[$catKey]['capabilities'][$capKey] = $cap;
        }

        // Sort categories by standard order
        $categoryOrder = ['controls', 'sensors', 'automation', 'monitoring', 'comfort', 'security', 'media'];
        uksort($byCategory, function ($a, $b) use ($categoryOrder): int {
            $posA = array_search($a, $categoryOrder, true);
            $posB = array_search($b, $categoryOrder, true);

            return (false === $posA ? 999 : $posA) <=> (false === $posB ? 999 : $posB);
        });

        return $byCategory;
    }
}
