<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\PhpUnit\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\PropertyTesting\PhpUnit\EnvironmentCorpus;
use Rasuvaeff\PropertyTesting\PhpUnit\Tests\Support\Env;
use Rasuvaeff\PropertyTesting\Runner\CorpusFactory;
use Rasuvaeff\PropertyTesting\Runner\Environment;

/**
 * `PROPERTY_DB_PASSWORD` reaches the corpus factory. Core hands out one corpus
 * per distinct DSN and password within a process, so the identity of the
 * returned corpus shows which password it was resolved with.
 */
#[CoversClass(EnvironmentCorpus::class)]
final class EnvironmentCorpusTest extends TestCase
{
    /** @var list<\Closure(): void> */
    private array $restore = [];

    private string $dsn;

    #[\Override]
    protected function setUp(): void
    {
        $this->restore = [Env::isolateProperty()];
        $this->dsn = sys_get_temp_dir() . '/prop-db-password-' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (array_reverse($this->restore) as $undo) {
            $undo();
        }
    }

    public function testNoCorpusWithoutPropertyDb(): void
    {
        $this->setPassword('secret');

        self::assertNull(EnvironmentCorpus::of(new Environment()));
    }

    public function testPassesThePasswordToTheCorpusFactory(): void
    {
        $this->setPassword('secret');

        $corpus = EnvironmentCorpus::of(new Environment(db: $this->dsn));

        self::assertSame(CorpusFactory::fromDsn(dsn: $this->dsn, password: 'secret'), $corpus);
        self::assertNotSame(CorpusFactory::fromDsn(dsn: $this->dsn), $corpus);
    }

    public function testResolvesWithoutAPasswordWhenTheVariableIsUnset(): void
    {
        $this->setPassword(null);

        $corpus = EnvironmentCorpus::of(new Environment(db: $this->dsn));

        self::assertSame(CorpusFactory::fromDsn(dsn: $this->dsn), $corpus);
        self::assertNotSame(CorpusFactory::fromDsn(dsn: $this->dsn, password: 'secret'), $corpus);
    }

    public function testAnEmptyPasswordMeansNone(): void
    {
        $this->setPassword('');

        self::assertNull(EnvironmentCorpus::password());
        self::assertSame(CorpusFactory::fromDsn(dsn: $this->dsn), EnvironmentCorpus::of(new Environment(db: $this->dsn)));
    }

    public function testAnUnsetPasswordMeansNone(): void
    {
        $this->setPassword(null);

        self::assertNull(EnvironmentCorpus::password());
    }

    public function testReadsThePasswordVerbatim(): void
    {
        $this->setPassword('0');

        self::assertSame('0', EnvironmentCorpus::password());
    }

    private function setPassword(?string $password): void
    {
        $this->restore[] = Env::set('PROPERTY_DB_PASSWORD', $password);
    }
}
