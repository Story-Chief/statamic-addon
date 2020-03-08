<?php

namespace Statamic\Addons\StoryChief;

use Statamic\API\Config;
use Statamic\Extend\ServiceProvider;

class StoryChiefServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $excludes = Config::get('system.csrf_exclude', []);
        $actionUrl = $this->actionUrl('*');

        if (! in_array($actionUrl, $excludes)) {
            $excludes[] = $actionUrl;
            Config::set('system.csrf_exclude', $excludes);
            Config::save();
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
