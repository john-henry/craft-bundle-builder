<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\controllers;

use Craft;
use craft\base\Element;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Bundles controller.
 *
 * Handles the bundles index, bootstrapping a new bundle draft for the native
 * element editor, and rendering AJAX component rows for the editor's component
 * picker. Editing and saving are handled by Craft's native element editor
 * (`elements/edit` + `elements/save*`).
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundlesController extends Controller
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
     * Renders the bundles index.
     *
     * @return Response The rendering result.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        return $this->renderTemplate('bundle-builder/bundles/index', [
            'bundleTypes' => BundleBuilder::getInstance()->getBundleTypes()->getEditableBundleTypes(),
        ]);
    }

    /**
     * Creates a fresh bundle draft and redirects to the native element editor.
     *
     * @return Response|null The response, or null on a model failure.
     * @throws BadRequestHttpException if no editable bundle type exists.
     * @throws ForbiddenHttpException if the user can't create bundles.
     * @throws \Throwable if the draft can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionCreate(): ?Response
    {
        $bundleTypesService = BundleBuilder::getInstance()->getBundleTypes();
        $typeId = $this->request->getParam('typeId');

        if ($typeId) {
            $bundleType = $bundleTypesService->getBundleTypeById((int)$typeId);
        } else {
            $editableTypes = $bundleTypesService->getEditableBundleTypes();
            $bundleType = reset($editableTypes) ?: null;
        }

        if (!$bundleType) {
            throw new BadRequestHttpException('No editable bundle type exists.');
        }

        $site = Cp::requestedSite();

        if (!$site) {
            throw new ForbiddenHttpException('User not authorized to edit content in any sites.');
        }

        $user = static::currentUser();

        $bundle = Craft::createObject(Bundle::class);
        $bundle->siteId = $site->id;
        $bundle->typeId = $bundleType->id;
        $bundle->enabled = true;

        if (!Craft::$app->getElements()->canSave($bundle, $user)) {
            throw new ForbiddenHttpException('User not authorized to create this bundle.');
        }

        $bundle->title = $this->request->getParam('title');
        $bundle->slug = $this->request->getParam('slug');

        if ($bundle->title && !$bundle->slug) {
            $bundle->slug = ElementHelper::generateSlug($bundle->title, null, $site->language);
        }
        if (!$bundle->slug) {
            $bundle->slug = ElementHelper::tempSlug();
        }

        // Pause time so postDate matches dateCreated when not explicitly set.
        DateTimeHelper::pause();
        $bundle->postDate = DateTimeHelper::now();

        $bundle->setScenario(Element::SCENARIO_ESSENTIALS);
        $success = Craft::$app->getDrafts()->saveElementAsDraft($bundle, $user->id, markAsSaved: false);

        DateTimeHelper::resume();

        if (!$success) {
            return $this->asModelFailure($bundle, Craft::t('bundle-builder', 'Couldn’t create bundle.'), 'bundle');
        }

        $editUrl = $bundle->getCpEditUrl();

        $response = $this->asModelSuccess(
            $bundle,
            Craft::t('bundle-builder', 'Bundle created.'),
            'bundle',
            array_filter([
                'cpEditUrl' => $this->request->getIsCpRequest() ? $editUrl : null,
            ]),
        );

        if (!$this->request->getAcceptsJson()) {
            $response->redirect(UrlHelper::urlWithParams($editUrl, ['fresh' => 1]));
        }

        return $response;
    }

    /**
     * Renders a single, empty component row for the AJAX component picker.
     *
     * @return Response The rendered row, head HTML, and body HTML.
     * @throws BadRequestHttpException if the request isn't an AJAX request.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionProductRow(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $index = $this->request->getRequiredParam('index');
        $view = $this->getView();

        $html = $view->renderTemplate('bundle-builder/bundles/_product-row', [
            'index' => $index,
            'bundleProduct' => null,
        ]);

        return $this->asJson([
            'html' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }
}
