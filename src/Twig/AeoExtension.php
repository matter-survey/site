<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\AeoLedeService;
use App\Service\StructuredDataService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the AEO services (AeoLedeService + StructuredDataService) as Twig
 * functions. Templates emit the lede sentence directly and use the
 * structured-data functions inside the `structured_data` block:
 *
 *   <p class="aeo-lede">{{ aeo_lede_device(device, endpoints|length) }}</p>
 *   <script type="application/ld+json">
 *   {{ structured_data_device(device, endpoints|length, dateModified)
 *      |json_encode(constant('JSON_UNESCAPED_SLASHES'))|raw }}
 *   </script>
 */
final class AeoExtension extends AbstractExtension
{
    public function __construct(
        private readonly AeoLedeService $lede,
        private readonly StructuredDataService $structuredData,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('aeo_lede_device', $this->lede->ledeForDevice(...)),
            new TwigFunction('aeo_lede_vendor', $this->lede->ledeForVendor(...)),
            new TwigFunction('aeo_lede_cluster', $this->lede->ledeForCluster(...)),
            new TwigFunction('aeo_lede_device_type', $this->lede->ledeForDeviceType(...)),

            new TwigFunction('structured_data_device', $this->structuredData->deviceJsonLd(...)),
            new TwigFunction('structured_data_vendor', $this->structuredData->vendorJsonLd(...)),
            new TwigFunction('structured_data_cluster', $this->structuredData->clusterJsonLd(...)),
            new TwigFunction('structured_data_device_type', $this->structuredData->deviceTypeJsonLd(...)),
            new TwigFunction('structured_data_dataset', $this->structuredData->datasetJsonLd(...)),
            new TwigFunction('structured_data_breadcrumb', $this->structuredData->breadcrumbListJsonLd(...)),
        ];
    }
}
