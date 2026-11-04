<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuRenderCheck;
use Magento\Framework\Exception\AuthorizationException;
use PHPUnit\Framework\TestCase;

/**
 * hyva_menu.render_check renders only in store views the caller's role may read.
 */
class MenuRenderCheckScopeTest extends TestCase
{
    use StoreScopeStub;

    /**
     * @var list<int>
     */
    private array $rendered = [];

    public function testTheMenusStoreViewsAreNarrowedToTheRole(): void
    {
        $this->tool([1], [1, 2])->execute(['id' => 4]);
        $this->assertSame([1], $this->rendered);

        $this->rendered = [];
        $this->tool(null, [1, 2])->execute(['id' => 4]);
        $this->assertSame([1, 2], $this->rendered);
    }

    public function testAMenuOnlyInOtherStoreViewsFallsBackToTheRolesStoreView(): void
    {
        $this->tool([1], [2], 1)->execute(['id' => 4]);

        $this->assertSame([1], $this->rendered);
    }

    public function testAnExplicitStoreViewOutsideTheRoleIsRefused(): void
    {
        $result = $this->tool([1], [1, 2])->execute(['id' => 4, 'store_id' => 2]);

        $this->assertTrue($result->isError());
        $this->assertSame([], $this->rendered);
    }

    public function testEmptyRenderExplainsInactive(): void
    {
        $check = $this->emptyCheck(['is_active' => false, 'is_published' => true]);

        $this->assertFalse($check['passed'] ?? null);
        $this->assertArrayHasKey('error', $check);
        $this->assertNull($check['error']);
        $this->assertStringContainsString('inactive', Cast::string($check['message'] ?? ''));
    }

    public function testEmptyRenderExplainsNotPublished(): void
    {
        $check = $this->emptyCheck(['is_active' => true, 'is_published' => false]);

        $this->assertFalse($check['passed'] ?? null);
        $this->assertStringContainsString('not published', Cast::string($check['message'] ?? ''));
    }

    public function testEmptyRenderOtherwiseGenericMessage(): void
    {
        $check = $this->emptyCheck(['is_active' => true, 'is_published' => true]);

        $message = Cast::string($check['message'] ?? '');
        $this->assertFalse($check['passed'] ?? null);
        $this->assertStringContainsString('rendered no content', $message);
        $this->assertStringNotContainsString('inactive', $message);
        $this->assertStringNotContainsString('not published', $message);
    }

    public function testEmptyRenderExplainsAStoreViewTheMenuIsNotAssignedTo(): void
    {
        $check = $this->emptyCheck(['is_active' => true, 'is_published' => true], null, [1], 2);

        $this->assertSame(2, $check['store_id'] ?? null);
        $this->assertFalse($check['passed'] ?? null);
        $this->assertSame(
            'Nothing rendered: the menu is not assigned to store view 2.',
            $check['message'] ?? null
        );
    }

    public function testByIdentifierAStoreViewTheMenuIsNotAssignedToGivesTheSameReasonAsById(): void
    {
        // T-135: the store-filtered identifier lookup misses the menu; it is then found in any store view.
        $located = [];
        $menus = $this->createMock(MenuService::class);
        $menus->method('locate')->willReturnCallback(
            static function (array $arguments) use (&$located): array {
                $located[] = $arguments;
                return ['id' => 4, 'identifier' => 'main', 'store_ids' => [1], 'is_active' => true,
                    'is_published' => true];
            }
        );
        $menus->method('resolveImportStoreId')->willReturnArgument(0);
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('findIdByIdentifier')->willReturnMap([['main', 2, null], ['main', null, 4]]);
        $gateway->method('render')->willReturn(['html' => '', 'message' => null, 'error' => null]);
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $tool = new MenuRenderCheck($features, $menus, $gateway, $this->storeScope(null));

        $result = $tool->execute(['identifier' => 'main', 'store_id' => 2]);
        $payload = json_decode(Cast::string($result->getContent()[0]['text'] ?? ''), true);

        $this->assertFalse($result->isError());
        $this->assertSame([['identifier' => 'main']], $located);
        $this->assertIsArray($payload);
        $this->assertSame(2, $payload['checks'][0]['store_id'] ?? null);
        $this->assertSame(
            'Nothing rendered: the menu is not assigned to store view 2.',
            $payload['checks'][0]['message'] ?? null
        );
    }

