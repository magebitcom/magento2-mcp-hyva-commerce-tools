<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

/**
 * Media files have no store scope: the Hyvä Media Gallery has no website or store view assignment, and the core MCP
 * module leaves data without a store assignment unscoped for website-restricted admins too (system.store.list,
 * system.log.*). Usages of a file are scoped (MediaAuditor, ImageUsageIndex callers); the files are not.
 */
class SharedMedia
{
    /** Sentence for media tool descriptions. */
    public const DESCRIPTION_NOTE = 'Media files are shared by all websites: the Hyvä Media Gallery has no website or '
        . 'store view scope, so every admin, a website-restricted one included, sees the same files.';
}
