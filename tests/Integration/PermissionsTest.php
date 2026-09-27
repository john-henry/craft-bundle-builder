<?php

/**
 * Pins the kebab-case per-bundle-type permission: that it's what gets
 * registered, and that the rename migration carries existing user grants
 * over from the old camelCase handle, including when the new row exists.
 */

use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\controllers\BundlesController;
use johnhenry\bundlebuilder\migrations\m260924_000000_kebab_case_permissions;

/**
 * Inserts a permission row with the given name and grants it to a user,
 * returning the permission ID.
 */
function grantRawPermission(string $name, int $userId): int
{
    $db = Craft::$app->getDb();
    $db->createCommand()->insert(Table::USERPERMISSIONS, ['name' => $name])->execute();
    $permissionId = (int)$db->getLastInsertID();
    $db->createCommand()->insert(Table::USERPERMISSIONS_USERS, ['permissionId' => $permissionId, 'userId' => $userId])->execute();

    return $permissionId;
}

/**
 * Returns the IDs of the users granted the named permission.
 *
 * @return int[]
 */
function usersWithPermission(string $name): array
{
    return array_map('intval', (new Query())
        ->select(['pu.userId'])
        ->from(['pu' => Table::USERPERMISSIONS_USERS])
        ->innerJoin(['p' => Table::USERPERMISSIONS], '[[p.id]] = [[pu.permissionId]]')
        ->where(['p.name' => $name])
        ->column());
}

describe('Bundle permissions', function () {
    it('registers a kebab-case permission per bundle type', function () {
        $bundleType = makeBundleType('Perms', 'perms' . StringHelper::randomString(6));
        Craft::$app->getUserPermissions()->reset();

        $names = [];
        foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
            $names = [...$names, ...array_keys($group['permissions'])];
        }

        expect($names)->toContain(BundlesController::PERMISSION_MANAGE_BUNDLES . ':' . $bundleType->uid);
    });

    it('renames old permission rows and keeps their grants', function () {
        $userId = (int)User::find()->status(null)->one()->id;
        $uid = StringHelper::UUID();

        grantRawPermission("bundle-builder:managebundles:$uid", $userId);
        (new m260924_000000_kebab_case_permissions())->safeUp();

        expect(usersWithPermission("bundle-builder:managebundles:$uid"))->toBe([])
            ->and(usersWithPermission("bundle-builder:manage-bundles:$uid"))->toBe([$userId]);
    });

    it('merges grants when the new permission row already exists', function () {
        $userId = (int)User::find()->status(null)->one()->id;
        $uid = StringHelper::UUID();

        grantRawPermission("bundle-builder:managebundles:$uid", $userId);
        grantRawPermission("bundle-builder:manage-bundles:$uid", $userId);
        (new m260924_000000_kebab_case_permissions())->safeUp();

        expect(usersWithPermission("bundle-builder:managebundles:$uid"))->toBe([])
            ->and(usersWithPermission("bundle-builder:manage-bundles:$uid"))->toBe([$userId]);
    });
});
