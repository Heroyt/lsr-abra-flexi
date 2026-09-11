<?php

declare(strict_types=1);

namespace Lsr\AbraFlexi\Di;

use Lsr\AbraFlexi\AbraFlexiClientFactory;
use Lsr\AbraFlexi\AbraFlexiConfiguration;
use Lsr\AbraFlexi\AbraFlexiInvoiceGateway;
use Lsr\AbraFlexi\AbraFlexiPriceListGateway;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * @property-read object{
 *     enabled: bool, apiUrl: string, company: string, username: string,
 *     password: string, currencyCode: string, timeout: int, verifyTls: bool,
 *     pdfReportName: string|null, pdfLanguage: string|null, traceCurl: bool,
 *     logger: string|null
 * } $config
 */
final class AbraFlexiExtension extends CompilerExtension
{
    public function getConfigSchema(): Schema {
        return Expect::structure([
            'enabled' => Expect::bool(false),
            'apiUrl' => Expect::string(''),
            'company' => Expect::string(''),
            'username' => Expect::string('')->dynamic(),
            'password' => Expect::string('')->dynamic(),
            'currencyCode' => Expect::string('CZK')->pattern('[A-Z]{3}'),
            'timeout' => Expect::int(30)->min(1),
            'verifyTls' => Expect::bool(true),
            'pdfReportName' => Expect::string()->nullable()->default(null),
            'pdfLanguage' => Expect::anyOf('cs', 'sk', 'en', 'de')->nullable()->default(null),
            'traceCurl' => Expect::bool(false),
            'logger' => Expect::string()->nullable()->default(null),
        ]);
    }

    public function loadConfiguration(): void {
        $builder = $this->getContainerBuilder();
        $config = $this->config;
        $configuration = $builder->addDefinition($this->prefix('configuration'))
            ->setFactory(AbraFlexiConfiguration::class, [
                'enabled' => $config->enabled,
                'apiUrl' => $config->apiUrl,
                'company' => $config->company,
                'username' => $config->username,
                'password' => $config->password,
                'currencyCode' => $config->currencyCode,
                'timeout' => $config->timeout,
                'verifyTls' => $config->verifyTls,
                'pdfReportName' => $config->pdfReportName,
                'pdfLanguage' => $config->pdfLanguage,
                'traceCurl' => $config->traceCurl,
            ]);
        $logger = $config->logger === null ? [] : ['logger' => $config->logger];
        $factory = $builder->addDefinition($this->prefix('clientFactory'))
            ->setFactory(AbraFlexiClientFactory::class, ['configuration' => $configuration] + $logger);
        $arguments = ['configuration' => $configuration, 'clientFactory' => $factory] + $logger;
        $builder->addDefinition($this->prefix('invoiceGateway'))
            ->setFactory(AbraFlexiInvoiceGateway::class, $arguments);
        $builder->addDefinition($this->prefix('priceListGateway'))
            ->setFactory(AbraFlexiPriceListGateway::class, $arguments);
    }
}
