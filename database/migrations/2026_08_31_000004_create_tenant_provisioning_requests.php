<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aanvragen om een tenant te maken of op te ruimen.
     *
     * Het beheerpaneel draait als lavoro_app en die mag met opzet geen
     * databases aanmaken; dat mag alleen lavoro_provisioner, die aan een eigen
     * Linux-gebruiker hangt. Het paneel legt daarom een aanvraag neer en een
     * worker die wél als de provisioner draait voert hem uit. Deze tabel is
     * die aanvraag, en tegelijk de plek waar te zien is dat het misging --
     * anders klikt iemand op "aanmaken" en gebeurt er stil niets.
     */
    public function up(): void
    {
        Schema::connection('central')->create('tenant_provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('status')->default('queued');
            $table->string('tenant_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('package_key')->nullable();
            $table->json('modules')->nullable();
            $table->text('error')->nullable();
            $table->string('generated_password')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_provisioning_requests');
    }
};
