<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\ReferenceCheck;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The worst thing this assistant has done.
 *
 * Asked for a Mitsubishi airco it searched "MSZ" — the naming Mitsubishi uses in
 * the world, not the one this catalogue uses — found nothing, said there were no
 * aircos, and when told otherwise produced six model numbers against product ids
 * 12 to 17: a Vent-Axia fan, a Renson ventilation box, a Honeywell thermostat, a
 * Vasco unit, a Vortice fan and a Berker light switch. Invented whole, pinned to
 * real ids, with working links. Choosing one would have put a ventilation grille
 * on a werkbon as an air conditioner.
 *
 * Two things had to be wrong for that: a dead end that said nothing about what
 * would have worked, and nothing checking that a named record was ever looked up.
 */
class InventedRecordTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function check(): ReferenceCheck
    {
        return app(ReferenceCheck::class);
    }

    public function test_a_record_no_tool_returned_is_reported(): void
    {
        $answer = 'Ik zie **Mitsubishi MSZ-AP25VGK** ([product #12](/products/12)) en [#15](/products/15).';

        $this->assertSame(
            ['products #12', 'products #15'],
            $this->check()->unverifiedIn($answer, ['{"products":[]}']),
        );
    }

    public function test_a_record_a_tool_did_return_passes(): void
    {
        $answer = 'Zie [#12](/products/12).';

        $this->assertSame([], $this->check()->unverifiedIn($answer, ['{"products":[{"id":12,"name":"Vent-Axia"}]}']));
    }

    /**
     * Loose on purpose — an id counts if it appears anywhere — but not so loose
     * that 12 is found inside 128 and accepted on the strength of another record.
     */
    public function test_an_id_inside_a_longer_number_does_not_count(): void
    {
        $answer = 'Zie [#12](/products/12).';

        $this->assertSame(
            ['products #12'],
            $this->check()->unverifiedIn($answer, ['{"products":[{"id":128}]}']),
        );
    }

    public function test_an_answer_with_no_records_in_it_is_left_alone(): void
    {
        $this->assertSame([], $this->check()->unverifiedIn('Er staat niets gepland voor morgen.', []));
    }

    /**
     * The dead end that started it. Nothing found has to say what would be found,
     * or there is nothing left but to guess.
     */
    public function test_an_empty_product_search_names_the_brands_and_types_that_do_exist(): void
    {
        $type = ProductType::factory()->create(['name' => 'Airco binnendeel']);
        $brand = Brand::factory()->create(['name' => 'Mitsubishi']);
        Product::factory()->create(['brand_id' => $brand->id, 'product_type_id' => $type->id, 'model' => 'SRK 25 ZS-W']);

        $result = app(ToolExecutor::class)->run(new ToolCall(
            'find_products',
            ['query' => 'MSZ Mitsubishi', 'product_type' => 'Airco'],
            $this->userWith('product.read'),
        ));

        $this->assertSame([], $result->content['products']);
        $this->assertContains('Mitsubishi', $result->content['brands_that_do_exist']);
        /** Keyed by name with a count, so the assistant can say how much of each there is. */
        $this->assertArrayHasKey('Airco binnendeel', $result->content['types_that_do_exist']);
        $this->assertStringContainsString('Verzin nooit een product', $result->content['note']);

        /**
         * The candidates themselves, not merely the names of brands. "Een
         * Mitsubishi 2,5 kW" matches no text, because the capacity is inside the
         * model number — SRK 25 is the 2,5 kW one — and nobody can work that out
         * from a list of brand names.
         */
        $this->assertSame('SRK 25 ZS-W', $result->content['candidates'][0]['model']);
        $this->assertArrayHasKey('attributes', $result->content['candidates'][0]);
    }

    /**
     * The attributes an installation records travel with the product, and are
     * searchable text in their own right.
     *
     * Barely exercised here — the aircos in this database have none — and the main
     * way in on a real one, where a capacity or a connection size is filled in
     * properly. Which is exactly the combination that goes untested and then does
     * not work: the code path only runs where nobody is looking at it.
     */
    public function test_a_product_carries_its_recorded_attributes_and_can_be_found_by_them(): void
    {
        $product = Product::factory()->create([
            'brand_id' => Brand::factory()->create(['name' => 'Zehnder'])->id,
            'product_type_id' => ProductType::factory()->create(['name' => 'Afzuigunit'])->id,
            'model' => 'ComfoAir Q350',
        ]);

        $attribute = ProductAttribute::create(['name' => 'Afzuigcapaciteit', 'searchable' => true]);
        $value = ProductAttributeValue::create(['product_attribute_id' => $attribute->id, 'value' => '200 m3/h']);

        $product->productAttributeValueables()->create([
            'product_attribute_id' => $attribute->id,
            'product_attribute_value_id' => $value->id,
        ]);

        $byName = app(ToolExecutor::class)->run(new ToolCall(
            'find_products', ['query' => 'ComfoAir'], $this->userWith('product.read')
        ))->content;

        $this->assertSame(
            [['name' => 'Afzuigcapaciteit', 'value' => '200 m3/h']],
            $byName['products'][0]['attributes'],
            'the attributes never reached the answer',
        );

        $byCapacity = app(ToolExecutor::class)->run(new ToolCall(
            'find_products', ['query' => '200 m3/h'], $this->userWith('product.read')
        ))->content;

        $this->assertSame(
            $product->id,
            $byCapacity['products'][0]['id'],
            'a capacity that is recorded could not be searched for',
        );
    }

    /**
     * The links were checked and bare model numbers were not, so "probeer een
     * Mitsubishi SZX 25 ZS-W" went out unchallenged — invented on the spot, no link
     * to give it away, offered as a suggestion. On a werkbon that is a part nobody
     * can order.
     */
    public function test_a_model_number_no_tool_returned_is_reported(): void
    {
        $brands = collect(['Mitsubishi']);
        $results = ['{"products":[{"id":55,"model":"SRK 25 ZS-WF"}]}'];

        $this->assertSame(
            ['SZX 25 ZS-W'],
            $this->check()->inventedModelsIn('Probeer een Mitsubishi SZX 25 ZS-W.', $results, $brands),
        );

        $this->assertSame(
            [],
            $this->check()->inventedModelsIn('Het is de Mitsubishi SRK 25 ZS-WF.', $results, $brands),
            'a model that was actually returned was called invented',
        );
    }

    /**
     * A check that fires on ordinary prose gets switched off inside a week.
     */
    /**
     * Reported through report(), which is the path production takes.
     *
     * A cheap guard decides whether the brands are worth fetching, and a tighter
     * version of it skipped model numbers spread over words — the very thing this
     * is for — while still saving the query and looking like an optimisation.
     */
    public function test_the_check_still_fires_through_the_reporting_path(): void
    {
        Product::factory()->create([
            'brand_id' => Brand::factory()->create(['name' => 'Mitsubishi'])->id,
            'product_type_id' => ProductType::factory()->create(['name' => 'Airco'])->id,
            'model' => 'SRK 25 ZS-WF',
        ]);

        $this->assertSame(
            ['model SZX 25 ZS-W'],
            $this->check()->report('Probeer een Mitsubishi SZX 25 ZS-W.', ['{"products":[]}'], 1),
        );

        $this->assertSame(
            [],
            $this->check()->report(
                'Het is de Mitsubishi SRK 25 ZS-WF.',
                ['{"products":[{"model":"SRK 25 ZS-WF"}]}'],
                1,
            ),
        );
    }

    public function test_words_after_a_brand_name_are_not_model_numbers(): void
    {
        $brands = collect(['Mitsubishi']);

        foreach ([
            'Er zijn geen Mitsubishi buitendelen in het assortiment.',
            'Mitsubishi levert GEEN losse buitendelen.',
            'De Mitsubishi documentatie ontbreekt.',
        ] as $prose) {
            $this->assertSame([], $this->check()->inventedModelsIn($prose, ['{}'], $brands), $prose);
        }
    }

    /**
     * A type that exists and holds nothing is a fact worth being able to state.
     * Without it the assistant could not tell "none in stock" from "wrong search",
     * waffled, and then produced a list it had never been given.
     */
    public function test_a_product_type_that_exists_but_is_empty_says_so(): void
    {
        ProductType::factory()->create(['name' => 'Airco buitendeel']);
        $full = ProductType::factory()->create(['name' => 'Airco set']);
        Product::factory()->create([
            'brand_id' => Brand::factory()->create(['name' => 'Mitsubishi'])->id,
            'product_type_id' => $full->id,
        ]);

        $content = app(ToolExecutor::class)->run(new ToolCall(
            'find_products',
            ['query' => 'buitendeel', 'product_type' => 'Airco buitendeel'],
            $this->userWith('product.read'),
        ))->content;

        $this->assertSame(['Airco buitendeel'], $content['types_that_are_empty']);
        $this->assertSame(['Airco set' => 1], $content['types_that_do_exist']);
        $this->assertStringContainsString('geen producten in', $content['note']);
    }

    public function test_a_search_that_finds_something_says_nothing_about_what_it_did_not(): void
    {
        $type = ProductType::factory()->create(['name' => 'Airco binnendeel']);
        $brand = Brand::factory()->create(['name' => 'Mitsubishi']);
        Product::factory()->create(['brand_id' => $brand->id, 'product_type_id' => $type->id, 'model' => 'SRK 25 ZS-W']);

        $result = app(ToolExecutor::class)->run(new ToolCall(
            'find_products',
            ['query' => 'Mitsubishi'],
            $this->userWith('product.read'),
        ));

        $this->assertNotEmpty($result->content['products']);
        $this->assertArrayNotHasKey('brands_that_do_exist', $result->content);
    }
}
