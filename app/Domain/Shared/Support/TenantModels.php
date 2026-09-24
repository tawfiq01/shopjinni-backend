<?php

namespace App\Domain\Shared\Support;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;

/**
 * Discovers every Eloquent model under App\Domain that uses
 * BelongsToCompany, by reflection rather than a hand-maintained list — so
 * the per-company backup export and the isolation test suite can't
 * silently drift from whatever models actually opted in.
 */
class TenantModels
{
    /** @return array<int, class-string<Model>> */
    public static function classes(): array
    {
        $classes = [];

        foreach (File::allFiles(app_path('Domain')) as $file) {
            if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = str_replace([app_path().DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            if (in_array(BelongsToCompany::class, class_uses_recursive($class), true)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /** @return array<int, string> */
    public static function tables(): array
    {
        return array_values(array_unique(array_map(
            fn (string $class) => (new $class())->getTable(),
            self::classes(),
        )));
    }
}
