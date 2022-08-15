<?php

namespace Batch\Command\FakeAccountCheck;

class FakeAccountCheckFactory
{
    public function __invoke($controllers)
    {
        return new FakeAccountCheck($controllers->get('faucetdev'));
    }
}