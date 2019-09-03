<?php
/**
 * Created by PhpStorm.
 * User: Huan
 * Date: 2019/3/16
 * Time: 14:37
 * 以表显示的资料集合
 * 资料管理
 * 任务管理
 * 加入管理(伪)
 * 资产登记
 */
namespace app\admin\controller;
use app\admin\model\Admin;
use app\common\controller\Filesbase;
use think\Db;
use think\Request;
use think\Session;
use ZipArchive;

/**
 * Class Listfile
 * @package app\admin\controller
 * Listfile控制器
 */
class Listfile extends Filesbase {
    public $cols;
    public function _initialize() {
        parent::_initialize(); // TODO: Change the autogenerated stub
        $this->cols = [[
            ['type'=>'checkbox'],
            [
                'field'=>'id',
                'title'=>'ID',
                'sort'=>true,
//                'minwidth'=>150,
//                'width'=>200,
            ],[
                'field'=>'filename',
                'title'=>'文件名',
                'sort'=>true,
//                'minwidth'=>150,
//                'width'=>200,
            ],[
                'field'=>'type',
                'title'=>'类别',
                'sort'=>true,
//                'minwidth'=>150,
//                'width'=>200,
            ],[
                'field'=>'abstract',
                'title'=>'说明',
                'sort'=>true,
//                'minwidth'=>150,
//                'width'=>200,
            ],[
                'field'=>'datetime',
                'title'=>'日期',
                'sort'=>true,
//                'minwidth'=>150,
//                'width'=>200,
            ],[
                'field'=>'author',
                'title'=>'上传者',
                'sort'=>true,
//                'minwidth'=>150,
//                'width'=>200,
            ],
        ]];
    }
    public function index() {

        //我很想知道下面这个paginate是什么原理
        //但是暂时搞不清楚,反正总之就是用来干处理分页输出的..
        //心态崩了
        $data = Db::name('files')->order('id desc')->paginate(10);
        return view('index',[
            'title'=>'高级权限',
            'subtitle'=>'资料列表',
            'data'=>$data,
        ]);
    }
    public function public_index() {
        //我很想知道下面这个paginate是什么原理
        //但是暂时搞不清楚,反正总之就是用来干处理分页输出的..
        //心态崩了
        $data = Db::name('files')
            ->where('type','public')
            ->order('datetime desc')
            ->paginate(10);
        return view('index',[
            'title'=>'资料管理',
            'subtitle'=>'公开文件',
            'data'=>$data,
            'filetype'=>'public',
        ]);
    }
    public function add_file($label='') {
        $result = $this->file_add($label);
        if(empty($result)){
            $this->redirect($_SERVER["HTTP_REFERER"]);
        } else {
            $request = Request::instance();
            $msg = $request->module() . DS . $request->controller() . DS . $request->action();
            $this->error($result. "  错误点在". $msg );
        }
    }
    public function del_file($id) {
        $result = $this->file_del($id);
        trace($id);
        if(empty($result))
            $this->success("删除成功");
        else
            $this->error($result);
    }
    public function download_file($id){
        $this->file_download($id);
    }


    /*********************************文件的批量管理****************************************/
    /**
     * 批量下载文件
     */
    public function batch_file_download(){
        $request = Request::instance();
        $post = $request->post();
        $files = json_decode($post['data']);
        if(empty($files))$this->error("请先选择要下载的文件");
        // 进行文件的批量下载
        $zip = new ZipArchive;
        $zipName = ROOT_PATH . 'runtime' . DS . "download.zip";

        $result = '';

        if ($zip->open($zipName,ZipArchive::CREATE|ZipArchive::OVERWRITE ) === TRUE) {
            //-------添加文件---------------------------
            $inHere = [];//用于判断文件是否已经被添加进入zip
            foreach ($files as $key => $file){
                // 这里一定要重新从数据库中读取
                // 这是因为在file对象中，并没有保存path数据
                $data = Db::name('files')->where('id',$file->id)->find();
                $file_id = $data['id'];
                $filename = $data['path'];      // {$filename} = {$data}/{$filename_}
                $pattern = '/[^\\.]*$/';        //差点没把我气死
                $isMatched = preg_match($pattern, $filename, $filename_);
                if($isMatched)
                    $filename_ = $data['filename'] . '.' . $filename_[0];
                else// 虽然由于框架问题,好像本身就不能上传没有后缀的文件,但是这里还是进行判断吧
                    $filename_ = $data['filename'];
                // 这里有一个非常弱智的问题
                // 在linux中，文件夹的分隔符为/,window为\
                // 这导致了存储的文件路径可能不兼容
                // 为了兼容，我只能把路径中的所有/或者\替换成 DS（PHP自带的分隔符常量）
                // 注意下面经过两次转义\\/\\\\ 第一次-> \/\\ 第二次-> /\
                $filePath =
                    preg_replace('/[\\/\\\\]/', DS, path . DS . $filename);
                trace($filePath);
                if(!file_exists($filePath)){
                    $result = $result . "无法寻找文件$filename_";
                } else {
                    if(isset($inHere[$filename_])){
                        $filename_ = "[$file_id] ". $filename_;
                    }
                    $inHere[$filename_] = 1;
                    $zip->addFile($filePath, $filename_);
                    trace("ADD File To Batch : $filename_");
                }
            }
            $zip->close();
            //-------打包下载----------------------------
            header('Content-Type: application/zip');
            header('Content-disposition: attachment; filename=download.zip');
            header('Content-Length: ' . filesize($zipName));
            $tmp = fopen($zipName,"r");
            echo fread($tmp, filesize($zipName));
            fclose($tmp);
            unlink($zipName);
            if(!empty($result))
                $this->error($result);
        } else {
            $this->error("未知错误,下载失败");
        }
    }

