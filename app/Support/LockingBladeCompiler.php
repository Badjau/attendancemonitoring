<?php

namespace App\Support;

use Illuminate\View\Compilers\BladeCompiler;
use Throwable;

class LockingBladeCompiler extends BladeCompiler
{
    private const MAX_RENAME_ATTEMPTS = 8;

    public function compile($path = null): void
    {
        if (! is_dir($this->cachePath)) {
            @mkdir($this->cachePath, 0777, true);
        }

        $lockPath = $this->cachePath.DIRECTORY_SEPARATOR.'.blade-compiler.lock';
        $lock = @fopen($lockPath, 'c');

        if ($lock === false) {
            $this->compileWithRetry($path);

            return;
        }

        $locked = false;

        try {
            if (! flock($lock, LOCK_EX)) {
                $this->compileWithRetry($path);

                return;
            }

            $locked = true;

            $this->compileWithRetry($path);
        } finally {
            if ($locked) {
                flock($lock, LOCK_UN);
            }

            fclose($lock);
        }
    }

    private function compileWithRetry($path = null): void
    {
        for ($attempt = 1; $attempt <= self::MAX_RENAME_ATTEMPTS; $attempt++) {
            try {
                parent::compile($path);

                return;
            } catch (Throwable $exception) {
                if (! $this->isWindowsRenameFailure($exception)) {
                    throw $exception;
                }

                if ($this->compiledViewIsFresh($path)) {
                    return;
                }

                if ($attempt === self::MAX_RENAME_ATTEMPTS) {
                    if ($this->compileWithoutAtomicRename($path)) {
                        return;
                    }

                    throw $exception;
                }

                usleep($this->retryDelay($attempt));
            }
        }
    }

    private function compiledViewIsFresh($path = null): bool
    {
        $viewPath = $path ?: $this->getPath();

        if (! is_string($viewPath) || $viewPath === '') {
            return false;
        }

        try {
            return ! $this->isExpired($viewPath);
        } catch (Throwable) {
            return false;
        }
    }

    private function compileWithoutAtomicRename($path = null): bool
    {
        if ($path) {
            $this->setPath($path);
        }

        if ($this->cachePath === null) {
            return true;
        }

        $viewPath = $this->getPath();

        if (! is_string($viewPath) || $viewPath === '') {
            return false;
        }

        $contents = $this->compileString($this->files->get($viewPath));
        $contents = $this->appendFilePath($contents);
        $compiledPath = $this->getCompiledPath($viewPath);

        $this->ensureCompiledDirectoryExists($compiledPath);

        if ($this->files->exists($compiledPath)) {
            $compiledHash = $this->files->hash($compiledPath, 'xxh128');

            if ($compiledHash === hash('xxh128', $contents)) {
                return true;
            }
        }

        return $this->files->put($compiledPath, $contents, true) !== false;
    }

    private function retryDelay(int $attempt): int
    {
        return min(1_000_000, 100_000 * $attempt);
    }

    private function isWindowsRenameFailure(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'rename(')
            && (str_contains($message, 'Access is denied')
                || str_contains($message, 'Permission denied')
                || str_contains($message, 'code: 5'));
    }
}
