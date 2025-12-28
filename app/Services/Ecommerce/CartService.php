<?php

namespace App\Services\Ecommerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CartService
{
    public function getOrCreateCart(User $user, int $professionalId): Cart
    {
        return Cart::firstOrCreate(
            [
                'user_id' => $user->id,
                'professional_id' => $professionalId,
            ],
            [
                'subtotal' => 0,
                'tax' => 0,
                'total' => 0,
            ]
        );
    }

    public function addItem(Cart $cart, Product $product, int $quantity = 1): CartItem
    {
        if (!$product->hasStock($quantity)) {
            throw new \Exception('Insufficient stock');
        }

        return DB::transaction(function () use ($cart, $product, $quantity) {
            $cartItem = CartItem::updateOrCreate(
                [
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => DB::raw("quantity + {$quantity}"),
                    'unit_price' => $product->price,
                    'total_price' => DB::raw("unit_price * quantity"),
                ]
            );

            $cartItem->refresh();
            $cart->recalculate();

            return $cartItem;
        });
    }

    public function updateQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity <= 0) {
            $item->delete();
            $item->cart->recalculate();
            return;
        }

        if (!$item->product->hasStock($quantity)) {
            throw new \Exception('Insufficient stock');
        }

        $item->updateQuantity($quantity);
    }

    public function removeItem(CartItem $item): void
    {
        $cart = $item->cart;
        $item->delete();
        $cart->recalculate();
    }

    public function clearCart(Cart $cart): void
    {
        $cart->clear();
    }
}

