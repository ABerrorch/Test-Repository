<?php
namespace app\common\controller;
use think\Controller;
/**
 * Base基类控制器
 */
class Base extends Controller{
    /**
     * 初始化方法
     */
    public function _initialize(){
        trace("[URL] " . "[" . date("Y-m-d H:i:s") . "] " . $this->request->url());
    }

}
