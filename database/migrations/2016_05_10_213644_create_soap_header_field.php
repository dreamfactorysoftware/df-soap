<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSoapHeaderField extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('soap_config', 'headers')) {
            Schema::table(
                'soap_config',
                function (Blueprint $t){
                    $t->text('headers')->nullable();
                }
            );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('soap_config', 'headers')) {
            Schema::table(
                'soap_config',
                function (Blueprint $t){
                    $t->dropColumn('headers');
                }
            );
        }
    }
}
