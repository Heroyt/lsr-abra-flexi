<?php

declare(strict_types=1);

namespace Tests;

use Lsr\AbraFlexi\AbraFlexiInvoiceGateway;
use Lsr\AbraFlexi\AbraFlexiPriceListGateway;
use Lsr\AbraFlexi\Enums\InvoiceGatewayFailure;
use Lsr\AbraFlexi\Exceptions\InvoiceGatewayException;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\Extensions\ExtensionsExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AbraFlexiExtensionTest extends TestCase
{
    public function test_shipped_example_resolves_services_and_rejects_disabled_access(): void {
        $compiler = new Compiler();
        $compiler->addExtension('extensions', new ExtensionsExtension());
        $compiler->loadConfig(dirname(__DIR__) . '/examples/abra-flexi.neon');
        $compiler->addConfig(['services' => ['logger' => ['create' => NullLogger::class]]]);
        $className = 'AbraExampleContainer' . bin2hex(random_bytes(8));
        $compiler->setClassName($className);
        eval($compiler->compile());
        $container = new $className();
        self::assertInstanceOf(Container::class, $container);
        $container->initialize();

        $priceList = $container->getByType(AbraFlexiPriceListGateway::class);
        self::assertFalse($priceList->isConfigured());
        $invoice = $container->getByType(AbraFlexiInvoiceGateway::class);
        try {
            $invoice->findByExternalId('ext:example:invoice.1');
            self::fail('The example must not enable accounting requests implicitly.');
        } catch (InvoiceGatewayException $exception) {
            self::assertSame(InvoiceGatewayFailure::ConfigurationDisabled, $exception->failure);
        }
    }
}
