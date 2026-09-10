<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Tests;

use K2gl\TokenStatusList\Exception\InvalidStatusListException;
use K2gl\TokenStatusList\StatusReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(StatusReference::class)]
final class StatusReferenceTest extends TestCase
{
    public function testReadsTheStatusClaimOfAReferencedToken(): void
    {
        // arrange: the Section 6.2 example payload, as json_decode() delivers it
        $payload = json_decode('{"status": {"status_list": {"idx": 0, "uri": "https://example.com/statuslists/1"}}}');

        // act
        $reference = StatusReference::fromClaim($payload->status);

        // assert
        fact($reference->uri)->is('https://example.com/statuslists/1');
        fact($reference->index)->is(0);
    }

    public function testAcceptsTheClaimAsAnArray(): void
    {
        // act
        $reference = StatusReference::fromClaim(['status_list' => ['idx' => 42, 'uri' => 'https://example.com/statuslists/1']]);

        // assert
        fact($reference->index)->is(42);
    }

    public function testBuildsTheClaimForAReferencedToken(): void
    {
        // arrange
        $reference = new StatusReference(uri: 'https://example.com/statuslists/1', index: 7);

        // act + assert
        fact($reference->toClaim())->is(['status_list' => ['idx' => 7, 'uri' => 'https://example.com/statuslists/1']]);
    }

    public function testAcceptsNonHttpUris(): void
    {
        // act
        $reference = new StatusReference(uri: 'urn:example:statuslists:1', index: 0);

        // assert
        fact($reference->uri)->is('urn:example:statuslists:1');
    }

    public function testRejectsANegativeIndex(): void
    {
        // act + assert
        fact(static fn () => new StatusReference(uri: 'https://example.com/statuslists/1', index: -1))
            ->throws(InvalidStatusListException::class, 'non-negative');
    }

    #[DataProvider('nonUris')]
    public function testRejectsAValueThatIsNotAUri(string $uri): void
    {
        // act + assert
        fact(static fn () => new StatusReference(uri: $uri, index: 0))->throws(InvalidStatusListException::class, 'is not a URI');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonUris(): array
    {
        return [
            'empty'          => [''],
            'no scheme'      => ['example.com/statuslists/1'],
            'scheme only'    => ['https:'],
            'with whitespace' => ['https://example.com/status lists/1'],
        ];
    }

    #[DataProvider('malformedClaims')]
    public function testRejectsAMalformedClaim(array $claim, string $message): void
    {
        // act + assert
        fact(static fn () => StatusReference::fromClaim($claim))->throws(InvalidStatusListException::class, $message);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedClaims(): array
    {
        return [
            'no status_list'     => [['other_mechanism' => []], 'no "status_list" object'],
            'status_list scalar' => [['status_list' => 'x'], 'no "status_list" object'],
            'idx missing'        => [['status_list' => ['uri' => 'https://example.com/s/1']], 'integer "idx"'],
            'idx as string'      => [['status_list' => ['idx' => '0', 'uri' => 'https://example.com/s/1']], 'integer "idx"'],
            'idx as float'       => [['status_list' => ['idx' => 1.0, 'uri' => 'https://example.com/s/1']], 'integer "idx"'],
            'uri missing'        => [['status_list' => ['idx' => 0]], 'string "uri"'],
        ];
    }
}
