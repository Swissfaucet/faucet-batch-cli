<?php

namespace Batch\Command\UserAccountStats;

class UserAccountStatsFactory
{
    public function __invoke($controllers)
    {
        return new UserAccountStats($controllers->get('faucetdev'));
    }
}