    /**
     * 批量删除文件
     */
    public function batch_file_delete(){
        $request = Request::instance();
        $post = $request->post();
        $files = json_decode($post['data']);
        if(empty($files))$this->error("请先选择文件");
        $result = '';
        foreach ($files as $file){
            $result0 = $this->file_del($file->id);
            if(!empty($result0)){
                $result = $result . "<br>" . $result0;
            }
        }
        return $result;
    }
    /**
     * 个人用户使用batch_file_list的入口
     */
    public function batch_file_list_person($research_id=null){
        $name = Session::get("user.username");
        // 获取个人所在的项目
        $projects_list = Db::name('project')
            ->field('id,name')
            ->where("
            principal='$name'
            OR principal LIKE '$name,%'
            OR principal LIKE '%,$name,%'
            OR principal LIKE '%,$name'")
            ->select();
        // 提取项目编号
        $pjt_id = [-1];
        foreach ($projects_list as $pro){
            $pjt_id[count($pjt_id)] = $pro['id'];
        }
        // 获取个人所在的课题
        $researches_list = Db::name('research')
            ->field('id,name')
            ->where("
            principal='$name'
            OR principal LIKE '$name,%'
            OR principal LIKE '%,$name,%'
            OR principal LIKE '%,$name'")
            ->whereOr("project_id","in",$pjt_id)
            ->select();

        $researches_list = array_merge($researches_list,$projects_list);
        if(!empty($research_id)){
            $files_list = $this->get_file_list_from_research($research_id);
        } else {
            $files_list = [];
        }
        $this->assign([
            'title' => '项目课题管理',
            'subtitle'=>'文件批量管理',
        ]);
        return $this->batch_file_list($researches_list,$files_list);
    }
    /**
     * 管理员用户使用batch_file_list的入口
     */
    public function batch_file_list_admin($research_id=null){
        $researches_list = Db::name('research')
            ->field('id,name')
            ->select();
        if(!empty($research_id)){
            $files_list = $this->get_file_list_from_research($research_id);
        } else {
            // 注意，这里有一个弱智问题，path里面存在\,可能被理解成转义符而出错
            // 所以不能读取path中的数据
            $files_list = Db::name('files')
                ->field('id,filename,abstract,type,datetime,author')
                ->select();
        }
        $this->assign([
            'title' => '高级权限',
            'subtitle'=>'文件批量管理',
        ]);
        return $this->batch_file_list($researches_list,$files_list);
    }

    /**
     * 列表显示具有批量下载功能的文件
     */
    private function batch_file_list($researches_list,$files_list){
        // 转换成JSON，然后进行模板渲染
        $cols = json_encode($this->cols);
        $data_json = json_encode($files_list);
        return view('batch_file',[
            'research_list'=>$researches_list,
            'cols'=>$cols,
            'data_json'=>$data_json,
        ]);
    }

    /**
     * 根据research_id,获得文件列表的编号
     * @param $research_id
     */
    protected function get_file_list_from_research($research_id){
        $research = Db::name('research')
            ->where("id",$research_id)
            ->find();
        if(empty($research)){
            $this->error("没有找到编号为[$research_id]的课题");
        }
        // 获取属于该课题的要求
        $request = Db::name('request')
            ->alias("req")
            ->join("__CONFERENCE__ con","req.conference_id = con.id")
            ->where("con.research_id",$research_id)
            ->field("req.id,file_id")
            ->select();
        // 从要求中获取文件列表,-1是用于避免数组为空的情况
        $files = [-1];$cnt = 1;
        foreach ($request as $key => $req){
            if(isset($req['file_id'])){
                $files[$cnt++] = $req['file_id'];
            }
        }
//        print_r($request);
//        print_r($files);
        $data = Db::name('files')
            ->order('id DESC')
            ->field('id,filename,abstract,type,datetime,author')
            ->where("type","research:$research_id") // 获取课题文件
            ->whereOr("id","in",$files)             // 获取要求文件
            ->select();
        return $data;
    }

}


