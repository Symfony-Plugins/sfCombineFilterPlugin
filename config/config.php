<?php

sfRouting::getInstance()->prependRoute('download_packed_files',
'/packed/:type/:cachefilename/packed.*',
array(
  'module' => 'sfCombineFilter',
  'action' => 'download',
  'target_action' => 'index'
));
