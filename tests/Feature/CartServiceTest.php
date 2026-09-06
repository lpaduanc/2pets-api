<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Ecommerce\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre a correcao de `CartService::addItem`: o incremento de quantidade
 * deixou de usar `DB::raw("quantity + {$quantity}")` (interpolacao de
 * variavel em SQL cru) e passou a somar via lockForUpdate + Eloquent,
 * sempre parametrizado.
 */
class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    private CartService $cartService;

    private User $tutor;

    private Product $product;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cartService = app(CartService::class);
        $this->tutor = User::factory()->tutor()->create();

        $professionalUser = User::factory()->professional()->create();

        $this->product = Product::create([
            'professional_id' => $professionalUser->id,
            'name' => 'Ração Premium',
            'sku' => 'RACAO-001',
            'price' => 100.00,
            'stock_quantity' => 50,
            'track_inventory' => true,
            'is_active' => true,
        ]);

        $this->cart = $this->cartService->getOrCreateCart($this->tutor, $professionalUser->id);
    }

    public function test_adding_an_item_creates_it_with_the_requested_quantity(): void
    {
        $cartItem = $this->cartService->addItem($this->cart, $this->product, 3);

        $this->assertSame(3, $cartItem->quantity);
        $this->assertEquals(300.00, (float) $cartItem->total_price);
    }

    public function test_adding_the_same_product_twice_sums_the_quantity_instead_of_overwriting(): void
    {
        $this->cartService->addItem($this->cart, $this->product, 2);
        $cartItem = $this->cartService->addItem($this->cart, $this->product, 5);

        $this->assertSame(7, $cartItem->quantity);
        $this->assertEquals(700.00, (float) $cartItem->total_price);
        $this->assertSame(1, CartItem::where('cart_id', $this->cart->id)->count());
    }

    public function test_adding_more_than_available_stock_is_rejected(): void
    {
        $this->expectException(\Exception::class);

        $this->cartService->addItem($this->cart, $this->product, 999);
    }
}
