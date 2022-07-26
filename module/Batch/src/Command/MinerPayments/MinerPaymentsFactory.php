<?php

namespace Batch\Command\MinerPayments;

class MinerPaymentsFactory {

    public function __invoke($controllers): MinerPayments
    {
        return new MinerPayments($controllers->get('faucetdev'));
    }
}
