<?php

/*
 * This file is part of the symfony package.
 * (c) 2004-2006 Fabien Potencier <fabien.potencier@symfony-project.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Optimizes a project for better performance.
 *
 * @package    symfony
 * @subpackage task
 * @author     Fabien Potencier <fabien.potencier@symfony-project.com>
 * @version    SVN: $Id: sfProjectOptimizeTask.class.php 32707 2011-07-01 12:54:40Z fabien $
 */
class sfProjectOptimizeTask extends sfBaseTask
{
  /**
   * @see sfTask
   */
  protected function configure()
  {
    $this->addArguments(array(
      new sfCommandArgument('application', sfCommandArgument::REQUIRED, 'The application name'),
      new sfCommandArgument('env', sfCommandArgument::OPTIONAL, 'The environment name', 'prod'),
    ));

    $this->namespace = 'project';
    $this->name = 'optimize';
    $this->briefDescription = 'Optimizes a project for better performance';

    $this->detailedDescription = <<<EOF
The [project:optimize|INFO] optimizes a project for better performance:

  [./symfony project:optimize frontend prod|INFO]

This task should only be used on a production server. Don't forget to re-run
the task each time the project changes.
EOF;
  }

  /**
   * @see sfTask
   */
  protected function execute($arguments = array(), $options = array())
  {
    $data = array();
    $modules = $this->findModules();
    $target = sfConfig::get('sf_cache_dir').'/'.$arguments['application'].'/'.$arguments['env'].'/config/configuration.php';

    // remove existing optimization file
    if (file_exists($target))
    {
      $this->getFilesystem()->remove($target);
    }

    // recreate configuration without the cache
    $this->setConfiguration($this->createConfiguration($this->configuration->getApplication(), $this->configuration->getEnvironment()));

    // initialize the context
    sfContext::createInstance($this->configuration);

    // force cache generation for generated modules
    foreach ($modules as $module)
    {
      $this->configuration->getConfigCache()->import('modules/'.$module.'/config/generator.yml', false, true);
    }

    // force cache generation for existing modules
    $this->generateModulesCacheFiles($modules);

    $templates = $this->findTemplates($modules);

    $data['getTemplateDir'] = $this->optimizeGetTemplateDir($modules, $templates);
    $data['getControllerDirs'] = $this->optimizeGetControllerDirs($modules);
    $data['getPluginPaths'] = $this->configuration->getPluginPaths();
    $data['loadHelpers'] = $this->optimizeLoadHelpers($modules);

    if (!file_exists($directory = dirname($target)))
    {
      $this->getFilesystem()->mkdirs($directory);
    }

    $this->logSection('file+', $target);
    $this->saveCacheFile($target, $data);
  }

  protected function saveCacheFile($target, $data)
  {
    $saveData = var_export($data, true);

    $currentUmask = umask(0000);
    if (false !== file_put_contents($target, '<?php return '. $saveData . ';' . PHP_EOL))
    {
      chmod($target, 0666);
    }

    umask($currentUmask);
  }

  protected function optimizeGetControllerDirs($modules)
  {
    $data = array();
    foreach ($modules as $module)
    {
      $data[$module] = $this->configuration->getControllerDirs($module);
    }

    return $data;
  }

  protected function optimizeGetTemplateDir($modules, $templates)
  {
    $data = array();
    foreach ($modules as $module)
    {
      $data[$module] = array();
      foreach ($templates[$module] as $template)
      {
        if (null !== $dir = $this->configuration->getTemplateDir($module, $template))
        {
          $data[$module][$template] = $dir;
        }
      }
    }

    return $data;
  }

  protected function optimizeLoadHelpers($modules)
  {
    $data = array();

    $finder = sfFinder::type('file')->name('*Helper.php');

    // module helpers
    foreach ($modules as $module)
    {
      $helpers = array();

      $dirs = $this->configuration->getHelperDirs($module);
      foreach ($finder->in($dirs[0]) as $file)
      {
        $helpers[basename($file, 'Helper.php')] = $file;
      }

      if (count($helpers))
      {
        $data[$module] = $helpers;
      }
    }

    // all other helpers
    foreach ($this->configuration->getHelperDirs() as $dir)
    {
      foreach ($finder->in($dir) as $file)
      {
        $helper = basename($file, 'Helper.php');
        if (!isset($data[''][$helper]))
        {
          $data[''][$helper] = $file;
        }
      }
    }

    return $data;
  }

  protected function findTemplates($modules)
  {
    $files = array();

    foreach ($modules as $module)
    {
      $files[$module] = sfFinder::type('file')->follow_link()->relative()->in($this->configuration->getTemplateDirs($module));
    }

    return $files;
  }

  protected function generateModulesCacheFiles($modules)
  {
    static $configs = array('cache', 'filters', 'module', 'security', 'view');

    foreach ($modules as $module)
    {
      foreach ($configs as $config)
      {
        $configFName = 'modules/'.$module.'/config/' . $config . '.yml';
        $fName = $this->configuration->getConfigCache()->getCacheName($configFName);
        if (file_exists($fName))
        {
          unlink($fName);
        }

        $fName = $this->configuration->getConfigCache()->checkConfig('modules/'.$module.'/config/' . $config . '.yml', true);
        if (!$fName !== null)
        {
          $this->logSection('file+', $fName);
        }
      }
    }
  }

  protected function findModules()
  {
    // application
    $dirs = array(sfConfig::get('sf_app_module_dir'));

    // plugins
    $pluginSubPaths = $this->configuration->getPluginSubPaths(DIRECTORY_SEPARATOR.'modules');
    $modules = array();
    foreach (sfFinder::type('dir')->maxdepth(0)->follow_link()->relative()->in($pluginSubPaths) as $module)
    {
        if (in_array($module, sfConfig::get('sf_enabled_modules')))
        {
          $modules[] = $module;
        }
    }

    // core modules
    $dirs[] = sfConfig::get('sf_symfony_lib_dir').'/controller';

    return array_unique(array_merge(sfFinder::type('dir')->maxdepth(0)->follow_link()->relative()->in($dirs), $modules));
  }
}
