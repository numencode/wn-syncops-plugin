<?php

declare(strict_types=1);

namespace NumenCode\SyncOps\Classes;

/**
 * Replaces secret values (passwords, key passphrases) with "***" in text before it is printed.
 */
final class Redactor
{
    /**
     * @var array<int, string>
     */
    private array $secrets = [];

    /**
     * @param array<int, string|null> $secrets
     */
    public function __construct(array $secrets = [])
    {
        $this->add($secrets);
    }

    /**
     * @param array<int, string|null> $secrets
     */
    public function add(array $secrets): void
    {
        foreach ($secrets as $secret) {
            if (!is_string($secret) || $secret === '') {
                continue;
            }

            // The value as it is, and as it appears in shell-escaped or URL-encoded text
            foreach ([$secret, RemoteServer::quote($secret), escapeshellarg($secret), rawurlencode($secret)] as $variant) {
                $this->secrets[] = $variant;
            }
        }

        $this->secrets = array_values(array_unique($this->secrets));

        // Longest first, so a secret containing another secret is replaced as a whole
        usort($this->secrets, static fn (string $a, string $b) => strlen($b) <=> strlen($a));
    }

    public function redact(string $text): string
    {
        return $this->secrets === [] ? $text : str_replace($this->secrets, '***', $text);
    }
}
