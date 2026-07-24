<?php

namespace Tests\Unit;

use App\Support\LockingBladeCompiler;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LockingBladeCompilerTest extends TestCase
{
    public function test_the_application_uses_the_locking_blade_compiler(): void
    {
        $this->assertInstanceOf(LockingBladeCompiler::class, app('blade.compiler'));
    }

    public function test_the_locking_blade_compiler_can_compile_a_view(): void
    {
        /** @var LockingBladeCompiler $compiler */
        $compiler = app('blade.compiler');
        $sourcePath = storage_path('framework/testing/locking-blade-compiler-test.blade.php');

        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, '<div>{{ $name }}</div>');

        $compiledPath = $compiler->getCompiledPath($sourcePath);
        File::delete($compiledPath);

        try {
            $compiler->compile($sourcePath);

            $this->assertFileExists($compiledPath);
            $this->assertStringContainsString('$name', File::get($compiledPath));
        } finally {
            File::delete($sourcePath);
            File::delete($compiledPath);
        }
    }
}
