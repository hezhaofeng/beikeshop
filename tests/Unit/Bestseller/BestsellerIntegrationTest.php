<?php

namespace Tests\Unit\Bestseller;

use Beike\Hook\Hook;
use Beike\Plugin\Manager;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Mockery;
use Plugin\Bestseller\Bootstrap;
use Plugin\Bestseller\Controllers\AdminBestsellerSelectorController;
use Plugin\Bestseller\Services\BestsellerSelectionService;

class BestsellerIntegrationTest extends BestsellerTestCase
{
    public function test_plugin_discovery_has_only_the_merged_entry(): void
    {
        $manager = new class extends Manager
        {
            public function configurations(): array
            {
                return $this->getPluginsConfig();
            }
        };
        $configs = $manager->configurations();

        $this->assertSame('bestseller', $configs['Bestseller']['code']);
        $this->assertSame('热卖商品模块与批量选品', $configs['Bestseller']['name']['zh_cn']);
        $this->assertNotContains('bestseller_selector', array_column($configs, 'code'));
    }

    public function test_original_selection_routes_and_permissions_belong_to_merged_controller(): void
    {
        $router = new Router(new Dispatcher($this->app), $this->app);
        $this->app->instance('router', $router);
        $router->prefix('admin')->name('admin.')->middleware(['admin', 'admin_auth:admin'])->group(function (): void {
            require $this->app->basePath('plugins/Bestseller/Routes/admin.php');
        });
        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        $this->assertCount(4, $routes);
        foreach (['state', 'categories', 'products', 'save'] as $action) {
            $route = $routes->getByName('admin.bestseller_selector.' . $action);
            $this->assertNotNull($route);
            $this->assertSame('admin/bestseller-selector/' . $action, $route->uri());
            $this->assertSame(AdminBestsellerSelectorController::class . '@' . $action, $route->getActionName());
            $this->assertSame($action === 'save' ? ['POST'] : ['GET', 'HEAD'], $route->methods());
            $this->assertContains('admin_auth:admin', $route->gatherMiddleware());
            $this->assertContains('can:plugins_update', $route->gatherMiddleware());
        }
    }

    public function test_single_plugin_boot_registers_editors_and_applies_selection_after_core_processing(): void
    {
        (new Bootstrap)->boot();
        $this->assertSame(['editors' => ['editor-bestseller', 'editor-latest_products']], hook_filter('admin.design.index.data', ['editors' => []]));

        $service = Mockery::mock(BestsellerSelectionService::class);
        foreach (['bestseller', 'product', 'tab_product'] as $code) {
            $data = ['module_code' => $code, 'module_id' => 'home-' . $code];
            $service->shouldReceive('applyHomepageSelection')->once()->with($data)->andReturn($data + ['selected' => true]);
        }
        $this->app->instance(BestsellerSelectionService::class, $service);
        foreach (['bestseller', 'product', 'tab_product'] as $code) {
            $data = ['module_code' => $code, 'module_id' => 'home-' . $code];
            $this->assertSame($data + ['selected' => true], hook_filter('service.design.module.content.after', $data));
        }
        $latest = ['module_code' => 'latest_products', 'products' => [1]];
        $this->assertSame($latest, hook_filter('service.design.module.content.after', $latest));
    }

    public function test_selection_panel_renders_only_on_bestseller_edit_page(): void
    {
        $view = Mockery::mock(View::class);
        $view->shouldReceive('render')->once()->andReturn('<section>选品面板</section>');
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('make')->once()->with('Bestseller::admin.selector_panel', [], [])->andReturn($view);
        $this->app->instance(Factory::class, $factory);
        (new Bootstrap)->boot();
        $hook = $this->app->make('Hook');
        $this->assertInstanceOf(Hook::class, $hook);

        $this->assertSame('<section>选品面板</section>', $hook->getHook('admin.plugin.form.after', [
            ['plugin' => (object) ['code' => 'bestseller']],
        ], static fn () => ''));
        foreach (['bestseller_selector', 'latest_products', 'other'] as $code) {
            $this->assertSame('', $hook->getHook('admin.plugin.form.after', [
                ['plugin' => (object) ['code' => $code]],
            ], static fn () => ''));
        }
    }

    public function test_save_endpoint_validates_and_forwards_all_time_sales_mode(): void
    {
        $input   = ['modules' => [
            'hot'    => ['mode' => 'all_time_sales', 'product_ids' => []],
            'normal' => ['mode' => 'all_time_sales', 'product_ids' => []],
        ]];
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('all')->once()->andReturn($input);
        $validator = new ValidatorFactory(new Translator(new ArrayLoader, 'zh_cn'));
        $request->shouldReceive('validate')->once()->andReturnUsing(fn (array $rules): array => $validator->make($input, $rules)->validate());
        $service = Mockery::mock(BestsellerSelectionService::class);
        $service->shouldReceive('saveModules')->once()->with($input['modules'])->andReturn($input);
        $responseFactory = Mockery::mock(ResponseFactory::class);
        $responseFactory->shouldReceive('json')->once()->andReturnUsing(fn (array $data): JsonResponse => new JsonResponse($data));
        $this->app->instance(ResponseFactory::class, $responseFactory);

        $response = (new AdminBestsellerSelectorController)->save($request, $service);
        $this->assertSame('all_time_sales', $response->getData(true)['data']['modules']['hot']['mode']);
        $this->assertSame('all_time_sales', $response->getData(true)['data']['modules']['normal']['mode']);
    }
}
