<?php
/**
 * Instant Analytics plugin for Craft CMS 3.x
 *
 * Instant Analytics brings full Google Analytics support to your Twig templates
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

namespace nystudio107\instantanalytics\services;

use nystudio107\instantanalytics\InstantAnalytics;

use Craft;
use craft\base\Component;

/**
 * Commerce Service
 *
 * @author    nystudio107
 * @package   InstantAnalytics
 * @since     1.0.0
 */
class Commerce extends Component
{
    // Public Methods
    // =========================================================================

    // TODO: pending Commerce for Craft 3

    /**
     * Extract product data from a Craft Commerce Product or Variant
     *
     * @param Commerce_ProductModel|Commerce_VariantModel $productVariant the
     *                                                                    Product
     *                                                                    or
     *                                                                    Variant
     *
     * @return array the product data
     */
    public function getProductDataFromProduct($productVariant = null)
    {
        $result = [];
        if ($productVariant) {
            if (is_object($productVariant) && ($productVariant->getElementType() == "Commerce_Product" || is_a($productVariant, "Commerce\\Base\\Purchasable"))) {
                $productType = property_exists($productVariant, "typeId")
                    ? craft()->commerce_productTypes->getProductTypeById($productVariant->typeId)
                    : null;

                if ($productType && $productType->hasVariants) {
                    $productVariant = ArrayHelper::getFirstValue($productVariant->getVariants());
                    $product = $productVariant->getProduct();
                    if ($product) {
                        $category = $product->getType()['name'];
                        $name = $product->title;
                        $variant = $productVariant->title;
                    } else {
                        $category = $productVariant->getType()['name'];
                        $name = $productVariant->title;
                        $variant = "";
                    }
                } else {
                    if (isset($productVariant->defaultVariantId)) {
                        $productVariant = craft()->commerce_variants->getVariantById($productVariant->defaultVariantId);
                        $category = $productVariant->getProduct()->getType()['name'];
                        $name = $productVariant->title;
                        $variant = "";
                    } else {
                        if (isset($productVariant->product)) {
                            $category = $productVariant->product->getType()['name'];
                            $name = $productVariant->product->title;
                        } else {
                            $category = $productVariant->getType()['name'];
                            $name = $productVariant->title;
                        }
                        $variant = $productVariant->title;
                    }
                }
            }

            $productData = [
                'sku'      => $productVariant->sku,
                'name'     => $name,
                'price'    => number_format($productVariant->price, 2, '.', ''),
                'category' => $category,
                /*
                                'brand' => "",
                */
            ];

            if ($variant) {
                $productData['variant'] = $variant;
            }

            $settings = craft()->plugins->getPlugin('instantanalytics')->getSettings();
            $isVariant = $productVariant->getElementType() == "Commerce_Variant";

            if (isset($settings) && isset($settings['productCategoryField']) && $settings['productCategoryField'] != "") {
                $productData['category'] = $this->_pullDataFromField(
                    $productVariant,
                    $settings['productCategoryField']
                );
                if (empty($productData['category']) && $isVariant) {
                    $productData['category'] = $this->_pullDataFromField(
                        $productVariant->product,
                        $settings['productCategoryField']
                    );
                }
            }

            if (isset($settings) && isset($settings['productBrandField']) && $settings['productBrandField'] != "") {
                $productData['brand'] = $this->_pullDataFromField(
                    $productVariant,
                    $settings['productBrandField'],
                    true
                );
                if (empty($productData['brand']) && $isVariant) {
                    $productData['brand'] = $this->_pullDataFromField(
                        $productVariant,
                        $settings['productBrandField'],
                        true
                    );
                }
            }

            $result = $productData;
        }

        return $result;
    } /* -- getProductDataFromProduct */

    /**
     * Add a product impression from a Craft Commerce Product or Variant
     *
     * @param IAnalytics $analytics            the Analytics object
     * @param            Commerce_ProductModel or Commerce_VariantModel
     *                                            $productVariant the Product
     *                                            or Variant
     * @param int        $index                Where the product appears in the
     *                                         list
     */
    public function addCommerceProductImpression($analytics = null, $productVariant = null, $index = 0, $listName = "default", $listIndex = 1)
    {
        if ($productVariant) {
            if ($analytics) {
                $productData = $this->getProductDataFromProduct($productVariant);
                /**
                 * As per: https://github.com/theiconic/php-ga-measurement-protocol/issues/26
                 */
                if ($listName && $listIndex) {
                    $analytics->setProductImpressionListName($listName, $listIndex);
                }
                if ($index) {
                    $productData['position'] = $index;
                }

                //Add the product to the hit to be sent
                $analytics->addProductImpression($productData, $listIndex);
                InstantAnalyticsPlugin::log("addCommerceProductImpression for `" . $productData['sku'] . "` - `" . $productData['name'] . "` - `" . $productData['name'] . "` - `" . $index . "`", LogLevel::Info, false);
            }
        }
    } /* -- addCommerceProductImpression */

