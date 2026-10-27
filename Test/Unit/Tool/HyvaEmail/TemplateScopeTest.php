<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaEmail;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template\TemplateCreateDraft;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template\TemplatePublish;
use PHPUnit\Framework\TestCase;

/**
 * Email templates are global: a scope-restricted role may not change them.
 */
class TemplateScopeTest extends TestCase
{
    use StoreScopeStub;

    public function testARestrictedRoleMayNotCreateOrPublishTemplates(): void
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $gateway = $this->createMock(EmailGatewayInterface::class);
        $gateway->expects($this->never())->method('createFromCore');
        $contentService = $this->createMock(ContentService::class);
        $contentService->expects($this->never())->method('publish');
        $guard = $this->storeScope([1]);
        $previews = $this->createMock(PreviewService::class);

        foreach ([TemplateCreateDraft::class, TemplatePublish::class] as $class) {
            $result = (new $class($features, $gateway, $contentService, $previews, $guard))->execute(
                ['id' => 4, 'orig_template_code' => 'customer_create_account_email_template', 'confirm' => true]
            );
            $this->assertTrue($result->isError(), $class);
            $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '', $class);
        }
    }
}
