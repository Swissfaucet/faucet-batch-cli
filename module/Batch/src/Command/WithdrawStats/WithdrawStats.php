<?php

namespace Batch\Command\WithdrawStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class WithdrawStats extends Command
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
            'Checking for new Withdrawals @ '.date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastDate = $this->mSecTools->getCoreSetting('job_withdraw_stats_date');
        $lastrun = $this->mSecTools->getCoreSetting('job_withdraw_stats_lastrun');

        // only run batch once per day
        if(date('Y-m-d', strtotime($lastrun)) != date('Y-m-d', time())) {
            try {
                $stop_date = new \DateTime($lastDate);
                $stop_date->modify('+1 day');
                $statDate = strtotime($stop_date->format('Y-m-d H:i:s'));
            } catch (\Exception $e) {
                $output->writeln([
                    '## ERROR - COULD NOT INITIALIZE DATE',
                ]);
                return Command::SUCCESS;
            }
        } else {
            $output->writeln([
                '## ERROR - BATCH HAS ALREADY RUN TODAY',
            ]);
            return Command::SUCCESS;
        }

        $output->writeln([
            'Date to process: '.$stop_date->format('Y-m-d')
        ]);

        $withdrawalsFound = $this->processWithdrawals($statDate);
        $output->writeln([
            'Processed '.$withdrawalsFound.' new withdrawals',
        ]);

        $this->mSecTools->updateCoreSetting('job_withdraw_stats_date', date('Y-m-d', $statDate));
        $this->mSecTools->updateCoreSetting('job_withdraw_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Withdraw Statistics completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function processWithdrawals($date)
    {
        // load withdrawals ( only sent )
        $tWh = new Where();
        $tWh->like('date_sent', date('Y-m-d', $date).'%');
        $tWh->like('state', 'done');
        $tSel = new Select($this->mWthTbl->getTable());
        // attach referral user id
        $tSel->join(['u' => 'user'],'u.User_ID = faucet_withdraw.user_idfs', ['ref_user_idfs']);
        $tSel->where($tWh);
        $tSel->order('date_sent ASC');

        $withdrawalsToday = $this->mWthTbl->selectWith($tSel);

        // Count Entries
        $withdrawalsCount = $withdrawalsToday->count();

        $wthByUserId = [];
        $wthCoinsByUserId = [];
        $wthCoinsByRefId = [];
        foreach($withdrawalsToday as $account) {
            // count amount of withdrawals per user
            if(!array_key_exists('user-'.$account->user_idfs, $wthByUserId)) {
                $wthByUserId['user-'.$account->user_idfs] = 0;
            }
            $wthByUserId['user-'.$account->user_idfs]++;

            // count amount of coins per user
            if(!array_key_exists('user-'.$account->user_idfs, $wthCoinsByUserId)) {
                $wthCoinsByUserId['user-'.$account->user_idfs] = 0;
            }
            $wthCoinsByUserId['user-'.$account->user_idfs]+=$account->amount;

            // count amount of coins per referral user
            if($account->ref_user_idfs != 0) {
                if(!array_key_exists('user-'.$account->ref_user_idfs, $wthCoinsByRefId)) {
                    $wthCoinsByRefId['user-'.$account->ref_user_idfs] = 0;
                }
                $wthCoinsByRefId['user-'.$account->ref_user_idfs]+=($account->amount*0.1);
            }
        }

        $this->output->writeLn([
            '- Update User Withdrawal Stats for '.count($wthByUserId).' Users'
        ]);
        // update user stats (alltime)
        $key = 'user-wth-amount-total';
        $this->updateUserStatsByKey($key, $wthByUserId);

        $key = 'user-wth-coins-total';
        $this->updateUserStatsByKey($key, $wthCoinsByUserId);

        $key = 'user-ref-bonus-total';
        $this->updateUserStatsByKey($key, $wthCoinsByRefId);

        // update user stats (month)
        $key = 'user-wth-amount-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $wthByUserId);

        $key = 'user-wth-coins-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $wthCoinsByUserId);

        $key = 'user-ref-bonus-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $wthCoinsByRefId);

        return $withdrawalsCount;
    }

    private function updateUserStatsByKey($key, $wthByUserId) : void
    {
        foreach(array_keys($wthByUserId) as $userIdStr) {
            $userId = substr($userIdStr, strlen('user-'));
            if(is_numeric($userId) && $userId > 0 && !empty($userId)) {
                $now = date('Y-m-d H:i:s', time());

                $check = $this->mUserStatsTbl->select(['user_idfs' => $userId, 'stat_key' => $key]);
                if($check->count() == 0) {
                    // start of a new month
                    $this->mUserStatsTbl->insert([
                        'user_idfs' => $userId,
                        'stat_key' => $key,
                        'stat_data' => $wthByUserId[$userIdStr],
                        'date' => $now
                    ]);
                } else {
                    $currentVal = $check->current()->stat_data;
                    $this->mUserStatsTbl->update([
                        'stat_data' => $currentVal+$wthByUserId[$userIdStr],
                        'date' => $now
                    ],['user_idfs' => $userId, 'stat_key' => $key]);
                }
            }
        }
    }
}