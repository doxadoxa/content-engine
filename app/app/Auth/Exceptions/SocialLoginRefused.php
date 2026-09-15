<?php

declare(strict_types=1);

namespace App\Auth\Exceptions;

use RuntimeException;

/**
 * A sign-in that could not be completed, for a reason worth telling somebody.
 *
 * The message on this exception is shown verbatim on the sign-in screen, so
 * every one of them is written for the person reading it and none of them
 * mentions a provider subject, a token, or which of the two accounts involved
 * already existed. That last omission is not politeness: "there is already an
 * account for that address" answered to an unverified address is an account
 * enumeration oracle, and the whole reason this exception exists is to refuse
 * that case.
 */
final class SocialLoginRefused extends RuntimeException {}
