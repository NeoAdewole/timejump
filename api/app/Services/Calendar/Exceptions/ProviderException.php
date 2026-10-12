<?php

namespace App\Services\Calendar\Exceptions;

use RuntimeException;

/** A calendar provider could not be reached or returned an error. */
class ProviderException extends RuntimeException {}
