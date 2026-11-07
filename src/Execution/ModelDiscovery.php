<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Execution;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Finds the application's Eloquent models: every autoloadable, non-abstract
 * model class in config('retention.models.paths'), plus the classes listed
 * in config('retention.models.additional').
 */
final readonly class ModelDiscovery
{
    public function __construct(
        private Repository $config,
        private PolicyRegistry $policies,
    ) {}

    /**
     * @return list<class-string<Model>>
     */
    public function models(): array
    {
        $models = [];

        foreach ($this->configuredList('retention.models.paths') as $path) {
            foreach ($this->classesIn($path) as $class) {
                if ($this->isModel($class)) {
                    $models[] = $class;
                }
            }
        }

        foreach ($this->configuredList('retention.models.additional') as $class) {
            if (! $this->isModel($class)) {
                throw new InvalidArgumentException("[retention.models.additional] contains [{$class}], which is not an Eloquent model.");
            }

            $models[] = $class;
        }

        $models = array_values(array_unique($models));
        sort($models);

        return $models;
    }

    /**
     * The models that use HasRetention.
     *
     * @return list<class-string<Model>>
     */
    public function retainedModels(): array
    {
        return array_values(array_filter($this->models(), $this->policies->has(...)));
    }

    /**
     * Resolves model names given on the command line: a fully qualified class
     * name or the class basename of a retained model.
     *
     * @param  list<string>  $names
     * @return list<class-string<Model>>
     */
    public function resolve(array $names): array
    {
        $retained = $this->retainedModels();
        $resolved = [];

        foreach ($names as $name) {
            $matches = array_values(array_filter(
                $retained,
                fn (string $model): bool => $model === ltrim($name, '\\') || class_basename($model) === $name,
            ));

            if (count($matches) !== 1) {
                throw new InvalidArgumentException(count($matches) === 0
                    ? "Unknown model [{$name}]; models with a retention policy: ".implode(', ', $retained).'.'
                    : "Model name [{$name}] is ambiguous: ".implode(', ', $matches).'; use the full class name.');
            }

            $resolved[] = $matches[0];
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @phpstan-assert-if-true =class-string<Model> $class
     */
    private function isModel(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, Model::class)
            && ! (new ReflectionClass($class))->isAbstract();
    }

    /**
     * @return list<string>
     */
    private function classesIn(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $classes = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $class = $this->classInFile($file->getPathname());

                if ($class !== null) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * The first named class declared in a PHP file, with its namespace.
     */
    private function classInFile(string $file): ?string
    {
        $tokens = PhpToken::tokenize((string) file_get_contents($file));
        $namespace = '';

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = '';

                for ($next = $index + 1; isset($tokens[$next]) && ! $tokens[$next]->is([';', '{']); $next++) {
                    if ($tokens[$next]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace .= $tokens[$next]->text;
                    }
                }
            }

            if (! $token->is(T_CLASS)) {
                continue;
            }

            // Skip `new class` (anonymous classes) and `Foo::class`.
            if ($this->significant($tokens, $index, -1)?->is([T_NEW, T_DOUBLE_COLON])) {
                continue;
            }

            $name = $this->significant($tokens, $index, 1);

            if ($name?->is(T_STRING)) {
                return ltrim($namespace.'\\'.$name->text, '\\');
            }
        }

        return null;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     */
    private function significant(array $tokens, int $index, int $direction): ?PhpToken
    {
        for ($i = $index + $direction; isset($tokens[$i]); $i += $direction) {
            if (! $tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function configuredList(string $key): array
    {
        $value = $this->config->get($key) ?? [];

        if (! is_array($value) || array_filter($value, fn (mixed $item): bool => ! is_string($item)) !== []) {
            throw new InvalidArgumentException("[{$key}] must be a list of strings.");
        }

        return array_values($value);
    }
}
