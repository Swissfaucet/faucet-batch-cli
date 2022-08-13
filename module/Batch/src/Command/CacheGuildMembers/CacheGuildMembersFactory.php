<?php

namespace Batch\Command\CacheGuildMembers;

class CacheGuildMembersFactory
{
    public function __invoke($controllers)
    {
        return new CacheGuildMembers($controllers->get('faucetdev'));
    }
}