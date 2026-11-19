<?php

declare(strict_types=1);

namespace App\Library;

use App\Entity\Source;
use App\Entity\User;
use App\Message\FetchSourceMetadata;
use App\Repository\SourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Saves a link to a household's library, whichever way it arrives (the app, a share sheet). */
final readonly class LinkSaver
{
    public function __construct(
        private UrlNormalizer $urls,
        private SourceRepository $sources,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Normalizes the link, returns the saved one if the household has it already, and otherwise
     * saves it and queues its preview (which then queues the extraction).
     *
     * @return array{Source, bool} the source, and whether it was saved now
     *
     * @throws \InvalidArgumentException when it isn't a web link
     */
    public function save(User $user, string $url): array
    {
        $url = $this->urls->withScheme($url);
        $normalizedUrl = $this->urls->normalize($url);
        $household = $user->getHousehold();

        $existing = $this->sources->findOneBy(['household' => $household, 'normalizedUrl' => $normalizedUrl]);
        if (null !== $existing) {
            return [$existing, false];
        }

        $source = new Source($household, $url, $normalizedUrl, $this->urls->detectPlatform($url), $user);
        $this->entityManager->persist($source);
        $this->entityManager->flush();
        $this->bus->dispatch(new FetchSourceMetadata($source->getId()->toRfc4122()));

        return [$source, true];
    }

    /**
     * The link in a share sheet's text: apps share "Look at this! https://…", sometimes with the
     * link in the title or text instead of the url field.
     */
    public static function findUrl(?string ...$texts): ?string
    {
        foreach ($texts as $text) {
            if (null !== $text && 1 === preg_match('#https?://[^\s<>"]+#i', $text, $match)) {
                return rtrim($match[0], '.,;:!?)]}');
            }
        }

        return null;
    }
}
