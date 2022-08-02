<?php

namespace Batch\Command\FaucetClaimStats;

class FaucetClaimStatsFactory {
    public function __invoke($controllers): FaucetClaimStats
    {
        return new FaucetClaimStats($controllers->get('faucetdev'));
    }
}
