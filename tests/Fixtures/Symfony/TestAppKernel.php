<?php

declare(strict_types=1);

namespace K911\Swoole\Tests\Fixtures\Symfony;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use K911\Swoole\Bridge\Symfony\Bundle\SwooleBundle;
use K911\Swoole\Bridge\Symfony\Kernel\CoroutinesSupportingKernelTrait;
use K911\Swoole\Tests\Fixtures\Symfony\CoverageBundle\CoverageBundle;
use K911\Swoole\Tests\Fixtures\Symfony\TestBundle\DependencyInjection\CompilerPass\OverrideDoctrineCompilerPass;
use K911\Swoole\Tests\Fixtures\Symfony\TestBundle\TestBundle;
use PixelFederation\DoctrineResettableEmBundle\PixelFederationDoctrineResettableEmBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class TestAppKernel extends Kernel
{
    use MicroKernelTrait;
    use CoroutinesSupportingKernelTrait;

    private const CONFIG_EXTENSIONS = '.{php,xml,yaml,yml}';

    private ?string $overrideProdEnv;

    private ?TestCacheKernel $cacheKernel = null;

    private bool $coverageEnabled;

    private bool $profilerEnabled = false;

    public function __construct(string $environment, bool $debug, ?string $overrideProdEnv = null)
    {
        if ('_cov' === \mb_substr($environment, -4, 4)) {
            $environment = \mb_substr($environment, 0, -4);
            $this->coverageEnabled = true;
        } elseif ('cov' === $environment) {
            $this->coverageEnabled = true;
        } else {
            $this->coverageEnabled = false;
        }

        if ('profiler' === $environment) {
            $this->profilerEnabled = true;
        }

        if ('_http_cache' === \mb_substr($environment, -11, 11)) {
            $environment = \mb_substr($environment, 0, -11);
            $this->cacheKernel = new TestCacheKernel($this);
        }

        if (null !== $overrideProdEnv) {
            $overrideProdEnv = trim($overrideProdEnv);
        }

        $this->overrideProdEnv = $overrideProdEnv;

        parent::__construct($environment, $debug);
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheDir(): string
    {
        return $this->getVarDir().'/cache/'.$this->environment;
    }

    /**
     * {@inheritdoc}
     */
    public function getLogDir(): string
    {
        return $this->getVarDir().'/log';
    }

    /**
     * {@inheritdoc}
     */
    public function registerBundles(): \Generator
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new MonologBundle();
        yield new SwooleBundle();
        yield new TestBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new PixelFederationDoctrineResettableEmBundle();

        if ($this->coverageEnabled) {
            yield new CoverageBundle();
        }

        if ($this->profilerEnabled) {
            yield new WebProfilerBundle();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getProjectDir(): string
    {
        return __DIR__.'/app';
    }

    /**
     * {@inheritDoc}
     */
    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        // Use CacheKernel if available.
        if (null !== $this->cacheKernel) {
            // Prevent endless loop. Unset $this->cacheKernel, handle the request and then restore it.
            $cacheKernel = $this->cacheKernel;
            $this->cacheKernel = null;
            $response = $cacheKernel->handle($request, $type, $catch);
            $this->cacheKernel = $cacheKernel;

            return $response;
        }

        return parent::handle($request, $type, $catch);
    }

    /**
     * This should always return bool, but we need to coerce it depending on the Symfony version in use.
     */
    public function isDebug(): bool
    {
        return (bool) $this->debug;
    }

    protected function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new OverrideDoctrineCompilerPass());
    }

    /**
     * {@inheritdoc}
     *
     * @param RoutingConfigurator $routes
     *
     * @throws \Symfony\Component\Config\Exception\LoaderLoadException
     */
    protected function configureRoutes($routes): void
    {
        $routes->import($this->getProjectDir().'/routing.yml');

        $envRoutingFile = $this->getProjectDir().'/config/'.$this->environment.'/routing/routing.yaml';

        if (\file_exists($envRoutingFile)) {
            $routes->import($envRoutingFile);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     */
    protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader): void
    {
        $c->setParameter('bundle.root_dir', \dirname(__DIR__, 3));

        $confDir = $this->getProjectDir().'/config';

        $loader->load($confDir.'/*'.self::CONFIG_EXTENSIONS, 'glob');
        if (\is_dir($confDir.'/'.$this->environment)) {
            $loader->load($confDir.'/'.$this->environment.'/*'.self::CONFIG_EXTENSIONS, 'glob');
        }

        if ($this->coverageEnabled && 'cov' !== $this->environment) {
            $loader->load($confDir.'/cov/**/*'.self::CONFIG_EXTENSIONS, 'glob');
        }

        $this->loadOverrideForProdEnvironment($confDir, $loader);
    }

    private function getVarDir(): string
    {
        return $this->getProjectDir().'/var';
    }

    private function loadOverrideForProdEnvironment(string $confDir, LoaderInterface $loader): void
    {
        if ('prod' !== $this->environment) {
            return;
        }

        $envPackageConfigurationDir = sprintf('%s/%s', $confDir, $this->overrideProdEnv);

        if (!is_dir($envPackageConfigurationDir)) {
            return;
        }

        $loader->load($envPackageConfigurationDir.'/**/*'.self::CONFIG_EXTENSIONS, 'glob');
    }
}
