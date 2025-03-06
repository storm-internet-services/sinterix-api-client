<?php

namespace Sinterix;

class ValidationError extends RequestError
{

    public function __construct(
        public array $inputErrors
    ){
        $this->code = 422;
    }
}