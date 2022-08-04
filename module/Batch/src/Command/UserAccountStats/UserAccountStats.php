<?php

namespace Batch\Command\UserAccountStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UserAccountStats extends Command
{
    /**
     * User Table
     *
     * @var TableGateway $mUserTbl
     * @since 1.0.0
     */
    protected TableGateway $mUserTbl;

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
        $this->mUserTbl = new TableGateway('user', $mapper);
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
            'Checking for new accounts @ '.date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastDate = $this->mSecTools->getCoreSetting('job_account_stats_date');
        $lastrun = $this->mSecTools->getCoreSetting('job_account_stats_lastrun');

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

        $accountsFound = $this->processNewUserAccounts($statDate);
        $output->writeln([
            'Processed '.$accountsFound.' new users',
        ]);

        $this->mSecTools->updateCoreSetting('job_account_stats_date', date('Y-m-d', $statDate));
        $this->mSecTools->updateCoreSetting('job_account_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Account Statistics completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function processNewUserAccounts($date)
    {
        // load shares
        $tWh = new Where();
        $tWh->like('created_date', date('Y-m-d', $date).'%');
        $tSel = new Select($this->mUserTbl->getTable());
        $tSel->where($tWh);
        $tSel->order('created_date ASC');

        $accountsToday = $this->mUserTbl->selectWith($tSel);

        // Count Entries
        $accountsCount = $accountsToday->count();

        $refsByUserId = [];
        foreach($accountsToday as $account) {
            if($account->ref_user_idfs != 0) {
                if(!array_key_exists('user-'.$account->ref_user_idfs, $refsByUserId)) {
                    $refsByUserId['user-'.$account->ref_user_idfs] = 0;
                }
                $refsByUserId['user-'.$account->ref_user_idfs]++;
            }
        }

        $this->output->writeLn([
            '- Update User Referral Stats for '.count($refsByUserId).' Users'
        ]);
        // update user stats (alltime)
        $key = 'user-referral-total';
        $this->updateUserStatsByKey($key, $refsByUserId);

        // update user stats (month)
        $key = 'user-referral-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $refsByUserId);

        $this->output->writeLn([
            '- Update Core Stats'
        ]);
        // update core stats (total)
        $key = 'users-total';
        $this->updateStatsByKey($key, $accountsCount);

        // update core stats (month)
        $key = 'users-total-m-'.date('n-Y', $date);
        $this->updateStatsByKey($key, $accountsCount);

        // update core stats (day)
        $key = 'users-created-d-'.date('Y-m-d', $date);
        $this->updateStatsByKey($key, $accountsCount);

        return $accountsCount;
    }

    private function updateUserStatsByKey($key, $refsByUserId) : void
    {
        foreach(array_keys($refsByUserId) as $userIdStr) {
            $userId = substr($userIdStr, strlen('user-'));
            if(is_numeric($userId) && $userId > 0 && !empty($userId)) {
                $now = date('Y-m-d H:i:s', time());

                $check = $this->mUserStatsTbl->select(['user_idfs' => $userId, 'stat_key' => $key]);
                if($check->count() == 0) {
                    // start of a new month
                    $this->mUserStatsTbl->insert([
                        'user_idfs' => $userId,
                        'stat_key' => $key,
                        'stat_data' => $refsByUserId[$userIdStr],
                        'date' => $now
                    ]);
                } else {
                    $currentVal = $check->current()->stat_data;
                    $this->mUserStatsTbl->update([
                        'stat_data' => $currentVal+$refsByUserId[$userIdStr],
                        'date' => $now
                    ],['user_idfs' => $userId, 'stat_key' => $key]);
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
            $currentVal = $check->current()->data;
            $this->mStatsTbl->update([
                'data' => $currentVal+$newVal,
                'date' => $now
            ],['stats_key' => $key]);
        }
    }
}