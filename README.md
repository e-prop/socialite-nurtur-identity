# Nurtur Identity

Laravel Socialite provider for the Nurtur Identity platform.

```bash
composer require e-prop/socialite-nurtur-identity
```

Nurtur Identity is a Duende IdentityServer deployment, so this is a standard OIDC
authorization code flow with PKCE. The provider covers login, the API scope each
application needs, refresh tokens, and RP-initiated logout.

## Installation & Basic Usage

Please see the [Base Installation Guide](https://socialiteproviders.com/usage/), then
follow the provider specific instructions below.

### Add configuration to `config/services.php`

```php
'nurtur-identity' => [
    'client_id' => env('NURTUR_IDENTITY_CLIENT_ID'),
    'client_secret' => env('NURTUR_IDENTITY_CLIENT_SECRET'),
    'redirect' => env('NURTUR_IDENTITY_REDIRECT_URI'),
    'base_url' => env('NURTUR_IDENTITY_URL', 'https://identity.nurtur.tech'),
    'tenant' => env('NURTUR_IDENTITY_TENANT'),
],
```

| Key | Required | Description |
| --- | --- | --- |
| `client_id` | yes | The client registered on Identity, e.g. `members-hub`. |
| `client_secret` | yes | The client secret. |
| `redirect` | yes | Your callback route, e.g. `https://example.com/login/nurtur`. Must match a redirect URI on the client registration. |
| `base_url` | no | The Identity server to authenticate against. Defaults to production; use `https://identity-dev.nurtur.tech` in development. |
| `tenant` | no | The application's own API scope, e.g. `members-hub-api`. Space separate for more than one. Merged with the OIDC scopes below. |

The provider always requests `openid profile offline_access`, plus whatever `tenant`
holds.

> **`offline_access` is what makes refresh tokens work.** Identity will not return a
> `refresh_token` without it, and it will reject the scope outright unless the client
> registration allows offline access. If login starts failing with `invalid_scope`
> after switching to this provider, that registration is what needs updating.

### Add provider event listener

In Laravel 11+, register the listener with the `Event` facade in your
`AppServiceProvider` `boot` method:

```php
Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
    $event->extendSocialite('nurtur-identity', \SocialiteProviders\NurturIdentity\Provider::class);
});
```

<details>
<summary>Laravel 10 or below</summary>

Add the event to your `listen[]` array in `app/Providers/EventServiceProvider`:

```php
protected $listen = [
    \SocialiteProviders\Manager\SocialiteWasCalled::class => [
        \SocialiteProviders\NurturIdentity\NurturIdentityExtendSocialite::class.'@handle',
    ],
];
```
</details>

### Usage

```php
return Socialite::driver('nurtur-identity')->redirect();
```

The user returned by the callback carries the tokens:

```php
$identityUser = Socialite::driver('nurtur-identity')->user();

$identityUser->getId();      // the `sub` claim
$identityUser->getEmail();
$identityUser->getName();
$identityUser->getRaw();     // every claim from /connect/userinfo

$identityUser->token;
$identityUser->refreshToken;
$identityUser->expiresIn;
$identityUser->accessTokenResponseBody['id_token'] ?? null;
```

## Storing and refreshing tokens

Socialite has no storage layer of its own — no migrations, no models — so persisting
tokens is the application's job. Laravel
[documents](https://laravel.com/docs/12.x/socialite#authentication-and-storage) doing
this with columns on your own `users` table, which is the pattern to follow here.

Storing the tokens is what lets other Nurtur systems that authenticate through Identity
act on the user's behalf without sending them back through a login.

```php
$table->text('nurtur_token')->nullable();
$table->text('nurtur_refresh_token')->nullable();
$table->timestamp('nurtur_token_expires_at')->nullable();
```

Socialite's documented example omits expiry, but you need it to know when to refresh.
Cast the tokens so they are encrypted at rest:

```php
protected function casts(): array
{
    return [
        'nurtur_token' => 'encrypted',
        'nurtur_refresh_token' => 'encrypted',
        'nurtur_token_expires_at' => 'datetime',
    ];
}
```

Write them in the callback:

```php
$identityUser = Socialite::driver('nurtur-identity')->user();

$user = User::updateOrCreate([
    'nurtur_id' => $identityUser->getId(),
], [
    'email' => $identityUser->getEmail(),
    'nurtur_token' => $identityUser->token,
    'nurtur_refresh_token' => $identityUser->refreshToken,
    'nurtur_token_expires_at' => now()->addSeconds($identityUser->expiresIn),
]);

Auth::login($user);
```

Then refresh on demand. `refreshToken()` is provided by Socialite itself — it makes the
`refresh_token` grant call against Identity and returns a `Laravel\Socialite\Two\Token`.
Deciding when to call it, and saving the result, is the part you own:

```php
public function nurturAccessToken(): ?string
{
    if (! $this->nurtur_refresh_token) {
        return $this->nurtur_token;
    }

    if ($this->nurtur_token_expires_at?->isAfter(now()->addMinute())) {
        return $this->nurtur_token;
    }

    $token = Socialite::driver('nurtur-identity')->refreshToken($this->nurtur_refresh_token);

    $this->forceFill([
        'nurtur_token' => $token->token,
        'nurtur_refresh_token' => $token->refreshToken,
        'nurtur_token_expires_at' => now()->addSeconds($token->expiresIn),
    ])->save();

    return $token->token;
}
```

Identity rotates refresh tokens, so always persist the new `refreshToken` alongside the
new access token — the old one stops working.

## Forcing re-authentication

To make the user log in again even if they hold a session on Identity:

```php
return Socialite::driver('nurtur-identity')->forceLogin()->redirect();
```

`prompt()` sets the OIDC `prompt` parameter directly if you need one of the other
values Identity supports (`none`, `login`, `consent`, `select_account`, `create`).

## Logging out

`getLogoutUrl()` builds the RP-initiated logout URL, ending the Identity session rather
than only the local one:

```php
$provider = Socialite::driver('nurtur-identity');

// End the Identity session and come back to us.
return redirect($provider->getLogoutUrl(route('login'), $idToken));
```

The `id_token_hint` is the `id_token` from `accessTokenResponseBody` at login; store it
if you want to use it. Without it the client id is sent instead, which Identity also
accepts.

## Tests

```bash
composer install
composer test
```

The suite runs the provider against a Testbench application with a mocked Identity
server, covering the authorize URL, the PKCE challenge, the code exchange and claim
mapping, the refresh token grant, and logout URLs.
