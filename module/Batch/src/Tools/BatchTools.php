<?php

namespace Batch\Tools;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Sql\Where;

class BatchTools {
    /**
     * Guild Weekly Task Status Table
     *
     * @var TableGateway $mGuildTaskStatusTbl
     * @since 1.0.0
     */
    protected TableGateway $mGuildTaskStatusTbl;

    /**
     * Guild User Table
     *
     * @var TableGateway $mGuildUserTbl
     * @since 1.0.0
     */
    protected TableGateway $mGuildUserTbl;

    private TableGateway $mAchievTbl;

    private TableGateway $mAchievUserTbl;

    private TableGateway $mStatsTbl;

    private TableGateway $mUserStatsTbl;

    /**
     * Constructor
     *
     * BatchTools constructor.
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        // Guild Weekly Task Addon
        $this->mGuildUserTbl = new TableGateway('faucet_guild_user', $mapper);
        $this->mGuildTaskStatusTbl = new TableGateway('faucet_guild_weekly_status', $mapper);
        $this->mAchievTbl = new TableGateway('faucet_achievement', $mapper);
        $this->mAchievUserTbl = new TableGateway('faucet_achievement_user', $mapper);
        $this->mStatsTbl = new TableGateway('core_statistic', $mapper);
        $this->mUserStatsTbl = new TableGateway('user_faucet_stat', $mapper);
    }

    /**
     * Get Week for Date
     * (Weeks are from wed-tue)
     *
     * @param $date
     * @return string
     */
    public function getWeek($date) : string
    {
        // update user stats (week)
        $week = date('W-Y', $date);
        $weekDay = date('w', $date);
        $weekCheck = date('W', $date);
        $monthCheck = date('n', $date);
        // fix php bug - wrong iso week for first week of the year
        //$dev = 1;
        $yearFixApplied = false;
        if($monthCheck == 1 && ($weekCheck > 10 || $weekCheck == 1)) {
            // last week of last year is extended to tuesday as our week begins at wednesday
            if($weekDay != 3 && $weekDay != 4 && $weekDay != 5) {
                //$dev = 5;
                try {
                    $stop_date = new \DateTime(date('Y-m-d', $date));
                    $stop_date->modify('-5 days');
                    $statDate = strtotime($stop_date->format('Y-m-d H:i:s'));

                    $week = date('W-Y', $statDate);
                    $yearFixApplied = true;
                } catch(\Exception $e) {

                }
            }
        }
        // dont mess with fixed date from year change
        if(!$yearFixApplied) {
            //$dev = 3;
            // monday and tuesday are counted to last weeks iso week
            if($weekDay == 1 || $weekDay == 2) {
                //$dev = 4;
                $week = ($weekCheck-1).'-'.date('Y', $date);
            }
        }

        return $week;
    }

    /**
     * Load All Users in an active Guild
     * @return array
     */
    public function loadUsersInGuilds(): array
    {
        $guWh = new Where();
        $guWh->notLike('date_joined', '0000-00-00 00:00:00');
        $guildUsers = $this->mGuildUserTbl->select($guWh);
        $guildByUserId = [];
        foreach ($guildUsers as $gu) {
            $guildByUserId['user-' . $gu->user_idfs] = $gu->guild_idfs;
        }

        return $guildByUserId;
    }

    /**
     * Update Guild Weekly Stats
     * @param $stats
     * @param $week
     * @param $statKey
     */
    public function updateGuildWeeklyStats($stats, $week, $statKey, $output) : bool
    {

        $weekInfo = explode('-', $week);
        $weekNo = $weekInfo[0];
        $yearNo = $weekInfo[1];
        $guildCount = 0;
        if(count($stats) == 0) {
            $output->writeln([
                '-- No updates for Guild Weekly Tasks ('.$statKey.') for Week '.$week,
            ]);
            return true;
        }
        $output->writeln([
            '-- Update Guild Weekly Stats ('.$statKey.') for Week '.$week,
        ]);
        foreach(array_keys($stats) as $guildKey) {
            $guildId = substr($guildKey, strlen('guild-'));
            if(is_numeric($guildId)) {
                $guildCount++;
                $check = $this->mGuildTaskStatusTbl->select([
                    'weekly_key' => $statKey,
                    'guild_idfs' => $guildId,
                    'week' => $weekNo,
                    'year' => $yearNo
                ]);
                if($check->count() == 0) {
                    $this->mGuildTaskStatusTbl->insert([
                        'weekly_key' => $statKey,
                        'guild_idfs' => $guildId,
                        'week' => $weekNo,
                        'year' => $yearNo,
                        'progress' => $stats[$guildKey]
                    ]);
                } else {
                    $checkInfo = $check->current();
                    $statId = $checkInfo->id;
                    $currentVal = $checkInfo->progress;
                    $this->mGuildTaskStatusTbl->update([
                        'progress' => $currentVal+$stats[$guildKey]
                    ],['id' => $statId]);
                }
            }
        }

        $output->writeln([
            '-- Updated Stats for '.$guildCount.' Guilds',
        ]);

        return true;
    }

