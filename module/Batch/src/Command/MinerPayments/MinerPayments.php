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
     * Withdraw Buff Table
     *
     * @var TableGateway $mBuffTbl
     * @since 1.0.0
     */
    protected TableGateway $mBuffTbl;

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
        $this->mBuffTbl = new TableGateway('faucet_withdraw_buff', $mapper);

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

        $paymentQueue = $this->mPaymentTbl->select(['state' => 'open']);
        $totalPayments = $paymentQueue->count();
        if($totalPayments == 0) {
            $output->writeln([
                '## ERROR - NO PAYMENTS IN QUEUE',
            ]);
            return Command::SUCCESS;
        }

        $totalPaid = 0;
        foreach ($paymentQueue as $payment) {
            // send coins to user
            $newBalance = $this->mTxTools->executeTransaction($payment->amount_coin, false, $payment->user_idfs, $payment->id, $payment->coin.'-nanov2', $payment->shares_percent.'% of all shares on pool.', 0, false);
            if($newBalance) {
                $totalPaid+=$payment->amount_coin;
                // Add Buff for daily withdrawal limit
                $this->mBuffTbl->insert([
                    'ref_idfs' => 0,
                    'ref_type' => 'mining',
                    'label' => $payment->coin.' Mining',
                    'days_left' => 1,
                    'days_total' => 1,
                    'amount' => $payment->amount_coin,
                    'created_date' => date('Y-m-d H:i:s', time()),
                    'user_idfs' => $payment->user_idfs
                ]);
            } else {
                $output->writeln([
                    '## ERROR - PAYMENT '.$payment->id.' COULD NOT BE EXECUTED',
                ]);
            }
        }

        $output->writeln([
            '-- Sent '.$totalPayments.' Payments',
        ]);
        $output->writeln([
            '-- Paid '.$totalPaid.' Coins to Users',
        ]);

        // Flag payments as done
        $this->mPaymentTbl->update(['state' => 'paid'],['state' => 'open']);

        $output->writeln([
            '-- Payment run completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }
}