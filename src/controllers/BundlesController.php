<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\controllers;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Product;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\models\BundleProduct;
use Throwable;
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
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundlesController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The base of the per-bundle-type permission; append `:{bundleTypeUid}`.
     */
    public const PERMISSION_MANAGE_BUNDLES = 'bundle-builder:manage-bundles';

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
     * @throws ForbiddenHttpException if the user can't manage bundles of any type.
     * @throws BadRequestHttpException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function actionIndex(): Response
    {
        $this->requireCpRequest();

        $bundleTypes = BundleBuilder::getInstance()->getBundleTypes()->getEditableBundleTypes();

        if (empty($bundleTypes) && !static::currentUser()?->admin) {
            throw new ForbiddenHttpException(Craft::t('bundle-builder', 'User not authorized to manage bundles of any type.'));
        }

        return $this->renderTemplate('bundle-builder/bundles/index', [
            'bundleTypes' => $bundleTypes,
        ]);
    }

    /**
     * Creates a fresh bundle draft and redirects to the native element editor.
     *
     * @return Response|null The response, or null on a model failure.
     * @throws BadRequestHttpException if no editable bundle type exists.
     * @throws ForbiddenHttpException if the user can't create bundles.
     * @throws Throwable if the draft can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
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
            throw new BadRequestHttpException(Craft::t('bundle-builder', 'No editable bundle type exists.'));
        }

        $this->requirePermission(self::PERMISSION_MANAGE_BUNDLES . ':' . $bundleType->uid);

        $site = Cp::requestedSite();

        if (!$site) {
            throw new ForbiddenHttpException('User not authorized to edit content in any sites.');
        }

        $user = static::currentUser();

        $bundle = Craft::createObject(Bundle::class);
        $bundle->siteId = $site->id;
        $bundle->typeId = $bundleType->id;
        $bundle->applyDefaultStatus();

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
     * Renders a component row for each product picked in the bundle editor's
     * product selector modal, at the posted quantity (1 for a new row, the
     * row's own when its product is swapped).
     *
     * The same partial the bundle editor renders server-side for the rows that
     * are already there, so added rows match them exactly.
     *
     * @return Response The rendered rows, head HTML, and body HTML.
     * @throws BadRequestHttpException if the request isn't an AJAX request, or the namespace isn't a form namespace.
     * @throws ForbiddenHttpException if the user can't manage bundles of the posted type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function actionProductRows(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $typeId = (int)$this->request->getParam('typeId');
        $editableTypeIds = array_map(
            static fn($bundleType): int => (int)$bundleType->id,
            BundleBuilder::getInstance()->getBundleTypes()->getEditableBundleTypes(),
        );

        if (!in_array($typeId, $editableTypeIds, true)) {
            throw new ForbiddenHttpException(Craft::t('bundle-builder', 'User not authorized to manage bundles of this type.'));
        }

        $namespace = $this->request->getParam('namespace');

        if ($namespace !== null && $namespace !== '' && (!is_string($namespace) || !preg_match('/^[\w\-\[\]]+$/', $namespace))) {
            throw new BadRequestHttpException('Invalid namespace.');
        }

        $index = (int)$this->request->getRequiredParam('index');
        $qty = max(1, (int)$this->request->getParam('qty', 1));
        $existingIds = $this->_intList($this->request->getParam('existingProductIds'));
        $bundleType = BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($typeId);

        // Only real products: not drafts, revisions or anything else by ID
        $productIds = Product::find()
            ->id($this->_intList($this->request->getParam('productIds')) ?: [0])
            ->status(null)
            ->fixedOrder()
            ->ids();
        $productIds = array_map('intval', $productIds);
        $allIds = array_values(array_unique([...$existingIds, ...$productIds]));
        $view = $this->getView();

        // Rendered under the editor's namespace, so rows added in a slideout
        // post alongside the rows already there.
        $html = $view->namespaceInputs(function() use ($view, $productIds, $allIds, $index, $qty, $bundleType): string {
            $rows = '';

            foreach ($productIds as $i => $productId) {
                $rows .= $view->renderTemplate('bundle-builder/bundles/_product-row', [
                    'index' => $index + $i,
                    'bundleProduct' => new BundleProduct(['productId' => $productId, 'qty' => $qty]),
                    'disabledProductIds' => array_values(array_diff($allIds, [$productId])),
                    'componentSources' => $bundleType->componentSources ?? '*',
                ]);
            }

            return $rows;
        }, $namespace ?: null);

        return $this->asJson([
            'html' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a posted list of IDs as integers, dropping anything that isn't one.
     *
     * @param mixed $value The posted value.
     * @return int[] The IDs.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($value, 'is_numeric')));
    }
}
