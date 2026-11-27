<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\GoogleSignIn;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Session endpoints for the SPA. Authentication itself happens in the firewall
 * (json_login, App\Security\GoogleAuthenticator and logout in config/packages/security.yaml); these
 * actions only shape the response, or send the browser to Google.
 */
#[AsController]
final readonly class SecurityController
{
    public function __construct(private GoogleSignIn $google)
    {
    }

    /** What the sign-in page offers, besides email and password. */
    #[Route('/api/auth/options', name: 'api_auth_options', methods: ['GET'])]
    public function options(): JsonResponse
    {
        return new JsonResponse(['google' => $this->google->isEnabled()]);
    }

    /** "Continue with Google" is a plain link here: ?target=/path/to/land/on&locale=es */
    #[Route('/api/auth/google', name: 'api_auth_google', methods: ['GET'])]
    public function google(Request $request): RedirectResponse
    {
        if (!$this->google->isEnabled()) {
            throw new NotFoundHttpException('Google sign-in is not set up.');
        }
        $target = $request->query->get('target');
        $locale = $request->query->get('locale');

        return new RedirectResponse($this->google->start($request, \is_string($target) ? $target : null, \is_string($locale) ? $locale : null));
    }

    #[Route('/api/auth/google/callback', name: 'api_auth_google_callback', methods: ['GET'])]
    public function googleCallback(): never
    {
        throw new NotFoundHttpException('Google sign-in is not set up.');
    }

    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(#[CurrentUser] ?User $user): Response
    {
        // Reached without a user only when json_login did not run (e.g. wrong content type).
        if (null === $user) {
            return new JsonResponse(['error' => 'Send a JSON body with "email" and "password".'], Response::HTTP_BAD_REQUEST);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the logout key on the firewall.');
    }
}