    public function testByIdentifierAMenuOfThatStoreViewIsLocatedWithIt(): void
    {
        $located = [];
        $menus = $this->createMock(MenuService::class);
        $menus->method('locate')->willReturnCallback(
            static function (array $arguments) use (&$located): array {
                $located[] = $arguments;
                return ['id' => 5, 'identifier' => 'main', 'store_ids' => [2], 'is_active' => true,
                    'is_published' => true];
            }
        );
        $menus->method('resolveImportStoreId')->willReturnArgument(0);
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('findIdByIdentifier')->willReturn(5);
        $gateway->method('render')->willReturn(['html' => '<a href="/">Home</a>', 'message' => null, 'error' => null]);
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $tool = new MenuRenderCheck($features, $menus, $gateway, $this->storeScope(null));

        $tool->execute(['identifier' => 'main', 'store_id' => 2]);

        $this->assertSame([['identifier' => 'main', 'store_id' => 2]], $located);
    }

    public function testAnAllStoreViewsMenuIsNeverReportedAsNotAssigned(): void
    {
        $check = $this->emptyCheck(['is_active' => true, 'is_published' => true], null, [0], 2);

        $message = Cast::string($check['message'] ?? '');
        $this->assertSame(2, $check['store_id'] ?? null);
        $this->assertStringNotContainsString('not assigned', $message);
        $this->assertStringContainsString('rendered no content', $message);
    }

    public function testTheRenderersOwnMessageIsKept(): void
    {
        $check = $this->emptyCheck(['is_active' => false, 'is_published' => false], 'Circular <b>reference</b>');

        $this->assertSame('Circular reference', $check['message'] ?? null);
    }

    public function testARenderedMenuHasNoMessage(): void
    {
        $text = $this->tool(null, [1])->execute(['id' => 4])->getContent()[0]['text'] ?? '';
        $payload = json_decode(Cast::string($text), true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['checks'][0]['passed'] ?? null);
        $this->assertIsArray($payload['checks'][0] ?? null);
        $this->assertArrayHasKey('message', $payload['checks'][0]);
        $this->assertNull($payload['checks'][0]['message']);
    }

    /**
     * Check for menu 4 when Hyvä renders no HTML and no error (store view 1 unless $storeId is given).
     *
     * @param array<string, mixed> $state
     * @param string|null $rendererMessage
     * @param list<int> $menuStoreIds
     * @param int|null $storeId Explicit store_id argument.
     * @return array<string, mixed>
     */
    private function emptyCheck(
        array $state,
        ?string $rendererMessage = null,
        array $menuStoreIds = [1],
        ?int $storeId = null
    ): array {
        $render = ['html' => '', 'message' => $rendererMessage, 'error' => null];
        $arguments = $storeId === null ? ['id' => 4] : ['id' => 4, 'store_id' => $storeId];
        $text = $this->tool(null, $menuStoreIds, null, $state, $render)->execute($arguments)
            ->getContent()[0]['text'] ?? '';
        $payload = json_decode(Cast::string($text), true);
        $this->assertIsArray($payload);
        $check = $payload['checks'][0] ?? null;
        $this->assertIsArray($check);
        /** @var array<string, mixed> $check */
        return $check;
    }

    /**
     * Render check for menu 4 in $menuStoreIds, for a caller restricted to $allowedStoreIds (null = unrestricted).
     *
     * @param array|null $allowedStoreIds
     * @param array $menuStoreIds
     * @param int|null $fallback Store view the menu service resolves without one.
     * @param array $state Extra menu row keys (is_active, is_published).
     * @param array|null $render Gateway render result; default a rendered link.
     * @return MenuRenderCheck
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-param list<int> $menuStoreIds
     * @phpstan-param array<string, mixed> $state
     * @phpstan-param array{html: string, message: string|null, error: string|null}|null $render
     */
    private function tool(
        ?array $allowedStoreIds,
        array $menuStoreIds,
        ?int $fallback = null,
        array $state = ['is_active' => true, 'is_published' => true],
        ?array $render = null
    ): MenuRenderCheck {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $menus = $this->createMock(MenuService::class);
        $menus->method('locate')->willReturn(
            ['id' => 4, 'identifier' => 'main', 'store_ids' => $menuStoreIds] + $state
        );
        $menus->method('resolveImportStoreId')->willReturnCallback(
            static function (?int $storeId) use ($allowedStoreIds, $fallback): int {
                if ($storeId !== null && $allowedStoreIds !== null && !in_array($storeId, $allowedStoreIds, true)) {
                    throw new AuthorizationException(__('Outside the role.'));
                }
                return $storeId ?? $fallback ?? 1;
            }
        );
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('render')->willReturnCallback(
            function (string $identifier, int $storeId) use ($render): array {
                $this->rendered[] = $storeId;
                return $render ?? ['html' => '<a href="/">Home</a>', 'message' => null, 'error' => null];
            }
        );
        return new MenuRenderCheck($features, $menus, $gateway, $this->storeScope($allowedStoreIds));
    }
}
