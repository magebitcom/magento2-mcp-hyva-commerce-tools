<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Schema;

use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;

/**
 * Raw JSON-schema fragments for open-shaped Hyvä CMS inputs (component nodes carry arbitrary field keys).
 */
class ContentSchema
{
    public const COMPONENTS = [
        'type' => 'array',
        'description' => 'Root components, in order. Each item is {"component": "<name>", "fields": {...}, '
            . '"children": [...]} or a raw node with field values on the node. Missing fields get the component '
            . 'defaults; uids are generated. Use hyva_cms.component.list / hyva_cms.component.schema for names and '
            . 'fields.',
        'items' => [
            'type' => 'object',
            'properties' => ['component' => ['type' => 'string']],
            'required' => ['component'],
        ],
    ];

    public const OPERATIONS = [
        'type' => 'array',
        'description' => 'Draft edits applied in order (all or nothing): add {component, fields?, children?, '
            . 'parent_uid?, position?}; remove {uid}; move {uid, parent_uid?, position?}; set_field {uid, field, '
            . 'value}; set_fields {uid, fields}; replace_node {uid, node}; replace_text {find, replace, fields?}. '
            . 'parent_uid omitted = root; position is zero-based.',
        'items' => [
            'type' => 'object',
            'properties' => ['op' => ['type' => 'string', 'enum' => DraftEditor::OPERATIONS]],
            'required' => ['op'],
        ],
    ];
}