    /**
     * Complete Achievement for User if goal is reached
     *
     * @param $userId
     * @param $achievType
     * @param $value
     * @return bool
     */
    public function completeUserAchievement($userId, $achievType, $value): bool
    {
        if(!is_numeric($userId) || $userId <= 0) {
            return false;
        }
        $achievement = $this->mAchievTbl->select(['type' => $achievType, 'series' => 0]);
        if($achievement->count() > 0) {
            $achievement = $achievement->current();

            if($value >= $achievement->goal) {
                // check if user has already claimed achievement
                $check = $this->mAchievUserTbl->select([
                    'user_idfs' => $userId,
                    'achievement_idfs' => $achievement->Achievement_ID]);
                if($check->count() == 0) {
                    $this->mAchievUserTbl->insert([
                        'user_idfs' => $userId,
                        'achievement_idfs' => $achievement->Achievement_ID,
                        'date' => date('Y-m-d H:i:s', time())
                    ]);
                }

                $checkNextLevel = true;
                $nextLevelId = $achievement->Achievement_ID;
                while($checkNextLevel) {
                    $nextLevelAchiev = $this->mAchievTbl->select(['series' => $nextLevelId]);
                    if($nextLevelAchiev->count() > 0) {
                        $nextLevelAchiev = $nextLevelAchiev->current();
                        $nextLevelId = $nextLevelAchiev->Achievement_ID;

                        if($value >= $nextLevelAchiev->goal) {
                            // check if user has already claimed achievement
                            $check = $this->mAchievUserTbl->select([
                                'user_idfs' => $userId,
                                'achievement_idfs' => $nextLevelAchiev->Achievement_ID]);
                            if($check->count() == 0) {
                                $this->mAchievUserTbl->insert([
                                    'user_idfs' => $userId,
                                    'achievement_idfs' => $nextLevelAchiev->Achievement_ID,
                                    'date' => date('Y-m-d H:i:s', time())
                                ]);
                            }
                        } else {
                            $checkNextLevel = false;
                        }
                    } else {
                        $checkNextLevel = false;
                    }
                }

                return true;
            }
        }

        return true;
    }

    public function updateCoreStatsByKey($key, $newVal, $replace = false) : void
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
            if(!$replace) {
                $currentVal = $check->current()->data;
                $this->mStatsTbl->update([
                    'data' => $currentVal+$newVal,
                    'date' => $now
                ],['stats_key' => $key]);
            } else {
                $this->mStatsTbl->update([
                    'data' => $newVal,
                    'date' => $now
                ],['stats_key' => $key]);
            }
        }
    }

    /**
     * Update User Statistics by Key
     *
     * @param $key
     * @param $valuesByUserId
     */
    public function updateUserStatsByKey($key, $valuesByUserId, $checkAchievementKey = false) : void
    {
        foreach(array_keys($valuesByUserId) as $userIdStr) {
            $userId = substr($userIdStr, strlen('user-'));
            if(is_numeric($userId) && $userId > 0 && !empty($userId)) {
                $now = date('Y-m-d H:i:s', time());

                $check = $this->mUserStatsTbl->select(['user_idfs' => $userId, 'stat_key' => $key]);
                if($check->count() == 0) {
                    // start of a new month
                    $this->mUserStatsTbl->insert([
                        'user_idfs' => $userId,
                        'stat_key' => $key,
                        'stat_data' => $valuesByUserId[$userIdStr],
                        'date' => $now
                    ]);
                } else {
                    $currentVal = $check->current()->stat_data;
                    $this->mUserStatsTbl->update([
                        'stat_data' => $currentVal+$valuesByUserId[$userIdStr],
                        'date' => $now
                    ],['user_idfs' => $userId, 'stat_key' => $key]);
                }
                if($checkAchievementKey) {
                    $this->completeUserAchievement($userId, $checkAchievementKey, $valuesByUserId[$userIdStr]);
                }
            }
        }
    }
}