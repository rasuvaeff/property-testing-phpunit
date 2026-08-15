<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\PhpUnit\CorpusFromEnv;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
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
}
