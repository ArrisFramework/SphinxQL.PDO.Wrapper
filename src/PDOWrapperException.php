<?php

namespace Arris\Toolkit\SphinxQL;

class PDOWrapperException extends \RuntimeException
{
    public static function create($message, $args = [], $code = 0): PDOWrapperException
    {
        return new static(vsprintf($message, $args), $code);
    }

}