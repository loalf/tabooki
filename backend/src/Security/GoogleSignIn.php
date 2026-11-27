<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "Continue with Google": the OpenID Connect authorization code flow, with PKCE.
 *
 * start() sends the browser to Google; Google sends it back to /api/auth/google/callback, where
 * GoogleAuthenticator calls finish(). The state, nonce and PKCE verifier wait in the session.
 * Off until GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET are set (docs/DEPLOY.md).
 */
final readonly class GoogleSignIn
{
    public const string AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const string SESSION_KEY = '_google_sign_in';
    private const array ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public function __construct(
        #[Autowire(service: 'google.http_client')]
        private HttpClientInterface $http,
        private UrlGeneratorInterface $urls,
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(GOOGLE_CLIENT_SECRET)%')]
        private string $clientSecret,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret;
    }

    /**
     * Where to send the browser to sign in with Google.
     *
     * @param string|null $target the SPA path to land on afterwards
     * @param string|null $locale the SPA's language, for an account created on the way
     */
    public function start(Request $request, ?string $target, ?string $locale): string
    {
        $verifier = self::random();
        $pending = [
            'state' => self::random(),
            'nonce' => self::random(),
            'verifier' => $verifier,
            'target' => self::safeTarget($target),
            'locale' => \in_array($locale, User::LOCALES, true) ? $locale : null,
        ];
        $request->getSession()->set(self::SESSION_KEY, $pending);

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $pending['state'],
            'nonce' => $pending['nonce'],
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            // Lets someone with several Google accounts pick one, instead of the last one used.
            'prompt' => 'select_account',
        ], encoding_type: \PHP_QUERY_RFC3986);
    }

    /**
     * Back from Google: checks the answer belongs to the sign-in this browser started, trades the
     * code for an ID token and reads who signed in.
     *
     * @throws AuthenticationException when anything is off, or the person cancelled
     */
    public function finish(Request $request): GoogleIdentity
    {
        $session = $request->getSession();
        /** @var array{state: string, nonce: string, verifier: string, target: string, locale: ?string}|null $pending */
        $pending = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        if (null !== $request->query->get('error')) {
            // access_denied: they pressed Cancel on Google's page.
            throw new GoogleSignInCancelled();
        }
        $code = $request->query->getString('code');
        if (null === $pending || '' === $code || !hash_equals($pending['state'], $request->query->getString('state'))) {
            throw new CustomUserMessageAuthenticationException('The Google sign-in expired or was started in another browser.');
        }

        try {
            $response = $this->http->request('POST', self::TOKEN_URL, ['body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code_verifier' => $pending['verifier'],
            ]]);
            $tokens = 200 === $response->getStatusCode() ? $response->toArray() : [];
        } catch (HttpException) {
            $tokens = [];
        }
        $idToken = $tokens['id_token'] ?? null;
        if (!\is_string($idToken)) {
            throw new CustomUserMessageAuthenticationException('Google did not accept the sign-in.');
        }

        // The token comes straight from Google's token endpoint over TLS, so its signature needs
        // no checking (OpenID Connect Core 3.1.3.7, step 6); the claims still do.
        $claims = self::claims($idToken);
        $valid = \in_array($claims['iss'] ?? null, self::ISSUERS, true)
            && ($claims['aud'] ?? null) === $this->clientId
            && \is_int($claims['exp'] ?? null) && $claims['exp'] > time() - 60
            && \is_string($claims['nonce'] ?? null) && hash_equals($pending['nonce'], $claims['nonce'])
            && \is_string($claims['sub'] ?? null) && '' !== $claims['sub']
            && \is_string($claims['email'] ?? null) && '' !== $claims['email'];
        if (!$valid) {
            throw new CustomUserMessageAuthenticationException('Google sent an ID token that is not for this sign-in.');
        }
        if (true !== ($claims['email_verified'] ?? false)) {
            throw new CustomUserMessageAuthenticationException('The Google account has no verified email address.');
        }

        return new GoogleIdentity(
            id: $claims['sub'],
            email: mb_strtolower($claims['email']),
            name: \is_string($claims['name'] ?? null) ? trim($claims['name']) : '',
            givenName: \is_string($claims['given_name'] ?? null) ? trim($claims['given_name']) : '',
            target: $pending['target'],
            locale: $pending['locale'],
        );
    }

    private function redirectUri(): string
    {
        return $this->urls->generate('api_auth_google_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Only paths inside the SPA: never another site, nor an API endpoint. */
    private static function safeTarget(?string $target): string
    {
        if (null === $target || !str_starts_with($target, '/') || str_starts_with($target, '//') || str_starts_with($target, '/\\')
            || str_starts_with($target, '/api') || 1 === preg_match('/[\x00-\x1f]/', $target)) {
            return '/';
        }

        return $target;
    }

    /** @return array<string, mixed> */
    private static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (3 !== \count($parts)) {
            return [];
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        /** @var array<string, mixed>|null $claims a JSON object */
        $claims = false === $json ? null : json_decode($json, true);

        return \is_array($claims) ? $claims : [];
    }

    private static function random(): string
    {
        return self::base64Url(random_bytes(32));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
