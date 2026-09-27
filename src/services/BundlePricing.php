<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\helpers\Currency;
use craft\db\Table;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\DiscountType;
use johnhenry\bundlebuilder\enums\PricingStrategy;
use johnhenry\bundlebuilder\jobs\RecalculateBundlePrices;
use johnhenry\bundlebuilder\models\BundleProduct;
use johnhenry\bundlebuilder\records\BundleProductRecord;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Bundle pricing service.
 *
 * Automatic bundle pricing: the summed sale price of the components, less a
 * percentage or flat discount. The stored price uses each component's default
 * variant; the cart adjusts it for the variants the customer actually chose,
 * so a dearer variant costs more and a cheaper one less.
 *
 * @phpstan-type PriceRange array{min: float, max: float}
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundlePricing extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the summed sale price of a bundle's components, each at its
     * default variant and multiplied by its quantity. Loads every component
     * product in one query.
     *
     * @param Bundle $bundle The bundle to total.
     * @return float The components subtotal.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getComponentsSubtotal(Bundle $bundle): float
    {
        $bundleProducts = $bundle->getProducts();

        if (empty($bundleProducts)) {
            return 0.0;
        }

        $productIds = array_values(array_unique(array_filter(array_map(
            static fn(BundleProduct $bundleProduct): ?int => $bundleProduct->productId,
            $bundleProducts,
        ))));

        // Include disabled products: a disabled component still ships in the
        // bundle, and getProductById() elsewhere ignores status too.
        $products = Product::find()->id($productIds)->siteId($bundle->siteId)->status(null)->indexBy('id')->all();

        $subtotal = 0.0;

        foreach ($bundleProducts as $bundleProduct) {
            $product = $products[$bundleProduct->productId] ?? null;

            if (!$product) {
                Craft::warning(
                    "Bundle {$bundle->id} lists product {$bundleProduct->productId}, which could not be found; "
                    . 'its price is not included in the bundle total.',
                    'bundle-builder',
                );
                continue;
            }

            $bundleProduct->setProduct($product);

            if (!$bundleProduct->getDefaultVariant()) {
                Craft::warning(
                    "Bundle {$bundle->id}'s component product {$bundleProduct->productId} has no variant a customer can buy; "
                    . 'its price is not included in the bundle total.',
                    'bundle-builder',
                );
                continue;
            }

            $subtotal += $this->getComponentUnitPrice($bundleProduct) * $bundleProduct->qty;
        }

        return $subtotal;
    }

    /**
     * Returns a product's default variant's sale price, which takes
     * promotional and catalog pricing into account.
     *
     * @param Product $product The component product.
     * @return float The component's unit price.
     * @throws InvalidConfigException
     * @deprecated in 1.2.0. Use {@see getComponentUnitPrice()}, which respects the variants a component offers.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getComponentPrice(Product $product): float
    {
        $variant = $product->getDefaultVariant();

        return (float)($variant?->getSalePrice() ?? 0);
    }

    /**
     * Returns a component's unit price: the sale price of the variant chosen
     * when the customer doesn't pick one.
     *
     * @param BundleProduct $bundleProduct The component.
     * @return float The component's unit price.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getComponentUnitPrice(BundleProduct $bundleProduct): float
    {
        return (float)($bundleProduct->getDefaultVariant()?->getSalePrice() ?? 0);
    }

    /**
     * Returns how much choosing a variant changes an automatic bundle's price:
     * the difference from the component's default variant, times the
     * component's quantity, less the bundle's percentage discount. A fixed
     * price bundle doesn't change, so this is always zero for one, as it is
     * for a variant the component doesn't offer.
     *
     * @param Bundle $bundle The bundle.
     * @param BundleProduct $bundleProduct The component.
     * @param Variant $variant The chosen variant.
     * @return float The change in the bundle's price, unrounded.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getVariantAdjustment(Bundle $bundle, BundleProduct $bundleProduct, Variant $variant): float
    {
        if ($bundle->pricingStrategy !== PricingStrategy::Automatic->value) {
            return 0.0;
        }

        return $this->_variantAdjustment($bundle, $bundleProduct, $variant, $this->getComponentUnitPrice($bundleProduct));
    }

    /**
     * Returns how much a set of chosen variants changes a bundle's price: each
     * component's {@see getVariantAdjustment()}, rounded to the store currency
     * as it's shown next to the variant, then summed, so the charge matches
     * what the shopper saw add up. A flat discount bigger than the components
     * holds the price at 0 until a choice lifts it above. Choices for variants
     * a component doesn't offer are ignored.
     *
     * @param Bundle $bundle The bundle.
     * @param array<int, array{productId?: int, variantId?: int}> $selections The chosen variant for each component.
     * @return float The change in the bundle's price.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getSelectionsAdjustment(Bundle $bundle, array $selections): float
    {
        if ($bundle->pricingStrategy !== PricingStrategy::Automatic->value) {
            return 0.0;
        }

        $chosen = [];

        foreach ($selections as $selection) {
            $chosen[(int)($selection['productId'] ?? 0)] = (int)($selection['variantId'] ?? 0);
        }

        $currency = $bundle->getStore()->getCurrency();
        $adjustment = 0.0;

        foreach ($bundle->getProducts() as $bundleProduct) {
            $variantId = $chosen[(int)$bundleProduct->productId] ?? null;

            foreach ($variantId ? $bundleProduct->getVariants() : [] as $variant) {
                if ((int)$variant->id === $variantId) {
                    $adjustment += Currency::round($this->getVariantAdjustment($bundle, $bundleProduct, $variant), $currency);
                    break;
                }
            }
        }

        // A flat discount bigger than the components floors the price at 0,
        // so only the part of a dearer choice that lifts it above 0 is charged
        if ($bundle->discountType === DiscountType::Flat->value && $adjustment !== 0.0) {
            $subtotal = $this->getComponentsSubtotal($bundle);
            $discount = (float)($bundle->discountAmount ?? 0);
            $adjustment = max(0.0, $subtotal + $adjustment - $discount) - max(0.0, $subtotal - $discount);
        }

        return Currency::round($adjustment, $currency);
    }

    /**
     * Returns the lowest and highest a bundle's sale price can be across the
     * variants its components offer. The same for both on a fixed price
     * bundle, or when every component's variants cost the same.
     *
     * @param Bundle $bundle The bundle.
     * @return PriceRange The price range.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getPriceRange(Bundle $bundle): array
    {
        $price = (float)$bundle->getSalePrice();

        if ($bundle->pricingStrategy !== PricingStrategy::Automatic->value) {
            return ['min' => $price, 'max' => $price];
        }

        $currency = $bundle->getStore()->getCurrency();
        $min = $max = 0.0;

        foreach ($bundle->getProducts() as $bundleProduct) {
            $defaultPrice = $this->getComponentUnitPrice($bundleProduct);
            $adjustments = array_map(
                fn(Variant $variant): float => Currency::round($this->_variantAdjustment($bundle, $bundleProduct, $variant, $defaultPrice), $currency),
                $bundleProduct->getVariants(),
            );

            if ($adjustments) {
                $min += min($adjustments);
                $max += max($adjustments);
            }
        }

        return [
            'min' => max(0.0, Currency::round($price + $min, $currency)),
            'max' => max(0.0, Currency::round($price + $max, $currency)),
        ];
    }

    /**
     * Calculates a bundle's automatic price: the components subtotal less the
     * configured discount, rounded to the store currency's minor unit and
     * floored at zero.
     *
     * @param Bundle $bundle The bundle to price.
     * @return float The calculated price.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function calculatePrice(Bundle $bundle): float
    {
        $subtotal = $this->getComponentsSubtotal($bundle);
        $discountAmount = (float)($bundle->discountAmount ?? 0);

        $price = match ($bundle->discountType) {
            DiscountType::Percentage->value => $subtotal * (1 - ($discountAmount / 100)),
            DiscountType::Flat->value => $subtotal - $discountAmount,
            default => $subtotal,
        };

        return max(0.0, Currency::round($price, $bundle->getStore()->getCurrency()));
    }

    /**
     * Returns the IDs of every bundle, not counting revisions, that lists the
     * given product as a component.
     *
     * @param int $productId The component product's ID.
     * @return int[] The bundle IDs.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundleIdsForProduct(int $productId): array
    {
        // Revisions keep their own component rows; only live bundles count.
        $bundleIds = BundleProductRecord::find()
            ->select(['bundlebuilder_products.bundleId'])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[bundlebuilder_products.bundleId]]')
            ->where(['bundlebuilder_products.productId' => $productId, 'elements.revisionId' => null])
            ->column();

        return array_values(array_unique(array_map('intval', $bundleIds)));
    }

    /**
     * Recalculates and stores an automatically priced bundle's price by
     * re-saving it. Fixed-price bundles are left alone.
     *
     * @param Bundle $bundle The bundle to recalculate.
     * @return void
     * @throws Throwable if the bundle can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function recalculateBundle(Bundle $bundle): void
    {
        if ($bundle->pricingStrategy !== PricingStrategy::Automatic->value) {
            return;
        }

        // Bundle::beforeSave() recomputes the price. Flagged as a resave, so a
        // component's price change doesn't add a revision to every bundle.
        $bundle->resaving = true;
        Craft::$app->getElements()->saveElement($bundle, false);
    }

    /**
     * Recalculates every automatically priced bundle containing the given
     * product. For a widely used component, queue {@see RecalculateBundlePrices}
     * instead, which batches the work.
     *
     * @param int $productId The component product's ID.
     * @return void
     * @throws Throwable if a bundle can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function recalculateBundlesForProduct(int $productId): void
    {
        $bundleIds = $this->getBundleIdsForProduct($productId);

        if (empty($bundleIds)) {
            return;
        }

        $bundles = Bundle::find()
            ->id($bundleIds)
            ->status(null)
            ->all();

        foreach ($bundles as $bundle) {
            $this->recalculateBundle($bundle);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the share of a component price change that reaches an automatic
     * bundle's price: all of it, less the bundle's percentage discount if it
     * has one. A flat discount doesn't scale with the components.
     *
     * @param Bundle $bundle The bundle.
     * @return float The factor, between 0 and 1.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _discountFactor(Bundle $bundle): float
    {
        if ($bundle->discountType !== DiscountType::Percentage->value) {
            return 1.0;
        }

        return max(0.0, 1 - ((float)($bundle->discountAmount ?? 0) / 100));
    }

    /**
     * The adjustment for a variant against an already-known default price, so
     * a caller pricing every variant looks the default up once. Zero for a
     * variant the component doesn't offer.
     *
     * @param Bundle $bundle The bundle.
     * @param BundleProduct $bundleProduct The component.
     * @param Variant $variant The variant.
     * @param float $defaultPrice The component's default variant's sale price.
     * @return float The change in the bundle's price, unrounded.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _variantAdjustment(Bundle $bundle, BundleProduct $bundleProduct, Variant $variant, float $defaultPrice): float
    {
        if ((int)$variant->getPrimaryOwnerId() !== (int)$bundleProduct->productId || !$bundleProduct->allowsVariant((int)$variant->id)) {
            return 0.0;
        }

        $difference = (float)($variant->getSalePrice() ?? 0) - $defaultPrice;

        return $difference * $bundleProduct->qty * $this->_discountFactor($bundle);
    }
}
