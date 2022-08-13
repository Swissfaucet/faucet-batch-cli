<?php

namespace Batch\Command\CheckPtcPayments;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Batch\Tools\TransactionTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Http\ClientStatic;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckPtcPayments extends Command
{
    /**
     * Dailytask User Table
     *
     * @var TableGateway $mDepositTbl
     * @since 1.0.0
     */
    protected TableGateway $mDepositTbl;

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
     * Batch Tools
     */
    protected BatchTools $mBatchTools;

    /**
     * Constructor
     *
     * UserResource constructor.
     * @param $mapper
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        $this->mDepositTbl = new TableGateway('ptc_deposit', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mTxTools = new TransactionTools($mapper);
        $this->mBatchTools = new BatchTools($mapper);

        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // log start
        $output->writeln([
            '=====================',
            'Check for PTC Crypto Payments @ ' . date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastrun = $this->mSecTools->getCoreSetting('job_ptc_payments_lastrun');

        // only run batch once per day
        if(strtotime($lastrun) < strtotime('-30 minutes')) {
            # all good
        } else {
            $output->writeln([
                '## ERROR - BATCH HAS ALREADY RUN IN THE LAST 30 MINUTES',
            ]);
            return Command::SUCCESS;
        }

        $openWh = new Where();
        $openWh->like('coin', 'USD');
        $openWh->equalTo('sent', 0);
        $openWh->greaterThanOrEqualTo('date', date('Y-m-d H:i:s', strtotime('-3 hours')));

        $openDeposits = $this->mDepositTbl->select($openWh);
        $openDepCount = $openDeposits->count();

        if($openDepCount > 0) {
            $output->writeln([
                '- Processing '.$openDepCount.' pending deposits',
            ]);

            $this->processCryptoPayments($openDeposits, $output);
        } else {
            $output->writeln([
                '- No pending deposits found',
            ]);
        }

        $this->mSecTools->updateCoreSetting('job_ptc_payments_lastrun', date('Y-m-d H:i:s', time()));

        return Command::SUCCESS;
    }

    /**
     * Process Pending Crypto Payments
     * @param $payments
     * @param $output
     */
    private function processCryptoPayments($payments, $output) {
        $merchKey = $this->mSecTools->getCoreSetting('cu-merchant-key');
        $secKey = $this->mSecTools->getCoreSetting('cu-secret-key');
        $creditsUsed = 0;

        foreach($payments as $payment) {
            if(strlen($payment->wallet_receive) > 6 && $payment->coin == 'USD') {
                $response = ClientStatic::get(
                    'https://cryptounifier.io/api/v1/merchant/invoice-info', [
                    'invoice_hash' => $payment->wallet_receive
                    ], [
                    'X-Merchant-Key' => $merchKey,
                    'X-Secret-Key' => $secKey
                ]);
                $status = $response->getStatusCode();
                if($status == 200) {
                    $creditsUsed+=0.1;
                    $responseBody = $response->getBody();

                    $responseJson = json_decode($responseBody);
                    if(isset($responseJson->message->status)) {
                        $paymentStatus = filter_var($responseJson->message->status, FILTER_SANITIZE_NUMBER_INT);
                        if($paymentStatus == 2 || $paymentStatus == 3) {
                            $output->writeln([
                                '- Invoice '.$payment->wallet_receive. ' is paid - sending PTC Credits to User '.$payment->user_idfs,
                            ]);
                            $sent = $this->sendPTCCreditsToUser($payment->amount, $payment->user_idfs, $payment->Deposit_ID);
                            if($sent) {
                                $output->writeln([
                                    '- Successfully sent '.$payment->amount.' PTC Credits to User '.$payment->user_idfs,
                                ]);
                            } else {
                                $output->writeln([
                                    '## ERROR WHILE SENDING '.$payment->amount.' PTC Credits to User '.$payment->user_idfs,
                                ]);
                            }
                        } else {
                            $output->writeln([
                                '- Invoice '.$payment->wallet_receive. ' not paid yet - checking again later',
                            ]);
                        }
                    } else {
                        $output->writeln([
                            '- Could get status for invoice '.$payment->wallet_receive,
                        ]);
                    }
                } else {
                    $output->writeln([
                        '- Could not load invoice info - status code = '.$status,
                    ]);
                }
            } else {
                $output->writeln([
                    '- Skipping Deposit No '.$payment->Deposit_ID.' because of invalid currency / invoice no',
                ]);
            }
        }

        $output->writeln([
            '- Total Credits used for this run: '.$creditsUsed,
        ]);
    }

    /**
     * Send PTC Credits to User and mark deposit as done
     *
     * @param $amount
     * @param $userId
     * @param $depositId
     * @return bool
     */
    private function sendPTCCreditsToUser($amount, $userId, $depositId): bool
    {
        if($depositId <= 0 || $userId <= 0 || $amount <= 0) {
            return false;
        }
        $newBalance = $this->mTxTools->executeCreditTransaction($amount, false, $userId, $depositId, 'deposit');
        if($newBalance) {
            $this->mDepositTbl->update(['received' => 1, 'sent' => 1], ['Deposit_ID' => $depositId]);
            return true;
        } else {
            return false;
        }
    }
}