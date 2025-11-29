<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ClusterRepository;
use App\Repository\DeviceRepository;
use App\Repository\DeviceTypeRepository;
use App\Repository\VendorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SitemapController extends AbstractController
{
    public function __construct(
        private DeviceRepository $deviceRepo,
        private VendorRepository $vendorRepo,
        private ClusterRepository $clusterRepo,
        private DeviceTypeRepository $deviceTypeRepo,
    ) {}

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function index(): Response
    {
        $urls = [];

        // Static pages
        $urls[] = $this->createUrl('device_index', [], 1.0, 'daily');
        $urls[] = $this->createUrl('vendor_index', [], 0.8, 'weekly');
        $urls[] = $this->createUrl('stats_dashboard', [], 0.7, 'weekly');
        $urls[] = $this->createUrl('stats_clusters', [], 0.7, 'weekly');
        $urls[] = $this->createUrl('stats_device_types', [], 0.7, 'weekly');
        $urls[] = $this->createUrl('stats_binding', [], 0.6, 'weekly');
        $urls[] = $this->createUrl('stats_versions', [], 0.6, 'weekly');
        $urls[] = $this->createUrl('stats_pairings', [], 0.6, 'weekly');

        // Device pages
        $devices = $this->getAllDevicesForSitemap();
        foreach ($devices as $device) {
            if (!empty($device['slug'])) {
                $urls[] = $this->createUrl(
                    'device_show',
                    ['slug' => $device['slug']],
                    0.8,
                    'weekly',
                    $device['last_seen'] ?? null
                );
            }
        }

        // Vendor pages
        $vendors = $this->vendorRepo->findAllOrderedByDeviceCount();
        foreach ($vendors as $vendor) {
            $urls[] = $this->createUrl(
                'vendor_show',
                ['slug' => $vendor->getSlug()],
                0.7,
                'weekly',
                $vendor->getUpdatedAt()->format('Y-m-d')
            );
        }

        // Device type pages
        $deviceTypes = $this->deviceTypeRepo->findAll();
        foreach ($deviceTypes as $deviceType) {
            $urls[] = $this->createUrl(
                'stats_device_type_show',
                ['type' => $deviceType->getId()],
                0.6,
                'monthly'
            );
        }

        // Cluster pages
        $clusters = $this->clusterRepo->findAll();
        foreach ($clusters as $cluster) {
            $urls[] = $this->createUrl(
                'stats_cluster_show',
                ['hexId' => $cluster->getHexId()],
                0.6,
                'monthly'
            );
        }

        $response = new Response(
            $this->renderSitemapXml($urls),
            Response::HTTP_OK,
            ['Content-Type' => 'application/xml; charset=utf-8']
        );

        // Cache for 1 hour
        $response->setSharedMaxAge(3600);

        return $response;
    }

    /**
     * Get all devices for sitemap (with pagination to handle large datasets).
     *
     * @return array<array{slug: string|null, last_seen: string|null}>
     */
    private function getAllDevicesForSitemap(): array
    {
        $devices = [];
        $offset = 0;
        $limit = 1000;

        do {
            $batch = $this->deviceRepo->getAllDevices($limit, $offset);
            $devices = array_merge($devices, $batch);
            $offset += $limit;
        } while (\count($batch) === $limit);

        return $devices;
    }

    /**
     * Create a URL entry for the sitemap.
     *
     * @param array<string, mixed> $params
     */
    private function createUrl(
        string $route,
        array $params,
        float $priority,
        string $changefreq,
        ?string $lastmod = null
    ): array {
        return [
            'loc' => $this->generateUrl($route, $params, UrlGeneratorInterface::ABSOLUTE_URL),
            'priority' => $priority,
            'changefreq' => $changefreq,
            'lastmod' => $lastmod,
        ];
    }

    /**
     * Render sitemap XML.
     *
     * @param array<array{loc: string, priority: float, changefreq: string, lastmod: string|null}> $urls
     */
    private function renderSitemapXml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1, 'UTF-8') . '</loc>' . "\n";

            if ($url['lastmod'] !== null) {
                // Ensure date is in W3C format (YYYY-MM-DD or full ISO 8601)
                $date = $url['lastmod'];
                if (\strlen($date) === 10) {
                    // Already YYYY-MM-DD format
                    $xml .= '    <lastmod>' . $date . '</lastmod>' . "\n";
                } elseif (strtotime($date) !== false) {
                    // Convert to YYYY-MM-DD
                    $xml .= '    <lastmod>' . date('Y-m-d', strtotime($date)) . '</lastmod>' . "\n";
                }
            }

            $xml .= '    <changefreq>' . $url['changefreq'] . '</changefreq>' . "\n";
            $xml .= '    <priority>' . number_format($url['priority'], 1) . '</priority>' . "\n";
            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }
}
