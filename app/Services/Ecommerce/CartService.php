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
        if (! $product->hasStock($quantity)) {
            throw new \Exception('Insufficient stock');
        }

        return DB::transaction(function () use ($cart, $product, $quantity) {
            $cartItem = $this->incrementOrCreateItem($cart, $product, $quantity);

            $cart->recalculate();

            return $cartItem;
        });
    }

    /**
     * Soma `$quantity` ao item existente (bloqueado com `lockForUpdate` para
     * evitar corrida entre duas requisicoes simultaneas) ou cria um novo.
     *
     * Nao usa mais `DB::raw("quantity + {$quantity}")`: mesmo com `$quantity`
     * tipado como int, interpolar variavel dentro de SQL cru e o padrao que
     * abre brecha de injecao em qualquer refactor futuro que afrouxe o tipo.
     * Os valores aqui sao sempre parametrizados pelo proprio Eloquent.
     */
    private function incrementOrCreateItem(Cart $cart, Product $product, int $quantity): CartItem
    {
        $existingItem = CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        $newQuantity = $quantity + ($existingItem->quantity ?? 0);

        return CartItem::updateOrCreate(
            [
                'cart_id' => $cart->id,
                'product_id' => $product->id,
            ],
            [
                'quantity' => $newQuantity,
                'unit_price' => $product->price,
                'total_price' => $product->price * $newQuantity,
            ]
        );
    }

    public function updateQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity <= 0) {
            $item->delete();
            $item->cart->recalculate();

            return;
        }

        if (! $item->product->hasStock($quantity)) {
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
