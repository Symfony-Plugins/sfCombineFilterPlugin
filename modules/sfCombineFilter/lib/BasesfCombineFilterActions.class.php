<?php

class BasesfCombineFilterActions extends sfActions
{
  private $data, $type, $size, $mimeType, $lastModified,$cacheFileName;

  public function executeDownload()
  {
    $type = $this->getRequestParameter('type');
    $this->cacheFileName = $this->getRequestParameter('cachefilename');
    $this->forward404Unless($this->cacheFileName);
    $this->type = ($type=='js')?'javascript':$type;

    $arr = explode('-',$this->cacheFileName);
    $this->lastModified = $arr[0];

    $this->serve();
    $this->setHttpHeaders();

    return $this->renderText($this->data);
  }

  protected function serve()
  {
    //get an instance of the file cache object. We grab the web root then get the name of the cache folder
    //we don't want to use sf_cache_dir because that is application and environment specific
    //we don't want to a path relative to sf_web_dir because the sf_root_dir can be changed, better to start from there
    $cache = new sfFileCache(sfConfig::get('sf_root_dir').DIRECTORY_SEPARATOR.sfConfig::get('sf_cache_dir_name'));

    //Next we see if we can pull the file from the cache.
    if($cache->has($this->cacheFileName, 'packed_files'))
    {
      //Ok! We have a cached copy of the file!
      $this->data = $cache->get($this->cacheFileName, 'packed_files');
      $this->mimeType = 'text/' . $this->type;
      $this->size = strlen($this->data);
    }
    else
    {
      //cached copy wasn't found, return 404
      $this->forward404('Cache file not found!!!');
    }
  }

  protected function setHttpHeaders() {
    $this->getResponse()->setHttpHeader('content-type', $this->mimeType, true);
    $this->getResponse()->setHttpHeader('content-length', $this->size, true);
    $this->getResponse()->setHttpHeader('Cache-Control', 'null', false);
    $this->getResponse()->setHttpHeader('Pragma', null, false);
    $this->getResponse()->setHttpHeader('Etag',$this->cacheFileName,false);
    $this->getResponse()->setHttpHeader('Last-Modified', gmdate('D, d M Y H:i:s',$this->lastModified).' GMT',false);
    $this->getResponse()->setHttpHeader('Expires', gmdate('D, d M Y H:i:s',strtotime('+1 month')).' GMT',false);
    $this->getResponse()->addCacheControlHttpHeader('max-age', 86400);

    if((isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) <= $this->lastModified)||
    (isset($_SERVER['HTTP_IF_NONE_MATCH']) && stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) == '"' . $this->cacheFileName . '"'))
    {
      $this->getResponse()->setStatusCode(304, 'Not Modified');
      return sfView::NONE;
    }
  }
}
