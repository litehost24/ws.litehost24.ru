<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('ip1')->nullable();
            $table->string('username1')->nullable();
            $table->string('password1')->nullable();
            $table->string('webwasepath1')->nullable(); // Assuming this is a typo for 'webbasepath1'
            $table->string('url1')->nullable();
            $table->string('ip2')->nullable();
            $table->string('username2')->nullable();
            $table->string('password2')->nullable();
            $table->string('webwasepath2')->nullable(); // Assuming this is a typo for 'webbasepath2'
            $table->string('url2')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('servers');
    }
};