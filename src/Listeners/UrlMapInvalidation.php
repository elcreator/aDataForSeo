<?php

namespace Elcreator\aDataForSeo\Listeners;

use Elcreator\aDataForSeo\Support\UrlMap;

/** A saved, (un)published or deleted document changes which URL is which page. */
final class UrlMapInvalidation
{
    public static function handle($params = []): void
    {
        UrlMap::invalidate();
    }
}
