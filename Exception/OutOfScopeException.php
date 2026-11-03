<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Exception;

use Magento\Framework\Exception\AuthorizationException;

/**
 * Refusal by the caller's website / store view restriction (Hyvä Advanced Admin Permissions), as opposed to a missing
 * permission; tools answer both with the soft 403, and audit this one as "out_of_scope".
 */
class OutOfScopeException extends AuthorizationException
{
}
