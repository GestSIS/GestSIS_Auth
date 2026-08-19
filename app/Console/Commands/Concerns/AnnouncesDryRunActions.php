<?php

namespace App\Console\Commands\Concerns;

trait AnnouncesDryRunActions
{
    private function announce(string $tag, string $subject, bool $dryRun): void
    {
        $this->line("[{$tag}] {$subject}" . ($dryRun ? ' (dry-run)' : ''));
    }
}
