<?php
/*
 * This file is part of the sfCombineFilter package.
 *
 * sfCombineFilter.class.php (c) 2007 Scott Meves.
 * sfCombineFilter.class.php modifications (c) 2008 by Benjamin Runnels *
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * This filter combines requested js and css files into a single request each.
 *
 * @package      sfCombineFilter
 * @subpackage   filter
 * @author       Scott Meves <scott@stereointeractive.com>
 *
 */
class sfCombineFilter extends sfFilter
{
  private $response,$sf_relative_url_root,$type,$files,$lastmodified;

  public function execute ($filterChain)
  {
    $filterChain->execute();

    sfLoader::loadHelpers('Asset');
    $this->response = $this->getContext()->getResponse();
    $this->sf_relative_url_root = $this->getContext()->getRequest()->getRelativeUrlRoot(). $this->getContext()->getRequest()->getScriptName();

    if($this->getContext()->getRequest()->isXmlHttpRequest() != true && strpos($this->getContext()->getResponse()->getContentType(),'text/html') === 0)
    {
      if (sfConfig::get('app_sf_combine_filter_plugin_javascripts',true))
      {
        $this->type = 'javascript';
        $this->files = array();
        $this->lastmodified = 0;
        $this->getCombinedJavascripts();
      }

      if (sfConfig::get('app_sf_combine_filter_plugin_stylesheets',true))
      {
        $this->type = 'css';
        $this->files = array();
        $this->lastmodified = 0;
        $this->getCombinedStylesheets();
      }
    }
  }

  protected function getCombinedJavascripts()
  {
    $root_js_only = sfConfig::get('app_sf_combine_filter_plugin_root_js_only', false);

    $already_seen = array();
    $combined_sources = array();

    foreach (array('first', '', 'last') as $position)
    {
      foreach ($this->response->getJavascripts($position) as $files => $options)
      {
        if (!is_array($files))
        {
          $files = array($files);
        }

        foreach ($files as $file)
        {
          if (isset($already_seen[$file])) continue;

          $already_seen[$file] = 1;

          if (is_array($options) && $this->isAbsolutePath($options))
          {
            continue;
          }

          $path = _compute_public_path($file, 'js', 'js');

          // do not include, when in exclude array
          foreach (sfConfig::get('app_sf_combine_filter_plugin_js_exclude_files', array()) as $exclude)
          {
            if (strpos($path, $exclude)!==false) continue 2;
          }

          if ((!$root_js_only && !strpos($path, '://')&& strpos($path, '.js')) || ($root_js_only && strpos($path, $this->sf_relative_url_root.'/js/') === 0)) {
            $element = ($root_js_only ? preg_replace("/^".str_replace('/', '\/', $this->sf_relative_url_root.'/js/')."/i", '', $path) : $path);
            if($this->checkFile($element))
            {
              $combined_sources[] = $element;
              $this->response->getParameterHolder()->remove($file, 'helper/asset/auto/javascript'.($position ? '/'.$position : ''));
            }

          }
        }
      }
    }

    if (count($combined_sources)) {
      $cacheFileName = $this->lastmodified . '-' . md5(implode(',', $combined_sources));
      if($this->cacheFile($cacheFileName))
      {
        $combined_sources_str = $this->sf_relative_url_root ."/packed/js/$cacheFileName/packed.js";
        //TODO: keep track if there is a dynamic file in the middle of static files and create multiple packed files
        // if required to keep the proper order
        $this->response->addJavascript($combined_sources_str); //, 'first'
      }
    }
  }

  protected function cacheFile($cacheFileName)
  {
    //get an instance of the file cache object. We grab the web root then get the name of the cache folder
    //we don't want to use sf_cache_dir because that is application and environment specific
    //we don't want to a path relative to sf_web_dir because the sf_root_dir can be changed, better to start from there
    $cache = new sfFileCache(sfConfig::get('sf_root_dir').DIRECTORY_SEPARATOR.sfConfig::get('sf_cache_dir_name'));

    //cached files are in the 'packed_files' name space

    //Next we see if we can pull the file from the cache.
    if($cache->has($cacheFileName, 'packed_files'))
    {
      //Ok! We have a cached copy of the file!
      return true;
    }
    else
    {
      // Get contents of the files
      $contents = '';
      foreach ($this->files as $path)
      {
        if($this->type=='css')
        {
          $cssPath = str_replace(sfConfig::get('app_sf_combine_filter_plugin_css_filtered_paths', array()),'',$path);
          $con = $this->fixCssPaths(file_get_contents($path),$cssPath);
        }
        else
        {
          $con = file_get_contents($path);
        }

        if ($this->type=='javascript'&& sfConfig::get('app_sf_combine_filter_plugin_compress_js', false))
        {
          $jsMin = new JsMinEnh($con);
          $con = $jsMin->minify();
        }
        elseif($this->type=='css'&& sfConfig::get('app_sf_combine_filter_plugin_compress_css', true))
        {
          $con = $this->compressCss($con);
        }

        $contents .= "\n" . $con;
      }

      //Write the file data to the cache
      $cache->set($cacheFileName, 'packed_files', $contents);
      return true;
    }

    return false;
  }

