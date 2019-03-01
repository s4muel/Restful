<?php
namespace Drahak\Restful\DI;

use Nette;
use Drahak\Restful\Application\Routes\ResourceRoute;
use Drahak\Restful\IResource;
use Nette\Caching\Storages\FileStorage;
use Nette\DI\CompilerExtension;
use Nette\Configurator;
use Nette\DI\ContainerBuilder;
use Nette\DI\ServiceDefinition;
use Nette\DI\Statement;
use Tracy\Debugger;
use Nette\Loaders\RobotLoader;
use Nette\Utils\Validators;

if (!class_exists('Nette\DI\CompilerExtension')) {
	class_alias('Nette\Config\CompilerExtension', 'Nette\DI\CompilerExtension');
	class_alias('Nette\Config\Compiler', 'Nette\DI\Compiler');
	class_alias('Nette\Config\Helpers', 'Nette\DI\Config\Helpers');
}

if (class_exists('Nette\Loaders\NetteLoader') && (isset(Nette\Loaders\NetteLoader::getInstance()->renamed['Nette\Configurator']) || !class_exists('Nette\Configurator'))) {
	unset(Nette\Loaders\NetteLoader::getInstance()->renamed['Nette\Configurator']);
	class_alias('Nette\Config\Configurator', 'Nette\Configurator');
}

/**
 * Drahak RestfulExtension
 * @package Drahak\Restful\DI
 * @author Drahomír Hanák
 */
class RestfulExtension extends CompilerExtension
{

	/** Converter tag name */
	const CONVERTER_TAG = 'restful.converter';

	/** Snake case convention config name */
	const CONVENTION_SNAKE_CASE = 'snake_case';

	/** Camel case convention config name */
	const CONVENTION_CAMEL_CASE = 'camelCase';

	/** Pascal case convention config name */
	const CONVENTION_PASCAL_CASE = 'PascalCase';

	/**
	 * Default DI settings
	 * @var array
	 */
	protected $defaults = array(
		'convention' => NULL,
		'timeFormat' => 'c',
		'cacheDir' => '%tempDir%/cache',
		'jsonpKey' => 'jsonp',
                'prettyPrint' => TRUE,
		'prettyPrintKey' => 'pretty',
		'routes' => array(
			'generateAtStart' => FALSE,
			'presentersRoot' => '%appDir%',
			'autoGenerated' => TRUE,
			'autoRebuild' => TRUE,
			'module' => '',
			'prefix' => '',
			'panel' => TRUE
		),
		'security' => array(
			'privateKey' => NULL,
			'requestTimeKey' => 'timestamp',
			'requestTimeout' => 300,
		)
	);

	/**
	 * Load DI configuration
	 */
	public function loadConfiguration()
	{
		$container = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		// Additional module
		$this->loadRestful($container, $config);
		$this->loadValidation($container, $config);
		$this->loadResourceConverters($container, $config);
		$this->loadSecuritySection($container, $config);
		if ($config['routes']['autoGenerated']) $this->loadAutoGeneratedRoutes($container, $config);
		if ($config['routes']['panel']) $this->loadResourceRoutePanel($container, $config);
	}

	/**
	 * Before compile
	 */
	public function beforeCompile()
	{
		$container = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		$resourceConverter = $container->getDefinition($this->prefix('resourceConverter'));
		$services = $container->findByTag(self::CONVERTER_TAG);

		foreach ($services as $service => $args) {
			$resourceConverter->addSetup('$service->addConverter(?)', array('@' . $service));
		}
	}

