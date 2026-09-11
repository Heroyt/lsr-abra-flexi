<?php

declare(strict_types=1);

namespace Tests;

use AbraFlexi\Cenik;
use InvalidArgumentException;
use LogicException;
use Lsr\AbraFlexi\AbraFlexiConfiguration;
use Lsr\AbraFlexi\AbraFlexiPriceListGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use UnexpectedValueException;

final class AbraFlexiPriceListGatewayTest extends TestCase
{
    public function test_configuration_requires_explicit_enablement_and_connection_details(): void {
        $configuration = new AbraFlexiConfiguration(
            enabled: false,
            apiUrl: 'https://abra.example.test',
            company: 'test_company',
            username: 'api-user',
            password: 'api-password',
            currencyCode: 'GBP',
        );

        self::assertFalse($configuration->isConfiguredForPriceList());
    }

    public function test_price_list_amounts_are_rounded_to_hundredths_of_company_currency(): void {
        $client = $this->createMock(Cenik::class);
        $client->expects(self::once())
            ->method('loadFromAbraFlexi')
            ->with(['kod' => 'SERVICE-90', 'detail' => 'full'])
            ->willReturn(7);
        $client->method('getDataValue')->willReturnMap([
            ['kod', 'SERVICE-90'],
            ['nazev', 'Service 90 minutes'],
            ['cenaZaklVcDph', '990.005000'],
            ['cenaZaklBezDph', '818.181818'],
            ['typCenyDphK', 'typCeny.sDph'],
            ['typSzbDphK', 'typSzbDph.dphZakl'],
        ]);

        $item = $this->gateway($client)->findByCode(' code:SERVICE-90 ');

        self::assertNotNull($item);
        self::assertSame('SERVICE-90', $item->code);
        self::assertSame('Service 90 minutes', $item->name);
        self::assertSame('GBP', $item->currencyCode);
        self::assertSame(99001, $item->priceAmountMinor);
        self::assertSame(81818, $item->netPriceAmountMinor);
        self::assertSame('typCeny.sDph', $item->priceTypeCode);
        self::assertSame('typSzbDph.dphZakl', $item->vatRateTypeCode);
    }

    public function test_reused_client_does_not_fill_missing_prices_from_the_previous_item(): void {
        $client = $this->getMockBuilder(Cenik::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadFromAbraFlexi'])
            ->getMock();
        $client->expects(self::exactly(2))->method('loadFromAbraFlexi')->willReturnCallback(static function (array $parameters) use ($client): int {
            $fields = [
                'kod' => $parameters['kod'],
                'nazev' => 'Service',
                'cenaZaklVcDph' => '121.00',
                'typCenyDphK' => 'typCeny.sDph',
                'typSzbDphK' => 'typSzbDph.dphZakl',
            ];
            if ($parameters['kod'] === 'COMPLETE') {
                $fields['cenaZaklBezDph'] = '100.00';
            }
            foreach ($fields as $field => $value) {
                $client->setDataValue($field, $value);
            }
            return 1;
        });
        $gateway = $this->gateway($client);
        self::assertSame(10000, $gateway->findByCode('COMPLETE')?->netPriceAmountMinor);

        $this->expectException(UnexpectedValueException::class);
        $gateway->findByCode('INCOMPLETE');
    }

    public function test_missing_price_list_item_returns_null(): void {
        $client = $this->createMock(Cenik::class);
        $client->expects(self::once())
            ->method('loadFromAbraFlexi')
            ->with(['kod' => 'MISSING', 'detail' => 'full'])
            ->willReturn(0);
        $client->expects(self::never())->method('getDataValue');

        self::assertNull($this->gateway($client)->findByCode('MISSING'));
    }

    public function test_invalid_code_is_rejected_before_accessing_abra(): void {
        $client = $this->createMock(Cenik::class);
        $client->expects(self::never())->method('loadFromAbraFlexi');

        $this->expectException(InvalidArgumentException::class);
        $this->gateway($client)->findByCode("INVALID\nCODE");
    }

    public function test_library_failures_are_wrapped_at_the_adapter_boundary(): void {
        $client = $this->createStub(Cenik::class);
        $client->method('loadFromAbraFlexi')->willThrowException(new LogicException('api-password customer@example.test https://api-user:secret@private.example.test rejected'));

        try {
            $this->gateway($client)->findByCode('SERVICE-90');
            self::fail('The library exception should not cross the package adapter boundary.');
        } catch (RuntimeException $exception) {
            self::assertNull($exception->getPrevious());
            foreach (['api-password', 'customer@example.test', 'api-user', 'secret', 'private.example.test'] as $sensitive) {
                self::assertStringNotContainsString($sensitive, $exception->getMessage());
            }
        }
    }

    public function test_incomplete_price_data_is_rejected(): void {
        $client = $this->createStub(Cenik::class);
        $client->method('loadFromAbraFlexi')->willReturn(1);
        $client->method('getDataValue')->willReturnMap([
            ['kod', 'SERVICE-90'],
            ['nazev', 'Service 90 minutes'],
            ['cenaZaklVcDph', 'not-a-price'],
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->gateway($client)->findByCode('SERVICE-90');
    }

    private function configuration(): AbraFlexiConfiguration {
        return new AbraFlexiConfiguration(
            enabled: true,
            apiUrl: 'https://abra.example.test',
            company: 'test_company',
            username: 'api-user',
            password: 'api-password',
            currencyCode: 'GBP',
        );
    }

    private function gateway(Cenik $client): AbraFlexiPriceListGateway {
        return new AbraFlexiPriceListGateway($this->configuration(), new NullLogger(), $client);
    }
}
