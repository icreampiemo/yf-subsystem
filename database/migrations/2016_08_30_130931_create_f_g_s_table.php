<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateFGSTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fgs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('date');
            $table->string('po_no');
            $table->string('device_name');
            $table->integer('qty',false, true)->length(20);
            $table->integer('total_num_of_lots',false, true)->length(20);
            $table->string('dbcon');
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
        Schema::drop('fgs');
    }
}
