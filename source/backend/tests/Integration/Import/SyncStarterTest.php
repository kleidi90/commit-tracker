<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Entity\Repository;
use App\Entity\SyncRun;
use App\Enum\SyncStatus;
use App\Import\SyncAlreadyActiveException;
use App\Import\SyncStarter;
use App\Message\ImportRepositoryMessage;
use App\Provider\RepoRef;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SyncStarterTest extends KernelTestCase
{
    use ResetsDatabase;

    private EntityManagerInterface $entityManager;
    private MockClock $clock;
    private SyncStarter $starter;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        self::resetDatabase($this->entityManager->getConnection());

        $this->clock = new MockClock('2026-05-01 12:00:00 UTC');
        $this->starter = new SyncStarter($this->entityManager, $container->get(MessageBusInterface::class), $this->clock);
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;
        $this->transport->reset();
    }

    public function testQueuesARunAndDispatchesItsId(): void
    {
        $run = $this->starter->startFor(RepoRef::fromString('acme/widgets'));

        self::assertSame(SyncStatus::Queued, $run->getStatus());
        $sent = $this->transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(ImportRepositoryMessage::class, $message);
        self::assertSame($run->getId(), $message->syncRunId);
    }

    public function testRepositoryIdentityIsCaseInsensitiveAndKeepsTheFirstSpelling(): void
    {
        $first = $this->starter->startFor(RepoRef::fromString('Acme/Widgets'));
        $this->finish($first);

        $second = $this->starter->startFor(RepoRef::fromString('acme/widgets'));

        self::assertSame($first->getRepository()->getId(), $second->getRepository()->getId());
        self::assertSame(1, self::rowCount($this->entityManager->getConnection(), 'repositories'));
        self::assertSame('Acme/Widgets', $second->getRepository()->getFullName());
    }

    public function testRefusesASecondRunWhileOneIsActive(): void
    {
        $this->starter->startFor(RepoRef::fromString('acme/widgets'));

        $this->expectException(SyncAlreadyActiveException::class);
        $this->starter->startFor(RepoRef::fromString('acme/widgets'));
    }

    public function testReplacesAnAbandonedRun(): void
    {
        $stale = $this->starter->startFor(RepoRef::fromString('acme/widgets'));
        $this->clock->sleep(16 * 60);

        $fresh = $this->starter->start($stale->getRepository());

        self::assertSame(SyncStatus::Failed, $stale->getStatus());
        self::assertStringStartsWith('Abandoned', (string) $stale->getError());
        self::assertSame(SyncStatus::Queued, $fresh->getStatus());
    }

    public function testTheDatabaseRefusesASecondActiveRun(): void
    {
        // The check in SyncStarter can race; the partial unique index is what really guarantees it.
        $repository = new Repository(RepoRef::fromString('acme/widgets'), $this->clock->now());
        $this->entityManager->persist($repository);
        $this->entityManager->persist(new SyncRun($repository, $this->clock->now()));
        $this->entityManager->flush();

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->entityManager->persist(new SyncRun($repository, $this->clock->now()));
        $this->entityManager->flush();
    }

    private function finish(SyncRun $run): void
    {
        $run->markSucceeded($this->clock->now());
        $this->entityManager->flush();
    }
}
