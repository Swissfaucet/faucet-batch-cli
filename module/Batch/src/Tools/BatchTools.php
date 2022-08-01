<?php

namespace Batch\Tools;

class BatchTools {
    /**
     * Constructor
     *
     * BatchTools constructor.
     * @since 1.0.0
     */
    public function __construct()
    {

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
}