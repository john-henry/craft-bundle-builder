<?php

/**
 * Coverage for the Navigation integration, under whichever major version of
 * Navigation is installed.
 *
 * Navigation 4 needs the bundle node type registered, and bundle nodes saved
 * under Navigation 3 moved onto it, or they have no node type and lose their
 * links. Navigation 3 auto-discovers bundles, and the plugin switches that
 * entry on by default without adding a second one.
 *
 * Navigation is a soft dependency, so these are skipped when it isn't enabled.
 */

use craft\db\Query;
use craft\helpers\Json;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\migrations\m260925_020000_navigation_4_node_types;
use johnhenry\bundlebuilder\nodetypes\Bundle as BundleNodeType;
use verbb\navigation\elements\Node;
use verbb\navigation\Navigation;
use verbb\navigation\services\NodeTypes;

beforeEach(function () {
    if (!Craft::$app->getPlugins()->isPluginEnabled('navigation')) {
        $this->markTestSkipped('Navigation is not installed.');
    }
});

describe('Navigation 4', function () {
    beforeEach(function () {
        if (!class_exists(NodeTypes::class)) {
            $this->markTestSkipped('Navigation 4 is not installed.');
        }
    });

    it('registers the bundle node type once, with its own add button', function () {
        $types = array_map(
            static fn(object $type): string => $type::class,
            Navigation::$plugin->getNodeTypes()->getRegisteredNodeTypes(),
        );

        expect(array_count_values($types)[BundleNodeType::class] ?? 0)->toBe(1)
            ->and(BundleNodeType::getElementType())->toBe(Bundle::class)
            ->and(BundleNodeType::getBuilderConfig()['button'])->toBe('Add a bundle');
    });

    it('moves a Navigation 3 bundle node and menu settings onto the node type', function () {
        $nodeId = (new Query())->select('id')->from('{{%navigation_nodes}}')->scalar();
        $menuId = (new Query())->select('id')->from('{{%navigation_menus}}')->scalar();

        if (!$nodeId || !$menuId) {
            $this->markTestSkipped('The test database has no Navigation menu or node.');
        }

        $db = Craft::$app->getDb();
        $db->createCommand()->update('{{%navigation_nodes}}', ['type' => Bundle::class], ['id' => $nodeId])->execute();
        $db->createCommand()->update('{{%navigation_menus}}', [
            'permissions' => Json::encode([Bundle::class => ['enabled' => false, 'permissions' => '*']]),
        ], ['id' => $menuId])->execute();

        // Read-only, so the test can't rewrite the project's YAML.
        $projectConfig = Craft::$app->getProjectConfig();
        $readOnly = $projectConfig->readOnly;
        $projectConfig->readOnly = true;

        try {
            (new m260925_020000_navigation_4_node_types())->safeUp();
        } finally {
            $projectConfig->readOnly = $readOnly;
        }

        $type = (new Query())->select('type')->from('{{%navigation_nodes}}')->where(['id' => $nodeId])->scalar();
        $permissions = Json::decode((new Query())->select('permissions')->from('{{%navigation_menus}}')->where(['id' => $menuId])->scalar());

        $node = new Node();
        $node->type = $type;

        expect($type)->toBe(BundleNodeType::class)
            ->and($node->nodeType())->toBeInstanceOf(BundleNodeType::class)
            ->and($permissions)->toBe([BundleNodeType::class => ['enabled' => false, 'permissions' => '*']]);
    });
});

describe('Navigation 3', function () {
    beforeEach(function () {
        if (class_exists(NodeTypes::class)) {
            $this->markTestSkipped('Navigation 3 is not installed.');
        }
    });

    it('registers the Bundle element exactly once, enabled by default', function () {
        $elements = Navigation::getInstance()->getElements()->getRegisteredElements(false);

        $bundleEntries = array_values(array_filter(
            $elements,
            static fn(array $element): bool => ($element['type'] ?? null) === Bundle::class,
        ));

        expect($bundleEntries)->toHaveCount(1)
            ->and($bundleEntries[0]['default'] ?? null)->toBeTrue();
    });
});
