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
        $this->mBatchTools = new BatchTools($mapper);
        
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


        $offersDone = $this->generateOfferwallStats(time(), $output);
        $output->writeln([
            '- Processed '.$offersDone.' offers',
        ]);

        $this->mSecTools->updateCoreSetting('job_offerwall_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Offerwall Statistics completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function generateOfferwallStats($date, $output) {
        // load shares
        $tWh = new Where();
        //$tWh->like('date_completed', date('Y-m-d', $date).'%');
        $tWh->equalTo('stats_processed', 0);
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
        $coinsEarnedByUserId = [];
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
            if(!array_key_exists('user-'.$offer->user_idfs, $coinsEarnedByUserId)) {
                $coinsEarnedByUserId['user-'.$offer->user_idfs] = 0;
            }
            $coinsEarnedByUserId['user-'.$offer->user_idfs]+=$offer->amount;
            $this->mOffersDoneTbl->update(['stats_processed' => 1],['user_idfs' => $offer->user_idfs, 'offerwall_idfs' => $offer->offerwall_idfs, 'date_completed' => $offer->date_completed]);
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

        $key = 'user-offerearned-total';
        $this->updateUserStatsByKey($key, $coinsEarnedByUserId);

        // update user stats (month)
        $key = 'user-offertiny-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $offersTinyByUserId);

        $key = 'user-offersmall-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $offersSmallByUserId);

        $key = 'user-offermed-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $offersMedByUserId);

        $key = 'user-offerbig-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $offersBigByUserId);

        $key = 'user-offerearned-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $coinsEarnedByUserId);

        // update user stats (week)
        $weekNo = $this->mBatchTools->getWeek($date);
        $key = 'user-offertiny-w-'.$weekNo;
        $this->updateUserStatsByKey($key, $offersTinyByUserId, $weekNo, $output, 'oftiny');

        $key = 'user-offersmall-w-'.$weekNo;
        $this->updateUserStatsByKey($key, $offersSmallByUserId, $weekNo, $output, 'ofsmall');

        $key = 'user-offermed-w-'.$weekNo;
        $this->updateUserStatsByKey($key, $offersMedByUserId, $weekNo, $output, 'ofmed');

        $key = 'user-offerbig-w-'.$weekNo;
        $this->updateUserStatsByKey($key, $offersBigByUserId, $weekNo, $output, 'ofbig');

        $key = 'offerwalls-total-offers';
        $this->mBatchTools->updateCoreStatsByKey($key, $offersCount);

        return $offersCount;
    }

    private function updateUserStatsByKey($key, $offersByUserId, $week = false, $output = false, $gStatsKey = false) : void
    {
        if($week) {
            $guildByUserId = $this->mBatchTools->loadUsersInGuilds();
        }

        $tasksByGuild = [];
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

                if($week) {
                    // Add Weekly Value to Guild
                    if(array_key_exists('user-'.$userId,$guildByUserId)) {
                        $guildId = $guildByUserId['user-'.$userId];
                        if(!array_key_exists('guild-'.$guildId, $tasksByGuild)) {
                            $tasksByGuild['guild-'.$guildId] = 0;
                        }
                        $tasksByGuild['guild-'.$guildId]+=$offersByUserId[$userIdStr];
                    }
                }
            }
        }

        if($week) {
            $wkUpdate = $this->mBatchTools->updateGuildWeeklyStats($tasksByGuild, $week, $gStatsKey, $output);
        }
    }
}