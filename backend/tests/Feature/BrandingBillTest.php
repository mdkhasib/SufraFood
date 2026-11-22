<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PaymentMethod;
use App\Support\Features;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Logo & bill settings, POS bill print, the app download footer and the setup guide. */
class BrandingBillTest extends GroupOpsTestCase
{
    protected function paidOrder(): array
    {
        $this->actingAs($this->u('E-2011'));
        $this->post(route('pos.open'), ['opening_cash' => 1000]);
        $o = $this->postJson(route('pos.store'), ['order_type' => 'DINE_IN', 'customer_phone' => '01711222333', 'lines' => [['item_id' => Item::where('code', 'MN-001')->value('id'), 'qty' => 2]]])->json('order');
        $this->postJson(route('pos.pay', $o['id']), ['payments' => [['payment_method_id' => PaymentMethod::where('name', 'Cash')->value('id'), 'amount' => 700]]])->assertOk();

        return $o;
    }

    public function test_logo_upload_and_bill_settings_are_super_admin_company_settings(): void
    {
        Storage::fake('public');
        $sa = $this->u('SA-0001');
        $this->actingAs($sa)->get(route('setup.settings'))->assertOk()->assertSee('Logo & bill (POS)')->assertSee('Printer paper width')->assertSee('Mobile app');

        $this->post(route('setup.settings.save'), [
            'company__logo' => UploadedFile::fake()->image('logo.png', 300, 120),
            'company__tagline' => 'Best kacchi in town',
            'receipt__paper' => '58',
            'receipt__copies' => '2',
            'receipt__footer' => 'Come again!',
            'receipt__show_bn_names__present' => 1, 'receipt__show_bn_names' => 1,
            'receipt__show_cashier__present' => 1, // unticked → off
        ])->assertSessionHas('ok');
        Features::flush();
        $logo = Features::setting('company.logo');
        $this->assertNotEmpty($logo);
        Storage::disk('public')->assertExists($logo);
        $this->assertSame('58', (string) Features::setting('receipt.paper'));
        $this->assertSame(0, (int) Features::setting('receipt.show_cashier'));
        $this->assertNotNull(\App\Support\Brand::logoUrl());

        // preview renders the logo inline and the 58 mm width
        $this->get(route('setup.bill.preview'))->assertOk()->assertSee('data:image/png;base64', false)->assertSee('48mm', false)->assertSee('Best kacchi in town');

        // the GM (company admin) cannot change it until the Super Admin allows it
        $this->actingAs($this->u('E-1002'))->get(route('setup.settings'))->assertForbidden();
        $this->get(route('setup.bill.preview'))->assertForbidden();

        // remove the logo
        $this->actingAs($sa)->post(route('setup.settings.save'), ['company__logo__remove' => 1])->assertSessionHas('ok');
        Features::flush();
        $this->assertSame('', (string) Features::setting('company.logo'));
    }

    public function test_pos_bill_uses_settings_and_shows_change(): void
    {
        $this->setting('company.vat_reg', '000123456-0101');
        $this->setting('receipt.copies', 2);
        $this->setting('receipt.show_bn_names', 1);
        $o = $this->paidOrder();

        $html = $this->get(route('pos.receipt', $o['id']).'?tendered=700')->assertOk()
            ->assertSee('Sufra Food Ltd')->assertSee('000123456-0101')->assertSee('Customer copy')->assertSee('Office copy')
            ->assertSee('01711222333')->assertSee('Change to return')->assertSee('28.00')
            ->assertSee(Item::where('code', 'MN-001')->value('name_bn'))
            ->assertSee('01800-', false) // branch phone
            ->getContent();
        $this->assertStringContainsString('window.print()', $html);

        // settings turn parts off
        $this->setting('receipt.show_vat_reg', 0);
        $this->setting('receipt.show_customer', 0);
        $this->setting('receipt.copies', 1);
        $this->get(route('pos.receipt', $o['id']))->assertOk()->assertDontSee('000123456-0101')->assertDontSee('01711222333')->assertDontSee('Office copy');
    }

    public function test_unpaid_bill_and_other_branch_cannot_print(): void
    {
        $this->actingAs($this->u('E-2011'));
        $this->post(route('pos.open'), ['opening_cash' => 0]);
        $o = $this->postJson(route('pos.store'), ['order_type' => 'TAKEAWAY', 'lines' => [['item_id' => Item::where('code', 'MN-006')->value('id'), 'qty' => 1]]])->json('order');
        $this->get(route('pos.receipt', $o['id']))->assertOk()->assertSee('BILL · not paid yet');

        // a Dhanmondi branch manager cannot open a Gulshan bill
        $this->actingAs($this->u('E-3001'))->get(route('pos.receipt', $o['id']))->assertForbidden();
    }

    public function test_footer_download_link_and_setup_guide(): void
    {
        $dir = public_path('downloads');
        $apk = $dir.'/sufra-groupops.apk';
        $made = false;
        if (! file_exists($apk)) {
            @mkdir($dir, 0777, true);
            file_put_contents($apk, 'test');
            $made = true;
        }
        try {
            // login page footer
            $this->get(route('login'))->assertOk()->assertSee('Download Android app')->assertSee(route('app.guide', ['download' => 1]), false)->assertSee('How to set up the app');
            // app layout footer
            $this->actingAs($this->u('E-2008'))->get(route('home'))->assertOk()->assertSee('Download Android app')->assertSee('App setup guide');
            auth()->logout();

            // guide page: public, starts the download and shows the server address with examples
            $this->setting('app.server_url', 'http://192.168.1.50:8090/');
            $this->get(route('app.guide', ['download' => 1]))->assertOk()
                ->assertSee('Step 3')->assertSee('http://192.168.1.50:8090')->assertDontSee('http://192.168.1.50:8090/app', false)
                ->assertSee('https://ops.sufrafood.com')->assertSee(url('/download/app'), false)->assertSee('window.location.href', false);
            $this->get(route('app.guide'))->assertOk()->assertDontSee('window.location.href', false);

            // the app reads company name + logo before login (also its "Test connection")
            $this->getJson('/api/branding')->assertOk()->assertJsonPath('name', 'Sufra Food Ltd')->assertJsonStructure(['name', 'logo', 'initials']);
        } finally {
            if ($made) {
                @unlink($apk);
            }
        }
    }

    public function test_branch_phone_editable_on_locations(): void
    {
        $this->actingAs($this->u('E-1002'));
        $gul = \App\Models\Location::where('code', 'BR-GUL')->first();
        $this->get(route('crud.edit', ['locations', $gul->id]))->assertOk()->assertSee('Branch phone (printed on bills)');
    }
}
