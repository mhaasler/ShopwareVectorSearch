<?php declare(strict_types=1);

namespace MHaasler\ShopwareVectorSearch;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class ShopwareVectorSearch extends Plugin
{
    /**
     * Enable automatic composer dependency installation
     * This tells Shopware to run "composer install" in the plugin directory
     * when the plugin is installed or updated
     */
    public function executeComposerCommands(): bool
    {
        return true;
    }

    public function configureRoutes(RoutingConfigurator $routes, string $environment): void
    {
        $routes->import(__DIR__ . '/Resources/config/routes.xml');
    }

    public function install(InstallContext $installContext): void
    {
        // Installation logic will be handled by migration
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        
        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Clean up vector data if user doesn't want to keep data
        // This will be implemented in the service
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Plugin activation logic
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // Plugin deactivation logic
    }
} 