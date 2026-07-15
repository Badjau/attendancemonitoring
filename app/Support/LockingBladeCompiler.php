<?php

namespace App\Support;

use Illuminate\View\Compilers\BladeCompiler;
use Throwable;

class LockingBladeCompiler extends BladeCompiler
{
    public function compile($path = null): void
    {
        $lockPath = $this->cachePath.DIRECTORY_SEPARATOR.'.blade-compiler.lock';
        $lock = @fopen($lockPath, 'c');

        if ($lock === false) {
            $this->compileWithRetry($path);

            return;
        }

        try {
            flock($lock, LOCK_EX);

            $this->compileWithRetry($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function compileWithRetry($path = null): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                parent::compile($path);

                return;
            } catch (Throwable $exception) {
                if ($attempt === 3 || ! $this->isWindowsRenameFailure($exception)) {
                    throw $exception;
                }

                usleep(100000 * $attempt);
            }
        }
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
