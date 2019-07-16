<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSoapWsseUsernameTokenField extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('soap_config', 'wsse_username_token')) {
            Schema::table(
                'soap_config',
                function (Blueprint $t){
                    $t->string('wsse_username_token')->default(0)->nullable();
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
        if (Schema::hasColumn('soap_config', 'wsse_username_token')) {
            Schema::table(
                'soap_config',
                function (Blueprint $t){
                    $t->dropColumn('wsse_username_token');
                }
            );
        }
    }
}
