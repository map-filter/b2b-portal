<?php

namespace Tests\Feature\Api;

use App\Events\OrderPlaced;
use App\Listeners\GenerateInvoice;
use App\Listeners\NotifyManagerAboutOrder;
use App\Mail\NewOrderMail;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

class ImportAndInvoiceTest extends ApiTestCase
{
    public function test_csv_import_adds_known_skus_and_reports_problems(): void
    {
        $this->product(['sku' => 'DEMO-1']);
        $this->product(['sku' => 'DEMO-2']);
        Sanctum::actingAs($this->buyer());

        $csv = "Артикул;Количество\ndemo-1;5\nDEMO-1;3\nNOPE;1\nDEMO-2;abc\n";
        $file = UploadedFile::fake()->createWithContent('order.csv', $csv);

        $this->post('/api/cart/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('added', 1)
            ->assertJsonPath('not_found.0.sku', 'NOPE')
            ->assertJsonPath('invalid.0.row', 5);

        $this->getJson('/api/cart')->assertJsonPath('items.0.quantity', 8);
    }

    public function test_template_is_xlsx(): void
    {
        Sanctum::actingAs($this->buyer());

        $this->get('/api/cart/import/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_placed_order_gets_invoice_and_manager_email(): void
    {
        Storage::fake();
        Mail::fake();
        $manager = User::factory()->staff()->create();
        $company = $this->clientCompany(['manager_id' => $manager->id]);
        $product = $this->product();
        Sanctum::actingAs($this->buyer($company));

        $this->putJson("/api/cart/items/{$product->id}", ['quantity' => 2]);
        $orderId = $this->postJson('/api/orders')->assertCreated()->json('data.id');
        $number = $this->getJson("/api/orders/{$orderId}")->json('data.number');

        Storage::assertExists("invoices/{$number}.pdf");
        Mail::assertSent(NewOrderMail::class, fn (NewOrderMail $mail) => $mail->hasTo($manager->email));

        $this->get("/api/orders/{$orderId}/invoice")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_order_placed_listeners_are_queued(): void
    {
        Event::fake();

        Event::assertListening(OrderPlaced::class, GenerateInvoice::class);
        Event::assertListening(OrderPlaced::class, NotifyManagerAboutOrder::class);
    }
}
