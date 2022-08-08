<?php

namespace Batch\Command\TransactionStats;

class TransactionStatsFactory
{
    public function __invoke($controllers)
    {
        return new TransactionStats($controllers->get('faucetdev'));
    }
}