<?php

namespace App\Ai;

/**
 * The result of an export: the entries for the file and how many providers
 * were left out because their key can no longer be decrypted.
 */
final readonly class ProviderExport
{
    /**
     * @param  list<ProviderTransferEntry>  $entries
     */
    public function __construct(
        public array $entries,
        public int $omitted,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'format' => ProviderTransfer::FORMAT,
            'version' => ProviderTransfer::VERSION,
            'providers' => array_map(fn (ProviderTransferEntry $entry): array => $entry->toFileFormat(), $this->entries),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
