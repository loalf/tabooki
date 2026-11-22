<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Dto\SwapFeedbackInput;
use App\State\SwapFeedbackProcessor;

/** Why a planned meal was swapped (App\Entity\SwapFeedback): what the household's swaps teach. */
#[ApiResource(
    shortName: 'SwapFeedback',
    operations: [
        new Post(
            uriTemplate: '/swap-feedback',
            status: 204,
            description: 'Say why a meal was swapped. Repeated reasons become suggestions (GET /api/taste-suggestions); a dish nobody likes comes up less.',
            input: SwapFeedbackInput::class,
            output: false,
            read: false,
            processor: SwapFeedbackProcessor::class,
            name: 'swap_feedback',
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
final class SwapFeedbackCall
{
}
