<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\SourceInput;
use App\Entity\Source;
use App\Library\UrlNormalizer;
use App\Message\FetchSourceMetadata;
use App\Repository\SourceRepository;
use App\Security\CurrentHousehold;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Saves a link to the library: normalizes it, refuses duplicates, and queues the preview fetch.
 *
 * @implements ProcessorInterface<SourceInput, Source>
 */
final readonly class CreateSourceProcessor implements ProcessorInterface
{
    public const string DUPLICATE_ERROR = 'b9a3c7e2-5d1f-4c8a-9e6b-2f7d0a4c1e55';
    public const string INVALID_URL_ERROR = 'e4f1a2b3-6c7d-4e8f-9a0b-1c2d3e4f5a6b';

    public function __construct(
        private CurrentHousehold $current,
        private UrlNormalizer $urlNormalizer,
        private SourceRepository $sources,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    /** @param SourceInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Source
    {
        try {
            $url = $this->urlNormalizer->withScheme($data->url);
            $normalizedUrl = $this->urlNormalizer->normalize($url);
        } catch (\InvalidArgumentException) {
            throw $this->violation($data, 'This is not a valid web link.', self::INVALID_URL_ERROR);
        }

        $household = $this->current->household();
        $existing = $this->sources->findOneBy(['household' => $household, 'normalizedUrl' => $normalizedUrl]);
        if (null !== $existing) {
            throw $this->violation($data, 'This link is already in your library.', self::DUPLICATE_ERROR);
        }

        $source = new Source($household, $url, $normalizedUrl, $this->urlNormalizer->detectPlatform($url), $this->current->user());
        $this->entityManager->persist($source);
        $this->entityManager->flush();

        $this->bus->dispatch(new FetchSourceMetadata($source->getId()->toRfc4122()));

        return $source;
    }

    private function violation(SourceInput $data, string $message, string $code): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, null, [], $data, 'url', $data->url, null, $code),
        ]));
    }
}