    /**
     * Add a product detail view from a Craft Commerce Product or Variant
     *
     * @param IAnalytics $analytics            the Analytics object
     * @param            Commerce_ProductModel or Commerce_VariantModel
     *                                            $productVariant the Product
     *                                            or Variant
     */
    public function addCommerceProductDetailView($analytics = null, $productVariant = null)
    {
        if ($productVariant) {
            if ($analytics) {
                $productData = $this->getProductDataFromProduct($productVariant);

                // Don't forget to set the product action, in this case to DETAIL
                $analytics->setProductActionToDetail();

                //Add the product to the hit to be sent
                $analytics->addProduct($productData);
                InstantAnalyticsPlugin::log("addCommerceProductDetailView for `" . $productData['sku'] . "` - `" . $productData['name'] . "` - `" . $productData['name'] . "`", LogLevel::Info, false);
            }
        }
    } /* -- addCommerceProductDetailView */

    /**
     * Add a checkout step and option to an Analytics object
     *
     * @param IAnalytics          $analytics  the Analytics object
     * @param Commerce_OrderModel $orderModel the Product or Variant
     * @param int                 $step       the checkout step
     * @param string              $option     the checkout option
     */
    public function addCommerceCheckoutStep($analytics = null, $orderModel = null, $step = 1, $option = "")
    {
        if ($orderModel) {
            if ($analytics) {
                // Add each line item in the transaction
                // Two cases - variant and non variant products
                $index = 1;
                foreach ($orderModel->lineItems as $key => $lineItem) {
                    $this->addProductDataFromLineItem($analytics, $lineItem, $index, "");
                    $index++;
                }
                $analytics->setCheckoutStep($step);
                if ($option) {
                    $analytics->setCheckoutStepOption($option);
                }

                // Don't forget to set the product action, in this case to CHECKOUT
                $analytics->setProductActionToCheckout();
                InstantAnalyticsPlugin::log("addCommerceCheckoutStep step: `" . $step . "` with option: `" . $option . "`", LogLevel::Info, false);
            }
        }
    } /* -- addCommerceCheckoutStep */

    /**
     * Add a Craft Commerce LineItem to an Analytics object
     *
     * @return string the title of the product
     */
    public function addProductDataFromLineItem($analytics = null, $lineItem = null, $index = 0, $listName = "")
    {
        $result = "";
        if ($lineItem) {
            if ($analytics) {
                //This is the same for both variant and non variant products
                $productData = [
                    'sku'      => $lineItem->purchasable->sku,
                    'price'    => $lineItem->salePrice,
                    'quantity' => $lineItem->qty,
                ];

                if (isset($lineItem->purchasable->product)) {
                    $productVariant = $lineItem->purchasable->product;
                    if (!$lineItem->purchasable->product->type->hasVariants) {
                        //No variants (i.e. default variant)
                        $productData['name'] = $lineItem->purchasable->title;
                        $productData['category'] = $lineItem->purchasable->product->type['name'];
                    } else {
                        // Product with variants
                        $productData['name'] = $lineItem->purchasable->product->title;
                        $productData['category'] = $lineItem->purchasable->product->type['name'];
                        $productData['variant'] = $lineItem->purchasable->title;
                    }
                } else {
                    $productVariant = $lineItem->purchasable;
                    $productData['name'] = $lineItem->purchasable->title;
                    $productData['category'] = $lineItem->purchasable->type->name;
                }
                $result = $productData['name'];
                if ($index) {
                    $productData['position'] = $index;
                }
                if ($listName) {
                    $productData['list'] = $listName;
                }

                $settings = craft()->plugins->getPlugin('instantanalytics')->getSettings();
                if (isset($settings) && isset($settings['productCategoryField']) && $settings['productCategoryField'] != "") {
                    $productData['category'] = $this->_pullDataFromField($productVariant, $settings['productCategoryField']);
                }
                if (isset($settings) && isset($settings['productBrandField']) && $settings['productBrandField'] != "") {
                    $productData['brand'] = $this->_pullDataFromField($productVariant, $settings['productBrandField']);
                }
                //Add each product to the hit to be sent
                $analytics->addProduct($productData);
            }
        }

        return $result;
    } /* -- addProductDataFromLineItem */

    /**
     * Add a Craft Commerce OrderModel to an Analytics object
     *
     * @param IAnalytics          $analytics  the Analytics object
     * @param Commerce_OrderModel $orderModel the Product or Variant
     */
    public function addCommerceOrderToAnalytics($analytics = null, $orderModel = null)
    {
        if ($orderModel) {
            if ($analytics) {
                // First, include the transaction data
                $analytics->setTransactionId($orderModel->number)
                    ->setRevenue($orderModel->totalPrice)
                    ->setTax($orderModel->TotalTax)
                    ->setShipping($orderModel->totalShippingCost);

                // Coupon code?
                if ($orderModel->couponCode) {
                    $analytics->setCouponCode($orderModel->couponCode);
                }

                // Add each line item in the transaction
                // Two cases - variant and non variant products
                $index = 1;
                foreach ($orderModel->lineItems as $key => $lineItem) {
                    $this->addProductDataFromLineItem($analytics, $lineItem, $index, "");
                    $index++;
                }
            }
        }
    } /* -- addCommerceOrderToAnalytics */

