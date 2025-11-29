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
     *     userManualUrl: string,
     *     commissioningCustomFlow: int,
     *     commissioningCustomFlowUrl: string,
     *     commissioningModeInitialStepsHint: int,
     *     commissioningModeInitialStepsInstruction: string,
     *     commissioningModeSecondaryStepsHint: int,
     *     commissioningModeSecondaryStepsInstruction: string,
     *     discoveryCapabilitiesBitmask: int,
     *     maintenanceUrl: string,
     *     factoryResetStepsHint: int,
     *     factoryResetStepsInstruction: string,
     *     icdUserActiveModeTriggerHint: int,
     *     icdUserActiveModeTriggerInstruction: string,
     *     commissioningFallbackUrl: string,
     *     lsfUrl: string,
     *     lsfRevision: int,
     *     enhancedSetupFlowOptions: int,
     *     enhancedSetupFlowTCUrl: string,
     *     enhancedSetupFlowTCRevision: int,
     *     enhancedSetupFlowTCDigest: string,
     *     enhancedSetupFlowTCFileSize: int
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

    /**
     * Fetch all certified models from the DCL API.
     *
     * Returns a map of vid:pid => array of certified software versions.
     *
     * @return array<string, array{
     *     vid: int,
     *     pid: int,
     *     certifiedVersions: array<int>,
     *     certificationType: string
     * }>
     */
    public function fetchAllCertifiedModels(): array
    {
        $this->logger->info('Fetching certified models from DCL API');

        $certifiedModels = [];
        $offset = 0;

        do {
            $response = $this->httpClient->request('GET', self::BASE_URL . '/dcl/compliance/certified-models', [
                'query' => [
                    'pagination.limit' => self::PAGE_SIZE,
                    'pagination.offset' => $offset,
                    'pagination.count_total' => 'true',
                ],
            ]);

            $data = $response->toArray();
            $certifiedModelInfos = $data['certifiedModel'] ?? [];
            $total = (int) ($data['pagination']['total'] ?? 0);

            foreach ($certifiedModelInfos as $cert) {
                $key = $cert['vid'] . ':' . $cert['pid'];

                if (!isset($certifiedModels[$key])) {
                    $certifiedModels[$key] = [
                        'vid' => $cert['vid'],
                        'pid' => $cert['pid'],
                        'certifiedVersions' => [],
                        'certificationType' => $cert['certificationType'] ?? 'matter',
                    ];
                }

                // Only add if value is true (certified)
                if (($cert['value'] ?? false) === true && isset($cert['softwareVersion'])) {
                    $certifiedModels[$key]['certifiedVersions'][] = $cert['softwareVersion'];
                }
            }

            $offset += self::PAGE_SIZE;

            $this->logger->debug('Fetched certified models', [
                'count' => \count($certifiedModelInfos),
                'offset' => $offset,
                'total' => $total,
            ]);
        } while ($offset < $total);

        // Sort versions for each model
        foreach ($certifiedModels as &$model) {
            sort($model['certifiedVersions']);
        }

        $this->logger->info('Finished fetching certified models', ['count' => \count($certifiedModels)]);

        return $certifiedModels;
    }

    /**
     * Fetch PAA (Product Attestation Authority) root certificates.
     *
     * @return array<int, array{
     *     subject: string,
     *     subjectKeyId: string
     * }>
     */
    public function fetchPaaRootCertificates(): array
    {
        $this->logger->info('Fetching PAA root certificates from DCL API');

        $response = $this->httpClient->request('GET', self::BASE_URL . '/dcl/pki/root-certificates');
        $data = $response->toArray();

        $certs = $data['approvedRootCertificates']['certs'] ?? [];

        $this->logger->info('Finished fetching PAA certificates', ['count' => \count($certs)]);

        return $certs;
    }
}
