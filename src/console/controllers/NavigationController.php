<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use johnhenry\bundlebuilder\migrations\m260925_020000_navigation_4_node_types;
use Throwable;
use yii\console\ExitCode;

/**
 * Moves bundle menu items and menu settings onto the Navigation 4 bundle node type.
 *
 * Run this after upgrading Navigation from version 3 to 4 on a site already on
 * Bundle Builder 1.2 or later. Bundle menu items made under Navigation 3 have
 * no node type in Navigation 4 until they're moved, and lose their links.
 * It's safe to run more than once, and does nothing under Navigation 3.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class NavigationController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Moves bundle menu items and menu settings onto the Navigation 4 bundle node type.
     *
     * @return int The exit code.
     * @throws Throwable if the conversion fails.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function actionUpgradeNodes(): int
    {
        if (!class_exists('verbb\\navigation\\services\\NodeTypes')) {
            $this->stdout("Navigation 4 isn't installed, so there's nothing to move.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $migration = new m260925_020000_navigation_4_node_types();
        $migration->compact = true;
        $migration->up();

        $this->stdout("Bundle menu items are on the Navigation 4 bundle node type.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
