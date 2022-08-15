<?php

namespace Batch\Command\ShortlinkStats;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShortlinkStats extends Command {
    /**
     * Offerwall User Table
     *
     * @var TableGateway $mLinksDoneTbl
     * @since 1.0.0
     */
    protected TableGateway $mLinksDoneTbl;

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

    private TableGateway $mLinkStatsTbl;

    private OutputInterface $output;

    private TableGateway $mLinkTbl;

    /**
     * Constructor
     *
     * UserResource constructor.
     * @param $mapper
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        $this->mLinksDoneTbl = new TableGateway('shortlink_link_user', $mapper);
        $this->mLinkStatsTbl = new TableGateway('shortlink_stats', $mapper);
        $this->mLinkTbl = new TableGateway('shortlink', $mapper);
        $this->mUserStatsTbl = new TableGateway('user_faucet_stat', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mBatchTools = new BatchTools($mapper);

        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Make output avaiable for class
        $this->output = $output;

        // log start
        $output->writeln([
            '=====================',
            'Generating Shortlink Statistics @ ' . date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $linksDone = $this->generateShortlinkStats(time(), $output);
        $output->writeln([
            '- Processed '.$linksDone.' shortlinks',
        ]);

        $this->mSecTools->updateCoreSetting('job_shortlink_stats_lastrun', date('Y-m-d H:i:s', time()));

        $output->writeln([
            '-- Shortlink Statistics completed successfully',
            '=====================',
        ]);

        return Command::SUCCESS;
    }

    private function generateShortlinkStats($date, $output) {
        // load shares
        $tWh = new Where();
        $tWh->equalTo('stats_processed', 0);
        $tSel = new Select($this->mLinksDoneTbl->getTable());
        $tSel->where($tWh);
        $tSel->order('date_started ASC');

        $linksToday = $this->mLinksDoneTbl->selectWith($tSel);

        // Count Entries
        $linksCount = $linksToday->count();

        // get offers done by user
        $linksByUserId = [];
        $startedByProviderId = [];
        $completedByProviderId = [];
        $totalLinksCompleted = 0;
        foreach($linksToday as $link) {
            if(!array_key_exists('user-'.$link->user_idfs, $linksByUserId)) {
                $linksByUserId['user-'.$link->user_idfs] = 0;
            }
            if(!array_key_exists('sh-'.$link->shortlink_idfs, $startedByProviderId)) {
                $startedByProviderId['sh-'.$link->shortlink_idfs] = 0;
            }
            $startedByProviderId['sh-'.$link->shortlink_idfs]++;
            if(!array_key_exists('sh-'.$link->shortlink_idfs, $completedByProviderId)) {
                $completedByProviderId['sh-'.$link->shortlink_idfs] = 0;
            }
            // only count completed links
            if(strlen($link->date_completed) > 5 && $link->date_completed != '0000-00-00 00:00:00') {
                $totalLinksCompleted++;
                $completedByProviderId['sh-'.$link->shortlink_idfs]++;
                $linksByUserId['user-'.$link->user_idfs]++;
            }
            $this->mLinksDoneTbl->update(['stats_processed' => 1], ['id' => $link->id]);
        }

        // update user stats (alltime)
        $key = 'user-shortlink-total';
        $this->updateUserStatsByKey($key, $linksByUserId);

        // update user stats (month)
        $key = 'user-shortlink-m-'.date('n-Y', $date);
        $this->updateUserStatsByKey($key, $linksByUserId);

        // update user stats (week)
        $weekNo = $this->mBatchTools->getWeek($date);
        $key = 'user-shortlink-w-'.$weekNo;
        $this->updateUserStatsByKey($key, $linksByUserId, $weekNo, $output, 'shortlink');

        // update completion rates
        $this->updateShortlinkStats($startedByProviderId, $completedByProviderId);

        // update shortlink difficutly
        $this->updateShortlinkDifficulty();

        // update total shortlinks
        $key = 'shortlinks-total-links';
        $this->mBatchTools->updateCoreStatsByKey($key, $totalLinksCompleted);

        return $linksCount;
    }

    private function updateUserStatsByKey($key, $linksByUserId, $week = false, $output = false, $gStatsKey = false) : void
    {
        if($week) {
            $guildByUserId = $this->mBatchTools->loadUsersInGuilds();
        }

        $tasksByGuild = [];
        foreach(array_keys($linksByUserId) as $userIdStr) {
            $userId = substr($userIdStr, strlen('user-'));
            if(is_numeric($userId) && $userId > 0 && !empty($userId)) {
                $now = date('Y-m-d H:i:s', time());

                $check = $this->mUserStatsTbl->select(['user_idfs' => $userId, 'stat_key' => $key]);
                if($check->count() == 0) {
                    // start of a new month
                    $this->mUserStatsTbl->insert([
                        'user_idfs' => $userId,
                        'stat_key' => $key,
                        'stat_data' => $linksByUserId[$userIdStr],
                        'date' => $now
                    ]);
                } else {
                    $currentVal = $check->current()->stat_data;
                    $this->mUserStatsTbl->update([
                        'stat_data' => $currentVal+$linksByUserId[$userIdStr],
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
                        $tasksByGuild['guild-'.$guildId]+=$linksByUserId[$userIdStr];
                    }
                }
            }
        }

        if($week) {
            $wkUpdate = $this->mBatchTools->updateGuildWeeklyStats($tasksByGuild, $week, $gStatsKey, $output);
        }
    }

    /**
     * Update Shortlink Completion Rates
     * @param $started
     * @param $completed
     */
    private function updateShortlinkStats($started, $completed) : void
    {
        $this->output->writeln([
            '-- Update Shortlink Completion Rates',
        ]);
        foreach($started as $shKey => $shStats) {
            $startCount = $shStats;
            $completCount = 0;
            $shortlinkId = substr($shKey, strlen('sh-'));
            if(array_key_exists($shKey, $completed)) {
                $completCount = $completed[$shKey];
            }
            if($shortlinkId > 0 && is_numeric($shortlinkId) && !empty($shortlinkId)) {
                $check = $this->mLinkStatsTbl->select(['shortlink_idfs' => $shortlinkId]);
                if($check->count() == 0) {
                    $this->mLinkStatsTbl->insert([
                        'shortlink_idfs' => $shortlinkId,
                        'started' => $startCount,
                        'completed' => $completCount,
                        'date' => date('Y-m-d H:i:s', time())
                    ]);
                } else {
                    $oldStats = $check->current();
                    $this->mLinkStatsTbl->update([
                        'started' => $oldStats->started + $startCount,
                        'completed' => $oldStats->completed + $completCount,
                        'date' => date('Y-m-d H:i:s', time())
                    ],['shortlink_idfs' => $shortlinkId]);
                }
            }
        }
        $this->output->writeln([
            '-- Updated Completion Rates for '.count($started).' links',
        ]);
    }

    /**
     * Update Shortlink Difficulty based on completion rates
     */
    private function updateShortlinkDifficulty() : void
    {
        $this->output->writeln([
            '-- Update Shortlink Difficulty',
        ]);

        $shStats = $this->mLinkStatsTbl->select();

        foreach($shStats as $sh) {
            $percent = 0;
            if($sh->started > 0 && $sh->completed > 0) {
                $percent = round((100/(($sh->started)/$sh->completed)));
            }

            $difficulty = 'easy';
            if($percent <= 90 & $percent >= 80) {
                $difficulty = 'medium';
            }
            if($percent < 80 & $percent >= 70) {
                $difficulty = 'hard';
            }
            if($percent < 70) {
                $difficulty = 'ultra';
            }

            $this->mLinkTbl->update([
                'difficulty' => $difficulty
            ],[
                'Shortlink_ID' => $sh->shortlink_idfs
            ]);
        }
    }
}