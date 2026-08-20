<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\PhpUnit\CorpusFromEnv;
use Rasuvaeff\PropertyTesting\PhpUnit\LazyPhpRedisCorpusClient;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\Redis\PredisCorpusClient;
use Rasuvaeff\PropertyTesting\Runner\RedisCorpus;

/**
 * `PROPERTY_DB` resolves to a corpus, and a `redis://` DSN is the one shape
 * that was previously unreachable from a suite: the engine ships
 * {@see RedisCorpus} but reads no environment, so without this the shared
 * corpus existed only for harnesses that construct the runner themselves.
 *
 * The DSN cases build a corpus without talking to a server — both clients
 * connect lazily — which is what keeps these unit tests rather than a suite
 * that needs Redis to run.
 */
#[CoversClass(CorpusFromEnv::class)]
final class CorpusFromEnvTest extends TestCase
{
    public function testUnsetMeansNoCorpusAtAll(): void
    {
        $restore = Env::set('PROPERTY_DB', null);

        try {
            self::assertNull(CorpusFromEnv::resolve());
        } finally {
            $restore();
        }
    }

    public function testEmptyMeansNoCorpusEither(): void
    {
        $restore = Env::set('PROPERTY_DB', '');

        try {
            self::assertNull(CorpusFromEnv::resolve());
        } finally {
            $restore();
        }
    }

    public function testAPathIsStillADirectoryCorpus(): void
    {
        $restore = Env::set('PROPERTY_DB', sys_get_temp_dir() . '/property-db');

        try {
            self::assertInstanceOf(FilesystemCorpus::class, CorpusFromEnv::resolve());
        } finally {
            $restore();
        }
    }

    public function testResolvingADsnNeverOpensASocket(): void
    {
        // The reason the phpredis client is wrapped: CI installs ext-redis and
        // runs no Redis, and an eager connect() made every job red.
        $restore = Env::set('PROPERTY_DB', 'redis://127.0.0.1:6399/never-touched:');

        try {
            self::assertInstanceOf(RedisCorpus::class, CorpusFromEnv::resolve());
        } finally {
            $restore();
        }
    }

    public function testExtRedisIsPreferredWhenItIsLoaded(): void
    {
        // The documented preference, asserted in whichever environment this
        // runs: CI has the extension, the composer image does not.
        $restore = Env::set('PROPERTY_DB', 'redis://127.0.0.1:6399');

        try {
            $corpus = CorpusFromEnv::resolve();
            self::assertInstanceOf(RedisCorpus::class, $corpus);

            $client = (new \ReflectionProperty($corpus, 'client'))->getValue($corpus);

            self::assertInstanceOf(
                extension_loaded('redis') ? LazyPhpRedisCorpusClient::class : PredisCorpusClient::class,
                $client,
            );
        } finally {
            $restore();
        }
    }

    public function testARedisDsnIsASharedCorpus(): void
    {
        $restore = Env::set('PROPERTY_DB', 'redis://127.0.0.1:6379');

        try {
            self::assertInstanceOf(RedisCorpus::class, CorpusFromEnv::resolve());
        } finally {
            $restore();
        }
    }

    public function testTheDsnPathIsTheKeyPrefix(): void
    {
        // Two suites can share one server without sharing a corpus. What the
        // prefix parses to is RedisDsn's business and is pinned there; this
        // asserts only that the resolver hands it over.
        $restore = Env::set('PROPERTY_DB', 'redis://127.0.0.1:6379/suite-a:');

        try {
            $corpus = CorpusFromEnv::resolve();
            self::assertInstanceOf(RedisCorpus::class, $corpus);

            $prefix = (new \ReflectionProperty($corpus, 'prefix'))->getValue($corpus);
            self::assertSame('suite-a:', $prefix);
        } finally {
            $restore();
        }
    }

    public function testAnUnusableDsnSurfacesAsAConfigurationError(): void
    {
        $restore = Env::set('PROPERTY_DB', 'redis://');

        try {
            CorpusFromEnv::resolve();

            self::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not a usable Redis DSN', $e->getMessage());
        } finally {
            $restore();
        }
    }

    /**
     * A scheme other than `redis` — a `rediss://` typo, the wrong backend — is
     * a configuration error, never a directory named `rediss:`. The message
     * names the scheme but not the DSN, which may carry credentials.
     */
    public function testAMistypedSchemeErrorsInsteadOfWritingToADirectory(): void
    {
        $restore = Env::set('PROPERTY_DB', 'rediss://127.0.0.1:6379/suite');

        try {
            CorpusFromEnv::resolve();

            self::fail('expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('unsupported scheme "rediss://"', $e->getMessage());
            self::assertStringNotContainsString('127.0.0.1', $e->getMessage());
        } finally {
            $restore();
        }
    }

    /**
     * URI schemes are case-insensitive, so `Redis://` is still a shared corpus,
     * not a directory the previous exact-string check would have created.
     */
    public function testTheRedisSchemeIsMatchedCaseInsensitively(): void
    {
        $restore = Env::set('PROPERTY_DB', 'Redis://127.0.0.1:6399/suite:');

        try {
            self::assertInstanceOf(RedisCorpus::class, CorpusFromEnv::resolve());
        } finally {
            $restore();
        }
    }
}
