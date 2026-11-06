<?php

namespace App\Ai;

use App\Enums\AiVendor;

/**
 * One provider in a transfer file. The file calls the provider's id `uuid`.
 */
final readonly class ProviderTransferEntry
{
    public function __construct(
        public string $uuid,
        public string $name,
        public AiVendor $vendor,
        public ?string $model,
        public ?string $apiKey = null,
        public ?string $url = null,
        public ?string $embeddingModel = null,
        public ?float $searchMinSimilarity = null,
    ) {}

    /**
     * @param  array{uuid: string, name: string, vendor: string, model?: string|null, api_key?: string, url?: string, embedding_model?: string|null, search_min_similarity?: int|float|null}  $entry
     */
    public static function fromValidated(array $entry): self
    {
        return new self(
            uuid: $entry['uuid'],
            name: $entry['name'],
            vendor: AiVendor::from($entry['vendor']),
            model: $entry['model'] ?? null,
            apiKey: $entry['api_key'] ?? null,
            url: $entry['url'] ?? null,
            embeddingModel: $entry['embedding_model'] ?? null,
            searchMinSimilarity: isset($entry['search_min_similarity']) ? (float) $entry['search_min_similarity'] : null,
        );
    }

    /**
     * @return array{uuid: string, name: string, vendor: string, api_key: string|null, url: string|null, model: string|null, embedding_model: string|null, search_min_similarity: float|null}
     */
    public function toFileFormat(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'vendor' => $this->vendor->value,
            'api_key' => $this->apiKey,
            'url' => $this->url,
            'model' => $this->model,
            'embedding_model' => $this->embeddingModel,
            'search_min_similarity' => $this->searchMinSimilarity,
        ];
    }
}
