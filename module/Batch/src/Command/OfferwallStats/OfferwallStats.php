<?php

namespace Batch\Command\OfferwallStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OfferwallStats extends Command {
    /**
     * Offerwall User Table
     *
     * @var TableGateway $mOffersDoneTbl
     * @since 1.0.0
     */
    protected TableGateway $mOffersDoneTbl;

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
        $this->mOffersDoneTbl = new TableGateway('offerwall_user', $mapper);
        $this->mUserStatsTbl = new TableGateway('user_faucet_stat', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mBatchTools = new BatchTools();
        
        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // log start
        $output->writeln([
            '=====================',
            'Generating Offerwall Statistics @ ' . date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $lastDate = $this->mSecTools->getCoreSetting('job_offerwall_stats_date');
        $lastrun = $this->mSecTools->getCoreSetting('job_offerwall_stats_lastrun');

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

        $offersDone = $this->generateOfferwallStats($statDate);
        $output->writeln([
            '- Processed '.$offersDone.' offers',
        ]);

        $this->mSecTools->updateCoreSetting('job_offerwall_stats_date', date('Y-m-d', $statDate));
        $this->mSecTools->updateCoreSetting('job_offerwall_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Offerwall Statistics completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function generateOfferwallStats($date) {
        // load shares
        $tWh = new Where();
        $tWh->like('date_completed', date('Y-m-d', $date).'%');
        $tSel = new Select($this->mOffersDoneTbl->getTable());
        $tSel->where($tWh);
        $tSel->order('date_completed ASC');

        $offersToday = $this->mOffersDoneTbl->selectWith($tSel);

        // Count Entries
        $offersCount = $offersToday->count();

        // get offers done by user
        $offersBigByUserId = [];
        $offersMedByUserId = [];
        $offersSmallByUserId = [];
        $offersTinyByUserId = [];
        foreach($offersToday as $offer) {
            // big offers have 5000 coins reward or more
            if($offer->amount >= 50000) {
                if(!array_key_exists('user-'.$offer->user_idfs, $offersBigByUserId)) {
                    $offersBigByUserId['user-'.$offer->user_idfs] = 0;
                }
                $offersBigByUserId['user-'.$offer->user_idfs]++;
            } elseif($offer->amount >= 5000) {
                if(!array_key_exists('user-'.$offer->user_idfs, $offersMedByUserId)) {
                    $offersMedByUserId['user-'.$offer->user_idfs] = 0;
                }
                $offersMedByUserId['user-'.$offer->user_idfs]++;
            } elseif($offer->amount >= 1000) {
                if(!array_key_exists('user-'.$offer->user_idfs, $offersSmallByUserId)) {
                    $offersSmallByUserId['user-'.$offer->user_idfs] = 0;
                }
                $offersSmallByUserId['user-'.$offer->user_idfs]++;
            } else {
                if(!array_key_exists('user-'.$offer->user_idfs, $offersTinyByUserId)) {
                    $offersTinyByUserId['user-'.$offer->user_idfs] = 0;
                }
                $offersTinyByUserId['user-'.$offer->user_idfs]++;
            }
        }

        // update user stats (alltime)
        $key = 'user-offertiny-total';
        $this->updateUserStatsByKey($key, $offersTinyByUserId);

        $key = 'user-offersmall-total';
        $this->updateUserStatsByKey($key, $offersSmallByUserId);

        $key = 'user-offermed-total';
        $this->updateUserStatsByKey($key, $offersMedByUserId);

        $key = 'user-offerbig-total';
        $this->updateUserStatsByKey($key, $offersBigByUserId);

        // update user stats (month)
        $key = 'user-offertiny-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $offersTinyByUserId);

        $key = 'user-offersmall-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $offersSmallByUserId);

        $key = 'user-offermed-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $offersMedByUserId);

        $key = 'user-offerbig-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $offersBigByUserId);

        // update user stats (week)
        $key = 'user-offertiny-w-'.$this->mBatchTools->getWeek($date);
        $this->updateUserStatsByKey($key, $offersTinyByUserId);

        $key = 'user-offersmall-w-'.$this->mBatchTools->getWeek($date);
        $this->updateUserStatsByKey($key, $offersSmallByUserId);

        $key = 'user-offermed-w-'.$this->mBatchTools->getWeek($date);
        $this->updateUserStatsByKey($key, $offersMedByUserId);

        $key = 'user-offerbig-w-'.$this->mBatchTools->getWeek($date);
        $this->updateUserStatsByKey($key, $offersBigByUserId);

        return $offersCount;
    }

    private function updateUserStatsByKey($key, $offersByUserId) : void
    {
        foreach(array_keys($offersByUserId) as $userIdStr) {
            $userId = substr($userIdStr, strlen('user-'));
            if(is_numeric($userId) && $userId > 0 && !empty($userId)) {
                $now = date('Y-m-d H:i:s', time());

                $check = $this->mUserStatsTbl->select(['user_idfs' => $userId, 'stat_key' => $key]);
                if($check->count() == 0) {
                    // start of a new month
                    $this->mUserStatsTbl->insert([
                        'user_idfs' => $userId,
                        'stat_key' => $key,
                        'stat_data' => $offersByUserId[$userIdStr],
                        'date' => $now
                    ]);
                } else {
                    $currentVal = $check->current()->stat_data;
                    $this->mUserStatsTbl->update([
                        'stat_data' => $currentVal+$offersByUserId[$userIdStr],
                        'date' => $now
                    ],['user_idfs' => $userId, 'stat_key' => $key]);
                }
            }
        }
    }
}