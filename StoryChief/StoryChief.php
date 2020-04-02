<?php namespace Statamic\Addons\StoryChief;

use Statamic\Extend\Extensible;

class StoryChief
{
    use Extensible;


    public function checkAuth()
    {
        if (!$this->isAuth()) {
            header("HTTP/1.1 401 Unauthorized");
            exit;
        }
    }

    public function isAuth()
    {
        return hash_hmac('sha256', json_encode(request()->except('meta.mac')), $this->getConfig('key')) === request('meta.mac');
    }
}
