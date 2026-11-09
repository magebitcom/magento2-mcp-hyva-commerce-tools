<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Hyva\CmsLiveviewEditor\ViewModel\EntityPickerValue;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;

/**
 * Whether a stored Hyvä entity_picker field value names an entity, resolved the way Hyvä renders it.
 *
 * The Liveview editor saves an entity_picker field (cms_block block_identifier, hyva_form_widget form_identifier) as
 * a {source, id, identifier, label} object or a list of them; older content holds a bare string. Hyvä's templates
 * read it with EntityPickerValue::resolveIdentifier($value, [source]), so each entry is resolved with that same
 * method: its identifier, else its id, and nothing when the entry's source is another entity type. Every entry of a
 * list counts (Hyvä renders the first), so a delete never misses a reference. EntityPickerValue is taken from
 * OptionalServiceFactory because composer allows Hyvä CMS releases from before the picker; there only bare strings
 * exist, and those are compared as they are.
 */
class EntityPickerReference
{
    /**
     * @param OptionalServiceFactory $optionalServices
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices
    ) {
    }

    /**
     * Whether the value names one of the references
     *
     * @param mixed $value Stored field value.
     * @param string $source Entity picker source of the field, e.g. cms_block or form.
     * @param array $refs Identifiers (and ids) of the entity.
     * @return bool
     * @phpstan-param list<string> $refs
     */
    public function references(mixed $value, string $source, array $refs): bool
    {
        foreach ($this->resolveAll($value, $source) as $resolved) {
            if (in_array($resolved, $refs, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every identifier the value resolves to
     *
     * @param mixed $value
     * @param string $source
     * @return array
     * @phpstan-return list<string>
     */
    public function resolveAll(mixed $value, string $source): array
    {
        if (!class_exists(EntityPickerValue::class)) {
            return is_scalar($value) && (string) $value !== '' ? [(string) $value] : [];
        }
        $picker = $this->optionalServices->get(EntityPickerValue::class);
        $entries = is_array($value) && $value !== [] && array_is_list($value) ? $value : [$value];
        $resolved = [];
        foreach ($entries as $entry) {
            $identifier = $picker->resolveIdentifier($entry, [$source]);
            if ($identifier !== null) {
                $resolved[] = $identifier;
            }
        }
        return $resolved;
    }
}
