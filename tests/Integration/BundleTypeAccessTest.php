<?php

declare(strict_types=1);

use craft\elements\User;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\controllers\BundleTypesController;
use yii\web\ForbiddenHttpException;

it('hides bundle type navigation when admin changes are disabled', function (): void {
    Craft::$app->getUser()->setIdentity(User::find()->admin(true)->one());
    $general = Craft::$app->getConfig()->getGeneral();
    $allowAdminChanges = $general->allowAdminChanges;

    try {
        $general->allowAdminChanges = true;
        expect(BundleBuilder::getInstance()->getCpNavItem()['subnav'])->toHaveKey('bundle-types');
        $general->allowAdminChanges = false;
        $navigation = BundleBuilder::getInstance()->getCpNavItem()['subnav'];
        expect($navigation)->not->toHaveKey('bundle-types');
        expect($navigation)->toHaveKey('bundles');
    } finally {
        $general->allowAdminChanges = $allowAdminChanges;
    }
});

it('forbids direct bundle type access when admin changes are disabled', function (string $action): void {
    Craft::$app->getUser()->setIdentity(User::find()->admin(true)->one());
    $general = Craft::$app->getConfig()->getGeneral();
    $allowAdminChanges = $general->allowAdminChanges;
    $general->allowAdminChanges = false;
    $controller = new BundleTypesController('bundle-types', BundleBuilder::getInstance());
    $request = Craft::$app->getRequest();
    $isCpRequest = $request->getIsCpRequest();
    $request->setIsCpRequest(true);

    try {
        expect(fn() => $controller->runAction($action))->toThrow(
            ForbiddenHttpException::class,
            'Administrative changes are disallowed in this environment.',
        );
    } finally {
        $request->setIsCpRequest($isCpRequest);
        $general->allowAdminChanges = $allowAdminChanges;
    }
})->with(['index', 'edit', 'save', 'delete']);
