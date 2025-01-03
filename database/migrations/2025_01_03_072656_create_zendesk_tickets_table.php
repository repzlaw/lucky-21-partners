<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('zendesk_tickets', function (Blueprint $table) {
            $table->id('ticketid');
            $table->string('storeid')->nullable();
            $table->string('subject');
            $table->text('ticket_json');
            $table->enum('status', ['open', 'solved', 'closed']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zendesk_tickets');
    }
};
