<?php

namespace Batch\Command\OfferwallStats;

class OfferwallStatsFactory {
    public function __invoke($controllers): OfferwallStats
    {
        return new OfferwallStats($controllers->get('faucetdev'));
    }
}