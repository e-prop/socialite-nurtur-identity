<?php

namespace SocialiteProviders\NurturIdentity;

use SocialiteProviders\Manager\SocialiteWasCalled;

class NurturIdentityExtendSocialite
{
    public function handle(SocialiteWasCalled $socialiteWasCalled): void
    {
        $socialiteWasCalled->extendSocialite('nurtur-identity', Provider::class);
    }
}
