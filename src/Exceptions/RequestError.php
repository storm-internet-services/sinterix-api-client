<?php

namespace Sinterix\Exceptions;

class RequestError extends \Exception
{
    public function __construct(string $message) {
        parent::__construct($message, 400);
    }
}