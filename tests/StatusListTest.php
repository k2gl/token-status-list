<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Tests;

use K2gl\TokenStatusList\Exception\InvalidStatusListException;
use K2gl\TokenStatusList\Internal\Base64Url;
use K2gl\TokenStatusList\Status;
use K2gl\TokenStatusList\StatusList;
use K2gl\TokenStatusList\Tests\Support\TokenStatusListTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(StatusList::class)]
final class StatusListTest extends TokenStatusListTestCase
{
    public function testPacksOneBitStatusesFromTheLeastSignificantBit(): void
    {
        // arrange: the Section 4.1 example, 16 statuses
        $list = StatusList::create(16);
        $set = [0, 3, 4, 5, 7, 8, 9, 13, 15];

        // act
        foreach ($set as $index) {
            $list->set($index, Status::invalid());
        }

        // assert: byte layout and compressed form match the specification
        fact($list->bytes())->is("\xB9\xA3");
        fact($list->encode())->is('eNrbuRgAAhcBXQ');
        fact($list->count())->is(16);
    }

    public function testPacksTwoBitStatuses(): void
    {
        // arrange: the Section 4.1 two-bit example, 12 statuses
        $list = StatusList::create(12, bits: 2);
        $statuses = [0 => 1, 1 => 2, 3 => 3, 5 => 1, 7 => 1, 8 => 1, 9 => 2, 10 => 3, 11 => 3];

        // act
        foreach ($statuses as $index => $value) {
            $list->set($index, $value);
        }

        // assert
        fact($list->bytes())->is("\xC9\x44\xF9");
        fact($list->encode())->is('eNo76fITAAPfAgc');
        fact($list->get(1)->isSuspended())->true();
        fact($list->get(2)->isValid())->true();
        fact($list->get(3)->value)->is(3);
    }

