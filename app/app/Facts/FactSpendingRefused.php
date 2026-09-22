<?php

declare(strict_types=1);

namespace App\Facts;

use RuntimeException;

/** A pre-request refusal, so this is explicitly not an unknown paid attempt. */
final class FactSpendingRefused extends RuntimeException {}
