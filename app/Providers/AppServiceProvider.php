<?php

namespace App\Providers;

<<<<<<< HEAD
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illumincate\Support\Facades\Socialite;
=======
use Illuminate\Support\ServiceProvider;
>>>>>>> origin/master

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
<<<<<<< HEAD
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event): void {
            $event->extendSocialite('twitch', \SocialiteProviders\Twitch\Provider::class);
        });
        
=======
        //
>>>>>>> origin/master
    }
}
