<?php

namespace Batch\Tools;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Session\Container;

class SecurityTools {
    /**
     * Settings Table
     *
     * @var TableGateway $mSettingsTbl
     * @since 1.0.0
     */
    protected TableGateway $mSettingsTbl;

    /**
     * Constructor
     *
     * SecurityTools constructor.
     * @param $mapper
     * @since 1.0.0
     */
    public function __construct($mapper)
    {
        $this->mSettingsTbl = new TableGateway('settings', $mapper);
    }

    /**
     * Load Core Setting from Database
     *
     * @param $key
     * @return bool|string
     */
    public function getCoreSetting($key): bool|string
    {
        $settingFound = $this->mSettingsTbl->select(['settings_key' => $key]);
        if (count($settingFound) == 0) {
            return false;
        } else {
            return $settingFound->current()->settings_value;
        }
    }

    /**
     * Update Core Setting from Database
     * @param $key
     * @param $value
     * @return bool
     */
    public function updateCoreSetting($key, $value): bool
    {
        $settingFound = $this->mSettingsTbl->select(['settings_key' => $key]);
        if (count($settingFound) == 0) {
            return false;
        } else {
            $this->mSettingsTbl->update([
                'settings_value' => $value
            ], ['settings_key' => $key]);
            return true;
        }
    }
}