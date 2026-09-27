<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use johnhenry\bundlebuilder\BundleBuilder;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Orders controller.
 *
 * Changes the variants chosen for a bundle line from the CP order edit screen.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class OrdersController extends Controller
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
     * Changes the variants chosen for a bundle line on an incomplete order.
     *
     * @return Response A JSON success or error response.
     * @throws BadRequestHttpException if the request isn't a POST/JSON request or lacks its params.
     * @throws ForbiddenHttpException if the user can't edit orders, or this order.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function actionUpdateVariants(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('commerce-manageOrders');
        $this->requirePermission('commerce-editOrders');

        $orderId = (int)$this->request->getRequiredBodyParam('orderId');
        $lineItemId = (int)$this->request->getRequiredBodyParam('lineItemId');
        $variants = $this->request->getBodyParam('variants');

        $order = Commerce::getInstance()->getOrders()->getOrderById($orderId);

        if (!$order) {
            return $this->asJson(['success' => false, 'error' => Craft::t('bundle-builder', 'Order not found.')]);
        }

        // As Commerce's own order save does, so plugins that restrict orders
        // through Craft's authorization events apply here too.
        if (!Craft::$app->getElements()->canSave($order, static::currentUser())) {
            throw new ForbiddenHttpException(Craft::t('bundle-builder', 'User not authorized to edit this order.'));
        }

        try {
            $errors = BundleBuilder::getInstance()->getBundleCart()
                ->changeVariants($order, $lineItemId, is_array($variants) ? $variants : []);
        } catch (Throwable $e) {
            Craft::error("Couldn't change bundle variants on order $orderId: {$e->getMessage()}", 'bundle-builder');

            return $this->asJson(['success' => false, 'error' => Craft::t('bundle-builder', 'Couldn’t change the variants.')]);
        }

        if (!empty($errors)) {
            return $this->asJson(['success' => false, 'error' => implode(' ', $errors)]);
        }

        return $this->asJson(['success' => true]);
    }
}
