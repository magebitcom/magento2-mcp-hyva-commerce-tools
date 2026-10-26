<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Audit;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;

/**
 * Reads the Magebit_Mcp audit log (magebit_mcp_audit_log) for dashboard widgets.
 *
 * The audit log is the core's data and the core guards it with Magebit_Mcp::mcp_audit; this reader returns nothing
 * to an admin without that permission, whichever widget asks. Write tools are derived from the core tool registry
 * (WriteMode::WRITE), so every registered write tool counts, the core's and other satellites' too.
 *
 * Every write tool is either a draft write or a live write, by its `<area>.<entity>.<verb>` name:
 * - draft write: a tool of this module (`hyva_` area) that only saves Hyvä drafts, i.e. its verb is one of
 *   DRAFT_VERBS, or one of DRAFT_ENTITY_VERBS on an entity in DRAFT_ENTITIES (page, block, draft, menu, menu item,
 *   form, translation), and it is not destructive. Their settings changes that go live need "confirm".
 * - live write: every other write tool, i.e. destructive tools (DESTRUCTIVE_VERBS), immediate changes such as
 *   system.config.set, cache/index writes, the core's cms.page.create/update, category positions, store credit,
 *   linked groups, schedules and folders, and any write tool the rule does not know. Unknown tools land here on
 *   purpose: a write is shown as a draft only when its name says so.
 *
 * @phpstan-type ActivityRow array{id: int, tool: string, admin: string, status: string, error_code: int|null,
 *     created_at: string, summary: array<mixed>}
 */
class McpActivityReader
{
    /**
     * Last segment of a write tool's name that marks it destructive: it publishes to the storefront, overwrites,
     * moves, removes or rolls back existing data. The verb is the stable part of the `<area>.<entity>.<verb>`
     * naming every MCP tool follows, so a new write tool is classified the moment it is registered.
     */
    public const DESTRUCTIVE_VERBS = [
        'publish', 'delete', 'trash', 'remove', 'clear', 'restore', 'import', 'replace', 'cancel', 'move',
        'flush', 'reset', 'disable',
    ];

    /**
     * Last segments of this module's tools that only ever write drafts.
     */
    public const DRAFT_VERBS = ['create_draft', 'update_draft', 'convert_to_draft', 'update_alt'];

    /**
     * Last segments that write drafts when the entity (the segment before) holds Hyvä CMS content.
     */
    public const DRAFT_ENTITY_VERBS = ['create', 'update', 'add', 'run'];

    /**
     * Entities whose create/update/add/run tools save Hyvä content as a draft (`hyva_menu` = hyva_menu.create).
     */
    public const DRAFT_ENTITIES = ['page', 'block', 'draft', 'hyva_menu', 'item', 'form', 'translation'];

    public const AUDIT_RESOURCE = 'Magebit_Mcp::mcp_audit';

