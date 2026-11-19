<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\SourceInput;
use App\Entity\Source;
use App\Library\LinkSaver;
use App\Security\CurrentHousehold;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Saves a link to the library from the app (see LinkSaver), refusing duplicates.
 *
 * @implements ProcessorInterface<SourceInput, Source>
 */
final readonly class CreateSourceProcessor implements ProcessorInterface
{
    public const string DUPLICATE_ERROR = 'b9a3c7e2-5d1f-4c8a-9e6b-2f7d0a4c1e55';
    public const string INVALID_URL_ERROR = 'e4f1a2b3-6c7d-4e8f-9a0b-1c2d3e4f5a6b';

    public function __construct(
        private CurrentHousehold $current,
        private LinkSaver $links,
    ) {
    }

    /** @param SourceInput $data */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Source
    {
        try {
            [$source, $saved] = $this->links->save($this->current->user(), $data->url);
        } catch (\InvalidArgumentException) {
            throw $this->violation($data, 'This is not a valid web link.', self::INVALID_URL_ERROR);
        }
        if (!$saved) {
            throw $this->violation($data, 'This link is already in your library.', self::DUPLICATE_ERROR);
        }

        return $source;
    }

    private function violation(SourceInput $data, string $message, string $code): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, null, [], $data, 'url', $data->url, null, $code),
        ]));
    }
}
