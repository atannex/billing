<?php

declare(strict_types=1);

namespace Atannex\Billing\Exceptions;

/**
 * The provider could not be reached or answered 5xx. Safe to fail over only
 * when nothing was accepted yet.
 */
class GatewayUnavailable extends GatewayException {}