	/**
	 * @param ContainerBuilder $container
	 * @param $config
	 */
	private function loadRestful(ContainerBuilder $container, $config)
	{
		Validators::assert($config['prettyPrintKey'], 'string');

		$container->addDefinition($this->prefix('responseFactory'))
			->setClass('Drahak\Restful\Application\ResponseFactory')
			->addSetup('$service->setJsonp(?)', array($config['jsonpKey']))
			->addSetup('$service->setPrettyPrintKey(?)', array($config['prettyPrintKey']))
			->addSetup('$service->setPrettyPrint(?)', array($config['prettyPrint']));

		$container->addDefinition($this->prefix('resourceFactory'))
			->setClass('Drahak\Restful\ResourceFactory');
		$container->addDefinition($this->prefix('resource'))
			->setFactory($this->prefix('@resourceFactory') . '::create');

		$container->addDefinition($this->prefix('methodOptions'))
			->setClass('Drahak\Restful\Application\MethodOptions');

		// Mappers
		$container->addDefinition($this->prefix('xmlMapper'))
			->setClass('Drahak\Restful\Mapping\XmlMapper');
		$container->addDefinition($this->prefix('jsonMapper'))
			->setClass('Drahak\Restful\Mapping\JsonMapper');
		$container->addDefinition($this->prefix('queryMapper'))
			->setClass('Drahak\Restful\Mapping\QueryMapper');
		$container->addDefinition($this->prefix('dataUrlMapper'))
			->setClass('Drahak\Restful\Mapping\DataUrlMapper');
		$container->addDefinition($this->prefix('nullMapper'))
			->setClass('Drahak\Restful\Mapping\NullMapper');

		$container->addDefinition($this->prefix('mapperContext'))
			->setClass('Drahak\Restful\Mapping\MapperContext')
			->addSetup('$service->addMapper(?, ?)', array(IResource::XML, $this->prefix('@xmlMapper')))
			->addSetup('$service->addMapper(?, ?)', array(IResource::JSON, $this->prefix('@jsonMapper')))
			->addSetup('$service->addMapper(?, ?)', array(IResource::JSONP, $this->prefix('@jsonMapper')))
			->addSetup('$service->addMapper(?, ?)', array(IResource::QUERY, $this->prefix('@queryMapper')))
			->addSetup('$service->addMapper(?, ?)', array(IResource::DATA_URL, $this->prefix('@dataUrlMapper')))
			->addSetup('$service->addMapper(?, ?)', array(IResource::FILE, $this->prefix('@nullMapper')))
			->addSetup('$service->addMapper(?, ?)', array(IResource::NULL, $this->prefix('@nullMapper')));

		if (isset($config['mappers'])) {
			foreach ($config['mappers'] as $mapperName => $mapper) {
				$container->addDefinition($this->prefix($mapperName))
					->setClass($mapper['class']);

				$container->getDefinition($this->prefix('mapperContext'))
					->addSetup('$service->addMapper(?, ?)', array($mapper['contentType'], $this->prefix('@' . $mapperName)));
			}
		}

		// Input & validation
		$container->addDefinition($this->prefix('inputFactory'))
			->setClass('Drahak\Restful\Http\InputFactory');

		// Http
		$container->addDefinition($this->prefix('httpResponseFactory'))
			->setClass('Drahak\Restful\Http\ResponseFactory');

		$container->addDefinition($this->prefix('httpRequestFactory'))
			->setClass('Drahak\Restful\Http\ApiRequestFactory');

		$container->getDefinition('httpRequest')
			->setFactory($this->prefix('@httpRequestFactory') . '::createHttpRequest');

		$container->getDefinition('httpResponse')
			->setFactory($this->prefix('@httpResponseFactory') . '::createHttpResponse');

		$container->addDefinition($this->prefix('requestFilter'))
			->setClass('Drahak\Restful\Utils\RequestFilter')
			->setArguments(array('@httpRequest', array($config['jsonpKey'], $config['prettyPrintKey'])));

		$container->addDefinition($this->prefix('methodHandler'))
			->setClass('Drahak\Restful\Application\Events\MethodHandler');

		$container->getDefinition('application')
			->addSetup('$service->onStartup[] = ?', array(array($this->prefix('@methodHandler'), 'run')))
			->addSetup('$service->onError[] = ?', array(array($this->prefix('@methodHandler'), 'error')));
	}

	/**
	 * @param ContainerBuilder $container
	 * @param $config
	 */
	private function loadValidation(ContainerBuilder $container, $config)
	{
		$container->addDefinition($this->prefix('validator'))
			->setClass('Drahak\Restful\Validation\Validator');

		$container->addDefinition($this->prefix('validationScopeFactory'))
			->setClass('Drahak\Restful\Validation\ValidationScopeFactory');

		$container->addDefinition($this->prefix('validationScope'))
			->setClass('Drahak\Restful\Validation\ValidationScope')
			->setFactory($this->prefix('@validationScopeFactory') . '::create');

	}

	/**
	 * @param ContainerBuilder $container
	 * @param $config
	 */
	private function loadResourceConverters(ContainerBuilder $container, $config)
	{
		Validators::assert($config['timeFormat'], 'string');

		// Default used converters
		$container->addDefinition($this->prefix('objectConverter'))
			->setClass('Drahak\Restful\Converters\ObjectConverter')
			->addTag(self::CONVERTER_TAG);
		$container->addDefinition($this->prefix('dateTimeConverter'))
			->setClass('Drahak\Restful\Converters\DateTimeConverter')
			->setArguments(array($config['timeFormat']))
			->addTag(self::CONVERTER_TAG);

		// Other available converters
		$container->addDefinition($this->prefix('camelCaseConverter'))
			->setClass('Drahak\Restful\Converters\CamelCaseConverter');
		$container->addDefinition($this->prefix('pascalCaseConverter'))
			->setClass('Drahak\Restful\Converters\PascalCaseConverter');
		$container->addDefinition($this->prefix('snakeCaseConverter'))
			->setClass('Drahak\Restful\Converters\SnakeCaseConverter');

		// Determine which converter to use if any
		if ($config['convention'] === self::CONVENTION_SNAKE_CASE) {
			$container->getDefinition($this->prefix('snakeCaseConverter'))
				->addTag(self::CONVERTER_TAG);
		} else if ($config['convention'] === self::CONVENTION_CAMEL_CASE) {
			$container->getDefinition($this->prefix('camelCaseConverter'))
				->addTag(self::CONVERTER_TAG);
		} else if ($config['convention'] === self::CONVENTION_PASCAL_CASE) {
			$container->getDefinition($this->prefix('pascalCaseConverter'))
				->addTag(self::CONVERTER_TAG);
		}

		// Load converters by tag
		$container->addDefinition($this->prefix('resourceConverter'))
			->setClass('Drahak\Restful\Converters\ResourceConverter');
	}