    /**
     * Send analytics information for the completed order
     *
     * @param IAnalytics          $analytics  the Analytics object
     * @param Commerce_OrderModel $orderModel the Product or Variant
     */
    public function orderComplete($orderModel = null)
    {
        if ($orderModel) {
            $analytics = $this->eventAnalytics("Commerce", "Purchase", $orderModel->number, $orderModel->totalPrice);
            if ($analytics) {
                $this->addCommerceOrderToAnalytics($analytics, $orderModel);
                // Don't forget to set the product action, in this case to PURCHASE
                $analytics->setProductActionToPurchase();

                $analytics->sendEvent();

                InstantAnalyticsPlugin::log("orderComplete for `Commerce` - `Purchase` - `" . $orderModel->number . "` - `" . $orderModel->totalPrice . "`", LogLevel::Info, false);
            }
        }
    } /* -- orderComplete */

    /**
     * Send analytics information for the item added to the cart
     *
     * @param Commerce_OrderModel    $orderModel the Product or Variant
     * @param Commerce_LineItemModel $lineItem   the line item that was added
     */
    public function addToCart($orderModel = null, $lineItem = null)
    {
        if ($lineItem) {
            $title = $lineItem->purchasable->title;
            $quantity = $lineItem->qty;
            $analytics = $this->eventAnalytics("Commerce", "Add to Cart", $title, $quantity);
            if ($analytics) {
                $title = $this->addProductDataFromLineItem($analytics, $lineItem);
                $analytics->setEventLabel($title);
                // Don't forget to set the product action, in this case to ADD
                $analytics->setProductActionToAdd();
                $analytics->sendEvent();

                InstantAnalyticsPlugin::log("addToCart for `Commerce` - `Add to Cart` - `" . $title . "` - `" . $quantity . "`", LogLevel::Info, false);
            }
        }
    } /* -- addToCart */

    /**
     * Send analytics information for the item removed from the cart
     */
    public function removeFromCart($orderModel = null, $lineItem = null)
    {
        if ($lineItem) {
            $title = $lineItem->purchasable->title;
            $quantity = $lineItem->qty;
            $analytics = $this->eventAnalytics("Commerce", "Remove from Cart", $title, $quantity);
            if ($analytics) {
                $title = $this->addProductDataFromLineItem($analytics, $lineItem);
                $analytics->setEventLabel($title);
                // Don't forget to set the product action, in this case to ADD
                $analytics->setProductActionToRemove();
                $analytics->sendEvent();

                InstantAnalyticsPlugin::log("removeFromCart for `Commerce` - `Remove from Cart` - `" . $title . "` - `" . $quantity . "`", LogLevel::Info, false);
            }
        }
    } /* -- removeFromCart */

    /**
     * Extract the value of a field
     *
     * @param Commerce_OrderModel    $orderModel the Product or Variant
     * @param Commerce_LineItemModel $lineItem   the line item that was added
     * @param boolean                $isBrand    Are we getting the brand?
     *
     * @return string
     */
    private function _pullDataFromField($productVariant, $fieldHandle, $isBrand = false)
    {
        $result = "";
        if ($productVariant) {
            if ($fieldHandle) {
                $srcField = $productVariant[$fieldHandle];
                if ($srcField == null) {
                    $srcField = $productVariant->product->content->attributes[$fieldHandle];
                }

                if (isset($srcField->elementType)) {
                    switch ($srcField->elementType->classHandle) {
                        case "Neo":
                            break;

                        case ElementType::MatrixBlock:
                            break;

                        case ElementType::Tag:
                            break;

                        case ElementType::Category: {
                            $cats = [];
                            if ($isBrand) {
                                // Because we can only have one brand, we'll get
                                // the very last category. This means if our
                                // brand is a sub-category, we'll get the child
                                // not the parent.
                                /** @var CategoryModel $cat */
                                foreach ($srcField as $cat) {
                                    $cats = [$cat->getTitle()];
                                }
                            } else {
                                // For every category, show its ancestors
                                // delimited by a slash.
                                /** @var CategoryModel $cat */
                                foreach ($srcField as $cat) {
                                    $name = $cat->getTitle();
                                    while ($cat = $cat->getParent()) {
                                        $name = $cat->getTitle() . "/" . $name;
                                    }
                                    $cats[] = $name;
                                }
                            }
                            // Join separate categories with a pipe.
                            $result = implode("|", $cats);
                            break;
                        }

                        default:
                            $result = strip_tags($srcField);
                            break;
                    }
                } else {
                    $result = strip_tags($srcField);
                }
            }
        }

        return $result;
    } /* -- _pullDataFromField */

}
