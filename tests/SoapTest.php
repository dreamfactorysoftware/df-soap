<?php

use DreamFactory\Library\Utility\Enums\Verbs;

class SoapTest extends \DreamFactory\Core\Testing\TestCase
{
    protected static $staged = false;

    public function stage()
    {
        parent::stage();

        Artisan::call('migrate', ['--path' => 'vendor/dreamfactory/df-rws/database/migrations/']);
        Artisan::call('db:seed', ['--class' => DreamFactory\Core\Rws\Database\Seeds\DatabaseSeeder::class]);

        if (!$this->serviceExists('gmap')) {
            \DreamFactory\Core\Models\Service::create(
                [
                    "name"   => "gmap",
                    "type"   => "rws",
                    "label"  => "Remote web service",
                    "config" => [
                        "base_url"      => "http://maps.googleapis.com/maps/api/directions/json",
                        "cache_enabled" => false,
                        "parameters"    => [
                            [
                                "name"      => "origin",
                                "value"     => "5965 Willow Oak Pass, Cumming, GA 30040",
                                "outbound"  => true,
                                "cache_key" => true,
                                "action"    => 31
                            ],
                            [
                                "name"      => "destination",
                                "value"     => "3600 Mansell Rd. Alpharetta, GA",
                                "outbound"  => true,
                                "cache_key" => true,
                                "action"    => 31
                            ]
                        ]
                    ]
                ]
            );
        }

        if (!$this->serviceExists('dsp-tester')) {
            \DreamFactory\Core\Models\Service::create(
                [
                    "name"   => "dsp-tester",
                    "type"   => "rws",
                    "label"  => "Remote web service",
                    "config" => [
                        "base_url"      => "https://dsp-tester.cloud.dreamfactory.com/rest",
                        "cache_enabled" => false,
                        "headers"       => [
                            [
                                "name"             => "Authorization",
                                "value"            => "Basic YXJpZmlzbGFtQGRyZWFtZmFjdG9yeS5jb206dGVzdCEyMzQ=",
                                "pass_from_client" => false,
                                "action"           => 31
                            ],
                            [
                                "name"             => "X-DreamFactory-Application-Name",
                                "value"            => "admin",
                                "pass_from_client" => false,
                                "action"           => 31
                            ],
                            [
                                "name"             => "X-HTTP-Method",
                                "pass_from_client" => true,
                                "action"           => 31
                            ]
                        ]
                    ]
                ]
            );
        }
    }

    public function testGETGmapDirection()
    {
        $rs = $this->call(Verbs::GET, $this->prefix . '/gmap');
        $this->assertContains('{"routes":[{"bounds":{"northeast":{"lat":34.1951083,"lng":-84.2175458}',
            $rs->getContent());
    }

    public function testGETheaders()
    {
        $rs = $this->call(Verbs::GET, $this->prefix . '/dsp-tester');
        $this->assertEquals('{"service":[{"name":"Database","api_name":"db"},{"name":"Email Service","api_name":"email"},{"name":"Local File Storage","api_name":"files"},{"name":"Local Portal Service","api_name":"portal"}]}',
            $rs->getContent());
    }
}