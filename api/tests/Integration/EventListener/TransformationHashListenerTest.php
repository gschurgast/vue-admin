<?php

namespace App\Tests\Integration\EventListener;

use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use App\Message\PurgeTransformationVariantsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class TransformationHashListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em = $em;

        $tool = new SchemaTool($this->em);
        $metas = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metas);
        $tool->createSchema($metas);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.transformations_backfill');
        $this->transport = $transport;
        $this->transport->reset();
    }

    public function testCreateTransformationComputesHash(): void
    {
        $t = (new AssetTransformation())->setCode('test-create')->setLabel('Test create');
        $t->addStep(
            (new TransformationStep())->setType(StepType::RESIZE)->setParams(['width' => 800])->setPosition(0),
        );

        $this->em->persist($t);
        $this->em->flush();

        self::assertNotNull($t->getVersionHash());
        self::assertSame(40, strlen($t->getVersionHash()));
        self::assertCount(0, $this->transport->getSent());
    }

    public function testAddingStepRecomputesHashAndDispatchesPurge(): void
    {
        $t = (new AssetTransformation())->setCode('test-add-step')->setLabel('Test');
        $s1 = (new TransformationStep())->setType(StepType::RESIZE)->setParams(['width' => 800])->setPosition(0);
        $t->addStep($s1);
        $this->em->persist($t);
        $this->em->flush();
        $originalHash = $t->getVersionHash();
        $this->transport->reset();

        $s2 = (new TransformationStep())->setType(StepType::FORMAT_CONVERT)->setParams(['format' => 'webp'])->setPosition(1);
        $t->addStep($s2);
        $this->em->flush();

        self::assertNotSame($originalHash, $t->getVersionHash());
        $envelopes = $this->transport->getSent();
        self::assertCount(1, $envelopes);
        $msg = $envelopes[0]->getMessage();
        self::assertInstanceOf(PurgeTransformationVariantsMessage::class, $msg);
        self::assertSame($t->getId(), $msg->transformationId);
        self::assertSame($originalHash, $msg->versionHash);
    }

    public function testRemovingStepRecomputesHash(): void
    {
        $t = (new AssetTransformation())->setCode('test-rm-step')->setLabel('Test');
        $s = (new TransformationStep())->setType(StepType::ROTATE)->setParams(['angle' => 90])->setPosition(0);
        $t->addStep($s);
        $this->em->persist($t);
        $this->em->flush();
        $hashWithStep = $t->getVersionHash();

        $t->removeStep($s);
        $this->em->flush();

        self::assertNotSame($hashWithStep, $t->getVersionHash());
    }

    public function testDeletingTransformationDispatchesPurge(): void
    {
        $t = (new AssetTransformation())->setCode('test-delete')->setLabel('To delete');
        $t->addStep((new TransformationStep())->setType(StepType::RESIZE)->setParams(['width' => 800])->setPosition(0));
        $this->em->persist($t);
        $this->em->flush();
        $hashBeforeDelete = $t->getVersionHash();
        $idBeforeDelete = $t->getId();
        $this->transport->reset();

        $this->em->remove($t);
        $this->em->flush();

        $envelopes = $this->transport->getSent();
        self::assertCount(1, $envelopes);
        $msg = $envelopes[0]->getMessage();
        self::assertSame($idBeforeDelete, $msg->transformationId);
        self::assertSame($hashBeforeDelete, $msg->versionHash);
    }

    public function testNoOpUpdateProducesNoPurge(): void
    {
        $t = (new AssetTransformation())->setCode('test-noop')->setLabel('Orig');
        $t->addStep((new TransformationStep())->setType(StepType::RESIZE)->setParams(['width' => 800])->setPosition(0));
        $this->em->persist($t);
        $this->em->flush();
        $hashBefore = $t->getVersionHash();
        $this->transport->reset();

        $t->setLabel('Updated label');
        $this->em->flush();

        self::assertSame($hashBefore, $t->getVersionHash());
        self::assertCount(0, $this->transport->getSent());
    }
}
