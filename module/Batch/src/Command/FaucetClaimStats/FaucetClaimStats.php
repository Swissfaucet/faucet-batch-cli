<?php

namespace Batch\Command\FaucetClaimStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FaucetClaimStats extends Command {
    /**
     * Dailytask User Table
     *
     * @var TableGateway $mClaimTbl
     * @since 1.0.0
     */
    protected TableGateway $mClaimTbl;

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

    /**
     * Constructor
     *
     * UserResource constructor.
     * @param $mapper
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        $this->mClaimTbl = new TableGateway('faucet_claim', $mapper);
        $this->mUserStatsTbl = new TableGateway('user_faucet_stat', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mBatchTools = new BatchTools($mapper);

        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // log start
        $output->writeln([
            '=====================',
            'Generating Faucet Claim Statistics @ ' . date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastDate = $this->mSecTools->getCoreSetting('job_faucet_claims_date');
        $lastrun = $this->mSecTools->getCoreSetting('job_faucet_claims_lastrun');

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

        $tasksDone = $this->generateFaucetClaimStats($statDate);
        $output->writeln([
            '- Processed '.$tasksDone.' faucet claims',
        ]);

        $this->mSecTools->updateCoreSetting('job_faucet_claims_date', date('Y-m-d', $statDate));
        $this->mSecTools->updateCoreSetting('job_faucet_claims_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Faucet Claim Statistics completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function generateFaucetClaimStats($date) {
        // load shares
        $tWh = new Where();
        $tWh->like('date', date('Y-m-d', $date).'%');
        $tSel = new Select($this->mClaimTbl->getTable());
        $tSel->where($tWh);
        //  $tSel->order('date ASC');

        $claimsToday = $this->mClaimTbl->selectWith($tSel);

        // Count Entries
        $claimsCount = 0;

        // get claims done by user
        $claimsByUserId = [];
        foreach($claimsToday as $claim) {
            $claimsCount++;
            if(!array_key_exists('user-'.$claim->user_idfs, $claimsByUserId)) {
                $claimsByUserId['user-'.$claim->user_idfs] = 0;
            }
            $claimsByUserId['user-'.$claim->user_idfs]++;
        }

        // update user stats (alltime)
        $key = 'user-claims-total';
        $this->updateUserStatsByKey($key, $claimsByUserId);

        // update user stats (month)
        $key = 'user-claims-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $claimsByUserId);

        // update user stats (week)
        $key = 'user-claims-w-'.$this->mBatchTools->getWeek($date);
        $this->updateUserStatsByKey($key, $claimsByUserId);

        $key = 'faucet-claims-total';
        $this->mBatchTools->updateCoreStatsByKey($key, $claimsCount);

        return $claimsCount;
    }

    private function updateUserStatsByKey($key, $tasksByUserId) : void
    {
        foreach(array_keys($tasksByUserId) as $userIdStr) {
            $userId = substr($userIdStr, strlen('user-'));
            if(is_numeric($userId) && $userId > 0 && !empty($userId)) {
                $now = date('Y-m-d H:i:s', time());

                $check = $this->mUserStatsTbl->select(['user_idfs' => $userId, 'stat_key' => $key]);
                if($check->count() == 0) {
                    // start of a new month
                    $this->mUserStatsTbl->insert([
                        'user_idfs' => $userId,
                        'stat_key' => $key,
                        'stat_data' => $tasksByUserId[$userIdStr],
                        'date' => $now
                    ]);
                } else {
                    $currentVal = $check->current()->stat_data;
                    $this->mUserStatsTbl->update([
                        'stat_data' => $currentVal+$tasksByUserId[$userIdStr],
                        'date' => $now
                    ],['user_idfs' => $userId, 'stat_key' => $key]);
                }
            }
        }
    }
}