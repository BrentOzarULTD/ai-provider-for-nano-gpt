<?php

declare(strict_types=1);

namespace WordPress\NanoGptAiProvider\Account;

/**
 * Immutable Nano-GPT account balance value.
 *
 * @since 1.0.0
 */
class NanoGptBalance
{
    /** @var string USD balance as returned by the API. */
    private string $usdBalance;

    /** @var string Nano (XNO) balance as returned by the API. */
    private string $nanoBalance;

    public function __construct(string $usdBalance, string $nanoBalance)
    {
        $this->usdBalance = $usdBalance;
        $this->nanoBalance = $nanoBalance;
    }

    public function getUsdBalance(): string
    {
        return $this->usdBalance;
    }

    public function getNanoBalance(): string
    {
        return $this->nanoBalance;
    }
}
