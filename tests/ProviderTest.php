<?php

namespace SocialiteProviders\NurturIdentity\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Event;
use Laravel\Socialite\Facades\Socialite;
use Orchestra\Testbench\TestCase;
use SocialiteProviders\Manager\ServiceProvider as ManagerServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\NurturIdentity\Provider;

class ProviderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ManagerServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        // The manager fires SocialiteWasCalled from an app->booted callback, so the
        // listener has to exist before the application boots.
        Event::listen(fn (SocialiteWasCalled $e) => $e->extendSocialite('nurtur-identity', Provider::class));

        $app['config']->set('services.nurtur-identity', [
            'client_id' => 'members-hub',
            'client_secret' => 'shhh',
            'redirect' => 'https://example.com/login/nurtur',
            'base_url' => 'https://identity-dev.nurtur.tech/',
            'tenant' => 'members-hub-api',
        ]);
    }

    protected Store $session;

    protected function setUp(): void
    {
        parent::setUp();

        // Socialite keeps the OAuth state and PKCE verifier in the session.
        $this->session = new Store('test', new ArraySessionHandler(60));
        $this->app['request']->setLaravelSession($this->session);
    }

    private function driver(): Provider
    {
        return Socialite::driver('nurtur-identity');
    }

    public function test_it_builds_the_authorize_url(): void
    {
        $url = $this->driver()->redirect()->getTargetUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://identity-dev.nurtur.tech/connect/authorize?', $url);
        $this->assertSame('members-hub', $query['client_id']);
        $this->assertSame('https://example.com/login/nurtur', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid profile offline_access members-hub-api', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($query['state']);
        $this->assertArrayNotHasKey('prompt', $query);

        // The trailing slash on base_url must not produce a double slash.
        $this->assertStringNotContainsString('tech//connect', $url);
    }

    public function test_the_pkce_challenge_matches_the_stored_verifier(): void
    {
        $url = $this->driver()->redirect()->getTargetUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $verifier = $this->session->get('code_verifier');
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, $query['code_challenge']);
    }

    public function test_force_login_sets_the_prompt(): void
    {
        $url = $this->driver()->forceLogin()->redirect()->getTargetUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('login', $query['prompt']);

        $url = $this->driver()->forceLogin(false)->redirect()->getTargetUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('prompt', $query);
    }

    public function test_it_exchanges_the_code_and_maps_the_user(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'at-1',
                'refresh_token' => 'rt-1',
                'expires_in' => 3600,
                'id_token' => 'id-1',
                'scope' => 'openid profile offline_access members-hub-api',
            ])),
            new Response(200, [], json_encode([
                'sub' => 'abc-123',
                'email' => 'jonathan@example.com',
                'given_name' => 'Jonathan',
                'family_name' => 'Tiney',
                'preferred_username' => 'jtiney',
            ])),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));

        $this->session->put(['state' => 'xyz', 'code_verifier' => 'verifier-123']);
        $this->app['request']->merge(['code' => 'the-code', 'state' => 'xyz']);

        $provider = $this->driver()->setHttpClient(new Client(['handler' => $stack]));

        $user = $provider->user();

        $this->assertSame('abc-123', $user->getId());
        $this->assertSame('jonathan@example.com', $user->getEmail());
        $this->assertSame('Jonathan Tiney', $user->getName());
        $this->assertSame('jtiney', $user->getNickname());
        $this->assertSame('at-1', $user->token);
        $this->assertSame('rt-1', $user->refreshToken);
        $this->assertSame(3600, $user->expiresIn);
        $this->assertSame(['openid', 'profile', 'offline_access', 'members-hub-api'], $user->approvedScopes);
        $this->assertSame('id-1', $user->accessTokenResponseBody['id_token']);

        // Token request went to the right place, with PKCE.
        $tokenRequest = $history[0]['request'];
        $this->assertSame('https://identity-dev.nurtur.tech/connect/token', (string) $tokenRequest->getUri());
        parse_str((string) $tokenRequest->getBody(), $fields);
        $this->assertSame('authorization_code', $fields['grant_type']);
        $this->assertSame('verifier-123', $fields['code_verifier']);
        $this->assertSame('the-code', $fields['code']);

        // Userinfo was called with the bearer token.
        $userinfoRequest = $history[1]['request'];
        $this->assertSame('https://identity-dev.nurtur.tech/connect/userinfo', (string) $userinfoRequest->getUri());
        $this->assertSame('Bearer at-1', $userinfoRequest->getHeaderLine('Authorization'));
    }

    public function test_it_refreshes_a_token(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'at-2',
                'refresh_token' => 'rt-2',
                'expires_in' => 3600,
                'scope' => 'openid profile offline_access members-hub-api',
            ])),
        ]));
        $stack->push(\GuzzleHttp\Middleware::history($history));

        $token = $this->driver()->setHttpClient(new Client(['handler' => $stack]))->refreshToken('rt-1');

        $this->assertSame('at-2', $token->token);
        $this->assertSame('rt-2', $token->refreshToken);
        $this->assertSame(3600, $token->expiresIn);
        $this->assertSame(['openid', 'profile', 'offline_access', 'members-hub-api'], $token->approvedScopes);

        parse_str((string) $history[0]['request']->getBody(), $fields);
        $this->assertSame('https://identity-dev.nurtur.tech/connect/token', (string) $history[0]['request']->getUri());
        $this->assertSame('refresh_token', $fields['grant_type']);
        $this->assertSame('rt-1', $fields['refresh_token']);
        $this->assertSame('members-hub', $fields['client_id']);
    }

    public function test_it_builds_logout_urls(): void
    {
        $provider = $this->driver();

        $this->assertSame(
            'https://identity-dev.nurtur.tech/connect/endsession',
            $provider->getLogoutUrl()
        );

        $this->assertSame(
            'https://identity-dev.nurtur.tech/connect/endsession?post_logout_redirect_uri=https%3A%2F%2Fexample.com%2F&client_id=members-hub',
            $provider->getLogoutUrl('https://example.com/')
        );

        $this->assertSame(
            'https://identity-dev.nurtur.tech/connect/endsession?post_logout_redirect_uri=https%3A%2F%2Fexample.com%2F&id_token_hint=id-1',
            $provider->getLogoutUrl('https://example.com/', 'id-1')
        );
    }
}
