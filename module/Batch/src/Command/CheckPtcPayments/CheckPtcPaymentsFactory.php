<?php

namespace Batch\Command\CheckPtcPayments;

class CheckPtcPaymentsFactory {
    public function __invoke($controllers): CheckPtcPayments
    {
        return new CheckPtcPayments($controllers->get('faucetdev'));
    }
}