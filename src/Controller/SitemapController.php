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

    /**
     * Sitemap index - lists all individual sitemaps.
     */
    #[Route('/sitemap.xml', name: 'sitemap_index', methods: ['GET'])]
    public function index(): Response
    {
        $sitemaps = [
            ['route' => 'sitemap_pages', 'changefreq' => 'weekly'],
            ['route' => 'sitemap_devices', 'changefreq' => 'daily'],
            ['route' => 'sitemap_vendors', 'changefreq' => 'weekly'],
            ['route' => 'sitemap_specs', 'changefreq' => 'monthly'],
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($sitemaps as $sitemap) {
            $loc = $this->generateUrl($sitemap['route'], [], UrlGeneratorInterface::ABSOLUTE_URL);
            $xml .= '  <sitemap>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc>' . "\n";
            $xml .= '    <lastmod>' . date('Y-m-d') . '</lastmod>' . "\n";
            $xml .= '  </sitemap>' . "\n";
        }

        $xml .= '</sitemapindex>';

        return $this->createXmlResponse($xml, 3600);
    }

    /**
     * Static pages sitemap.
     */
    #[Route('/sitemap-pages.xml', name: 'sitemap_pages', methods: ['GET'])]
    public function pages(): Response
    {
        $urls = [
            $this->createUrl('device_index', [], 1.0, 'daily'),
            $this->createUrl('vendor_index', [], 0.8, 'weekly'),
            $this->createUrl('stats_dashboard', [], 0.7, 'weekly'),
            $this->createUrl('stats_clusters', [], 0.7, 'weekly'),
            $this->createUrl('stats_device_types', [], 0.7, 'weekly'),
            $this->createUrl('stats_binding', [], 0.6, 'weekly'),
            $this->createUrl('stats_versions', [], 0.6, 'weekly'),
            $this->createUrl('stats_pairings', [], 0.6, 'weekly'),
            $this->createUrl('page_about', [], 0.5, 'monthly'),
            $this->createUrl('page_faq', [], 0.5, 'monthly'),
            $this->createUrl('page_glossary', [], 0.5, 'monthly'),
        ];

        return $this->createXmlResponse($this->renderSitemapXml($urls), 86400);
    }

    /**
     * Device pages sitemap.
     */
    #[Route('/sitemap-devices.xml', name: 'sitemap_devices', methods: ['GET'])]
    public function devices(): Response
    {
        $urls = [];
        $offset = 0;
        $limit = 1000;

        do {
            $batch = $this->deviceRepo->getAllDevices($limit, $offset);
            foreach ($batch as $device) {
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
            $offset += $limit;
        } while (\count($batch) === $limit);

        return $this->createXmlResponse($this->renderSitemapXml($urls), 3600);
    }

    /**
     * Vendor pages sitemap.
     */
    #[Route('/sitemap-vendors.xml', name: 'sitemap_vendors', methods: ['GET'])]
    public function vendors(): Response
    {
        $urls = [];
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

        return $this->createXmlResponse($this->renderSitemapXml($urls), 3600);
    }

    /**
     * Specs sitemap (device types + clusters).
     */
    #[Route('/sitemap-specs.xml', name: 'sitemap_specs', methods: ['GET'])]
    public function specs(): Response
    {
        $urls = [];

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

        return $this->createXmlResponse($this->renderSitemapXml($urls), 86400);
    }

    /**
     * Create a URL entry for the sitemap.
     *
     * @param array<string, mixed> $params
     * @return array{loc: string, priority: float, changefreq: string, lastmod: string|null}
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
     * Create XML response with caching.
     */
    private function createXmlResponse(string $content, int $maxAge): Response
    {
        $response = new Response(
            $content,
            Response::HTTP_OK,
            ['Content-Type' => 'application/xml; charset=utf-8']
        );

        $response->setSharedMaxAge($maxAge);

        return $response;
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
                $date = $url['lastmod'];
                if (\strlen($date) === 10) {
                    $xml .= '    <lastmod>' . $date . '</lastmod>' . "\n";
                } elseif (strtotime($date) !== false) {
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
