<?php

namespace App\Exceptions;

/** A business-rule violation shown to the user (422 on API, flash error on web). */
class BizException extends \RuntimeException
{
}
