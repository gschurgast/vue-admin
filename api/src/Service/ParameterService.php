<?php

namespace App\Service;

use App\Entity\Parameter;
use App\Enum\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

class ParameterService
{
    /** @var array<string, Parameter|null> */
    private array $cache = [];

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function get(string $code): ?Parameter
    {
        if (!\array_key_exists($code, $this->cache)) {
            $this->cache[$code] = $this->em->getRepository(Parameter::class)->findOneBy(['code' => $code]);
        }
        return $this->cache[$code];
    }

    public function getString(string $code, string $default = ''): string
    {
        return $this->get($code)?->getValue() ?? $default;
    }

    public function getInt(string $code, int $default = 0): int
    {
        $p = $this->get($code);
        return $p ? $p->asInt() : $default;
    }

    public function getBool(string $code, bool $default = false): bool
    {
        $p = $this->get($code);
        return $p ? $p->asBool() : $default;
    }

    public function getJson(string $code, mixed $default = null): mixed
    {
        $p = $this->get($code);
        return $p ? $p->asJson() : $default;
    }

    public function set(string $code, string $value, ParameterType $type = ParameterType::STRING, ?string $description = null): Parameter
    {
        $p = $this->get($code);
        if (!$p) {
            $p = (new Parameter())->setCode($code);
            $this->em->persist($p);
        }
        $p->setValue($value)->setType($type);
        if ($description !== null) {
            $p->setDescription($description);
        }
        $this->em->flush();
        $this->cache[$code] = $p;
        return $p;
    }
}
