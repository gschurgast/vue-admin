<?php

declare(strict_types=1);

namespace App\Tests\Integration\AssetTransformation;

use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\AssetType;
use App\Enum\StepType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Plan 03 — Plan 01 — Task 3.
 *
 * Asserts:
 *   - alpha-flatten-on-jpeg warning is derived at flush time when the chain
 *     ends on a JPEG format_convert without an add_background step
 *   - the warning disappears when add_background is present
 *   - WEBP target → no warning
 *   - invalid params on a step → ValidationFailedException at flush
 *   - Asset.is_public defaults to false and roundtrips through the DB
 */
final class WarningsDerivationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

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
    }

    public function testJpegEndingWithoutAddBackgroundProducesWarning(): void
    {
        $t = (new AssetTransformation())->setCode('warn-jpeg')->setLabel('JPEG warn');
        $t->addStep((new TransformationStep())->setType(StepType::RESIZE)->setParams(['width' => 800])->setPosition(0));
        $t->addStep((new TransformationStep())->setType(StepType::FORMAT_CONVERT)->setParams(['format' => 'jpg'])->setPosition(1));

        $this->em->persist($t);
        $this->em->flush();
        $id = $t->getId();
        $this->em->clear();

        /** @var AssetTransformation $reload */
        $reload = $this->em->find(AssetTransformation::class, $id);
        self::assertSame(
            [['code' => 'alpha-flatten-on-jpeg', 'stepIndex' => null]],
            $reload->getWarnings(),
        );
    }

    public function testAddBackgroundSuppressesWarning(): void
    {
        $t = (new AssetTransformation())->setCode('warn-jpeg-bg')->setLabel('JPEG with bg');
        $t->addStep((new TransformationStep())->setType(StepType::RESIZE)->setParams(['width' => 800])->setPosition(0));
        $t->addStep((new TransformationStep())->setType(StepType::ADD_BACKGROUND)->setParams(['type' => 'color', 'color' => '#ffffff'])->setPosition(1));
        $t->addStep((new TransformationStep())->setType(StepType::FORMAT_CONVERT)->setParams(['format' => 'jpg'])->setPosition(2));

        $this->em->persist($t);
        $this->em->flush();
        $id = $t->getId();
        $this->em->clear();

        $reload = $this->em->find(AssetTransformation::class, $id);
        self::assertSame([], $reload->getWarnings());
    }

    public function testWebpTargetProducesNoWarning(): void
    {
        $t = (new AssetTransformation())->setCode('warn-webp')->setLabel('WEBP');
        $t->addStep((new TransformationStep())->setType(StepType::RESIZE)->setParams(['width' => 800])->setPosition(0));
        $t->addStep((new TransformationStep())->setType(StepType::FORMAT_CONVERT)->setParams(['format' => 'webp'])->setPosition(1));

        $this->em->persist($t);
        $this->em->flush();
        $id = $t->getId();
        $this->em->clear();

        $reload = $this->em->find(AssetTransformation::class, $id);
        self::assertSame([], $reload->getWarnings());
    }

    public function testInvalidStepParamsAreRejectedAtFlush(): void
    {
        $t = (new AssetTransformation())->setCode('warn-invalid')->setLabel('Invalid');
        $t->addStep((new TransformationStep())->setType(StepType::RESIZE)->setParams(['mode' => 'invalid'])->setPosition(0));

        $this->expectException(ValidationFailedException::class);
        $this->em->persist($t);
        $this->em->flush();
    }

    public function testAssetIsPublicDefaultsToFalseAndRoundtrips(): void
    {
        $asset = (new Asset())
            ->setType(AssetType::IMAGE)
            ->setMimeType('image/png')
            ->setFilename('test.png')
            ->setSize(1);

        $this->em->persist($asset);
        $this->em->flush();
        $id = $asset->getId();
        $this->em->clear();

        /** @var Asset $reload */
        $reload = $this->em->find(Asset::class, $id);
        self::assertFalse($reload->isPublic());

        $reload->setIsPublic(true);
        $this->em->flush();
        $this->em->clear();

        /** @var Asset $reload2 */
        $reload2 = $this->em->find(Asset::class, $id);
        self::assertTrue($reload2->isPublic());
    }
}
