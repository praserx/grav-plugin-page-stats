<?php
namespace Grav\Plugin;

use Grav\Common\Filesystem\Folder;
use Grav\Common\GPM\GPM;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Common\Filesystem\RecursiveFolderFilterIterator;
use Grav\Common\User\User;
use Grav\Common\Utils;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;

class StatisticsPlugin extends Plugin
{
    protected $route = 'stats';
    protected $enable = false;
    protected $page_stats_cache_id;

    protected $data_path;
    protected $totals_file;
    protected $votes_file;
    protected $totals_data;
    protected $votes_data;

    const TOTALS_FILE = 'totals.json';
    const VOTES_FILE = 'votes.json';
    const UP = true;
    const DOWN = false;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    public function onPluginsInitialized()
    {
        $this->data_path = Grav::instance()['locator']->findResource('log://popularity', true, true);
        $this->votes_file = $this->data_path . '/' . self::VOTES_FILE;
        $this->totals_file   = $this->data_path . '/' . self::TOTALS_FILE;
        
        if ($this->isAdmin()) {
            $this->initializeAdmin();
        } else {
            $this->initializeFrontend();
        }
    }

    public function initializeAdmin()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
            'onAdminMenu' => ['onAdminMenu', 0],
            'onDataTypeExcludeFromDataManagerPluginHook' => ['onDataTypeExcludeFromDataManagerPluginHook', 0],
        ]);

        if (strpos($uri->path(), $this->config->get('plugins.admin.route') . '/' . $this->route) === false) {
            return;
        }

        $this->grav['twig']->votes = $this->getVotesAll();
        $this->grav['twig']->views = $this->getTotalsAll();
    }

    public function initializeFrontend()
    {
        $this->calculateEnable();
        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
        ]);

        if ($this->enable) {
            $this->enable([
                'onFormProcessed' => ['onFormProcessed', 0],
                'onFormPageHeaderProcessed' => ['onFormPageHeaderProcessed', 0],
                'onPageInitialized' => ['onPageInitialized', 10],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
            ]);
        }
    }

    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigAdminTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';
    }

    public function onAdminMenu()
    {
        $this->grav['twig']->plugins_hooked_nav['PLUGIN_STATISTICS.STATISTICS'] = ['route' => $this->route, 'icon' => 'fa-bar-chart'];
    }

    public function onDataTypeExcludeFromDataManagerPluginHook()
    {
        $this->grav['admin']->dataTypesExcludedFromDataManagerPlugin[] = 'statistics';
    }

    public function onFormPageHeaderProcessed(Event $event)
    {
        $header = $event['header'];

        if ($this->enable) {
            if (!isset($header->form)) {
                $header->form = $this->grav['config']->get('plugins.statistics.form');
            }
        }

        $event->header = $header;
    }

    public function onTwigSiteVariables() {
        $path = $this->grav['uri']->path();
        $enabled = $this->enable;
        $votes = $this->getVotes($path);
        
        $this->grav['twig']->twig_vars['enable_statistics_plugin'] = $enabled;
        $this->grav['twig']->twig_vars['statistics'] = $votes; 
    }

    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        if (!$this->active) {
            return;
        }

        $path = $this->grav['uri']->path();

        switch ($action) {
            case 'upvote':
                $this->updateVotes($path, UP);
                break;
            case 'downvote':
                $this->updateVotes($path, DOWN);
                break;
        }
    }

    private function calculateEnable() {
        $uri = $this->grav['uri'];

        $disable_on_routes = (array) $this->config->get('plugins.statistics.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.statistics.enable_on_routes');

        $path = $uri->path();

        if (!in_array($path, $disable_on_routes)) {
            if (in_array($path, $enable_on_routes)) {
                $this->enable = true;
            } else {
                foreach($enable_on_routes as $route) {
                    if (Utils::startsWith($path, $route)) {
                        $this->enable = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * @param string $url
     * 
     */
    protected function updateVotes(string $url, boolean $increase)
    {
        if (!$this->votes_data) {
            $this->votes_data = $this->getData($this->votes_file);
        }

        if (array_key_exists($url, $this->votes_data) && $increase) {
            $this->votes_data[$url] = (int)$this->votes_data[$url] + 1;
        } else if (!array_key_exists($url, $this->votes_data) && $increase) {
            $this->votes_data[$url] = 1;
        } else if (array_key_exists($url, $this->votes_data) && !$increase) {
            $this->votes_data[$url] = (int)$this->votes_data[$url] - 1;
        } else if (!array_key_exists($url, $this->votes_data) && !$increase) {
            $this->votes_data[$url] = -1;
        }

        file_put_contents($this->votes_file, json_encode($this->votes_data));
    }

    /**
     * @return int
     */
    protected function getVotes($url)
    {
        if (!$this->votes_data) {
            $this->votes_data = $this->getData($this->votes_file);
        }

        if (array_key_exists($url, $this->votes_data)) {
            return $this->votes_data[$url];
        }

        return 0;
    }

    /**
     * @return array
     */
    protected function getVotesAll()
    {
        if (!$this->votes_data) {
            $this->votes_data = $this->getData($this->votes_file);
        }

        if (isset($this->votes_data)) {
            return $this->votes_data;
        }

        return [];
    }

    /**
     * @return array
     */
    protected function getTotalsAll()
    {
        if (!$this->totals_data) {
            $this->totals_data = $this->getData($this->totals_file);
        }

        if (isset($this->totals_data)) {
            return $this->totals_data;
        }

        return [];
    }

    /**
     * @param string $path
     * @return array
     */
    protected function getData(string $path)
    {
        if (file_exists($path)) {
            return (array)json_decode(file_get_contents($path), true);
        }

        return [];
    }
}