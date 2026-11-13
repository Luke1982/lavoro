<?php

namespace Tests\Feature\TaskChecks;

use App\Actions\Tasks\CreateTaskInstanceAction;
use App\Actions\Tasks\RegisterTaskAssetsAction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTask;
use App\Models\ServiceOrderTaskCheck;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The werkbon PDF tells the customer which checks were done on which machine.
 * Checks nobody ticked stay off it.
 */
class PdfChecksTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    public function test_the_pdf_lists_the_ticked_checks_per_machine(): void
    {
        if (!is_executable('/usr/bin/pdftotext')) {
            $this->markTestSkipped('pdftotext is not installed');
        }

        $template = ServiceOrderTask::create(['title' => 'Airco installatie']);
        foreach (['Rooster schoongemaakt', 'Uitleg gegeven aan klant'] as $position => $title) {
            $template->checks()->attach(ServiceOrderTaskCheck::create(['title' => $title])->id, [
                'is_required' => false,
                'sort_order' => $position + 1,
            ]);
        }

        $product = Product::factory()->create(['bundle' => false, 'registable' => true]);
        $order = ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $instance = app(CreateTaskInstanceAction::class)->execute([
            'service_order_id' => $order->id,
            'service_order_task_id' => $template->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'checks_per_unit' => true,
        ]);
        app(RegisterTaskAssetsAction::class)->execute($instance, [[
            'product_id' => $product->id,
            'serial_number' => 'DK-2231-A',
            'container_index' => null,
            'unit_index' => 0,
        ]]);
        $instance->checks()->where('unit_index', 0)->where('title', 'Rooster schoongemaakt')->update([
            'is_done' => true,
            'checked_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get("/serviceorders/{$order->id}/export/pdf")->assertOk();

        $file = tempnam(sys_get_temp_dir(), 'werkbon');
        file_put_contents($file, $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : $response->getContent());
        $text = preg_replace('/\s+/', ' ', (string) shell_exec('/usr/bin/pdftotext -layout ' . escapeshellarg($file) . ' -'));
        unlink($file);

        $this->assertStringContainsString('Machine 1 (DK-2231-A): ✓ Rooster schoongemaakt', $text);
        $this->assertStringNotContainsString('Uitleg gegeven aan klant', $text);
        $this->assertStringNotContainsString('Machine 2', $text);
    }
}
