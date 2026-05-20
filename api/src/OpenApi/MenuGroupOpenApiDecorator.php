<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\OpenApi;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Operation;
use App\Attribute\MenuGroup;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(decorates: 'api_platform.openapi.factory')]
class MenuGroupOpenApiDecorator implements OpenApiFactoryInterface
{
    public function __construct(
        private OpenApiFactoryInterface $decorated
    ) {}

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        // Map of tag names to their menu groups based on MenuGroup attribute
        $menuGroups = $this->getMenuGroupsFromAttributes();

        // Add x-menu-group to every operation of each path based on its tag
        $paths = $openApi->getPaths();
        $newPaths = new \ApiPlatform\OpenApi\Model\Paths();

        $methods = ['Get', 'Post', 'Put', 'Patch', 'Delete'];

        foreach ($paths->getPaths() as $path => $pathItem) {
            foreach ($methods as $method) {
                $getter = 'get' . $method;
                $setter = 'with' . $method;
                $operation = $pathItem->{$getter}();

                if (!$operation) {
                    continue;
                }

                $tags = $operation->getTags();
                $tag = $tags[0] ?? null;

                if ($tag && isset($menuGroups[$tag])) {
                    $newOperation = $operation->withExtensionProperty('x-menu-group', $menuGroups[$tag]);
                    $pathItem = $pathItem->{$setter}($newOperation);
                }
            }

            $newPaths->addPath($path, $pathItem);
        }

        return $openApi->withPaths($newPaths);
    }

    private function getMenuGroupsFromAttributes(): array
    {
        $menuGroups = [];

        // Scan entity directories for MenuGroup attributes
        $entityDirs = [
            __DIR__ . '/../Entity',
            __DIR__ . '/../ApiResource',
        ];

        foreach ($entityDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $this->scanDirectory($dir, $menuGroups);
        }

        return $menuGroups;
    }

    private function scanDirectory(string $dir, array &$menuGroups): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $this->getClassNameFromFile($file->getPathname());
            if (!$className || !class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
                $attributes = $reflection->getAttributes(MenuGroup::class);

                if (!empty($attributes)) {
                    $menuGroup = $attributes[0]->newInstance();
                    $shortName = $reflection->getShortName();
                    $menuGroups[$shortName] = $menuGroup->group;
                }
            } catch (\Throwable $e) {
                // Skip classes that can't be reflected
                continue;
            }
        }
    }

    private function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);

        // Extract namespace
        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = $matches[1];
        }

        // Extract class name
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $className = $matches[1];
            return $namespace ? $namespace . '\\' . $className : $className;
        }

        return null;
    }
}
