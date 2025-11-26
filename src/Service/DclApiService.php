<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for fetching data from the Matter Distributed Compliance Ledger (DCL) API.
 */
class DclApiService
{
    private const BASE_URL = 'https://on.dcl.csa-iot.org';
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch all vendors from the DCL API.
     *
     * @return array<int, array{
     *     vendorID: int,
     *     vendorName: string,
     *     companyLegalName: string,
     *     companyPreferredName: string,
     *     vendorLandingPageURL: string
     * }>
     */
    public function fetchAllVendors(): array
    {
        $this->logger->info('Fetching vendors from DCL API');

        $vendors = [];
        $offset = 0;

        do {
            $response = $this->httpClient->request('GET', self::BASE_URL . '/dcl/vendorinfo/vendors', [
                'query' => [
                    'pagination.limit' => self::PAGE_SIZE,
                    'pagination.offset' => $offset,
                    'pagination.count_total' => 'true',
                ],
            ]);

            $data = $response->toArray();
            $vendorInfos = $data['vendorInfo'] ?? [];
            $total = (int) ($data['pagination']['total'] ?? 0);

            foreach ($vendorInfos as $vendor) {
                $vendors[] = $vendor;
            }

            $offset += self::PAGE_SIZE;

            $this->logger->debug('Fetched vendors', [
                'count' => \count($vendorInfos),
                'offset' => $offset,
                'total' => $total,
            ]);
        } while ($offset < $total);

        $this->logger->info('Finished fetching vendors', ['count' => \count($vendors)]);

        return $vendors;
    }

    /**
     * Fetch all models/products from the DCL API.
     *
     * @return array<int, array{
     *     vid: int,
     *     pid: int,
     *     deviceTypeId: int,
     *     productName: string,
     *     productLabel: string,
     *     partNumber: string,
     *     productUrl: string,
     *     supportUrl: string,
     *     userManualUrl: string
     * }>
     */
    public function fetchAllModels(): array
    {
        $this->logger->info('Fetching models from DCL API');

        $models = [];
        $offset = 0;

        do {
            $response = $this->httpClient->request('GET', self::BASE_URL . '/dcl/model/models', [
                'query' => [
                    'pagination.limit' => self::PAGE_SIZE,
                    'pagination.offset' => $offset,
                    'pagination.count_total' => 'true',
                ],
            ]);

            $data = $response->toArray();
            $modelInfos = $data['model'] ?? [];
            $total = (int) ($data['pagination']['total'] ?? 0);

            foreach ($modelInfos as $model) {
                $models[] = $model;
            }

            $offset += self::PAGE_SIZE;

            $this->logger->debug('Fetched models', [
                'count' => \count($modelInfos),
                'offset' => $offset,
                'total' => $total,
            ]);
        } while ($offset < $total);

        $this->logger->info('Finished fetching models', ['count' => \count($models)]);

        return $models;
    }
}
