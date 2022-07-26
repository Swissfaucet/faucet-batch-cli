<?php

namespace Batch\Command\MinerPayments;

use Batch\Tools\SecurityTools;
use Batch\Tools\TransactionTools;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MinerPayments extends Command
{
    /**
     * Miner Payments Table
     *
     * @var TableGateway $mPaymentTbl
     * @since 1.0.0
     */
    protected TableGateway $mPaymentTbl;

    /**
     * Security Tools
     *
     * @var SecurityTools $mSecTools
     */
    protected SecurityTools $mSecTools;

    /**
     * Transaction Tools
     *
     * @var TransactionTools $mTxTools
     */
    protected TransactionTools $mTxTools;

    /**
     * Constructor
     *
     * UserResource constructor.
     * @param $mapper
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        $this->mPaymentTbl = new TableGateway('faucet_miner_payment', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mTxTools = new TransactionTools($mapper);

        // you *must* call the parent constructor
        parent::__construct();
    }

    /**
     * Run MinerPayments Command
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // log start
        $output->writeln([
            '=====================',
            'Sending Miner Payments @ ' . date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        return Command::SUCCESS;
    }
}