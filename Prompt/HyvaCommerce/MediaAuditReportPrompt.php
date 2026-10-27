<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Prompt\HyvaCommerce;

use Magebit\Mcp\Api\Data\PromptMessage;
use Magebit\Mcp\Api\PromptInterface;

/**
 * Read-only media audit prompt built on hyva_media.status / optimization.config_validate / audit_all.
 */
class MediaAuditReportPrompt implements PromptInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'hyva_media.audit_report';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Check my images';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'I will check the images used on your storefront for size, missing descriptions and missing optimised'
            . ' versions, and tell you what slows pages down.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getRequiresWrite(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getMessages(): array
    {
        return [
            new PromptMessage(
                PromptMessage::ROLE_USER,
                <<<TXT
                The store owner wants a short report on the images used on their storefront.

                1. Call hyva_media.status, then hyva_media.optimization.config_validate (skip it if image optimisation
                   is not available, and say so).
                2. Call hyva_media.audit_all.
                3. Report in plain language:
                   - images that are too heavy (name, size, where they are used) and which of them are big hero or
                   banner images;
                   - how many images have no description for screen readers (alt text), with the pages where it
                   matters most;
                   - images or files that are missing;
                   - whether modern formats (WebP/AVIF) and automatic image replacement are switched on.
                4. Suggest the three changes with the biggest effect first. You may offer to add missing descriptions
                   with hyva_media.image.update_alt (saved as drafts) or to swap an image with
                   hyva_media.image.replace, but only after the owner agrees.

                Never call hyva_media.optimization.clear in this conversation.
                TXT
            ),
        ];
    }
}
