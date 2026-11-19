<?php

declare(strict_types=1);

namespace App\Controller;

use App\Library\LinkSaver;
use App\Security\CurrentHousehold;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Saves a link sent from outside the app, authenticated with a share token (see ShareToken):
 * the iPhone Shortcut posts whatever the share sheet gave it. Accepts JSON or form fields
 * (url, text, title) or a plain-text body, and finds the link in them. The message is meant to
 * be shown as the Shortcut's notification.
 */
#[AsController]
final readonly class ShareController
{
    private const array MESSAGES = [
        'es' => ['saved' => 'Guardado en Tabooki', 'duplicate' => 'Ya estaba en tu biblioteca', 'no_link' => 'No hemos encontrado ningún enlace'],
        'en' => ['saved' => 'Saved to Tabooki', 'duplicate' => 'Already in your library', 'no_link' => "We didn't find a link"],
    ];

    public function __construct(private LinkSaver $links, private CurrentHousehold $current)
    {
    }

    #[Route('/api/share', name: 'api_share', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->current->user();
        $messages = self::MESSAGES['en' === $user->getLocale() ? 'en' : 'es'];

        $fields = $this->fields($request);
        $url = LinkSaver::findUrl($fields['url'] ?? null, $fields['text'] ?? null, $fields['title'] ?? null);
        try {
            [$source, $saved] = null !== $url ? $this->links->save($user, $url) : throw new \InvalidArgumentException();
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['status' => 'no_link', 'message' => $messages['no_link']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'status' => $saved ? 'saved' : 'duplicate',
            'message' => $messages[$saved ? 'saved' : 'duplicate'],
            'id' => $source->getId()->toRfc4122(),
            'url' => $source->getUrl(),
        ], $saved ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** @return array{url?: string, text?: string, title?: string} */
    private function fields(Request $request): array
    {
        $content = $request->getContent();
        $data = str_contains((string) $request->headers->get('Content-Type'), 'json')
            ? json_decode($content, true)
            : ([] !== $request->request->all() ? $request->request->all() : $content);
        if (\is_string($data)) {
            return ['text' => $data];
        }

        $fields = [];
        foreach (['url', 'text', 'title'] as $key) {
            if (\is_array($data) && \is_string($data[$key] ?? null)) {
                $fields[$key] = $data[$key];
            }
        }

        return $fields;
    }
}