    /**
     * @var array{write: list<string>, destructive: list<string>, draft: list<string>}|null
     */
    private ?array $toolSets = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param AuthorizationInterface $authorization
     * @param ToolRegistryInterface $toolRegistry
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly AuthorizationInterface $authorization,
        private readonly ToolRegistryInterface $toolRegistry
    ) {
    }

    /**
     * Names of every registered tool whose write mode is WRITE.
     *
     * @return list<string>
     */
    public function getWriteTools(): array
    {
        return $this->getToolSets()['write'];
    }

    /**
     * Names of the registered write tools that are destructive (see DESTRUCTIVE_VERBS).
     *
     * @return list<string>
     */
    public function getDestructiveTools(): array
    {
        return $this->getToolSets()['destructive'];
    }

    /**
     * Registered write tools that only save drafts (see the class docblock).
     *
     * @return list<string>
     */
    public function getDraftWriteTools(): array
    {
        return $this->getToolSets()['draft'];
    }

    /**
     * Registered write tools that are not draft writes: publish, immediate (live) and destructive actions.
     *
     * @return list<string>
     */
    public function getLiveWriteTools(): array
    {
        return array_values(array_diff($this->getWriteTools(), $this->getDraftWriteTools()));
    }

    /**
     * Whether a write tool's name marks it as a draft write (see the class docblock).
     *
     * @param string $toolName
     * @return bool
     */
    public function isDraftWriteName(string $toolName): bool
    {
        if (!str_starts_with($toolName, 'hyva_') || $this->isDestructiveName($toolName)) {
            return false;
        }
        $segments = explode('.', $toolName);
        $verb = (string) array_pop($segments);
        if (in_array($verb, self::DRAFT_VERBS, true)) {
            return true;
        }
        return in_array($verb, self::DRAFT_ENTITY_VERBS, true)
            && in_array((string) end($segments), self::DRAFT_ENTITIES, true);
    }

    /**
     * Whether a tool name is destructive by its last segment. Only meaningful for write tools.
     *
     * @param string $toolName
     * @return bool
     */
    public function isDestructiveName(string $toolName): bool
    {
        $segments = explode('.', $toolName);
        $verb = (string) end($segments);
        foreach (self::DESTRUCTIVE_VERBS as $destructive) {
            if ($verb === $destructive || str_starts_with($verb, $destructive . '_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read the registry once per request.
     *
     * @return array{write: list<string>, destructive: list<string>, draft: list<string>}
     */
    private function getToolSets(): array
    {
        if ($this->toolSets === null) {
            $write = [];
            $destructive = [];
            $draft = [];
            foreach ($this->toolRegistry->all() as $tool) {
                if ($tool->getWriteMode() !== WriteMode::WRITE) {
                    continue;
                }
                $name = $tool->getName();
                $write[] = $name;
                if ($this->isDestructiveName($name)) {
                    $destructive[] = $name;
                } elseif ($this->isDraftWriteName($name)) {
                    $draft[] = $name;
                }
            }
            $this->toolSets = ['write' => $write, 'destructive' => $destructive, 'draft' => $draft];
        }
        return $this->toolSets;
    }

    /**
     * Whether the current admin may read the MCP audit log.
     *
     * @return bool
     */
    public function isAllowed(): bool
    {
        return $this->authorization->isAllowed(self::AUDIT_RESOURCE);
    }

    /**
     * Get rows.
     *
     * @param array $filters
     * @param int $limit
     * @return array
     * @phpstan-param array{since?: string|null, tool_prefix?: string|null, tools?: list<string>,
     *     status?: string|null} $filters
     * @phpstan-return list<ActivityRow>
     */
    public function getRows(array $filters = [], int $limit = 10): array
    {
        if (!$this->isAllowed()) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magebit_mcp_audit_log');
        if (!$connection->isTableExists($table)) {
            return [];
        }
        $select = $connection->select()
            ->from(
                ['l' => $table],
                ['id', 'tool_name', 'response_status', 'error_code', 'created_at', 'result_summary_json']
            )
            ->joinLeft(
                ['u' => $this->resourceConnection->getTableName('admin_user')],
                'u.user_id = l.admin_user_id',
                ['username']
            )
            ->where('l.method = ?', 'tools/call')
            ->where('l.tool_name IS NOT NULL')
            ->order('l.id DESC')
            ->limit($limit);
        if (!empty($filters['since'])) {
            $select->where('l.created_at >= ?', $filters['since']);
        }
        if (!empty($filters['tool_prefix'])) {
            $select->where('l.tool_name LIKE ?', $filters['tool_prefix'] . '%');
        }
        if (array_key_exists('tools', $filters)) {
            if ($filters['tools'] === []) {
                return [];
            }
            $select->where('l.tool_name IN (?)', $filters['tools']);
        }
        if (!empty($filters['status'])) {
            $select->where('l.response_status = ?', $filters['status']);
        }
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $summary = json_decode(Cast::string($row['result_summary_json'] ?? ''), true);
            $rows[] = [
                'id' => Cast::int($row['id']),
                'tool' => Cast::string($row['tool_name']),
                'admin' => Cast::string($row['username'] ?? '', '?'),
                'status' => Cast::string($row['response_status']),
                'error_code' => $row['error_code'] !== null ? Cast::int($row['error_code']) : null,
                'created_at' => Cast::string($row['created_at']),
                'summary' => is_array($summary) ? $summary : [],
            ];
        }
        return $rows;
    }

    /**
     * Get totals.
     *
     * @param string $since UTC 'Y-m-d H:i:s'
     * @return array
     * @phpstan-return array{calls: int, errors: int, writes: int, admins: int}
     */
    public function getTotals(string $since): array
    {
        $empty = ['calls' => 0, 'errors' => 0, 'writes' => 0, 'admins' => 0];
        if (!$this->isAllowed()) {
            return $empty;
        }
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magebit_mcp_audit_log');
        if (!$connection->isTableExists($table)) {
            return $empty;
        }
        $writeTools = $this->getWriteTools();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, [
                    'calls' => 'COUNT(*)',
                    'errors' => "SUM(response_status <> 'ok')",
                    'writes' => $writeTools === []
                        ? 'SUM(0)'
                        : $connection->quoteInto('SUM(tool_name IN (?))', $writeTools),
                    'admins' => 'COUNT(DISTINCT admin_user_id)',
                ])
                ->where('method = ?', 'tools/call')
                ->where('created_at >= ?', $since)
        );
        $row = is_array($row) ? $row : [];
        return [
            'calls' => Cast::int($row['calls'] ?? 0),
            'errors' => Cast::int($row['errors'] ?? 0),
            'writes' => Cast::int($row['writes'] ?? 0),
            'admins' => Cast::int($row['admins'] ?? 0),
        ];
    }
}
