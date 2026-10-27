<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Form;

use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Form checks Hyvä Form Builder only makes on publish (HyvaFormProvider::assertPublishable, private) or never
 * (recipients, webhook URL), so agents see them before saving or publishing.
 *
 * @phpstan-import-type FormRow from FormGatewayInterface
 * @phpstan-type FormIssue array{severity: string, code: string, uid: string|null, message: string}
 */
class FormRules
{
    public const FIELD_PREFIX = 'form_field_';
    private const CHOICE_FIELDS = ['form_field_select', 'form_field_multiselect'];

    /**
     * @param ComponentTree $componentTree
     */
    public function __construct(
        private readonly ComponentTree $componentTree
    ) {
    }

    /**
     * Fields of a form document: uid, component, field_name, label, required.
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return list<array{uid: string, component: string, field_name: string, label: string,
     *     required: bool, node: array<string, mixed>}>
     */
    public function fields(array $document): array
    {
        $fields = [];
        $this->componentTree->walk($document, function (array $node) use (&$fields): void {
            $component = Cast::string($node['component'] ?? '');
            if (!str_starts_with($component, self::FIELD_PREFIX)) {
                return;
            }
            $fields[] = [
                'uid' => Cast::string($node['uid'] ?? ''),
                'component' => $component,
                'field_name' => Cast::string($node['field_name'] ?? ''),
                'label' => Cast::string($node['label'] ?? ''),
                'required' => Cast::bool($node['required'] ?? false),
                'node' => $node,
            ];
        });
        return $fields;
    }

