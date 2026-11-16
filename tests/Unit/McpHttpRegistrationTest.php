<?php

use Elcreator\aDataForSeo\aDataForSeoServiceProvider;
use Illuminate\Container\Container;

it('registers MCP tools during an enabled frontend request', function () {
    $app = new Container();
    $events = Mockery::mock();
    $events->shouldReceive('listen')->once();
    $app->instance('events', $events);
    $provider = new class($app) extends aDataForSeoServiceProvider {
        public bool $registered = false;

        protected function inBackend(): bool { return false; }
        protected function registerMcpTools(): void { $this->registered = true; }
    };

    $provider->boot();

    expect($provider->registered)->toBeTrue();
    Mockery::close();
});
