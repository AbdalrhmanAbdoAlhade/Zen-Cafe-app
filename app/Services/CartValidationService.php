<?php

namespace App\Services;

use App\Exceptions\MixedCartException;

class CartValidationService
{
    /**
     * يتأكد إن كل عناصر السلة من نوع واحد فقط - إما منتجات منيو (menu_item_id)
     * أو منتجات متجر (product_variant_id) - مش خليط بينهم في نفس الطلب.
     */
    public function assertNotMixed(array $items): void
    {
        $hasMenuItems = false;
        $hasStoreItems = false;

        foreach ($items as $item) {
            if (! empty($item['menu_item_id'])) {
                $hasMenuItems = true;
            }

            if (! empty($item['product_variant_id'])) {
                $hasStoreItems = true;
            }
        }

        if ($hasMenuItems && $hasStoreItems) {
            throw new MixedCartException();
        }
    }
}