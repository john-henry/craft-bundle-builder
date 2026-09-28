<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Json;
use yii\base\Exception;

/**
 * Moves bundle menu items onto the Navigation 4 bundle node type.
 *
 * Navigation 3 stored a bundle node's type, and each menu's bundle node type
 * settings, under the bundle element class. Navigation 4 keys both by node
 * type class, and its own upgrade only converts its built-in types, so bundle
 * nodes are left without a node type and lose their links.
 *
 * Does nothing unless Navigation 4 is installed, and is safe to run again:
 * the `bundle-builder/navigation/upgrade-nodes` command reruns it for sites
 * that move to Navigation 4 after this update.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260925_020000_navigation_4_node_types extends Migration
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The type Navigation 3 stored for bundle nodes.
     */
    private const ELEMENT_TYPE = 'johnhenry\\bundlebuilder\\elements\\Bundle';

    /**
     * @var string The Navigation 4 bundle node type.
     */
    private const NODE_TYPE = 'johnhenry\\bundlebuilder\\nodetypes\\Bundle';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration applied successfully.
     * @throws Exception if project config can't be updated.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function safeUp(): bool
    {
        if (!class_exists('verbb\\navigation\\services\\NodeTypes')) {
            return true;
        }

        if ($this->db->tableExists('{{%navigation_nodes}}')) {
            $this->update('{{%navigation_nodes}}', ['type' => self::NODE_TYPE], ['type' => self::ELEMENT_TYPE]);
        }

        $this->_renameMenuPermissionRows();
        $this->_renameMenuPermissionsInProjectConfig();

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always false; the conversion isn't reverted.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function safeDown(): bool
    {
        echo "m260925_020000_navigation_4_node_types cannot be reverted.\n";

        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * Renames the bundle key in each menu's stored node type settings.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _renameMenuPermissionRows(): void
    {
        $table = '{{%navigation_menus}}';

        if (!$this->db->tableExists($table) || !$this->db->columnExists($table, 'permissions')) {
            return;
        }

        $rows = (new Query())
            ->select(['id', 'permissions'])
            ->from($table)
            ->where(['not', ['permissions' => null]])
            ->all($this->db);

        foreach ($rows as $row) {
            $permissions = Json::decodeIfJson($row['permissions']);

            if (!is_array($permissions) || !$this->_renameKey($permissions)) {
                continue;
            }

            $this->update($table, ['permissions' => Json::encode($permissions)], ['id' => $row['id']]);
        }
    }

    /**
     * Renames the bundle key in each menu's project config, where project
     * config can be written. Other environments pick the change up from the
     * YAML.
     *
     * @return void
     * @throws Exception if project config can't be updated.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _renameMenuPermissionsInProjectConfig(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        if ($projectConfig->readOnly) {
            return;
        }

        $menus = $projectConfig->get('navigation.menus') ?? [];
        $projectConfig->muteEvents = true;

        try {
            foreach ($menus as $uid => $menu) {
                $permissions = $menu['permissions'] ?? null;

                if (!is_array($permissions) || !$this->_renameKey($permissions)) {
                    continue;
                }

                $projectConfig->set(
                    "navigation.menus.$uid.permissions",
                    $permissions,
                    'Move bundle menu settings to the Navigation 4 bundle node type',
                );
            }
        } finally {
            $projectConfig->muteEvents = false;
        }
    }

    /**
     * Moves the bundle element key to the node type key, unless the menu
     * already has settings under the node type.
     *
     * @param array<string, mixed> $permissions The menu's node type settings.
     * @return bool Whether anything changed.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _renameKey(array &$permissions): bool
    {
        if (!array_key_exists(self::ELEMENT_TYPE, $permissions)) {
            return false;
        }

        if (!array_key_exists(self::NODE_TYPE, $permissions)) {
            $permissions[self::NODE_TYPE] = $permissions[self::ELEMENT_TYPE];
        }

        unset($permissions[self::ELEMENT_TYPE]);

        return true;
    }
}
