<?php
namespace DreamFactory\Core\Rws\Database\Seeds;

use DreamFactory\Core\Database\Seeds\BaseModelSeeder;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Models\ServiceType;
use DreamFactory\Core\Soap\Models\SoapConfig;
use DreamFactory\Core\Soap\Services\Soap;

class DatabaseSeeder extends BaseModelSeeder
{
    protected $modelClass = ServiceType::class;

    protected $records = [
        [
            'name'           => 'soap',
            'class_name'     => Soap::class,
            'config_handler' => SoapConfig::class,
            'label'          => 'SOAP Service',
            'description'    => 'A service to handle SOAP Services',
            'group'          => ServiceTypeGroups::CUSTOM,
            'singleton'      => false
        ]
    ];
}