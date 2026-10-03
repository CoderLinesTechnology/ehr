<?php

namespace App\Domain\Tenancy;

use LogicException;

/** An attempt to write a row into an organization other than the current one. */
final class TenantMismatch extends LogicException {}
