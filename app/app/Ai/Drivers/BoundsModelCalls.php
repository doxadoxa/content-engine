<?php

declare(strict_types=1);

namespace App\Ai\Drivers;

use GuzzleHttp\Client;

/**
 * The deadline, and the one place it is decided.
 *
 * Every LarAgent driver builds its own HTTP client and every one of them does
 * it without a timeout — `OpenAI::client($key)` discovers Guzzle with Guzzle's
 * defaults, and `OpenAiCompatible`, `ClaudeDriver` and the native Gemini driver
 * each write `new Client([])` in as many words. Guzzle's default `timeout` is
 * `0`, which means wait for ever.
 *
 * So each provider gets a three-line subclass in this namespace whose only job
 * is to hand its SDK a client that has one. They share this trait rather than a
 * base class because the drivers themselves have four different shapes — two
 * abstract parents, a Groq SDK that wants milliseconds, and a Gemini driver
 * that builds Guzzle directly — and there is no single place above them to sit.
 *
 * BoundedDriversTest asserts that every provider in config/laragent.php uses one
 * of these. That test is the actual guarantee; the classes are just how it is
 * kept true.
 */
trait BoundsModelCalls
{
    /**
     * What the HTTP client is told about waiting.
     *
     * `timeout` covers the whole request and is deliberately generous: a model
     * writing two thousand words legitimately takes a while, and a deadline
     * that cuts real work short is worse than the hang it replaces — the tokens
     * are billed either way and the retry pays again. What matters is that it
     * sits far below the worker timeout, so the pipeline records a failure
     * instead of the process being killed with nothing written down.
     *
     * `connect_timeout` is short and separate. Failing to *reach* a provider
     * says nothing about how long its answers take, and ten seconds of DNS and
     * TLS is already an unwell network.
     *
     * @return array<string, mixed>
     */
    public static function httpOptions(): array
    {
        return [
            'timeout' => (float) config('models.timeout', 300),
            'connect_timeout' => (float) config('models.connect_timeout', 10),
        ];
    }

    /** The same deadline in milliseconds, for an SDK that asks in those. */
    public static function timeoutMilliseconds(): int
    {
        return (int) round(self::httpOptions()['timeout'] * 1000);
    }

    /**
     * @param  array<string, mixed>  $options  merged over the deadline, never under it
     */
    protected static function boundedClient(array $options = []): Client
    {
        return new Client([...self::httpOptions(), ...$options]);
    }
}
