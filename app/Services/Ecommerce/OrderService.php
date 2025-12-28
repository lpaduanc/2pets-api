<?php

namespace App\Services\Ecommerce;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrderService
{
    public function createFromCart(Cart $cart, array $shippingAddress): Order
    {
        if ($cart->items->isEmpty()) {
            throw new \Exception('Cart is empty');
        }

        return DB::transaction(function () use ($cart, $shippingAddress) {
            // Check stock availability
            foreach ($cart->items as $item) {
                if (!$item->product->hasStock($item->quantity)) {
                    throw new \Exception("Product {$item->product->name} is out of stock");
                }
            }

            $order = Order::create([
                'user_id' => $cart->user_id,
                'professional_id' => $cart->professional_id,
                'order_number' => $this->generateOrderNumber(),
                'status' => 'pending',
                'subtotal' => $cart->subtotal,
                'tax' => $cart->tax,
                'shipping' => 0, // Calculate based on address/carrier
                'total' => $cart->total,
                'shipping_address' => $shippingAddress,
            ]);

            // Create order items and decrement stock
            foreach ($cart->items as $cartItem) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $cartItem->product_id,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $cartItem->unit_price,
                    'total_price' => $cartItem->total_price,
                ]);

                $cartItem->product->decrementStock($cartItem->quantity);
            }

            $cart->clear();

            return $order->load('items.product');
        });
    }

    public function confirmOrder(Order $order): void
    {
        $order->update(['status' => 'confirmed']);
    }

    public function cancelOrder(Order $order): void
    {
        $order->cancel();
    }

    private function generateOrderNumber(): string
    {
        return '2P-' . date('Ymd') . '-' . strtoupper(Str::random(8));
    }
}

