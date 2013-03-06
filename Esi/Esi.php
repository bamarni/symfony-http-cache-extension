<?php

namespace Bamarni\HttpCache\Esi;

use Symfony\Component\HttpKernel\HttpCache\Esi as BaseEsi;

class Esi extends BaseEsi
{
    public function createCacheStrategy()
    {
        return new EsiResponseCacheStrategy();
    }
}
