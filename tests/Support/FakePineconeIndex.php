<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\VectorStore\PineconeVectorStore;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in Pinecone index, served entirely from memory over Http::fake().
 *
 * No test may make a network call (docs/engineering.md §9), but the shared
 * VectorStoreInterface conformance suite has to run against
 * PineconeVectorStore or the abstraction is only ever proved by one
 * implementation. Canned responses would not do it: the suite asserts ranking
 * order and filtering, so the fake implements both for real — cosine
 * similarity, `$eq` metadata filters, topK, and upsert-by-id semantics.
 *
 * What that proves is the adapter, which is the part this repository owns: that
 * it sends Pinecone's payload shape and reads Pinecone's response shape back
 * into `RetrievedChunk`. Pinecone's own ANN index is not under test.
 */
final class FakePineconeIndex
{
    private const HOST = 'https://fake-index.svc.pinecone.io';

    /**
     * @var array<string, array{
     *     id: string,
     *     values: list<float>,
     *     metadata: array<string, scalar|null>
     * }>
     */
    private array $vectors = [];

    /**
     * Install the fake and return a store wired to it.
     */
    public static function install(): PineconeVectorStore
    {
        $index = new self;

        Http::fake([
            self::HOST.'/*' => static fn (Request $request): mixed => $index->handle($request),
        ]);

        return new PineconeVectorStore(
            http: app(HttpFactory::class),
            apiKey: 'pcsk-test-key',
            host: self::HOST,
            namespace: 'test',
            timeoutSeconds: 5,
            retries: 0,
            retryDelayMs: 0,
        );
    }

    private function handle(Request $request): mixed
    {
        /** @var array<string, mixed> $body */
        $body = $request->data();

        return match (true) {
            str_ends_with($request->url(), '/vectors/upsert') => $this->upsert($body),
            str_ends_with($request->url(), '/vectors/delete') => $this->delete($body),
            str_ends_with($request->url(), '/query') => $this->query($body),
            default => Http::response(['message' => 'Not found'], 404),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function upsert(array $body): mixed
    {
        /** @var list<array<string, mixed>> $vectors */
        $vectors = $body['vectors'] ?? [];

        foreach ($vectors as $vector) {
            $id = (string) $vector['id'];

            // By id, so a re-upsert replaces rather than duplicates.
            $this->vectors[$id] = [
                'id' => $id,
                'values' => array_map(static fn (mixed $v): float => (float) $v, (array) $vector['values']),
                'metadata' => (array) ($vector['metadata'] ?? []),
            ];
        }

        return Http::response(['upsertedCount' => count($vectors)]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function delete(array $body): mixed
    {
        foreach ((array) ($body['ids'] ?? []) as $id) {
            // Unknown ids are a no-op, exactly as the real service treats them.
            unset($this->vectors[(string) $id]);
        }

        return Http::response([]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function query(array $body): mixed
    {
        $queryVector = array_map(static fn (mixed $v): float => (float) $v, (array) ($body['vector'] ?? []));
        $topK = (int) ($body['topK'] ?? 5);
        /** @var array<string, array{'$eq': scalar}> $filter */
        $filter = (array) ($body['filter'] ?? []);

        $matches = [];

        foreach ($this->vectors as $vector) {
            if (! $this->matches($vector['metadata'], $filter)) {
                continue;
            }

            $matches[] = [
                'id' => $vector['id'],
                'score' => $this->cosineSimilarity($queryVector, $vector['values']),
                'metadata' => $vector['metadata'],
            ];
        }

        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return Http::response(['matches' => array_slice($matches, 0, max(0, $topK))]);
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     * @param  array<string, array{'$eq': scalar}>  $filter
     */
    private function matches(array $metadata, array $filter): bool
    {
        foreach ($filter as $key => $condition) {
            if (($metadata[$key] ?? null) !== ($condition['$eq'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        foreach ($a as $index => $value) {
            $dot += $value * $b[$index];
            $magnitudeA += $value ** 2;
            $magnitudeB += $b[$index] ** 2;
        }

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magnitudeA) * sqrt($magnitudeB));
    }
}
