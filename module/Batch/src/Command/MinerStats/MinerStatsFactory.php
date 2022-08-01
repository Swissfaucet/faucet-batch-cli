<?php

namespace Batch\Command\MinerStats;

class MinerStatsFactory {
    public function __invoke($controllers): MinerStats
    {
        return new MinerStats($controllers->get('faucetdev'));
    }
}