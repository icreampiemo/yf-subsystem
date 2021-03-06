<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class IqcInspectionsRq extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('iqc_inspections_rq', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ctrl_no_rq');
            $table->string('partcode_rq');
            $table->string('partname_rq');
            $table->string('supplier_rq');   
            $table->string('app_date_rq');
            $table->string('app_time_rq');
            $table->string('app_no_rq');
            $table->string('lot_no_rq');
            $table->integer('lot_qty_rq',false, true)->length(20);
            $table->string('date_ispected_rq');
            $table->string('ww_rq');
            $table->string('fy_rq');
            $table->string('shift_rq');
            $table->string('time_ins_from_rq');
            $table->string('time_ins_to_rq');
            $table->string('inspector_rq');
            $table->string('submission_rq');
            $table->string('judgement_rq');
            $table->string('lot_inspected_rq');
            $table->string('lot_accepted_rq');
            $table->integer('no_of_defects_rq',false, true)->length(20);
            $table->string('remarks_rq');
            $table->string('dbcon_rq');       
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
        //
    }
}
