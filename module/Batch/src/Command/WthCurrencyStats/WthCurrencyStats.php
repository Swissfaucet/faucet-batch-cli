<?php

namespace Batch\Command\WthCurrencyStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WthCurrencyStats extends Command
{
    /**
     * User Table
     *
     * @var TableGateway $mWthTbl
     * @since 1.0.0
     */
    protected TableGateway $mWthTbl;

    /**
     * User Statistics Table
     *
     * @var TableGateway $mUserStatsTbl
     * @since 1.0.0
     */
    protected TableGateway $mUserStatsTbl;

    /**
     * Security Tools
     *
     * @var SecurityTools $mSecTools
     */
    protected SecurityTools $mSecTools;

    /**
     * Batch Tools
     */
    protected BatchTools $mBatchTools;

    private TableGateway $mStatsTbl;

    private OutputInterface $output;

    /**
     * Constructor
     *
     * UserResource constructor.
     * @param $mapper
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        $this->mWthTbl = new TableGateway('faucet_withdraw', $mapper);
        $this->mUserStatsTbl = new TableGateway('user_faucet_stat', $mapper);
        $this->mStatsTbl = new TableGateway('core_statistic', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mBatchTools = new BatchTools($mapper);

        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        // log start
        $output->writeln([
            '=====================',
            'Generating Withdraw Currency Stats @ '.date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastrun = $this->mSecTools->getCoreSetting('job_wth_currency_stats_lastrun');
        // only run batch once per day
        if(date('Y-m-d', strtotime($lastrun)) != date('Y-m-d', time())) {
            # all good
        } else {
            $output->writeln([
                '## ERROR - BATCH HAS ALREADY RUN TODAY',
            ]);
            return Command::SUCCESS;
        }

        $accountsFound = $this->processWithdrawals();
        $output->writeln([
            'Processed '.$accountsFound.' withdrawals',
        ]);

        $this->mSecTools->updateCoreSetting('job_wth_currency_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Withdraw Currency Statistics updated successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function processWithdrawals()
    {
        // load shares
        $tWh = new Where();
        $tWh->like('state', 'done');
        $tSel = new Select($this->mWthTbl->getTable());
        $tSel->where($tWh);

        $accountsToday = $this->mWthTbl->selectWith($tSel);

        // Count Entries
        $accountsCount = $accountsToday->count();

        $currenciesPerUserId = [];
        $currenciesGlobal = [];
        $currencyAmountGlobal = [];
        $totalWithdrawnInCoins = 0;
        foreach($accountsToday as $account) {
            $totalWithdrawnInCoins+=$account->amount;
            if(!array_key_exists('user-'.$account->user_idfs, $currenciesPerUserId)) {
                $currenciesPerUserId['user-'.$account->user_idfs] = [];
            }
            if(!array_key_exists('coin-'.$account->currency, $currenciesPerUserId['user-'.$account->user_idfs])) {
                $currenciesPerUserId['user-'.$account->user_idfs]['coin-'.$account->currency] = 0;
            }
            $currenciesPerUserId['user-'.$account->user_idfs]['coin-'.$account->currency]++;

            if(!array_key_exists('coin-'.$account->currency, $currenciesGlobal)) {
                $currenciesGlobal['coin-'.$account->currency] = 0;
            }
            $currenciesGlobal['coin-'.$account->currency]++;

            if(!array_key_exists('coin-'.$account->currency, $currencyAmountGlobal)) {
                $currencyAmountGlobal['coin-'.$account->currency] = 0;
            }
            $currencyAmountGlobal['coin-'.$account->currency]+=$account->amount_paid;
        }

        $this->output->writeLn([
            '- Update User Withdraw Coin Stats for '.count($currenciesPerUserId).' Users'
        ]);
        // update user stats (alltime)
        $key = 'user-wth';
        $this->updateUserStatsByKey($key, $currenciesPerUserId);

        $this->output->writeLn([
            '- Update Core Stats for '.count($currenciesGlobal).' Currencies'
        ]);
        // update core stats (total)
        $key = 'wth-amount';
        foreach($currenciesGlobal as $coinKey => $coinVal) {
            $coinId = strtolower(substr($coinKey, strlen('coin-')));
            if($coinId != '') {
                $cKey = $key.'-'.$coinId.'-total';
                $this->updateStatsByKey($cKey, $coinVal);
            }
        }

        // update core stats (total)
        $key = 'wth-crypto';
        foreach($currencyAmountGlobal as $coinKey => $coinVal) {
            $coinId = strtolower(substr($coinKey, strlen('coin-')));
            if($coinId != '') {
                $cKey = $key.'-'.$coinId.'-total';
                $this->updateStatsByKey($cKey, $coinVal);
            }
        }

        $key = 'wth-coins-total';
        $this->updateStatsByKey($key, $totalWithdrawnInCoins);


        return $accountsCount;
    }

    private function updateUserStatsByKey($key, $refsByUserId) : void
    {
        foreach(array_keys($refsByUserId) as $userIdStr) {
            $userId = substr($userIdStr, strlen('user-'));
            if(is_numeric($userId) && $userId > 0 && !empty($userId)) {
                $now = date('Y-m-d H:i:s', time());
                foreach(array_keys($refsByUserId[$userIdStr]) as $coinStr) {
                    $coinId = strtolower(substr($coinStr, strlen('coin-')));
                    if($coinId != '') {
                        $coinKey = $key.'-'.$coinId.'-total';
                        $check = $this->mUserStatsTbl->select(['user_idfs' => $userId, 'stat_key' => $coinKey]);
                        if($check->count() == 0) {
                            $this->mUserStatsTbl->insert([
                                'user_idfs' => $userId,
                                'stat_key' => $coinKey,
                                'stat_data' => $refsByUserId[$userIdStr][$coinStr],
                                'date' => $now
                            ]);
                        } else {
                            $this->mUserStatsTbl->update([
                                'stat_data' => $refsByUserId[$userIdStr][$coinStr],
                                'date' => $now
                            ],['user_idfs' => $userId, 'stat_key' => $coinKey]);
                        }
                    }
                }
            }
        }
    }

    private function updateStatsByKey($key, $newVal) : void
    {
        $now = date('Y-m-d H:i:s', time());

        $check = $this->mStatsTbl->select(['stats_key' => $key]);
        if($check->count() == 0) {
            // start of a new month
            $this->mStatsTbl->insert([
                'stats_key' => $key,
                'data' => $newVal,
                'date' => $now
            ]);
        } else {
            $this->mStatsTbl->update([
                'data' => $newVal,
                'date' => $now
            ],['stats_key' => $key]);
        }
    }
}