    #[DataProvider('specificationExamples')]
    public function testDecodesTheSpecificationExamples(string $lst, int $bits, string $bytes): void
    {
        // act
        $list = StatusList::decode($lst, $bits);

        // assert
        fact($list->bytes())->is($bytes);
        fact($list->bits())->is($bits);
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function specificationExamples(): array
    {
        return [
            'bits 1' => ['eNrbuRgAAhcBXQ', 1, "\xB9\xA3"],
            'bits 2' => ['eNo76fITAAPfAgc', 2, "\xC9\x44\xF9"],
        ];
    }

    #[DataProvider('appendixCVectors')]
    public function testMatchesTheAppendixCVectors(int $bits): void
    {
        // arrange
        $vector = self::appendixVector($bits);
        $rebuilt = StatusList::create(2 ** 20, bits: $bits);

        // act
        $decoded = StatusList::decode($vector['lst'], $bits);

        foreach ($vector['statuses'] as $index => $value) {
            $rebuilt->set((int) $index, $value);
        }

        // assert: the vector holds 2^20 entries
        fact($decoded->count())->is(2 ** 20);

        // assert: every listed status reads back
        foreach ($vector['statuses'] as $index => $value) {
            fact($decoded->get((int) $index)->value)->is($value);
        }

        // assert: nothing else is set, and our encoding reproduces the vector byte for byte
        fact($rebuilt->bytes())->is($decoded->bytes());
        fact($rebuilt->encode())->is($vector['lst']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function appendixCVectors(): array
    {
        return [
            'C.1 1-bit' => [1],
            'C.2 2-bit' => [2],
            'C.3 4-bit' => [4],
            'C.4 8-bit' => [8],
        ];
    }

    public function testCreatesAnAllValidList(): void
    {
        // act
        $list = StatusList::create(13, bits: 2);

        // assert: 13 two-bit statuses need 4 bytes, which hold 16
        fact($list->bytes())->is("\0\0\0\0");
        fact($list->count())->is(16);
        fact($list->get(12)->isValid())->true();
    }

    #[DataProvider('appendixCVectors')]
    public function testEveryValueRoundTripsAtEveryBitWidth(int $bits): void
    {
        // arrange
        $size = 64;
        $list = StatusList::create($size, bits: $bits);
        $limit = 2 ** $bits;

        // act
        for ($index = 0; $index < $size; $index++) {
            $list->set($index, $index % $limit);
        }

        // assert
        for ($index = 0; $index < $size; $index++) {
            fact($list->get($index)->value)->is($index % $limit);
        }
    }

    public function testSetOverwritesWithoutTouchingNeighbours(): void
    {
        // arrange
        $list = StatusList::create(4, bits: 2);
        $list->set(1, 3);
        $list->set(2, 3);

        // act
        $list->set(1, Status::valid());

        // assert
        fact($list->get(0)->value)->is(0);
        fact($list->get(1)->value)->is(0);
        fact($list->get(2)->value)->is(3);
        fact($list->get(3)->value)->is(0);
    }

    public function testFromArrayReadsTheJsonStructure(): void
    {
        // arrange
        $claim = new stdClass;
        $claim->bits = 1;
        $claim->lst = 'eNrbuRgAAhcBXQ';
        $claim->aggregation_uri = 'https://example.com/statuslists';

        // act
        $fromObject = StatusList::fromArray($claim);
        $fromArray = StatusList::fromArray(['bits' => 1, 'lst' => 'eNrbuRgAAhcBXQ']);

        // assert
        fact($fromObject->bytes())->is("\xB9\xA3");
        fact($fromObject->aggregationUri())->is('https://example.com/statuslists');
        fact($fromArray->aggregationUri())->null();
    }

    public function testToArrayIncludesTheAggregationUriOnlyWhenSet(): void
    {
        // arrange
        $list = self::exampleList();

        // act
        $plain = $list->toArray();
        $aggregated = $list->withAggregationUri('https://example.com/statuslists')->toArray();

        // assert
        fact($plain)->is(['bits' => 1, 'lst' => 'eNrbuRgAAhcBXQ']);
        fact($aggregated)->is([
            'bits' => 1,
            'lst' => 'eNrbuRgAAhcBXQ',
            'aggregation_uri' => 'https://example.com/statuslists',
        ]);
    }

    #[DataProvider('unsupportedBits')]
    public function testRejectsUnsupportedBits(int $bits): void
    {
        // act + assert
        fact(static fn () => StatusList::create(8, bits: $bits))->throws(InvalidStatusListException::class, '1, 2, 4 or 8 bits');
        fact(static fn () => StatusList::decode('eNrbuRgAAhcBXQ', $bits))->throws(InvalidStatusListException::class, '1, 2, 4 or 8 bits');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unsupportedBits(): array
    {
        return [
            'zero'  => [0],
            'three' => [3],
            '16'    => [16],
        ];
    }

    public function testRejectsANegativeSize(): void
    {
        // act + assert
        fact(static fn () => StatusList::create(-1))->throws(InvalidStatusListException::class, 'cannot hold -1');
    }

    public function testRejectsAStatusThatDoesNotFit(): void
    {
        // arrange
        $list = StatusList::create(8, bits: 2);

        // act + assert
        fact(static fn () => $list->set(0, 4))->throws(InvalidStatusListException::class, 'does not fit into 2 bit(s)');
        fact(static fn () => $list->set(0, Status::of(255)))->throws(InvalidStatusListException::class, 'does not fit');
    }

    public function testRejectsAnIndexOutsideTheList(): void
    {
        // arrange
        $list = StatusList::create(16);

        // act + assert
        fact(static fn () => $list->get(16))->throws(InvalidStatusListException::class, 'Index 16 is outside');
        fact(static fn () => $list->get(-1))->throws(InvalidStatusListException::class, 'Index -1 is outside');
        fact(static fn () => $list->set(16, 1))->throws(InvalidStatusListException::class, 'outside');
    }

    #[DataProvider('undecodableLists')]
    public function testRejectsAnUndecodableLst(string $lst, string $message): void
    {
        // act + assert
        fact(static fn () => StatusList::decode($lst, 1))->throws(InvalidStatusListException::class, $message);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function undecodableLists(): array
    {
        $compressed = gzcompress("\xB9\xA3", 9);

        return [
            'not base64url'      => ['eNrb+RgAAhcBXQ==', 'not base64url'],
            'empty'              => ['', 'not base64url'],
            'not zlib'           => [Base64Url::encode('plain bytes'), 'not ZLIB data'],
            'truncated stream'   => [Base64Url::encode(substr((string) $compressed, 0, -2)), 'not ZLIB data'],
            'trailing bytes'     => [Base64Url::encode($compressed . 'x'), 'not ZLIB data'],
        ];
    }

    public function testRejectsAListBeyondTheSizeLimit(): void
    {
        // arrange: 1 MiB of zeros compresses to about a kilobyte
        $bomb = Base64Url::encode((string) gzcompress(str_repeat("\0", 1024 * 1024), 9));

        // act + assert
        fact(static fn () => StatusList::decode($bomb, 1, maxBytes: 1024))
            ->throws(InvalidStatusListException::class, 'more than 1024 bytes');

        // assert: the same data is fine under a limit that fits it
        fact(StatusList::decode($bomb, 1, maxBytes: 1024 * 1024)->count())->is(8 * 1024 * 1024);
    }

    #[DataProvider('malformedStructures')]
    public function testFromArrayRejectsMalformedStructures(array $structure, string $message): void
    {
        // act + assert
        fact(static fn () => StatusList::fromArray($structure))->throws(InvalidStatusListException::class, $message);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedStructures(): array
    {
        return [
            'missing bits'               => [['lst' => 'eNrbuRgAAhcBXQ'], '"bits" member must be an integer'],
            'bits as string'             => [['bits' => '1', 'lst' => 'eNrbuRgAAhcBXQ'], '"bits" member must be an integer'],
            'missing lst'                => [['bits' => 1], '"lst" member must be a string'],
            'aggregation_uri not string' => [['bits' => 1, 'lst' => 'eNrbuRgAAhcBXQ', 'aggregation_uri' => 1], '"aggregation_uri" member must be a string'],
        ];
    }
}
