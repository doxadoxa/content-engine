<?php

declare(strict_types=1);

namespace App\ContentStudio;

use RuntimeException;

/** A safe, operator-actionable failure in the Content Studio workflow. */
class ContentStudioException extends RuntimeException
{
    // Named base type prevents arbitrary provider or database exceptions from
    // being exposed merely because they also extend RuntimeException.
}
