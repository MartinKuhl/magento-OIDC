<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Attribute;

use M2Oidc\OAuth\Logger\OidcLogger;
use M2Oidc\OAuth\Model\Attribute\Transformer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the claim-value Transformer.
 *
 * Covers:
 *  - regex_replace happy path
 *  - regex_replace length cap: values over REGEX_VALUE_MAX_LENGTH (4096)
 *    bytes skip the transform with a WARNING log and return the raw value
 *
 * @covers \M2Oidc\OAuth\Model\Attribute\Transformer
 */
class TransformerTest extends TestCase
{
    /** @var OidcLogger&MockObject */
    private OidcLogger $logger;

    /** @var Transformer */
    private Transformer $transformer;

    protected function setUp(): void
    {
        $this->logger      = $this->createMock(OidcLogger::class);
        $this->transformer = new Transformer($this->logger);
    }

    public function testRegexReplaceStripsEmailDomain(): void
    {
        $result = $this->transformer->apply(
            'jdoe@example.com',
            [],
            'regex_replace',
            (string) json_encode(['pattern' => '/@.*$/'])
        );

        $this->assertSame('jdoe', $result);
    }

    /**
     * A value longer than 4096 bytes must skip the transform entirely —
     * the raw value comes back unchanged and a WARNING is logged.
     */
    public function testRegexReplaceSkipsValuesOverLengthCap(): void
    {
        $oversized = str_repeat('a', 4097);

        $this->logger->expects($this->once())
            ->method('customlog')
            ->with($this->stringContains('4096'));

        $result = $this->transformer->apply(
            $oversized,
            [],
            'regex_replace',
            (string) json_encode(['pattern' => '/a/', 'replacement' => 'b'])
        );

        $this->assertSame($oversized, $result);
    }

    /**
     * A value at exactly the 4096-byte cap is still transformed.
     */
    public function testRegexReplaceTransformsValueAtLengthCap(): void
    {
        $atLimit = str_repeat('a', 4096);

        $this->logger->expects($this->never())->method('customlog');

        $result = $this->transformer->apply(
            $atLimit,
            [],
            'regex_replace',
            (string) json_encode(['pattern' => '/^a/', 'replacement' => 'b'])
        );

        $this->assertSame('b' . str_repeat('a', 4095), $result);
    }
}
