<?php

namespace Batch\Command\WithdrawStats;

class WithdrawStatsFactory
{
    public function __invoke($controllers)
    {
        return new WithdrawStats($controllers->get('faucetdev'));
    }
}