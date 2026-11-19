<?php

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Material;
use App\Models\Product;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes (offertes), the paid module 'quotes'.
 *
 * A line copies its description and prices from the catalogue the moment it is
 * added: a quote is what was offered, and a price changed in the catalogue next
 * month must not change a quote the customer already holds. The link back to
 * the product or material is kept for reporting and for turning an accepted
 * quote into work. It is a morph and has no foreign key: when the catalogue
 * item goes, the line keeps its copy and the link simply resolves to nothing.
 *
 * A line can hang under another: the materials that came along with a product
 * are its children, so removing the airco takes its piping with it.
 *
 * product_material_defaults is configuration, not consumption. It deliberately
 * is not the materiables pivot: a row there is material booked against a
 * werkbon and moves stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_number_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number');
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->string('title');
            $table->foreignIdFor(Customer::class)->constrained()->restrictOnDelete();
            $table->foreignIdFor(Location::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Contact::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Project::class)->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('Concept')->index();
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->text('intro_text')->nullable();
            $table->text('closing_text')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Quote::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(QuoteLine::class, 'parent_id')->nullable()->constrained('quote_lines')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->nullableMorphs('item');
            $table->unsignedInteger('position')->default(0);
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('vat_percent', 5, 2)->default(21);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->index(['quote_id', 'position']);
        });

        Schema::create('product_material_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Product::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Material::class)->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('prompt', 20)->default('ask');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_material_defaults');
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('quote_number_counters');
    }
};
