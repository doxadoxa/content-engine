<?php

declare(strict_types=1);

namespace App\Blog;

use RuntimeException;

/**
 * A delivery the blog will not store, and will not store on a retry either.
 *
 * Answered with 422, which the engine dead-letters where the operator sees
 * it. Thrown rather than returned so that it unwinds the transaction holding
 * the delivery's claim: a refused request leaves nothing behind.
 */
final class RefusedDelivery extends RuntimeException {}