  private function compressCss($content)
  {
    // remove comments
    // TODO:  check and see if this breaks hacks.  Turning it off for now
    //$content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
    // remove tabs, spaces, newlines, etc.
    $content = str_replace(array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), '', $content);
    return $content;
  }

  private function fixCssPaths($content,$path)
  {
    if (preg_match_all("/url\(\s?[\'|\"]?(.+)[\'|\"]?\s?\)/ix", $content, $urlMatches) )
    {
      $urlMatches = array_unique( $urlMatches[1] );
      $cssPathArray = explode(DIRECTORY_SEPARATOR, $path);

      // pop the css file name
      array_pop( $cssPathArray );
      $cssPathCount   = count( $cssPathArray );
      foreach( $urlMatches as $match )
      {
        $match = str_replace( array('"', "'"), '', $match );
        $relativeCount = substr_count( $match, '../' );
        // replace path if it is realtive
        if ( $match[0] !== '/' and strpos( $match, 'http:' ) === false )
        {
          $cssPathSlice = $relativeCount === 0 ? $cssPathArray : array_slice( $cssPathArray  , 0, $cssPathCount - $relativeCount  );
          $newMatchPath = implode('/', $cssPathSlice) . '/' . str_replace('../', '', $match);
          $content = str_replace( $match, $newMatchPath, $content );
        }
      }
    }
    return $content;
  }

  private function checkFile($element)
  {
    $sf_symfony_data_dir = sfConfig::get('sf_symfony_data_dir');

    $cachedir  = sfConfig::get('sf_cache_dir');
    $webdir    = sfConfig::get('sf_web_dir');
    $cssdir    = $webdir.DIRECTORY_SEPARATOR.'css';
    $jsdir     = $webdir.DIRECTORY_SEPARATOR.'js';

    // Determine the directory and type we should use
    switch ($this->type)
    {
      case 'css':
        $dir = $cssdir;
        break;
      case 'javascript':
        $dir = $jsdir;
        break;
    }

    $path = null;
    if (substr($element, 0, 4) == '/sf/')
    {
      $path = $sf_symfony_data_dir.DIRECTORY_SEPARATOR.'web'.$element;
    }
    else if (substr($element, 0, 3) == 'sf/')
    {
      $path = $sf_symfony_data_dir.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.$element;
    }
    else if (0 === strpos($element, '/'))
    {
      $path = realpath($webdir.$element);
    }
    else
    {
      $path = realpath($dir.DIRECTORY_SEPARATOR.$element);
    }

    if (!file_exists($path)) return false;

    $this->files[] = $path;
    $this->lastmodified = max($this->lastmodified, filemtime($path));
    return true;
  }

  protected function getCombinedStylesheets()
  {
    $root_css_only = sfConfig::get('app_sf_combine_filter_plugin_root_css_only', false);
    $already_seen = array();
    $combined_sources = array();

    foreach (array('first', '', 'last') as $position)
    {
      foreach ($this->response->getStylesheets($position) as $files => $options)
      {
        if (!is_array($files))
        {
          $files = array($files);
        }

        foreach ($files as $file)
        {
          if (isset($already_seen[$file])) continue;

          $already_seen[$file] = 1;

          if (is_array($options) && ($this->isInvalidMediaType($options) || $this->isAbsolutePath($options)))
          {
            continue;
          }

          $path = _compute_public_path($file, 'css', 'css');

          if ((!$root_css_only && !strpos($path, '://')&& strpos($path, '.css')) || ($root_css_only && strpos($path, $this->sf_relative_url_root.'/css/') === 0)) {
            $element = (!$root_css_only ? preg_replace("/^".str_replace('/', '\/', $this->sf_relative_url_root.'/css/')."/i", '', $path) : $path);
            if($this->checkFile($element))
            {
              $combined_sources[] = $element;
              $this->response->getParameterHolder()->remove($file, 'helper/asset/auto/stylesheet'.($position ? '/'.$position : ''));
            }
          }
        }
      }
    }

    if (count($combined_sources))
    {
      $cacheFileName = $this->lastmodified . '-' . md5(implode(',', $combined_sources));
      if($this->cacheFile($cacheFileName))
      {
        $combined_sources_str = $this->sf_relative_url_root ."/packed/css/$cacheFileName/packed.css";
        //TODO: keep track if there is a dynamic file in the middle of static files and create multiple packed files
        // if required to keep the proper order
        $this->response->addStylesheet($combined_sources_str, 'first');
      }
    }
  }

  protected function isInvalidMediaType($options)
  {
    return isset($options['media']) && !in_array($options['media'], array('', 'all', 'screen'));
  }

  protected function isAbsolutePath($options)
  {
    return isset($options['absolute']) && $options['absolute'] == true;
  }

}
