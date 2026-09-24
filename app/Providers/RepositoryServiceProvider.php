<?php

namespace App\Providers;

use App\Domain\Contracts\QrCodeRendererInterface;
use App\Domain\Contracts\RosterPdfRendererInterface;
use App\Domain\Contracts\StudentRepositoryInterface;
use App\Infrastructure\Pdf\DompdfRosterPdfRenderer;
use App\Infrastructure\Qr\EndroidQrCodeRenderer;
use App\Infrastructure\Repositories\EloquentStudentRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StudentRepositoryInterface::class, EloquentStudentRepository::class);
        $this->app->bind(QrCodeRendererInterface::class, EndroidQrCodeRenderer::class);
        $this->app->bind(RosterPdfRendererInterface::class, DompdfRosterPdfRenderer::class);

        // As you build out Sessions, Exclusions, etc., add one bind() line each here.
    }
}
