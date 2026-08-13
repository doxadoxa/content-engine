<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Drivers\BoundedOpenAiDriver;
use App\Pipelines\Contracts\Step;
use LarAgent\Core\DTO\DriverConfig;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;
use SplFileInfo;
use Tests\TestCase;
use Tests\Unit\PipelineTimeoutChainTest;

/**
 * The deadline on a model call.
 *
 * There was none. LarAgent's `OpenAiDriver` builds its client with
 * `OpenAI::client($key)` and `OpenAiCompatible` with `new Client([])`; Guzzle's
 * default `timeout` is 0, which means wait for ever. Nothing downstream bounded
 * it either — a step's `timeout()` decides when its claim may be taken over,
 * not when the handler is interrupted — so an unresponsive provider held an
 * expensive worker until the worker itself was killed at 2100 seconds.
 *
 * These assert the decision rather than Guzzle's plumbing: that a deadline is
 * set, that it is short enough for the pipeline to record a failure before the
 * worker is killed, and that the driver is the one the providers actually use.
 */
final class BoundedOpenAiDriverTest extends TestCase
{
    #[Test]
    public function a_call_has_a_deadline_at_all(): void
    {
        $options = BoundedOpenAiDriver::httpOptions();

        $this->assertGreaterThan(0.0, $options['timeout'], 'Zero is Guzzle for "wait for ever".');
        $this->assertGreaterThan(0.0, $options['connect_timeout']);
    }

    #[Test]
    public function the_deadline_is_configurable(): void
    {
        config()->set('models.timeout', 42);
        config()->set('models.connect_timeout', 7);

        $this->assertSame(42.0, BoundedOpenAiDriver::httpOptions()['timeout']);
        $this->assertSame(7.0, BoundedOpenAiDriver::httpOptions()['connect_timeout']);
    }

    #[Test]
    public function every_step_that_calls_a_model_can_reach_the_deadline_before_its_worker_is_killed(): void
    {
        $timeout = BoundedOpenAiDriver::httpOptions()['timeout'];

        $steps = $this->modelCallingSteps();

        $this->assertNotSame([], $steps, 'No step appears to call a model, which cannot be right.');

        foreach ($steps as $class => $queue) {
            $worker = $this->workerTimeout($queue);

            if ($worker === null) {
                continue;
            }

            // Derived from the step classes rather than from a list, so a step
            // that starts calling a model — or moves to a smaller pool — fails
            // here rather than in production. Two of them were already on the
            // cheap queue when this was written, where no useful deadline fits
            // under a 120-second worker at all.
            $this->assertLessThan(
                $worker,
                $timeout,
                "{$class} calls a model on the `{$queue}` queue, whose worker stops at {$worker}s, "
                    ."while a call may run to {$timeout}s. Inverted, the process is killed mid-call and "
                    .'nothing is recorded — which is the failure this timeout exists to replace, not a '
                    .'smaller version of it. Move the step to the expensive queue, or lower MODEL_TIMEOUT.',
            );
        }
    }

    #[Test]
    public function a_deployment_with_no_key_gets_no_client_rather_than_an_exception(): void
    {
        // The state the suite runs in, and any installation that has not been
        // given a key. The base driver refuses to send with an empty client;
        // throwing here would move that failure from the call to the boot.
        $driver = new BoundedOpenAiDriver(new DriverConfig(apiKey: null));

        $this->assertNull($this->clientOf($driver));
    }

    #[Test]
    public function a_key_produces_a_client(): void
    {
        $driver = new BoundedOpenAiDriver(new DriverConfig(apiKey: 'sk-test'));

        $this->assertNotNull($this->clientOf($driver));
    }

    #[Test]
    public function the_openai_providers_use_it(): void
    {
        // The driver is only worth having if it is the one config points at,
        // and a provider added later that reaches for LarAgent's own is exactly
        // the regression this catches.
        foreach (['default', 'openai'] as $provider) {
            $this->assertSame(
                BoundedOpenAiDriver::class,
                config("laragent.providers.{$provider}.driver"),
                "The `{$provider}` provider must use the driver that has a deadline.",
            );
        }
    }

    /**
     * Every step whose source reaches a model, and the queue it runs on.
     *
     * Read from the files, the way {@see PipelineTimeoutChainTest}
     * reads the real config: a list maintained by hand is a list that stops
     * being true the first time somebody adds an `ask()` to a step that did not
     * have one.
     *
     * @return array<class-string<Step>, string>
     */
    private function modelCallingSteps(): array
    {
        $steps = [];

        foreach ($this->stepFiles(app_path('Pipelines/Steps')) as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, '$context->ask(') && ! str_contains($source, '$context->send(')) {
                continue;
            }

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
                continue;
            }

            $class = trim($namespace[1]).'\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Step::class)) {
                continue;
            }

            /** @var Step $step */
            $step = app($class);

            $steps[$class] = $step->queue();
        }

        return $steps;
    }

    /** @return list<string> */
    private function stepFiles(string $directory): array
    {
        /** @var list<string> $files */
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** The supervisor that serves this queue, if one does. */
    private function workerTimeout(string $queue): ?int
    {
        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            if (in_array($queue, (array) ($supervisor['queue'] ?? []), true)) {
                return (int) $supervisor['timeout'];
            }
        }

        return null;
    }

    private function clientOf(BoundedOpenAiDriver $driver): mixed
    {
        $client = new ReflectionProperty($driver, 'client');

        return $client->isInitialized($driver) ? $client->getValue($driver) : null;
    }
}
