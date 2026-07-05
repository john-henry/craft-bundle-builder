<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\controllers;

use Craft;
use craft\behaviors\FieldLayoutBehavior;
use craft\web\Controller;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\models\BundleTypeSite;
use Throwable;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Bundle Types controller.
 *
 * Handles the control panel CRUD screens for bundle types: listing, editing,
 * saving, and deleting. All actions require an admin account, since bundle types
 * define field layouts and URL settings.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleTypesController extends Controller
{
    // Protected Properties
    // =========================================================================

    /**
     * @var array<int|string>|bool|int Whether the controller's actions can be accessed anonymously.
     */
    protected array|bool|int $allowAnonymous = false;

    // Public Methods
    // =========================================================================

    /**
     * Renders the bundle types index.
     *
     * @return Response The rendering result.
     * @throws ForbiddenHttpException if the user is not an admin.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIndex(): Response
    {
        $this->requireAdmin(false);

        return $this->renderTemplate('bundle-builder/bundle-types/index', [
            'bundleTypes' => BundleBuilder::getInstance()->getBundleTypes()->getAllBundleTypes(),
        ]);
    }

    /**
     * Renders the bundle type edit screen.
     *
     * @param int|null $bundleTypeId The bundle type ID, or null to create a new one.
     * @param BundleType|null $bundleType The bundle type being edited, when set from a failed save.
     * @return Response The rendering result.
     * @throws ForbiddenHttpException if the user is not an admin.
     * @throws NotFoundHttpException if the bundle type can't be found.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionEdit(?int $bundleTypeId = null, ?BundleType $bundleType = null): Response
    {
        $this->requireAdmin(false);

        if ($bundleType === null) {
            if ($bundleTypeId) {
                $bundleType = BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($bundleTypeId);
                if (!$bundleType) {
                    throw new NotFoundHttpException(Craft::t('bundle-builder', 'Bundle type not found.'));
                }
            } else {
                $bundleType = new BundleType();
            }
        }

        $title = $bundleType->id
            ? trim($bundleType->name) ?: Craft::t('bundle-builder', 'Edit Bundle Type')
            : Craft::t('bundle-builder', 'Create a Bundle Type');

        return $this->renderTemplate('bundle-builder/bundle-types/_edit', [
            'bundleTypeId' => $bundleTypeId,
            'bundleType' => $bundleType,
            'title' => $title,
        ]);
    }

    /**
     * Saves a bundle type.
     *
     * @return Response|null The response, or null on a model failure.
     * @throws BadRequestHttpException if the request isn't a POST request.
     * @throws ForbiddenHttpException if the user is not an admin.
     * @throws NotFoundHttpException if the posted ID doesn't resolve to a bundle type.
     * @throws Throwable if the bundle type can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionSave(): ?Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $service = BundleBuilder::getInstance()->getBundleTypes();

        $id = $request->getBodyParam('id');

        if ($id) {
            $bundleType = $service->getBundleTypeById((int)$id);
            if (!$bundleType) {
                throw new NotFoundHttpException(Craft::t('bundle-builder', 'Bundle type not found.'));
            }
        } else {
            $bundleType = new BundleType();
        }

        $bundleType->name = $request->getBodyParam('name', $bundleType->name);
        $bundleType->handle = $request->getBodyParam('handle', $bundleType->handle);
        $bundleType->skuFormat = $request->getBodyParam('skuFormat', $bundleType->skuFormat);
        $bundleType->descriptionFormat = $request->getBodyParam('descriptionFormat', $bundleType->descriptionFormat);
        $bundleType->showSlugField = (bool)$request->getBodyParam('showSlugField', $bundleType->showSlugField);
        $bundleType->taxTreatment = $request->getBodyParam('taxTreatment', $bundleType->taxTreatment);

        // Preview targets: keep only rows with a URL format.
        $previewTargets = array_values(array_filter(
            $request->getBodyParam('previewTargets', []),
            static fn(array $target): bool => !empty($target['urlFormat']),
        ));
        $bundleType->previewTargets = $previewTargets;

        // Site settings
        $bundleType->setSiteSettings($this->_siteSettingsFromPost());

        // Field layout
        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = Bundle::class;
        /** @var FieldLayoutBehavior $fieldLayoutBehavior */
        $fieldLayoutBehavior = $bundleType->getBehavior('fieldLayout');
        $fieldLayoutBehavior->setFieldLayout($fieldLayout);

        if (!$service->saveBundleType($bundleType)) {
            return $this->asModelFailure(
                $bundleType,
                Craft::t('bundle-builder', 'Couldn’t save bundle type.'),
                'bundleType'
            );
        }

        $this->setSuccessFlash(Craft::t('bundle-builder', 'Bundle type saved.'));

        return $this->redirectToPostedUrl($bundleType);
    }

    /**
     * Deletes a bundle type.
     *
     * @return Response A JSON success response.
     * @throws BadRequestHttpException if the request isn't a POST/JSON request.
     * @throws ForbiddenHttpException if the user is not an admin.
     * @throws Throwable if the bundle type can't be deleted.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionDelete(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = Craft::$app->getRequest()->getRequiredBodyParam('id');

        if (!BundleBuilder::getInstance()->getBundleTypes()->deleteBundleTypeById((int)$id)) {
            return $this->asJson(['success' => false, 'error' => Craft::t('bundle-builder', 'Could not delete bundle type.')]);
        }

        return $this->asJson(['success' => true]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the bundle type's site settings models from the posted data.
     *
     * @return BundleTypeSite[] The site settings, indexed by site ID.
     * @throws InvalidConfigException if a posted site can't be resolved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _siteSettingsFromPost(): array
    {
        $request = Craft::$app->getRequest();
        $postedSites = $request->getBodyParam('sites', []);
        $allSiteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $postedSettings = $postedSites[$site->handle] ?? null;

            // Only sites whose "enabled" switch is on get a settings row, so the
            // bundle propagates exactly where the merchandiser chose.
            if ($postedSettings === null || empty($postedSettings['enabled'])) {
                continue;
            }

            $hasUrls = !empty($postedSettings['uriFormat']);

            $allSiteSettings[$site->id] = new BundleTypeSite([
                'siteId' => $site->id,
                'hasUrls' => $hasUrls,
                'uriFormat' => $hasUrls ? $postedSettings['uriFormat'] : null,
                'template' => $hasUrls ? ($postedSettings['template'] ?? null) : null,
                'enabledByDefault' => !empty($postedSettings['enabledByDefault']),
            ]);
        }

        return $allSiteSettings;
    }
}
