<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Prompt\HyvaCommerce;

use Magebit\Mcp\Api\Data\PromptArgument;
use Magebit\Mcp\Api\Data\PromptMessage;
use Magebit\Mcp\Api\PromptInterface;

/**
 * Campaign landing page prompt: builds a Hyvä CMS page as a draft from a short brief.
 */
class LandingPageDraftPrompt implements PromptInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'hyva_cms.landing_page_draft';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Draft a campaign landing page';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Describe a campaign and I will build a Hyvä CMS landing page as a draft for you to review. Nothing '
            . 'goes live.';
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return [
            new PromptArgument(
                name: 'campaign',
                description: 'What is the page for? (e.g. "Black Friday: up to 50% off outdoor gear")',
                required: true
            ),
            new PromptArgument(
                name: 'url_key',
                description: 'Optional URL key, e.g. "black-friday"',
                required: false
            ),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getRequiresWrite(): bool
    {
        return true;
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
                The store owner wants a new landing page for this campaign: "{{campaign}}". Preferred URL key:
                "{{url_key}}" (make one up from the campaign name if empty; lowercase, hyphens).

                Build it as a Hyvä CMS DRAFT. Never publish and never schedule it in this conversation.

                1. Call hyva_cms.component.list once to see which building blocks exist, and hyva_cms.component.schema
                   for each component you plan to use (row, heading, text, image, button, banner are typical) so every
                   field value is valid.
                2. Write short, concrete copy: one headline, one or two sentences of supporting text, one
                   call-to-action button. Do not invent prices, dates or discounts the owner did not give you.
                3. Call hyva_cms.page.create with the title, URL key and the components. Leave is_active false.
                4. Call hyva_cms.component.validate on the new page; fix anything it reports with hyva_cms.draft.update.
                5. Reply in plain language: what is on the page, the preview link from the result (it works without
                   logging in), and that it stays invisible to shoppers until they ask you to publish it.

                Keep technical words out of the reply ("draft", "preview link" and "publish" are fine; component names
                and JSON are not).
                TXT
            ),
        ];
    }
}