    /**
     * Content checks: field names (set, format, unique), regex patterns, option defaults.
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return list<FormIssue>
     */
    public function documentIssues(array $document): array
    {
        $issues = [];
        $fields = $this->fields($document);
        if ($fields === []) {
            $issues[] = $this->issue('warning', 'no_fields', null, 'The form has no fields yet.');
        }
        $seen = [];
        foreach ($fields as $field) {
            $name = $field['field_name'];
            if ($name === '') {
                $issues[] = $this->issue('error', 'missing_field_name', $field['uid'], sprintf(
                    'Field "%s" has no field_name; submissions cannot carry its value.',
                    $field['label']
                ));
            } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                $issues[] = $this->issue('error', 'invalid_field_name', $field['uid'], sprintf(
                    'field_name "%s" must start with a letter and use only a-z, 0-9 and _.',
                    $name
                ));
            } elseif (isset($seen[$name])) {
                $issues[] = $this->issue('error', 'duplicate_field_name', $field['uid'], sprintf(
                    'field_name "%s" is used by more than one field (Hyvä refuses to publish this).',
                    $name
                ));
            }
            $seen[$name] = true;

            $pattern = Cast::string($field['node']['pattern'] ?? '');
            if ($pattern !== '' && !$this->compiles($pattern)) {
                $issues[] = $this->issue('error', 'invalid_pattern', $field['uid'], sprintf(
                    'Validation pattern of "%s" is not a valid regular expression.',
                    $field['label']
                ));
            }
            if (in_array($field['component'], self::CHOICE_FIELDS, true)) {
                $options = is_array($field['node']['options'] ?? null) ? $field['node']['options'] : [];
                if ($options === []) {
                    $issues[] = $this->issue('error', 'no_options', $field['uid'], sprintf(
                        'Choice field "%s" has no options.',
                        $field['label']
                    ));
                }
                $values = array_map(
                    static fn ($row): string => is_array($row) ? Cast::string($row['value'] ?? '') : '',
                    $options
                );
                $legacyDefault = Cast::string($field['node']['default_value'] ?? '');
                // Hyvä skips fields whose options carry the new default flag and splits multiselect defaults.
                $defaults = $field['component'] === 'form_field_multiselect'
                    ? array_filter(
                        array_map('trim', explode(',', $legacyDefault)),
                        static fn (string $d): bool => $d !== ''
                    )
                    : ($legacyDefault !== '' ? [$legacyDefault] : []);
                if ($this->flaggedOptions($options)) {
                    $defaults = [];
                }
                foreach ($defaults as $default) {
                    if (!in_array($default, $values, true)) {
                        $issues[] = $this->issue('error', 'default_not_an_option', $field['uid'], sprintf(
                            'Default "%s" of "%s" is not one of its options.',
                            $default,
                            $field['label']
                        ));
                    }
                }
            }
        }
        return $issues;
    }

    /**
     * Delivery checks: where submissions go (Hyvä sends nothing without a To address or a webhook).
     *
     * @param array $form
     * @param array|null $document Published (or draft) content, for reply-to and routing rules.
     * @return array
     * @phpstan-param FormRow $form
     * @phpstan-param array<string, mixed>|null $document
     * @phpstan-return list<FormIssue>
     */
    public function deliveryIssues(array $form, ?array $document): array
    {
        $issues = [];
        $to = $this->addresses($form['recipient_to']);
        $webhook = trim($form['webhook_url']);
        if ($to === [] && $webhook === '') {
            $issues[] = $this->issue('error', 'no_destination', null, 'Submissions go nowhere: set recipient_to '
                . 'and/or webhook_url.');
        }
        foreach (['recipient_to', 'recipient_cc', 'recipient_bcc'] as $key) {
            foreach ($this->addresses($form[$key]) as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                    $issues[] = $this->issue('error', 'invalid_recipient', null, sprintf(
                        '%s contains an invalid address "%s".',
                        $key,
                        $address
                    ));
                }
            }
        }
        if ($webhook !== '') {
            $problem = $this->webhookProblem($webhook);
            if ($problem !== null) {
                $issues[] = $this->issue('error', 'webhook_not_dispatchable', null, $problem);
            } elseif (str_starts_with(strtolower($webhook), 'http://')) {
                $issues[] = $this->issue('warning', 'webhook_not_https', null, 'The webhook URL is not HTTPS.');
            }
            $formats = FormGatewayInterface::WEBHOOK_FORMATS;
            if ($form['webhook_format'] !== '' && !in_array($form['webhook_format'], $formats, true)) {
                $issues[] = $this->issue('error', 'invalid_webhook_format', null, sprintf(
                    'webhook_format must be one of: %s.',
                    implode(', ', FormGatewayInterface::WEBHOOK_FORMATS)
                ));
            }
        }
        if (!$form['is_captcha_enabled']) {
            $issues[] = $this->issue('warning', 'captcha_disabled', null, 'CAPTCHA is off; the form may get spam.');
        }
        if ($form['has_published'] && !$form['is_active']) {
            $issues[] = $this->issue('info', 'published_but_inactive', null, 'The form is published but disabled, '
                . 'so the storefront does not show it.');
        }
        if ($form['is_active'] && !$form['has_published']) {
            $issues[] = $this->issue('warning', 'active_not_published', null, 'The form is enabled but has never '
                . 'been published; the storefront renders nothing.');
        }
        if ($document !== null) {
            foreach ($this->routingIssues($document) as $issue) {
                $issues[] = $issue;
            }
        }
        return $issues;
    }

    /**
     * Why Hyvä would refuse to dispatch to this webhook URL, or null.
     *
     * Hyvä's private WebhookDispatcher::isDispatchableUrl() rules, plus *.localhost hosts (which Hyvä would call, but
     * which resolve to the server itself).
     *
     * @param string $url
     * @return string|null
     */
    public function webhookProblem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower(Cast::string($parts['scheme'] ?? ''));
        $host = strtolower(trim(Cast::string($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'The webhook URL must be an absolute http(s) URL.';
        }
        if (in_array($host, ['localhost', '::1', '0.0.0.0'], true) || str_ends_with($host, '.localhost')) {
            return 'The webhook points at this server; Hyvä refuses local addresses.';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            return 'The webhook points at a private or reserved IP address; Hyvä refuses to call it.';
        }
        return null;
    }

    /**
     * Reply-to and conditional recipient rules on the hyva_form root.
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return list<FormIssue>
     */
    private function routingIssues(array $document): array
    {
        $issues = [];
        $byUid = [];
        foreach ($this->fields($document) as $field) {
            $byUid[$field['uid']] = $field;
        }
        $root = $this->componentTree->getRootNodes($document)[0] ?? [];
        $replyTo = Cast::string($root['reply_to_field_uid'] ?? '');
        if ($replyTo !== '' && ($byUid[$replyTo]['component'] ?? null) !== 'form_field_email') {
            $issues[] = $this->issue('error', 'reply_to_field_missing', $replyTo, 'reply_to_field_uid does not point '
                . 'at an email field of this form.');
        }
        $routing = is_array($root['recipient_routing'] ?? null) ? $root['recipient_routing'] : [];
        $routeField = Cast::string($routing['field_uid'] ?? '');
        if ($routeField !== '') {
            $field = $byUid[$routeField] ?? null;
            if ($field === null) {
                $issues[] = $this->issue('error', 'routing_field_missing', $routeField, 'recipient_routing.field_uid '
                    . 'does not point at a field of this form.');
            }
            foreach (is_array($routing['rules'] ?? null) ? $routing['rules'] : [] as $rule) {
                foreach ($this->addresses(is_array($rule) ? ($rule['recipients'] ?? '') : '') as $address) {
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                        $issues[] = $this->issue('error', 'invalid_routing_recipient', $routeField, sprintf(
                            'Routing rule for "%s" has an invalid address "%s".',
                            Cast::string(is_array($rule) ? ($rule['value'] ?? '') : ''),
                            $address
                        ));
                    }
                }
            }
        }
        return $issues;
    }

    /**
     * Addresses from a comma/semicolon list (or a list).
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<string>
     */
    public function addresses(mixed $value): array
    {
        $list = is_array($value) ? $value : preg_split('/[,;]/', Cast::string($value));
        return array_values(array_filter(array_map(
            static fn ($address): string => trim(Cast::string($address)),
            is_array($list) ? $list : []
        ), static fn (string $address): bool => $address !== ''));
    }

    /**
     * Whether any option row carries Hyvä's default flag.
     *
     * @param array $options
     * @return bool
     * @phpstan-param array<mixed> $options
     */
    private function flaggedOptions(array $options): bool
    {
        foreach ($options as $row) {
            if (is_array($row) && !empty($row['default'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a merchant pattern compiles the way the storefront uses it (HTML pattern, anchored, unicode).
     *
     * @param string $pattern
     * @return bool
     */
    private function compiles(string $pattern): bool
    {
        // An invalid pattern raises a warning; treat it as "does not compile" without logging it.
        set_error_handler(static fn (): bool => true);
        try {
            // Same delimiting as Hyvä's MerchantPattern: ~ delimiter, only ~ escaped.
            return preg_match('~^(?:' . str_replace('~', '\\~', $pattern) . ')$~u', '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Issue.
     *
     * @param string $severity
     * @param string $code
     * @param string|null $uid
     * @param string $message
     * @return array
     * @phpstan-return FormIssue
     */
    private function issue(string $severity, string $code, ?string $uid, string $message): array
    {
        return ['severity' => $severity, 'code' => $code, 'uid' => $uid, 'message' => $message];
    }
}
