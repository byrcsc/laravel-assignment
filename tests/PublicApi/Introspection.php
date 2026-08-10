<?php

declare(strict_types=1);

namespace ByRcsc\LaravelAssignment\Tests\PublicApi;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Reads the package back out of PHP, in the shapes {@see Surface} writes them
 * down in. Split from the manifest because the manifest changes whenever the
 * API does and this does not.
 */
final class Introspection
{
    /**
     * Renders one method as `name(type $param = default): returnType`.
     *
     * @param  class-string  $class
     */
    public static function signature(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);

        $parameters = array_map(static function (ReflectionParameter $parameter): string {
            $type = self::type($parameter->getType());
            $rendered = ($type === '' ? '' : $type.' ').'$'.$parameter->getName();

            if (! $parameter->isDefaultValueAvailable()) {
                return $rendered;
            }

            return $rendered.' = '.self::value($parameter->getDefaultValue());
        }, $reflection->getParameters());

        return $reflection->getName().'('.implode(', ', $parameters).'): '.self::type($reflection->getReturnType());
    }

    /**
     * The dotted paths a config array actually defines. Lists and empty arrays
     * are values, not namespaces, so recursion stops there.
     *
     * @param  array<array-key, mixed>  $config
     * @return list<string>
     */
    public static function configPaths(array $config, string $prefix = 'assignment'): array
    {
        $paths = [];

        foreach ($config as $key => $value) {
            $path = $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $paths = [...$paths, ...self::configPaths($value, $path)];

                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Every class, interface, trait, and enum shipped in `src`.
     *
     * @return list<class-string>
     */
    public static function sourceClasses(): array
    {
        $source = dirname(__DIR__, 2).'/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

        $classes = [];

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($source) + 1, -strlen('.php'));

            /** @var class-string $class */
            $class = 'ByRcsc\\LaravelAssignment\\'.str_replace('/', '\\', $relative);

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * The method names a facade advertises through its `@method` annotations.
     *
     * @param  class-string  $facade
     * @return list<string>
     */
    public static function facadeAnnotations(string $facade): array
    {
        $docBlock = (new \ReflectionClass($facade))->getDocComment();

        if (! is_string($docBlock)) {
            return [];
        }

        preg_match_all('/@method\s+static\s+\S+\s+(\w+)\(/', $docBlock, $matches);

        return $matches[1];
    }

    private static function type(?ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            $nullable = $type->allowsNull() && ! in_array($type->getName(), ['mixed', 'null'], true);

            return ($nullable ? '?' : '').$type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(
                static fn (ReflectionType $part): string => self::type($part),
                $type->getTypes(),
            ));
        }

        return '';
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => (string) $value,
            is_string($value) => "'".$value."'",
            default => throw new UnexpectedValueException('The surface has no rendering for this default value.'),
        };
    }
}
