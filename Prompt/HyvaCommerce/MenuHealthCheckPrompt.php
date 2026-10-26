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
 * Read-only menu health check prompt built on hyva_menu.status / audit_all / find_usage.
 */
class MenuHealthCheckPrompt implements PromptInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'hyva_menu.health_check';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Check my menus';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'I will check every navigation menu for broken links, disabled categories or products and empty '
            . 'sections, and tell you what to fix.';
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
                The store owner wants to know whether their navigation menus are healthy.

                1. Call hyva_menu.status. If Menu Builder is not available, explain that in one sentence (use the
                   reason from the result) and stop.
                2. Call hyva_menu.audit_all.
                3. For every menu with errors, call hyva_menu.find_usage so you can say where shoppers see it (header,
                   footer, inside a page).
                4. Report in plain language, most serious first: links that lead nowhere (deleted categories, products
                   or pages), disabled categories or products still linked, empty columns or promo blocks, menus that
                   are not shown anywhere. Name each menu and the item label.
                5. Offer concrete fixes ("remove the 'Old Sale' link from the main menu", "re-enable the Shorts
                   category"), but do not change anything until the owner says so.

                Translate jargon: say "link to a category that is switched off", not "inactive_category"; say "menu at
                the top of the site", not "design/header/topmenu_identifier".
                TXT
            ),
        ];
    }
}
