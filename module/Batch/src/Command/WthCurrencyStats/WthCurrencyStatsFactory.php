<?php

namespace Batch\Command\WthCurrencyStats;

class WthCurrencyStatsFactory
{
    public function __invoke($controllers)
    {
        return new WthCurrencyStats($controllers->get('faucetdev'));
    }
}