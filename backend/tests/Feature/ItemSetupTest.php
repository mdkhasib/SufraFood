<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Sale items (menu) and raw materials / stock are separate; product photos; category suggestions. */
class ItemSetupTest extends GroupOpsTestCase
{
    public function test_sale_items_and_raw_materials_are_separate_pages(): void
    {
        $this->actingAs($this->u('E-1002'));
        $this->get(route('home'))->assertSee('Sale items (menu)')->assertSee('Raw materials &amp; stock', false);
        $this->get(route('crud.index', 'menu_items'))->assertOk()->assertSee('Chicken Biryani')->assertDontSee('Onion')->assertDontSee('Ghee');
        $this->get(route('crud.index', 'items'))->assertOk()->assertSee('Onion')->assertSee('Vegetables')->assertSee('Meat &amp; poultry', false)->assertDontSee('Butter Naan');

        // a raw material cannot be opened, changed or deleted from the sale-items page (and the other way round)
        $onion = Item::where('code', 'RM-004')->first();
        $naan = Item::where('code', 'MN-005')->first();
        $this->get(route('crud.edit', ['menu_items', $onion->id]))->assertNotFound();
        $this->put(route('crud.update', ['menu_items', $onion->id]), ['code' => 'RM-004', 'name' => 'Onion', 'sale_price' => 50, 'unit' => 'kg'])->assertNotFound();
        $this->delete(route('crud.destroy', ['items', $naan->id]))->assertNotFound();
        $this->assertSame('RAW', $onion->fresh()->type);

        // the raw-material form has no "menu item" type, and refuses one
        $this->get(route('crud.create', 'items'))->assertOk()->assertDontSee('Menu item (POS)')->assertDontSee('Sale price');
        $this->post(route('crud.store', 'items'), ['code' => 'RM-090', 'name' => 'Basmati', 'type' => 'MENU', 'unit' => 'kg'])->assertSessionHasErrors('type');
        $this->post(route('crud.store', 'items'), ['code' => 'RM-090', 'name' => 'Basmati Rice', 'type' => 'RAW', 'category' => 'Rice & grains', 'unit' => 'kg', 'last_price' => 180])->assertSessionHasNoErrors();
        $this->assertSame('RAW', Item::where('code', 'RM-090')->value('type'));
    }

    public function test_menu_item_with_photo_category_suggestions_and_checks(): void
    {
        Storage::fake('public');
        $this->actingAs($this->u('E-1002'));
        $this->get(route('crud.create', 'menu_items'))->assertOk()
            ->assertSee('Product photo', false)->assertSee('<datalist id="dl-category">', false)->assertSee('Biryani &amp; Kacchi', false)->assertSee('Dessert')
            ->assertDontSee('name="type"', false);

        // sale price is required; the code must be new; posting type=RAW still makes a menu item
        $this->post(route('crud.store', 'menu_items'), ['code' => 'MN-050', 'name' => 'Beef Bhuna', 'unit' => 'plate', 'sale_price' => 0])->assertSessionHasErrors('sale_price');
        $this->post(route('crud.store', 'menu_items'), ['code' => 'MN-001', 'name' => 'Dup', 'unit' => 'plate', 'sale_price' => 100])->assertSessionHasErrors('code');
        $this->post(route('crud.store', 'menu_items'), [
            'code' => 'MN-050', 'name' => 'Beef Bhuna', 'name_bn' => 'বিফ ভুনা', 'category' => 'Platter', 'unit' => 'plate', 'sale_price' => 380, 'type' => 'RAW',
            'production_at' => 'BRANCH', 'active' => 1, 'image' => UploadedFile::fake()->image('bhuna.jpg', 600, 600),
        ])->assertSessionHasNoErrors();
        $it = Item::where('code', 'MN-050')->first();
        $this->assertSame('MENU', $it->type);
        $this->assertSame('Platter', $it->category);
        Storage::disk('public')->assertExists($it->image);

        // the photo shows in the list, on the POS and on the delivery order menu; the new category is suggested next time
        $this->get(route('crud.index', 'menu_items'))->assertSee($it->imageUrl(), false);
        $this->get(route('crud.create', 'menu_items'))->assertSee('<option value="Platter">', false);
        $this->actingAs($this->u('E-2011'));
        $this->post(route('pos.open'), ['opening_cash' => 0]);
        $this->get(route('pos'))->assertOk()->assertSee(str_replace('/', '\/', $it->imageUrl()), false);

        // remove the photo
        $this->actingAs($this->u('E-1002'))->put(route('crud.update', ['menu_items', $it->id]), ['code' => 'MN-050', 'name' => 'Beef Bhuna', 'unit' => 'plate', 'sale_price' => 390, 'image__remove' => 1])->assertSessionHasNoErrors();
        $this->assertNull($it->fresh()->image);
        $this->assertEquals(390.0, $it->fresh()->sale_price);
    }

    public function test_menu_items_cannot_be_requested_or_bought_as_stock(): void
    {
        $this->api('E-2001');
        $codes = collect($this->getJson('/api/items')->assertOk()->json())->pluck('code');
        $this->assertTrue($codes->contains('RM-004'));
        $this->assertFalse($codes->contains('MN-001'));

        $this->postJson('/api/requisitions', ['type' => 'RAW_MATERIAL', 'lines' => [['item_id' => Item::where('code', 'MN-001')->value('id'), 'qty' => 5]]])
            ->assertStatus(422)->assertJsonPath('message', 'Chicken Biryani is a sale (menu) item. Request its raw materials or the central kitchen item instead.');
    }
}
