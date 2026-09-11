<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi;

use AbraFlexi\Cenik;
use InvalidArgumentException;
use Lsr\AbraFlexi\Dto\PriceListItem;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class AbraFlexiPriceListGateway
{
    private ?Cenik $client;
    private readonly AbraFlexiClientFactory $clientFactory;

    public function __construct(
        private readonly AbraFlexiConfiguration $configuration,
        LoggerInterface $logger,
        ?Cenik $client = null,
        ?AbraFlexiClientFactory $clientFactory = null,
    ) {
        $this->client = $client;
        $this->clientFactory = $clientFactory ?? new AbraFlexiClientFactory($configuration, $logger);
    }

    public function isConfigured(): bool {
        return $this->configuration->isConfiguredForPriceList();
    }

    public function findByCode(string $code): ?PriceListItem {
        if ( ! $this->isConfigured()) {
            throw new RuntimeException('ABRA Flexi price-list access is not configured.');
        }

        $normalizedCode = $this->normalizeCode($code);
        try {
            $client = $this->client();
            $client->dataReset();
            $client->defaultUrlParams['detail'] = 'full';
            // Encode the whole selector to bypass the SDK's filter DSL and code normalization.
            $loaded = $client->loadFromAbraFlexi(rawurlencode('code:' . $normalizedCode));
        } catch (Throwable) {
            // SDK exceptions can contain credentials, URLs, and raw response bodies.
            throw new RuntimeException('ABRA Flexi price-list lookup failed.');
        }

        if ($loaded === 0) {
            return null;
        }

        return $this->mapItem($client);
    }

    private function client(): Cenik {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = $this->clientFactory->priceList();

        return $this->client;
    }

    private function normalizeCode(string $code): string {
        $code = trim($code);
        if (str_starts_with($code, 'code:')) {
            $code = substr($code, 5);
        }
        if ($code === '' || strlen($code) > 64 || preg_match('/[\x00-\x1F\x7F]/', $code) === 1) {
            throw new InvalidArgumentException('The ABRA Flexi price-list code must contain 1 to 64 printable characters.');
        }

        return $code;
    }

    private function mapItem(Cenik $client): PriceListItem {
        $code = AbraFlexiValueMapper::requiredString($client->getDataValue('kod'), 'kod');
        $name = AbraFlexiValueMapper::requiredString($client->getDataValue('nazev'), 'nazev');

        return new PriceListItem(
            code: $code,
            name: $name,
            currencyCode: $this->configuration->currencyCode,
            priceAmountMinor: AbraFlexiValueMapper::decimalToMinor($client->getDataValue('cenaZaklVcDph'), 'cenaZaklVcDph'),
            netPriceAmountMinor: AbraFlexiValueMapper::decimalToMinor($client->getDataValue('cenaZaklBezDph'), 'cenaZaklBezDph'),
            priceTypeCode: AbraFlexiValueMapper::requiredString($client->getDataValue('typCenyDphK'), 'typCenyDphK'),
            vatRateTypeCode: AbraFlexiValueMapper::requiredString($client->getDataValue('typSzbDphK'), 'typSzbDphK'),
        );
    }

}
