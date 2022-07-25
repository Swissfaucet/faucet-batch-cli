<?php

namespace Batch\Command\MinerShares;

class MinerSharesFactory
{
    public function __invoke($controllers)
    {
        return new MinerShares($controllers->get('faucetdev'));
    }
}