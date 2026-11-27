<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Repository\UserRepository;
use App\Security\GoogleSignIn;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleSignInTest extends ApiTestCase
{
    use SignsInUsers;

    private const string CLIENT_ID = 'test-client.apps.googleusercontent.com';

    protected static ?bool $alwaysBootKernel = false;

    private Client $client;

    /** What the token endpoint was sent. */
    private ?string $tokenRequest = null;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['headers' => ['Accept' => 'application/json']]);
        // The mocked Google and the session must outlive each request.
        $this->client->disableReboot();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testTheSignInPageOffersGoogle(): void
    {
        $this->client->request('GET', '/api/auth/options');

        self::assertResponseIsSuccessful();
        self::assertJsonEquals(['google' => true]);
    }

    public function testSomeoneNewGetsAnAccountInAHouseholdOfTheirOwn(): void
    {
        $this->createUser('ana@example.com', 'Casa');

        $landed = $this->signInWithGoogle(['sub' => 'g-123', 'email' => 'Luis.Perez@gmail.com', 'name' => 'Luis Pérez', 'given_name' => 'Luis'], target: '/plan?week=2026-W48');

        self::assertSame('/plan?week=2026-W48', $landed);
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'), 'stays signed in, like a password sign-in');
        $this->client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'email' => 'luis.perez@gmail.com',
            'displayName' => 'Luis Pérez',
            'locale' => 'en',
            'householdName' => "Luis's home",
            'hasPassword' => false,
            'google' => true,
        ]);

        // Signing in again finds the same account.
        $this->client->request('POST', '/api/logout');
        $this->signInWithGoogle(['sub' => 'g-123', 'email' => 'luis.perez@gmail.com']);
        self::assertCount(1, $this->users()->findBy(['googleId' => 'g-123']));
    }

    public function testAnAccountMadeFromTheConsoleIsLinkedByEmail(): void
    {
        $ana = $this->createUser('ana@example.com', 'Casa');

        $this->signInWithGoogle(['sub' => 'g-ana', 'email' => 'ana@example.com', 'name' => 'Ana García'], locale: 'es');

        $this->client->request('GET', '/api/me');
        self::assertJsonContains(['id' => $ana->getId()->toRfc4122(), 'displayName' => 'Ana', 'householdName' => 'Casa', 'hasPassword' => true, 'google' => true]);
        self::assertSame('g-ana', $this->users()->find($ana->getId())?->getGoogleId());
    }

    public function testANewSpanishSpeakerGetsCasaDe(): void
    {
        $this->signInWithGoogle(['sub' => 'g-1', 'email' => 'marta@gmail.com', 'given_name' => 'Marta'], locale: 'es');

        $this->client->request('GET', '/api/me');
        self::assertJsonContains(['householdName' => 'Casa de Marta', 'displayName' => 'marta', 'locale' => 'es']);
    }

    public function testSomeoneWhoSignedUpWithGoogleCanAddAPassword(): void
    {
        $this->signInWithGoogle(['sub' => 'g-1', 'email' => 'marta@gmail.com']);

        $this->client->request('POST', '/api/me/password', ['json' => ['currentPassword' => '', 'newPassword' => 'a-new-password']]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('POST', '/api/logout');
        $this->client->request('POST', '/api/login', ['json' => ['email' => 'marta@gmail.com', 'password' => 'a-new-password']]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testNoPasswordMeansNoPasswordSignIn(): void
    {
        $this->signInWithGoogle(['sub' => 'g-1', 'email' => 'marta@gmail.com']);
        $this->client->request('POST', '/api/logout');

        $this->client->request('POST', '/api/login', ['json' => ['email' => 'marta@gmail.com', 'password' => 'x']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testCancellingOnGooglesPageGoesBackQuietly(): void
    {
        $this->startSignIn();

        $this->client->request('GET', '/api/auth/google/callback?error=access_denied&state=whatever');

        self::assertResponseRedirects('/login');
        $this->assertSignedOut();
    }

    public function testAnAnswerForAnotherSignInIsRefused(): void
    {
        $this->startSignIn();
        $this->mockGoogle(['sub' => 'g-1', 'email' => 'marta@gmail.com']);

        $this->client->request('GET', '/api/auth/google/callback?code=c&state=not-the-one-we-sent');

        self::assertResponseRedirects('/login?error=google');
        self::assertNull($this->tokenRequest, 'the code is not even traded');
        $this->assertSignedOut();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badTokens(): iterable
    {
        yield 'for another app' => [['aud' => 'someone-else.apps.googleusercontent.com']];
        yield 'from another issuer' => [['iss' => 'https://evil.example']];
        yield 'expired' => [['exp' => time() - 3600]];
        yield 'replayed (another nonce)' => [['nonce' => 'old-nonce']];
        yield 'unverified email' => [['email_verified' => false]];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('badTokens')]
    public function testABadIdTokenIsRefused(array $override): void
    {
        $this->signInWithGoogle(['sub' => 'g-1', 'email' => 'marta@gmail.com', ...$override], expect: '/login?error=google');

        $this->assertSignedOut();
        self::assertNull($this->users()->findOneByEmail('marta@gmail.com'));
    }

    public function testFewNewAccountsADayFromOnePlace(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->signInWithGoogle(['sub' => 'g-'.$i, 'email' => "person{$i}@gmail.com"]);
            $this->client->request('POST', '/api/logout');
        }

        $this->signInWithGoogle(['sub' => 'g-6', 'email' => 'person6@gmail.com'], expect: '/login?error=google-limit');
        self::assertNull($this->users()->findOneByEmail('person6@gmail.com'));

        // Existing accounts still get in.
        $this->signInWithGoogle(['sub' => 'g-1', 'email' => 'person1@gmail.com']);
    }

    /**
     * Presses "Continue with Google", "signs in" there, and comes back.
     *
     * @param array<string, mixed> $claims of the ID token Google answers with
     *
     * @return string where the browser landed
     */
    private function signInWithGoogle(array $claims, string $target = '/', string $locale = 'en', string $expect = ''): string
    {
        $authorize = $this->startSignIn($target, $locale);
        self::assertSame(self::CLIENT_ID, $authorize['client_id']);
        // Built from the request: https://tabooki.xyz/... in production.
        self::assertSame('http://localhost/api/auth/google/callback', $authorize['redirect_uri']);
        self::assertSame('S256', $authorize['code_challenge_method']);

        $this->mockGoogle(['nonce' => $authorize['nonce'], ...$claims]);
        $this->client->request('GET', '/api/auth/google/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));
        $location = $this->location();

        if ('' !== $expect) {
            self::assertSame($expect, $location);

            return $location;
        }
        self::assertResponseRedirects();
        self::assertStringStartsNotWith('/login', $location);
        parse_str((string) $this->tokenRequest, $sent);
        self::assertSame('the-code', $sent['code']);
        self::assertSame('test-secret', $sent['client_secret']);
        $verifier = $sent['code_verifier'] ?? null;
        self::assertIsString($verifier);
        self::assertSame($authorize['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'PKCE');

        return $location;
    }

    /** @return array<string, string> the query of the authorize URL */
    private function startSignIn(string $target = '/', string $locale = 'en'): array
    {
        $this->client->request('GET', '/api/auth/google?'.http_build_query(['target' => $target, 'locale' => $locale]));
        $location = $this->location();
        self::assertStringStartsWith(GoogleSignIn::AUTHORIZE_URL.'?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        /** @var array<string, string> $authorize */
        $authorize = $query;

        return $authorize;
    }

    /** @param array<string, mixed> $claims */
    private function mockGoogle(array $claims): void
    {
        $this->tokenRequest = null;
        $claims += ['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'exp' => time() + 3600, 'email_verified' => true];
        $part = static fn (array $json): string => rtrim(strtr(base64_encode(json_encode($json, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $idToken = $part(['alg' => 'RS256', 'kid' => 'k']).'.'.$part($claims).'.c2lnbmF0dXJl';

        $google = self::getContainer()->get('google.http_client');
        self::assertInstanceOf(MockHttpClient::class, $google);
        $google->setResponseFactory(function (string $method, string $url, array $options) use ($idToken): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame(GoogleSignIn::TOKEN_URL, $url);
            $this->tokenRequest = \is_string($options['body'] ?? null) ? $options['body'] : null;

            return new JsonMockResponse(['access_token' => 'a', 'id_token' => $idToken, 'token_type' => 'Bearer', 'expires_in' => 3599]);
        });
    }

    private function location(): string
    {
        return $this->client->getResponse()?->getHeaders(false)['location'][0] ?? '';
    }

    private function assertSignedOut(): void
    {
        $this->client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
    }

    private function users(): UserRepository
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return $users;
    }
}
