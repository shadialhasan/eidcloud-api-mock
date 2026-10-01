<?php

declare(strict_types=1);

namespace EidCloud\ApiMock\Simulator;

/**
 * Simulates network latency, jitter, and intermittent chaos errors (500, 503, 504).
 */
class ChaosSimulator
{
    private int $delayMs = 0;
    private float $errorRate = 0.0; // 0.0 to 1.0 (e.g. 0.15 = 15%)
    private array $customErrorCodes = [500, 502, 503, 504];

    public function __construct(int $delayMs = 0, float $errorRate = 0.0)
    {
        $this->delayMs = max(0, $delayMs);
        $this->errorRate = max(0.0, min(1.0, $errorRate));
    }

    public function setDelayMs(int $delayMs): self
    {
        $this->delayMs = max(0, $delayMs);
        return $this;
    }

    public function getDelayMs(): int
    {
        return $this->delayMs;
    }

    public function setErrorRate(float $rate): self
    {
        $this->errorRate = max(0.0, min(1.0, $rate));
        return $this;
    }

    public function getErrorRate(): float
    {
        return $this->errorRate;
    }

    /**
     * Executes simulated delay using usleep if delay is configured.
     */
    public function applyDelay(bool $sleep = true): int
    {
        if ($this->delayMs <= 0) {
            return 0;
        }

        // Add 10% jitter
        $jitter = (int) round($this->delayMs * 0.1);
        $actualDelay = $this->delayMs + ($jitter > 0 ? mt_rand(-$jitter, $jitter) : 0);
        $actualDelay = max(1, $actualDelay);

        if ($sleep) {
            usleep($actualDelay * 1000);
        }

        return $actualDelay;
    }

    /**
     * Checks if chaos error should be triggered based on errorRate.
     * Returns an HTTP error array if triggered, null otherwise.
     */
    public function maybeTriggerChaosError(): ?array
    {
        if ($this->errorRate <= 0.0) {
            return null;
        }

        $rand = mt_rand(1, 10000) / 10000;
        if ($rand <= $this->errorRate) {
            $code = $this->customErrorCodes[array_rand($this->customErrorCodes)];
            return [
                'statusCode' => $code,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Mock-Chaos' => 'true'
                ],
                'body' => [
                    'error' => 'ChaosSimulationError',
                    'statusCode' => $code,
                    'message' => match ($code) {
                        500 => 'Internal Server Error (Simulated by EidCloud ApiMock)',
                        502 => 'Bad Gateway (Simulated by EidCloud ApiMock)',
                        503 => 'Service Unavailable (Simulated by EidCloud ApiMock)',
                        504 => 'Gateway Timeout (Simulated by EidCloud ApiMock)',
                        default => 'Simulated Server Error'
                    },
                    'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339)
                ]
            ];
        }

        return null;
    }
}
