<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Drivers\BoundedClaudeDriver;
use App\Ai\Drivers\BoundedGeminiDriver;
use App\Ai\Drivers\BoundedGroqDriver;
use App\Ai\Drivers\BoundedNativeGeminiDriver;
use App\Ai\Drivers\BoundedOllamaDriver;
use App\Ai\Drivers\BoundedOpenAiCompatible;
use App\Ai\Drivers\BoundedOpenAiDriver;
use App\Ai\Drivers\BoundedOpenAiResponsesDriver;
use App\Ai\Drivers\BoundedOpenRouterDriver;
use App\Ai\Drivers\BoundsModelCalls;
use GuzzleHttp\Client;
use LarAgent\Core\DTO\DriverConfig;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Every configured provider bounds its calls.
 *
 * This is the actual guarantee; the nine small classes in `App\Ai\Drivers` are
 * only how it is kept true. Every LarAgent driver builds its own HTTP client
 * and not one of them sets a timeout — so the failure mode is not "a driver has
 * a bug", it is "a provider was added and nobody remembered". A list of drivers
 * checked by hand would have exactly the same problem as the config it checks.
 *
 * `default_driver` is included deliberately: it is what a provider that names
 * no driver of its own inherits, which is the quietest way for an unbounded
 * client to arrive.
 */
final class BoundedDriversTest extends TestCase
{
    #[Test]
    public function every_provider_uses_a_driver_that_bounds_its_calls(): void
    {
        $providers = (array) config('laragent.providers', []);

        $this->assertNotSame([], $providers);

        foreach ($providers as $name => $provider) {
            $driver = $provider['driver'] ?? config('laragent.default_driver');

            $this->assertTrue(
                $this->bounds((string) $driver),
                "The `{$name}` provider uses {$driver}, which does not bound its HTTP calls. "
                    .'LarAgent\'s drivers build their client with no timeout, so one unresponsive '
                    .'provider holds a worker until the worker itself is killed. Wrap it the way the '
                    .'others in App\\Ai\\Drivers are.',
            );
        }
    }

    #[Test]
    public function the_default_driver_bounds_its_calls(): void
    {
        // What a provider added later without a `driver` key inherits.
        $this->assertTrue($this->bounds((string) config('laragent.default_driver')));
    }

    #[Test]
    public function each_driver_hands_its_sdk_a_client_carrying_the_deadline(): void
    {
        config()->set('models.timeout', 111);
        config()->set('models.connect_timeout', 9);

        // Constructed for real, so a subclass whose override never runs — a
        // wrong method name, a parent that stopped calling it — fails here.
        foreach ($this->constructibleDrivers() as $name => $driver) {
            $client = $this->guzzleInside($driver);

            $this->assertNotNull($client, "{$name} built no Guzzle client to inspect.");
            $this->assertSame(111.0, $client['timeout'] ?? null, "{$name} did not carry the deadline.");
            $this->assertSame(9.0, $client['connect_timeout'] ?? null, "{$name} did not carry the connect timeout.");
        }
    }

    #[Test]
    public function the_groq_deadline_is_converted_to_the_milliseconds_its_sdk_wants(): void
    {
        config()->set('models.timeout', 111);

        // The one provider whose SDK does not take a Guzzle client. A number in
        // the wrong unit here is a deadline a thousand times too short or too
        // long, and nothing would say so.
        $this->assertSame(111_000, BoundedGroqDriverProbe::milliseconds());
    }

    private function bounds(string $driver): bool
    {
        if (! class_exists($driver)) {
            return false;
        }

        /** @var class-string $driver */
        return in_array(BoundsModelCalls::class, $this->traitsOf($driver), true);
    }

    /**
     * @param  class-string  $class
     * @return list<class-string>
     */
    private function traitsOf(string $class): array
    {
        $traits = [];

        for ($current = new ReflectionClass($class); $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getTraitNames() as $trait) {
                $traits[] = $trait;

                // A trait that uses another — BoundsOpenAiCompatibleClient uses
                // BoundsModelCalls — still counts as bounding.
                foreach (class_uses($trait) ?: [] as $nested) {
                    $traits[] = $nested;
                }
            }
        }

        return $traits;
    }

    /**
     * The drivers that can be built with a fake key, and their Guzzle options.
     *
     * Groq is absent because its SDK takes no Guzzle client — it has its own
     * test above — and the responses driver is included because its client is
     * replaced after the parent has already built an unbounded one, which is
     * the fiddliest of the nine and the most worth checking.
     *
     * @return array<string, object>
     */
    private function constructibleDrivers(): array
    {
        $settings = new DriverConfig(apiKey: 'sk-test', apiUrl: 'https://example.test/v1');

        $drivers = [];

        foreach ([
            'openai' => BoundedOpenAiDriver::class,
            'compatible' => BoundedOpenAiCompatible::class,
            'gemini' => BoundedGeminiDriver::class,
            'gemini_native' => BoundedNativeGeminiDriver::class,
            'ollama' => BoundedOllamaDriver::class,
            'openrouter' => BoundedOpenRouterDriver::class,
            'claude' => BoundedClaudeDriver::class,
            'openai_responses' => BoundedOpenAiResponsesDriver::class,
        ] as $name => $class) {
            $drivers[$name] = new $class($settings);
        }

        return $drivers;
    }

    /**
     * The Guzzle config inside whatever SDK the driver built.
     *
     * Every one of these ends in a `GuzzleHttp\Client` somewhere; where it sits
     * differs per SDK, so this walks the object graph rather than knowing nine
     * shapes. Deprecated `getConfig()` is avoided by reading the private
     * `config` property, which is what the SDKs themselves would expose.
     *
     * @return array<string, mixed>|null
     */
    private function guzzleInside(object $driver, int $depth = 0): ?array
    {
        if ($driver instanceof Client) {
            $config = new ReflectionProperty($driver, 'config');

            /** @var array<string, mixed> $values */
            $values = $config->getValue($driver);

            return $values;
        }

        if ($depth > 6) {
            return null;
        }

        foreach ((new ReflectionClass($driver))->getProperties() as $property) {
            if (! $property->isInitialized($driver)) {
                continue;
            }

            $value = $property->getValue($driver);

            if (is_object($value) && ($found = $this->guzzleInside($value, $depth + 1)) !== null) {
                return $found;
            }
        }

        return null;
    }
}

/** Reaches the protected conversion without making it public for one caller. */
final class BoundedGroqDriverProbe extends BoundedGroqDriver
{
    public static function milliseconds(): int
    {
        return self::timeoutMilliseconds();
    }
}
