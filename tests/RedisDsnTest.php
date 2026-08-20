<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\PhpUnit\RedisDsn;

/**
 * Every answer this parser gives is a decision worth pinning: the default
 * port, the default prefix, and what counts as a DSN at all.
 */
#[CoversClass(RedisDsn::class)]
final class RedisDsnTest extends TestCase
{
    #[DataProvider('dsnProvider')]
    public function testParsesHostPortAndPrefix(string $dsn, string $host, int $port, string $prefix): void
    {
        $parsed = RedisDsn::parse($dsn);

        self::assertSame($host, $parsed->host);
        self::assertSame($port, $parsed->port);
        self::assertSame($prefix, $parsed->prefix);
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function dsnProvider(): iterable
    {
        yield 'host only' => ['redis://127.0.0.1', '127.0.0.1', 6379, 'property-testing:corpus:'];
        yield 'host and port' => ['redis://redis:6380', 'redis', 6380, 'property-testing:corpus:'];
        yield 'prefix in the path' => ['redis://redis:6380/suite-a:', 'redis', 6380, 'suite-a:'];
        yield 'prefix without a port' => ['redis://redis/suite-b:', 'redis', 6379, 'suite-b:'];
        yield 'trailing slash is not a prefix' => ['redis://redis/', 'redis', 6379, 'property-testing:corpus:'];
        yield 'nested path keeps its separators' => ['redis://redis/team/suite:', 'redis', 6379, 'team/suite:'];
    }

    public function testTheConnectionParametersAreTheOnesPredisTakes(): void
    {
        // Asserted here because at the call site the same literal could only be
        // checked by connecting to a server.
        self::assertSame(['scheme' => 'tcp', 'host' => 'redis', 'port' => 6380], RedisDsn::parse('redis://redis:6380/suite:')->toPredisParameters());
    }

    public function testADsnWithoutAHostIsAConfigurationError(): void
    {
        try {
            RedisDsn::parse('redis://');

            self::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('PROPERTY_DB="redis://" is not a usable Redis DSN; expected redis://host[:port][/key-prefix]', $e->getMessage());
        }
    }

    #[DataProvider('credentialledProvider')]
    public function testADsnWithCredentialsIsRejectedWithoutEchoingThePassword(string $dsn): void
    {
        try {
            RedisDsn::parse($dsn);

            self::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('credentials', $e->getMessage());
            self::assertStringNotContainsString('s3cret', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function credentialledProvider(): iterable
    {
        yield 'user and password' => ['redis://user:s3cret@redis:6379'];
        yield 'password only' => ['redis://:s3cret@redis:6379'];
        yield 'user only' => ['redis://user@redis:6379'];
    }

    #[DataProvider('malformedProvider')]
    public function testAMalformedDsnIsAConfigurationError(string $dsn): void
    {
        // Each of these makes parse_url() return false outright. The message
        // still has to quote the value, because it came from an environment
        // variable somebody typed by hand.
        try {
            RedisDsn::parse($dsn);

            self::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($dsn, $e->getMessage());
            self::assertStringContainsString('expected redis://host[:port][/key-prefix]', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'no host at all' => ['redis://'];
        yield 'port without a host' => ['redis://:6379'];
        yield 'prefix without a host' => ['redis:///prefix:'];
        yield 'port that is not a number' => ['redis://host:notaport'];
    }
}
