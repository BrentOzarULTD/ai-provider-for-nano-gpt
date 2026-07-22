<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Tests\Account;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WordPress\NanoGptAiProvider\Account\NanoGptBalanceClient;

class NanoGptBalanceClientTest extends TestCase
{
    public function testCreatesBalanceFromApiData(): void
    {
        $balance = NanoGptBalanceClient::fromArray([
            'usd_balance' => '12.3456',
            'nano_balance' => '7.8901',
            'nanoDepositAddress' => 'not-retained',
        ]);

        self::assertSame('12.3456', $balance->getUsdBalance());
        self::assertSame('7.8901', $balance->getNanoBalance());
    }

    public function testRejectsMissingBalanceValues(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid balance values');

        NanoGptBalanceClient::fromArray(['usd_balance' => '12.34']);
    }
}
