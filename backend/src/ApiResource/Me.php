<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Dto\ChangePasswordInput;
use App\Entity\User;
use App\State\MeProcessor;
use App\State\MeProvider;
use App\State\PasswordProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** The signed-in user and their household: what the SPA needs to boot. */
#[ApiResource(
    shortName: 'Me',
    operations: [
        new Get(uriTemplate: '/me', uriVariables: []),
        // read: true, so the current values are loaded before the merge-patch is applied
        // (API Platform skips reading on PATCH when the URI has no variables).
        new Patch(uriTemplate: '/me', uriVariables: [], read: true, processor: MeProcessor::class),
        new Post(
            uriTemplate: '/me/password',
            uriVariables: [],
            status: 204,
            description: 'Change the password. Other devices are signed out; this one stays signed in.',
            denormalizationContext: ['groups' => ['me:password']],
            input: ChangePasswordInput::class,
            output: false,
            read: false,
            processor: PasswordProcessor::class,
            name: 'me_change_password',
        ),
    ],
    normalizationContext: ['groups' => ['me:read']],
    denormalizationContext: ['groups' => ['me:write']],
    security: "is_granted('ROLE_USER')",
    provider: MeProvider::class,
)]
final class Me
{
    #[ApiProperty(identifier: true)]
    #[Groups(['me:read'])]
    public string $id = '';

    #[Groups(['me:read'])]
    public string $email = '';

    #[Groups(['me:read', 'me:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $displayName = '';

    #[Groups(['me:read', 'me:write'])]
    #[Assert\Choice(choices: User::LOCALES)]
    public string $locale = 'es';

    #[Groups(['me:read'])]
    public string $householdName = '';

    #[Groups(['me:read'])]
    public int $defaultServings = 4;

    /**
     * The models extraction uses now: a recipe extracted with another one (Amazon Nova, an older
     * Claude) can be extracted again with them.
     *
     * @var list<string>
     */
    #[Groups(['me:read'])]
    public array $extractionModels = [];

    /** @param list<string> $extractionModels */
    public static function fromUser(User $user, array $extractionModels = []): self
    {
        $me = new self();
        $me->id = $user->getId()->toRfc4122();
        $me->email = $user->getEmail();
        $me->displayName = $user->getDisplayName();
        $me->locale = $user->getLocale();
        $me->householdName = $user->getHousehold()->getName();
        $me->defaultServings = $user->getHousehold()->getDefaultServings();
        $me->extractionModels = $extractionModels;

        return $me;
    }
}
