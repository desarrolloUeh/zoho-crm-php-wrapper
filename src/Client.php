<?php

namespace Zoho\CRM;

use Zoho\CRM\Core\ApiRequestPaginator;
use Zoho\CRM\Core\ClientResponseMode;
use Doctrine\Common\Inflector\Inflector;

class Client
{
    private $auth_token;

    private $preferences;

    private $supported_modules = [
        'Info',
        'Leads',
        'Users'
    ];

    private $default_parameters = [
        'scope' => 'crmapi',
        'newFormat' => 1,
        'version' => 2,
        'fromIndex' => Core\ApiRequestPaginator::MIN_INDEX,
        'toIndex' => Core\ApiRequestPaginator::PAGE_MAX_SIZE
    ];

    public function __construct($auth_token)
    {
        $this->setAuthToken($auth_token);

        $this->preferences = new Core\ClientPreferences();

        $this->registerModules();
    }

    public function getSupportedModules()
    {
        return $this->supported_modules;
    }

    public function supports($module)
    {
        return in_array($module, $this->supported_modules);
    }

    public function preferences()
    {
        return $this->preferences;
    }

    public function getAuthToken()
    {
        return $this->auth_token;
    }

    public function setAuthToken($auth_token)
    {
        if ($auth_token === null || $auth_token === '')
            throw new Exception\NullAuthTokenException();
        else
            $this->auth_token = $auth_token;
    }

    public function getDefaultParameters()
    {
        return $this->default_parameters;
    }

    public function setDefaultParameters(array $params)
    {
        $this->default_parameters = $params;
    }

    public function setDefaultParameter($key, $value)
    {
        $this->default_parameters[$key] = $value;
    }

    public function unsetDefaultParameter($key)
    {
        unset($this->default_parameters[$key]);
    }

    private function registerModules()
    {
        foreach ($this->supported_modules as $module) {
            $parameterized_module = Inflector::tableize($module);
            $class_name = getModuleClassName($module);
            if (class_exists($class_name)) {
                $this->{$parameterized_module} = new $class_name($this);
            } else {
                throw new Exception\ModuleNotFoundException("Module $class_name not found.");
            }
        }
    }

    private function getModule($module)
    {
        return $this->{Inflector::tableize($module)};
    }

    public function request($module, $method, array $params = [], $pagination = false, $format = Core\ResponseFormat::JSON)
    {
        // Check if the requested module and method are both supported
        if (!$this->supports($module)) {
            throw new Exception\UnsupportedModuleException($module);
        } elseif (!$this->getModule($module)->supports($method)) {
            throw new Exception\UnsupportedMethodException($module, $method);
        }

        // Extend default parameters with the current auth token, and the user-defined parameters
        $url_parameters = (new Core\UrlParameters($this->default_parameters))
                              ->extend(['authtoken' => $this->auth_token])
                              ->extend($params);

        // Build a request object which encapsulates everything
        $request = new Core\Request($format, $module, $method, $url_parameters);

        $response = null;

        if ($pagination) {
            // If pagination is requested or required, let a paginator handle the request
            $paginator = new Core\ApiRequestPaginator($request);
            if ($this->preferences->getAutoFetchPaginatedRequests()) {
                $paginator->fetchAll();
                $response = $paginator->getAggregatedResponse();
            } else {
                return $paginator;
            }
        } else {
            // Send the request to the Zoho API, parse, then finally clean its response
            $raw_data = Core\ApiRequestLauncher::fire($request);
            $clean_data = Core\ApiResponseParser::clean($request, $raw_data);
            $response = new Core\Response($request, $raw_data, $clean_data);
        }

        if ($this->preferences->getResponseMode() === ClientResponseMode::RECORDS_ARRAY) {
            // Unwrap the response content
            $response = $response->getContent();
        } elseif ($this->preferences->getResponseMode() === ClientResponseMode::ENTITY) {
            // Convert response data to an entity object
            $response = $response->toEntity();
        }

        return $response;
    }
}
