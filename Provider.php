<?php

namespace SocialiteProviders\NurturIdentity;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

/**
 * Nurtur Identity is a Duende IdentityServer deployment, so this is a standard
 * OIDC authorization code flow against its /connect endpoints.
 *
 * @see https://identity.nurtur.tech/.well-known/openid-configuration
 */
class Provider extends AbstractProvider
{
    public const IDENTIFIER = 'NURTURIDENTITY';

    protected $usesPKCE = true;

    protected $scopeSeparator = ' ';

    /**
     * Identity will only issue a refresh token when `offline_access` is requested,
     * and only then if the client is registered with offline access allowed.
     */
    protected $scopes = ['openid', 'profile', 'offline_access'];

    public static function additionalConfigKeys(): array
    {
        return ['base_url', 'tenant'];
    }

    protected function getBaseUrl(): string
    {
        return rtrim((string) $this->getConfig('base_url', 'https://identity.nurtur.tech'), '/');
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->getBaseUrl().'/connect/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->getBaseUrl().'/connect/token';
    }

    /**
     * Each application requests its own API scope alongside the OIDC ones, so the
     * tenant scope (e.g. `members-hub-api`) comes from config rather than the provider.
     *
     * {@inheritdoc}
     */
    public function getScopes()
    {
        $tenant = $this->getConfig('tenant', []);

        return array_values(array_unique(array_merge(
            parent::getScopes(),
            is_array($tenant) ? $tenant : preg_split('/\s+/', $tenant, -1, PREG_SPLIT_NO_EMPTY)
        )));
    }

    /**
     * Require the user to authenticate again, even if they already hold a session
     * on Identity.
     */
    public function forceLogin(bool $force = true): self
    {
        return $this->prompt($force ? 'login' : null);
    }

    /**
     * Set the OIDC `prompt` parameter. Identity supports none, login, consent,
     * select_account and create.
     */
    public function prompt(?string $prompt): self
    {
        // with() replaces the parameter bag outright, so merge into it instead.
        if ($prompt === null) {
            unset($this->parameters['prompt']);
        } else {
            $this->parameters['prompt'] = $prompt;
        }

        return $this;
    }

    /**
     * Build the RP-initiated logout URL, so an application can end the user's
     * Identity session and not just its own.
     *
     * `post_logout_redirect_uri` is only honoured when Identity can tell which
     * client is asking, which is what the id token hint or client id provides.
     */
    public function getLogoutUrl(?string $redirectUri = null, ?string $idTokenHint = null): string
    {
        $url = $this->getBaseUrl().'/connect/endsession';

        if ($redirectUri === null) {
            return $url;
        }

        $query = ['post_logout_redirect_uri' => $redirectUri];

        if ($idTokenHint !== null) {
            $query['id_token_hint'] = $idTokenHint;
        } else {
            $query['client_id'] = $this->clientId;
        }

        return $url.'?'.http_build_query($query);
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get($this->getBaseUrl().'/connect/userinfo', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => Arr::get($user, 'sub'),
            'nickname' => Arr::get($user, 'preferred_username'),
            'name' => Arr::get($user, 'name') ?: $this->buildName($user),
            'email' => Arr::get($user, 'email'),
            'avatar' => Arr::get($user, 'picture'),
        ]);
    }

    /**
     * Identity does not always return the `name` claim, but the profile scope
     * gives us the parts to build it from.
     */
    protected function buildName(array $user): ?string
    {
        $name = trim(sprintf('%s %s', Arr::get($user, 'given_name', ''), Arr::get($user, 'family_name', '')));

        return $name !== '' ? $name : null;
    }
}
