<?php

namespace App\Services\Calendar\Exceptions;

/** The stored OAuth grant is no longer valid; the user must reconnect the account. */
class ReauthorizationRequired extends ProviderException {}
