<?php

namespace Batch\Command\GuildWeeklyCheck;

use Batch\Tools\BatchTools;
use Batch\Tools\SecurityTools;
use Batch\Tools\TransactionTools;
use Laminas\Db\TableGateway\TableGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GuildWeeklyCheck extends Command
{
    /**
     * User Table
     *
     * @var TableGateway $mUserTbl
     * @since 1.0.0
     */
    protected TableGateway $mUserTbl;

    /**
     * Guild Weekly Task Table
     *
     * @var TableGateway $mWeeklyTbl
     * @since 1.0.0
     */
    protected TableGateway $mWeeklyTbl;

    /**
     * Guild Weekly Status Table
     *
     * @var TableGateway $mWeeklyStatusTbl
     * @since 1.0.0
     */
    protected TableGateway $mWeeklyStatusTbl;

    /**
     * Guild Weekly Claim Table
     *
     * @var TableGateway $mWeeklyClaimTbl
     * @since 1.0.0
     */
    protected TableGateway $mWeeklyClaimTbl;

    /**
     * Guild Statistics Table
     *
     * @var TableGateway $mGuildStatsTbl
     * @since 1.0.0
     */
    protected TableGateway $mGuildStatsTbl;

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
     * Transaction Tools
     *
     * @var TransactionTools $mTxTools
     */
    protected TransactionTools $mTxTools;

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
        $this->mWeeklyTbl = new TableGateway('faucet_guild_weekly', $mapper);
        $this->mWeeklyStatusTbl = new TableGateway('faucet_guild_weekly_status', $mapper);
        $this->mWeeklyClaimTbl = new TableGateway('faucet_guild_weekly_claim', $mapper);
        $this->mGuildStatsTbl = new TableGateway('faucet_guild_statistic', $mapper);

        $this->mSecTools = new SecurityTools($mapper);
        $this->mBatchTools = new BatchTools($mapper);
        $this->mTxTools = new TransactionTools($mapper);

        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Make output avaiable for other class functions
        $this->output = $output;

        // log start
        $output->writeln([
            '=====================',
            'Checking Guild Weekly Tasks @ '.date('Y-m-d H:i:s', time()),
            '---------------------',
        ]);

        $statDate = time();

        $baseTasks = $this->loadGuildWeeklyTasks();
        $weekNo = $this->mBatchTools->getWeek($statDate);

        $output->writeln([
            'Check Status for '.count($baseTasks).' Tasks @ Week '.$weekNo
        ]);

        $this->processGuildWeeklyTasks($baseTasks, $weekNo, $statDate);

        $this->mSecTools->updateCoreSetting('job_guild_weeklys_lastrun', date('Y-m-d H:i:s', time()));

        return Command::SUCCESS;
    }

    /**
     * Load Guild Weekly Tasks
     * @return array
     */
    private function loadGuildWeeklyTasks(): array
    {
        $baseTasks = $this->mWeeklyTbl->select(['active' => 1, 'series' => 0]);
        $weeklyTasks = [];
        foreach($baseTasks as $task) {
            $weeklyTasks[] = $task;
        }
        return $weeklyTasks;
    }

    /**
     * Process Guild Weekly Tasks
     * @param $tasks
     * @param $weekYear
     * @param $date
     */
    private function processGuildWeeklyTasks($tasks, $weekYear, $date) {
        $weekInfo = explode('-', $weekYear);
        $weekNo = $weekInfo[0];
        $weekYear = $weekInfo[1];

        // Get current progress
        $progressByGuild = $this->getGuildsWeeklyProgress($weekNo, $weekYear);

        // Get completed Tasks
        $claimsByGuild = $this->getGuildsWeeklyClaims($weekNo, $weekYear);

        $completedTasksByGuildId = [];

        foreach($tasks as $task) {
            foreach($progressByGuild as $gKey => $guildProgress) {
                // check if guild has progress on this task
                if(array_key_exists($task->target_mode, $guildProgress)) {
                    // check if target is reached
                    if($guildProgress[$task->target_mode] >= $task->target) {
                        // check if task is already claimed
                        $taskClaimed = false;
                        if(array_key_exists($gKey, $claimsByGuild)) {
                            if(array_key_exists('weekly-'.$task->Weekly_ID, $claimsByGuild[$gKey])) {
                                $taskClaimed = true;
                            }
                        }
                        // claim task if not claimed yet
                        if(!$taskClaimed) {
                            $guildId = substr($gKey, strlen('guild-'));
                            if($guildId != '' && $guildId > 0) {
                                $this->claimGuildWeeklyTask($guildId, $task->Weekly_ID, $task->reward, $task->label, $weekNo, $weekYear);
                                if(!array_key_exists($gKey, $completedTasksByGuildId)) {
                                    $completedTasksByGuildId[$gKey] = 0;
                                }
                                $completedTasksByGuildId[$gKey]++;
                            }
                        }

                        // check if next level task is also complete (multi-level tasks)
                        $checkNextLvl = true;
                        $nextLevelBase = $task->Weekly_ID;
                        while($checkNextLvl) {
                            $nextLevel = $this->getNextLevelTask($nextLevelBase);
                            if($nextLevel) {
                                if($guildProgress[$nextLevel->target_mode] >= $nextLevel->target) {
                                    $taskClaimed = false;
                                    if(array_key_exists($gKey, $claimsByGuild)) {
                                        if(array_key_exists('weekly-'.$nextLevel->Weekly_ID, $claimsByGuild[$gKey])) {
                                            $taskClaimed = true;
                                        }
                                    }
                                    // claim task if not claimed yet
                                    if(!$taskClaimed) {
                                        $guildId = substr($gKey, strlen('guild-'));
                                        if($guildId != '' && $guildId > 0) {
                                            $this->claimGuildWeeklyTask($guildId, $nextLevel->Weekly_ID, $nextLevel->reward, $nextLevel->label, $weekNo, $weekYear);
                                            if(!array_key_exists($gKey, $completedTasksByGuildId)) {
                                                $completedTasksByGuildId[$gKey] = 0;
                                            }
                                            $completedTasksByGuildId[$gKey]++;
                                        }
                                    }
                                    // go one level up
                                    $nextLevelBase = $nextLevel->Weekly_ID;
                                } else {
                                    $checkNextLvl = false;
                                }
                            } else {
                                $checkNextLvl = false;
                            }
                        }
                    }
                }
            }
        }

        // Update Guild Weekly Task Stats for Contests
        $key = 'guild-weeklys-m-'.date('n-Y', $date);
        $this->updateGuildStatsByKey($key, $completedTasksByGuildId);
    }

    /**
     * Get Progress for Weekly Tasks for all Guilds
     * @param $week
     * @param $year
     * @return array
     */
    private function getGuildsWeeklyProgress($week, $year): array
    {
        // get progress of all guilds from specified week
        $progressAllGuilds = $this->mWeeklyStatusTbl->select(['week' => $week, 'year' => $year]);

        // sort all progress by guild and task name
        $progressByGuild = [];
        foreach($progressAllGuilds as $prog) {
            $gKey = 'guild-'.$prog->guild_idfs;
            if(!array_key_exists($gKey,$progressByGuild)) {
                $progressByGuild[$gKey] = [];
            }
            $progressByGuild[$gKey][$prog->weekly_key] = $prog->progress;
        }

        $this->output->writeln([
            '- Found Progress for '.count($progressByGuild).' Guilds',
        ]);

        return $progressByGuild;
    }

    /**
     * Get all completed Weekly Tasks for all Guilds
     * @param $week
     * @param $year
     * @return array
     */
    private function getGuildsWeeklyClaims($week, $year): array
    {
        // get all completed weekly tasks to prevent double completion
        $completedWeeklys = $this->mWeeklyClaimTbl->select(['week' => $week, 'year' => $year]);

        // sort all claims by guild and weekly task id
        $claimsByGuild = [];
        foreach($completedWeeklys as $weekly) {
            $gKey = 'guild-'.$weekly->guild_idfs;
            if(!array_key_exists($gKey, $claimsByGuild)) {
                $claimsByGuild[$gKey] = [];
            }
            $claimsByGuild[$gKey]['weekly-'.$weekly->weekly_idfs] = $weekly->id;
        }

        $this->output->writeln([
            '- Found Claims for '.count($claimsByGuild).' Guilds',
        ]);

        return $claimsByGuild;
    }

    /**
     * Claim Weekly Task for Guild and pay reward to guild bank
     * @param $guildId
     * @param $weeklyId
     * @param $reward
     * @param $taskName
     * @param $week
     * @param $year
     */
    private function claimGuildWeeklyTask($guildId, $weeklyId, $reward, $taskName, $week, $year): void
    {
        $this->output->writeln([
            '- Claim Task '.$weeklyId.' for Guild '.$guildId,
        ]);

        // Send Reward to Guild and get Tx Id
        $txId = $this->mTxTools->executeGuildTransaction($reward, false, $guildId, $weeklyId, 'weekly-task', 'Weekly Task '.$taskName.' complete', 1, true);

        // Mark Task as claimed
        $this->mWeeklyClaimTbl->insert([
            'reward' => $reward,
            'transaction_id' => $txId,
            'guild_idfs' => $guildId,
            'weekly_idfs' => $weeklyId,
            'week' => $week,
            'year' => $year,
            'date_claimed' => date('Y-m-d H:i:s', time())
        ]);
    }

    /**
     * Get Next Level Task for Task if there is one
     * @param $taskId
     * @return mixed
     */
    private function getNextLevelTask($taskId): mixed
    {
        $nextLevel = $this->mWeeklyTbl->select(['series' => $taskId, 'active' => 1]);
        if($nextLevel->count() > 0) {
            return $nextLevel->current();
        } else {
            return false;
        }
    }

    private function updateGuildStatsByKey($key, $tasksByGuildId) : void
    {
        $this->output->writeln([
            '- Update Guild Weekly Stats for '.count($tasksByGuildId).' Guilds',
        ]);

        foreach(array_keys($tasksByGuildId) as $guildIdStr) {
            $guildId = substr($guildIdStr, strlen('guild-'));
            if(is_numeric($guildId) && $guildId > 0 && !empty($guildId)) {
                $now = date('Y-m-d H:i:s', time());

                $check = $this->mGuildStatsTbl->select(['guild_idfs' => $guildId, 'stat_key' => $key]);
                if($check->count() == 0) {
                    // start of a new month
                    $this->mGuildStatsTbl->insert([
                        'guild_idfs' => $guildId,
                        'stat_key' => $key,
                        'data' => $tasksByGuildId[$guildIdStr],
                        'date' => $now
                    ]);
                } else {
                    $currentVal = $check->current()->data;
                    $this->mGuildStatsTbl->update([
                        'data' => $currentVal+$tasksByGuildId[$guildIdStr],
                        'date' => $now
                    ],['guild_idfs' => $guildId, 'stat_key' => $key]);
                }
            }
        }
    }
}