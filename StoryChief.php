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
        if (request('key') === $this->getConfig('key') && $this->getConfig('key') !== null) {
            return true;
        } else {
            return false;
        }
    }
}