	/**
	 * @param ContainerBuilder $container
	 * @param array $config
	 */
	private function loadAutoGeneratedRoutes(ContainerBuilder $container, $config)
	{
		$container->addDefinition($this->prefix('routeAnnotation'))
			->setClass('Drahak\Restful\Application\RouteAnnotation');

		$container->addDefinition($this->prefix('routeListFactory'))
			->setClass('Drahak\Restful\Application\RouteListFactory')
			->setArguments(array($config['routes']['presentersRoot'], $config['routes']['autoRebuild']))
			->addSetup('$service->setModule(?)', array($config['routes']['module']))
			->addSetup('$service->setPrefix(?)', array($config['routes']['prefix']));

		$container->addDefinition($this->prefix('cachedRouteListFactory'))
			->setClass('Drahak\Restful\Application\CachedRouteListFactory')
			->setArguments(array($config['routes']['presentersRoot'], $this->prefix('@routeListFactory')));

		$statement = new Statement('offsetSet', array(NULL, new Statement($this->prefix('@cachedRouteListFactory') . '::create')));
		if ($config['routes']['generateAtStart']) {
			$setup = $container->getDefinition('router')
				->getSetup();
			array_unshift($setup, $statement);
			$container->getDefinition('router')
				->setSetup($setup);
		} else {
			$container->getDefinition('router')
				->addSetup($statement);
		}
	}

	/**
	 * @param ContainerBuilder $container
	 * @param array $config
	 */
	private function loadResourceRoutePanel(ContainerBuilder $container, $config)
	{
        $routerPanel = $container->addDefinition($this->prefix('panel'))
            ->setClass('Drahak\Restful\Diagnostics\ResourceRouterPanel')
            ->setArguments(array(
                $config['security']['privateKey'],
                isset($config['security']['requestTimeKey']) ? $config['security']['requestTimeKey'] : 'timestamp'
            ));
        if (class_exists('Tracy\Debugger')) {
            $routerPanel->addSetup('Tracy\Debugger::getBar()->addPanel(?)', array('@self'));
        } else {
            $routerPanel->addSetup('Nette\Diagnostics\Debugger::getBar()->addPanel(?)', array('@self'));
        }

		$container->getDefinition('application')
			->addSetup('$service->onStartup[] = ?', array(array($this->prefix('@panel'), 'getTab')));
	}

	/**
	 * @param ContainerBuilder $container
	 * @param array $config
	 */
	private function loadSecuritySection(ContainerBuilder $container, $config)
	{
		$container->addDefinition($this->prefix('security.hashCalculator'))
			->setClass('Drahak\Restful\Security\HashCalculator')
			->addSetup('$service->setPrivateKey(?)', array($config['security']['privateKey']));

		$container->addDefinition($this->prefix('security.hashAuthenticator'))
			->setClass('Drahak\Restful\Security\Authentication\HashAuthenticator')
			->setArguments(array($config['security']['privateKey']));
		$container->addDefinition($this->prefix('security.timeoutAuthenticator'))
			->setClass('Drahak\Restful\Security\Authentication\TimeoutAuthenticator')
			->setArguments(array($config['security']['requestTimeKey'], $config['security']['requestTimeout']));

		$container->addDefinition($this->prefix('security.nullAuthentication'))
			->setClass('Drahak\Restful\Security\Process\NullAuthentication');
		$container->addDefinition($this->prefix('security.securedAuthentication'))
			->setClass('Drahak\Restful\Security\Process\SecuredAuthentication');
		$container->addDefinition($this->prefix('security.basicAuthentication'))
			->setClass('Drahak\Restful\Security\Process\BasicAuthentication');

		$container->addDefinition($this->prefix('security.authentication'))
			->setClass('Drahak\Restful\Security\AuthenticationContext')
			->addSetup('$service->setAuthProcess(?)', array($this->prefix('@security.nullAuthentication')));

		// enable OAuth2 in Restful
		if ($this->getByType($container, 'Drahak\OAuth2\KeyGenerator')) {
			$container->addDefinition($this->prefix('security.oauth2Authentication'))
				->setClass('Drahak\Restful\Security\Process\OAuth2Authentication');
		}
	}

	/**
	 * @param ContainerBuilder $container
	 * @param string $type
	 * @return ServiceDefinition|null
	 */
	private function getByType(ContainerBuilder $container, $type)
	{
		$definitionas = $container->getDefinitions();
		foreach ($definitionas as $definition) {
			if ($definition->class === $type) {
				return $definition;
			}
		}
		return NULL;
	}

	/**
	 * Register REST API extension
	 * @param Configurator $configurator
	 */
	public static function install(Configurator $configurator)
	{
		$configurator->onCompile[] = function($configurator, $compiler) {
			$compiler->addExtension('restful', new RestfulExtension);
		};
	}

}
